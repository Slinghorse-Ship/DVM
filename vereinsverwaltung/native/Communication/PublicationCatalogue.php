<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Ten delivery summaries from exact saved originals; no tokens, bodies or provider checkpoints in the UI. */
final class PublicationCatalogue
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public static function query(array $input):array
    {
        $page=filter_var($input['queue_page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        $provider=$input['queue_provider']??'all';$state=$input['queue_state']??'all';$focus=$input['queue_job']??'';
        if($page===false||!is_string($provider)||!in_array($provider,['all','meta','stadtlandfunk'],true)||!is_string($state)||!in_array($state,['all','pending','processing','sent','uncertain','cancelled'],true)||!is_string($focus)||($focus!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$focus)))throw new DomainException('Verlaufsfilter prüfen.');
        return ['page'=>$page,'provider'=>$provider,'state'=>$state,'focus'=>$focus];
    }
    public function listing(Access $access,array $input=[]):array
    {
        $q=self::query($input);$access->require('social','read');
        return Db::atomic($this->db,function()use($access,$q):array{
            $club=$access->require('social','read')['club_id'];$where="j.club_id=? AND j.kind IN ('meta','stadtlandfunk')";$args=[$club];
            if($q['provider']!=='all'){$where.=' AND j.kind=?';$args[]=$q['provider'];}if($q['state']!=='all'){$where.=' AND j.status=?';$args[]=$q['state'];}
            $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_delivery_jobs j WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($pages,$q['page']);
            if($q['focus']!==''){
                $job=Db::run($this->db,'SELECT j.due_at,j.id FROM native_delivery_jobs j WHERE '.$where.' AND j.id=?',[...$args,$q['focus']])->fetch();
                if(!$job)throw new DomainException('Eigener Auftrag in diesem Verlauf nicht verfügbar.');
                $before=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_delivery_jobs j WHERE '.$where.' AND (j.due_at>? OR (j.due_at=? AND j.id>?))',[...$args,$job['due_at'],$job['due_at'],$job['id']])->fetchColumn();$page=intdiv($before,10)+1;
            }
            $rows=Db::run($this->db,"SELECT j.id,j.kind,j.status,j.attempts,j.external_id,j.reason,j.due_at,j.started_at,j.completed_at,s.item_id,s.item_version FROM native_delivery_jobs j LEFT JOIN native_delivery_sources s ON s.club_id=j.club_id AND s.job_id=j.id AND s.module='social' WHERE ".$where.' ORDER BY j.due_at DESC,j.id DESC LIMIT 10 OFFSET '.(($page-1)*10),$args)->fetchAll();
            foreach($rows as&$row){
                $row['has_result']=$row['external_id']!==null&&$row['external_id']!=='';
                $row['title']='Social-Beitrag';$row['title_missing']=true;$row['channel']='meta';$row['content_kind']='post';$row['permalink']=null;
                if($row['kind']==='stadtlandfunk'){
                    $ciphertext=Db::run($this->db,'SELECT ciphertext FROM native_delivery_jobs WHERE club_id=? AND id=?',[$club,$row['id']])->fetchColumn();$payload=$this->box->open('job:'.$club.':'.$row['id'],$ciphertext);
                    $row['title']=$payload['event']['title'];$row['title_missing']=false;$row['channel']='stadtlandfunk';$row['content_kind']='event';
                    if($row['status']==='sent')try{$post=PersonalPublication::postId($row['external_id']);if($post!==null)$row['permalink']='https://www.stadtlandfunk.app/post-detail?'.http_build_query(['id'=>$post]);}catch(DomainException){}
                }elseif($row['item_id']!==null){
                    $original=Db::run($this->db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$row['item_id'],$row['item_version']])->fetchColumn();
                    if(is_string($original)){$original=json_decode($original,true,64,JSON_THROW_ON_ERROR);$row['title']=$original['title'];$row['title_missing']=false;$row['content_kind']=$original['kind'];}
                    $ciphertext=Db::run($this->db,'SELECT ciphertext FROM native_delivery_jobs WHERE club_id=? AND id=?',[$club,$row['id']])->fetchColumn();$payload=$this->box->open('job:'.$club.':'.$row['id'],$ciphertext);
                    if(in_array($payload['channel']??null,['facebook','instagram'],true))$row['channel']=$payload['channel'];
                    if(($payload['kind']??null)==='carousel'){
                        $row['content_kind']='carousel';$row['has_result']=true;$row['carousel_stop']=false;
                        if(CarouselProgress::installed($this->db)){$phases=CarouselProgress::rows($this->db,$club,$row['id'],$payload);$row['carousel_stop']=$row['status']==='uncertain'&&!isset($phases[$row['channel']==='instagram'?11:10]);$row['carousel_ready']=count(array_filter($phases,static fn($phase)=>(int)$phase['slot']<10&&$phase['state']==='known'));$row['carousel_count']=count($payload['images']);}
                    }
                }
                $row['title']=Content::text($row['title'],300,true);unset($row['external_id'],$row['reason']);
            }unset($row);
            $access->require('social','read');if($access->club()!==$club)throw new DomainException('Vereinszuordnung während Verlauf geändert.');
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page,'provider'=>$q['provider'],'state'=>$q['state'],'focus'=>$q['focus']];
        });
    }
}
