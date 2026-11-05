<?php declare(strict_types=1);
namespace Kulturhus\Native\Files;

use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Cms\{CameraPhotos,MediaUploads,UploadTransaction};
use Kulturhus\Native\Shared\Access;

/** Single/multiple mixed selections reuse the established HEIC/RAW and image quota pipeline. */
final class Uploads
{
    public function __construct(private PDO $db,private Access $access,private string $fileRoot,private string $mediaRoot){}
    public function uploadMany(array $files,?callable $associate=null):mixed
    {
        $this->access->require('website','edit');
        return UploadTransaction::run($this->db,function($transaction)use($files,$associate):mixed{
            if(!array_is_list($files)||count($files)<1||count($files)>10)throw new InvalidArgumentException('Eine bis zehn Dateien gleichzeitig auswählen.');
            $photos=[];$documents=[];$photoIndices=[];$fileIndices=[];
            foreach($files as$index=>$file){if(!is_array($file)||array_diff(array_keys($file),['path','name'])||!is_string($file['path']??null)||!is_string($file['name']??null))throw new InvalidArgumentException('Uploaddateien prüfen.');if(self::photo($file['path'],$file['name'])){$photos[]=$file;$photoIndices[]=$index;}else{$documents[]=$file;$fileIndices[]=$index;}}
            $versions=new AssetVersions($this->db,$this->access,$this->fileRoot,$this->mediaRoot);$rows=[];
            if($documents!==[]){$created=$versions->uploadMany($documents,null,$transaction);foreach($created as$index=>$row)$rows[$fileIndices[$index]]=$row;}
            if($photos!==[]){$created=(new MediaUploads($this->db,$this->access,$this->mediaRoot))->uploadMany($photos,fn($images)=>array_map(fn($image)=>$versions->linkImage($image['id']),$images),$transaction);foreach($created as$index=>$row)$rows[$photoIndices[$index]]=$row;}
            ksort($rows,SORT_NUMERIC);$rows=array_values($rows);return $associate?$associate($rows):$rows;
        });
    }
    public function replace(string $id,int $version,string $path,string $name):array
    {
        $versions=new AssetVersions($this->db,$this->access,$this->fileRoot,$this->mediaRoot);$asset=$versions->get($id);$photo=self::photo($path,$name);
        if(($asset['kind']==='image')!==$photo)throw new InvalidArgumentException($asset['kind']==='image'?'Für diese Versionsfolge ein Foto auswählen.':'Für diese Versionsfolge ein Dokument, Archiv oder Audio-/Videodatei auswählen.');
        if(!$photo)return $versions->replace($id,$version,$path,$name);
        return UploadTransaction::run($this->db,fn($transaction)=>(new MediaUploads($this->db,$this->access,$this->mediaRoot))->uploadMany([['path'=>$path,'name'=>$name]],fn($images)=>$versions->linkImage($images[0]['id'],$id,$version),$transaction));
    }
    private static function photo(string $path,string $name):bool
    {
        $kind=CameraPhotos::sourceKind($path,$name);if($kind!==null)return true;
        return in_array((new \finfo(FILEINFO_MIME_TYPE))->file($path),['image/jpeg','image/png','image/webp'],true);
    }
}
