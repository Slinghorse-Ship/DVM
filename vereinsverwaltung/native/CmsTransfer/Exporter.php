<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;

use InvalidArgumentException;
use Kulturhus\Native\Cms\{ClubPublicationLock,Conflict,DataModel,FieldPolicy,Media,MediaMetadata,Pages,WebsiteDocuments};
use Kulturhus\Native\Files\{AssetVersions,Metadata};
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** A current saved native model plus the complete authorized content dependency closure. */
final class Exporter
{
    use ClubPublicationLock;
    public function __construct(private PDO $db,private Access $access,private string $mediaRoot,private string $privateRoot){}

    /** Selection is page ID => exact saved version. An empty selection exports the declarative model. */
    public function export(array $selection,string $target,bool $languages=true):array
    {
        $this->access->require('website','manage');$this->access->require('website','read');
        if(count($selection)>100)throw new InvalidArgumentException('Höchstens 100 gespeicherte Seiten auswählen.');
        foreach($selection as$id=>$version)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_int($version)||$version<1)throw new InvalidArgumentException('Seite und gespeicherten Versionsstand auswählen.');
        return Db::atomic($this->db,function()use($selection,$target,$languages):array{
            $this->access->require('website','manage');$this->lockClub();$model=new DataModel($this->db,$this->access);
            $resolvedFields=$model->fields();FieldPolicy::assertCopy($this->db,$this->access,['field_definitions'=>array_column($resolvedFields,null,'key')]);$fields=array_map(Content::field(...),$resolvedFields);Content::assertFieldBudget($fields);$templates=array_map(Content::template(...),$model->templates());
            if(count($fields)+count($templates)>200)throw new InvalidArgumentException('Höchstens 200 Feld- und Vorlagendefinitionen übertragen.');
            $queue=array_keys($selection);$fieldByKey=array_column($fields,null,'key');
            foreach($fields as$field)foreach(['show_when','required_when']as$kind)foreach($field[$kind]['conditions']??[]as$rule){$type=$fieldByKey[$rule['field']]['type']??'';if(in_array($type,['page-reference','pagetable'],true)&&is_string($rule['value']??null))$queue[]=$rule['value'];}
            $pages=[];$pageService=new Pages($this->db,$this->access);$images=[];$files=[];$documents=[];$draftLanguages=[];
            while($queue!==[]){
                $id=array_shift($queue);if(isset($pages[$id]))continue;if(count($pages)>=100)throw new InvalidArgumentException('Auswahl und Abhängigkeiten umfassen mehr als 100 Seiten.');
                $page=$pageService->get($id);if($page['trashed']||$page['archived'])throw new Conflict('Seiten im Papierkorb oder Archiv zuerst ausdrücklich zurückholen.');
                if(isset($selection[$id])&&$page['version']!==$selection[$id])throw new Conflict('Eine ausgewählte Seite wurde inzwischen geändert. Auswahl neu laden.');
                // A projected manager DTO intentionally omits protected fields. A transfer must either
                // authorize the complete exact original or fail, rather than silently lose its data.
                $saved=Db::run($this->db,'SELECT draft_json FROM khcms_pages WHERE id=? AND club_id=? FOR SHARE',[$id,$this->access->club()])->fetchColumn();if(!is_string($saved))throw new Conflict('Gespeichertes Original ist nicht mehr verfügbar.');$original=json_decode($saved,true,64,JSON_THROW_ON_ERROR);
                FieldPolicy::assertCopy($this->db,$this->access,$original);$history=FieldPolicy::exportHistory($this->db,$this->access,$original);$normalized=$model->publication($original);$payload=Content::page($normalized);$definitions=$normalized['field_definitions']??[];
                $pages[$id]=['id'=>$id,'source_version'=>$page['version'],'payload'=>$payload,'field_policy_history'=>$history];
                foreach(Content::references($payload,$definitions,'page')as$dependency)if(!isset($pages[$dependency]))$queue[]=$dependency;
                foreach(Content::references($payload,$definitions,'image')as$dependency)$images[$dependency]=true;
                foreach(Content::references($payload,$definitions,'file')as$dependency)$files[$dependency]=true;
                foreach(Content::references($payload,$definitions,'document')as$dependency)$documents[$dependency]=true;
                if($languages){
                    $settings=new \Kulturhus\Native\Language\Settings($this->db,$this->access);
                    if($settings->state()['version']>0)$draftLanguages=[...$draftLanguages,...(new \Kulturhus\Native\Language\Translations($this->db,$this->access))->exportDrafts($id)];
                }
            }
            // Reference literals in model conditions also belong to the package's exact closure.
            foreach($fields as$field)foreach(['show_when','required_when']as$kind)foreach($field[$kind]['conditions']??[]as$rule){$type=$fieldByKey[$rule['field']]['type']??'';$value=$rule['value']??null;if(!is_string($value))continue;if($type==='media-reference')$images[$value]=true;elseif($type==='file-reference')$files[$value]=true;}
            $assetRoot=dirname($this->mediaRoot);$assets=[];$blobs=[];$media=new Media($this->db,$this->access,$this->mediaRoot);$versions=new AssetVersions($this->db,$this->access,$assetRoot.'/files',$this->mediaRoot);
            foreach(array_keys($images)as$id){$file=$media->variant($id,'original');$meta=(new MediaMetadata($this->db,$this->access))->get($id);$this->asset($assets,$blobs,$id,'image',$file,array_intersect_key($meta,array_flip([...\Kulturhus\Native\Shared\MediaDescription::PUBLIC_FIELDS,...Metadata::RIGHTS])));}
            foreach(array_keys($files)as$id){
                try{$revision=$versions->revision($id);if($revision['kind']!=='file')throw new InvalidArgumentException('Dateifeld benötigt eine Dokumentfassung.');$head=$versions->get($revision['asset_id']);$metadata=$revision['metadata'];foreach(Metadata::RIGHTS as$key)$metadata[$key]=$head['metadata'][$key];$this->asset($assets,$blobs,$id,'file',$versions->file($id),$metadata);}
                catch(\DomainException $error){$legacy=Db::run($this->db,'SELECT 1 FROM khcms_documents WHERE club_id=? AND id=?',[$this->access->club(),$id])->fetchColumn();if(!$legacy)throw $error;$documents[$id]=true;}
            }
            foreach(array_keys($documents)as$id)$this->asset($assets,$blobs,$id,'document',(new WebsiteDocuments($this->db,$this->access,$assetRoot.'/website-documents'))->file($id),[]);
            $content=['format'=>Package::FORMAT,'version'=>1,'fields'=>$fields,'templates'=>$templates,'pages'=>array_values($pages),'assets'=>array_values($assets),'languages'=>$draftLanguages];Content::validate($content);
            $package=Package::create($target,$content,$blobs);return $package+['pages'=>count($pages),'fields'=>count($fields),'templates'=>count($templates),'assets'=>count($assets),'languages'=>count($draftLanguages)];
        });
    }

    private function asset(array &$assets,array &$blobs,string $id,string $kind,array $file,array $metadata):void
    {
        if(isset($assets[$id])&&$assets[$id]['kind']!==$kind)throw new InvalidArgumentException('Mehrdeutige Dateiabhängigkeit im Paket.');
        $assets[$id]=['id'=>$id,'kind'=>$kind,'name'=>$file['name'],'mime'=>$file['mime'],'bytes'=>$file['bytes'],'sha256'=>$file['sha256'],'metadata'=>Metadata::normalize($metadata,$file['name'])];$blobs[$file['sha256']]=$file['path'];if(count($assets)>100)throw new InvalidArgumentException('Auswahl und Abhängigkeiten umfassen mehr als 100 Dateien.');
    }
}
