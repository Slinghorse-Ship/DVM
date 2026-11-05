<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Communication\Digests\{Schedule,Plans};

/** Original edition metadata only: a campaign is one entry, never one per recipient. */
final class NewsletterMonth
{
    public function __construct(private PDO $db){}
    public function rows(Access $access,array $q,\DateTimeImmutable $now):array
    {
        $club=$access->require('newsletter','read')['club_id'];$utc=new \DateTimeZone('UTC');$zone=new \DateTimeZone('Europe/Berlin');$start=$q['start']->setTimezone($utc)->format('Y-m-d H:i:s');$end=$q['end']->setTimezone($utc)->format('Y-m-d H:i:s');$rows=[];
        // Outbox retry changes individual due_at values. Its immutable dedupe key
        // permits one distribution per edition version; aggregate the complete
        // distribution before applying the displayed date window.
        $sql="SELECT g.*,JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.title')) title FROM (
            SELECT s.item_id,s.item_version,MIN(j.id) focus_job,COUNT(*) recipients,
              SUM(j.status='pending') pending_count,SUM(j.status='processing') processing_count,
              SUM(j.status='sent') sent_count,SUM(j.status='uncertain') uncertain_count,SUM(j.status='cancelled') cancelled_count,
              COALESCE(MAX(l.due_at),MIN(j.due_at)) original_due_at,
              COALESCE(MIN(IF(j.status='processing',j.due_at,NULL)),MIN(IF(j.status='pending',j.due_at,NULL)),MAX(l.due_at),MIN(j.due_at)) due_at
            FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.club_id=s.club_id AND j.id=s.job_id AND j.kind='campaign'
            LEFT JOIN khn_newsletter_jobs l ON l.club_id=s.club_id AND l.item_id=s.item_id AND l.item_version=s.item_version
            WHERE s.club_id=? AND s.module='newsletter' GROUP BY s.item_id,s.item_version
            ) g LEFT JOIN khn_newsletter_revisions r ON r.club_id=? AND r.item_id=g.item_id AND r.version=g.item_version WHERE g.due_at>=? AND g.due_at<? ORDER BY g.due_at,g.item_id,g.item_version LIMIT 5001";
        $groups=Db::run($this->db,$sql,[$club,$club,$start,$end])->fetchAll(PDO::FETCH_ASSOC);if(count($groups)>5000)throw new DomainException('Zu viele Newsletterausgaben für eine vollständige Monatsanzeige.',422);
        foreach($groups as$group){
            foreach(['recipients','pending_count','processing_count','sent_count','uncertain_count','cancelled_count']as$key)$group[$key]=(int)$group[$key];
            $state=match(true){$group['uncertain_count']>0=>'uncertain',$group['sent_count']===$group['recipients']=>'sent',$group['cancelled_count']===$group['recipients']=>'cancelled',$group['sent_count']>0=>'partial',$group['processing_count']>0=>'processing',$group['pending_count']>0=>'pending',default=>'failed'};
            $rows[]=$this->row('distribution:'.$group['item_id'].':'.$group['item_version'],$group['item_id'],(int)$group['item_version'],$group['due_at'],$state,$group['title'],'newsletter','publication',$zone)+['focus_job'=>$group['focus_job'],'original_due_at'=>$group['original_due_at'],'counts'=>array_intersect_key($group,array_flip(['recipients','pending_count','processing_count','sent_count','uncertain_count','cancelled_count']))];
        }
        // An original local preparation remains useful without a connected mail
        // transport; once its distribution exists, the distribution replaces it.
        $local=Db::run($this->db,"SELECT l.id,l.item_id,l.item_version,l.due_at,l.status,JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.title')) title FROM khn_newsletter_jobs l LEFT JOIN khn_newsletter_revisions r ON r.club_id=l.club_id AND r.item_id=l.item_id AND r.version=l.item_version WHERE l.club_id=? AND l.due_at>=? AND l.due_at<? AND NOT EXISTS(SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.club_id=s.club_id AND j.id=s.job_id AND j.kind='campaign' WHERE s.club_id=l.club_id AND s.module='newsletter' AND s.item_id=l.item_id AND s.item_version=l.item_version) ORDER BY l.due_at,l.id LIMIT 5001",[$club,$start,$end])->fetchAll(PDO::FETCH_ASSOC);if(count($local)>5000)throw new DomainException('Zu viele Newsletterausgaben für eine vollständige Monatsanzeige.',422);
        foreach($local as$job)$rows[]=$this->row('local:'.$job['id'],$job['item_id'],(int)$job['item_version'],$job['due_at'],$job['status'],$job['title'],'newsletter_local','local',$zone);
        // Old distribution jobs without a source are still visible as explicitly
        // unassigned mail work. Never infer a subject from another edition.
        $orphans=Db::run($this->db,"SELECT MIN(j.id) focus_job,j.due_at,COUNT(*) recipients FROM native_delivery_jobs j LEFT JOIN native_delivery_sources s ON s.club_id=j.club_id AND s.job_id=j.id AND s.module='newsletter' WHERE j.club_id=? AND j.kind='campaign' AND s.job_id IS NULL AND j.due_at>=? AND j.due_at<? GROUP BY j.due_at ORDER BY j.due_at LIMIT 5001",[$club,$start,$end])->fetchAll(PDO::FETCH_ASSOC);if(count($orphans)>5000)throw new DomainException('Zu viele Newsletterausgaben für eine vollständige Monatsanzeige.',422);
        foreach($orphans as$job)$rows[]=$this->row('unassigned:'.$job['due_at'],null,null,$job['due_at'],'outdated',null,'newsletter','publication',$zone)+['focus_job'=>$job['focus_job'],'unassigned'=>true,'counts'=>['recipients'=>(int)$job['recipients']]];
        $automatic=Db::run($this->db,"SELECT a.id,a.item_id,a.source_version,a.due_at,a.status,JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.title')) title FROM khn_newsletter_automations a LEFT JOIN khn_newsletter_revisions r ON r.club_id=a.club_id AND r.item_id=a.item_id AND r.version=a.source_version WHERE a.club_id=? AND a.due_at>=? AND a.due_at<? ORDER BY a.due_at,a.id LIMIT 5001",[$club,$start,$end])->fetchAll(PDO::FETCH_ASSOC);if(count($automatic)>5000)throw new DomainException('Zu viele Newsletterausgaben für eine vollständige Monatsanzeige.',422);
        foreach($automatic as$job){$row=$this->row('preparation:'.$job['id'],$job['item_id'],(int)$job['source_version'],$job['due_at'],$job['status'],$job['title'],'newsletter_automation','automation',$zone);$row['phase']='prepare';$row['needs_approval']=true;
            if($job['status']==='prepared'){$target=Db::run($this->db,"SELECT id,status FROM khn_newsletter_items WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.automation_id'))=? ORDER BY created_at,id LIMIT 1",[$club,$job['id']])->fetch(PDO::FETCH_ASSOC);if($target){$row['target_item_id']=$target['id'];$row['needs_approval']=in_array($target['status'],['draft','review'],true);}else$row['status']='outdated';}$rows[]=$row;}
        array_push($rows,...$this->forecasts($club,$q,$now,$zone));
        if($access->require('newsletter','read')['club_id']!==$club)throw new DomainException('Vereinszuordnung während Planung geändert.',409);return$rows;
    }
    private function row(string $key,?string $item,?int $version,string $due,string $state,?string $title,string $transport,string $step,\DateTimeZone $zone):array
    {
        $at=(new \DateTimeImmutable($due,new \DateTimeZone('UTC')))->setTimezone($zone);
        return['id'=>substr(hash('sha256','newsletter-month:'.$key),0,32),'item_id'=>$item,'item_version'=>$version,'transport'=>$transport,'module'=>'newsletter','step'=>$step,'phase'=>$step,'channels'=>['newsletter'],'target_item_id'=>$item,'channel'=>'newsletter','status'=>$state,'due_at'=>$due,'day'=>$at->format('Y-m-d'),'time'=>$at->format('H:i T'),'datetime'=>$at->format(DATE_ATOM),'title'=>$title!==null?Content::text($title,320,true):'','title_missing'=>$title===null,'kind'=>'newsletter','image_id'=>null,'needs_approval'=>false];
    }
    private function forecasts(string $club,array $q,\DateTimeImmutable $now,\DateTimeZone $zone):array
    {
        $plans=Db::run($this->db,'SELECT period,paused,prepare_days,send_hour FROM native_digest_plans WHERE club_id=? ORDER BY period',[$club])->fetchAll(PDO::FETCH_ASSOC);$rows=[];$seen=[];$utc=new \DateTimeZone('UTC');
        $first=$q['start']->modify('first day of this month')->setTime(0,0);$last=$q['end']->modify('first day of next month')->setTime(0,0);
        foreach($plans as$plan)for($month=$first;$month<$last;$month=$month->modify('+1 month')){
            $schedule=Schedule::at($plan['period'],$month,(int)$plan['prepare_days'],(int)$plan['send_hour']);$key=$plan['period'].':'.$schedule['period_key'];if(isset($seen[$key]))continue;$seen[$key]=true;
            $edition=Db::run($this->db,'SELECT e.item_id,e.schedule_json,i.version,i.status,i.created_at,JSON_UNQUOTE(JSON_EXTRACT(i.payload,\'$.title\')) title FROM native_digest_editions e LEFT JOIN khn_newsletter_items i ON i.club_id=e.club_id AND i.id=e.item_id WHERE e.club_id=? AND e.period=? AND e.period_key=?',[$club,$plan['period'],$schedule['period_key']])->fetch(PDO::FETCH_ASSOC);
            if($edition&&is_string($edition['schedule_json'])){try{$original=json_decode($edition['schedule_json'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Gespeicherte Newsletterplanung prüfen.',422);}if(!is_array($original)||($original['period_key']??null)!==$schedule['period_key']||!is_bool($original['quarter']??null))throw new DomainException('Gespeicherte Newsletterplanung prüfen.',422);foreach(['prepare_at','send_at','deadline']as$field){if(!is_string($original[$field]??null))throw new DomainException('Gespeicherte Newsletterplanung prüfen.',422);Content::time($original[$field]);}$schedule=$original;}
            $paused=(bool)$plan['paused']||Plans::paused($this->db,$club,$plan['period'],$schedule['period_key']);$item=$edition['item_id']??null;$version=$edition&&$edition['version']!==null?(int)$edition['version']:null;$title=$edition['title']??null;
            $hasJobs=$item!==null&&(Db::run($this->db,'SELECT 1 FROM khn_newsletter_jobs WHERE club_id=? AND item_id=? LIMIT 1',[$club,$item])->fetchColumn()||Db::run($this->db,"SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.club_id=s.club_id AND j.id=s.job_id AND j.kind='campaign' WHERE s.club_id=? AND s.module='newsletter' AND s.item_id=? LIMIT 1",[$club,$item])->fetchColumn());
            foreach(['prepare'=>'prepare_at','send'=>'send_at']as$phase=>$field){
                if($phase==='send'&&$hasJobs)continue;$at=$phase==='prepare'&&is_string($edition['created_at']??null)?new \DateTimeImmutable($edition['created_at'],$utc):new \DateTimeImmutable($schedule[$field]);
                if($at<$q['start']||$at>=$q['end'])continue;
                // A current recurring setting is evidence for future work only.
                // Earlier work needs its actual stored edition, not a forecast.
                if($at<$now&&(!$edition||$phase==='send'))continue;
                $state=$edition&&$version===null?'outdated':($phase==='prepare'&&$edition?'prepared':($paused?'paused':'enabled'));$row=$this->row('automatic:'.$key.':'.$phase,$item,$version,$at->setTimezone($utc)->format('Y-m-d H:i:s'),$state,$title,'newsletter_automation','automation',$zone);$row['phase']=$phase;$row['needs_approval']=!$edition||in_array($edition['status'],['draft','review'],true);$row['title_missing']=$edition&&$version===null;$row['period_mode']=$plan['period'];$row['period_key']=$schedule['period_key'];$row['period_quarter']=$schedule['quarter'];$row['forecast']=$state!=='prepared';$rows[]=$row;
            }
        }
        return$rows;
    }
}
