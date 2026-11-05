<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,OutgoingInvoices,Documents};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Deliberate correction approval, with whole current financial facts and an atomic first result. */
final class OutgoingCorrectionRequests
{
    public const SECTION='outgoing-correction';
    public function __construct(private PDO $db,private string $actorId){}
    public static function json(array $value):string{return OutgoingMailMessage::json($value);}
    private function busy(\Throwable $failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Rechnung oder Korrektur wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
    }
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw$failure;}
    }
    private function actor(bool $write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException$failure){throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
    }
    public function assertAccess(bool $write=false):array{return $this->read(fn():array=>$this->actor($write));}
    public function current(string $invoice):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice))throw new DomainException('Eigene ursprüngliche Rechnung auswählen.',422);
        return $this->read(function()use($invoice):array{$actor=$this->actor();$row=Db::run($this->db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$invoice])->fetch();if(!$row)throw new DomainException('Ausgangsrechnung nicht verfügbar.',404);$row['data']=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);return $row;});
    }
    public function state(string $invoice):array
    {
        return $this->read(function()use($invoice):array{
            $actor=$this->actor();$current=OutgoingCorrectionSource::read($this->db,$this->actorId,$invoice);$credited=0;
            foreach($current['credits']as$row)$credited+=(int)json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR)['total_cents'];
            $paid=$current['entry']['allocated_cents'];return ['actor'=>$actor,'invoice'=>$current['invoice'],'source'=>$current['source'],'paid_cents'=>$paid,'credited_cents'=>$credited,'creditable_cents'=>max(0,$paid-$credited),'can_cancel'=>$paid===0&&$credited===0];
        });
    }
    public function initial(string $invoice):array
    {
        return $this->read(function()use($invoice):array{
            $this->actor(true);$state=$this->state($invoice);$input=OutgoingCorrectionInput::initial($state['invoice']);
            if($state['creditable_cents']>0){$input['data']['kind']='credit';$input['data']['amount']=OutgoingInvoices::amount($state['creditable_cents']);}
            elseif(!$state['can_cancel'])throw new DomainException('Für diesen aktuellen Rechnungs- und Zahlungsstand kann kein neues Korrekturdokument ausgegeben werden.',409);
            return $input;
        });
    }
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Rechnungsbestätigungen benötigen das aktuelle installierte Paket.',503);
    }
    private static function reviewHash(array $review):string
    {
        return hash('sha256',self::json(array_intersect_key($review,array_flip(['input','normalized','source','document']))));
    }
    private function rendered(array $input):array
    {
        $this->actor(true);$data=$input['data'];
        if(!in_array($data['kind'],['cancellation','credit'],true))throw new DomainException('Korrekturart ausdrücklich auswählen.',422);
        if(trim($data['reason'])===''||mb_strlen(trim($data['reason']),'UTF-8')>1000)throw new DomainException('Korrekturgrund mit bis zu 1000 Zeichen angeben.',422);
        try{return(new OutgoingInvoices($this->db,$this->actorId))->correctionPreview($input['invoice'],(int)$input['version'],$data['kind'],$data['amount'],$data['reason'],$data['date']);}
        catch(DomainException$failure){if($failure->getCode()===0)throw new DomainException($failure->getMessage(),422,$failure);throw$failure;}
    }
    private static function reviewed(array $input,array $preview):array
    {
        $review=['input'=>$input,'normalized'=>$preview['normalized'],'source'=>$preview['source'],'document'=>$preview['document'],'draft_pdf_sha256'=>$preview['sha256']];$review['hash']=self::reviewHash($review);return $review;
    }
    /** Rendering an unnumbered correction never creates a credit, cancellation or refund. */
    public function prepare(array $raw):array
    {
        $input=OutgoingCorrectionInput::capture($raw);
        return $this->read(fn():array=>self::reviewed($input,$this->rendered($input)));
    }
    public function preview(array $raw,string $reviewHash):array
    {
        $input=OutgoingCorrectionInput::capture($raw);
        return $this->read(function()use($input,$reviewHash):array{
            $preview=$this->rendered($input);$review=self::reviewed($input,$preview);if(!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash)||!hash_equals($review['hash'],$reviewHash))throw new DomainException('Rechnung, Zahlung oder frühere Gutschrift geändert. Ganze Korrektur erneut prüfen.',409);
            return $preview;
        });
    }
    /** An actual earlier canonical result is shared business history, never a personal approval. */
    public function legacy(string $key,array $raw):?array
    {
        $input=OutgoingCorrectionInput::capture($raw);if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Kennung des früheren Korrekturformulars prüfen.',422);
        return $this->read(function()use($input,$key):array{
            $actor=$this->actor();$invoice=Db::run($this->db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$key])->fetch();if(!$invoice)return[];
            if($invoice['parent_id']!==$input['invoice']||$invoice['kind']!==$input['data']['kind']||!in_array($invoice['kind'],['credit','cancellation'],true)||$invoice['state']!=='issued')throw new DomainException('Diese ursprüngliche Kennung gehört zu einem anderen Rechnungsvorgang.',409);
            $history=Db::run($this->db,"SELECT * FROM outgoing_invoice_history WHERE club_id=? AND invoice_id=? AND version=1 AND action='issued' FOR SHARE NOWAIT",[$actor['club_id'],$invoice['id']])->fetch();if(!$history)throw new DomainException('Tatsächliches früheres Korrekturdokument bitte prüfen lassen.',503);
            $invoice['data']=json_decode($invoice['payload'],true,64,JSON_THROW_ON_ERROR);return compact('invoice','history');
        })?:null;
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=OutgoingCorrectionInput::capture($raw);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Korrektur und Prüfung auswählen.',422);
        if(!$approved)throw new DomainException('Genau diesen ganzen geprüften Korrekturentwurf ausdrücklich ausgeben.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if($old){
                if($old['section']!==self::SECTION||$old['actor_id']!==$actor['id']||$old['input_json']!==$json||!hash_equals($old['input_sha256'],$inputHash))throw new DomainException('Diese Korrekturkennung gehört zu anderen ursprünglichen Angaben.',409);
                $first=$this->decode($old);if(!hash_equals($first['review']['hash'],$reviewHash))throw new DomainException('Diese Korrekturkennung gehört zu einer anderen ursprünglichen Prüfung.',409);return $first;
            }
            $this->actor(true);
            if(Db::run($this->db,'SELECT id FROM outgoing_invoices WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetchColumn())throw new DomainException('Diese Korrekturkennung gehört zu einem früheren ausgestellten Korrekturdokument. Tatsächlichen früheren Ausgang gezielt öffnen.',409);
            try{$review=$this->prepare($input);}catch(DomainException$failure){if($failure->getCode()===422)throw new DomainException('Rechnung, Zahlung oder frühere Gutschrift geändert. Ganze Korrektur erneut prüfen.',409,$failure);throw$failure;}if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Rechnung, Zahlung oder frühere Gutschrift geändert. Ganze Korrektur erneut prüfen.',409);
            $data=$input['data'];$service=new OutgoingInvoices($this->db,$this->actorId);$id=$service->correct($input['invoice'],(int)$input['version'],$data['kind'],$data['amount'],$data['reason'],$data['date'],$key);
            $result=Db::run($this->db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$id])->fetch();$result['data']=json_decode($result['payload'],true,64,JSON_THROW_ON_ERROR);
            $originalAfter=Db::run($this->db,'SELECT * FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$input['invoice']])->fetch();$originalAfter['data']=json_decode($originalAfter['payload'],true,64,JSON_THROW_ON_ERROR);
            $original=(new Documents($this->db,$this->actorId))->currentFinancialOriginal($result['document_id'],1);$file=['document_id'=>$result['document_id']]+array_intersect_key($original['file'],array_flip(['revision','name','mime','sha256','size']));
            if($result['parent_id']!==$input['invoice']||$result['kind']!==$data['kind']||$result['state']!=='issued'||(int)$result['version']!==1||$result['data']['total_cents']!==$review['normalized']['amount_cents'])throw new DomainException('Tatsächliche ursprüngliche Korrekturbestätigung bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$originalJson=self::json(['format'=>'dvm-outgoing-correction-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'original_after'=>$originalAfter,'file'=>$file,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,1,$inputHash,$json,hash('sha256',$originalJson),$originalJson,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\Throwable$failure){$this->busy($failure);throw$failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if($row['section']!==self::SECTION||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-outgoing-correction-original/1'||($original['section']??null)!==self::SECTION||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['input']??null)!==$input||($original['review']['input']??null)!==$input||!hash_equals((string)($original['review']['hash']??''),self::reviewHash($original['review']??[]))||($original['approved']??null)!==true||($original['result']['club_id']??null)!==$row['club_id']||($original['result']['parent_id']??null)!==$input['invoice']||($original['result']['kind']??null)!==$input['data']['kind']||($original['result']['state']??null)!=='issued'||(int)($original['result']['version']??0)!==1||(int)$row['version']!==1||($original['original_after']['id']??null)!==$input['invoice']||($original['result']['data']['total_cents']??null)!==($original['review']['normalized']['amount_cents']??null)||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Korrekturbestätigung bitte prüfen lassen.',503);
        return ['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'original_after'=>$original['original_after'],'file'=>$original['file'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);
        return $this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Korrekturbestätigung nicht verfügbar.',404);return $this->decode($row);});
    }
    public function history(string $invoice,array $query=[]):array
    {
        $search=$query['query']??'';$page=$query['page']??'1';
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice)||!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Ganze Korrekturbestätigungssuche und Seite prüfen.',422);
        $page=(string)$page;return $this->read(function()use($invoice,$search,$page):array{
            $actor=$this->actor();$this->available();$where=" WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.invoice'))=?";$args=[$actor['club_id'],$actor['id'],self::SECTION,$invoice];
            if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$search;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare ursprüngliche Korrekturbestätigungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'query'=>$search,'error'=>$error,'per_page'=>25];
        });
    }
}
