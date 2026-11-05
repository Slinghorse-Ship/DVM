<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;

use Kulturhus\Native\Cms\{CameraPhotos,MediaUploads,UploadTransaction};
use Kulturhus\Native\Files\{AssetVersions,Catalog,Formats,ImageGroups,Removal};
use Kulturhus\Native\Shared\Db;

/** Versioned general files use their native services and immutable original references. */
final class FilesController
{
    private readonly string $root;
    public function __construct(private readonly \PDO $db,private readonly Context $context,private readonly string $mediaRoot)
    {$this->root=dirname($mediaRoot).'/files';}
    private function service():AssetVersions{return new AssetVersions($this->db,$this->context->access,$this->root,$this->mediaRoot);}
    private function ok(mixed $data,array $meta=[],int $status=200):array{return ['status'=>$status,'data'=>$data,'meta'=>$meta];}
    private function own(string $id,bool $removed=false):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw Problem::missing();
        $row=Db::run($this->db,'SELECT state FROM khcms_assets WHERE id=? AND club_id=?',[$id,$this->context->access->club()])->fetch();
        if(!$row||(!$removed&&$row['state']!=='active'))throw Problem::missing();
        if($row['state']!=='active')$this->context->access->require('website','manage');
    }
    private static function page(array $query):int
    {Input::keys($query,['page']);$value=$query['page']??1;if((!is_string($value)&&!is_int($value))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$value)||(int)$value>1000000)throw Problem::invalid();return (int)$value;}
    private static function flag(array $query,string $name):bool
    {$value=$query[$name]??'0';if(!is_string($value)||!in_array($value,['0','1'],true))throw Problem::invalid();return $value==='1';}
    private static function pagination(array $window):array
    {return ['page'=>(int)$window['page'],'limit'=>10,'total'=>(int)$window['total'],'pages'=>(int)$window['pages'],'has_more'=>$window['page']<$window['pages']];}
    public static function uploadPath(string $path):bool
    {return $path==='/api/v1/files/assets'||preg_match('~^/api/v1/files/assets/[a-f0-9]{32}/replace$~D',$path)===1;}

    public function dispatch(string $method,string $path,array $query,array $body,string $key):array|Download
    {
        $read=in_array($method,['GET','HEAD'],true);$this->context->require($read?'files.read':'files.write');
        if($path==='/api/v1/files/assets/link-image'&&$method==='POST'){Input::keys($query,[]);return $this->linkImage($body,$key);}
        if(!preg_match('~^/api/v1/files/(assets|revisions)(?:/([a-f0-9]{32})(?:/([a-z-]+))?)?$~D',$path,$match))throw Problem::missing();
        $type=$match[1];$id=$match[2]??null;$action=$match[3]??null;
        if($read){if($body)throw Problem::invalid();return Db::atomic($this->db,function()use($type,$id,$action,$query):array|Download{$result=$this->read($type,$id,$action,$query);$this->context->require('files.read');return $result;});}
        Input::keys($query,[]);if($type!=='assets'||$id===null||$method!=='POST')throw new Problem('method_not_allowed',405,'Dateiaktionen benötigen POST; Uploads benötigen multipart/form-data.');
        if(in_array($action,['remove','restore-removed','purge'],true))return $this->lifecycle($id,$action,$path,$body,$key);
        $this->own($id,$action==='reattach');
        if(in_array($action,['detach','reattach'],true))$this->context->access->require('website','manage');
        $result=(new Operations($this->db,$this->context,Factory::operationKey()))->run($key,$method,$path,$body,function()use($id,$action,$body):array{
            $this->context->require('files.write');$service=$this->service();$version=Input::version($body);
            if($action==='metadata'){
                Input::keys($body,['version','media_version','data']);$data=Input::object($body,'data');$old=$service->get($id);$data=array_replace($old['metadata'],$old['source_metadata']??[],$data);
                $mediaVersion=null;if(array_key_exists('media_version',$body)){$mediaVersion=Input::version(['version'=>$body['media_version']]);}
                $saved=$service->metadata($id,$version,$data,$mediaVersion);
            }elseif($action==='restore'){
                Input::keys($body,['version','revision_id']);$saved=$service->restore($id,Input::text($body,'revision_id',32),$version);
            }elseif($action==='link-image'){
                Input::keys($body,['version','source_id']);$saved=$service->linkImage(Input::text($body,'source_id',32),$id,$version);
            }elseif(in_array($action,['detach','reattach'],true)){
                Input::keys($body,['version']);$groups=new ImageGroups($this->db,$this->context->access,$this->root,$this->mediaRoot);$saved=$action==='detach'?$groups->detach($id,$version):$groups->restore($id,$version);
            }else throw new Problem('method_not_allowed',405,'Unbekannte Dateiaktion.');
            return $this->ok(FileDto::asset($saved));
        });
        // Replays acknowledge the original revision; current lifecycle authority still applies.
        $this->context->require('files.write');$this->own($id,in_array($action,['detach','reattach'],true));return $result;
    }

    private function read(string $type,?string $id,?string $action,array $query):array|Download
    {
        $service=$this->service();
        if($type==='revisions'){
            if($id===null||!in_array($action,[null,'content'],true))throw Problem::missing();Input::keys($query,[]);
            $asset=Db::run($this->db,'SELECT asset_id FROM khcms_asset_revisions WHERE id=? AND club_id=?',[$id,$this->context->access->club()])->fetchColumn();if(!is_string($asset))throw Problem::missing();$this->own($asset);
            $row=$service->revision($id);
            if($action==='content')return new Download($service->file($id),$this->context,fn()=>$this->service()->file($id),true);
            return $this->ok(FileDto::revision($row));
        }
        if($id===null){
            if($action!==null)throw Problem::missing();Input::keys($query,['q','page','kind','mine','tag','removed']);$page=self::page(array_intersect_key($query,['page'=>true]));$kind=Input::text($query,'kind',10,false)?:'all';$removed=self::flag($query,'removed');
            $window=(new Catalog($this->db,$this->context->access))->listing(Input::text($query,'q',200,false),$page,$kind,self::flag($query,'mine'),Input::text($query,'tag',60,false),$removed);
            return $this->ok(array_map(fn(array $row):array=>FileDto::asset($service->get($row['id'],$removed)),$window['rows']),self::pagination($window));
        }
        if($action==='versions'){
            $this->own($id,true);$window=$service->history($id,self::page($query));return $this->ok(array_map(fn(array $row):array=>FileDto::revision($row+['asset_id'=>$id,'kind'=>$window['asset']['kind']]),$window['rows']),self::pagination($window));
        }
        if($action==='removal'){
            Input::keys($query,[]);$this->own($id,true);return $this->ok(FileDto::removal((new Removal($this->db,$this->context->access,$this->root))->describe($id)));
        }
        if($action!==null)throw Problem::missing();Input::keys($query,['include_removed']);$removed=self::flag($query,'include_removed');$this->own($id,$removed);return $this->ok(FileDto::asset($service->get($id,$removed)));
    }

    /** Real HTTP uploads only: no body parameter can become a filesystem path. */
    public function upload(string $path,array $files,array $post,string $key):array
    {
        $this->context->require('files.write');if(!self::uploadPath($path))throw Problem::missing();$replacement=$path!=='/api/v1/files/assets';Input::keys($post,$replacement?['version']:['metadata']);
        if(!array_is_list($files)||!$files||count($files)>10||($replacement&&count($files)!==1))throw Problem::invalid();$fingerprints=[];$documentBytes=0;$cameraBytes=0;
        foreach($files as $file){Input::keys($file,['path','name']);if(!is_string($file['path']??null)||!is_string($file['name']??null)||preg_match('~^[a-z][a-z0-9+.-]*://~i',$file['path'])||is_link($file['path'])||!is_file($file['path']))throw Problem::invalid();$bytes=filesize($file['path']);if($bytes===false||$bytes<1)throw Problem::invalid();$photo=self::photo($file['path'],$file['name']);if($photo){if($bytes>CameraPhotos::INPUT_BYTES||($cameraBytes+=$bytes)>CameraPhotos::BATCH_INPUT_BYTES)throw new Problem('upload_too_large',413,'Kameraoriginale bis 256 MiB je Datei und zusammen bis 512 MiB auswählen.');}elseif($bytes>Formats::MAX_BYTES||($documentBytes+=$bytes)>Formats::MAX_BATCH_BYTES)throw new Problem('upload_too_large',413,'Dokumente bis 25 MiB je Datei und zusammen bis 50 MiB auswählen.');$fingerprints[]=['name'=>$file['name'],'bytes'=>$bytes,'sha256'=>hash_file('sha256',$file['path'])];}
        $metadata=[];if(array_key_exists('metadata',$post)){if(!is_string($post['metadata']))throw Problem::invalid();$metadata=Http::json($post['metadata']);}
        $version=null;$id=null;if($replacement){$id=explode('/',$path)[5];$this->own($id);$raw=$post['version']??null;if(!is_string($raw)||!preg_match('/^[1-9][0-9]{0,9}$/D',$raw)||(float)$raw>2147483646)throw Problem::invalid();$version=(int)$raw;}
        $input=['files'=>$fingerprints,'metadata'=>$metadata,'version'=>$version];
        $result=UploadTransaction::run($this->db,function(UploadTransaction $tx)use($path,$files,$metadata,$version,$id,$input,$key):array{
            return (new Operations($this->db,$this->context,Factory::operationKey()))->run($key,'POST',$path,$input,function()use($files,$metadata,$version,$id,$tx):array{
                $this->context->require('files.write');$service=$this->service();$rows=[];
                if($id!==null){
                    $old=$service->get($id);$file=$files[0];$photo=self::photo($file['path'],$file['name']);if(($old['kind']==='image')!==$photo)throw Problem::invalid();
                    $rows[]=$photo?(new MediaUploads($this->db,$this->context->access,$this->mediaRoot))->uploadMany($files,fn(array $images):array=>$service->linkImage($images[0]['id'],$id,$version),$tx):$service->replace($id,$version,$file['path'],$file['name'],null,$tx);
                }else{
                    // The native generic and camera services share one original SQL/disk rollback.
                    $photos=[];$documents=[];$photoIndices=[];$fileIndices=[];
                    foreach($files as $index=>$file){if(self::photo($file['path'],$file['name'])){$photos[]=$file;$photoIndices[]=$index;}else{$documents[]=$file+['metadata'=>$metadata];$fileIndices[]=$index;}}
                    if($documents){$created=$service->uploadMany($documents,null,$tx);foreach($created as $index=>$row)$rows[$fileIndices[$index]]=$row;}
                    if($photos){$created=(new MediaUploads($this->db,$this->context->access,$this->mediaRoot))->uploadMany($photos,fn(array $images):array=>array_map(fn(array $image):array=>$service->linkImage($image['id'],null,null,$metadata?:null),$images),$tx);foreach($created as $index=>$row)$rows[$photoIndices[$index]]=$row;}
                    ksort($rows,SORT_NUMERIC);$rows=array_values($rows);
                }
                $this->context->require('files.write');return $this->ok(array_map(FileDto::asset(...),$rows),[],$id===null?201:200);
            });
        });
        $this->context->require('files.write');foreach($result['data'] as $row){$this->own($row['id']);$this->service()->get($row['id']);}return $result;
    }
    private static function photo(string $path,string $name):bool
    {return CameraPhotos::sourceKind($path,$name)!==null||in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),['image/jpeg','image/png','image/webp'],true);}

    public function linkImage(array $body,string $key):array
    {
        $this->context->require('files.write');Input::keys($body,['source_id']);$source=Input::text($body,'source_id',32);
        $result=(new Operations($this->db,$this->context,Factory::operationKey()))->run($key,'POST','/api/v1/files/assets/link-image',$body,fn():array=>$this->ok(FileDto::asset($this->service()->linkImage($source)),[],201));
        $this->own($result['data']['id']);$this->context->require('files.write');return $result;
    }
    private function lifecycle(string $id,string $action,string $path,array $body,string $key):array
    {
        $this->context->access->require('website','manage');$this->own($id,true);Input::keys($body,$action==='remove'?['version']:($action==='purge'?['version','operation','confirmed']:['version','operation']));$version=Input::version($body);
        if($action==='purge'&&($body['confirmed']??null)!==true)throw Problem::invalid();$operation=$action==='remove'?null:Input::text($body,'operation',32);
        return (new RecoverableOperations($this->db,$this->context))->run($key,'POST',$path,$body,function(string $derived)use($id,$action,$version,$operation):array{
            $this->context->access->require('website','manage');$service=new Removal($this->db,$this->context->access,$this->root);
            $result=match($action){'remove'=>$service->remove($id,$version,$derived),'restore-removed'=>$service->restore($id,$version,$operation),'purge'=>$service->purge($id,$version,$operation)};
            return $this->ok(FileDto::removal($result));
        });
    }
}
