<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Events,EventDetails};

/** Read-only projection of saved event drafts and future reminder slots; never queues publication. */
final class EventPreparationMonth
{
    public static function rows(PDO $db,Access $access,array $q,\DateTimeImmutable $now):array
    {
        $club=$access->require('social','read')['club_id'];
        try{$access->require('website','read');}catch(\DomainException){return [];}
        $settings=Db::run($db,'SELECT enabled,config_json FROM native_social_event_preparation_settings WHERE club_id=?',[$club])->fetch();
        if(!$settings)return [];
        $config=EventPreparations::config(json_decode($settings['config_json'],true,32,JSON_THROW_ON_ERROR));
        $zone=new \DateTimeZone('Europe/Berlin');$utc=new \DateTimeZone('UTC');$now=$now->setTimezone($zone);$rows=[];
        $ids=Db::run($db,"SELECT page_id FROM khcms_events WHERE club_id=? AND starts_at>=? AND status IN ('scheduled','sold_out') ORDER BY starts_at,page_id LIMIT 501",[$club,$q['start']->setTimezone($utc)->format('Y-m-d H:i:s')])->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)>500)throw new \DomainException('Zu viele Veranstaltungen für eine vollständige Monatsanzeige.',422);
        foreach($ids as$id){
            $event=(new Events($db,$club,$access))->publicId($id,true);if(!$event)continue;
            $start=EventDetails::start($event)->setTimezone($zone);$slots=[];
            $saved=Db::run($db,'SELECT p.*,i.payload,i.status item_status,i.version current_version FROM native_social_event_preparations p JOIN khn_social_items i ON i.club_id=p.club_id AND i.id=p.item_id WHERE p.club_id=? AND p.page_id=?',[$club,$id])->fetchAll();
            foreach($saved as$p)$slots[$p['kind'].':'.$p['slot']]=['at'=>preg_match('/\Ad(14|7|3|1|0)\z/D',$p['slot'],$reminder)?$start->modify('-'.$reminder[1].' days')->setTime(10,0):new \DateTimeImmutable($p['created_at'],$utc),'kind'=>$p['kind'],'slot'=>$p['slot'],'saved'=>$p];
            if((int)$settings['enabled']&&$start>$now&&$start<=$now->modify('+'.$config['horizon_days'].' days')){
                foreach($config['formats']as$kind)if(!isset($slots[$kind.':initial'])&&!($kind==='story'&&array_filter($saved,static fn($p)=>$p['kind']==='story')))$slots[$kind.':initial']=['at'=>$now,'kind'=>$kind,'slot'=>'initial'];
                if($config['story_reminders']&&in_array('story',$config['formats'],true))foreach([14,7,3,1,0]as$days){$key='story:d'.$days;if(!isset($slots[$key])){$at=$start->modify('-'.$days.' days')->setTime(10,0);if($at>=$now->setTime(0,0))$slots[$key]=['at'=>$at,'kind'=>'story','slot'=>'d'.$days];}}
            }
            foreach($slots as$slot){$at=$slot['at']->setTimezone($zone);if($at<$q['start']||$at>=$q['end'])continue;$p=$slot['saved']??null;
                if($p&&Db::run($db,'SELECT 1 FROM khn_social_jobs WHERE club_id=? AND item_id=? LIMIT 1',[$club,$p['item_id']])->fetchColumn())continue;
                $payload=$p?json_decode($p['payload'],true,64,JSON_THROW_ON_ERROR):[];$state=$p?match($p['state']){'ready'=>'prepared','failed'=>'failed','needs_review','withdrawn'=>'outdated',default=>'pending'}:'enabled';
                if($p&&(int)$p['page_revision']!==(int)$event['published_version'])$state='outdated';
                $automatic=(int)$settings['enabled']&&$slot['kind']==='story'&&$config['auto_stories']&&$config['auto_since']!==null&&(!$p||((int)$p['page_revision']===(int)$event['published_version']&&StoryAutomation::plannedAt($p,$event,$config)!==null));
                if(!$automatic&&$p&&(int)$p['current_version']!==(int)$p['item_version']){$frozen=Db::run($db,'SELECT payload FROM khn_social_revisions WHERE club_id=? AND item_id=? AND version=?',[$club,$p['item_id'],$p['item_version']])->fetchColumn();if(!is_string($frozen)||Images::fingerprint(json_decode($frozen,true,64,JSON_THROW_ON_ERROR))!==Images::fingerprint($payload))$state='outdated';}
                $image=null;if($p&&in_array($slot['kind'],['post','story'],true)){$images=(new ImageHistory($db,$access))->newest($p['item_id'],(int)$p['current_version'],Images::fingerprint($payload),$slot['kind'],1);$image=$images[0]['id']??null;if($image===null&&isset($payload['approved_image_ids'][0])){$candidate=$payload['approved_image_ids'][0];if(Db::run($db,'SELECT 1 FROM native_social_images WHERE club_id=? AND item_id=? AND id=?',[$club,$p['item_id'],$candidate])->fetchColumn())$image=$candidate;}}
                $channels=$payload['channels']??['facebook','instagram'];$title=$payload['title']??$event['title'];
                if($slot['slot']!=='initial')$title.=' · '.match($slot['slot']){'d0'=>'HEUTE','d1'=>'MORGEN',default=>'NOCH '.substr($slot['slot'],1).' TAGE'};
                $rows[]=['id'=>'event:'.$id.':'.$slot['kind'].':'.$slot['slot'],'item_id'=>$p['item_id']??null,'item_version'=>$p?(int)$p['current_version']:null,'target_item_id'=>$p['item_id']??null,'transport'=>'event_preparation','step'=>'automation','phase'=>'prepare','channel'=>'automation','channels'=>$channels,'status'=>$state,'due_at'=>$at->setTimezone($utc)->format('Y-m-d H:i:s'),'day'=>$at->format('Y-m-d'),'time'=>$at->format('H:i T'),'datetime'=>$at->format(DATE_ATOM),'title'=>$title,'title_missing'=>false,'kind'=>$slot['kind'],'image_id'=>$image,'forecast'=>!$p,'automatic_story'=>(bool)$automatic,'needs_approval'=>!$automatic&&(!$p||!in_array($p['item_status'],['approved','scheduled','local_ready'],true))];
            }
        }
        return $rows;
    }
}
