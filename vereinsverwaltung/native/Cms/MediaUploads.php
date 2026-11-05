<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\Access;
use PDO;

/** Composes the existing pixel decoder and quota rules without exposing intermediate copies. */
final class MediaUploads
{
    public const MAX_FILES=10;
    public function __construct(private PDO $db,private Access $access,private string $root) {}
    public function uploadMany(array $files,?callable $associate=null,?UploadTransaction $context=null,string $alt=''): mixed
    {
        $this->access->require('website','edit');
        if($context===null)return UploadTransaction::run($this->db,fn($tx)=>$this->uploadMany($files,$associate,$tx,$alt));
        $context->require($this->db);
        if(!array_is_list($files)||count($files)>self::MAX_FILES)throw new \InvalidArgumentException('Höchstens zehn Fotos gleichzeitig speichern.');
        $club=$this->access->require('website','edit')['club_id'];$media=new Media($this->db,$this->access,$this->root);$root=realpath($this->root);$copies=[];$owned=[];$total=0;$sourceTotal=0;
        $cleanup=static function()use(&$owned):void{
            foreach($owned as$directory=>$files){
                if(is_link($directory))continue;
                foreach($files as$file=>$hash)if(!is_link($file)&&is_file($file)&&hash_file('sha256',$file)===$hash)@unlink($file);
                @rmdir($directory);
            }
        };
        return $context->segment($this->db,function()use($files,$associate,$club,$media,$root,$alt,$context,$cleanup,&$owned,&$copies,&$total,&$sourceTotal):mixed{
        $context->onRollback($this->db,$cleanup);
        foreach($files as$file){
            if(!is_array($file)||array_diff(array_keys($file),['path','name'])||!is_string($file['path']??null)||!is_string($file['name']??null))throw new \InvalidArgumentException('Neue Fotodateien prüfen.');
            if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$file['path'])||is_link($file['path'])||!is_file($file['path']))throw new \InvalidArgumentException('Lokale Fotodatei prüfen.');
            $bytes=filesize($file['path']);if($bytes===false||$bytes<1||$bytes>CameraPhotos::INPUT_BYTES||($sourceTotal+=$bytes)>CameraPhotos::BATCH_INPUT_BYTES)throw new \InvalidArgumentException('Kameraoriginale bis 256 MiB je Datei und zusammen bis 512 MiB auswählen.');
            if($this->access->require('website','edit')['club_id']!==$club)throw new Conflict('Vereinszugehörigkeit inzwischen geändert.');
            $copy=CameraPhotos::withFile($file['path'],$file['name'],function(string $camera)use($media,$file,$alt,$context,$club,&$total):array{return RasterPhotos::withFile($camera,function(string $prepared)use($media,$file,$alt,$context,$club,&$total):array{
                $bytes=filesize($prepared);if($bytes===false||($total+=$bytes)>CameraPhotos::PREPARED_BATCH_BYTES)throw new \InvalidArgumentException('Vorbereitete Fotos zusammen bis 40 MiB auswählen.');
                if($this->access->require('website','edit')['club_id']!==$club)throw new Conflict('Vereinszugehörigkeit inzwischen geändert.');
                return $media->upload($prepared,$file['name'],$alt,null,null,null,$context);
            });});
            $directory=$root.DIRECTORY_SEPARATOR.hash('sha256',$club).DIRECTORY_SEPARATOR.$copy['id'];$paths=[];
            foreach($copy['variants']as$variant){$path=$directory.DIRECTORY_SEPARATOR.$variant['file'];$paths[$path]=hash_file('sha256',$path);}
            $owned[$directory]=$paths;$copies[]=$copy;
        }
        return $associate===null?$copies:$associate($copies);
        });
    }
}
