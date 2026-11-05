<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,MailChannelSettings,InvoiceMailbox};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Private confirmations contain redacted intentions, never SMTP/IMAP passwords. */
final class MailSettingsRequests
{
    public function __construct(private PDO $db,private string $actorId,#[\SensitiveParameter] private ?string $key=null){}
    private function busy(\Throwable $failure):never
    {if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Postfach wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
    private function read(callable $operation):array
    { $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);} }
    private function actor(string $scope):array
    {MailInput::scope($scope);$actor=Permissions::actor($this->db,$this->actorId);if(empty($actor['is_superuser']))throw new DomainException('Nur der Superuser darf Postfächer und Versandzuordnungen ändern.',403);return $actor;}
    public function assertAccess(string $scope):array{return $this->read(fn():array=>$this->actor($scope));}
    private function available():void
    {if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Postfachbestätigungen benötigen das aktuelle installierte Paket.',503);}
    private function service(string $scope):MailChannelSettings|InvoiceMailbox
    {if($this->key===null||strlen($this->key)<32)throw new DomainException('Geschützter Serverschlüssel fehlt.',503);return $scope==='mail_channels'?new MailChannelSettings($this->db,$this->actorId,$this->key):new InvoiceMailbox($this->db,$this->actorId,$this->key);}
    private function redacted(string $scope,#[\SensitiveParameter] array $input,array $actor):array
    {if($this->key===null||strlen($this->key)<32)throw new DomainException('Geschützter Serverschlüssel fehlt.',503);return MailInput::redact($scope,$input,$this->key,$actor['club_id']."\0".$actor['id']);}
    public function current(string $scope):array
    {return $this->read(function()use($scope):array{$this->actor($scope);return $this->service($scope)->reviewContext();});}
    public function prepare(string $scope,#[\SensitiveParameter] array $input):array
    {
        $input=MailInput::capture($scope,$input);$version=MailInput::version($input['version']);
        return $this->read(function()use($scope,$input,$version):array{
            $actor=$this->actor($scope);$this->available();$service=$this->service($scope);$current=$service->reviewContext();$normalized=$service->review($input['data'],$version);
            $review=['section'=>$scope,'input'=>$this->redacted($scope,$input,$actor),'normalized'=>$normalized,'current'=>$current];
            return $review+['hash'=>hash('sha256',GeneralRequests::json($review))];
        });
    }
    public function accept(string $scope,#[\SensitiveParameter] array $input,string $key,string $reviewHash,bool $approved):array
    {
        $input=MailInput::capture($scope,$input);
        if(!$approved)throw new DomainException('Die vollständig geprüften Postfacheinstellungen ausdrücklich bestätigen.',422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Postfachkennung und Prüfung auswählen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($scope,$input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor($scope);if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();
            $redacted=$this->redacted($scope,$input,$actor);$json=GeneralRequests::json($redacted);$hash=hash('sha256',$scope."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['actor_id']!==$actor['id']||$old['section']!==$scope||!hash_equals($old['input_sha256'],$hash))throw new DomainException('Diese Postfachkennung gehört zu anderen ursprünglichen Angaben.',409);return $this->decode($old);}
            $plan=$this->prepare($scope,$input);if(!hash_equals($plan['hash'],$reviewHash)||$input['version']!==(string)$plan['current']['version'])throw new DomainException('Diese Postfacheinstellungen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
            $service=$this->service($scope);$service->save($input['data'],MailInput::version($input['version']));$result=$service->reviewContext();
            if($result['data']!==$plan['normalized']['data']||$result['passwords']!==$plan['normalized']['passwords']||($scope==='invoice_mailbox'&&($result['sender_name']??null)!==($plan['normalized']['sender_name']??null))||$result['version']!==$plan['current']['version']+1)throw new DomainException('Ursprüngliche Postfachbestätigung bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=GeneralRequests::json(['format'=>'dvm-mail-settings-change-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>$scope,'input'=>$redacted,'review'=>$plan,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],$scope,$result['version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\Throwable $failure){$this->busy($failure);}
    }
    private function decode(array $row):array
    {
        try{$input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $failure){throw new DomainException('Ursprüngliche Postfachbestätigung bitte prüfen lassen.',503,$failure);}
        if(!is_array($input)||!is_array($original)||!in_array($row['section'],MailInput::SCOPES,true)||!hash_equals($row['input_sha256'],hash('sha256',$row['section']."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-mail-settings-change-original/1'||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['section']??null)!==$row['section']||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['section']??null)!==$row['section']||($original['review']['input']??null)!==$input||($original['result']['version']??null)!==(int)$row['version']||($original['result']['data']??null)!==($original['review']['normalized']['data']??null)||($original['result']['passwords']??null)!==($original['review']['normalized']['passwords']??null)||($row['section']==='invoice_mailbox'&&(!is_string($original['result']['sender_name']??null)||$original['result']['sender_name']!==($original['review']['normalized']['sender_name']??null))))throw new DomainException('Ursprüngliche Postfachbestätigung bitte prüfen lassen.',503);
        foreach(MailInput::secrets($row['section'])as $path)if(MailInput::value($input['data'],$path)!=='')throw new DomainException('Ursprüngliche Postfachbestätigung bitte prüfen lassen.',503);
        $review=$original['review'];$hash=$review['hash']??'';unset($review['hash']);if(!is_string($hash)||!hash_equals($hash,hash('sha256',GeneralRequests::json($review)))||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Postfachbestätigung bitte prüfen lassen.',503);
        return ['key'=>$row['request_key'],'section'=>$row['section'],'actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $scope,string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Postfachbestätigung nicht verfügbar.',404);
        return $this->read(function()use($scope,$key):array{$actor=$this->actor($scope);$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],$scope,$key])->fetch();if(!$row)throw new DomainException('Ursprüngliche Postfachbestätigung nicht verfügbar.',404);return $this->decode($row);});
    }
    public function history(string $scope,array $query=[]):array
    {
        $search=$query['query']??'';$raw=$query['page']??'1';$page=is_string($raw)||is_int($raw)?(string)$raw:'';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Postfachsuche mit höchstens 150 Zeichen eingeben.',422);
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Postfachseite auswählen.',422);
        return $this->read(function()use($scope,$search,$page):array{$actor=$this->actor($scope);$this->available();$where=' WHERE club_id=? AND actor_id=? AND section=?';$args=[$actor['club_id'],$actor['id'],$scope];if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT(request_key,CHAR(10),version,CHAR(10),created_at)))>0';$args[]=$search;}$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare Postfachseite auswählen.':'';$rows=$error===''?Db::run($this->db,'SELECT request_key,version,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$error===''?(int)$page:null,'page_input'=>$page,'query'=>$search,'per_page'=>25,'error'=>$error];});
    }
}
