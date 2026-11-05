<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Shared\{Access,Db,RichText};
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict,CustomFields,DataModel,FieldPolicy};
/** Append-only translations never edit a source draft, approval or published original. */
final class Translations
{
    use ClubPublicationLock;
    public function __construct(private \PDO $db,private Access $access){}
    public function get(string $page,string $locale):array
    {
        (new Settings($this->db,$this->access))->require($locale,'edit');$source=$this->source($page);$row=$this->row($page,$locale);
        return $row?['page_id'=>$page,'locale'=>$locale,'version'=>(int)$row['version'],'source_revision'=>(int)$row['source_revision'],'payload'=>$this->readablePayload($page,(int)$row['source_revision'],json_decode($row['draft_json'],true,64,JSON_THROW_ON_ERROR)),'published_revision'=>$row['published_revision']===null?null:(int)$row['published_revision'],'source_version'=>(int)$source['version'],'source_published_revision'=>$source['published_revision']===null?null:(int)$source['published_revision']]:['page_id'=>$page,'locale'=>$locale,'version'=>0,'source_revision'=>(int)($source['published_revision']??$source['version']),'payload'=>['slug'=>$source['published_slug']??$source['slug'],'fields'=>[]],'published_revision'=>null,'source_version'=>(int)$source['version'],'source_published_revision'=>$source['published_revision']===null?null:(int)$source['published_revision']];
    }
    public function save(string $page,string $locale,array $payload,int $sourceRevision,int $expected):array
    {
        return $this->change($page,$locale,$expected,'save',function(array $source,?array $old)use($payload,$sourceRevision):array{
            $original=$this->original($source['id'],$sourceRevision);return ['source'=>$sourceRevision,'payload'=>$this->normalize($payload,$original,$old?json_decode($old['draft_json'],true,64,JSON_THROW_ON_ERROR):[],$old?$this->original($source['id'],(int)$old['source_revision']):null),'published'=>$old['published_revision']??null];
        });
    }
    public function publish(string $page,string $locale,int $expected):array
    {
        return $this->change($page,$locale,$expected,'publish',function(array $source,?array $old)use($page,$locale,$expected):array{
            if(!$old)throw new \DomainException('Die Übersetzung zuerst speichern.');
            if($source['published_revision']===null||(int)$source['published_revision']!==(int)$old['source_revision'])throw new Conflict('Die Übersetzung gehört nicht zur aktuell veröffentlichten Originalfassung.');
            $payload=json_decode($old['draft_json'],true,64,JSON_THROW_ON_ERROR);if(!empty($payload['requires_review']))throw new Conflict('Kopierte Übersetzung zuerst prüfen und ausdrücklich speichern.');
            unset($payload['_approval']);$original=$this->original($page,(int)$old['source_revision']);
            if(\Kulturhus\Native\Cms\NewsChannels::isNews($original)){
                $policy=(new \Kulturhus\Native\Cms\NewsPolicy($this->db,$this->access))->get();
                if($policy['enabled']){
                    $author=Db::run($this->db,"SELECT actor_id FROM native_language_revisions WHERE club_id=? AND page_id=? AND locale=? AND action IN ('save','restore','copy') ORDER BY revision DESC LIMIT 1",[$this->access->club(),$page,$locale])->fetchColumn();$reviewer=$this->access->require('website','publish')['id'];
                    if(!$author||$author===$reviewer)throw new \DomainException('Eine andere Person mit Sprach-Veröffentlichungsrecht muss die Beitragsübersetzung freigeben.');
                    $payload['_approval']=['policy_version'=>$policy['version'],'author_id'=>$author,'reviewer_id'=>$reviewer,'source_revision'=>(int)$old['source_revision']];
                }
            }
            $this->reserve($page,$locale,$payload['slug']);
            return ['source'=>(int)$old['source_revision'],'payload'=>$payload,'published'=>$expected+1];
        });
    }
    public function unpublish(string $page,string $locale,int $expected):array
    {
        return $this->change($page,$locale,$expected,'unpublish',function(array $source,?array $old):array{
            if(!$old||$old['published_revision']===null)throw new Conflict('Diese Übersetzung ist bereits zurückgezogen.');
            return ['source'=>(int)$old['source_revision'],'payload'=>json_decode($old['draft_json'],true,64,JSON_THROW_ON_ERROR),'published'=>null];
        });
    }
    public function restore(string $page,string $locale,int $revision,int $expected):array
    {
        return $this->change($page,$locale,$expected,'restore',function(array $source,?array $old)use($page,$locale,$revision):array{
            $row=Db::run($this->db,'SELECT source_revision,payload_json FROM native_language_revisions WHERE club_id=? AND page_id=? AND locale=? AND revision=?',[$this->access->club(),$page,$locale,$revision])->fetch();
            if(!$row)throw new \DomainException('Übersetzungsfassung nicht gefunden.');
            $original=$this->original($page,(int)$row['source_revision']);$payload=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$payload=$this->protectPatch($payload,$original,$old?json_decode($old['draft_json'],true,64,JSON_THROW_ON_ERROR):[],$old?$this->original($page,(int)$old['source_revision']):null);return ['source'=>(int)$row['source_revision'],'payload'=>$payload,'published'=>$old['published_revision']??null];
        });
    }
    public function revisions(string $page,string $locale,int $before=4294967295):array
    {
        (new Settings($this->db,$this->access))->require($locale,'edit');$this->source($page);
        $rows=Db::run($this->db,'SELECT revision,source_revision,payload_json,actor_id,action,changed_at FROM native_language_revisions WHERE club_id=? AND page_id=? AND locale=? AND revision<? ORDER BY revision DESC LIMIT 20',[$this->access->club(),$page,$locale,$before])->fetchAll();
        foreach($rows as &$row){$row['payload']=$this->readablePayload($page,(int)$row['source_revision'],json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR));unset($row['payload_json']);}return $rows;
    }
    public function sourceSnapshot(string $page,string $locale,int $revision):array
    {
        (new Settings($this->db,$this->access))->require($locale,'edit');$this->source($page);$source=$this->original($page,$revision);$payload=FieldPolicy::privatePayload($this->db,$this->access,$source);$payload['field_definitions']=FieldPolicy::editorDefinitions($this->db,$this->access,$payload['field_definitions']??[],$source['field_definitions']??[]);return $payload;
    }
    /** Part of the caller's private page-copy transaction. Never copies publication or route ownership. */
    public function cloneDrafts(string $sourceId,string $targetId,string $basis='draft'):int
    {
        if(!$this->db->inTransaction())throw new \DomainException('Sprachkopien gehören in die gemeinsame Seitenkopie-Transaktion.');
        if(!in_array($basis,['draft','published'],true)||$sourceId===$targetId||!preg_match('/\A[a-f0-9]{32}\z/D',$sourceId)||!preg_match('/\A[a-f0-9]{32}\z/D',$targetId))throw new \InvalidArgumentException('Original und neue private Sprachkopie prüfen.');
        $this->lockClub();$actor=$this->access->require('website','edit');$settings=new Settings($this->db,$this->access);if($settings->state()['version']===0)return 0;
        $source=Db::run($this->db,'SELECT id,published_revision FROM khcms_pages WHERE club_id=? AND id=?',[$this->access->club(),$sourceId])->fetch();
        \Kulturhus\Native\Cms\TemplatePolicy::assertCapability($this->db,$this->access,$sourceId,'read');$target=$this->source($targetId,'edit');if(!$source||$target['published_revision']!==null)throw new \DomainException('Eigene Quelle und unveröffentlichte Zielseite erforderlich.');
        if($basis==='published'&&$source['published_revision']===null)throw new Conflict('Die Quelle hat keine öffentliche Fassung.');
        if(Db::run($this->db,'SELECT 1 FROM native_language_pages WHERE club_id=? AND page_id=? LIMIT 1',[$this->access->club(),$targetId])->fetchColumn())throw new Conflict('Die Zielseite besitzt bereits eigene Sprachfassungen.');
        $rows=$basis==='draft'?Db::run($this->db,'SELECT locale,source_revision,draft_json AS payload_json FROM native_language_pages WHERE club_id=? AND page_id=? ORDER BY locale',[$this->access->club(),$sourceId])->fetchAll():Db::run($this->db,'SELECT r.locale,r.source_revision,r.payload_json FROM native_language_pages p JOIN native_language_revisions r ON r.club_id=p.club_id AND r.page_id=p.page_id AND r.locale=p.locale AND r.revision=p.published_revision WHERE p.club_id=? AND p.page_id=? AND r.source_revision=? ORDER BY r.locale',[$this->access->club(),$sourceId,(int)$source['published_revision']])->fetchAll();
        foreach($rows as $row){
            $settings->require($row['locale'],'edit',false);$payload=json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);$this->assertPatchCopy($sourceId,(int)$row['source_revision'],$payload);$payload['requires_review']=true;$payload['copied_from']=['page_id'=>$sourceId,'source_revision'=>(int)$row['source_revision']];$json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            Db::run($this->db,'INSERT INTO native_language_pages (club_id,page_id,locale,version,source_revision,draft_json,published_revision) VALUES (?,?,?,1,?,?,NULL)',[$this->access->club(),$targetId,$row['locale'],(int)$target['version'],$json]);
            Db::run($this->db,"INSERT INTO native_language_revisions (club_id,page_id,locale,revision,source_revision,payload_json,actor_id,action,changed_at) VALUES (?,?,?,1,?,?,?,'copy',UTC_TIMESTAMP(6))",[$this->access->club(),$targetId,$row['locale'],(int)$target['version'],$json,$actor['id']]);
        }return count($rows);
    }
    /** Re-project committed or replayed DTO text using current source/field authority, also for publishers. */
    public function privatePayload(string $page,int $sourceRevision,array $payload):array
    {$this->source($page);return $this->readablePayload($page,$sourceRevision,$payload);}

    /** Complete authorized native transfer only; no approval, actor or copied provenance data. */
    public function exportDrafts(string $page):array
    {
        return Db::atomic($this->db,function()use($page):array{
        $this->lockClub();$this->access->require('website','manage');$this->access->require('website','edit');$this->source($page);
        $settings=new Settings($this->db,$this->access);if($settings->state()['version']===0)return [];$result=[];
        $rows=Db::run($this->db,'SELECT locale,source_revision FROM native_language_pages WHERE club_id=? AND page_id=? ORDER BY locale',[$this->access->club(),$page])->fetchAll();
        foreach($rows as $row){$settings->require($row['locale'],'edit',false);$json=Db::run($this->db,'SELECT draft_json FROM native_language_pages WHERE club_id=? AND page_id=? AND locale=?',[$this->access->club(),$page,$row['locale']])->fetchColumn();if(!is_string($json))throw new Conflict('Sprachoriginal wurde inzwischen geändert.');$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR);$this->assertPatchCopy($page,(int)$row['source_revision'],$payload);$original=$this->original($page,(int)$row['source_revision']);$history=[];$model=new DataModel($this->db,$this->access);foreach($payload['fields']['custom_fields']??[]as$key=>$value){$rules=$this->history($payload['field_policy_history'][$key]??null);foreach([$original['field_definitions'][$key]??null,$payload['field_definitions'][$key]??null,$payload['field_source_definitions'][$key]??null,$model->field($key)]as$definition)if($definition!==null)$rules=self::joinHistory($rules,self::definitionHistory($definition));$history[$key]=$rules;}$result[]=['page_id'=>$page,'locale'=>$row['locale'],'payload'=>array_intersect_key($payload,array_flip(['slug','fields'])),'field_policy_history'=>$history];}
        return $result;
        });
    }

    public static function normalizeFieldHistory(mixed $input):array
    {
        if(!is_array($input)||($input!==[]&&array_is_list($input))||count($input)>100)throw new \InvalidArgumentException('Geschlossene Sprachfeldrechte prüfen.');$result=[];
        foreach($input as$key=>$rules){CustomFields::key($key);$result[$key]=self::validatedHistory($rules);}ksort($result,SORT_STRING);return $result;
    }

    /** Server-only native transfer into a new private original inside the caller's transaction. */
    public function importDraft(string $page,string $locale,array $payload,int $sourceRevision,array $history=[]):array
    {
        if(!$this->db->inTransaction())throw new \DomainException('Sprachimport gehört in die gemeinsame private Importtransaktion.');
        return Db::atomic($this->db,function()use($page,$locale,$payload,$sourceRevision,$history):array{
        $this->access->require('website','manage');$this->access->require('website','edit');$this->lockClub();$source=$this->source($page,'edit');
        if($sourceRevision!==1||(int)$source['version']!==1||$source['published_revision']!==null||$this->row($page,$locale)!==null)throw new Conflict('Sprachimport benötigt eine neue unveröffentlichte Originalseite ohne vorhandene Sprachfassung.');
        $history=self::normalizeFieldHistory($history);$values=$payload['fields']['custom_fields']??[];if(!is_array($values)||array_diff(array_keys($history),array_keys($values)))throw new \InvalidArgumentException('Sprachrechte gehören zu übertragenen Textfeldern.');
        foreach($history as$rules)if(!$this->historyAllowed($rules,true))throw new \DomainException('Die übertragenen Sprachangaben sind nicht vollständig bearbeitbar.');
        $this->save($page,$locale,$payload,1,0);$row=$this->row($page,$locale);$saved=json_decode($row['draft_json'],true,64,JSON_THROW_ON_ERROR);
        foreach($history as$key=>$rules)$saved['field_policy_history'][$key]=self::joinHistory($this->history($saved['field_policy_history'][$key]??null),$rules);
        $json=json_encode($saved,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($json)>2*1024*1024)throw new \InvalidArgumentException('Übersetzung auf höchstens 2 MiB begrenzen.');
        Db::run($this->db,'UPDATE native_language_pages SET draft_json=? WHERE club_id=? AND page_id=? AND locale=? AND version=1 AND published_revision IS NULL',[$json,$this->access->club(),$page,$locale]);
        Db::run($this->db,"UPDATE native_language_revisions SET payload_json=? WHERE club_id=? AND page_id=? AND locale=? AND revision=1 AND action='save'",[$json,$this->access->club(),$page,$locale]);return $this->get($page,$locale);
        });
    }

    /** Binds a resumable copy to the chosen exact language state, including publication and text. */
    public function sourceFingerprint(string $sourceId,string $basis='draft'):string
    {
        $this->access->require('website','edit');if(!preg_match('/\A[a-f0-9]{32}\z/D',$sourceId)||!in_array($basis,['draft','published'],true))throw new \InvalidArgumentException('Sprachquelle prüfen.');\Kulturhus\Native\Cms\TemplatePolicy::assertCapability($this->db,$this->access,$sourceId,'read');
        if((new Settings($this->db,$this->access))->state()['version']===0)return hash('sha256','[]');
        $source=Db::run($this->db,'SELECT id,published_revision FROM khcms_pages WHERE club_id=? AND id=?',[$this->access->club(),$sourceId])->fetch();if(!$source)throw new \DomainException('Eigene Sprachquelle nicht verfügbar.');
        $rows=$basis==='draft'?Db::run($this->db,'SELECT locale,version,source_revision,draft_json,published_revision FROM native_language_pages WHERE club_id=? AND page_id=? ORDER BY locale',[$this->access->club(),$sourceId])->fetchAll():Db::run($this->db,'SELECT r.locale,r.revision,r.source_revision,r.payload_json FROM native_language_pages p JOIN native_language_revisions r ON r.club_id=p.club_id AND r.page_id=p.page_id AND r.locale=p.locale AND r.revision=p.published_revision WHERE p.club_id=? AND p.page_id=? AND r.source_revision=? ORDER BY r.locale',[$this->access->club(),$sourceId,(int)($source['published_revision']??0)])->fetchAll();
        foreach($rows as $row){(new Settings($this->db,$this->access))->require($row['locale'],'edit',false);$this->assertPatchCopy($sourceId,(int)$row['source_revision'],json_decode($row['draft_json']??$row['payload_json'],true,64,JSON_THROW_ON_ERROR));}
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    /** Every own historical language address, including saved proposals, for the confirmed purge tombstone. */
    public function retainedPaths(string $pageId):array
    {
        $this->access->require('website','manage');if(!preg_match('/\A[a-f0-9]{32}\z/D',$pageId))throw new \InvalidArgumentException('Originalseite prüfen.');
        if(!Db::run($this->db,'SELECT 1 FROM khcms_pages WHERE id=? AND club_id=?',[$pageId,$this->access->club()])->fetchColumn())throw new \DomainException('Eigene Originalseite nicht verfügbar.');
        if((new Settings($this->db,$this->access))->state()['version']===0)return [];
        $paths=[];$statement=Db::run($this->db,"SELECT locale,slug FROM native_language_routes WHERE club_id=? AND page_id=? UNION SELECT locale,JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.slug')) AS slug FROM native_language_pages WHERE club_id=? AND page_id=? UNION SELECT locale,JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.slug')) AS slug FROM native_language_revisions WHERE club_id=? AND page_id=?",[$this->access->club(),$pageId,$this->access->club(),$pageId,$this->access->club(),$pageId]);
        while($row=$statement->fetch()){Settings::code($row['locale']);if(!is_string($row['slug'])||strlen($row['slug'])>180||!preg_match('~\A[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D',$row['slug']))throw new \DomainException('Ursprüngliche Sprachadresse vor der Löschung prüfen.');$paths[$row['locale'].'/'.$row['slug']]=true;}
        $paths=array_keys($paths);sort($paths,SORT_STRING);return $paths;
    }
    /** Only called inside the original's explicit, confirmed terminal-trash purge transaction. */
    public function purgeForOriginal(string $pageId,int $expectedSourceVersion,bool $confirmed):int
    {
        if(!$this->db->inTransaction()||!$confirmed||!preg_match('/\A[a-f0-9]{32}\z/D',$pageId)||$expectedSourceVersion<1)throw new \DomainException('Sprachdaten nur gemeinsam mit der bestätigten endgültigen Original-Löschung entfernen.');
        $this->lockClub();$this->access->require('website','manage');$row=Db::run($this->db,'SELECT p.version FROM khcms_pages p JOIN khcms_trash t ON t.club_id=p.club_id AND t.page_id=p.id WHERE p.club_id=? AND p.id=? AND p.published_revision IS NULL FOR UPDATE',[$this->access->club(),$pageId])->fetch();
        if(!$row||(int)$row['version']!==$expectedSourceVersion)throw new Conflict('Nur die geprüfte eigene Originalfassung im Papierkorb endgültig löschen.');
        if((new Settings($this->db,$this->access))->state()['version']===0)return 0;
        $count=0;foreach(['native_language_routes','native_language_revisions','native_language_pages'] as $table)$count+=Db::run($this->db,'DELETE FROM '.$table.' WHERE club_id=? AND page_id=?',[$this->access->club(),$pageId])->rowCount();return $count;
    }
    private function change(string $page,string $locale,int $expected,string $action,\Closure $operation):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$page)||$expected<0||$expected>4294967293)throw new \InvalidArgumentException('Seite und Versionsstand prüfen.');
        $settings=new Settings($this->db,$this->access);$capability=in_array($action,['publish','unpublish'],true)?'publish':'edit';$settings->require($locale,$capability);
        if($settings->state()['config']['source']===$locale)throw new \DomainException('Die Quellsprache im Original bearbeiten.');
        return Db::atomic($this->db,function()use($page,$locale,$expected,$action,$operation,$settings,$capability):array{
            $this->lockClub();$actor=$settings->require($locale,$capability);$source=$this->source($page,$capability);$old=$this->row($page,$locale);
            if((int)($old['version']??0)!==$expected)throw new Conflict('Die Übersetzung wurde inzwischen geändert. Bitte neu laden.');
            $value=$operation($source,$old);$version=$expected+1;$json=json_encode($value['payload'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if(strlen($json)>2*1024*1024)throw new \InvalidArgumentException('Übersetzung auf höchstens 2 MiB begrenzen.');
            Db::run($this->db,'INSERT INTO native_language_revisions (club_id,page_id,locale,revision,source_revision,payload_json,actor_id,action,changed_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$this->access->club(),$page,$locale,$version,$value['source'],$json,$actor['id'],$action]);
            Db::run($this->db,'INSERT INTO native_language_pages (club_id,page_id,locale,version,source_revision,draft_json,published_revision) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),source_revision=VALUES(source_revision),draft_json=VALUES(draft_json),published_revision=VALUES(published_revision)',[$this->access->club(),$page,$locale,$version,$value['source'],$json,$value['published']]);
            if(in_array($action,['publish','unpublish'],true))$settings->invalidate();
            // Publishers may have publish-only rights. Returning the saved DTO does not grant edit.
            return ['page_id'=>$page,'locale'=>$locale,'version'=>$version,'source_revision'=>$value['source'],'payload'=>$this->readablePayload($page,(int)$value['source'],$value['payload']),'published_revision'=>$value['published']];
        });
    }
    private function source(string $page,string $capability='read'):array
    {
        $this->access->require('website','read');\Kulturhus\Native\Cms\TemplatePolicy::assertCapability($this->db,$this->access,$page,$capability);
        $row=Db::run($this->db,'SELECT p.id,p.version,p.slug,p.published_slug,p.published_revision FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id LEFT JOIN khcms_trash t ON t.page_id=p.id AND t.club_id=p.club_id WHERE p.id=? AND p.club_id=? AND a.page_id IS NULL AND t.page_id IS NULL',[$page,$this->access->club()])->fetch();
        if(!$row)throw new \DomainException('Seite in diesem Verein nicht verfügbar.');return $row;
    }
    private function original(string $page,int $revision):array
    {
        if($revision<1||$revision>4294967294)throw new \InvalidArgumentException('Gültige Originalfassung auswählen.');
        $json=Db::run($this->db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND club_id=? AND revision=?',[$page,$this->access->club(),$revision])->fetchColumn();
        if(!$json)throw new \DomainException('Originalfassung nicht gefunden.');return json_decode($json,true,64,JSON_THROW_ON_ERROR);
    }
    private function row(string $page,string $locale):?array
    {
        return Db::run($this->db,'SELECT * FROM native_language_pages WHERE club_id=? AND page_id=? AND locale=?',[$this->access->club(),$page,$locale])->fetch()?:null;
    }
    private function reserve(string $page,string $locale,string $slug):void
    {
        \Kulturhus\Native\Cms\PagePurge::assertPathAvailable($this->db,$this->access->club(),$locale.'/'.$slug);
        $owner=Db::run($this->db,'SELECT page_id FROM native_language_routes WHERE club_id=? AND locale=? AND slug=?',[$this->access->club(),$locale,$slug])->fetchColumn();
        if($owner&&$owner!==$page)throw new Conflict('Dieser Sprachpfad gehört bereits zu einer anderen Seite.');
        // A translation must not shadow another page's explicit source fallback URL.
        if(Db::run($this->db,'SELECT 1 FROM khcms_pages WHERE club_id=? AND id<>? AND published_slug=? LIMIT 1',[$this->access->club(),$page,$slug])->fetchColumn())throw new Conflict('Dieser Sprachpfad gehört zur Rückfalladresse einer anderen Seite.');
        if(!$owner)Db::run($this->db,'INSERT INTO native_language_routes (club_id,locale,slug,page_id) VALUES (?,?,?,?)',[$this->access->club(),$locale,$slug,$page]);
    }
    private function normalize(array $payload,array $source,array $previous=[],?array $oldSource=null):array
    {
        if(array_diff(array_keys($payload),['slug','fields'])||!is_array($payload['fields']??null)||count($payload['fields'])>20)throw new \InvalidArgumentException('Sprachpfad und übersetzbare Felder angeben.');
        $slug=$payload['slug']??null;
        if(!is_string($slug)||strlen($slug)>180||!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*\z/D',$slug))throw new \InvalidArgumentException('Gültigen Sprachpfad angeben.');
        $payload=$this->protectPatch($payload,$source,$previous,$oldSource);
        $allowed=['title'=>200,'nav_label'=>100,'excerpt'=>1000,'body'=>1000000,'body_html'=>1000000,'seo_title'=>200,'seo_description'=>500,'event_location'=>300,'event_address'=>1000,'event_price'=>200];$fields=[];
        foreach($payload['fields'] as $key=>$value){
            if(isset($allowed[$key])){$value=Settings::text($value,$allowed[$key]);if($key==='body_html')$value=RichText::sanitize($value);if($key==='title'&&$value==='')throw new \InvalidArgumentException('Einen übersetzten Seitennamen angeben.');$fields[$key]=$value;}
            elseif($key==='custom_fields'){
                if(!is_array($value)||count($value)>100)throw new \InvalidArgumentException('Übersetzbare Zusatzfelder prüfen.');$custom=[];
                foreach($value as $name=>$translated){$definition=$source['field_definitions'][$name]??null;if(!$definition||!in_array($definition['type'],['text','textarea','richtext'],true)||\Kulturhus\Native\Cms\FieldValues::collection($definition)||($definition['display']??'visible')!=='visible'||($definition['public']??true)!==true)throw new \InvalidArgumentException('Nur originale sichtbare öffentliche Textfelder übersetzen.');$custom[$name]=CustomFields::value($definition,$translated);}$fields[$key]=$custom;
            }elseif($key==='home_layout'){
                if(($source['slug']??'')!=='start'||!is_array($value)||array_diff(array_keys($value),array_diff(array_keys(\Kulturhus\Native\Cms\HomeLayout::LABELS),['sections'])))throw new \InvalidArgumentException('Nur ursprüngliche Startseitentexte übersetzen.');
                $base=array_replace(\Kulturhus\Native\Cms\HomeLayout::defaults(),$source['home_layout']??[]);
                if(isset($value['highlights'])&&(!is_array($value['highlights'])||count($value['highlights'])!==count($base['highlights'])))throw new \InvalidArgumentException('Anzahl und Reihenfolge der ursprünglichen Schwerpunkte erhalten.');
                $clean=\Kulturhus\Native\Cms\HomeLayout::normalize(array_replace($base,$value));$fields[$key]=array_intersect_key($clean,$value);
            }elseif($key==='content_sections'){
                if(!is_array($value)||count($value)>20)throw new \InvalidArgumentException('Übersetzbare Abschnitte prüfen.');$sections=[];$originals=array_column($source['content_sections']??[],null,'id');
                foreach($value as $id=>$texts){if(!isset($originals[$id])||!is_array($texts)||array_diff(array_keys($texts),['heading','body_html','alt','caption','button_label']))throw new \InvalidArgumentException('Nur originale Abschnittstexte übersetzen.');foreach($texts as $name=>$text){$clean=Settings::text($text,$name==='body_html'?20000:500);$sections[$id][$name]=$name==='body_html'?RichText::sanitize($clean):$clean;}}$fields[$key]=$sections;
            }else throw new \InvalidArgumentException('Dieses Originalfeld darf nicht übersetzt werden.');
        }
        if(isset($fields['body_html']))$fields['body']=RichText::plain($fields['body_html']);elseif(isset($fields['body']))$fields['body_html']='';
        if(isset($fields['content_sections'])){ $preview=self::overlay($source,['content_sections'=>$fields['content_sections']]);\Kulturhus\Native\Cms\ContentSections::normalize($preview['content_sections']); }
        if(\Kulturhus\Native\Cms\NewsChannels::isNews($source)&&isset($fields['title'])&&strcasecmp($fields['title'],'Neue Website-Neuigkeit')===0)throw new \InvalidArgumentException('Den Platzhaltertitel vor der Freigabe durch einen Beitragstitel ersetzen.');
        $result=['slug'=>$slug,'fields'=>$fields];foreach(['field_definitions','field_source_definitions','field_policy_history']as$key)if(!empty($payload[$key]))$result[$key]=$payload[$key];return $result;
    }
    /** Same source and patch captures govern DTOs, history, save/restore and public overlays. */
    private function readablePayload(string $page,int $revision,array $payload):array
    {
        $source=$this->original($page,$revision);$values=$payload['fields']['custom_fields']??[];
        if($values){$original=FieldPolicy::privatePayload($this->db,$this->access,array_replace($source,['custom_fields'=>$values]));$patch=FieldPolicy::privatePayload($this->db,$this->access,['field_definitions'=>$payload['field_definitions']??$source['field_definitions']??[],'custom_fields'=>$values]);$bound=FieldPolicy::privatePayload($this->db,$this->access,['field_definitions'=>$payload['field_source_definitions']??$source['field_definitions']??[],'custom_fields'=>$values]);$payload['fields']['custom_fields']=array_intersect_key($values,$original['custom_fields'],$patch['custom_fields'],$bound['custom_fields']);foreach($payload['fields']['custom_fields']as$key=>$value)if(!$this->historyAllowed($this->history($payload['field_policy_history'][$key]??null)))unset($payload['fields']['custom_fields'][$key]);foreach(['field_definitions','field_source_definitions','field_policy_history']as$key)if(isset($payload[$key]))$payload[$key]=array_intersect_key($payload[$key],$payload['fields']['custom_fields']);}
        return $payload;
    }

    private function protectPatch(array $payload,array $source,array $previous=[],?array $oldSource=null):array
    {
        $values=$payload['fields']['custom_fields']??[];$before=$previous['fields']['custom_fields']??[];
        if(!is_array($values)||!is_array($before))throw new \InvalidArgumentException('Übersetzbare Zusatzfelder prüfen.');
        $keys=array_fill_keys([...array_keys($values),...array_keys($before)],true);if(!$keys)return $payload;
        $model=new DataModel($this->db,$this->access);$histories=[];$submitted=$values;
        foreach(array_keys($keys) as $key){
            $oldRules=$this->history($previous['field_policy_history'][$key]??null);
            foreach([$oldSource['field_definitions'][$key]??null,$previous['field_definitions'][$key]??null,$previous['field_source_definitions'][$key]??null]as$definition)if($definition!==null)$oldRules=self::joinHistory($oldRules,self::definitionHistory($definition));
            $newRules=$this->history($payload['field_policy_history'][$key]??null);
            foreach([$source['field_definitions'][$key]??null,$payload['field_definitions'][$key]??null,$payload['field_source_definitions'][$key]??null]as$definition)if($definition!==null)$newRules=self::joinHistory($newRules,self::definitionHistory($definition));
            if(isset($source['field_definitions'][$key]))$newRules=self::joinHistory($newRules,self::definitionHistory($model->field($key)));
            $all=self::joinHistory($oldRules,$newRules);$editable=$this->historyAllowed($all,true);
            if(!$editable){if(array_key_exists($key,$values)&&(!array_key_exists($key,$before)||$values[$key]!==$before[$key]))throw new \DomainException('Für diese gespeicherte Sprachangabe fehlt die Bearbeitungsberechtigung.');if(array_key_exists($key,$before))$values[$key]=$before[$key];}
            $histories[$key]=$editable&&array_key_exists($key,$submitted)?$newRules:$all;
        }
        $definitions=array_intersect_key($payload['field_source_definitions']??$source['field_definitions']??[],$keys);
        foreach($values as $key=>$value)if(!isset($source['field_definitions'][$key],$definitions[$key]))throw new \InvalidArgumentException('Nur vorhandene originale Zusatzfelder übersetzen.');
        $priorSource=array_intersect_key($previous['field_source_definitions']??$oldSource['field_definitions']??[],$before);
        $prepared=FieldPolicy::prepare($this->db,$this->access,$definitions,$values,['field_definitions'=>$priorSource,'custom_fields'=>$before]);
        $model=new DataModel($this->db,$this->access);$captures=[];
        foreach($prepared['definitions'] as $key=>$field){if(isset($payload['field_definitions'][$key]))$captures[$key]=$payload['field_definitions'][$key];else{$current=$model->field($key);$field['access']=$current['access']??[];$captures[$key]=$field;}}
        $priorCurrent=array_intersect_key($previous['field_definitions']??$oldSource['field_definitions']??[],$before);
        $captured=FieldPolicy::prepare($this->db,$this->access,$captures,$prepared['values'],['field_definitions'=>$priorCurrent,'custom_fields'=>$before]);
        $payload['fields']['custom_fields']=$captured['values'];$payload['field_definitions']=array_intersect_key($captured['definitions'],$captured['values']);$payload['field_source_definitions']=array_intersect_key($prepared['definitions'],$captured['values']);$payload['field_policy_history']=array_intersect_key($histories,$captured['values']);return $payload;
    }

    /** The finite native capability sets retain all prior bindings without unbounded history. */
    private static function validatedHistory(mixed $input):array
    {
        if($input===null)return ['read'=>['read'],'edit'=>['edit']];
        if(!is_array($input)||array_diff(array_keys($input),['read','edit'])||!isset($input['read'],$input['edit']))throw new \DomainException('Gespeicherte Sprachrechte sind nicht verfügbar.');
        foreach(['read'=>['read','edit','publish','manage'],'edit'=>['edit','publish','manage']]as$kind=>$allowed){$roles=$input[$kind];if(!is_array($roles)||!array_is_list($roles)||count(array_filter($roles,'is_string'))!==count($roles)||count($roles)>count($allowed)||array_diff($roles,$allowed)||count(array_unique($roles))!==count($roles)||!in_array($kind==='read'?'read':'edit',$roles,true))throw new \DomainException('Gespeicherte Sprachrechte sind nicht verfügbar.');$input[$kind]=array_values(array_intersect($allowed,$roles));}return $input;
    }
    private function history(mixed $input):array{return self::validatedHistory($input);}
    private static function definitionHistory(array $field):array
    {
        return FieldPolicy::capturedHistory($field);
    }
    private static function joinHistory(array $one,array $two):array
    {return self::validatedHistory(['read'=>array_values(array_unique([...$one['read'],...$two['read']])),'edit'=>array_values(array_unique([...$one['edit'],...$two['edit']]))]);}
    private function historyAllowed(array $history,bool $edit=false):bool
    {try{foreach(array_unique([...$history['read'],...($edit?$history['edit']:[])])as$role)$this->access->require('website',$role);return true;}catch(\DomainException){return false;}}

    private function assertPatchCopy(string $page,int $revision,array $patch):void
    {
        $values=$patch['fields']['custom_fields']??[];if(!$values)return;foreach($values as$key=>$value)if(!$this->historyAllowed($this->history($patch['field_policy_history'][$key]??null),true))throw new \DomainException('Eine geschützte Sprachangabe ist nicht vollständig bearbeitbar.');$source=$this->original($page,$revision);
        FieldPolicy::assertCopy($this->db,$this->access,['field_definitions'=>array_intersect_key($source['field_definitions']??[],$values)]);
        FieldPolicy::assertCopy($this->db,$this->access,['field_definitions'=>array_intersect_key($patch['field_definitions']??$source['field_definitions']??[],$values)]);
        FieldPolicy::assertCopy($this->db,$this->access,['field_definitions'=>array_intersect_key($patch['field_source_definitions']??$source['field_definitions']??[],$values)]);
    }

    /** Only readable source AND saved translation fields enter an anonymous overlay. */
    public static function publicOverlay(\PDO $db,string $club,array $source,array $patch):array
    {
        $fields=$patch['fields'];$values=$fields['custom_fields']??[];
        if($values){$original=FieldPolicy::publicSource($db,$club,array_replace($source,['custom_fields'=>$values]));$translated=FieldPolicy::publicSource($db,$club,['field_definitions'=>$patch['field_definitions']??$source['field_definitions']??[],'custom_fields'=>$values]);$bound=FieldPolicy::publicSource($db,$club,['field_definitions'=>$patch['field_source_definitions']??$source['field_definitions']??[],'custom_fields'=>$values]);$fields['custom_fields']=array_intersect_key($values,$original['custom_fields'],$translated['custom_fields'],$bound['custom_fields']);foreach($fields['custom_fields']as$key=>$value){$history=self::validatedHistory($patch['field_policy_history'][$key]??null);if($history['read']!==['read'])unset($fields['custom_fields'][$key]);}}
        return self::overlay($source,$fields);
    }

    /** Applies text-only patches to the exact public snapshot; identity, finance, media and links stay original. */
    public static function overlay(array $source,array $fields):array
    {
        foreach($fields as $key=>$value){
            if($key==='custom_fields'){
                foreach($value as $name=>$text){$source['custom_fields'][$name]=$text;if(($source['field_definitions'][$name]['type']??'')==='richtext')$source['custom_field_text'][$name]=\Kulturhus\Native\Cms\RichTextField::plain($text??'');}
            }elseif($key==='home_layout'){
                $source['home_layout']=array_replace(\Kulturhus\Native\Cms\HomeLayout::defaults(),$source['home_layout']??[],$value);
            }elseif($key==='content_sections'){
                foreach($source['content_sections']??[] as $i=>$section)if(isset($value[$section['id']]))$source['content_sections'][$i]=array_replace($section,$value[$section['id']]);
                $source['content_sections_text']=\Kulturhus\Native\Cms\ContentSections::plain($source['content_sections']??[]);
            }else $source[$key]=$value;
        }return $source;
    }
}
