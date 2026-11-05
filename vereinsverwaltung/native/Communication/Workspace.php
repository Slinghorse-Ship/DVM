<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use PDO;

/** Independent editorial repository. Transport is handled by the dedicated private worker. */
final class Workspace
{
    private string $items;
    private string $jobs;
    private string $history;
    private string $templateTable;
    private string $automations;

    public function __construct(private PDO $db, private Access $access, public readonly string $module,private ?string $mediaRoot=null)
    {
        $this->items = Schema::table($module, 'items');
        $this->jobs = Schema::table($module, 'jobs');
        $this->history = Schema::table($module, 'revisions');
        $this->templateTable = Schema::table($module, 'templates');
        $this->automations = Schema::table($module, 'automations');
    }

    public function list(): array
    {
        $this->access->require($this->module, 'read');
        return array_map($this->decode(...), Db::run($this->db, "SELECT * FROM $this->items WHERE club_id=? ORDER BY updated_at DESC,id LIMIT 500", [$this->access->club()])->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(string $id,bool $current=false): array
    {
        $this->access->require($this->module, 'read');
        return $this->read($id,$current);
    }

    private function read(string $id, bool $lock = false): array
    {
        Content::id($id);
        $row = Db::run($this->db, "SELECT * FROM $this->items WHERE club_id=? AND id=?".($lock ? ' FOR UPDATE' : ''), [$this->access->club(), $id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Inhalt nicht verfügbar.');
        return $this->decode($row);
    }

    private function decode(array $row): array
    {
        return array_merge(json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR), ['id'=>$row['id'], 'club_id'=>$row['club_id'], 'module'=>$this->module, 'status'=>$row['status'], 'version'=>(int)$row['version'], 'created_at'=>$row['created_at'], 'updated_at'=>$row['updated_at']]);
    }

    private function version(array $item, int $expected): void
    {
        if ($expected !== $item['version']) throw new DomainException('Inhalt inzwischen geändert. Bitte neu laden.');
    }

    private function persist(string $id, array $data, string $status, int $version, bool $insert = false): array
    {
        $club = $this->access->club();
        $now = gmdate('Y-m-d H:i:s');
        unset($data['id'], $data['club_id'], $data['module'], $data['status'], $data['version'], $data['created_at'], $data['updated_at']);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($insert) Db::run($this->db, "INSERT INTO $this->items(club_id,id,payload,status,version,created_at,updated_at) VALUES(?,?,?,?,?,?,?)", [$club,$id,$json,$status,$version,$now,$now]);
        else Db::run($this->db, "UPDATE $this->items SET payload=?,status=?,version=?,updated_at=? WHERE club_id=? AND id=?", [$json,$status,$version,$now,$club,$id]);
        $snapshot = $this->read($id);
        Db::run($this->db, "INSERT INTO $this->history(club_id,item_id,version,payload,actor_id,created_at) VALUES(?,?,?,?,?,?)", [$club,$id,$version,json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$this->access->user()['id'],$now]);
        return $snapshot;
    }

    public function save(array $data, int $expectedVersion = 0): array
    {
        $this->access->require($this->module, 'edit');
        return Db::atomic($this->db, function () use ($data,$expectedVersion): array {
            $this->access->require($this->module, 'edit');
            $id = empty($data['id']) ? bin2hex(random_bytes(16)) : Content::id($data['id']);
            $old = empty($data['id']) ? null : $this->read($id, true);
            if ($old) {
                $this->version($old, $expectedVersion);
                if ($old['status'] === 'ambiguous') throw new DomainException('Unklares Zustellergebnis ist gesperrt und darf nicht automatisch wiederholt werden.');
            } elseif ($expectedVersion !== 0) throw new DomainException('Neue Inhalte beginnen mit Version 0.');
            $merged = array_merge($old ?? [], $data);
            if($this->module==='social'&&$old===null&&$expectedVersion===0&&($merged['kind']??null)==='story'&&($merged['story']??[])===[]){
                $merged['story']=[['title'=>mb_strcut(Content::text($merged['title']??'',300,true),0,200,'UTF-8'),'text'=>mb_strcut(Content::text($merged['summary']??'',2000),0,1200,'UTF-8'),'media_ref'=>'']];
            }
            // Older clients submit only plain body. An explicit body replacement must
            // not be silently overwritten by the previous rich HTML snapshot.
            if (array_key_exists('body',$data) && !array_key_exists('body_html',$data)) unset($merged['body_html']);
            $content = Content::document($this->module, $merged);
            if($this->module==='newsletter'&&($content['document_attachments']??[])){$content['document_attachments']=(new DocumentAttachments($this->db,$this->access,$this->mediaRoot??throw new DomainException('PDF-Speicher fehlt.')))->capture($content['document_attachments'],'edit');}
            if($this->module==='social'&&$content['kind']==='reel'&&!(new Videos($this->db,$this->access,$this->mediaRoot??''))->installed())throw new DomainException('Videos über den Installer aktivieren.');
            $template = $this->findTemplate($content['template']);
            if ($template['kind'] !== $content['kind']) throw new DomainException('Vorlage passt nicht zur Inhaltsart.');
            $content['template_snapshot'] = $template;
            \Kulturhus\Native\Cms\MediaAvailability::assertContentSelectable($this->db,$this->access->club(),$content);
            if($this->module!=='social'){$logo=$this->module==='newsletter'?\Kulturhus\Native\Shared\Identity::forClub($this->db,$this->access->club())['logo_id']:'';$content['_media_usage']=ImageUsage::capture($this->db,$this->access->club(),ImageUsage::refs($content,$logo),$this->module);}
            $content['approved_by'] = null;
            $content['approved_version'] = null;
            $content['scheduled_at'] = null;
            $state = $data['status'] ?? 'draft';
            if (!in_array($state, ['draft','review','archived'], true)) throw new DomainException('Speichern erlaubt Entwurf, Prüfung oder Archiv.');
            if ($state === 'review') $this->requireContent($content);
            $this->cancelJobs($id);
            Db::run($this->db, "UPDATE $this->automations SET status='outdated',version=version+1 WHERE club_id=? AND item_id=? AND status IN ('enabled','paused')", [$this->access->club(),$id]);
            return $this->persist($id,$content,$state,($old['version'] ?? 0)+1,$old === null);
        });
    }

    private function requireContent(array $content): void
    {
        if ($content['body'] === '' && !$content['blocks'] && !$content['story'] && !($content['carousel']??[]) && $content['kind']!=='reel') throw new DomainException('Für die Freigabe fehlt der Inhalt.');
        if ($this->module === 'social' && !$content['channels']) throw new DomainException('Mindestens einen Kanal auswählen.');
        if ($content['kind'] === 'story' && !$content['story']) throw new DomainException('Eine Story benötigt mindestens eine Folie.');
        if($content['kind']==='carousel')Carousel::count($content);
    }

    public function approve(string $id, int $expectedVersion, ?string $videoId=null): array
    {
        $this->access->require($this->module, 'publish');
        return Db::atomic($this->db, function () use ($id,$expectedVersion,$videoId): array {
            $this->access->require($this->module, 'publish');
            $item = $this->read($id,true);
            $this->version($item,$expectedVersion);
            if (!in_array($item['status'], ['draft','review'], true)) throw new DomainException('Nur Entwürfe können freigegeben werden.');
            $this->requireContent($item);
            if($this->module==='newsletter'&&($item['document_attachments']??[]))(new DocumentAttachments($this->db,$this->access,$this->mediaRoot??throw new DomainException('PDF-Speicher fehlt.')))->assertPublication($item['document_attachments']);
            if($this->module==='social'&&$item['kind']==='reel'){
                if($videoId===null||$this->mediaRoot===null)throw new DomainException('Eine originale Videofassung auswählen.');
                $item['approved_video']=(new VideoApproval($this->db,$this->access,$this->mediaRoot))->capture($item,$videoId);
                $item['approved_image_ids']=[];
            } elseif($this->module==='social'){
                $expectedSlides=$item['kind']==='carousel'?Carousel::count($item):($item['kind']==='story'?max(1,count($item['story'])):1);
                $images=(new ImageHistory($this->db,$this->access))->newest($id,$expectedVersion,Images::fingerprint($item),$item['kind'],$expectedSlides);
                if($item['kind']==='story'&&(count($images)!==$expectedSlides||array_map(static fn($image)=>(int)$image['slide'],$images)!==range(0,$expectedSlides-1)))throw new DomainException('Alle Story-Bilder dieser Fassung zuerst speichern und prüfen.');
                if($item['kind']==='carousel'){
                    if($this->mediaRoot===null||count($images)!==$expectedSlides||array_map(static fn($image)=>(int)$image['slide'],$images)!==range(0,$expectedSlides-1))throw new DomainException('Alle Karussell-Bilder dieser Fassung zuerst speichern und prüfen.');
                    $store=new Images($this->db,$this->access,$this->mediaRoot);$identity=Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$this->access->club()])->fetchColumn();
                    foreach($images as$image){$meta=json_decode($image['metadata_json'],true,32,JSON_THROW_ON_ERROR);if(($meta['identity_version']??null)!==($identity===false?0:(int)$identity))throw new DomainException('Vereinsdaten geändert. Karussell-Bilder erneut speichern.');$store->file($image['id']);$store->jpeg($image['id']);}
                }
                $latest=[];foreach($images as$image){ImageUsage::image($this->db,$this->access->club(),$image);$latest[(int)$image['slide']]=$image['id'];}ksort($latest);$item['approved_image_ids']=array_values($latest);
            }
            if($this->module!=='social')ImageUsage::item($this->db,$this->access->club(),$item,$this->module);
            $item['approved_by'] = $this->access->user()['id'];
            $item['approved_authority'] = $this->access instanceof \Kulturhus\Native\Integration\ManagerAccess ? 'manager' : 'native';
            $item['approved_version'] = $expectedVersion+1;
            return $this->persist($id,$item,'approved',$expectedVersion+1);
        });
    }

    public function schedule(string $id, string $at, int $expectedVersion): array
    {
        $this->access->require($this->module, 'publish');
        $due = Content::time($at);
        return Db::atomic($this->db, function () use ($id,$due,$expectedVersion): array {
            $this->access->require($this->module, 'publish');
            $item = $this->read($id,true);
            $this->version($item,$expectedVersion);
            if ($item['status'] !== 'approved' || $item['approved_version'] !== $expectedVersion) throw new DomainException('Die aktuelle Fassung benötigt eine Freigabe.');
            if($this->module!=='social')ImageUsage::item($this->db,$this->access->club(),$item,$this->module);
            elseif($item['kind']==='reel')$this->checkVideo($item);
            else foreach($item['approved_image_ids']??[]as$image)ImageUsage::storedImage($this->db,$this->access->club(),$image);
            $recipients = $this->recipientIds($item);
            $item['scheduled_at'] = $due;
            $item = $this->persist($id,$item,'scheduled',$expectedVersion+1);
            $now = gmdate('Y-m-d H:i:s');
            $job = ['scheduled_by'=>$this->access->user()['id'],'scheduled_authority'=>$this->access instanceof \Kulturhus\Native\Integration\ManagerAccess?'manager':'native','snapshot'=>$item, 'recipient_ids'=>$recipients, 'mode'=>'local-only', 'transport'=>'disabled'];
            Db::run($this->db, "INSERT INTO $this->jobs(club_id,id,item_id,item_version,due_at,status,payload,created_at,updated_at) VALUES(?,?,?,?,?,'pending',?,?,?)", [$this->access->club(),bin2hex(random_bytes(16)),$id,$item['version'],$due,json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$now,$now]);
            return $item;
        });
    }

    public function renewVideoApproval(string $id,int $expectedVersion,string $videoId): array
    {
        if($this->module!=='social'||$this->mediaRoot===null)throw new DomainException('Eigenes Reel erneut öffnen.');
        Content::id($videoId);$this->access->require('social','publish');
        return Db::atomic($this->db,function()use($id,$expectedVersion,$videoId):array{
            $actor=$this->access->require('social','publish');$club=$actor['club_id'];
            $item=$this->read($id,true);$this->version($item,$expectedVersion);
            if($item['kind']!=='reel'||!in_array($item['status'],['approved','scheduled','local_ready'],true)||($item['approved_video']['id']??null)!==$videoId)throw new DomainException('Genau die freigegebene Videofassung auswählen.');
            if(Db::run($this->db,"SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.id=s.job_id AND j.club_id=s.club_id WHERE s.club_id=? AND s.module='social' AND s.item_id=? AND j.status IN ('processing','uncertain') FOR SHARE",[$club,$id])->fetchColumn())throw new DomainException('Unklares Veröffentlichungsergebnis zuerst prüfen.');
            $original=Db::run($this->db,'SELECT * FROM native_social_videos WHERE club_id=? AND item_id=? AND id=? FOR SHARE',[$club,$id,$videoId])->fetch();
            $identity=Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=? FOR SHARE',[$club])->fetchColumn();
            if(!$original||!hash_equals($original['content_hash'],Images::fingerprint($item))||(int)$original['identity_version']!==($identity===false?0:(int)$identity))throw new DomainException('Inhalt oder Vereinsdaten wurden geändert. Neue Videofassung speichern.');
            VideoArchive::assertSelectable($this->db,$club,$original['source_id']);
            (new Videos($this->db,$this->access,$this->mediaRoot))->file($videoId);
            $item['approved_by']=null;$item['approved_version']=null;$item['approved_image_ids']=[];$item['scheduled_at']=null;unset($item['approved_video']);
            $this->cancelJobs($id);
            Db::run($this->db,"UPDATE native_delivery_jobs j JOIN native_delivery_sources s ON s.job_id=j.id AND s.club_id=j.club_id SET j.status='cancelled',j.reason='video_review_renewed',j.completed_at=UTC_TIMESTAMP() WHERE s.club_id=? AND s.module='social' AND s.item_id=? AND j.status='pending'",[$club,$id]);
            Db::run($this->db,"UPDATE $this->automations SET status='outdated',version=version+1 WHERE club_id=? AND item_id=? AND status IN ('enabled','paused')",[$club,$id]);
            $draft=$this->persist($id,$item,'draft',$expectedVersion+1);
            $new=bin2hex(random_bytes(16));
            Db::run($this->db,'INSERT INTO native_social_videos VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$new,$club,$id,$original['source_id'],$draft['version'],Images::fingerprint($draft),(int)$original['identity_version']]);
            return ['item'=>$draft,'video_id'=>$new];
        });
    }

    private function checkVideo(array $item): void
    {
        if($this->mediaRoot===null)throw new DomainException('Privaten Videospeicher prüfen.');
        (new VideoApproval($this->db,$this->access,$this->mediaRoot))->check($item);
    }

    private function recipientIds(array $item): array
    {
        if ($this->module === 'newsletter') return array_column((new Subscribers($this->db,$this->access))->eligible($item['audience']), 'id');
        if ($this->module === 'press') {
            $available = (new PressContacts($this->db,$this->access))->activeIds($item['recipient_ids']);
            if (array_diff($item['recipient_ids'],$available)) throw new DomainException('Ein ausgewählter Pressekontakt ist nicht mehr aktiv.');
            return $item['recipient_ids'];
        }
        return [];
    }

    private function cancelJobs(string $id): void
    {
        Db::run($this->db, "UPDATE $this->jobs SET status='cancelled',updated_at=? WHERE club_id=? AND item_id=? AND status IN ('pending','ready')", [gmdate('Y-m-d H:i:s'),$this->access->club(),$id]);
    }

    /** Moves due approved snapshots into a local preparation queue; never calls a transport. */
    public function runDue(?string $at = null): array
    {
        $this->access->require($this->module,'publish');
        $this->access->require($this->module,'edit');
        $due = $at === null ? gmdate('Y-m-d H:i:s') : Content::time($at);
        $rows = Db::run($this->db, "SELECT id,item_id FROM $this->jobs WHERE club_id=? AND status='pending' AND due_at<=? ORDER BY due_at,id LIMIT 100", [$this->access->club(),$due])->fetchAll(PDO::FETCH_ASSOC);
        $prepared = 0;
        foreach ($rows as $row) {
            $prepared += Db::atomic($this->db, function () use ($row,$due): int {
                $this->access->require($this->module,'publish');
                $this->access->require($this->module,'edit');
                $item = $this->read($row['item_id'],true);
                $job = Db::run($this->db, "SELECT * FROM $this->jobs WHERE club_id=? AND id=? FOR UPDATE", [$this->access->club(),$row['id']])->fetch(PDO::FETCH_ASSOC);
                if (!$job || $job['status'] !== 'pending' || $job['due_at'] > $due) return 0;
                if ($item['status'] !== 'scheduled' || $item['version'] !== (int)$job['item_version']) {
                    Db::run($this->db, "UPDATE $this->jobs SET status='cancelled',updated_at=? WHERE club_id=? AND id=?", [gmdate('Y-m-d H:i:s'),$this->access->club(),$row['id']]);
                    return 0;
                }
                $payload = json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
                try {
                    if($this->module!=='social')ImageUsage::item($this->db,$this->access->club(),$payload['snapshot'],$this->module);
                    elseif($payload['snapshot']['kind']==='reel')$this->checkVideo($payload['snapshot']);
                    else foreach($payload['snapshot']['approved_image_ids']??[]as$image)ImageUsage::storedImage($this->db,$this->access->club(),$image);
                    foreach([[$payload['scheduled_by']??'', $payload['scheduled_authority']??'native'],[$item['approved_by']??'', $item['approved_authority']??'native']] as [$actor,$authority]){
                        $current=\Kulturhus\Native\Shared\Authority::resolve($this->db,['created_by'=>$actor,'authority'=>$authority,'club_id'=>$item['club_id']]);$user=$current->require($this->module,'publish');
                        if($user['club_id']!==$item['club_id'])throw new DomainException('Vereinszuordnung geändert.');
                    }
                } catch(DomainException) {Db::run($this->db,"UPDATE $this->jobs SET status='cancelled',updated_at=? WHERE club_id=? AND id=?",[gmdate('Y-m-d H:i:s'),$this->access->club(),$row['id']]);return 0;}
                // Revoked consents and removed contacts can only shrink an approved audience.
                if ($this->module === 'press') $allowed = (new PressContacts($this->db,$this->access))->activeIds($payload['recipient_ids']);
                else $allowed = $this->recipientIds($item);
                $payload['recipient_ids'] = array_values(array_intersect($payload['recipient_ids'],$allowed));
                Db::run($this->db, "UPDATE $this->jobs SET status='ready',payload=?,updated_at=? WHERE club_id=? AND id=?", [json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),gmdate('Y-m-d H:i:s'),$this->access->club(),$row['id']]);
                $queuedExternal=in_array($this->module,['social','press'],true)&&Db::run($this->db,"SELECT 1 FROM native_delivery_sources s JOIN native_delivery_jobs j ON j.id=s.job_id AND j.club_id=s.club_id WHERE s.club_id=? AND s.module=? AND s.item_id=? AND s.item_version=? AND j.kind=? AND j.status IN ('pending','processing','uncertain','sent') LIMIT 1 FOR SHARE",[$this->access->club(),$this->module,$item['id'],$item['version'],$this->module==='press'?'press':'meta'])->fetchColumn();
                if(!$queuedExternal)$this->persist($item['id'],$item,'local_ready',$item['version']+1);
                return 1;
            });
        }
        return ['mode'=>'local-only','prepared'=>$prepared,'drafts_prepared'=>$this->runAutomations($due),'sent'=>0,'transport'=>'disabled'];
    }

    public function queue(): array
    {
        $this->access->require($this->module,'read');
        $rows = Db::run($this->db, "SELECT id,item_id,item_version,due_at,status,payload,created_at,updated_at FROM $this->jobs WHERE club_id=? ORDER BY due_at DESC,id LIMIT 500", [$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $payload = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            $row['recipient_count'] = count($payload['recipient_ids']);
            $row['mode'] = 'local-only';
            $row['transport'] = 'disabled';
            unset($row['payload']);
        }
        return $rows;
    }

    /** Records uncertainty for an externally reconciled legacy attempt; it cannot authorize a retry. */
    public function markAmbiguous(string $id, int $expectedVersion): array
    {
        $this->access->require($this->module,'manage');
        return Db::atomic($this->db,function () use ($id,$expectedVersion): array {
            $this->access->require($this->module,'manage');
            $item = $this->read($id,true);
            $this->version($item,$expectedVersion);
            if ($item['status'] === 'ambiguous') return $item;
            Db::run($this->db, "UPDATE $this->jobs SET status='uncertain',updated_at=? WHERE club_id=? AND item_id=? AND status IN ('pending','ready')", [gmdate('Y-m-d H:i:s'),$this->access->club(),$id]);
            return $this->persist($id,$item,'ambiguous',$expectedVersion+1);
        });
    }

    public function revisions(string $id): array
    {
        $this->get($id);
        $rows = Db::run($this->db, "SELECT version,payload,actor_id,created_at FROM $this->history WHERE club_id=? AND item_id=? ORDER BY version DESC", [$this->access->club(),$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['payload'] = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        return $rows;
    }

    public function preview(string $id): string { return Content::preview($this->get($id)); }

    public function revision(string $id,int $version): array
    {
        $this->access->require($this->module,'read');Content::id($id);if($version<1)throw new DomainException('Originalfassung auswählen.');
        $row=Db::run($this->db,"SELECT r.payload FROM $this->history r JOIN $this->items i ON i.id=r.item_id AND i.club_id=r.club_id WHERE r.club_id=? AND r.item_id=? AND r.version=?",[$this->access->club(),$id,$version])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Originalfassung nicht verfügbar.');$original=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        if(($original['id']??null)!==$id||($original['club_id']??null)!==$this->access->club()||($original['module']??null)!==$this->module||($original['version']??null)!==$version)throw new DomainException('Ursprünglichen Fassungsnachweis prüfen.');
        $this->access->require($this->module,'read');return$original;
    }

    public function templates(): array
    {
        $this->access->require($this->module,'read');
        $result = array_map(static fn(array $t): array => $t+['version'=>1,'builtin'=>true], Content::defaults($this->module));
        if($this->module==='social'&&!(new Videos($this->db,$this->access,$this->mediaRoot??''))->installed())$result=array_values(array_filter($result,static fn(array $t):bool=>$t['kind']!=='reel'));
        foreach (Db::run($this->db, "SELECT id,version,payload FROM $this->templateTable WHERE club_id=? ORDER BY id", [$this->access->club()])->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR)+['id'=>$row['id'],'version'=>(int)$row['version'],'builtin'=>false];
        }
        return $result;
    }

    public function templateChoices(): array
    {
        $this->access->require($this->module,'read');
        $rows=array_map(static fn(array $t):array=>array_intersect_key($t,array_flip(['id','name','kind'])),Content::defaults($this->module));
        if($this->module==='social'&&!(new Videos($this->db,$this->access,$this->mediaRoot??''))->installed())$rows=array_values(array_filter($rows,static fn(array $t):bool=>$t['kind']!=='reel'));
        $own=Db::run($this->db,"SELECT id,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')) AS name,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.kind')) AS kind FROM $this->templateTable WHERE club_id=?",[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
        usort($own,static fn(array $a,array $b):int=>strcmp(mb_strtolower($a['name'],'UTF-8'),mb_strtolower($b['name'],'UTF-8'))?:strcmp($a['id'],$b['id']));return array_merge($rows,$own);
    }

    public function getTemplate(string $id, bool $lock = false): array
    {
        $this->access->require($this->module,'read');
        foreach (Content::defaults($this->module) as $template) {
            if ($template['id'] !== $id) continue;
            if ($this->module === 'social' && $template['kind'] === 'reel' && !(new Videos($this->db,$this->access,$this->mediaRoot??''))->installed()) break;
            return $template + ['version'=>1,'builtin'=>true];
        }
        Content::id($id);
        $row=Db::run($this->db,"SELECT id,version,payload FROM $this->templateTable WHERE club_id=? AND id=?".($lock?' FOR UPDATE':''),[$this->access->club(),$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new DomainException('Vorlage nicht verfügbar.');
        return json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR)+['id'=>$row['id'],'version'=>(int)$row['version'],'builtin'=>false];
    }

    private function findTemplate(string $id): array { return $this->getTemplate($id); }

    /** The human-selected template version is locked until its new draft is committed. */
    public function draftFromTemplate(string $id,int $expectedVersion): array
    {
        $this->access->require($this->module,'edit');
        return Db::atomic($this->db,function()use($id,$expectedVersion):array{
            $template=$this->getTemplate($id,true);
            if($template['version']!==$expectedVersion) throw new DomainException('Vorlage inzwischen geändert. Aktuellen Stand öffnen.');
            return $this->fromTemplate($id);
        });
    }

    public function templateFromItem(string $id,int $expectedVersion,string $name): array
    {
        $this->access->require($this->module,'manage');
        return Db::atomic($this->db,function()use($id,$expectedVersion,$name):array{
            $source=$this->get($id,true);$this->version($source,$expectedVersion);
            $source['name']=$name;unset($source['id']);return$this->saveTemplate($source);
        });
    }

    /** A historical original becomes a new current revision; it never replaces history. */
    public function restoreTemplate(string $id,int $expectedVersion,int $sourceVersion): array
    {
        $this->access->require($this->module,'manage');
        return Db::atomic($this->db,function()use($id,$expectedVersion,$sourceVersion):array{
            $current=$this->getTemplate($id,true);
            if($current['builtin']||$current['version']!==$expectedVersion) throw new DomainException('Aktuellen eigenen Vorlagenstand öffnen.');
            $history=Schema::table($this->module,'template_revisions');
            $raw=Db::run($this->db,"SELECT payload FROM $history WHERE club_id=? AND item_id=? AND version=?",[$this->access->club(),$id,$sourceVersion])->fetchColumn();
            if(!is_string($raw)) throw new DomainException('Vorlagenfassung nicht verfügbar.');
            $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$data['id']=$id;
            return $this->saveTemplate($data,$expectedVersion);
        });
    }

    public function saveTemplate(array $data, int $expectedVersion = 0): array
    {
        $this->access->require($this->module,'manage');
        return Db::atomic($this->db,function () use ($data,$expectedVersion): array {
            $this->access->require($this->module,'manage');
            $id = empty($data['id']) ? bin2hex(random_bytes(16)) : Content::id($data['id']);
            $old = empty($data['id']) ? null : Db::run($this->db, "SELECT version FROM $this->templateTable WHERE club_id=? AND id=? FOR UPDATE", [$this->access->club(),$id])->fetch(PDO::FETCH_ASSOC);
            if ((!empty($data['id']) && !$old) || ($old ? (int)$old['version'] : 0) !== $expectedVersion) throw new DomainException('Vorlage inzwischen geändert oder nicht verfügbar.');
            $valid = Content::document($this->module,$data+['title'=>$data['name'] ?? '']);
            $valid = array_intersect_key($valid,array_flip(['title','body','body_html','blocks','kind',...($this->module==='social'?['summary','story','channels','destination_url','image_design']:[])]));
            $valid['name'] = Content::text($data['name'] ?? '',200,true);
            \Kulturhus\Native\Cms\MediaAvailability::assertContentSelectable($this->db,$this->access->club(),$valid);
            $json = json_encode($valid,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $now = gmdate('Y-m-d H:i:s');
            if ($old) Db::run($this->db, "UPDATE $this->templateTable SET version=?,payload=?,updated_at=? WHERE club_id=? AND id=?", [$expectedVersion+1,$json,$now,$this->access->club(),$id]);
            else Db::run($this->db, "INSERT INTO $this->templateTable(club_id,id,version,payload,updated_at) VALUES(?,?,1,?,?)", [$this->access->club(),$id,$json,$now]);
            $history = Schema::table($this->module,'template_revisions');
            Db::run($this->db, "INSERT INTO $history(club_id,item_id,version,payload,actor_id,created_at) VALUES(?,?,?,?,?,?)", [$this->access->club(),$id,$expectedVersion+1,$json,$this->access->user()['id'],$now]);
            return $valid+['id'=>$id,'version'=>$expectedVersion+1,'builtin'=>false];
        });
    }

    public function fromTemplate(string $id, array $overrides = []): array
    {
        $template = $this->findTemplate($id);
        unset($overrides['id']);
        $content = array_intersect_key($template,array_flip(['title','body','body_html','blocks','kind',...($this->module==='social'?['summary','story','carousel','channels','destination_url','image_design']:[])]));
        if (array_key_exists('body',$overrides) && !array_key_exists('body_html',$overrides)) unset($content['body_html']);
        return $this->save(array_merge($content, $overrides, ['template'=>$id]),0);
    }

    public function templateRevisions(string $id): array
    {
        $this->access->require($this->module,'read');
        Content::id($id);
        $this->findTemplate($id);
        $table = Schema::table($this->module,'template_revisions');
        $rows = Db::run($this->db,"SELECT version,payload,actor_id,created_at FROM $table WHERE club_id=? AND item_id=? ORDER BY version DESC",[$this->access->club(),$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['payload'] = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        return $rows;
    }

    /** One-shot reminder creates a NEW draft for review. It never approves or transmits. */
    public function planAutomation(string $id, string $at, int $expectedVersion): array
    {
        $this->access->require($this->module,'manage');
        $due = Content::time($at);
        return Db::atomic($this->db,function () use ($id,$due,$expectedVersion): array {
            $this->access->require($this->module,'manage');
            $item = $this->read($id,true);
            $this->version($item,$expectedVersion);
            if (!in_array($item['status'],['approved','scheduled','local_ready'],true)) throw new DomainException('Automatische Vorschläge benötigen eine freigegebene Quelle.');
            $existing = Db::run($this->db, "SELECT * FROM $this->automations WHERE club_id=? AND item_id=? AND source_version=?", [$this->access->club(),$id,$expectedVersion])->fetch(PDO::FETCH_ASSOC);
            if ($existing) throw new DomainException('Für diese Fassung existiert bereits eine Planung.');
            $automation = bin2hex(random_bytes(16));
            Db::run($this->db, "INSERT INTO $this->automations(club_id,id,item_id,source_version,version,due_at,status,created_at,actor_id,authority) VALUES(?,?,?,?,1,?,'enabled',?,?,?)", [$this->access->club(),$automation,$id,$expectedVersion,$due,gmdate('Y-m-d H:i:s'),$this->access->user()['id'],$this->access instanceof \Kulturhus\Native\Integration\ManagerAccess?'manager':'native']);
            return ['id'=>$automation,'item_id'=>$id,'source_version'=>$expectedVersion,'version'=>1,'due_at'=>$due,'status'=>'enabled'];
        });
    }

    public function automations(): array
    {
        $this->access->require($this->module,'read');
        return Db::run($this->db,"SELECT id,item_id,source_version,version,due_at,status FROM $this->automations WHERE club_id=? ORDER BY due_at,id",[$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pauseAutomation(string $id, int $expectedVersion, bool $paused): void
    {
        $this->access->require($this->module,'manage');
        Content::id($id);
        Db::atomic($this->db,function () use ($id,$expectedVersion,$paused): void {
            $this->access->require($this->module,'manage');
            $changed = Db::run($this->db,"UPDATE $this->automations SET status=?,version=version+1 WHERE club_id=? AND id=? AND version=? AND status IN ('enabled','paused')",[$paused?'paused':'enabled',$this->access->club(),$id,$expectedVersion])->rowCount();
            if (!$changed) throw new DomainException('Planung inzwischen geändert oder nicht verfügbar.');
        });
    }

    public function runAutomations(string $due): int
    {
        // runDue requires publish; creating configured suggestions requires edit as well.
        $this->access->require($this->module,'edit');
        $rows = Db::run($this->db,"SELECT id,item_id FROM $this->automations WHERE club_id=? AND status='enabled' AND due_at<=? ORDER BY due_at,id LIMIT 100",[$this->access->club(),$due])->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            $count += Db::atomic($this->db,function () use ($row,$due): int {
                $this->access->require($this->module,'publish');
                $this->access->require($this->module,'edit');
                $item = $this->read($row['item_id'],true);
                $plan = Db::run($this->db,"SELECT * FROM $this->automations WHERE club_id=? AND id=? FOR UPDATE",[$this->access->club(),$row['id']])->fetch(PDO::FETCH_ASSOC);
                if (!$plan || $plan['status'] !== 'enabled' || $plan['due_at'] > $due) return 0;
                try {
                    $owner=\Kulturhus\Native\Shared\Authority::resolve($this->db,['created_by'=>$plan['actor_id'],'authority'=>$plan['authority'],'club_id'=>$this->access->club()]);
                    $owner->require($this->module,'manage');$owner->require($this->module,'edit');
                } catch (\DomainException) {
                    Db::run($this->db,"UPDATE $this->automations SET status='cancelled',version=version+1 WHERE club_id=? AND id=?",[$this->access->club(),$row['id']]);return 0;
                }
                if ($item['version'] !== (int)$plan['source_version'] || !in_array($item['status'],['approved','scheduled','local_ready'],true)) {
                    Db::run($this->db,"UPDATE $this->automations SET status='outdated',version=version+1 WHERE club_id=? AND id=?",[$this->access->club(),$row['id']]);
                    return 0;
                }
                $copy = Content::document($this->module,$item);
                $copy['template_snapshot'] = $item['template_snapshot'];
                $copy['approved_by'] = null;
                $copy['approved_version'] = null;
                $copy['scheduled_at'] = null;
                $copy['automation_source'] = $item['id'];
                $copy['automation_id'] = $plan['id'];
                $this->persist(bin2hex(random_bytes(16)),$copy,'draft',1,true);
                Db::run($this->db,"UPDATE $this->automations SET status='prepared',version=version+1 WHERE club_id=? AND id=?",[$this->access->club(),$row['id']]);
                return 1;
            });
        }
        return $count;
    }
}
