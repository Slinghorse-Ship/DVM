<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Cms\{Events,EventDetails};
use Kulturhus\Native\Delivery\Outbox;
use Kulturhus\Native\Shared\{Access,Authority,Db,Identity,SecretBox};

/** The saved story rule approves only due event stories, never a general publication queue. */
final class StoryAutomation
{
    public function __construct(private PDO $db,private SecretBox $box,private string $mediaRoot,private ?\Closure $http=null,private ?\Closure $uploadHttp=null){}

    public static function configured(PDO $db,Access $access,string $item):bool
    {
        $club=$access->require('social','read')['club_id'];
        try{$access->require('website','read');}catch(DomainException){return false;}
        $row=Db::run($db,"SELECT p.*,s.enabled,s.config_json FROM native_social_event_preparations p JOIN native_social_event_preparation_settings s ON s.club_id=p.club_id WHERE p.club_id=? AND p.item_id=? AND p.kind='story'",[$club,$item])->fetch();
        if(!$row||!(int)$row['enabled'])return false;
        if(!empty((new Workspace($db,$access,'social'))->get($item)['story_link_requested']))return false;
        $config=EventPreparations::config(json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR));
        $event=(new Events($db,$club,$access))->publicId($row['page_id'],true);
        return $event&&$event['published_version']===(int)$row['page_revision']&&in_array($event['event_status'],['scheduled','sold_out'],true)&&EventDetails::start($event)>new DateTimeImmutable('now',new DateTimeZone('UTC'))&&self::plannedAt($row,$event,$config)!==null;
    }

    private static function access(PDO $db,array $row):Access
    {
        $access=Authority::resolve($db,['club_id'=>$row['club_id'],'created_by'=>$row['actor_id'],'authority'=>$row['authority']]);
        foreach([['social','manage'],['social','edit'],['social','publish'],['website','read']]as[$module,$capability])$access->require($module,$capability);
        return $access;
    }

    public static function plannedAt(array $preparation,array $event,array $config):?DateTimeImmutable
    {
        if(!$config['auto_stories']||$config['auto_since']===null)return null;
        $utc=new DateTimeZone('UTC');$zone=new DateTimeZone($event['event_timezone']??'Europe/Berlin');
        $since=new DateTimeImmutable($config['auto_since'],$utc);$created=new DateTimeImmutable($preparation['created_at'],$utc);
        if($preparation['slot']==='initial')$due=$created;
        elseif(preg_match('/\Ad(14|7|3|1|0)\z/D',$preparation['slot'],$match))$due=EventDetails::start($event)->setTimezone($zone)->modify('-'.$match[1].' days')->setTime(10,0)->setTimezone($utc);
        else return null;
        if($created>=$since&&$due<$created)$due=$created;
        if($due<$since)return null;
        return $due;
    }

    public static function due(array $preparation,array $event,array $config,DateTimeImmutable $now):?DateTimeImmutable
    {
        $due=self::plannedAt($preparation,$event,$config);$zone=new DateTimeZone($event['event_timezone']??'Europe/Berlin');
        return $due!==null&&$due<=$now&&$due->setTimezone($zone)->format('Y-m-d')===$now->setTimezone($zone)->format('Y-m-d')?$due:null;
    }

    public function run(string $club,int $limit=3,bool $send=false,?DateTimeImmutable $now=null):array
    {
        if($this->db->inTransaction()||$limit<1||$limit>20)throw new DomainException('Begrenzten Story-Lauf separat ausführen.');
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));$now=$now->setTimezone(new DateTimeZone('UTC'));
        $result=['prepared'=>0,'review'=>0,'deliveries'=>[],'transport'=>$send?'enabled':'disabled'];$lock='auto-stories:'.substr(hash('sha256',$club),0,45);
        if((int)Db::run($this->db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return $result;
        try{
            $row=Db::run($this->db,'SELECT * FROM native_social_event_preparation_settings WHERE club_id=?',[$club])->fetch();
            $config=$row?EventPreparations::config(json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR)):null;
            if($row&&(int)$row['enabled']&&$config['auto_stories']&&$config['auto_since']!==null){
                try{$access=self::access($this->db,$row);}catch(DomainException){$access=null;++$result['review'];}
                if($access){
                    $rows=Db::run($this->db,"SELECT p.*,i.version current_version,i.payload FROM native_social_event_preparations p JOIN khn_social_items i ON i.club_id=p.club_id AND i.id=p.item_id JOIN khcms_events e ON e.club_id=p.club_id AND e.page_id=p.page_id WHERE p.club_id=? AND p.kind='story' AND p.state='ready' AND i.status='draft' AND e.starts_at>? AND e.status IN ('scheduled','sold_out') ORDER BY e.starts_at,p.created_at,p.item_id LIMIT 500",[$club,$now->format('Y-m-d H:i:s')])->fetchAll();
                    $due=[];
                    foreach($rows as$p){if(!empty(json_decode($p['payload'],true,64,JSON_THROW_ON_ERROR)['story_link_requested']))continue;$event=(new Events($this->db,$club,$access))->publicId($p['page_id'],true);if(!$event)continue;$at=self::due($p,$event,$config,$now);if($at!==null)$due[]=['preparation'=>$p,'event'=>$event,'at'=>$at];}
                    usort($due,static fn(array $a,array $b):int=>[$a['at'],$a['preparation']['item_id']]<=>[$b['at'],$b['preparation']['item_id']]);
                    foreach(array_slice($due,0,$limit)as$candidate){
                        try{$this->prepare($access,$row,$candidate['preparation'],$candidate['event'],$candidate['at'],$now);++$result['prepared'];}
                        catch(DomainException|\RuntimeException $failure){
                            ++$result['review'];$p=$candidate['preparation'];
                            $reason=preg_match('/Story-(?:Vorlage|Bild)|Bildangaben|Vereinsdaten|Story-Bild/u',$failure->getMessage())?'story_template':'story_review';
                            Db::run($this->db,"UPDATE native_social_event_preparations SET reason=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND item_id=? AND state='ready' AND version=?",[$reason,$club,$p['item_id'],(int)$p['version']]);
                        }
                    }
                }
            }
            if($send){
                $ids=Db::run($this->db,"SELECT j.id FROM native_delivery_jobs j JOIN native_delivery_sources s ON s.club_id=j.club_id AND s.job_id=j.id JOIN native_social_event_preparations p ON p.club_id=s.club_id AND p.item_id=s.item_id AND p.item_version=s.item_version WHERE j.club_id=? AND j.kind='meta' AND j.status='pending' AND j.due_at<=UTC_TIMESTAMP() AND s.module='social' AND p.kind='story' AND p.state='auto_scheduled' ORDER BY j.due_at,j.id LIMIT ".min(200,$limit*2),[$club])->fetchAll(PDO::FETCH_COLUMN);
                $publisher=new Meta($this->db,$this->box,$this->http,$this->mediaRoot,$this->uploadHttp);
                $result['deliveries']=(new Outbox($this->db,$this->box))->run(function(string $jobClub,array $payload,string $kind,array $job)use($publisher):string{
                    if($kind!=='meta'||($payload['kind']??null)!=='story'||!isset($payload['auto_story']))throw new DomainException('Nur die gespeicherte Story-Automatik ausführen.');
                    return $publisher->send($jobClub,$payload,$job);
                },min(200,$limit*2),['meta'],$club,$ids);
            }
            return $result;
        }finally{Db::run($this->db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }

    private function prepare(Access $access,array $settings,array $p,array $event,DateTimeImmutable $at,DateTimeImmutable $now):void
    {
        if($event['published_version']!==(int)$p['page_revision'])throw new DomainException('Veranstaltungsangaben geändert. Entwurf aktualisieren.');
        $workspace=new Workspace($this->db,$access,'social',$this->mediaRoot);$item=$workspace->get($p['item_id']);
        $expected=(int)($p['current_version']??$p['item_version']);
        if($item['kind']!=='story'||$item['status']!=='draft'||$item['version']!==$expected)throw new DomainException('Story inzwischen bearbeitet.');
        $image=(new StorySeries($this->db,$access,$this->mediaRoot))->ensure($item);
        $imageRow=(new ImageHistory($this->db,$access))->originals($item['id'],[$image])[0];$metadata=json_decode($imageRow['metadata_json'],true,32,JSON_THROW_ON_ERROR);
        if(($metadata['identity_version']??null)!==Identity::forClub($this->db,$access->club())['version'])throw new DomainException('Vereinsdaten geändert. Story-Bild aktualisieren.');
        Db::atomic($this->db,function()use($access,$settings,$p,$expected,$at,$now,$workspace,$image):void{
            $current=Db::run($this->db,'SELECT * FROM native_social_event_preparation_settings WHERE club_id=? FOR SHARE',[$access->club()])->fetch();
            if(!$current||(int)$current['version']!==(int)$settings['version'])throw new DomainException('Story-Automatik inzwischen geändert.');
            self::access($this->db,$current);$config=EventPreparations::config(json_decode($current['config_json'],true,32,JSON_THROW_ON_ERROR));
            $saved=Db::run($this->db,"SELECT * FROM native_social_event_preparations WHERE club_id=? AND item_id=? FOR UPDATE",[$access->club(),$p['item_id']])->fetch();
            $event=(new Events($this->db,$access->club(),$access))->publicId($p['page_id'],true);
            $due=$saved&&$event?self::due($saved,$event,$config,$now):null;
            if(!$saved||$saved['state']!=='ready'||(int)$saved['version']!==(int)$p['version']||!$event||$event['published_version']!==(int)$p['page_revision']||$due===null||$due!=$at||EventDetails::start($event)<=$now)throw new DomainException('Story-Termin oder Veranstaltungsquelle geändert.');
            $approved=$workspace->approve($p['item_id'],$expected);
            if(($approved['approved_image_ids'][0]??null)!==$image)throw new DomainException('Story-Bild inzwischen geändert.');
            $item=$workspace->schedule($p['item_id'],$at->format(DATE_ATOM),$approved['version']);
            Db::run($this->db,"UPDATE native_social_event_preparations SET state='auto_scheduled',reason='',item_version=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND item_id=?",[$item['version'],$access->club(),$item['id']]);
            $rule=['configuration_version'=>(int)$current['version'],'since'=>$config['auto_since'],'actor'=>$current['actor_id'],'authority'=>$current['authority'],'page'=>$p['page_id'],'page_revision'=>(int)$p['page_revision'],'slot'=>$p['slot'],'item'=>$item['id'],'version'=>$item['version'],'due_at'=>$at->format('Y-m-d H:i:s')];
            (new Meta($this->db,$this->box,$this->http,$this->mediaRoot,$this->uploadHttp))->queue($access,$item['id'],$rule['due_at'],$this->mediaRoot,$rule);
            Db::run($this->db,"UPDATE khn_social_jobs SET status='ready',payload=JSON_SET(CAST(payload AS JSON),'$.automatic_story',CAST(? AS JSON)),updated_at=UTC_TIMESTAMP() WHERE club_id=? AND item_id=? AND item_version=? AND status='pending'",[json_encode($rule,JSON_THROW_ON_ERROR),$access->club(),$item['id'],$item['version']]);
        });
    }

    /** Rechecked under the outbox's authority transaction immediately before the API call. */
    public static function authorize(PDO $db,array $job,array $payload,?DateTimeImmutable $now=null):void
    {
        $rule=$payload['auto_story']??null;
        if(!is_array($rule)||($job['kind']??null)!=='meta'||($payload['kind']??null)!=='story')throw new DomainException('Gespeicherte Story-Automatik erforderlich.');
        $row=Db::run($db,'SELECT * FROM native_social_event_preparation_settings WHERE club_id=? FOR SHARE',[$job['club_id']])->fetch();
        if(!$row||!(int)$row['enabled'])throw new DomainException('Story-Automatik ausgeschaltet.');
        $access=self::access($db,$row);$config=EventPreparations::config(json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR));
        if(!$config['auto_stories']||$config['auto_since']===null||($rule['since']??null)!==$config['auto_since']||($rule['configuration_version']??null)!==(int)$row['version']||($rule['actor']??null)!==$row['actor_id']||($rule['authority']??null)!==$row['authority']||($job['actor_id']??null)!==$row['actor_id'])throw new DomainException('Story-Regel oder Berechtigung geändert.');
        $p=Db::run($db,"SELECT * FROM native_social_event_preparations WHERE club_id=? AND item_id=? AND kind='story' FOR SHARE",[$access->club(),$rule['item']??''])->fetch();
        $item=(new Workspace($db,$access,'social'))->get($rule['item']??'');
        $event=$p?(new Events($db,$access->club(),$access))->publicId($p['page_id'],true):null;
        $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if(!$p||$p['state']!=='auto_scheduled'||(int)$p['item_version']!==($rule['version']??null)||$item['kind']!=='story'||$item['version']!==$rule['version']||$item['status']!=='scheduled'||$item['scheduled_at']!==($rule['due_at']??null)||!is_string($job['due_at']??null)||$job['due_at']<$rule['due_at']||$p['page_id']!==($rule['page']??null)||(int)$p['page_revision']!==($rule['page_revision']??null)||$p['slot']!==($rule['slot']??null)||!$event||$event['published_version']!==(int)$p['page_revision']||!in_array($event['event_status'],['scheduled','sold_out'],true)||EventDetails::start($event)<=$now)throw new DomainException('Story oder Veranstaltung inzwischen geändert.');
        $due=self::due($p,$event,$config,$now);
        if($due===null||$due->format('Y-m-d H:i:s')!==$rule['due_at'])throw new DomainException('Dieser Story-Termin ist nicht mehr aktuell.');
    }
}
