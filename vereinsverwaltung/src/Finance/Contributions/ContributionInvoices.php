<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Immutable issued invoices for actual annual dues; incomplete details remain a visible draft. */
final class ContributionInvoices
{
    public function __construct(private PDO $db,private string $actorId){}
    /** An incomplete invoice may have earlier draft revisions before its actual issued original. */
    public function currentOriginal(string $entryId):array
    {
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->exec('START TRANSACTION');$actor=Permissions::finance($this->db,$this->actorId);
            $invoice=DocumentCatalog::query($this->db,'SELECT * FROM contribution_invoices WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT',[$actor['club_id'],$entryId])->fetch();if(!$invoice)throw new DomainException('Beitragsrechnung fehlt.',404);
            $file=DocumentCatalog::query($this->db,'SELECT * FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1 FOR SHARE NOWAIT',[$actor['club_id'],$invoice['document_id']])->fetch();if(!$file)throw new DomainException('Ursprüngliches Beitragsrechnungs-PDF fehlt.',503);
            $original=(new Documents($this->db,$this->actorId))->currentFinancialOriginal($invoice['document_id'],(int)$file['revision']);if($original['trashed'])throw new DomainException('Beitragsrechnung zuerst aus dem Papierkorb wiederherstellen.',409);
            $snapshot=json_decode($file['snapshot'],true,64,JSON_THROW_ON_ERROR);
            if($file['mime']!=='application/pdf'||!str_starts_with($file['bytes'],'%PDF-')||!hash_equals($file['sha256'],hash('sha256',$file['bytes']))||($snapshot['draft']??null)!==($invoice['state']==='draft')||$original['document']['kind']!=='invoice'||$original['document']['origin']!=='generated')throw new DomainException('Ursprüngliches Beitragsrechnungs-PDF bitte prüfen lassen.',503);
            $result=compact('invoice','file','snapshot','original');if($own)$this->db->commit();return $result;
        }catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beitragsrechnung oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
    }
    public function issue(string $entryId):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if($a['finance_access']!=='write')throw new DomainException('Finanzbearbeitung für Beitragsrechnungen erforderlich.');
        return Infrastructure\Database\Transactions::run($this->db,fn():array=>$this->issueLocked(Permissions::finance($this->db,$this->actorId,true),$entryId));
    }
    public function issueForAdmission(string $applicationId,string $entryId):array
    {
        $a=BoardAccess::require($this->db,$this->actorId);
        $exists=DocumentCatalog::query($this->db,"SELECT 1 FROM membership_applications a JOIN member_dues_people d ON d.club_id=a.club_id AND d.contact_id=a.contact_id WHERE a.club_id=? AND a.id=? AND a.status='accepted' AND a.reviewed_by=? AND d.entry_id=?",[$a['club_id'],$applicationId,$a['id'],$entryId])->fetchColumn();
        if(!$exists)throw new DomainException('Beitragsrechnung muss zum angenommenen Antrag gehören.');
        return $this->issueLocked($a,$entryId);
    }
    private function settings(string $club,string $section):array
    {
        $payload=DocumentCatalog::query($this->db,'SELECT payload FROM club_settings WHERE club_id=? AND section=? FOR SHARE NOWAIT',[$club,$section])->fetchColumn();
        return $payload?json_decode($payload,true,512,JSON_THROW_ON_ERROR):SettingsSchema::defaults($section);
    }
    private function issueLocked(array $a,string $entryId):array
    {
        $club=$a['club_id'];$old=DocumentCatalog::query($this->db,'SELECT * FROM contribution_invoices WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT',[$club,$entryId])->fetch();
        if($old&&$old['state']==='issued')return $old;
        $entry=DocumentCatalog::query($this->db,'SELECT * FROM finance_entries WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$entryId])->fetch();
        if(!$entry||$entry['kind']!=='contribution'||$entry['status']==='cancelled'||DocumentCatalog::query($this->db,'SELECT 1 FROM finance_reversals WHERE club_id=? AND entry_id=? FOR SHARE NOWAIT',[$club,$entryId])->fetchColumn())throw new DomainException('Aktive Jahresbeitragsforderung erforderlich.');
        $dues=json_decode($entry['notes'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($dues)||empty($dues['payer'])||empty($dues['year']))throw new DomainException('Beitragszahler und Jahr fehlen.');
        $recipient=DocumentCatalog::query($this->db,'SELECT * FROM contacts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$dues['payer']])->fetch();if(!$recipient)throw new DomainException('Beitragszahler fehlt.');
        $membership=DocumentCatalog::query($this->db,'SELECT * FROM memberships WHERE club_id=? AND contact_id=? ORDER BY starts_on DESC LIMIT 1 FOR SHARE NOWAIT',[$club,$recipient['id']])->fetch();
        $company=$this->settings($club,'club');$bank=$this->settings($club,'bank');$paper=$this->settings($club,'paper');
        $paper['sender_name']=$company['name'];$paper['sender_street']=$company['street'];$paper['sender_address2']=$company['address2'];$paper['sender_city']=trim($company['postcode'].' '.$company['city']);
        $missing=[];
        if(trim($company['name'])===''||trim($company['street'])===''||trim($company['postcode'])===''||trim($company['city'])==='')$missing[]='Vereinsanschrift';
        if(trim($recipient['address'])==='')$missing[]='Empfängeranschrift';
        if(trim($bank['iban'])===''||trim($bank['holder'])==='')$missing[]='Bankverbindung';
        if(($bank['account_id']??'')==='')$missing[]='Zugehöriges Finanzkonto';
        $ready=$missing===[];$amount=number_format((int)$entry['amount_cents']/100,2,',','.').' €';
        $values=array_fill_keys(array_keys(SettingsSchema::VARIABLES),'');
        $values=array_replace($values,['VEREINSNAME'=>$company['name'],'BRIEFANREDE'=>'Guten Tag '.$recipient['name'].',','ZAHLUNG_FUER'=>$recipient['name'],'ZAHLBETRAG'=>$amount,'VEREINS_IBAN'=>$bank['iban'],'VEREINS_BIC'=>$bank['bic'],'KONTOINHABER'=>$bank['holder'],'MITGLIEDSNUMMER'=>$membership['member_number']??'','VERWENDUNGSZWECK'=>$entry['display_number'],'RECHNUNGSNUMMER'=>$entry['display_number'],'RECHNUNGSDATUM'=>date('d.m.Y',strtotime($entry['document_date'])),'DATUM'=>date('d.m.Y',strtotime($entry['document_date'])),'FAELLIGKEIT'=>date('d.m.Y',strtotime($entry['due_date'])),'ABRECHNUNGSZEITRAUM'=>(string)$dues['year'],'BEZEICHNUNG'=>$entry['description'],'ZAHLUNGSART'=>'Überweisung','BEARBEITER'=>$company['name']]);
        $text=$this->settings($club,'texts_contributions')['invoice'];foreach(['subject','message','intro','closing'] as $field)$text[$field]=SettingsSchema::interpolate($text[$field],$values);
        foreach(['footer_left','footer_middle','footer_right'] as $field)$paper[$field]=SettingsSchema::interpolate($paper[$field],$values);
        $paper['show_delivery']=false;
        $assets=[];if($paper['logo']!==''){$asset=Settings\PaperAssets::load($this->db,$club,$paper['logo']);$assets['logo']=['mime'=>$asset['mime'],'base64'=>base64_encode($asset['bytes'])];}
        $accountId=$bank['account_id']??'';if($accountId!==''){$bankAccount=DocumentCatalog::query($this->db,"SELECT id FROM finance_accounts WHERE club_id=? AND id=? AND kind='offline' FOR SHARE NOWAIT",[$club,$accountId])->fetchColumn();if(!$bankAccount)throw new DomainException('Zugeordnetes Rechnungskonto fehlt.');}
        $document=['payment_reference'=>$entry['display_number'],'payment_account_id'=>$accountId,'kind'=>'invoice','draft'=>!$ready,'paper'=>$paper,'values'=>$values,'text'=>$text,'assets'=>$assets,'title'=>'Beitragsrechnung '.$entry['display_number'],'recipient'=>$recipient['name']."\n".$recipient['address'],'positions'=>[['description'=>$entry['description'],'quantity'=>'1','unit'=>'Jahr','tax'=>'–','gross'=>$amount]],'net'=>$amount,'tax'=>'Nicht ausgewiesen','gross'=>$amount];
        $document['text']['intro']='Jährlicher Mitgliedsbeitrag gemäß Satzung für '.$dues['year'].'.'.($document['text']['intro']!==''?"\n\n".$document['text']['intro']:'');
        if(!$ready)$document['text']['closing']='Entwurf – vor Ausgabe ergänzen: '.implode(', ',$missing).'.';
        $document['recipient_email']=$recipient['email'];$snapshot=json_encode($document,JSON_THROW_ON_ERROR);
        if($old&&DocumentCatalog::query($this->db,'SELECT snapshot FROM document_files WHERE club_id=? AND document_id=? ORDER BY revision DESC LIMIT 1',[$club,$old['document_id']])->fetchColumn()===$snapshot)return $old;
        $bytes=DocumentPreview::pdf($document);$source=DocumentCatalog::register($this->db,$club,'entry',$entryId);$id=$old['document_id']??substr(hash('sha256','contribution-invoice:'.$club.':'.$entryId),0,32);$now=gmdate('c');
        $revision=(int)DocumentCatalog::query($this->db,'SELECT COALESCE(MAX(revision),0)+1 FROM document_files WHERE club_id=? AND document_id=?',[$club,$id])->fetchColumn();
        if(!$old)DocumentCatalog::query($this->db,"INSERT INTO documents(club_id,id,folder_id,title,kind,document_date,scopes,origin,created_at,created_by) VALUES(?,?,?,?,'invoice',?,'[\"finance\"]','generated',?,?)",[$club,$id,$source['folder_id'],$document['title'],$entry['document_date'],$now,$a['id']]);
        else DocumentCatalog::query($this->db,'UPDATE documents SET version=version+1 WHERE club_id=? AND id=?',[$club,$id]);
        $q=$this->db->prepare('INSERT INTO document_files(club_id,document_id,revision,name,mime,bytes,sha256,created_at,created_by,snapshot) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->bindValue(1,$club);$q->bindValue(2,$id);$q->bindValue(3,$revision,PDO::PARAM_INT);$q->bindValue(4,'beitragsrechnung-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$entry['display_number']).'.pdf');$q->bindValue(5,'application/pdf');$q->bindValue(6,$bytes,PDO::PARAM_LOB);$q->bindValue(7,hash('sha256',$bytes));$q->bindValue(8,$now);$q->bindValue(9,$a['id']);$q->bindValue(10,json_encode($document,JSON_THROW_ON_ERROR));$q->execute();
        DocumentCatalog::query($this->db,"INSERT IGNORE INTO document_links VALUES(?,?,'entry',?)",[$club,$id,$entryId]);
        DocumentCatalog::query($this->db,'INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,?,?,?,?)',[$club,$id,$a['id'],$ready?'contribution.invoice.issued':'contribution.invoice.draft',$revision,$now,json_encode(['entry_id'=>$entryId,'missing_fields'=>$missing],JSON_THROW_ON_ERROR)]);
        DocumentCatalog::query($this->db,'INSERT INTO contribution_invoices VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE recipient_email=VALUES(recipient_email),state=VALUES(state),missing_fields=VALUES(missing_fields)',[$club,$entryId,$id,$recipient['id'],$recipient['email'],(int)$dues['year'],$ready?'issued':'draft',json_encode($missing,JSON_THROW_ON_ERROR),$now]);
        if($ready){ManagerNotifications::documentAvailable($this->db,$club,$id,$recipient['id'],$revision);ManagerNotifications::contributionPaid($this->db,$club,$entryId);}
        return DocumentCatalog::query($this->db,'SELECT * FROM contribution_invoices WHERE club_id=? AND entry_id=?',[$club,$entryId])->fetch();
    }
}
