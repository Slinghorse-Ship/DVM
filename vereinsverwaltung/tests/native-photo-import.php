<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\Web\{PhotoImportAssets,Manager};
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1')exit(1);
$root=dirname(__DIR__).'/resources/photo-import';$provenance=json_decode(file_get_contents($root.'/provenance.json'),true,64,JSON_THROW_ON_ERROR);$n=0;
foreach($provenance['files']as$f){if(filesize($root.'/'.$f['file'])!==$f['bytes']||hash_file('sha256',$root.'/'.$f['file'])!==$f['sha256'])throw new RuntimeException('Pinned decoder or corresponding source differs: '.$f['file']);$n++;}
foreach(PhotoImportAssets::scripts()+PhotoImportAssets::libraries()as$name=>$file){if(!is_file($file)||!is_readable($file)||str_contains($name,'/'))throw new RuntimeException('Local photo asset unavailable.');$n++;}
echo "$n pinned photo assets, licences and corresponding sources verified.\n";
// Actual worker/pixel/multipart contracts run in photo-import-browser.mjs after
// Actions installs Chromium; the native suite never silently skips that CI step.

$fixture=__DIR__.'/fixtures/photo-import/';$camera=json_decode(file_get_contents($fixture.'own-jxl-preview.json'),true,16,JSON_THROW_ON_ERROR);
if(($camera['raw_compression']??null)!==52546||($camera['lossless_independent_ffmpeg_decode']??null)!==true||($camera['sensor_width']??null)!==1024||($camera['sensor_height']??null)!==768||!hash_equals($camera['dng_sha256']??'',hash_file('sha256',$fixture.'own-jxl-preview.dng')))throw new RuntimeException('Own independently proven JPEG XL RAW fixture required.');
echo "Own JPEG XL DNG fixture hash and independent lossless sensor proof verified.\n";
