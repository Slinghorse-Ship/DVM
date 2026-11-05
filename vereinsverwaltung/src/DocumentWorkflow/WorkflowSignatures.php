<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;
require_once __DIR__.'/WorkflowSignatureAccess.php';
require_once __DIR__.'/WorkflowStore.php';
require_once __DIR__.'/WorkflowList.php';
/** Authenticated, revision-bound simple signatures. No bearer links, external signing service or automatic mails. */
final class WorkflowSignatures
{
    public const STATEMENT='Ich bestätige, dass ich die Gefährdungen, denen ich ausgesetzt sein kann, sowie ihre möglichen Auswirkungen, Wahrscheinlichkeit und Schwere gelesen und verstanden habe. Ich werde die mitgeteilten Schutzmaßnahmen soweit praktikabel befolgen. Sind die Maßnahmen nicht ausreichend, stelle ich die Arbeit ein und informiere die verantwortliche Leitung.';
    public function __construct(private \PDO $db,private WorkflowSignatureAccess $access) {}
    private function actor():array{return (new WorkflowStore($this->db,$this->access))->actor();}
    public function members():array
    {
        $this->actor();$result=[];
        foreach($this->access->signatureMembers() as $person){
            if(!is_string($person['id']??null)||!preg_match('/\A[A-Za-z0-9_.:@-]{1,128}\z/D',$person['id'])||!is_string($person['name']??null)||mb_strlen($person['name'])>200||trim($person['name'])==='')throw new WorkflowException('Mitgliederliste prüfen.');
            if(isset($result[$person['id']]))throw new WorkflowException('Doppelte Mitgliedskennung.');
            $result[$person['id']]=['id'=>$person['id'],'name'=>$person['name']];
        }
        return array_values($result);
    }
    private function query(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
    private function transaction(callable $fn):mixed
    {
        if($this->db->inTransaction())throw new WorkflowException('Unterschriften benötigen eine eigene Transaktion.');
        $this->db->beginTransaction();try{$result=$fn();$this->db->commit();return $result;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function request(string $document,int $revision,array $recipients):int
    {
        if(!$recipients||!array_is_list($recipients)||count($recipients)>100||count(array_unique($recipients,SORT_REGULAR))!==count($recipients))throw new WorkflowException('Ein bis 100 verschiedene Mitglieder auswählen.');
        return $this->transaction(function()use($document,$revision,$recipients):int{
            $actor=$this->actor();if($actor['access']!=='write')throw new WorkflowException('Schreibrecht erforderlich.');
            $doc=$this->query('SELECT * FROM dwf_documents WHERE club_id=? AND id=? FOR UPDATE',[$actor['club_id'],$document])->fetch(\PDO::FETCH_ASSOC);
            if(!$doc||$doc['status']!=='approved'||(int)$doc['version']!==$revision)throw new WorkflowException('Zuerst die aktuelle Beurteilung freigeben.');
            $snapshot=$this->query('SELECT snapshot_json FROM dwf_document_versions WHERE club_id=? AND document_id=? AND revision=?',[$actor['club_id'],$document,$revision])->fetchColumn();
            if(!is_string($snapshot))throw new WorkflowException('Dokumentfassung fehlt.');
            $pinned=json_decode($snapshot,true,64,JSON_THROW_ON_ERROR);
            if(!in_array('risk_register',array_column($pinned['fields'],'type'),true))throw new WorkflowException('Unterschriften sind für Beurteilungen mit Gefährdungsregister vorgesehen.');
            $members=array_column($this->members(),null,'id');$created=0;
            foreach($recipients as $id){
                if(!is_string($id)||!isset($members[$id]))throw new WorkflowException('Ausgewähltes Mitglied ist nicht mehr verfügbar.');
                if($this->query('SELECT id FROM dwf_signatures WHERE club_id=? AND document_id=? AND revision=? AND recipient_id=? FOR UPDATE',[$actor['club_id'],$document,$revision,$id])->fetchColumn())continue;
                $this->query('INSERT INTO dwf_signatures (id,club_id,document_id,revision,recipient_id,document_sha256,statement,requested_by,requested_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[bin2hex(random_bytes(16)),$actor['club_id'],$document,$revision,$id,hash('sha256',$snapshot),self::STATEMENT,$actor['id']]);$created++;
            }
            return $created;
        });
    }
    public function own():array
    {
        $actor=$this->actor();$members=array_column($this->members(),null,'id');if(!isset($members[$actor['id']]))return [];
        return $this->query("SELECT s.id,s.revision,s.signed_at,s.document_id,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.title')) title,d.version,d.status FROM dwf_signatures s JOIN dwf_documents d ON d.club_id=s.club_id AND d.id=s.document_id JOIN dwf_document_versions v ON v.club_id=s.club_id AND v.document_id=s.document_id AND v.revision=s.revision WHERE s.club_id=? AND s.recipient_id=? ORDER BY s.requested_at DESC,s.id DESC",[$actor['club_id'],$actor['id']])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function ownPage(mixed $page=1):array
    {
        $actor=$this->actor();if(!in_array($actor['id'],array_column($this->members(),'id'),true))return WorkflowList::rows([],$page);
        $from=' FROM dwf_signatures s JOIN dwf_documents d ON d.club_id=s.club_id AND d.id=s.document_id JOIN dwf_document_versions v ON v.club_id=s.club_id AND v.document_id=s.document_id AND v.revision=s.revision WHERE s.club_id=? AND s.recipient_id=?';
        return WorkflowList::query($this->db,"SELECT s.id,s.revision,s.signed_at,s.document_id,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.title')) title,d.version,d.status".$from.' ORDER BY s.requested_at DESC,s.id DESC','SELECT COUNT(*)'.$from,[$actor['club_id'],$actor['id']],$page);
    }
    public function documentPage(string $id,int $revision,mixed $page=1):array
    {
        (new WorkflowStore($this->db,$this->access))->document($id,$revision);$actor=$this->actor();$where=' FROM dwf_signatures WHERE club_id=? AND document_id=? AND revision=?';
        return WorkflowList::query($this->db,'SELECT id,recipient_id,revision,signed_at,signer_name,document_sha256,requested_at'.$where.' ORDER BY requested_at,id','SELECT COUNT(*)'.$where,[$actor['club_id'],$id,$revision],$page);
    }
    public function availableMembersPage(string $id,int $revision,mixed $page=1):array
    {
        (new WorkflowStore($this->db,$this->access))->document($id,$revision);$actor=$this->actor();
        $requested=array_fill_keys($this->query('SELECT recipient_id FROM dwf_signatures WHERE club_id=? AND document_id=? AND revision=?',[$actor['club_id'],$id,$revision])->fetchAll(\PDO::FETCH_COLUMN),true);
        return WorkflowList::rows(array_values(array_filter($this->members(),static fn(array $member):bool=>!isset($requested[$member['id']]))),$page);
    }
    public function forDocument(string $id,int $revision):array
    {
        (new WorkflowStore($this->db,$this->access))->document($id,$revision);$actor=$this->actor();
        return $this->query('SELECT id,recipient_id,revision,signed_at,signer_name,document_sha256,requested_at FROM dwf_signatures WHERE club_id=? AND document_id=? AND revision=? ORDER BY requested_at,id',[$actor['club_id'],$id,$revision])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function ownRequest(string $id):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new WorkflowException('Unterschrift nicht verfügbar.');
        $actor=$this->actor();$members=array_column($this->members(),null,'id');if(!isset($members[$actor['id']]))throw new WorkflowException('Mitgliedszugang nicht verfügbar.');
        $request=$this->query('SELECT s.*,d.version current_revision,d.status current_status FROM dwf_signatures s JOIN dwf_documents d ON d.club_id=s.club_id AND d.id=s.document_id WHERE s.id=? AND s.club_id=? AND s.recipient_id=?',[$id,$actor['club_id'],$actor['id']])->fetch(\PDO::FETCH_ASSOC);
        if(!$request)throw new WorkflowException('Unterschrift nicht verfügbar.');
        $snapshot=$this->query('SELECT snapshot_json FROM dwf_document_versions WHERE club_id=? AND document_id=? AND revision=?',[$actor['club_id'],$request['document_id'],$request['revision']])->fetchColumn();
        if(!is_string($snapshot)||!hash_equals($request['document_sha256'],hash('sha256',$snapshot)))throw new WorkflowException('Dokumentnachweis stimmt nicht überein.');
        $request['document']=json_decode($snapshot,true,64,JSON_THROW_ON_ERROR);$request['member_name']=$members[$actor['id']]['name'];
        $request['can_sign']=!$request['signed_at']&&$request['current_status']==='approved'&&(int)$request['current_revision']===(int)$request['revision'];
        unset($request['signature_png']);return $request;
    }
    public function sign(string $id,string $hash,bool $accepted,string $image):void
    {
        if(!$accepted||strlen($image)>22000||!preg_match('~\Adata:image/png;base64,([A-Za-z0-9+/=]+)\z~D',$image,$match))throw new WorkflowException('Bestätigung auswählen und mit Finger oder Maus unterschreiben.');
        $bytes=base64_decode($match[1],true);
        if(!is_string($bytes)||strlen($bytes)>16384)throw new WorkflowException('Unterschrift zu groß. Bitte das Unterschriftenfeld erneut verwenden.');
        try{WorkflowPdf::render(['validate_signature'=>base64_encode($bytes)]);}catch(\DomainException $e){throw new WorkflowException('Bitte im Unterschriftenfeld mit Finger oder Maus unterschreiben.',0,$e);}
        $this->transaction(function()use($id,$hash,$bytes):void{
            $request=$this->ownRequest($id);$actor=$this->actor();
            $current=$this->query('SELECT version,status FROM dwf_documents WHERE club_id=? AND id=? FOR UPDATE',[$actor['club_id'],$request['document_id']])->fetch(\PDO::FETCH_ASSOC);
            if(!$request['can_sign']||!$current||$current['status']!=='approved'||(int)$current['version']!==(int)$request['revision']||!hash_equals($request['document_sha256'],$hash))throw new WorkflowException('Diese Fassung kann nicht mehr unterschrieben werden. Bitte neu öffnen.');
            $q=$this->query('UPDATE dwf_signatures SET signed_at=UTC_TIMESTAMP(6),signer_name=?,signature_png=?,signature_sha256=? WHERE id=? AND club_id=? AND recipient_id=? AND signed_at IS NULL',[$request['member_name'],$bytes,hash('sha256',$bytes),$id,$actor['club_id'],$actor['id']]);
            if($q->rowCount()!==1)throw new WorkflowException('Diese Fassung wurde bereits unterschrieben.');
        });
    }
    public function ownPdf(string $id):array
    {
        $request=$this->ownRequest($id);$actor=$this->actor();$doc=$request['document'];$activities=$this->query('SELECT * FROM dwf_activities WHERE club_id=? AND document_id=? AND revision<=? ORDER BY revision',[$actor['club_id'],$request['document_id'],$request['revision']])->fetchAll(\PDO::FETCH_ASSOC);
        $profiles=[];foreach($doc['fields'] as $field)if($field['type']==='risk_register'&&($field['matrix_profile']??'')!=='')$profiles[$field['matrix_profile']]=RiskProfiles::get($field['matrix_profile']);
        $attachments=[];
        foreach($this->query('SELECT name,sha256,size_bytes,file_bytes FROM dwf_attachments WHERE club_id=? AND document_id=? AND added_revision<=? AND (removed_revision IS NULL OR removed_revision>?)',[$actor['club_id'],$request['document_id'],$request['revision'],$request['revision']])->fetchAll(\PDO::FETCH_ASSOC) as $file){if(!hash_equals($file['sha256'],hash('sha256',$file['file_bytes'])))throw new WorkflowException('Anlage prüfen.');$attachments[]=['name'=>$file['name'],'size_bytes'=>(int)$file['size_bytes'],'base64'=>base64_encode($file['file_bytes'])];}
        $signatures=$request['signed_at']?$this->evidence($request['document_id'],(int)$request['revision'],$actor['id']):[];
        return ['name'=>'Gefaehrdungsbeurteilung-v'.$request['revision'].'.pdf','mime'=>'application/pdf','bytes'=>WorkflowPdf::render(['document'=>$doc,'profiles'=>$profiles,'activities'=>$activities,'people'=>array_column($this->members(),'name','id'),'attachments'=>$attachments,'signatures'=>$signatures])];
    }
    public function evidence(string $id,int $revision,?string $recipient=null):array
    {
        $actor=$this->actor();
        if($recipient===null)(new WorkflowStore($this->db,$this->access))->document($id,$revision);
        else{
            if($recipient!==$actor['id']||!in_array($actor['id'],array_column($this->members(),'id'),true))throw new WorkflowException('Unterschrift nicht verfügbar.');
            $owned=$this->query('SELECT id FROM dwf_signatures WHERE club_id=? AND document_id=? AND revision=? AND recipient_id=?',[$actor['club_id'],$id,$revision,$actor['id']])->fetchColumn();
            if(!is_string($owned))throw new WorkflowException('Unterschrift nicht verfügbar.');
            $this->ownRequest($owned);
        }
        $snapshot=$this->query('SELECT snapshot_json FROM dwf_document_versions WHERE club_id=? AND document_id=? AND revision=?',[$actor['club_id'],$id,$revision])->fetchColumn();
        if(!is_string($snapshot))throw new WorkflowException('Dokumentfassung fehlt.');
        $sql='SELECT signer_name,signed_at,signature_png,signature_sha256,document_sha256,statement,revision FROM dwf_signatures WHERE club_id=? AND document_id=? AND revision=? AND signed_at IS NOT NULL';$args=[$actor['club_id'],$id,$revision];if($recipient!==null){$sql.=' AND recipient_id=?';$args[]=$recipient;}
        $result=[];foreach($this->query($sql.' ORDER BY signed_at,id',$args)->fetchAll(\PDO::FETCH_ASSOC) as $row){if(!is_string($row['signature_png'])||!hash_equals($row['signature_sha256'],hash('sha256',$row['signature_png']))||!hash_equals($row['document_sha256'],hash('sha256',$snapshot)))throw new WorkflowException('Unterschriftennachweis prüfen.');$row['image_base64']=base64_encode($row['signature_png']);unset($row['signature_png']);$result[]=$row;}return $result;
    }
}
