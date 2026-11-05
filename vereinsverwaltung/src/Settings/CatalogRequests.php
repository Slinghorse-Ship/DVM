<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use PDO;
use DomainException;
use DateTimeImmutable;
use DateTimeZone;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Settings;
use Kulturhus\Verein\Infrastructure\Database\Transactions;

/** A closed tariff policy shares storage, never another settings operation's decoder. */
final class CatalogRequests
{
    public function __construct(private PDO $db,private string $actorId){}
    private static function json(array $value):string{return GeneralRequests::json($value);}
    private function busy(\Throwable $failure):void{if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarife werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);}
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();try{if($own)$this->db->beginTransaction();$result=$operation();if($own)$this->db->commit();return$result;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();$this->busy($failure);throw$failure;}
    }
    private function available():void{if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Tarifbestätigungen benötigen das aktuelle installierte Paket.',503);}
    public function prepare(array $input):array
    {
        return$this->read(function()use($input):array{
            $repo=new CatalogRepository($this->db,$this->actorId);$actor=$repo->actor(true);$this->available();$input=CatalogInput::capture($input);$normalized=CatalogValues::normalize($input['kind'],$input['data']);$current=$repo->state($input['id'],$input['kind']);$context=CatalogContext::read($this->db,$actor['club_id'],$input['id'],$input['kind'],$normalized);
            $review=['section'=>'catalog','input'=>$input,'normalized'=>$normalized,'current'=>$current,'catalog_context'=>$context];return$review+['hash'=>hash('sha256',self::json($review))];
        });
    }
    public function accept(array $input,string $key,string $reviewHash,bool $approved):array
    {
        $input=CatalogInput::capture($input);if(!$approved)throw new DomainException('Die vollständig geprüften Tarifangaben ausdrücklich bestätigen.',422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Tarifkennung und Prüfung auswählen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
            if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $repo=new CatalogRepository($this->db,$this->actorId);$actor=$repo->actor(true);if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);$this->available();$json=self::json($input);$hash=hash('sha256',"catalog\0".$json);
            $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR UPDATE',[$club,$key])->fetch();if($old){if($old['actor_id']!==$actor['id']||$old['section']!=='catalog'||!hash_equals($old['input_sha256'],$hash))throw new DomainException('Diese Tarifkennung gehört zu anderen ursprünglichen Angaben.',409);return$this->decode($old);}
            $review=$this->prepare($input);if(!hash_equals($reviewHash,$review['hash'])||$input['version']!==(string)$review['current']['version'])throw new DomainException('Dieser Tarif wurde inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);CatalogContext::assert($review['catalog_context'],$review['normalized']);
            $id=(new Settings($this->db,$this->actorId))->saveCatalog($input['id'],GeneralInput::version($input['version']),$input['kind'],$input['data']);$result=$repo->state($id,$input['kind']);if($result['data']!==$review['normalized']||$result['version']!==$review['current']['version']+1)throw new DomainException('Ursprüngliche Tarifbestätigung bitte prüfen lassen.',503);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$original=self::json(['format'=>'dvm-catalog-change-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>'catalog','input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
            Db::run($this->db,'INSERT INTO native_settings_requests VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],'catalog',$result['version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);return$this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
        });}catch(\PDOException $failure){$this->busy($failure);throw$failure;}
    }
    public static function issues(array $review):array
    {
        $issues=[];if($review['input']['version']!==(string)$review['current']['version'])$issues[]=['code'=>409,'message'=>'Die ursprüngliche Tariffassung ist nicht mehr aktuell.'];
        try{CatalogContext::assert($review['catalog_context'],$review['normalized']);}catch(DomainException $failure){$issues[]=['code'=>422,'message'=>$failure->getMessage()];}return$issues;
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($original)||$row['section']!=='catalog'||!hash_equals($row['input_sha256'],hash('sha256',"catalog\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-catalog-change-original/1'||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['section']??null)!=='catalog'||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['section']??null)!=='catalog'||($original['review']['input']??null)!==$input||($original['result']['kind']??null)!==($input['kind']??null)||($original['result']['version']??null)!==(int)$row['version']||($original['result']['data']??null)!==($original['review']['normalized']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$original['result']['id']??''))throw new DomainException('Ursprüngliche Tarifbestätigung bitte prüfen lassen.',503);
        $review=$original['review'];$hash=$review['hash']??'';unset($review['hash']);if(!is_string($hash)||!hash_equals($hash,hash('sha256',self::json($review)))||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Tarifbestätigung bitte prüfen lassen.',503);
        return['key'=>$row['request_key'],'section'=>'catalog','actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Tarifbestätigung nicht verfügbar.',404);
        return$this->read(function()use($key):array{$actor=(new CatalogRepository($this->db,$this->actorId))->actor();$this->available();$row=Db::run($this->db,"SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section='catalog' AND request_key=? FOR SHARE NOWAIT",[$actor['club_id'],$actor['id'],$key])->fetch();if(!$row)throw new DomainException('Ursprüngliche Tarifbestätigung nicht verfügbar.',404);return$this->decode($row);});
    }
    public function history(string $tab,array $query=[]):array
    {
        $kinds=CatalogRepository::kinds($tab);$search=$query['query']??'';$raw=$query['page']??'1';$page=is_string($raw)||is_int($raw)?(string)$raw:'';
        if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$search))throw new DomainException('Tarifsuche mit höchstens 150 Zeichen eingeben.',422);if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Verfügbare Bestätigungsseite auswählen.',422);
        return$this->read(function()use($kinds,$search,$page):array{$actor=(new CatalogRepository($this->db,$this->actorId))->actor();$this->available();$args=[$actor['club_id'],$actor['id'],...$kinds];$where=" WHERE club_id=? AND actor_id=? AND section='catalog' AND JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.kind')) IN (".implode(',',array_fill(0,count($kinds),'?')).')';if($search!==''){$where.=' AND LOCATE(LOWER(?),LOWER(CONCAT(request_key,CHAR(10),version,CHAR(10),created_at)))>0';$args[]=$search;}$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_settings_requests'.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));$error=(int)$page>$pages?'Verfügbare Bestätigungsseite auswählen.':'';$rows=$error===''?Db::run($this->db,"SELECT request_key,version,created_at,JSON_UNQUOTE(JSON_EXTRACT(original_json,'$.result.id')) AS catalog_id,JSON_UNQUOTE(JSON_EXTRACT(input_json,'$.kind')) AS kind FROM native_settings_requests".$where.' ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll():[];return['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page_input'=>$page,'page'=>$error===''?(int)$page:null,'query'=>$search,'per_page'=>25,'error'=>$error];});
    }
}
