<?php declare(strict_types=1);
/** Match a normal webroot: public bytes are served statically, never boot a CMS per asset. */
function ownFintsDirectoryPublicAssets(string $root):void
{
 if(!preg_match('~/\.local/native-ci-[a-f0-9]{24}/editorial-workflow-kh_native_[a-f0-9]{24}\z~D',$root)||is_link($root)||!is_dir($root.'/web')||is_link($root.'/web'))throw new RuntimeException('Exact owned directory browser root required.');
 $public=dirname(__DIR__,2).'/public';$target=$root.'/web/vereinsverwaltung';if(!is_dir($target))mkdir($target,0700);
 $types=['css','js','mjs','svg','png','jpg','jpeg','gif','webp','ico','woff','woff2','ttf','otf','wasm','webmanifest'];
 $copy=static function(string $name,string $source)use($target,$types):void{if(!preg_match('~\A[a-zA-Z0-9_./-]+\z~D',$name)||in_array('..',explode('/',$name),true)||!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),$types,true)||!is_file($source))return;$file=$target.'/'.$name;if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);if(!copy($source,$file))throw new RuntimeException('Owned directory public asset unavailable.');};
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public,FilesystemIterator::SKIP_DOTS))as$asset)if($asset->isFile()&&!$asset->isLink())$copy(str_replace(DIRECTORY_SEPARATOR,'/',substr($asset->getPathname(),strlen($public)+1)),$asset->getPathname());
 foreach(\Kulturhus\Native\Integration\Assets::sources()as$name=>$source)$copy($name,$source);
}
