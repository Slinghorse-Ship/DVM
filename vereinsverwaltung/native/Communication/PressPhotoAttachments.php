<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use DomainException;
use PDO;
use Kulturhus\Native\Cms\Media;
use Kulturhus\Native\Shared\{Access,Db};

/** Independently selected photo originals; durable mail contains bytes, never filesystem paths. */
final class PressPhotoAttachments
{
    public static function ids(mixed $ids):array
    {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>100)throw new DomainException('Fotoanlagen auswählen.');
        return array_values(array_unique(array_map(Content::id(...),$ids)));
    }
    public static function selection(array $post):array
    {
        return self::ids($post['attachment_media_ids']??[]);
    }
    public static function freeze(PDO $db,Access $access,string $root,array $ids):array
    {
        $ids=self::ids($ids);if(count($ids)>10)throw new DomainException('Bis zu zehn Fotoanlagen pro E-Mail auswählen.');
        $photos=[];$bytes=0;$store=new Media($db,new PressMediaAccess($db,$access),$root);
        foreach($ids as$id){
            $expected=Db::run($db,'SELECT sha256 FROM khcms_media WHERE club_id=? AND id=? FOR SHARE',[$access->club(),$id])->fetchColumn();
            $file=$store->variant($id,'original');if(!is_string($expected)||!hash_equals($expected,$file['sha256']))throw new DomainException('Ursprüngliche Fotoanlage verändert.');$bytes+=$file['bytes'];if($bytes>10000000)throw new DomainException('Fotoanlagen zusammen auf 10 MB begrenzen.');
            $raw=file_get_contents($file['path']);if(!is_string($raw)||strlen($raw)!==$file['bytes']||!hash_equals($file['sha256'],hash('sha256',$raw)))throw new DomainException('Ursprüngliche Fotoanlage verändert.');
            $name=self::filename($file);
            $photos[]=['media_id'=>$id,'name'=>$name,'mime'=>$file['mime'],'base64'=>base64_encode($raw),'sha256'=>$file['sha256']];
        }
        return $photos;
    }
    public static function filename(array $file):string
    {
        $ext=match($file['mime']){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>throw new DomainException('Fotoformat prüfen.')};
        $name=str_replace(['/',chr(92)],'_',$file['name']);$name=preg_replace('/[\x00-\x1f\x7f]/u','',$name);$base=pathinfo($name,PATHINFO_FILENAME);
        return mb_strcut($base?:'Pressefoto',0,180,'UTF-8').'.'.$ext;
    }
    public static function open(mixed $photos):array
    {
        if(!is_array($photos)||!array_is_list($photos)||count($photos)>10)throw new DomainException('Gespeicherte Fotoanlagen prüfen.');
        $result=[];$bytes=0;
        foreach($photos as$photo){
            if(!is_array($photo)||!is_string($photo['base64']??null)||strlen($photo['base64'])>14000000||!is_string($photo['name']??null)||$photo['name']===''||strlen($photo['name'])>200||preg_match('~[\x00-\x1f/\\\\]~',$photo['name'])||!in_array($photo['mime']??null,['image/jpeg','image/png','image/webp'],true)||!is_string($photo['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$photo['sha256']))throw new DomainException('Gespeicherte Fotoanlage prüfen.');
            Content::id($photo['media_id']??null);$raw=base64_decode($photo['base64'],true);$size=is_string($raw)?@getimagesizefromstring($raw):false;$bytes+=is_string($raw)?strlen($raw):0;
            if(!$size||$size['mime']!==$photo['mime']||!hash_equals($photo['sha256'],hash('sha256',$raw))||$bytes>10000000)throw new DomainException('Gespeicherte Fotoanlage ist beschädigt oder zu groß.');
            $result[]=['name'=>$photo['name'],'mime'=>$photo['mime'],'bytes'=>$raw];
        }
        return $result;
    }
}
