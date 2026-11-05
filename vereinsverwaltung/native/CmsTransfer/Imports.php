<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict,DataModel,Media,MediaMetadata,Pages,PathHistory,UploadTransaction,WebsiteDocuments};
use Kulturhus\Native\Files\{AssetVersions,Formats,Metadata};
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Private preview and exactly-once creation of new local, unpublished native originals. */
final class Imports
{
    use ClubPublicationLock;
    private string $root;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private string $privateRoot)
    {$this->root=Package::directory($privateRoot.'/cms-transfers');}

    public function prepare(string $source,array $options=[]):array
    {
        $actor=$this->access->require('website','manage');$this->access->require('website','edit');$this->access->require('website','read');
        if(array_diff(array_keys($options),['prefix','slugs']))throw new InvalidArgumentException('Importoptionen prüfen.');
        $id=bin2hex(random_bytes(16));$prefix=$options['prefix']??'import_'.substr($id,0,8);
        if(!is_string($prefix)||!preg_match('/\A[a-z][a-z0-9_]{0,19}\z/D',$prefix))throw new InvalidArgumentException('Kennung: 1–20 Kleinbuchstaben, Zahlen oder Unterstriche.');
        $package=Package::read($source,$this->root);$cleanupPackage=['owned_root'=>$package['owned_root']];
        try{
            $model=Content::validate($package['content']);$this->inspect($model,$package);$map=Content::modelMap($model,$prefix);$ids=[];$slugs=[];$destinations=[];$override=$options['slugs']??[];
            if(!is_array($override)||array_diff(array_keys($override),array_keys($model['pages'])))throw new InvalidArgumentException('Nur Paket-Seiten können eine neue Zieladresse erhalten.');
            foreach($model['pages']as$key=>$page){$ids[$key]=bin2hex(random_bytes(16));$old=$page['payload']['slug'];$slug=$override[$key]??self::slug($prefix,$old);if(!is_string($slug)||strlen($slug)>180)throw new InvalidArgumentException('Zieladresse auf höchstens 180 Zeichen kürzen.');\Kulturhus\Native\Cms\Templates::normalize(['title'=>$page['payload']['title'],'slug'=>$slug]);if(isset($destinations[$slug]))throw new InvalidArgumentException('Zieladressen müssen eindeutig sein.');$destinations[$slug]=true;$slugs[$old]=$slug;}
            $sections=[];foreach($model['pages']as$key=>$page)foreach($page['payload']['content_sections']??[]as$section)$sections[$key][$section['id']]=bin2hex(random_bytes(16));
            $plan=['package'=>$package,'prefix'=>$prefix,'map'=>$map,'page_ids'=>$ids,'slugs'=>$slugs,'section_ids'=>$sections];
            Db::atomic($this->db,function()use($actor,$id,$package,$plan,$model):void{
                $current=$this->access->require('website','manage');$this->access->require('website','edit');$this->lockClub();if($actor['id']!==$current['id']||$actor['club_id']!==$current['club_id'])throw new DomainException('Zugang wurde geändert.');$this->available($plan,$model);
                Db::run($this->db,"INSERT INTO native_cms_imports VALUES(?,?,?,1,'ready',?,?,?,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$id,$actor['club_id'],$actor['id'],$package['sha256'],$this->targetHash(),self::json($plan)]);
            });
            unset($model,$plan,$package);return $this->get($id);
        }catch(\Throwable $error){
            // A failed commit acknowledgement must not erase bytes of a possibly accepted plan.
            try{$accepted=Db::run($this->db,'SELECT 1 FROM native_cms_imports WHERE id=? AND club_id=?',[$id,$actor['club_id']])->fetchColumn();if(!$accepted)Package::cleanup($cleanupPackage);}catch(\Throwable){}
            throw $error;
        }
    }

    public function get(string $id):array
    {
        $row=$this->row($id);$plan=Package::decodeJson($row['plan_json']);$model=Content::validate($plan['package']['content']);$pages=[];$secrets=[];
        foreach($model['pages']as$key=>$page){$template=$model['templates'][$page['payload']['template']];$defs=\Kulturhus\Native\Cms\TemplateContexts::resolve($template,array_intersect_key($model['resolved'],array_fill_keys($template['fields'],true)));$pages[]=['source_id'=>$key,'title'=>$page['payload']['title'],'source_version'=>$page['source_version'],'slug'=>$plan['slugs'][$page['payload']['slug']],'target_id'=>$plan['page_ids'][$key]];foreach(Content::secretInputs($defs,$page['payload']['custom_fields']??[])as$input)$secrets[]=['page_id'=>$key]+$input;}
        $warnings=[];foreach($model['assets']as$asset)if($asset['kind']==='image'){$warnings[]='Fotos erhalten neue, sicher verarbeitete Bildfassungen.';break;}
        return ['id'=>$id,'version'=>(int)$row['version'],'status'=>$row['status'],'sha256'=>$row['package_sha256'],'prefix'=>$plan['prefix'],'pages'=>$pages,'counts'=>['pages'=>count($pages),'fields'=>count($model['fields']),'templates'=>count($model['templates']),'assets'=>count($model['assets']),'languages'=>count($model['languages'])],'secrets'=>$secrets,'warnings'=>$warnings,'blockers'=>$this->blockers($plan,$model),'result'=>$row['result_json']===null?null:Package::decodeJson($row['result_json']),'stale'=>$row['status']==='ready'&&!hash_equals($row['target_hash'],$this->targetHash())];
    }

    public function recent(int $page=1):array
    {
        $actor=$this->access->require('website','manage');if($page<1||$page>100000)throw new InvalidArgumentException('Auftragsseite prüfen.');$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_cms_imports WHERE club_id=? AND actor_id=?',[$actor['club_id'],$actor['id']])->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT id,version,status,package_sha256,created_at FROM native_cms_imports WHERE club_id=? AND actor_id=? ORDER BY created_at DESC,id LIMIT 10 OFFSET '.(($page-1)*10),[$actor['club_id'],$actor['id']])->fetchAll();return compact('rows','page','pages','total');
    }

    /** Deliberate recovery rechecks the retained exact package against today's local destination. */
    public function revise(string $id,int $version,array $options):array
    {
        if(!$options||array_diff(array_keys($options),['prefix','slugs']))throw new InvalidArgumentException('Neue Importkennung oder Zieladressen angeben.');
        return Db::atomic($this->db,function()use($id,$version,$options):array{
            $this->access->require('website','manage');$this->access->require('website','edit');$this->lockClub();$row=$this->row($id,true);if($row['status']!=='ready'||(int)$row['version']!==$version)throw new Conflict('Nur den aktuellen offenen Importauftrag erneut prüfen.');
            $plan=Package::decodeJson($row['plan_json']);$this->packageFiles($plan['package']);$model=Content::validate($plan['package']['content']);$prefix=$options['prefix']??$plan['prefix'];if(!is_string($prefix)||!preg_match('/\A[a-z][a-z0-9_]{0,19}\z/D',$prefix))throw new InvalidArgumentException('Kennung: 1–20 Kleinbuchstaben, Zahlen oder Unterstriche.');$overrides=$options['slugs']??[];if(!is_array($overrides)||array_diff(array_keys($overrides),array_keys($model['pages'])))throw new InvalidArgumentException('Zieladressen gehören nur zu Paket-Seiten.');
            $slugs=[];$used=[];foreach($model['pages']as$key=>$page){$old=$page['payload']['slug'];$slug=$overrides[$key]??($prefix===$plan['prefix']?$plan['slugs'][$old]:self::slug($prefix,$old));if(!is_string($slug))throw new InvalidArgumentException('Zieladresse als Text angeben.');\Kulturhus\Native\Cms\Templates::normalize(['title'=>$page['payload']['title'],'slug'=>$slug]);if(isset($used[$slug]))throw new InvalidArgumentException('Zieladressen müssen eindeutig sein.');$used[$slug]=true;$slugs[$old]=$slug;}
            $plan['prefix']=$prefix;$plan['map']=Content::modelMap($model,$prefix);$plan['slugs']=$slugs;$this->available($plan,$model);
            Db::run($this->db,'UPDATE native_cms_imports SET version=version+1,plan_json=?,target_hash=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=? AND version=?',[self::json($plan),$this->targetHash(),$id,$this->access->club(),$version]);return $this->get($id);
        });
    }

    public function apply(string $id,int $version,bool $confirmed,array $secrets=[]):array
    {
        $this->access->require('website','manage');$this->access->require('website','edit');if(!$confirmed)throw new InvalidArgumentException('Neue unveröffentlichte Entwürfe ausdrücklich anlegen.');
        return UploadTransaction::run($this->db,function(UploadTransaction $tx)use($id,$version,$secrets):array{
            $actor=$this->access->require('website','manage');$this->access->require('website','edit');$this->lockClub();$row=$this->row($id,true);
            if($row['status']==='completed'){$result=Package::decodeJson($row['result_json']);if(!in_array($version,[$result['applied_version'],(int)$row['version']],true))throw new Conflict('Auftragsstand wurde inzwischen geändert.');return ['replayed'=>true]+$result;}
            if($row['status']!=='ready'||(int)$row['version']!==$version)throw new Conflict('Importauftrag wurde inzwischen geändert.');if(!hash_equals($row['target_hash'],$this->targetHash()))throw new Conflict('Zielbestand oder Inhaltsmodell wurde inzwischen geändert. Paket mit dem aktuellen Stand erneut prüfen.');
            $plan=Package::decodeJson($row['plan_json']);$this->packageFiles($plan['package']);$model=Content::validate($plan['package']['content']);$this->available($plan,$model);$blockers=$this->blockers($plan,$model);if($blockers)throw new Conflict(implode(' ',$blockers));$this->checkSecrets($model,$secrets);$ids=$plan['page_ids'];$evidence=[];
            $assetRoot=dirname($this->mediaRoot);$versions=new AssetVersions($this->db,$this->access,$assetRoot.'/files',$this->mediaRoot);$media=new Media($this->db,$this->access,$this->mediaRoot);$documents=new WebsiteDocuments($this->db,$this->access,$assetRoot.'/website-documents');
            foreach($model['assets']as$key=>$asset){
                $source=$plan['package']['blobs'][$asset['sha256']];
                if($asset['kind']==='image'){$created=$media->upload($source,$asset['name'],$asset['metadata']['alt'],null,null,null,$tx);$meta=new MediaMetadata($this->db,$this->access);$metadata=array_intersect_key($asset['metadata'],array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS]));$meta->save($created['id'],$metadata,$meta->get($created['id'])['version']);$ids[$key]=$created['id'];$file=$media->variant($created['id'],'original');}
                elseif($asset['kind']==='document'){$created=$documents->uploadMany([['path'=>$source,'name'=>$asset['name']]],null,$tx)[0];$ids[$key]=$created['id'];$file=$documents->file($created['id']);}
                else{$created=$versions->uploadMany([['path'=>$source,'name'=>$asset['name'],'metadata'=>$asset['metadata']]],null,$tx)[0];$ids[$key]=$created['revision_id'];$file=$versions->file($created['revision_id']);}
                if($asset['kind']!=='image'&&($file['bytes']!==$asset['bytes']||!hash_equals($asset['sha256'],$file['sha256'])))throw new Conflict('Importierte Dateibytes weichen vom geprüften Original ab.');
                $evidence[]=['source_id'=>$key,'target_id'=>$ids[$key],'kind'=>$asset['kind'],'source_sha256'=>$asset['sha256'],'target_sha256'=>$file['sha256'],'source_bytes'=>$asset['bytes'],'target_bytes'=>$file['bytes']];
            }
            $dataModel=new DataModel($this->db,$this->access);$modelVersions=[];
            foreach($this->fieldOrder($model['fields'])as$key){$field=Content::mappedField($model['fields'][$key],$plan['map'],$ids);unset($field['show_when'],$field['required_when']);$saved=$dataModel->saveField($field,0);$modelVersions['fields'][$saved['key']]=$saved['version'];}
            foreach($model['templates']as$template){$template=Content::mappedTemplate($template,$plan['map']);unset($template['policy']);$saved=$dataModel->saveTemplate($template,0);$modelVersions['templates'][$saved['key']]=$saved['version'];}
            $prepared=[];
            foreach($model['pages']as$key=>$page){$template=$model['templates'][$page['payload']['template']];$defs=\Kulturhus\Native\Cms\TemplateContexts::resolve($template,array_intersect_key($model['resolved'],array_fill_keys($template['fields'],true)));$payload=Content::mappedPage($page['payload'],$defs,$plan['map'],$ids,$plan['slugs'],$actor['id'],$secrets[$key]??[],$plan['section_ids'][$key]??[]);$payload['slug']=$plan['slugs'][$page['payload']['slug']];$prepared[$key]=['id'=>$ids[$key],'payload'=>$payload,'field_policy_history'=>Content::mappedPageHistory($page['field_policy_history']??[],$plan['map'])];}
            $finalize=function()use($model,$plan,$ids,$dataModel,&$modelVersions):void{
                foreach($model['fields']as$field){$mapped=Content::mappedField($field,$plan['map'],$ids,$model['fields']);$saved=$dataModel->saveField($mapped,$modelVersions['fields'][$mapped['key']]);$modelVersions['fields'][$mapped['key']]=$saved['version'];}
                foreach($model['templates']as$template){$mapped=Content::mappedTemplate($template,$plan['map']);$saved=$dataModel->saveTemplate($mapped,$modelVersions['templates'][$mapped['key']]);$modelVersions['templates'][$mapped['key']]=$saved['version'];}
            };
            if($prepared!==[])$createdPages=(new Pages($this->db,$this->access))->importDraftSet(array_values($prepared),$finalize);
            else{$finalize();$createdPages=[];}
            $languageCount=$this->languages($model,$plan,$ids,$createdPages);
            $summaries=array_map(static fn(array $page):array=>array_intersect_key($page,array_flip(['id','title','slug','template','version','published_revision','nav_visible'])),$createdPages);
            $result=['applied_version'=>$version,'package_sha256'=>$row['package_sha256'],'pages'=>$summaries,'models'=>$modelVersions,'assets'=>$evidence,'languages'=>$languageCount];
            Db::run($this->db,"UPDATE native_cms_imports SET status='completed',version=version+1,result_json=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=? AND actor_id=? AND version=?",[self::json($result),$id,$actor['club_id'],$actor['id'],$version]);return ['replayed'=>false]+$result;
        });
    }

    public function cancel(string $id,int $version,bool $confirmed):array
    {
        if(!$confirmed)throw new InvalidArgumentException('Importauftrag ausdrücklich abbrechen.');return Db::atomic($this->db,function()use($id,$version):array{$this->access->require('website','manage');$this->lockClub();$row=$this->row($id,true);if($row['status']==='cancelled'&&(int)$row['version']===$version+1)return $this->get($id);if($row['status']!=='ready'||(int)$row['version']!==$version)throw new Conflict('Nur den aktuellen offenen Importauftrag abbrechen.');Db::run($this->db,"UPDATE native_cms_imports SET status='cancelled',version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?",[$id,$this->access->club()]);return $this->get($id);});
    }

    /** Only a closed job's private package copies are removable; target pages and files remain originals. */
    public function cleanup(string $id,int $version,bool $confirmed):array
    {
        if(!$confirmed)throw new InvalidArgumentException('Private Paketkopien ausdrücklich entfernen.');$row=$this->row($id);if(!in_array($row['status'],['completed','cancelled'],true)||(int)$row['version']!==$version)throw new Conflict('Zuerst den aktuellen Importauftrag abschließen oder abbrechen.');$plan=Package::decodeJson($row['plan_json']);$this->owned($plan['package']);Package::cleanup($plan['package']);return $this->get($id);
    }

    private function row(string $id,bool $write=false):array
    {
        $actor=$this->access->require('website','manage');if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Importauftragskennung prüfen.');$row=Db::run($this->db,'SELECT * FROM native_cms_imports WHERE id=? AND club_id=? AND actor_id=?'.($write?' FOR UPDATE':''),[$id,$actor['club_id'],$actor['id']])->fetch();if(!$row)throw new DomainException('Eigener Importauftrag nicht verfügbar.');return $row;
    }
    private function targetHash():string
    {
        $club=$this->access->require('website','manage')['club_id'];$hash=hash_init('sha256');$rows=Db::run($this->db,'SELECT kind,definition_key,version FROM khcms_definitions WHERE club_id=? ORDER BY kind,definition_key',[$club]);while($row=$rows->fetch(PDO::FETCH_ASSOC))hash_update($hash,self::json($row));$generation=Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=?',[$club])->fetchColumn();$language=(new \Kulturhus\Native\Language\Settings($this->db,$this->access))->state();hash_update($hash,self::json(['generation'=>$generation===false?0:(int)$generation,'languages'=>$language,'package'=>\Kulturhus\Native\Installer\PackageVersion::GENERATION]));return hash_final($hash);
    }
    private function available(array $plan,array $model):void
    {
        $capabilities=['read'=>true,'edit'=>true];foreach($model['fields']as$field)foreach($field['access']??[]as$cap)$capabilities[$cap]=true;foreach([...array_values($model['pages']),...$model['languages']]as$item)foreach($item['field_policy_history']??[]as$rules)foreach($rules as$roles)foreach($roles as$cap)$capabilities[$cap]=true;foreach(array_keys($capabilities)as$cap)$this->access->require('website',$cap);
        $dataModel=new DataModel($this->db,$this->access);$existing=['fields'=>array_fill_keys($dataModel->managedFieldKeys(),true),'templates'=>array_column($dataModel->templates(),null,'key')];foreach($plan['map']as$kind=>$map)foreach($map as$target)if(isset($existing[$kind][$target]))throw new Conflict('Eine Ziel-Feld- oder Vorlagenkennung ist bereits vergeben. Andere Importkennung wählen.');
        foreach($model['pages']as$key=>$page){$id=$plan['page_ids'][$key];$slug=$plan['slugs'][$page['payload']['slug']];PathHistory::assertAvailable($this->db,$this->access->club(),$id,$slug);(new \Kulturhus\Native\Language\Settings($this->db,$this->access))->assertSourcePath($slug,$id);if(Db::run($this->db,'SELECT 1 FROM khcms_pages WHERE id=? OR (club_id=? AND (slug=? OR published_slug=?)) LIMIT 1',[$id,$this->access->club(),$slug,$slug])->fetchColumn())throw new Conflict('Eine neue Zielkennung oder Zieladresse wurde inzwischen vergeben.');}
        $settings=new \Kulturhus\Native\Language\Settings($this->db,$this->access);foreach($model['languages']as$language){$settings->require($language['locale'],'edit');if($settings->state()['config']['source']===$language['locale'])throw new Conflict('Eine Sprachfassung gehört zur lokalen Quellsprache. Sprachen vor dem Import prüfen.');}
    }
    private function blockers(array $plan,array $model):array
    {
        $blocked=[];foreach($model['languages']as$language)if(array_key_exists('home_layout',$language['payload']['fields'])){$source=$model['pages'][$language['page_id']]['payload'];if($plan['slugs'][$source['slug']]!=='start')$blocked[$language['page_id']]='„'.$source['title'].'“ benötigt für übersetzte Startseitentexte die Zieladresse „start“. Zieladresse ändern und erneut prüfen.';}return array_values($blocked);
    }
    private function inspect(array $model,array $package):void
    {
        foreach($model['assets']as$asset){$path=$package['blobs'][$asset['sha256']];if($asset['kind']==='image'){$info=@getimagesize($path);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||$mime!==$asset['mime']||($info['mime']??'')!==$mime||$info[0]<1||$info[1]<1||$info[0]*$info[1]>16000000||$asset['bytes']>12*1024*1024)throw new InvalidArgumentException('Foto im Paket entspricht nicht dem nativen Bildformat.');}else{$descriptor=Formats::inspect($path,$asset['name']);if($descriptor['mime']!==$asset['mime']||$descriptor['bytes']!==$asset['bytes']||!hash_equals($descriptor['sha256'],$asset['sha256'])||($asset['kind']==='document'&&($descriptor['mime']!=='application/pdf'||$asset['bytes']>WebsiteDocuments::MAX_BYTES)))throw new InvalidArgumentException('Dateiformat im Paket stimmt nicht mit den tatsächlichen Bytes überein.');}}
    }
    private function packageFiles(array $package):void
    {$this->owned($package);foreach($package['blobs']as$sha=>$path)if(!is_string($path)||dirname($path)!==$package['owned_root']||is_link($path)||!is_file($path)||!hash_equals($sha,hash_file('sha256',$path)))throw new Conflict('Private Paketbytes sind nicht mehr vollständig unverändert. Paket erneut prüfen.');}
    private function owned(array $package):void
    {if(!is_string($package['owned_root']??null)||dirname($package['owned_root'])!==$this->root||!preg_match('~/package-[a-f0-9]{32}\z~D',$package['owned_root'])||is_link($package['owned_root']))throw new DomainException('Privater Paketbesitz ist nicht bestätigt.');}
    private function fieldOrder(array $fields):array
    {$order=[];$seen=[];$visit=function(string $key)use(&$visit,&$order,&$seen,$fields):void{if(isset($seen[$key]))return;$seen[$key]=true;foreach($fields[$key]['fields']??[]as$child)$visit($child);$order[]=$key;};foreach(array_keys($fields)as$key)$visit($key);return $order;}
    private function checkSecrets(array $model,array $secrets):void
    {
        if(array_diff(array_keys($secrets),array_keys($model['pages'])))throw new InvalidArgumentException('Lokale Geheimangaben gehören nur zu Paket-Seiten.');foreach($model['pages']as$key=>$page){$template=$model['templates'][$page['payload']['template']];$defs=\Kulturhus\Native\Cms\TemplateContexts::resolve($template,array_intersect_key($model['resolved'],array_fill_keys($template['fields'],true)));$paths=array_map(static fn($row)=>implode('.',$row['path']),Content::secretInputs($defs,$page['payload']['custom_fields']??[]));if(!is_array($secrets[$key]??[])||array_diff(array_keys($secrets[$key]??[]),$paths))throw new InvalidArgumentException('Nur ausdrücklich ausgewählte lokale Passwortfelder ausfüllen.');foreach($secrets[$key]??[]as$value)if(!is_string($value)||strlen($value)>4096)throw new InvalidArgumentException('Lokale Passwortangabe prüfen.');}
    }
    private function languages(array $model,array $plan,array $ids,array $pages):int
    {
        $count=0;$service=new \Kulturhus\Native\Language\Translations($this->db,$this->access);foreach($model['languages']as$language){$source=$model['pages'][$language['page_id']]['payload'];$template=$model['templates'][$source['template']];$definitions=\Kulturhus\Native\Cms\TemplateContexts::resolve($template,array_intersect_key($model['resolved'],array_fill_keys($template['fields'],true)));$payload=Content::mappedLanguage($language['payload'],$plan['map'],$plan['slugs'],$plan['section_ids'][$language['page_id']]??[],$definitions);$payload['slug']=$payload['slug']===$source['slug']?$plan['slugs'][$source['slug']]:self::slug($plan['prefix'],$payload['slug']);$target=$ids[$language['page_id']];$page=(new Pages($this->db,$this->access))->get($target);$history=Content::mappedLanguagePolicy($language['field_policy_history']??[],$plan['map']);$service->importDraft($target,$language['locale'],$payload,$page['version'],$history);++$count;}return $count;
    }
    private static function json(array $value):string
    {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function slug(string $prefix,string $source):string
    {$root=str_replace('_','-',$prefix).'/';if(strlen($root.$source)<=180)return $root.$source;return $root.rtrim(substr($source,0,180-strlen($root)-9),'/-').'-'.substr(hash('sha256',$source),0,8);}
}
