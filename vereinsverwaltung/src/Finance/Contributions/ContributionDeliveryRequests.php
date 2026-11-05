<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Finance,ContributionInvoices,ContributionReminders};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Conscious local outbox decisions. No transport, invoice or payment is created. */
final class ContributionDeliveryRequests
{
    public const SECTION='contribution-delivery';
    public function __construct(private PDO $db,private string $actorId){}
    public static function json(array $value):string{return ContributionRunRequests::json($value);}
    private function busy(\Throwable $failure):void
    {
        if(($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))||($failure instanceof DomainException&&$failure->getMessage()==='Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'))throw new DomainException('Beitragsmail oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
    }
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw $failure;}
    }
    private function actor(bool $write=false):array
    {
        try{return Permissions::finance($this->db,$this->actorId,$write);}catch(DomainException $failure){throw new DomainException($write?'Aktuelle Finanzschreibberechtigung erforderlich.':'Aktuelle Finanzleseberechtigung erforderlich.',403,$failure);}
    }
    public function assertAccess(bool $write=false):array{return $this->read(fn():array=>$this->actor($write));}
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Beitragsmailentscheidungen benötigen das aktuelle installierte Paket.',503);
    }
    private static function jobId(string $id):void
    {
        if($id===''||preg_match('//u',$id)!==1||mb_strlen($id,'UTF-8')>128||preg_match('/[\x00-\x1f\x7f]/',$id))throw new DomainException('Beitragsmailauftrag nicht verfügbar.',404);
    }
    public function context(string $id):array
    {
        self::jobId($id);return $this->read(function()use($id):array{
            $actor=$this->actor();$this->available();Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR SHARE NOWAIT',[$actor['club_id']])->fetchColumn();
            $delivery=Db::run($this->db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$actor['club_id'],$id])->fetch();if(!$delivery)throw new DomainException('Beitragsmailauftrag nicht verfügbar.',404);
            $entry=(new Finance($this->db,$this->actorId))->currentEntry($delivery['entry_id']);$original=(new ContributionInvoices($this->db,$this->actorId))->currentOriginal($delivery['entry_id']);
            $file=['document_id'=>$original['invoice']['document_id']]+$original['original']['file']+['snapshot_sha256'=>hash('sha256',$original['file']['snapshot']),'draft'=>$original['snapshot']['draft']];
            return compact('delivery','entry','file')+['invoice'=>$original['invoice']];
        });
    }
    private static function hash(array $review):string
    {
        unset($review['hash']);return hash('sha256',self::json($review));
    }
    public function prepare(array $raw):array
    {
        $input=ContributionDeliveryInput::capture($raw);$normalized=ContributionDeliveryInput::normalize($input);
        return $this->read(function()use($input,$normalized):array{
            $this->actor(true);$source=$this->context($normalized['job']);$job=$source['delivery'];$operation=$normalized['operation'];
            $expected=['release'=>['skipped'],'cancel'=>['pending'],'hold'=>['sending'],'confirm_sent'=>['uncertain'],'confirm_not_sent'=>['uncertain']];
            if(!in_array($job['status'],$expected[$operation],true))throw new DomainException('Diese Aktion passt nicht mehr zum tatsächlichen Beitragsmailstatus. Aktuellen Auftrag erneut prüfen.',409);
            if($operation==='hold'){
                $attempt=is_string($job['attempted_at'])?strtotime($job['attempted_at']):false;
                if($attempt===false||$attempt>time()-900)throw new DomainException('Der Versand läuft noch oder sein Beginn ist nicht belegt. Keine Wiederfreigabe; zunächst den Serverlauf prüfen.',409);
            }
            if($operation==='confirm_sent'&&($job['sent_remaining_cents']===null||(int)$job['sent_remaining_cents']<=0))throw new DomainException('Ursprünglicher Versandbetrag fehlt. Versandnachweis zunächst fachlich klären.',422);
            if($operation==='release'){
                $read=(new ContributionReminders($this->db,$this->actorId))->deliverySource($job['id'],ContributionRunInput::today());
                if($read['delivery']!==$job||$read['current']['invoice']['document_id']!==$job['document_id']||$read['original']['snapshot']['draft'])throw new DomainException('Ursprüngliche Beitragsrechnung und Auftrag passen nicht zusammen.',409);
                $source['current']=$read['current'];$source['message']=$read['message'];$source['day']=ContributionRunInput::today();
                require_once dirname(__DIR__,4).'/vendor/autoload.php';
                \Kulturhus\Native\Delivery\MailMessage::compose(['from'=>'preview@example.invalid','name'=>'DVM Vorschau','reply_to'=>'preview@example.invalid'],['to'=>$read['current']['recipient_email'],'subject'=>$read['message']['subject'],'text'=>$read['message']['text'],'html'=>$read['message']['html'],'attachments'=>$read['message']['attach_pdf']?[['name'=>$read['original']['file']['name'],'mime'=>'application/pdf','bytes'=>$read['original']['file']['bytes']]]:[]])->toString();
            }
            $review=['input'=>$input,'normalized'=>$normalized,'source'=>$source,'no_transport'=>true];return $review+['hash'=>self::hash($review)];
        });
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=ContributionDeliveryInput::capture($raw);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Eigene ursprüngliche Beitragsmailkennung und Prüfung auswählen.',422);
        if(!$approved)throw new DomainException('Genau diese geprüfte Beitragsmailentscheidung ausdrücklich bestätigen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor();$this->available();if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$json=self::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR SHARE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['section']!==self::SECTION||$old['actor_id']!==$actor['id']||$old['input_json']!==$json||!hash_equals($old['input_sha256'],$inputHash))throw new DomainException('Diese Beitragsmailkennung gehört zu anderen ursprünglichen Angaben.',409);$first=$this->decode($old);if(!hash_equals($first['review']['hash'],$reviewHash))throw new DomainException('Diese Beitragsmailkennung gehört zu einer anderen ursprünglichen Prüfung.',409);return $first;}
            $this->actor(true);$review=$this->prepare($input);if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Auftrag, Zahlung oder Originalquelle inzwischen geändert. Ganze Entscheidung erneut prüfen.',409);
            $before=$review['source']['delivery'];$after=$before;$decision=$review['normalized'];$operation=$decision['operation'];$reason=$decision['reason'];
            if($operation==='release'){
                $after['status']='pending';$after['actor_id']=$actor['id'];$after['scheduled_for']=$review['source']['day'];$after['recipient_contact_id']=$review['source']['current']['recipient']['id'];$after['recipient_email']=$review['source']['current']['recipient_email'];$after['attempted_at']=null;$after['sent_at']=null;$after['sent_remaining_cents']=null;$after['reason']='Bewusst erneut vorgemerkt. '.$reason;
            }elseif($operation==='cancel'){$after['status']='skipped';$after['reason']='Vormerkung bewusst angehalten. '.$reason;}
            elseif($operation==='hold'){$after['status']='uncertain';$after['reason']='Unterbrochener Versand zur Klärung vorgemerkt. '.$reason;}
            elseif($operation==='confirm_not_sent'){$after['status']='skipped';$after['reason']='Nichtversand anhand Nachweis manuell bestätigt. '.$reason;}
            else{$after['status']='sent';$after['sent_at']=$decision['sent_at'];$after['reason']='Versand anhand Nachweis manuell bestätigt. '.$reason;}
            $fields=['status','actor_id','scheduled_for','recipient_contact_id','recipient_email','attempted_at','sent_at','sent_remaining_cents','reason'];$args=array_map(static fn(string $field):mixed=>$after[$field],$fields);
            $changed=Db::run($this->db,'UPDATE contribution_deliveries SET '.implode(',',array_map(static fn(string $field):string=>$field.'=?',$fields)).' WHERE club_id=? AND id=? AND status=?',[...$args,$club,$before['id'],$before['status']]);if($changed->rowCount()!==1)throw new DomainException('Beitragsmail wurde inzwischen bearbeitet. Erneut prüfen.',409);
            $result=Db::run($this->db,'SELECT * FROM contribution_deliveries WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$club,$before['id']])->fetch();if($result!==$after)throw new DomainException('Tatsächlichen Beitragsmailausgang bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>'dvm-contribution-delivery-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at)VALUES(?,?,?,?,1,?)',[$club,$actor['id'],'contribution.mail.'.$operation,$before['id'],$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,version,input_sha256,input_json,original_sha256,original_json,created_at)VALUES(?,?,?,?,1,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,$inputHash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return $this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\Throwable $failure){$this->busy($failure);throw $failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$first=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($first)||$row['section']!==self::SECTION||(int)$row['version']!==1||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($first['format']??null)!=='dvm-contribution-delivery-original/1'||($first['section']??null)!==self::SECTION||($first['request_key']??null)!==$row['request_key']||($first['club_id']??null)!==$row['club_id']||($first['actor_id']??null)!==$row['actor_id']||($first['input']??null)!==$input||($first['review']['input']??null)!==$input||($first['approved']??null)!==true||($first['review']['no_transport']??null)!==true||($first['result']['id']??null)!==$input['job']||($first['result']['club_id']??null)!==$row['club_id']||!hash_equals((string)($first['review']['hash']??''),self::hash($first['review']??[]))||($first['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Beitragsmailentscheidung bitte prüfen lassen.',503);
        $before=$first['review']['source']['delivery']??[];$decision=$first['review']['normalized']??[];$states=['release'=>'pending','cancel'=>'skipped','hold'=>'uncertain','confirm_sent'=>'sent','confirm_not_sent'=>'skipped'];$operation=$input['operation']??'';
        if(!isset($states[$operation])||($decision['operation']??null)!==$operation||($decision['job']??null)!==$input['job']||($before['id']??null)!==$input['job']||($before['club_id']??null)!==$row['club_id']||($first['result']['status']??null)!==$states[$operation]||($decision['reason']??null)!==trim($input['data']['reason'])||($decision['evidence']??null)!==trim($input['data']['evidence']))throw new DomainException('Ursprüngliche Beitragsmailentscheidung bitte prüfen lassen.',503);
        foreach(['entry_id','document_id','type','cycle','year','created_at']as$field)if(($first['result'][$field]??null)!==($before[$field]??null))throw new DomainException('Ursprüngliche Beitragsmailentscheidung bitte prüfen lassen.',503);
        if(($operation==='release'&&($first['result']['actor_id']!==$row['actor_id']||$first['result']['recipient_email']!==($first['review']['source']['current']['recipient_email']??null)))||($operation!=='release'&&$first['result']['actor_id']!==$before['actor_id'])||($operation==='confirm_sent'&&($first['result']['sent_at']!==($decision['sent_at']??null)||$first['result']['sent_remaining_cents']!==$before['sent_remaining_cents'])))throw new DomainException('Ursprüngliche Beitragsmailentscheidung bitte prüfen lassen.',503);
        return ['key'=>$row['request_key'],'input'=>$input,'review'=>$first['review'],'result'=>$first['result'],'created_at'=>$first['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Eigene ursprüngliche Beitragsmailentscheidung nicht verfügbar.',404);
        return $this->read(function()use($key):array{$actor=$this->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Eigene ursprüngliche Beitragsmailentscheidung nicht verfügbar.',404);return $this->decode($row);});
    }
    private static function controls(array $query,bool $listing):array
    {
        $search=$query['query']??'';$page=$query['page']??'1';$status=$query['status']??'';$year=$query['year']??'';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page)||($listing&&(!is_string($status)||!in_array($status,['','pending','sending','sent','uncertain','skipped'],true)||!is_string($year)||($year!==''&&!preg_match('/\A[2-9][0-9]{3}\z/D',$year)))))throw new DomainException('Ganze Beitragsmailsuche, Status, Jahr und Seite prüfen.',422);
        return compact('search','status','year')+['page'=>(string)$page];
    }
    public function listing(array $query=[]):array
    {
        $filter=self::controls($query,true);
        return $this->read(function()use($filter):array{
            $actor=$this->actor();$where=' WHERE club_id=?';$args=[$actor['club_id']];
            if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),id,entry_id,recipient_email,reason)))>0';$args[]=$filter['search'];}
            if($filter['status']!==''){$where.=' AND status=?';$args[]=$filter['status'];}if($filter['year']!==''){$where.=' AND year=?';$args[]=(int)$filter['year'];}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM contribution_deliveries'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$filter['page']>$pages?'Verfügbare Beitragsmailseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT * FROM contribution_deliveries'.$where.' ORDER BY created_at DESC,id DESC LIMIT 25 OFFSET '.(((int)$filter['page']-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$filter['page'],'page'=>$error===''?(int)$filter['page']:null,'error'=>$error,'per_page'=>25];
        });
    }
    public function history(string $job,array $query=[]):array
    {
        self::jobId($job);$filter=self::controls($query,false);
        return $this->read(function()use($job,$filter):array{
            $actor=$this->actor();$this->available();$where=" WHERE club_id=? AND actor_id=? AND section=? AND JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.job'))=?";$args=[$actor['club_id'],$actor['id'],self::SECTION,$job];
            if($filter['search']!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT_WS(CHAR(10),request_key,created_at,input_json)))>0';$args[]=$filter['search'];}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$filter['page']>$pages?'Verfügbare ursprüngliche Beitragsmailentscheidungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT request_key,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$filter['page']-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$filter['page'],'page'=>$error===''?(int)$filter['page']:null,'error'=>$error,'per_page'=>25];
        });
    }

}
