<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,OutgoingInvoices};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** A private first approval of one actual outgoing message or one known-unsent replacement. */
final class OutgoingMailRequests
{
    public const SECTION='outgoing-mail';
    public function __construct(private PDO $db,private string $actorId){}
    public static function json(array$value):string{return OutgoingMailMessage::json($value);}
    private function busy(\Throwable$failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Rechnung oder Versandauftrag wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
    }
    private function read(callable$operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return$result;}
        catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw$failure;}
    }
    private function actor(bool$write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException$failure){throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
    }
    public function assertAccess(bool$write=false):array{return$this->read(fn():array=>$this->actor($write));}
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Rechnungs-Mailbestätigungen benötigen das aktuelle installierte Paket.',503);
    }
    public function context(string$invoice):array{return$this->read(function()use($invoice):array{$this->actor();return OutgoingMailContext::read($this->db,$this->actorId,$invoice);});}
    public function prepare(array$raw):array
    {
        return$this->read(function()use($raw):array{
            $this->actor(true);$this->available();$input=OutgoingMailInput::capture($raw);$context=OutgoingMailContext::read($this->db,$this->actorId,$input['invoice']);
            if($input['version']!==(string)$context['invoice']['version'])throw new DomainException('Rechnung inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
            if($input['kind']==='queue'&&$context['delivery']!==null)throw new DomainException('Für dieses Original liegt bereits ein Versandauftrag vor. Den vorhandenen Stand öffnen.',409);
            if($input['kind']==='replace'&&($context['delivery']['state']??null)!=='blocked')throw new DomainException('Nur einen tatsächlich gestoppten, sicher nicht versendeten Auftrag bewusst erneut freigeben.',409);
            if(!filter_var($context['invoice']['data']['buyer_email']??'',FILTER_VALIDATE_EMAIL))throw new DomainException('Keine gültige E-Mail im ausgegebenen Empfängerstand. PDF bitte per Post übermitteln.',422);
            $message=OutgoingMailMessage::make($context,$input);
            $review=['section'=>self::SECTION,'input'=>$input,'normalized'=>$message['normalized'],'source'=>$context['source'],'message'=>array_intersect_key($message,array_flip(['subject','body','message','html'])),'mime_ready'=>true,'original_pdf_ready'=>true];
            return$review+['hash'=>hash('sha256',self::json($review))];
        });
    }
    public function accept(array$raw,string$key,string$reviewHash,bool$approved):array
    {
        $input=OutgoingMailInput::capture($raw);
        if(!$approved)throw new DomainException('Nachricht, Empfänger und genau dieses Original-PDF ausdrücklich bestätigen.',422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Nachrichtenkennung und Prüfung auswählen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['section']!==self::SECTION||$old['actor_id']!==$actor['id']||!hash_equals($old['input_sha256'],$inputHash)||$old['input_json']!==$json)throw new DomainException('Diese Nachrichtenkennung gehört zu anderen ursprünglichen Angaben.',409);return$this->decode($old);}
            $this->actor(true);$review=$this->prepare($input);if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Nachricht, Rechnung, Zahlungsstand oder Quellen inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
            $previous=$review['source']['delivery'];$previousHash=$input['kind']==='replace'?hash('sha256',self::json($previous)):null;
            (new OutgoingInvoices($this->db,$this->actorId))->queue($input['invoice'],(int)$input['version'],$input,$previousHash);
            $result=Db::run($this->db,'SELECT * FROM outgoing_invoice_deliveries WHERE club_id=? AND invoice_id=? FOR SHARE NOWAIT',[$club,$input['invoice']])->fetch();
            if(!$result||$result['state']!=='pending'||$result['actor_id']!==$actor['id']||$result['subject']!==$review['message']['subject']||$result['body']!==$review['message']['body']||$result['recipient']!==$review['source']['invoice']['data']['buyer_email']||$result['document_id']!==$review['source']['file']['document_id']||$result['document_sha256']!==$review['source']['file']['sha256'])throw new DomainException('Tatsächlichen ursprünglichen Versandauftrag bitte prüfen lassen.',503);
            if($previous){$unchanged=$previous;foreach(['subject','body','actor_id','state','error','updated_at']as$field)$unchanged[$field]=$result[$field];if($unchanged!==$result)throw new DomainException('Das Original-PDF und der ursprüngliche Empfänger dürfen bei erneuter Freigabe nicht ersetzt werden.',503);}
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>'dvm-outgoing-invoice-mail-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,(int)$input['version'],$inputHash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return$this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\Throwable$failure){$this->busy($failure);throw$failure;}
    }
    private function decode(array$row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($original)||$row['section']!==self::SECTION||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-outgoing-invoice-mail-original/1'||($original['section']??null)!==self::SECTION||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['section']??null)!==self::SECTION||($original['review']['input']??null)!==$input||($original['review']['source']['invoice']['id']??null)!==$input['invoice']||(string)($original['review']['source']['invoice']['version']??'')!==$input['version']||(int)$row['version']!==(int)$input['version']||($original['result']['invoice_id']??null)!==$input['invoice']||($original['result']['club_id']??null)!==$row['club_id']||($original['result']['actor_id']??null)!==$row['actor_id']||($original['result']['state']??null)!=='pending'||($original['result']['subject']??null)!==($original['review']['message']['subject']??null)||($original['result']['body']??null)!==($original['review']['message']['body']??null)||($original['result']['recipient']??null)!==($original['review']['source']['invoice']['data']['buyer_email']??null)||($original['result']['document_id']??null)!==($original['review']['source']['file']['document_id']??null)||($original['result']['document_sha256']??null)!==($original['review']['source']['file']['sha256']??null)||($original['review']['mime_ready']??null)!==true||($original['review']['original_pdf_ready']??null)!==true)throw new DomainException('Ursprüngliche Rechnungs-Mailbestätigung bitte prüfen lassen.',503);
        $review=$original['review'];$hash=$review['hash']??null;unset($review['hash']);
        if(!is_string($hash)||!hash_equals($hash,hash('sha256',self::json($review)))||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Rechnungs-Mailbestätigung bitte prüfen lassen.',503);
        $message=OutgoingMailMessage::open($original['result']);$payment=$original['review']['source']['payment'];
        if($message['format']!==OutgoingMailMessage::FORMAT||$message['payment_open_cents']!==($payment===null?'':(string)$payment['open_cents'])||!hash_equals($message['payment_source_sha256'],hash('sha256',self::json($payment??[]))))throw new DomainException('Ursprüngliche Rechnungs-Mailbestätigung bitte prüfen lassen.',503);
        return['key'=>$row['request_key'],'actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string$key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Rechnungs-Mailbestätigung nicht verfügbar.',404);
        return$this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Rechnungs-Mailbestätigung nicht verfügbar.',404);return$this->decode($row);});
    }
    public function history(string$invoice,array$query=[]):array
    {
        $search=$query['query']??'';$page=$query['page']??'1';
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice)||!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Ganze Bestätigungssuche und Seite prüfen.',422);
        $page=(string)$page;
        return$this->read(function()use($invoice,$search,$page):array{
            $actor=$this->actor();$this->available();$where=" WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.invoice'))=?";$args=[$actor['club_id'],$actor['id'],self::SECTION,$invoice];if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$search;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare ursprüngliche Bestätigungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'query'=>$search,'error'=>$error,'per_page'=>25];
        });
    }
}
