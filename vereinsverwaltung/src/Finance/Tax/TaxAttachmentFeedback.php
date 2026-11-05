<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Retrieve only this club's known ticket; commit feedback before acknowledging it. */
final class TaxAttachmentFeedback
{
    public function __construct(private PDO $db,private string $actor){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function filing(string $id,bool $write=false):array
    {
        $f=new TaxFilings($this->db,$this->actor);$r=$f->get($id);
        if($write&&!$f->canWrite())throw new DomainException('Finanz-Schreibrecht erforderlich.');
        if($write&&$r['environment']==='production')BoardAccess::require($this->db,$this->actor);
        return $r;
    }
    public function status(string $id):array
    {
        $r=$this->filing($id);$check=$this->q('SELECT cursor_id,checked_at,last_error FROM tax_attachment_checks WHERE club_id=? AND filing_id=?',[$r['club_id'],$id])->fetch()?:['cursor_id'=>'','checked_at'=>null,'last_error'=>null];
        $rows=$this->q('SELECT provision_id,details,received_at,acknowledged_at FROM tax_attachment_feedback WHERE club_id=? AND filing_id=? ORDER BY received_at,provision_id',[$r['club_id'],$id])->fetchAll();
        foreach($rows as &$row)$row['details']=json_decode($row['details'],true,64,JSON_THROW_ON_ERROR);unset($row);
        return $check+['items'=>$rows];
    }
    private static function id(mixed $value):string
    {
        if(!is_string($value)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$value))throw new DomainException('Ungültige ELSTER-Rückmeldung.');return $value;
    }
    public function refresh(string $id,#[\SensitiveParameter] string $certificate,#[\SensitiveParameter] string $pin):array
    {
        if($this->db->inTransaction())throw new DomainException('Rückmeldungen benötigen einen eigenen Vorgang.');
        $r=$this->filing($id,true);
        if(!in_array($r['state'],['sent_test','sent_production'],true)||!($r['form_data']['attachments']??[]))throw new DomainException('Zuerst einen Belegauftrag mit Anlagen übermitteln.');
        $attempts=(new TaxFilings($this->db,$this->actor))->attempts($id);$sent=array_values(array_filter($attempts,static fn($a)=>$a['state']===$r['state']));
        if(count($sent)!==1)throw new DomainException('Abgabeauftrag ist nicht eindeutig.');
        $ticket=self::id($sent[0]['transfer_ticket']);
        $lock='tax-feedback-'.substr(hash('sha256',$r['club_id'].':'.$id),0,40);
        if((int)$this->q('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('Rückmeldungen werden bereits abgerufen.');
        try{
            $this->q("INSERT IGNORE INTO tax_attachment_checks(club_id,filing_id) VALUES(?,?)",[$r['club_id'],$id]);
            $base=['data_version'=>$r['data_version'],'environment'=>$r['environment'],'filing_id'=>$id,'transfer_ticket'=>$ticket,'certificate_b64'=>base64_encode($certificate),'pin'=>$pin];
            // Retry ONLY acknowledgement of already durably saved feedback, never a tax submission.
            $this->acknowledge($r,$base);
            $status=$this->status($id);
            $reply=TaxEricAdapter::invoke($base+['action'=>'feedback-fetch','cursor'=>$status['cursor_id'],'attachment_names'=>array_column($r['form_data']['attachments'],'name')]);
            self::reply($reply,$r,$ticket);
            if(!is_array($reply['items']??null)||!array_is_list($reply['items'])||count($reply['items'])>10||!is_string($reply['cursor']??null))throw new DomainException('Unvollständige ELSTER-Rückmeldung.');
            $cursor=$reply['cursor'];if($cursor!=='')self::id($cursor);
            if($cursor!==''&&$cursor===$status['cursor_id'])throw new DomainException('ELSTER-Abholung konnte nicht fortgesetzt werden.');
            $this->db->beginTransaction();
            try{
                $this->filing($id,true);$seen=[];
                foreach($reply['items'] as $item){
                    $provision=self::id($item['id']??null);
                    if(isset($seen[$provision]))throw new DomainException('Doppelte ELSTER-Rückmeldung.');$seen[$provision]=true;
                    $xml=$item['xml']??null;$details=$item['feedback']??null;
                    if(!is_string($xml)||strlen($xml)>1048576||!is_array($details)||!hash_equals(hash('sha256',$xml),(string)($item['sha256']??'')))throw new DomainException('Beschädigte ELSTER-Rückmeldung.');
                    // Independently enforce ticket/name binding at the database boundary.
                    $fragment=preg_replace('/^\s*<\?xml[^?]*\?>/','',$xml,1);
                    if(preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$fragment))throw new DomainException('Ungültige XML-Rückmeldung.');
                    $doc=new \DOMDocument();$old=libxml_use_internal_errors(true);
                    try{$ok=$doc->loadXML('<AnhangRueckmeldung>'.$fragment.'</AnhangRueckmeldung>',LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
                    if(!$ok)throw new DomainException('Ungültige XML-Rückmeldung.');
                    foreach(['TransferTicket'=>$ticket,'NutzdatenTicket'=>$id,'Datenart'=>TaxFormSchema::load($r['data_version'])->profile()['data_art']] as $key=>$value){$nodes=$doc->getElementsByTagName($key);if($nodes->length!==1||$nodes->item(0)->textContent!==$value)throw new DomainException('Rückmeldung gehört zu einem anderen Auftrag.');}
                    $names=$doc->getElementsByTagName('Dateibezeichnung');$allowed=array_column($r['form_data']['attachments'],'name');
                    if($names->length<1||$names->length>20)throw new DomainException('Anlagen-Rückmeldung ist unvollständig.');
                    foreach($names as $name)if(!in_array($name->textContent,$allowed,true))throw new DomainException('Rückmeldung betrifft eine unbekannte Anlage.');
                    $existing=$this->q('SELECT payload_sha256 FROM tax_attachment_feedback WHERE club_id=? AND filing_id=? AND provision_id=?',[$r['club_id'],$id,$provision])->fetchColumn();
                    if($existing!==false){if(!hash_equals($existing,$item['sha256']))throw new DomainException('ELSTER-Rückmeldung wurde unerwartet verändert.');continue;}
                    $this->q('INSERT INTO tax_attachment_feedback(club_id,filing_id,provision_id,transfer_ticket,payload,payload_sha256,details,received_by,received_at) VALUES(?,?,?,?,?,?,?,?,?)',[$r['club_id'],$id,$provision,$ticket,$xml,$item['sha256'],json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$this->actor,gmdate('c')]);
                }
                $this->q('UPDATE tax_attachment_checks SET cursor_id=?,checked_at=?,last_error=NULL WHERE club_id=? AND filing_id=?',[$cursor,$cursor===''?gmdate('c'):$status['checked_at'],$r['club_id'],$id]);
                $this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
            $this->acknowledge($r,$base);
            return $this->status($id);
        }catch(\Throwable $e){
            $message=$e instanceof DomainException?$e->getMessage():'Rückmeldungen konnten nicht vollständig gespeichert werden. Bitte erneut abrufen.';
            $this->q('UPDATE tax_attachment_checks SET last_error=? WHERE club_id=? AND filing_id=?',[$message,$r['club_id'],$id]);
            throw new DomainException($message);
        }finally{
            unset($base);if(function_exists('sodium_memzero')){sodium_memzero($certificate);sodium_memzero($pin);}
            $this->q('SELECT RELEASE_LOCK(?)',[$lock]);
        }
    }
    private static function reply(array $reply,array $r,string $ticket):void
    {
        if(($reply['code']??null)!==0||($reply['environment']??null)!==$r['environment']||($reply['transfer_ticket']??null)!==$ticket)throw new DomainException('ELSTER-Rückmeldungen konnten nicht abgerufen werden.');
    }
    private function acknowledge(array $r,#[\SensitiveParameter] array $base):void
    {
        if($this->db->inTransaction())throw new DomainException('Rückmeldung muss vor der Empfangsbestätigung gespeichert sein.');
        $ids=$this->q('SELECT provision_id FROM tax_attachment_feedback WHERE club_id=? AND filing_id=? AND acknowledged_at IS NULL ORDER BY received_at LIMIT 100',[$r['club_id'],$r['id']])->fetchAll(PDO::FETCH_COLUMN);
        if(!$ids)return;
        $reply=TaxEricAdapter::invoke($base+['action'=>'feedback-ack','provisions'=>$ids]);self::reply($reply,$r,$base['transfer_ticket']);
        if(($reply['acknowledged']??null)!==$ids)throw new DomainException('Rückmeldung gespeichert. Empfangsbestätigung noch offen; erneut abrufen.');
        foreach($ids as $id)$this->q('UPDATE tax_attachment_feedback SET acknowledged_at=? WHERE club_id=? AND filing_id=? AND provision_id=? AND acknowledged_at IS NULL',[gmdate('c'),$r['club_id'],$r['id'],$id]);
    }
}
