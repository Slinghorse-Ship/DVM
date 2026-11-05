<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\Cms\{RasterPhotos,CameraPhotos};
if(($argv[1]??'')==='--generate'){
 $root=$argv[2];if(!is_dir($root)||is_link($root))throw new RuntimeException('Own fixture root required.');
 $image=imagecreatetruecolor(8000,6000);$colors=[[220,30,40],[20,180,70],[30,70,220],[225,180,20]];
 foreach($colors as$n=>$color)imagefilledrectangle($image,($n%2)*4000,intdiv($n,2)*3000,($n%2)*4000+3999,intdiv($n,2)*3000+2999,imagecolorallocate($image,...$color));
 imagejpeg($image,$root.'/48mp.jpg',90);imagepng($image,$root.'/48mp.png',1);imagewebp($image,$root.'/48mp.webp',90);unset($image);
 $image=imagecreatetruecolor(12,8);imagealphablending($image,false);imagesavealpha($image,true);imagefill($image,0,0,imagecolorallocatealpha($image,35,120,170,50));imagepng($image,$root.'/small.png');imagejpeg($image,$root.'/small.jpg',90);imagewebp($image,$root.'/small.webp',90);unset($image);
 $jpeg=file_get_contents($root.'/48mp.jpg');for($n=1;$n<=8;$n++){$tiff='II'.pack('vVv',42,8,1).pack('vvVv',274,3,1,$n)."\0\0".pack('V',0);$exif="Exif\0\0".$tiff;file_put_contents($root.'/orientation-'.$n.'.jpg',substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));}
 file_put_contents($root.'/invalid.jpg','Own corrupt raster source');
 echo json_encode(['generated'=>true]).PHP_EOL;exit;
}
$allocatorBefore=null;
if(in_array($argv[1]??'',['--fragmented-raster','--crowded-raster','--retained-raster'],true)){
 $count=($argv[1]??'')==='--crowded-raster'?115000:150000;
 $chunks=[];for($i=0;$i<$count;$i++)$chunks[]=str_repeat(chr(65+$i%20),256);if(($argv[1]??'')==='--fragmented-raster')unset($chunks);
 $allocatorBefore=['allocated_bytes'=>memory_get_usage(true),'live_bytes'=>memory_get_usage(false)];
}
$source=$argv[2]??'';$consumed=false;$path=null;$status='prepared';$data=[];
try{
 $consumer=static function(string $file)use(&$consumed,&$path):array{
  $consumed=true;$path=$file;$info=getimagesize($file);$pixels=[];
  if($info){$image=match($info['mime']){'image/jpeg'=>imagecreatefromjpeg($file),'image/png'=>imagecreatefrompng($file),'image/webp'=>imagecreatefromwebp($file),default=>false};if($image){foreach([[0,0],[imagesx($image)-1,0],[0,imagesy($image)-1],[imagesx($image)-1,imagesy($image)-1]]as[$x,$y])$pixels[]=imagecolorsforindex($image,imagecolorat($image,$x,$y));unset($image);}}
  return ['info'=>$info,'bytes'=>filesize($file),'prepared_sha256'=>hash_file('sha256',$file),'corners'=>$pixels];
 };
 $data=($argv[1]??'')==='--camera'?CameraPhotos::withFile($source,'Own camera.heic',$consumer):RasterPhotos::withFile($source,$consumer);
}catch(InvalidArgumentException $error){$status='rejected';$data=['message'=>$error->getMessage()];}
echo json_encode(['status'=>$status,'allocator_before'=>$allocatorBefore,'consumed'=>$consumed,'temporary_removed'=>$path!==null&&$path!==$source?!file_exists($path):null,'source_sha256'=>is_file($source)?hash_file('sha256',$source):null,'memory_limit'=>ini_get('memory_limit'),'zend_peak'=>memory_get_peak_usage(true),'native_rss_kib'=>getrusage()['ru_maxrss']??null,'child_rss_kib'=>getrusage(1)['ru_maxrss']??null]+$data,JSON_THROW_ON_ERROR).PHP_EOL;
