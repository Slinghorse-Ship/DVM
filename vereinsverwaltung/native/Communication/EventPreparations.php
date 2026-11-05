<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Kulturhus\Native\Cms\{EventDetails, Events};
use Kulturhus\Native\Shared\{Access, Authority, Db, Identity};

/** Durable, locally rendered drafts. A source/format/slot can create exactly one item. */
final class EventPreparations
{
    public function __construct(private PDO $db, private string $mediaRoot, private ?\Closure $checkpoint = null) {}

    public static function config(array $input): array
    {
        $media = $input['media'] ?? 'both';
        if (!is_string($media) || !in_array($media, ['image', 'video', 'both'], true)) throw new DomainException('Bild, animiertes Video oder beides auswählen.');
        $formats = match ($media) { 'image'=>['post', 'story'], 'video'=>['reel'], 'both'=>['post', 'story', 'reel'] };
        $rawDays = $input['horizon_days'] ?? 365;
        if (!is_string($rawDays) && !is_int($rawDays)) throw new DomainException('Zeitraum, Entwurfssprache und Story-Erinnerungen prüfen.');
        $days = filter_var($rawDays, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>730]]);
        $reminders = $input['story_reminders'] ?? true;
        $locale = $input['locale'] ?? 'de';
        if ($days === false || !is_bool($reminders) || !in_array($locale, ['de', 'en'], true)) throw new DomainException('Zeitraum, Entwurfssprache und Story-Erinnerungen prüfen.');
        $animation = ReelRenderer::animation($input['animation'] ?? 'zoom');
        if ($animation === 'still') throw new DomainException('Animationsvorlage auswählen.');
        $storyOptions=[];
        foreach(['auto_stories'=>false,'story_master'=>true,'story_day_hint'=>true]as$key=>$default){
            $value=$input[$key]??$default;
            if(!is_bool($value))throw new DomainException('Story-Automatik, Bildvorlage und Tageshinweis prüfen.');
            $storyOptions[$key]=$value;
        }
        $since=$input['auto_since']??null;
        if($since!==null&&(!is_string($since)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D',$since)))throw new DomainException('Story-Automatik neu speichern.');
        return ['media'=>$media, 'formats'=>$formats, 'horizon_days'=>$days,
            'story_reminders'=>$reminders, 'locale'=>$locale, 'design'=>ImageDesign::options($input['design'] ?? []),
            'animation'=>$animation]+$storyOptions+['auto_since'=>$since];
    }

    public function settings(Access $access, bool $write = false): array
    {
        $club = $access->require('social', 'manage')['club_id'];
        $row = Db::run($this->db, 'SELECT * FROM native_social_event_preparation_settings WHERE club_id=?'.($this->db->inTransaction() ? ($write ? ' FOR UPDATE' : ' FOR SHARE') : ''), [$club])->fetch();
        return $row ? ['version'=>(int)$row['version'], 'enabled'=>(bool)$row['enabled'], 'config'=>self::config(json_decode($row['config_json'], true, 32, JSON_THROW_ON_ERROR))]
            : ['version'=>0, 'enabled'=>false, 'config'=>self::config([])];
    }

    public function save(Access $access, array $input, bool $enabled, int $version): array
    {
        $access->require('social', 'manage'); $access->require('social', 'edit'); $access->require('website', 'read');
        if ($version < 0 || $version >= 4294967295) throw new DomainException('Fassung der Veranstaltungsautomatik prüfen.');
        $config = self::config($input);
        if ($enabled && in_array('reel', $config['formats'], true)) (new ReelRenderer($this->db, $access, $this->mediaRoot))->requireAvailable();
        return Db::atomic($this->db, function () use ($access, $config, $enabled, $version): array {
            $actor = $access->require('social', 'manage'); $access->require('social', 'edit'); $access->require('website', 'read');
            $current = $this->settings($access, true);
            if ($current['version'] !== $version) throw new DomainException('Veranstaltungsautomatik inzwischen geändert. Neu laden.');
            if($config['auto_stories'])$access->require('social','publish');
            $config['auto_since']=$config['auto_stories']
                ? (($current['enabled']&&$current['config']['auto_stories']&&$current['config']['auto_since']!==null)?$current['config']['auto_since']:(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'))
                : null;
            $authority = $access instanceof \Kulturhus\Native\Integration\ManagerAccess ? 'manager' : 'native';
            $json = json_encode($config, JSON_THROW_ON_ERROR);
            Db::run($this->db, 'INSERT INTO native_social_event_preparation_settings VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),enabled=VALUES(enabled),config_json=VALUES(config_json),actor_id=VALUES(actor_id),authority=VALUES(authority),updated_at=VALUES(updated_at)', [$actor['club_id'], $version+1, (int)$enabled, $json, $actor['id'], $authority]);
            Db::run($this->db, 'INSERT INTO native_social_event_preparation_history VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$actor['club_id'], $version+1, (int)$enabled, $json, $actor['id'], $authority]);
            return $this->settings($access);
        });
    }

    /** Berlin calendar days retain the former 14/7/3/1/today reminder sequence. */
    public static function slots(array $event, array $config, DateTimeImmutable $now): array
    {
        $start = EventDetails::start($event);
        if ($start <= $now || !in_array($event['event_status'], ['scheduled', 'sold_out'], true)) return [];
        $zone = new DateTimeZone($event['event_timezone'] ?? 'Europe/Berlin');
        $days = (int)$now->setTimezone($zone)->setTime(0, 0)->diff($start->setTimezone($zone)->setTime(0, 0))->format('%r%a');
        $reminder = $config['story_reminders'] && in_array($days, [14, 7, 3, 1, 0], true);
        $label = $reminder ? match ($days) { 0=>$config['locale']==='en'?'TODAY':'HEUTE', 1=>$config['locale']==='en'?'TOMORROW':'MORGEN', default=>$config['locale']==='en'?$days.' DAYS TO GO':'NOCH '.$days.' TAGE' } : ($config['locale']==='en'?'SAVE THE DATE':'TERMIN MERKEN');
        $result = [];
        foreach ($config['formats'] as $kind) $result[] = ['kind'=>$kind, 'slot'=>$kind==='story' && $reminder ? 'd'.$days : 'initial', 'label'=>$kind==='post'||!($config['story_day_hint']??true) ? '' : $label];
        // Prepare future reminder originals now, so each calendar selection has an editor.
        if($config['story_reminders']&&in_array('story',$config['formats'],true))foreach([14,7,3,1,0]as$offset){
            if($offset>$days||array_filter($result,static fn($slot)=>$slot['kind']==='story'&&$slot['slot']==='d'.$offset))continue;
            $result[]=['kind'=>'story','slot'=>'d'.$offset,'label'=>!($config['story_day_hint']??true)?'':match($offset){0=>$config['locale']==='en'?'TODAY':'HEUTE',1=>$config['locale']==='en'?'TOMORROW':'MORGEN',default=>$config['locale']==='en'?$offset.' DAYS TO GO':'NOCH '.$offset.' TAGE'}];
        }
        return $result;
    }

    public function listing(Access $access): array
    {
        $club = $access->require('social', 'read')['club_id'];
        return Db::run($this->db, 'SELECT j.*,JSON_UNQUOTE(JSON_EXTRACT(i.payload,\'$.title\')) title,i.version current_version,i.status item_status FROM native_social_event_preparations j JOIN khn_social_items i ON i.club_id=j.club_id AND i.id=j.item_id WHERE j.club_id=? ORDER BY j.updated_at DESC,j.item_id LIMIT 100', [$club])->fetchAll();
    }

    public function retry(Access $access, string $item, int $version): void
    {
        Content::id($item); $club = $access->require('social', 'manage')['club_id']; $access->require('social', 'edit');
        Db::atomic($this->db, function () use ($access, $club, $item, $version): void {
            $settings = $this->settings($access);
            if (!$settings['enabled']) throw new DomainException('Veranstaltungsautomatik zuerst aktivieren.');
            $row = Db::run($this->db, 'SELECT * FROM native_social_event_preparations WHERE club_id=? AND item_id=? FOR UPDATE', [$club, $item])->fetch();
            if (!$row || (int)$row['version'] !== $version || $row['state'] !== 'failed') throw new DomainException('Gespeicherten Entwurfsstatus neu öffnen.');
            $draft = (new Workspace($this->db, $access, 'social', $this->mediaRoot))->get($item);
            if ($draft['version'] !== (int)$row['item_version'] || $draft['status'] !== 'draft') throw new DomainException('Bearbeiteten Entwurf in der Redaktion öffnen.');
            Db::run($this->db, "UPDATE native_social_event_preparations SET state='pending',reason='',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND item_id=?", [$club, $item]);
        });
    }

    /** Refresh presentation on an explicit current draft; approved originals and user animation stay intact. */
    public function refresh(Access $access,string $item,int $itemVersion,int $preparationVersion):array
    {
        Content::id($item);$access->require('social','manage');$access->require('social','edit');$access->require('website','read');
        return Db::atomic($this->db,function()use($access,$item,$itemVersion,$preparationVersion):array{
            $settings=$this->settings($access,true);if(!$settings['enabled'])throw new DomainException('Veranstaltungsautomatik zuerst aktivieren.');
            $row=Db::run($this->db,'SELECT * FROM native_social_event_preparations WHERE club_id=? AND item_id=? FOR UPDATE',[$access->club(),$item])->fetch();
            if(!$row||(int)$row['version']!==$preparationVersion)throw new DomainException('Gespeicherten Entwurfsstatus neu öffnen.');
            $workspace=new Workspace($this->db,$access,'social',$this->mediaRoot);$draft=$workspace->get($item);
            if($draft['version']!==$itemVersion||$draft['status']!=='draft')throw new DomainException('Nur aktuelle nicht freigegebene Entwürfe können neu gestaltet werden.');
            $event=(new Events($this->db,$access->club(),$access))->publicId($row['page_id'],true);
            if(!$event||$event['published_version']!==(int)$row['page_revision']||!in_array($event['event_status'],['scheduled','sold_out'],true))throw new DomainException('Veranstaltungsquelle geändert. Entwurf prüfen.');
            $draft['image_design']=$settings['config']['design'];$english=$settings['config']['locale']==='en';
            $label=$english?'SAVE THE DATE':'TERMIN MERKEN';
            if(preg_match('/\Ad(14|7|3|1|0)\z/D',$row['slot'],$m))$label=match((int)$m[1]){0=>$english?'TODAY':'HEUTE',1=>$english?'TOMORROW':'MORGEN',default=>$english?$m[1].' DAYS TO GO':'NOCH '.$m[1].' TAGE'};
            $strip=static fn(string$text):string=>preg_replace('/\A(?:HEUTE|MORGEN|NOCH [0-9]{1,3} TAGE|TERMIN MERKEN|TODAY|TOMORROW|[0-9]{1,3} DAYS TO GO|SAVE THE DATE)(?:\s*·\s*|\R|\z)/u','',$text,1);
            $place=trim(($event['event_location']??'').' '.($event['event_address']??''));
            $summary=$strip($draft['summary']);if($place!==''&&!str_contains($summary,$place))$summary.="\n".$place;
            if(!$settings['config']['story_day_hint'])$label='';
            $draft['summary']=($draft['kind']==='post'||$label===''?'':$label.' · ').$summary;
            if($draft['kind']==='story')foreach($draft['story']as&$slide)$slide['text']=($label!==''?$label."\n":'').$strip($slide['text']);unset($slide);
            $saved=$workspace->save($draft,$itemVersion);
            Db::run($this->db,"UPDATE native_social_event_preparations SET item_version=?,settings_version=?,state='pending',reason='',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND item_id=? AND version=?",[$saved['version'],$settings['version'],$access->club(),$item,$preparationVersion]);
            return $saved;
        });
    }

    public function run(string $club, int $limit = 3, ?DateTimeImmutable $now = null): array
    {
        if ($this->db->inTransaction() || $limit < 1 || $limit > 20) throw new DomainException('Eigene begrenzte Entwurfsvorbereitung erforderlich.');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $result = ['created'=>0, 'ready'=>0, 'review'=>0, 'failed'=>0];
        $lock = 'social-event-drafts:'.substr(hash('sha256', $club), 0, 42);
        if ((int)Db::run($this->db, 'SELECT GET_LOCK(?,0)', [$lock])->fetchColumn() !== 1) return $result;
        try {
            $row = Db::run($this->db, 'SELECT * FROM native_social_event_preparation_settings WHERE club_id=?', [$club])->fetch();
            if (!$row || !(int)$row['enabled']) return $result;
            $access = Authority::resolve($this->db, ['club_id'=>$club, 'created_by'=>$row['actor_id'], 'authority'=>$row['authority']]);
            $access->require('social', 'manage'); $access->require('social', 'edit'); $access->require('website', 'read');
            $config = self::config(json_decode($row['config_json'], true, 32, JSON_THROW_ON_ERROR));
            $formats = implode(',', array_fill(0, count($config['formats']), '?'));
            $jobs = Db::run($this->db, "SELECT * FROM native_social_event_preparations WHERE club_id=? AND state IN ('pending','rendering') AND kind IN (".$formats.") ORDER BY created_at,item_id LIMIT ".$limit, [$club, ...$config['formats']])->fetchAll();
            $remaining = $limit;
            foreach ($jobs as $job) { $this->render($access, $job, $result, $now); --$remaining; }
            if ($remaining === 0) return $result;
            $events = Db::run($this->db, "SELECT page_id FROM khcms_events WHERE club_id=? AND starts_at>? AND starts_at<=? AND status IN ('scheduled','sold_out') ORDER BY starts_at,page_id LIMIT 500", [$club, $now->format('Y-m-d H:i:s'), $now->modify('+'.$config['horizon_days'].' days')->format('Y-m-d H:i:s')])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($events as $id) {
                $event = (new Events($this->db, $club, $access))->publicId($id, true);
                if (!$event) continue;
                foreach (self::slots($event, $config, $now) as $slot) {
                    if ($slot['kind'] === 'story' && $slot['slot'] === 'initial' && Db::run($this->db, "SELECT 1 FROM native_social_event_preparations WHERE club_id=? AND page_id=? AND kind='story' LIMIT 1", [$club, $id])->fetchColumn()) continue;
                    if (Db::run($this->db, 'SELECT 1 FROM native_social_event_preparations WHERE club_id=? AND page_id=? AND slot=? AND kind=?', [$club, $id, $slot['slot'], $slot['kind']])->fetchColumn()) continue;
                    $job = Db::atomic($this->db, function () use ($access, $row, $config, $event, $slot, $club, $id): array {
                        $current = $this->settings($access);
                        if (!$current['enabled'] || $current['version'] !== (int)$row['version']) throw new DomainException('Veranstaltungsautomatik inzwischen geändert. Neu laden.');
                        $draft = (new EventDrafts($this->db, $access))->create($id, $event['published_version'], 'website', false, $config['locale'], $slot['kind'], $slot['label'], $config['design'], $config['animation']);
                        Db::run($this->db, "INSERT INTO native_social_event_preparations VALUES(?,?,?,?,?,?,?,?,'pending','',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", [$club, $id, $slot['slot'], $slot['kind'], $draft['id'], $draft['version'], $event['published_version'], $row['version']]);
                        return Db::run($this->db, 'SELECT * FROM native_social_event_preparations WHERE club_id=? AND item_id=?', [$club, $draft['id']])->fetch();
                    });
                    ++$result['created'];
                    if ($this->checkpoint) ($this->checkpoint)('draft-created', $job);
                    $this->render($access, $job, $result, $now);
                    if (--$remaining === 0) return $result;
                }
            }
            return $result;
        } finally { Db::run($this->db, 'SELECT RELEASE_LOCK(?)', [$lock]); }
    }

    private function render(Access $access, array $job, array &$result, DateTimeImmutable $now): void
    {
        $item = $job['item_id'];
        $state = $this->settings($access);
        if (!$state['enabled'] || !in_array($job['kind'], $state['config']['formats'], true)) return;
        $access->require('social', 'edit');
        $workspace = new Workspace($this->db, $access, 'social', $this->mediaRoot);
        $draft = $workspace->get($item);
        $event = (new Events($this->db, $access->club(), $access))->publicId($job['page_id'], true);
        if (!$event || !in_array($event['event_status'], ['scheduled', 'sold_out'], true)) { $this->status($access, $job, 'withdrawn', 'event_withdrawn'); ++$result['review']; return; }
        if (EventDetails::start($event) <= $now) { $this->status($access, $job, 'withdrawn', 'event_past'); ++$result['review']; return; }
        if ($event['published_version'] !== (int)$job['page_revision'] || $draft['version'] !== (int)$job['item_version'] || $draft['status'] !== 'draft') { $this->status($access, $job, 'needs_review', 'original_changed'); ++$result['review']; return; }
        try {
            if ($job['kind'] === 'reel') {
                $video = Db::run($this->db, 'SELECT id,identity_version FROM native_social_videos WHERE club_id=? AND item_id=? AND item_version=? AND content_hash=? ORDER BY created_at DESC,id DESC LIMIT 1', [$access->club(), $item, $draft['version'], Images::fingerprint($draft)])->fetch();
                if ($video && (int)$video['identity_version'] !== Identity::forClub($this->db, $access->club())['version']) { $this->status($access, $job, 'needs_review', 'original_changed'); ++$result['review']; return; }
                if ($video) (new Videos($this->db, $access, $this->mediaRoot))->file($video['id']);
                else (new ReelRenderer($this->db, $access, $this->mediaRoot))->render($item, $draft['version'], $draft['video_design']['animation'], substr(hash('sha256', 'event-reel:'.$access->club().':'.$item.':'.$draft['version']), 0, 32));
            } else {
                $images = (new ImageHistory($this->db, $access))->newest($item, $draft['version'], Images::fingerprint($draft), $job['kind'], 1);
                if (!$images) (new Images($this->db, $access, $this->mediaRoot))->render($item);
                else foreach ($images as $image) {
                    $metadata = json_decode($image['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
                    if (($metadata['identity_version'] ?? null) !== Identity::forClub($this->db, $access->club())['version']) { $this->status($access, $job, 'needs_review', 'original_changed'); ++$result['review']; return; }
                    (new Images($this->db, $access, $this->mediaRoot))->file($image['id']);
                }
            }
        } catch (DomainException|\RuntimeException $error) {
            $this->status($access, $job, 'failed', $job['kind']==='reel' ? 'video_preparation' : 'image_preparation'); ++$result['failed'];
            return;
        }
        if ($this->checkpoint) ($this->checkpoint)('media-created', $job);
        $current = $workspace->get($item);
        $event = (new Events($this->db, $access->club(), $access))->publicId($job['page_id'], true);
        if (!$this->settings($access)['enabled']) return;
        if ($current['version'] !== (int)$job['item_version'] || !$event || $event['published_version'] !== (int)$job['page_revision']) { $this->status($access, $job, 'needs_review', 'original_changed'); ++$result['review']; return; }
        $this->status($access, $job, 'ready', ''); ++$result['ready'];
    }

    private function status(Access $access, array $job, string $state, string $reason): void
    {
        $access->require('social', 'edit');
        Db::run($this->db, 'UPDATE native_social_event_preparations SET state=?,reason=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE club_id=? AND item_id=? AND version=?', [$state, $reason, $access->club(), $job['item_id'], $job['version']]);
    }
}
