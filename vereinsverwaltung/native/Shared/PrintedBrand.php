<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
/** Only the configured club logo; never a general media-reader capability. Frozen pixels for original receipts. */
final class PrintedBrand
{
    public static function snapshot(\PDO $db,string $club,array $identity,string $mediaRoot):array
    {
        $result=['logo_status'=>empty($identity['logo_id'])?'not-configured':'unavailable','logo_png'=>'','qr_png'=>''];
        if(($identity['origin']??'')!==''){$origin=\Kulturhus\Native\Communication\Content::url($identity['origin']);$result['qr_png']=base64_encode(Qr::png($origin));}
        if(empty($identity['logo_id'])||$mediaRoot===''||is_link($mediaRoot))return $result;
        $root=realpath($mediaRoot);if(!$root||!is_dir($root))return $result;$documentRoot=$_SERVER['DOCUMENT_ROOT']??'';$public=$documentRoot!==''?realpath($documentRoot):false;if($public&&($root===$public||str_starts_with($root,$public.'/')))return $result;
        $id=\Kulturhus\Native\Communication\Content::id($identity['logo_id']);$row=Db::run($db,'SELECT mime,sha256 FROM khcms_media WHERE club_id=? AND id=?',[$club,$id])->fetch();$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$row['mime']??'']??null;if(!$ext)return $result;
        $dir=$root.'/'.hash('sha256',$club).'/'.$id;$file=$dir.'/original.'.$ext;$actual=realpath($file);
        if(!$actual||!str_starts_with($actual,$root.'/')||is_link($dir)||is_link($file)||!is_file($actual)||filesize($actual)>12*1024*1024||!hash_equals($row['sha256'],hash_file('sha256',$actual)))return $result;
        $size=@getimagesize($actual);if(!$size||$size[0]*$size[1]>6000000||($size['mime']??'')!==$row['mime'])return $result;
        $source=match($ext){'png'=>@imagecreatefrompng($actual),'jpg'=>@imagecreatefromjpeg($actual),'webp'=>@imagecreatefromwebp($actual)};if(!$source)return $result;
        $ratio=min(1,320/max($size[0],$size[1]));$image=imagecreatetruecolor(max(1,(int)round($size[0]*$ratio)),max(1,(int)round($size[1]*$ratio)));imagealphablending($image,false);imagesavealpha($image,true);imagecopyresampled($image,$source,0,0,0,0,imagesx($image),imagesy($image),$size[0],$size[1]);ob_start();try{imagepng($image,null,6);$bytes=ob_get_contents();}finally{ob_end_clean();unset($image,$source);}
        if(is_string($bytes)&&strlen($bytes)<500000)$result=['logo_status'=>'frozen','logo_png'=>base64_encode($bytes),'logo_sha256'=>hash('sha256',$bytes),'qr_png'=>$result['qr_png']];return $result;
    }
}
