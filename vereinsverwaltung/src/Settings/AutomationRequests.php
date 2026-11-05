<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{Database,CatalogBillingAutomation};
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** First private activation/pause approval; it never executes the financial worker. */
final class AutomationRequests
{
    private const SECTION='catalog-automation';
    public function __construct(private PDO $db,private string $actorId){}
    private function read(callable $operation):mixed{return(new AutomationRepository($this->db,$this->actorId))->read($operation);}
    private function available():void{foreach(['native_settings_requests','native_catalog_automation','native_catalog_automation_changes','native_catalog_automation_runs']as$table)if(!Database::tableExists($this->db,$table))throw new DomainException('Automatikbestätigungen benötigen das aktuelle installierte Paket.',503);}
    /** Compare typed JSON values without treating MySQL object-key order as a business change. */
    private static function ordered(mixed $value):mixed{if(!is_array($value))return$value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$part)$value[$key]=self::ordered($part);return$value;}
    public function prepare(array $raw):array
    {
        return$this->read(function()use($raw):array{
            $repo=new AutomationRepository($this->db,$this->actorId);$repo->actor(true);$this->available();$input=AutomationInput::capture($raw);$normalized=AutomationInput::normalize($input);$current=$repo->state($input['id'],$input['tab']);$source=$current['source'];$code=(string)$source['catalog']['data']['tax_account'];$source['tax_account']=$repo->taxAccount($normalized['first'],$code);
            if($normalized['enabled']&&$code!==''&&(!$source['tax_account']||!($source['tax_account']['active']??false)||($source['tax_account']['blocked']??false)))throw new DomainException('Aktives ursprüngliches Steuerkonto vor der Automatikfreigabe prüfen.',422);
            $quote=$normalized['enabled']?(new CatalogBillingAutomation($this->db,$this->actorId))->review($input['id'],$normalized['first'],$normalized['mode']):null;$review=['section'=>self::SECTION,'input'=>$input,'normalized'=>$normalized,'current'=>$current,'source'=>$source,'quote'=>$quote,'existing_run'=>$normalized['enabled']?$repo->scheduledRun($input['id'],$input['tab'],$normalized['first']):null];return$review+['hash'=>hash('sha256',GeneralRequests::json($review))];
        });
    }
    public static function issues(array $review):array
    {
        $input=$review['input'];$current=$review['current'];$plan=$current['canonical'];$normalized=$review['normalized'];
        if($input['version']!==(string)$current['version'])return[['code'=>409,'message'=>'Die ursprüngliche Automatikfassung ist nicht mehr aktuell.']];
        if($input['operation']==='pause'){
            if(!$plan)return[['code'=>422,'message'=>'Bestehende eigene Automatik zum Pausieren auswählen.']];if($plan['status']!=='active')return[['code'=>409,'message'=>'Diese Automatik ist bereits pausiert.']];
            if($normalized['first']!==$plan['next_on']||$normalized['mode']!==$plan['mode']||$normalized['due_days']!==(int)$plan['due_days'])return[['code'=>409,'message'=>'Zum Pausieren die vollständige aktuelle Freigabe bewusst erneut prüfen.']];
        }
        return[];
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=AutomationInput::capture($raw);if(!$approved)throw new DomainException('Die vollständig geprüfte Automatikänderung ausdrücklich bestätigen.',422);if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Automatikkennung und Prüfung auswählen.',422);$club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);$repo=new AutomationRepository($this->db,$this->actorId);$actor=$repo->actor(true);if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();$json=GeneralRequests::json($input);$inputHash=hash('sha256',self::SECTION."\0".$json);$old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$club,$key])->fetch();
            if($old){if($old['section']!==self::SECTION||$old['actor_id']!==$actor['id']||!hash_equals($old['input_sha256'],$inputHash)||$old['input_json']!==$json)throw new DomainException('Diese Automatikkennung gehört zu anderen ursprünglichen Angaben.',409);$read=$this->decode($old);$this->readable($repo,$read);return$read;}
            $review=$this->prepare($input);if(!hash_equals($review['hash'],$reviewHash))throw new DomainException('Automatik oder Quellen inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);if($issues=self::issues($review))throw new DomainException($issues[0]['message'],$issues[0]['code']);$data=$review['normalized'];$fingerprint=$review['quote']['fingerprint']??'';
            (new CatalogBillingAutomation($this->db,$this->actorId))->change($input['id'],(int)$input['version'],$data['first'],$data['mode'],$data['due_days'],$fingerprint,$key,$data['enabled']);$actual=$repo->state($input['id'],$input['tab']);$canonical=$actual['canonical'];if(!$canonical||$actual['version']!==(int)$input['version']+1||$actual['source']!==$review['current']['source'])throw new DomainException('Tatsächliches Automatikergebnis bitte prüfen lassen.',503);
            if($data['enabled']){
                if($canonical['status']!=='active'||$canonical['next_on']!==$data['first']||$canonical['mode']!==$data['mode']||(int)$canonical['due_days']!==$data['due_days']||$canonical['source_hash']!==$review['quote']['source_fingerprint']||$canonical['approved_by']!==$actor['id']||self::ordered(json_decode($canonical['approved_quote'],true,64,JSON_THROW_ON_ERROR))!==self::ordered($review['quote']))throw new DomainException('Tatsächliches Automatikergebnis bitte prüfen lassen.',503);
            }else{$expected=$review['current']['canonical'];$expected['version']++;$expected['status']='paused';$expected['reason']='manual';$compare=$canonical;unset($expected['changed_at'],$compare['changed_at']);if($compare!==$expected)throw new DomainException('Tatsächliches Automatikergebnis bitte prüfen lassen.',503);}
            $result=['id'=>$input['id'],'tab'=>$input['tab'],'version'=>$actual['version'],'canonical'=>$canonical,'source'=>$actual['source']];$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=GeneralRequests::json(['format'=>'dvm-catalog-automation-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);Db::run($this->db,'INSERT INTO native_settings_requests(club_id,request_key,actor_id,section,input_sha256,input_json,original_sha256,original_json,version,created_at)VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,$inputHash,$json,hash('sha256',$original),$original,$result['version'],$now->format('Y-m-d H:i:s.u')]);return$this->receipt($key);
        });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifautomatik wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw$failure;}
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($input)||!is_array($original)||$row['section']!==self::SECTION||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-catalog-automation-original/1'||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['section']??null)!==self::SECTION||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['section']??null)!==self::SECTION||($original['review']['input']??null)!==$input||($original['result']['id']??null)!==($input['id']??null)||($original['result']['tab']??null)!==($input['tab']??null)||($original['result']['version']??null)!==(int)$row['version']||($original['result']['canonical']['version']??null)!==(int)$row['version']||($original['result']['canonical']['assignment_id']??null)!==($input['id']??null)||($original['result']['source']??null)!==($original['review']['current']['source']??null)||($original['result']['canonical']['status']??null)!==(($input['operation']??null)==='activate'?'active':'paused'))throw new DomainException('Ursprüngliche Automatikbestätigung bitte prüfen lassen.',503);
        $review=$original['review'];$hash=$review['hash']??'';unset($review['hash']);if(!is_string($hash)||!hash_equals($hash,hash('sha256',GeneralRequests::json($review)))||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Automatikbestätigung bitte prüfen lassen.',503);return['key'=>$row['request_key'],'section'=>self::SECTION,'actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    private function readable(AutomationRepository $repo,array $read):void
    {
        try{$repo->contact($read['result']['source']['contact']['id']);}catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;throw new DomainException('Ursprüngliche Automatikbestätigung ist mit den aktuellen Profilrechten nicht verfügbar.',403,$failure);}
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Automatikbestätigung nicht verfügbar.',404);return$this->read(function()use($key):array{
            $repo=new AutomationRepository($this->db,$this->actorId);$actor=$repo->actor();$this->available();$row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();if(!$row)throw new DomainException('Ursprüngliche Automatikbestätigung nicht verfügbar.',404);$read=$this->decode($row);$this->readable($repo,$read);return$read;
        });
    }
    public function history(string $tab,array $input=[]):array
    {
        CatalogRepository::kinds($tab);$query=$input['query']??'';$page=$input['page']??'1';if(!is_string($query)||!mb_check_encoding($query,'UTF-8')||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige Automatikbestätigungssuche und Seite prüfen.',422);$page=(string)$page;
        return$this->read(function()use($tab,$query,$page):array{
            $actor=(new AutomationRepository($this->db,$this->actorId))->actor();$this->available();$from=" FROM native_settings_requests r JOIN contacts c ON c.club_id=r.club_id AND c.id=JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.result.source.contact.id')) AND c.archived_at IS NULL";$where=" WHERE r.club_id=? AND r.actor_id=? AND r.section=? AND JSON_UNQUOTE(JSON_EXTRACT(r.input_json,'$.tab'))=?";$args=[$actor['club_id'],$actor['id'],self::SECTION,$tab];if($query!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',r.request_key,r.version,r.created_at,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.contact.name')),JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.catalog.data.name')))))>0";$args[]=$query;}$total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));if((int)$page>$pages)throw new DomainException('Automatikbestätigungsseite ist nicht mehr verfügbar.',422);
            $rows=Db::run($this->db,"SELECT r.request_key,r.version,r.created_at,JSON_UNQUOTE(JSON_EXTRACT(r.input_json,'$.operation')) AS operation,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.contact.name')) AS contact_name,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.catalog.data.name')) AS catalog_name".$from.$where.' ORDER BY r.created_at DESC,r.request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll();return['rows'=>$rows,'query'=>$query,'page'=>(int)$page,'page_input'=>$page,'pages'=>$pages,'total'=>$total,'size'=>25];
        });
    }
}
