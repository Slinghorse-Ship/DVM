<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
use Kulturhus\Verein\Settings;

/** A separate closed assignment intention and private first original, sharing storage only. */
final class AssignmentRequests
{
    private const SECTION='catalog-assignment';
    public function __construct(private PDO $db,private string $actorId){}
    private static function json(array $value):string{return GeneralRequests::json($value);}
    private function read(callable $operation):mixed{return(new AssignmentRepository($this->db,$this->actorId))->read($operation);}
    private function available():void
    {
        if(!Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_settings_requests'")->fetchColumn())throw new DomainException('Zuweisungsbestätigungen benötigen das aktuelle installierte Paket.',503);
    }
    public function prepare(array $raw):array
    {
        return$this->read(function()use($raw):array{
            $repo=new AssignmentRepository($this->db,$this->actorId);$repo->actor(true);$this->available();$input=AssignmentInput::capture($raw);
            $current=$input['operation']==='end'?$repo->state($input['id'],$input['tab']):['id'=>'','tab'=>$input['tab'],'version'=>0,'data'=>null];
            $normalized=AssignmentInput::normalize($input,$current);
            $source=$input['operation']==='end'?$current['source']:$repo->source($normalized['catalog_id'],$normalized['contact_id'],$input['tab']);
            $review=['section'=>self::SECTION,'input'=>$input,'current'=>$current,'normalized'=>$normalized,'source'=>$source,'overlap'=>$input['operation']==='create'&&$repo->overlaps($normalized)];
            return$review+['hash'=>hash('sha256',self::json($review))];
        });
    }
    public static function issues(array $review):array
    {
        $issues=[];
        if($review['input']['version']!==(string)$review['current']['version'])$issues[]=['code'=>409,'message'=>'Die ursprüngliche Zuweisungsfassung ist nicht mehr aktuell.'];
        if($review['input']['operation']==='create'){
            if(!$review['source']['catalog']['active'])$issues[]=['code'=>422,'message'=>'Aktiven Tarif auswählen.'];
            if($review['overlap'])$issues[]=['code'=>409,'message'=>'Tarif ist in diesem Zeitraum bereits zugewiesen.'];
        }elseif($review['current']['data']['end']!=='')$issues[]=['code'=>409,'message'=>'Diese Tarifzuweisung ist bereits befristet oder beendet.'];
        return$issues;
    }
    public function accept(array $raw,string $key,string $reviewHash,bool $approved):array
    {
        $input=AssignmentInput::capture($raw);
        if(!$approved)throw new DomainException('Die vollständig geprüfte Tarifzuweisung ausdrücklich bestätigen.',422);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Ursprüngliche Zuweisungskennung und Prüfung auswählen.',422);
        $club=Db::run($this->db,'SELECT club_id FROM actors WHERE id=?',[$this->actorId])->fetchColumn();
        if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{
            return Transactions::run($this->db,function()use($input,$key,$reviewHash,$club):array{
                if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$club])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
                $repo=new AssignmentRepository($this->db,$this->actorId);$actor=$repo->actor(true);
                if($actor['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
                $this->available();$json=self::json($input);$hash=hash('sha256',self::SECTION."\0".$json);
                $old=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=? FOR UPDATE NOWAIT',[$club,$key])->fetch();
                if($old){
                    if($old['actor_id']!==$actor['id']||$old['section']!==self::SECTION||!hash_equals($old['input_sha256'],$hash))throw new DomainException('Diese Zuweisungskennung gehört zu anderen ursprünglichen Angaben.',409);
                    $read=$this->decode($old);$this->readable($repo,$read);return$read;
                }
                $review=$this->prepare($input);
                if(!hash_equals($reviewHash,$review['hash']))throw new DomainException('Zuweisung oder Quellen inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
                if($issues=self::issues($review))throw new DomainException($issues[0]['message'],$issues[0]['code']);
                $settings=new Settings($this->db,$this->actorId);$data=$review['normalized'];
                if($input['operation']==='create')$id=$settings->assign($data['catalog_id'],$data['contact_id'],$data['start'],$data['end']);
                else{$id=$input['id'];$settings->endAssignment($id,GeneralInput::version($input['version']),$data['end']);}
                $result=$repo->state($id,$input['tab']);
                if($result['data']!==$review['normalized']||$result['version']!==$review['current']['version']+1||$result['source']!==$review['source'])throw new DomainException('Ursprüngliche Zuweisungsbestätigung bitte prüfen lassen.',503);
                $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                $original=self::json(['format'=>'dvm-catalog-assignment-original/1','request_key'=>$key,'club_id'=>$club,'actor_id'=>$actor['id'],'section'=>self::SECTION,'input'=>$input,'review'=>$review,'approved'=>true,'result'=>$result,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z')]);
                Db::run($this->db,'INSERT INTO native_settings_requests VALUES(?,?,?,?,?,?,?,?,?,?)',[$club,$key,$actor['id'],self::SECTION,$result['version'],$hash,$json,hash('sha256',$original),$original,$now->format('Y-m-d H:i:s.u')]);
                return$this->decode(Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND request_key=?',[$club,$key])->fetch());
            });
        }catch(\PDOException $failure){
            if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Tarifzuweisungen werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
            throw$failure;
        }
    }
    private function decode(array $row):array
    {
        $input=json_decode($row['input_json'],true,64,JSON_THROW_ON_ERROR);$original=json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!is_array($original)||$row['section']!==self::SECTION||!hash_equals($row['input_sha256'],hash('sha256',self::SECTION."\0".$row['input_json']))||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||($original['format']??null)!=='dvm-catalog-assignment-original/1'||($original['request_key']??null)!==$row['request_key']||($original['club_id']??null)!==$row['club_id']||($original['actor_id']??null)!==$row['actor_id']||($original['section']??null)!==self::SECTION||($original['input']??null)!==$input||($original['approved']??null)!==true||($original['review']['section']??null)!==self::SECTION||($original['review']['input']??null)!==$input||($original['result']['tab']??null)!==($input['tab']??null)||($original['result']['version']??null)!==(int)$row['version']||($original['result']['data']??null)!==($original['review']['normalized']??null)||($original['result']['source']??null)!==($original['review']['source']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$original['result']['id']??'')||!in_array($input['operation']??null,['create','end'],true)||(($input['operation']??null)==='end'&&($original['result']['id']??null)!==($input['id']??null)))throw new DomainException('Ursprüngliche Zuweisungsbestätigung bitte prüfen lassen.',503);
        $review=$original['review'];$hash=$review['hash']??'';unset($review['hash']);
        if(!is_string($hash)||!hash_equals($hash,hash('sha256',self::json($review)))||($original['created_at']??null)!==(new DateTimeImmutable($row['created_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'))throw new DomainException('Ursprüngliche Zuweisungsbestätigung bitte prüfen lassen.',503);
        return['key'=>$row['request_key'],'section'=>self::SECTION,'actor_id'=>$row['actor_id'],'input'=>$input,'review'=>$original['review'],'result'=>$original['result'],'created_at'=>$original['created_at'],'original_json'=>$row['original_json'],'sha256'=>$row['original_sha256']];
    }
    private function readable(AssignmentRepository $repo,array $read):void
    {
        try{$repo->contact($read['result']['data']['contact_id']);}
        catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;throw new DomainException('Ursprüngliche Zuweisungsbestätigung ist mit den aktuellen Profilrechten nicht verfügbar.',403,$failure);}
    }
    public function receipt(string $key):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Zuweisungsbestätigung nicht verfügbar.',404);
        return$this->read(function()use($key):array{
            $repo=new AssignmentRepository($this->db,$this->actorId);$actor=$repo->actor();$this->available();
            $row=Db::run($this->db,'SELECT * FROM native_settings_requests WHERE club_id=? AND actor_id=? AND section=? AND request_key=? FOR SHARE NOWAIT',[$actor['club_id'],$actor['id'],self::SECTION,$key])->fetch();
            if(!$row)throw new DomainException('Ursprüngliche Zuweisungsbestätigung nicht verfügbar.',404);
            $read=$this->decode($row);$this->readable($repo,$read);return$read;
        });
    }
    public function history(string $tab,array $input=[]):array
    {
        CatalogRepository::kinds($tab);$query=$input['query']??'';$page=$input['page']??'1';
        if(!is_string($query)||!mb_check_encoding($query,'UTF-8')||mb_strlen($query,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$query)||(!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$page))throw new DomainException('Vollständige Bestätigungssuche und Seite prüfen.',422);
        $page=(string)$page;
        return$this->read(function()use($tab,$query,$page):array{
            $actor=(new AssignmentRepository($this->db,$this->actorId))->actor();$this->available();
            $from=" FROM native_settings_requests r JOIN contacts c ON c.club_id=r.club_id AND c.id=JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.result.data.contact_id')) AND c.archived_at IS NULL";
            $where=" WHERE r.club_id=? AND r.actor_id=? AND r.section=? AND JSON_UNQUOTE(JSON_EXTRACT(r.input_json,'$.tab'))=?";
            $args=[$actor['club_id'],$actor['id'],self::SECTION,$tab];
            if($query!==''){$where.=" AND LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',r.request_key,r.version,r.created_at,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.contact.name')),JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.catalog.data.name')))))>0";$args[]=$query;}
            $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from.$where.' FOR SHARE NOWAIT',$args)->fetchColumn();$pages=max(1,(int)ceil($total/25));
            if((int)$page>$pages)throw new DomainException('Verfügbare Zuweisungsbestätigungsseite auswählen.',422);
            $rows=Db::run($this->db,"SELECT r.request_key,r.version,r.created_at,JSON_UNQUOTE(JSON_EXTRACT(r.input_json,'$.operation')) AS operation,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.result.id')) AS assignment_id,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.contact.name')) AS contact_name,JSON_UNQUOTE(JSON_EXTRACT(r.original_json,'$.review.source.catalog.data.name')) AS catalog_name".$from.$where.' ORDER BY r.created_at DESC,r.request_key DESC LIMIT 25 OFFSET '.(((int)$page-1)*25).' FOR SHARE NOWAIT',$args)->fetchAll();
            return['rows'=>$rows,'query'=>$query,'page'=>(int)$page,'page_input'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>25];
        });
    }
}
