<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Official paper certificates: print, sign, retain the signed copy. No signature is inferred. */
final class DonationReceipts
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        return $a;
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    public function listing(string $kind,string $source):array
    {
        $a=$this->actor();$multi=Database::tableExists($this->db,'donation_receipt_sources');if($multi&&$kind==='money')return $this->q("SELECT r.* FROM donation_receipts r WHERE r.club_id=? AND r.kind=? AND (r.source_id=? OR EXISTS(SELECT 1 FROM donation_receipt_sources s WHERE s.club_id=r.club_id AND s.receipt_id=r.id AND s.source_type='entry' AND s.source_id=?)) ORDER BY r.issued_at DESC,r.id",[$a['club_id'],$kind,$source,$source])->fetchAll();return $this->q('SELECT * FROM donation_receipts WHERE club_id=? AND kind=? AND source_id=? ORDER BY issued_at DESC,id',[$a['club_id'],$kind,$source])->fetchAll();
    }
    public function build(array $input):array
    {
        $a=$this->actor(true);$forms=new DonationForms($this->db,$this->actorId);$snapshot=$forms->build($input);
        if($snapshot['source_id']==='')throw new DomainException('Bitte zuerst eine tatsächliche Spende erfassen und diesen Beleg öffnen.');
        if(($input['receipt_reviewed']??'')!=='1')throw new DomainException('Zuwendung, Anschriften und Ausstellungsberechtigung bitte ausdrücklich prüfen.');
        $d=$snapshot['donation_form'];$defaults=$forms->defaults($d['kind'],$snapshot['source_id'],(string)($input['payment_id']??''));
        $stored=$this->q("SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();$club=$stored?json_decode($stored,true,32,JSON_THROW_ON_ERROR):SettingsSchema::defaults('club');
        $tax=(new Settings($this->db,$this->actorId))->section('tax')['data'];
        if(empty($tax['receipt_authority_reviewed'])||trim((string)($tax['receipt_signatory']??''))===''||empty($tax['notice_document_id']))throw new DomainException('Steuerbescheid als Finanzdokument hinterlegen und Zeichnungsberechtigung in den Steuereinstellungen prüfen.');
        $currentNotice=(new Documents($this->db,$this->actorId))->taxNotice($tax['notice_document_id']);
        $noticeDoc=$currentNotice['document']+['mime'=>$currentNotice['file']['mime'],'revision'=>$currentNotice['file']['revision'],'sha256'=>$currentNotice['file']['sha256']];
        if($noticeDoc['origin']!=='upload'||$noticeDoc['mime']!=='application/pdf'||$noticeDoc['state']!=='active'||!in_array('finance',$noticeDoc['scopes'],true))throw new DomainException('Hinterlegter Steuerbescheid muss ein aktives PDF-Finanzdokument sein.');
        $snapshot['authority']=['document_id'=>$noticeDoc['id'],'revision'=>$noticeDoc['revision'],'sha256'=>$noticeDoc['sha256'],'signatory'=>$tax['receipt_signatory'],'tax_settings_version'=>(new Settings($this->db,$this->actorId))->section('tax')['version']];
        foreach(['name','street','postcode','city'] as $field)if(trim((string)($club[$field]??''))==='')throw new DomainException('Vollständige Vereinsanschrift zuerst in den Vereinsdaten speichern.');
        foreach(['issuer','purpose','tax_office','tax_number','notice_type','assessment_period'] as $field)if($d[$field]!==$defaults[$field])throw new DomainException('Aussteller- und Steuerangaben müssen mit den gespeicherten Vereinseinstellungen übereinstimmen.');
        if(($input['notice_date']??'')!==$defaults['notice_date'])throw new DomainException('Bescheiddatum zuerst in den Steuereinstellungen speichern.');
        if($snapshot['missing'])throw new DomainException('Für die Ausstellung fehlen: '.implode(', ',$snapshot['missing']).'.');
        if(in_array($d['notice_type'],['exemption','corporate'],true)&&$d['assessment_period']==='')throw new DomainException('Veranlagungszeitraum des Freistellungsbescheids ergänzen.');
        if($d['notice_type']==='assessment'&&empty($tax['assessment_no_prior_notice']))throw new DomainException('§ 60a darf nur vor dem ersten Freistellungsbescheid beziehungsweise der ersten Anlage zum Körperschaftsteuerbescheid verwendet werden. Aktenlage in den Steuereinstellungen prüfen.');
        $notice=new \DateTimeImmutable($defaults['notice_date']);$today=new \DateTimeImmutable('today');
        if((in_array($d['notice_type'],['exemption','corporate'],true)&&$notice<$today->modify('-5 years'))||($d['notice_type']==='assessment'&&(int)$notice->format('Y')<(int)$today->format('Y')-3))throw new DomainException('Der hinterlegte Steuerbescheid ist für die Ausstellung zu alt. Aktuellen Bescheid hinterlegen.');
        $donorLines=array_values(array_filter(array_map('trim',explode("\n",$d['donor']))));
        if(count($donorLines)<3)throw new DomainException('Vollständigen Zuwender mit Name, Straße und Postleitzahl/Ort angeben.');
        $finance=new Finance($this->db,$this->actorId);
        if($d['kind']==='money'){
            $source=$finance->entry($snapshot['source_id']);
            $deductible=$tax['membership_deductible']??'';
            if(!in_array($deductible,['yes','no'],true))throw new DomainException('Abziehbarkeit der Mitgliedsbeiträge zuerst in den Steuereinstellungen prüfen.');
            if($source['kind']==='contribution'&&$deductible!=='yes')throw new DomainException('Für steuerlich nicht abziehbare Mitgliedsbeiträge darf keine Zuwendungsbestätigung ausgestellt werden.');
            if($source['kind']==='contribution'){
                if(!$forms->contributionPayer($snapshot['source_id']))throw new DomainException('Beitragszahler eindeutig über die Jahresbeitragszuordnung oder Beitragsrechnung hinterlegen.');
                if($d['donor']!==$defaults['donor'])throw new DomainException('Name und Anschrift müssen zum zugeordneten Beitragszahler passen. Bitte dessen Kontaktanschrift berichtigen.');
            }
            if($d['waiver']!=='no')throw new DomainException('Aufwandsverzicht benötigt einen gesonderten Anspruchsnachweis. Hier werden tatsächlich eingegangene Geldspenden bestätigt.');
            $snapshot['non_deductible_memberships']=$deductible==='no';
        }else{
            $source=$finance->goodsEntry($snapshot['source_id']);
            if(trim((string)$source['valuation'])==='')throw new DomainException('Wertermittlung der Sachspende dokumentieren.');
            if($d['goods_origin']!=='business'&&!$d['goods_evidence'])throw new DomainException('Unterlagen zur Wertermittlung der Sachspende fehlen.');
        }
        $person=($d['kind']==='money'&&$source['kind']==='contribution')?null:$finance->donationContact($d['kind']==='goods'?'goods':'entry',$snapshot['source_id']);
        if($person&&$d['donor']!==$person['name']."\n".$person['address'])throw new DomainException('Zuwenderdaten müssen zum zugeordneten Spender passen. Bitte Kontaktanschrift berichtigen.');
        $snapshot['kind']='donation_receipt';$snapshot['signature_mode']='handwritten';
        $snapshot['donation_form']['signature_place_date']=$club['city'].', '.date('d.m.Y');
        $snapshot['issued_by']=$a['id'];$snapshot['issued_on']=date('Y-m-d');
        return $snapshot;
    }
    public function issue(array $input,string $key):string
    {
        $a=$this->actor(true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');
        // Hash only form fields, not routing or CSRF tokens.
        $fields=array_keys((new DonationForms($this->db,$this->actorId))->defaults('money'));
        $fields[]='receipt_reviewed';$canonical=array_intersect_key($input,array_flip($fields));ksort($canonical);
        $hash=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR));
        $old=$this->q('SELECT * FROM donation_receipts WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();
        if($old){if($old['input_hash']!==$hash||$old['issued_by']!==$a['id'])throw new DomainException('Vorgangskennung wurde mit anderen Eingaben verwendet.');return $old['document_id'];}
        $snapshot=$this->build($input);$id='ZB-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(6)));$snapshot['receipt_number']=$id;
        $claimType=$snapshot['donation_form']['kind']==='goods'?'goods':'payment';
        $claims=$claimType==='goods'?[$snapshot['source_id']]:$snapshot['payment_ids'];
        $bytes=DocumentPreview::pdf($snapshot);
        $before=function()use($input,$snapshot,$claims,$claimType,$a){
            $current=$this->build($input);unset($snapshot['receipt_number']);
            if($current!==$snapshot)throw new DomainException('Angaben während der Erstellung geändert. Formular neu öffnen.');
            foreach($claims as $claim)if($this->q('SELECT 1 FROM donation_receipt_claims WHERE club_id=? AND source_type=? AND source_id=?',[$a['club_id'],$claimType,$claim])->fetchColumn())throw new DomainException('Diese Zuwendung wurde bereits bescheinigt. Vorhandenes Dokument verwenden oder zuerst begründet stornieren.');
        };
        $after=function(string $document)use($a,$id,$key,$hash,$snapshot,$claimType,$claims){
            $this->q('INSERT INTO donation_receipts(club_id,id,document_id,request_key,input_hash,kind,source_id,issued_at,issued_by) VALUES(?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$document,$key,$hash,$snapshot['donation_form']['kind'],$snapshot['source_id'],gmdate('c'),$a['id']]);
            foreach($claims as $claim)$this->q('INSERT INTO donation_receipt_claims VALUES(?,?,?,?)',[$a['club_id'],$claimType,$claim,$id]);
        };
        return (new Documents($this->db,$this->actorId))->generated(strtolower($id).'.pdf',$bytes,['title'=>'Zuwendungsbescheinigung '.$id,'kind'=>'certificate','scope'=>'finance','document_date'=>$snapshot['issued_on'],'links'=>[($claimType==='goods'?'goods':'entry').':'.$snapshot['source_id']],'notes'=>'Zur eigenhändigen Unterschrift erstellt. Ausdrucken, durch eine berechtigte Person unterschreiben und unterschriebenes Doppel als separates Dokument beim selben Spendenbeleg aufbewahren.'],$key,$snapshot,$before,$after);
    }
    public function cancel(string $id,string $reason):void
    {
        $a=$this->actor(true);$reason=trim($reason);if($reason===''||strlen($reason)>1000)throw new DomainException('Stornogrund angeben (max. 1000 Zeichen).');
        $this->db->beginTransaction();
        try{
            $r=$this->q('SELECT * FROM donation_receipts WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();
            if(!$r||$r['cancelled_at'])throw new DomainException('Bescheinigung nicht verfügbar oder bereits storniert.');
            $this->q('UPDATE donation_receipts SET cancelled_at=?,cancelled_by=?,cancel_reason=? WHERE club_id=? AND id=?',[gmdate('c'),$a['id'],$reason,$a['club_id'],$id]);
            if(Database::tableExists($this->db,'donation_waiver_receipt_claims'))$this->q('DELETE FROM donation_waiver_receipt_claims WHERE club_id=? AND receipt_id=?',[$a['club_id'],$id]);
            $this->q('DELETE FROM donation_receipt_claims WHERE club_id=? AND receipt_id=?',[$a['club_id'],$id]);
            $this->q("UPDATE documents SET state='archived',notes=CONCAT(notes,?),version=version+1 WHERE club_id=? AND id=?",["\nSTORNIERT: ".$reason,$a['club_id'],$r['document_id']]);
            $version=(int)$this->q('SELECT version FROM documents WHERE club_id=? AND id=?',[$a['club_id'],$r['document_id']])->fetchColumn();
            $this->q('INSERT INTO document_history(club_id,document_id,actor_id,action,version,happened_at,details) VALUES(?,?,?,?,?,?,?)',[$a['club_id'],$r['document_id'],$a['id'],'metadata',$version,gmdate('c'),json_encode(['reason'=>'Zuwendungsbescheinigung storniert: '.$reason],JSON_THROW_ON_ERROR)]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'donation.receipt.cancelled',$id,1,gmdate('c')]);
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public static function assertPaymentMutable(PDO $db,string $club,string $payment):void
    {
        if(DocumentCatalog::query($db,"SELECT 1 FROM donation_receipt_claims WHERE club_id=? AND source_type='payment' AND source_id=?".($db->inTransaction()?' FOR SHARE NOWAIT':''),[$club,$payment])->fetchColumn())throw new DomainException('Zuerst die zugehörige Zuwendungsbescheinigung begründet stornieren.');
    }
}
