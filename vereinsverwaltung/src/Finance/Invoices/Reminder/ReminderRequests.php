<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Invoices\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{ContactArchive,DocumentPreview,Documents,Permissions};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Immutable personal approval, canonical financial letter and separate mail intention. */
final class ReminderRequests
{
    public const SECTION='invoice-reminder';
    public function __construct(private PDO $db,private string $actorId,#[\SensitiveParameter]private ?string $key=null){}
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function master():string{$key=$this->key??ContactArchive::serverKey();if(strlen($key)<32)throw new DomainException('Privater Erinnerungsschlüssel nicht verfügbar.',503);return $key;}
    private function actor(bool $write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException $failure){if($failure->getCode()===503||$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.')throw $failure;throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
    }
    private function busy(\Throwable $failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Rechnung oder Erinnerung wird gerade bearbeitet. Erneut prüfen.',503,$failure);
    }
    private function read(callable $work):mixed
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$work();if($own)$this->db->commit();return $result;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw $failure;}
    }
    public function assertAccess(bool $write=false):array{return $this->read(fn():array=>$this->actor($write));}
    private function available(bool $mail=false):void
    {
        $tables=$mail?['native_settings_requests','native_invoice_reminder_deliveries','native_invoice_reminder_attempts']:['native_settings_requests'];
        foreach($tables as$table)if(!Db::run($this->db,'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])->fetchColumn())throw new DomainException('Rechnungserinnerungen benötigen das aktuelle installierte Paket.',503);
    }
    public function parentDocument(string $invoice):string
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$invoice))throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.',404);
        return $this->read(function()use($invoice):string{$actor=$this->actor();$id=Db::run($this->db,'SELECT document_id FROM outgoing_invoices WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$invoice])->fetchColumn();if(!is_string($id)||$id==='')throw new DomainException('Eigene ausgegebene Originalrechnung auswählen.',404);return $id;});
    }
    private static function hash(array $review):string{unset($review['hash']);return hash('sha256',self::json($review));}
    public function initial(string $invoice,string $day):array
    {
        return $this->read(function()use($invoice,$day):array{$this->actor(true);$this->available();return ReminderInput::initial(ReminderContext::read($this->db,$this->actorId,$invoice,$day));});
    }
    private function noOpenDelivery(string $club,string $invoice):void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_invoice_reminder_deliveries'")->fetchColumn())return;
        if(Db::run($this->db,"SELECT id FROM native_invoice_reminder_deliveries WHERE club_id=? AND invoice_id=? AND state IN ('pending','sending','uncertain') ORDER BY created_at,id LIMIT 1 FOR SHARE NOWAIT",[$club,$invoice])->fetchColumn())throw new DomainException('Für diese Rechnung ist ein Erinnerungsversand offen oder ungeklärt. Zuerst den ursprünglichen Ausgang prüfen.',409);
    }
    private function plan(array $raw):array
    {
        $input=ReminderInput::normalize($raw);
        return $this->read(function()use($input):array{
            $actor=$this->actor(true);$this->available($input['channel']==='mail');$context=ReminderContext::read($this->db,$this->actorId,$input['invoice'],$input['day']);$this->noOpenDelivery($actor['club_id'],$input['invoice']);
            $frame=ReminderText::frame($context,$input);$mail=null;
            if($input['channel']==='mail'){
                $identity=ReminderMail::identity($this->db,$this->actorId,$this->master(),$context);if(!$identity['configured'])throw new DomainException('Postfach für Zahlungserinnerungen zuerst einrichten oder den Briefweg ausdrücklich wählen.',422);
                try{$message=ReminderMail::message($identity['sender'],$context,$frame);}catch(\Throwable $failure){throw new DomainException('Ursprünglichen Empfänger und die ganze E-Mail-Erinnerung prüfen oder den Briefweg wählen.',422,$failure);}
                $mail=['sender'=>$identity['sender'],'settings'=>$identity['source'],'effective_subject'=>$message->getSubject(),'effective_recipient'=>$message->getTo()[0]->getAddress(),'configured'=>true,'connection_confirmed'=>false];
            }
            $source=$context;unset($source['assets'],$source['original']['original_pdf'],$source['original']['snapshot']);
            $review=['input'=>$input,'source'=>$source,'mail'=>$mail,'document_sha256'=>hash('sha256',self::json($frame['document'])),'financial_source_sha256'=>hash('sha256',self::json($context['original']['source'])),'actor_id'=>$actor['id'],'club_id'=>$actor['club_id'],'no_immediate_transport'=>true,'no_new_money'=>true,'no_new_invoice'=>true];
            return ['review'=>$review+['hash'=>self::hash($review)],'frame'=>$frame,'context'=>$context];
        });
    }
    public function preview(array $raw):array
    {
        $plan=$this->plan($raw);$plan['pdf']=DocumentPreview::pdf($plan['frame']['document']);$plan['mime']=null;
        if($plan['review']['input']['channel']==='mail'){
            $mail=ReminderMail::prepare($this->db,$this->actorId,$this->master(),$plan['context'],$plan['frame']);$review=$plan['review']['mail'];
            if(!$mail['mime_ready']||$mail['sender']!==$review['sender']||$mail['source']!==$review['settings']||$mail['effective_subject']!==$review['effective_subject']||$mail['effective_recipient']!==$review['effective_recipient'])throw new DomainException('Postfach während der Prüfung geändert. Ganze Erinnerung erneut prüfen.',409);
            $plan['mime']=$mail['mime'];
        }
        return $plan;
    }
    public function prepare(array $raw):array{return $this->preview($raw)['review'];}
    public function reviewedPreview(array $raw,string $hash):array
    {
        $plan=$this->preview($raw);if(!hash_equals($plan['review']['hash'],$hash))throw new DomainException('Rechnung, Zahlungsstand oder Vorlagenquelle geändert. Ganze Erinnerung erneut prüfen.',409);return $plan;
    }
    private function known(array $input,string $key,string $reviewHash):?array
    {
        return $this->read(function()use($input,$key,$reviewHash):?array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$key])->fetch();if(!$row)return null;if($row['actor_id']!==$actor['id']||$row['section']!==self::SECTION||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".self::json($input))))throw new DomainException('Diese Erinnerungskennung gehört zu anderen ursprünglichen Angaben.',409);$first=$this->decode($row);if(!hash_equals($first['review']['hash'],$reviewHash))throw new DomainException('Diese Erinnerungskennung gehört zu einer anderen ursprünglichen Prüfung.',409);return $first;});
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=ReminderInput::capture($raw);if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Erinnerungskennung und Prüfung auswählen.',422);if(!$approved)throw new DomainException('Genau diese vollständig geprüfte Erinnerung ausdrücklich bestätigen.',422);if($first=$this->known($input,$key,$reviewHash))return $first;
        $preview=$this->reviewedPreview($input,$reviewHash);$club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$preview,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);$actor=$this->actor();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);if($first=$this->known($input,$key,$reviewHash))return $first;
            $current=$this->plan($input);if(!hash_equals($current['review']['hash'],$reviewHash)||$current['review']['document_sha256']!==$preview['review']['document_sha256'])throw new DomainException('Rechnung, Zahlungsstand oder Vorlagenquelle geändert. Ganze Erinnerung erneut prüfen.',409);
            $parent=$current['context']['invoice']['document_id'];$filename='dvm-zahlungserinnerung-'.$input['invoice'].'.pdf';$snapshot=['kind'=>'invoice-reminder','format'=>'dvm-invoice-reminder-document/1','invoice_id'=>$input['invoice'],'source_document'=>$parent,'source_file_sha256'=>$current['context']['original']['source']['file']['sha256'],'rendered'=>$current['frame']['document']];
            $document=(new Documents($this->db,$this->actorId))->generatedForFinancialOriginal($parent,$filename,$preview['pdf'],['title'=>$current['frame']['subject'],'kind'=>'record','document_date'=>$input['day'],'links'=>[],'notes'=>'Zahlungserinnerung zur ursprünglichen Rechnung '.$current['context']['invoice']['number'].'. Kein neuer Rechnungsbeleg und keine Gebühr.'],$key,$snapshot);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$delivery=$input['channel']==='mail'?substr(hash('sha256','dvm-invoice-reminder-delivery:'.$club.':'.$key),0,32):null;
            $result=['invoice_id'=>$input['invoice'],'source_document'=>$parent,'document_id'=>$document,'filename'=>$filename,'mime'=>'application/pdf','sha256'=>hash('sha256',$preview['pdf']),'bytes'=>strlen($preview['pdf']),'channel'=>$input['channel'],'delivery_id'=>$delivery,'delivery_state'=>$delivery?'pending':null,'mail_sha256'=>$delivery?hash('sha256',$preview['mime']):null,'mail_bytes'=>$delivery?strlen($preview['mime']):null];
            $first=['format'=>'dvm-invoice-reminder-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$current['review'],'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')];$json=self::json($input);$original=self::json($first);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at) VALUES(?,?,?,?,1,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,hash('sha256',self::SECTION."\0".$json),$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            if($delivery){
                $payload=['format'=>'dvm-invoice-reminder-message/1','invoice_id'=>$input['invoice'],'recipient'=>$current['review']['mail']['effective_recipient'],'sender'=>$current['review']['mail']['sender'],'subject'=>$current['review']['mail']['effective_subject'],'message'=>$current['frame']['message'],'html'=>$current['frame']['html'],'mime_base64'=>base64_encode($preview['mime']),'mime_sha256'=>hash('sha256',$preview['mime']),'financial_source_sha256'=>$current['review']['financial_source_sha256'],'source'=>$current['review']['source']['original']['source']];$cipher=ReminderEnvelope::seal($this->master(),$club,$delivery,$payload);
                Db::run($this->db,'INSERT INTO native_invoice_reminder_deliveries(club_id,id,invoice_id,actor_id,request_key,document_id,document_sha256,source_sha256,message_sha256,sealed_message,recipient,state,version,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,\'pending\',1,?,?)',[$club,$delivery,$input['invoice'],$actor['id'],$key,$document,$result['sha256'],$current['review']['financial_source_sha256'],hash('sha256',self::json($payload)),$cipher,$payload['recipient'],$now->format('Y-m-d H:i:s.u'),$now->format('Y-m-d H:i:s.u')]);
            }
            Db::run($this->db,"INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,'invoice.reminder.accepted',?,1,?)",[$club,$actor['id'],$document,$now->format('Y-m-d\TH:i:s.u\Z')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch());
        });}catch(\Throwable $failure){$this->busy($failure);throw $failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$first=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);$valid=is_array($input)&&is_array($first)&&$row['section']===self::SECTION&&(int)$row['version']===1&&hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))&&hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))&&($first['format']??null)==='dvm-invoice-reminder-original/1'&&($first['request_key']??null)===$row['request_key']&&($first['club_id']??null)===$row['club_id']&&($first['actor_id']??null)===$row['actor_id']&&($first['section']??null)===self::SECTION&&($first['input']??null)===$input&&($first['review']['input']??null)===$input&&($first['approved']??null)===true&&($first['review']['no_immediate_transport']??null)===true&&($first['review']['no_new_money']??null)===true&&($first['review']['no_new_invoice']??null)===true;
        if(!$valid||!hash_equals((string)($first['review']['hash']??''),self::hash($first['review']??[]))||($first['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche persönliche Erinnerungsbestätigung bitte prüfen lassen.',503);
        ReminderInput::normalize($input);$result=$first['result']??[];if(($first['review']['actor_id']??null)!==$row['actor_id']||($first['review']['club_id']??null)!==$row['club_id']||($result['invoice_id']??null)!==$input['invoice']||($result['source_document']??null)!==($first['review']['source']['invoice']['document_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($result['document_id']??''))||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($result['sha256']??''))||($result['mime']??null)!=='application/pdf'||!is_int($result['bytes']??null)||$result['bytes']<5||($result['filename']??null)!=='dvm-zahlungserinnerung-'.$input['invoice'].'.pdf'||($result['channel']??null)!==$input['channel'])throw new DomainException('Ursprüngliches Erinnerungs-PDF bitte prüfen lassen.',503);
        if($input['channel']==='mail'){
            if(($result['delivery_id']??null)!==substr(hash('sha256','dvm-invoice-reminder-delivery:'.$row['club_id'].':'.$row['request_key']),0,32)||($result['delivery_state']??null)!=='pending'||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($result['mail_sha256']??''))||!is_int($result['mail_bytes']??null)||$result['mail_bytes']<1)throw new DomainException('Ursprüngliche Erinnerungs-E-Mail bitte prüfen lassen.',503);
        }elseif(($result['delivery_id']??null)!==null||($result['delivery_state']??null)!==null||($result['mail_sha256']??null)!==null||($result['mail_bytes']??null)!==null)throw new DomainException('Ursprünglichen Briefweg bitte prüfen lassen.',503);
        $output=(new Documents($this->db,$this->actorId))->currentFinancialOriginal($result['document_id'],1);if($output['document']['origin']!=='generated'||$output['document']['kind']!=='record'||$output['file']['sha256']!==$result['sha256']||(int)$output['file']['size']!==$result['bytes'])throw new DomainException('Ursprüngliches Erinnerungs-PDF bitte prüfen lassen.',503);
        return ['key'=>$row['request_key'],'input'=>$input,'review'=>$first['review'],'result'=>$result,'created_at'=>$first['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);
        return $this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);return $this->decode($row);});
    }
    public function history(string $invoice,array $query=[]):array
    {
        return ReminderHistory::read($this->db,$this->actorId,self::SECTION,$invoice,$query);
    }
    public function pdf(string $key):string
    {
        return $this->read(function()use($key):string{$first=$this->receipt($key);$actor=$this->actor();$file=Db::run($this->db,'SELECT bytes,sha256,mime FROM document_files WHERE club_id=? AND document_id=? AND revision=1 FOR SHARE NOWAIT',[$actor['club_id'],$first['result']['document_id']])->fetch();if(!$file||$file['mime']!=='application/pdf'||$file['sha256']!==$first['result']['sha256']||strlen($file['bytes'])!==$first['result']['bytes']||!hash_equals($file['sha256'],hash('sha256',$file['bytes'])))throw new DomainException('Ursprüngliches Erinnerungs-PDF bitte prüfen lassen.',503);return $file['bytes'];});
    }
    public function eml(string $key):string
    {
        return $this->read(function()use($key):string{$first=$this->receipt($key);if($first['input']['channel']!=='mail')throw new DomainException('Für diesen ursprünglichen Brief besteht keine E-Mail-Ausgabe.',404);$actor=$this->actor();$row=Db::run($this->db,'SELECT * FROM native_invoice_reminder_deliveries WHERE club_id=? AND id=? AND actor_id=? FOR SHARE NOWAIT',[$actor['club_id'],$first['result']['delivery_id'],$actor['id']])->fetch();if(!$row)throw new DomainException('Ursprüngliche Erinnerungs-E-Mail nicht verfügbar.',404);$payload=ReminderEnvelope::open($this->master(),$row);$mime=$payload['mime'];if(strlen($mime)!==$first['result']['mail_bytes']||!hash_equals($first['result']['mail_sha256'],hash('sha256',$mime)))throw new DomainException('Ursprüngliche Erinnerungs-E-Mail bitte prüfen lassen.',503);return $mime;});
    }
}
