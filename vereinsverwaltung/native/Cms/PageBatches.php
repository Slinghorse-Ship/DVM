<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Each item and its checkpoint commit together. An interrupted request resumes from the original selection. */
final class PageBatches
{
    use ClubPublicationLock;
    public const LIMIT=5000;
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db):void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS khcms_page_batches (
            id CHAR(32) PRIMARY KEY,club_id VARCHAR(64) NOT NULL,direction VARCHAR(12) NOT NULL,
            items_json MEDIUMTEXT NOT NULL,next_index INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_json MEDIUMTEXT NULL,version INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(12) NOT NULL,last_reason VARCHAR(500) NULL,
            created_by VARCHAR(64) NOT NULL,updated_by VARCHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,
            KEY cms_batch_club(club_id,updated_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function create(array $items,string $direction):array
    {
        $cap=self::cap($direction);$this->access->require('website',$cap);
        if(!array_is_list($items)||!$items||count($items)>self::LIMIT)throw new InvalidArgumentException('1–5000 ausgewählte Originalseiten angeben.');
        $seen=[];
        foreach($items as $item){
            if(!is_array($item)||array_keys($item)!==['id','version']||!is_string($item['id'])||!preg_match('/\A[a-f0-9]{32}\z/D',$item['id'])||!is_int($item['version'])||$item['version']<1||$item['version']>4294967294||isset($seen[$item['id']]))throw new InvalidArgumentException('Eindeutige Seitenkennungen und gespeicherte Originalversionen angeben.');
            $seen[$item['id']]=true;
        }
        return Db::atomic($this->db,function()use($items,$direction,$cap):array{
            $actor=$this->access->require('website',$cap);$this->lockClub();
            foreach($items as $item){
                $row=Db::run($this->db,'SELECT p.version,EXISTS(SELECT 1 FROM khcms_trash t WHERE t.page_id=p.id AND t.club_id=p.club_id) AS trashed FROM khcms_pages p WHERE p.id=? AND p.club_id=?',[$item['id'],$actor['club_id']])->fetch();
                if(!$row||(int)$row['version']!==$item['version']||(bool)$row['trashed']!==($direction==='restore'))throw new Conflict('Auswahl wurde inzwischen geändert. Originale neu laden und den Stapel erneut vorbereiten.');
            }
            $id=bin2hex(random_bytes(16));$json=json_encode($items,JSON_THROW_ON_ERROR);
            Db::run($this->db,"INSERT INTO khcms_page_batches(id,club_id,direction,items_json,status,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,'ready',?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$id,$actor['club_id'],$direction,$json,$actor['id'],$actor['id']]);
            return $this->get($id);
        });
    }
    public function createMatching(string $direction,string $query):array
    {
        $cap=self::cap($direction);$this->access->require('website',$cap);
        return Db::atomic($this->db,function()use($direction,$query,$cap):array{
            $this->access->require('website',$cap);$this->lockClub();
            $selection=$this->directory($direction,$query,1,self::LIMIT);
            if($selection['total']<1||$selection['total']>self::LIMIT)throw new InvalidArgumentException('Suche auf 1–5000 Originale eingrenzen.');
            return $this->create(array_map(static fn($row)=>['id'=>$row['id'],'version'=>(int)$row['version']],$selection['rows']),$direction);
        });
    }
    public function get(string $id):array
    {
        $this->access->require('website','read');
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Gültige Stapelkennung angeben.');
        $row=Db::run($this->db,'SELECT * FROM khcms_page_batches WHERE id=? AND club_id=?',[$id,$this->access->club()])->fetch();
        if(!$row)throw new \RuntimeException('Stapel nicht gefunden.');
        $row['items']=json_decode($row['items_json'],true,512,JSON_THROW_ON_ERROR);unset($row['items_json']);
        $row['skipped']=json_decode($row['skipped_json']??'[]',true,512,JSON_THROW_ON_ERROR);unset($row['skipped_json']);
        $row['version']=(int)$row['version'];$row['next_index']=(int)$row['next_index'];$row['total']=count($row['items']);return $row;
    }
    public function advance(string $id,int $expected,int $limit=25):array
    {
        if($limit<1||$limit>25)throw new InvalidArgumentException('Pro Schritt 1–25 Originale bearbeiten.');
        for($i=0;$i<$limit;++$i){
            $job=$this->step($id,$expected,'advance');$expected=$job['version'];
            if($job['status']!=='ready')return $job;
        }
        return $job;
    }
    public function cancel(string $id,int $expected):array{return $this->step($id,$expected,'cancel');}
    public function skip(string $id,int $expected):array{return $this->step($id,$expected,'skip');}
    private function step(string $id,int $expected,string $operation):array
    {
        $first=$this->get($id);$cap=self::cap($first['direction']);$this->access->require('website',$cap);
        return Db::atomic($this->db,function()use($id,$expected,$operation,$cap):array{
            $actor=$this->access->require('website',$cap);$this->lockClub();
            $job=$this->get($id);
            if($expected<1||$job['version']!==$expected)throw new Conflict('Stapel wurde inzwischen fortgesetzt oder geändert. Aktuellen Fortschritt neu laden.');
            if(!in_array($job['status'],['ready','paused'],true))throw new Conflict('Dieser Stapel ist abgeschlossen oder abgebrochen.');
            $next=$job['next_index'];$status='ready';$reason=null;
            if($operation==='cancel')$status='cancelled';
            elseif($operation==='skip'){
                if($job['status']!=='paused')throw new Conflict('Nur ein angehaltener Konflikt darf bewusst übersprungen werden.');
                $job['skipped'][]=['id'=>$job['items'][$next]['id'],'reason'=>$job['last_reason']];
                ++$next;$reason='Original bewusst unverändert übersprungen.';
            }else{
                $item=$job['items'][$next];$trash=new PageTrash($this->db,$this->access);
                try{
                    if($job['direction']==='trash')$trash->move($item['id'],$item['version']);
                    else $trash->restore($item['id'],$item['version']);
                    ++$next;
                }catch(Conflict|InvalidArgumentException $error){$status='paused';$reason=mb_substr($error->getMessage(),0,500);}
            }
            if($status==='ready'&&$next===$job['total'])$status='completed';
            Db::run($this->db,'UPDATE khcms_page_batches SET next_index=?,status=?,last_reason=?,skipped_json=?,version=version+1,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?',[$next,$status,$reason,json_encode($job['skipped'],JSON_THROW_ON_ERROR),$actor['id'],$id,$actor['club_id']]);
            return $this->get($id);
        });
    }
    private static function cap(string $direction):string
    {
        return match($direction){'trash'=>'publish','restore'=>'edit',default=>throw new InvalidArgumentException('Stapelaktion Papierkorb oder privat Zurückholen wählen.')};
    }
    public function directory(string $direction,string $query='',int $page=1,int $size=25):array
    {
        self::cap($direction);$this->access->require('website','read');
        if($size<1||$size>self::LIMIT||$page<1||$page>999999||!preg_match('//u',$query)||preg_match('/[\x00-\x1f\x7f]/',$query)||mb_strlen($query)>120)throw new InvalidArgumentException('Stapel-Suche und Trefferseite prüfen.');
        return Db::atomic($this->db,function()use($direction,$query,$page,$size):array{
            $where='p.club_id=? AND '.($direction==='trash'?PageTrash::activeSql():'NOT ('.PageTrash::activeSql().')');
            $parameters=[$this->access->club()];
            if($query!==''){$where.=" AND LOWER(CONCAT(JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')),' ',p.slug)) LIKE LOWER(?) ESCAPE '!'";$parameters[]='%'.strtr($query,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_pages p WHERE '.$where,$parameters)->fetchColumn();
            $last=max(1,(int)ceil($total/$size));$page=min($page,$last);
            $rows=Db::run($this->db,"SELECT p.id,p.version,p.slug,JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.title')) AS title FROM khcms_pages p WHERE ".$where.' ORDER BY p.updated_at DESC,p.id LIMIT '.$size.' OFFSET '.(($page-1)*$size),$parameters)->fetchAll(PDO::FETCH_ASSOC);
            return compact('rows','total','page','last');
        });
    }
    public function recent():array
    {
        $this->access->require('website','read');
        return Db::run($this->db,'SELECT id,status,direction,next_index,JSON_LENGTH(items_json) AS total FROM khcms_page_batches WHERE club_id=? ORDER BY updated_at DESC,id LIMIT 25',[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
    }
}
