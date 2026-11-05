<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Permissions,Settings as ClubSettings,SettingsSchema};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** Accepted general settings keep their own original; existing revisions are never backfilled. */
final class GeneralRequests
{
    public function __construct(private PDO $db,private string $actorId){}
    public static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return$result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw$failure;}
    }
    private function busy(\Throwable $failure):void
    {
        if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Einstellungen werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
    }
    private function actor(string $section,bool $write=false):array
    {
        if(!GeneralValues::supports($section)&&!in_array($section,['numbers','bank','tax',\Kulturhus\Verein\FinancePreferences::SECTION],true))throw new DomainException('Eigenen Einstellungsbereich auswählen.',422);
        $actor=Permissions::actor($this->db,$this->actorId);
        if(in_array($section,['numbers','bank','tax',\Kulturhus\Verein\FinancePreferences::SECTION],true)){
            if($actor['role']==='editor'||!in_array($actor['finance_access'],$write?['write']:['read','write'],true))throw new DomainException(match($section){'numbers'=>'Aktuelle Finanzberechtigung für Nummernkreise erforderlich.','bank'=>'Aktuelle Finanzberechtigung für Rechnungsbankdaten erforderlich.','tax'=>'Aktuelle Finanzberechtigung für Steuereinstellungen erforderlich.',default=>'Aktuelle Finanzberechtigung für Finanz-Vorauswahl erforderlich.'},403);
            return$actor;
        }
        if($actor['role']==='editor'||!$actor['settings_access'])throw new DomainException('Eigene Vereinseinstellungen erforderlich.',403);
        return$actor;
    }
    public function assertAccess(string $section,bool $write=false):array{return$this->read(fn():array=>$this->actor($section,$write));}
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Einstellungsbestätigungen benötigen das aktuelle installierte Paket.',503);
    }
    public function prepare(string $section,array $input):array
    {
        $input=GeneralInput::capture($section,$input);$normalized=match($section){'numbers'=>NumberingRules::normalize($input['data']),'bank'=>FinanceBankInput::normalize($input['data']),'tax'=>FinanceTaxInput::normalize($input['data']),\Kulturhus\Verein\FinancePreferences::SECTION=>FinancePreferenceInput::normalize($input['data']),default=>GeneralValues::normalize($section,$input['data'])};
        return$this->read(function()use($section,$input,$normalized):array{
            $actor=$this->actor($section,true);$this->available();$current=(new ClubSettings($this->db,$this->actorId))->section($section);
            $review=['section'=>$section,'input'=>$input,'normalized'=>$normalized,'current'=>$current];
            if($section==='bank')$review['bank_context']=FinanceBankContext::read($this->db,$actor['id'],$normalized);
            if($section==='tax')$review['tax_context']=FinanceTaxContext::read($this->db,$actor['id'],$normalized);
            if($section==='numbers')$review['numbering_context']=NumberingContext::read($this->db,$actor['club_id'],$normalized,$input['date']);
            if($section===\Kulturhus\Verein\FinancePreferences::SECTION)$review['preference_context']=FinancePreferenceContext::read($this->db,$actor['id'],$normalized);
            return$review+['hash'=>hash('sha256',self::json($review))];
        });
    }
    public function accept(string $section,array $input,string $key,string $reviewHash,bool $approved):array
    {
        $input=GeneralInput::capture($section,$input);
        if(!$approved)throw new DomainException('Die vollständig geprüften Einstellungen ausdrücklich bestätigen.',422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Einstellungskennung und Prüfung auswählen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();
        if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($section,$input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $actor=$this->actor($section,true);if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();
            $json=self::json($input);$hash=hash('sha256',$section."\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR UPDATE',[$club,$key])->fetch();
            if($old){if($old['actor_id']!==$actor['id']||$old['section']!==$section||!hash_equals($old['input_sha256'],$hash))throw new DomainException('Diese Einstellungskennung gehört zu anderen ursprünglichen Angaben.',409);return$this->decode($old);}
            $plan=$this->prepare($section,$input);
            if(!hash_equals($plan['hash'],$reviewHash)||$input['version']!==(string)$plan['current']['version'])throw new DomainException('Diese Einstellungen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
            if($section==='numbers'&&($issues=NumberingContext::issues($plan)))throw new DomainException($issues[0]['message'],$issues[0]['code']);
            (new ClubSettings($this->db,$this->actorId))->save($section,$input['data'],GeneralInput::version($input['version']));
            $result=(new ClubSettings($this->db,$this->actorId))->section($section);
            if($result['data']!==$plan['normalized']||$result['version']!==$plan['current']['version']+1)throw new DomainException('Ursprüngliche Einstellungsbestätigung bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $original=self::json(['format'=>'dvm-settings-change-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>$section,'input'=>$input,'review'=>$plan,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],$section,$result['version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
            return$this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\PDOException $failure){$this->busy($failure);throw$failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($original)||(!GeneralValues::supports($row['section'])&&!in_array($row['section'],['numbers','bank','tax',\Kulturhus\Verein\FinancePreferences::SECTION],true))||!hash_equals($row['input_sha256'],hash('sha256',$row['section']."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-settings-change-original/1'||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['section']??null)!==$row['section']||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['section']??null)!==$row['section']||($original['review']['input']??null)!==$input||($original['result']['version']??null)!==(int)$row['version']||($original['result']['data']??null)!==($original['review']['normalized']??null))throw new DomainException('Ursprüngliche Einstellungsbestätigung bitte prüfen lassen.',503);
        $review=$original['review'];$hash=$review['hash']??'';unset($review['hash']);
        if(!is_string($hash)||!hash_equals($hash,hash('sha256',self::json($review)))||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Einstellungsbestätigung bitte prüfen lassen.',503);
        if($row['section']==='tax')FinanceTaxContext::originalAccess($this->db,$this->actorId,$original['review']);
        return['key'=>$row['request_key'],'section'=>$row['section'],'actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $section,string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Einstellungsbestätigung nicht verfügbar.',404);
        return$this->read(function()use($section,$key):array{
            $actor=$this->actor($section);$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],$section,$key])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Einstellungsbestätigung nicht verfügbar.',404);return$this->decode($row);
        });
    }
    public function history(string $section,array $query=[]):array
    {
        $search=$query['query']??'';$raw=$query['page']??'1';$page=is_string($raw)||is_int($raw)?(string)$raw:'';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Einstellungssuche mit höchstens 150 Zeichen eingeben.',422);
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Einstellungsseite auswählen.',422);
        return$this->read(function()use($section,$search,$page):array{
            $actor=$this->actor($section);$this->available();$where=' WHERE club_id=? AND actor_id=? AND section=?';$args=[$actor['club_id'],$actor['id'],$section];
            if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT(request_key,CHAR(10),version,CHAR(10),created_at)))>0';$args[]=$search;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare Einstellungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT request_key,version,created_at FROM native_settings_requests'.$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$error===''?(int)$page:null,'page_input'=>$page,'query'=>$search,'per_page'=>25,'error'=>$error];
        });
    }
    /** Earlier canonical revisions are real saved values, not fabricated approval records. */
    public function revisions(string $section,mixed $pageInput='1'):array
    {
        $page=is_string($pageInput)||is_int($pageInput)?(string)$pageInput:'';
        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Fassungsseite auswählen.',422);
        return$this->read(function()use($section,$page):array{
            $actor=$this->actor($section);$args=[$actor['club_id'],$section];$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM settings_revisions WHERE club_id=? AND section=? FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare Fassungsseite auswählen.':'';
            $rows=$error===''?Db::run($this->db,'SELECT version,actor_id,happened_at FROM settings_revisions WHERE club_id=? AND section=? ORDER BY version DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];
            return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'error'=>$error];
        });
    }
    public function revision(string $section,mixed $version):array
    {
        $version=GeneralInput::version($version);if($version<1)throw new DomainException('Ursprüngliche Einstellungsfassung nicht verfügbar.',404);
        return$this->read(function()use($section,$version):array{
            $actor=$this->actor($section);$row=Db::run($this->db,'SELECT * FROM settings_revisions WHERE club_id=? AND section=? AND version=? FOR SHARE NOWAIT',[$actor['club_id'],$section,$version])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Einstellungsfassung nicht verfügbar.',404);
            return['section'=>$section,'version'=>(int)$row['version'],'actor_id'=>$row['actor_id'],'happened_at'=>$row['happened_at'],'data'=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),'payload_json'=>$row['payload'],'sha256'=>hash('sha256',$row['payload'])];
        });
    }
}
