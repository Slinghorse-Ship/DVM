<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Web\ReelStudioAssets;
$root=dirname(__DIR__).'/resources/reel-aac';
$provenance=json_decode(file_get_contents($root.'/provenance.json'),true,32,JSON_THROW_ON_ERROR);
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;};
foreach($provenance['files']as$file=>$hash)$check(is_file($root.'/'.$file)&&hash_file('sha256',$root.'/'.$file)===$hash,'exact local AAC runtime, corresponding source and licence: '.$file);
$check($provenance['aac_encoder']['version']==='1.61.3'&&$provenance['libavcodec']['source']==='FFmpeg 8.0'&&$provenance['libavcodec']['gpl']===false&&$provenance['libavcodec']['nonfree']===false,'explicit pinned MPL encoder and LGPL-only AAC build');
$check($provenance['libavcodec']['initial_memory_bytes']===18874368&&$provenance['libavcodec']['maximum_memory_bytes']===67108864,'initial and maximum worker memory have bounded exact build inputs');
foreach(ReelStudioAssets::sources()as$name=>$file)$check(is_file($file),'both manager entry points have the actual local codec file: '.$name);
$module=file_get_contents($root.'/native-aac-encoder.mjs');$check(str_contains($module,"new URL('./native-aac-worker.js', import.meta.url)")&&!str_contains($module,'new Blob(')&&!str_contains($module,'inlineWorker('),'AAC factory uses one local module worker without broad blob-worker policy');
$worker=file_get_contents($root.'/native-aac-worker.js');$check(str_contains($worker,"import createModule from './native-aac-libav.mjs'"),'actual audio worker resolves the rebuilt own libavcodec module');
foreach(['portrait-avc-center-5.1.mp4'=>'7c987f21b893f7b02d23bb51d5a1f791e20c9289c5124544d121449414718fe9','portrait-avc-center-7.1.mp4'=>'9a7466942ce252343565da624b69f76cadd87293529a9838694c370f3c8e4ff4']as$fixture=>$hash){$file=__DIR__.'/fixtures/reel-profile/'.$fixture;$check(hash_file('sha256',$file)===$hash,'actual corresponding surround source byte proof: '.$fixture);$rejected=false;try{\Kulturhus\Native\Communication\ReelFile::inspect($file);}catch(DomainException){$rejected=true;}$check($rejected,'common publication profile rejects raw surround until explicit stereo processing: '.$fixture);}
echo $n.' AAC source/licence/asset/profile contracts passed; no provider or shared database action.'.PHP_EOL;
require __DIR__.'/native-videos.php';
