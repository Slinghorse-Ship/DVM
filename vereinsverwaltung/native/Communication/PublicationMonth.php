<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** Read-only month projection of existing, club-bound original publication jobs. */
final class PublicationMonth
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public static function query(array $input,?\DateTimeImmutable $now=null):array
    {
        $zone=new \DateTimeZone('Europe/Berlin');$now=($now??new \DateTimeImmutable())->setTimezone($zone);
        $month=$input['plan_month']??$now->format('Y-m');$channel=$input['plan_channel']??'all';$state=$input['plan_state']??'all';$step=$input['plan_step']??'all';
        if(!is_string($month)||!preg_match('/\A(?:19|20|21)[0-9]{2}-(?:0[1-9]|1[0-2])\z/D',$month)||!is_string($channel)||!in_array($channel,['all','facebook','instagram','stadtlandfunk','local'],true)||!is_string($state)||!in_array($state,['all','pending','processing','sent','uncertain','cancelled','ready','failed','enabled','paused','prepared','outdated'],true)||!is_string($step)||!in_array($step,['all','publication','local','automation'],true))throw new DomainException('Monat, Plattform und Status prüfen.');
        $first=new \DateTimeImmutable($month.'-01 00:00:00',$zone);$next=$first->modify('+1 month');$start=$first->modify('-'.((int)$first->format('N')-1).' days');$end=$next->modify('+'.((8-(int)$next->format('N'))%7).' days');
        return ['month'=>$month,'channel'=>$channel,'state'=>$state,'step'=>$step,'first'=>$first,'next'=>$next,'start'=>$start,'end'=>$end,'today'=>$now->format('Y-m-d')];
    }
    public function listing(Access $access,array $input=[]):array
    {
        $access->require('social','read');$q=self::query($input);
        return Db::atomic($this->db,function()use($access,$q):array{
            $club=$access->require('social','read')['club_id'];$utc=new \DateTimeZone('UTC');$zone=new \DateTimeZone('Europe/Berlin');
            $range=[$club,$q['start']->setTimezone($utc)->format('Y-m-d H:i:s'),$q['end']->setTimezone($utc)->format('Y-m-d H:i:s')];
            $jobs=Db::run($this->db,"SELECT j.id,j.kind,j.status,j.due_at,s.item_id,s.item_version FROM native_delivery_jobs j LEFT JOIN native_delivery_sources s ON s.club_id=j.club_id AND s.job_id=j.id AND s.module='social' WHERE j.club_id=? AND j.due_at>=? AND j.due_at<? AND j.kind IN ('meta','stadtlandfunk') ORDER BY j.due_at,j.id LIMIT 5001",$range)->fetchAll();
            $local=Db::run($this->db,"SELECT id,item_id,item_version,due_at,status FROM khn_social_jobs WHERE club_id=? AND due_at>=? AND due_at<? AND status IN ('pending','ready','cancelled','failed') ORDER BY due_at,id LIMIT 5001",$range)->fetchAll();
            $automations=Db::run($this->db,'SELECT id,item_id,source_version AS item_version,due_at,status FROM khn_social_automations WHERE club_id=? AND due_at>=? AND due_at<? ORDER BY due_at,id LIMIT 5001',$range)->fetchAll();
            if(count($jobs)>5000||count($local)>5000||count($automations)>5000)throw new DomainException('Zu viele Aufträge für eine vollständige Monatsanzeige. Verlauf verwenden.');
            $rows=[];
            foreach($jobs as$job){
                $cipher=Db::run($this->db,'SELECT ciphertext FROM native_delivery_jobs WHERE club_id=? AND id=?',[$club,$job['id']])->fetchColumn();$payload=$this->box->open('job:'.$club.':'.$job['id'],$cipher);
                $original=null;if($job['item_id']!==null){$saved=Db::run($this->db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$job['item_id'],$job['item_version']])->fetchColumn();if(is_string($saved))$original=json_decode($saved,true,64,JSON_THROW_ON_ERROR);}
                $channel=$job['kind']==='stadtlandfunk'?'stadtlandfunk':($payload['channel']??'meta');if(!in_array($channel,['facebook','instagram','stadtlandfunk'],true))$channel='meta';
                $title=$job['kind']==='stadtlandfunk'?($payload['event']['title']??'Originalfassung nicht verfügbar'):($original['title']??'Originalfassung nicht verfügbar');
                $image=$payload['image_id']??$original['approved_image_ids'][0]??null;
                $job['automatic_story']=$job['kind']==='meta'&&($payload['kind']??null)==='story'&&is_array($payload['auto_story']??null);
                $job['channels']=[$channel];$job['title_missing']=$job['kind']==='stadtlandfunk'?!isset($payload['event']['title']):!isset($original['title']);
                $rows[]=$this->row($job,$channel,$title,$job['kind']==='stadtlandfunk'?'event':($original['kind']??($payload['kind']??'post')),$image,$zone);
            }
            foreach($local as$job){
                // A local preparation is not a second post when its transport job already exists,
                // including cancelled/sent jobs or dates outside this displayed window.
                if(Db::run($this->db,"SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.club_id=s.club_id AND j.id=s.job_id WHERE s.club_id=? AND s.module='social' AND s.item_id=? AND s.item_version=? AND j.due_at=? AND j.kind IN ('meta','stadtlandfunk') LIMIT 1",[$club,$job['item_id'],$job['item_version'],$job['due_at']])->fetchColumn())continue;
                $saved=Db::run($this->db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$job['item_id'],$job['item_version']])->fetchColumn();$original=is_string($saved)?json_decode($saved,true,64,JSON_THROW_ON_ERROR):[];
                $job['kind']='local';$job['channels']=$original['channels']??[];$job['title_missing']=!isset($original['title']);$rows[]=$this->row($job,'local',$original['title']??'Originalfassung nicht verfügbar',$original['kind']??'post',$original['approved_image_ids'][0]??null,$zone);
            }
            foreach($automations as$job){
                $saved=Db::run($this->db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$job['item_id'],$job['item_version']])->fetchColumn();$original=is_string($saved)?json_decode($saved,true,64,JSON_THROW_ON_ERROR):[];
                $job['kind']='automation';$job['channels']=$original['channels']??[];$job['title_missing']=!isset($original['title']);$job['target_item_id']=$job['item_id'];
                if($job['status']==='prepared'){$draft=Db::run($this->db,"SELECT id FROM khn_social_items WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.automation_id'))=? ORDER BY created_at,id LIMIT 1",[$club,$job['id']])->fetchColumn();if(is_string($draft))$job['target_item_id']=$draft;}
                $rows[]=$this->row($job,'automation',$original['title']??'Originalfassung nicht verfügbar',$original['kind']??'post',$original['approved_image_ids'][0]??null,$zone);
            }
            $rows=array_values(array_filter($rows,static fn($row)=>($q['channel']==='all'||in_array($q['channel'],$row['channels'],true)||$row['channel']===$q['channel'])&&($q['state']==='all'||$row['status']===$q['state'])&&($q['step']==='all'||$row['step']===$q['step'])));
            usort($rows,static fn($a,$b)=>[$a['due_at'],$a['id']]<=>[$b['due_at'],$b['id']]);
            $access->require('social','read');if($access->club()!==$club)throw new DomainException('Vereinszuordnung während Planung geändert.');
            return $q+['rows'=>$rows];
        });
    }
    private function row(array $job,string $channel,string $title,string $kind,mixed $image,\DateTimeZone $zone):array
    {
        $at=(new \DateTimeImmutable($job['due_at'],new \DateTimeZone('UTC')))->setTimezone($zone);
        return ['id'=>$job['id'],'item_id'=>$job['item_id'],'item_version'=>$job['item_version'],'transport'=>$job['kind'],'step'=>in_array($job['kind'],['local','automation'],true)?$job['kind']:'publication','channels'=>array_values(array_intersect($job['channels'],['facebook','instagram','stadtlandfunk'])),'target_item_id'=>$job['target_item_id']??$job['item_id'],'channel'=>$channel,'status'=>$job['status'],'due_at'=>$job['due_at'],'day'=>$at->format('Y-m-d'),'time'=>$at->format('H:i T'),'datetime'=>$at->format(DATE_ATOM),'title'=>Content::text($title,300,true),'title_missing'=>$job['title_missing'],'kind'=>$kind,'automatic_story'=>(bool)($job['automatic_story']??false),'image_id'=>is_string($image)&&preg_match('/\A[a-f0-9]{32}\z/D',$image)?$image:null];
    }
}
