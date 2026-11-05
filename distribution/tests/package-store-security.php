<?php declare(strict_types=1);
require __DIR__.'/../src/PackageStore.php';
$checks=0;$skipped=0;
function check(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);++$checks;}
function rejected(callable $run,string $label):void{try{$run();}catch(RuntimeException){check(true,$label);return;}throw new RuntimeException('Not rejected: '.$label);}
$fixtureRoot=getenv('VEREIN_TEST_ROOT')?:(PHP_OS_FAMILY==='Linux'?sys_get_temp_dir():__DIR__.'/../../.local');
$base=rtrim($fixtureRoot,'/\\').'/package-store-security-'.bin2hex(random_bytes(5));mkdir($base,0700,true);$base=str_replace('\\','/',(string)realpath($base));
function archiveZip(string $path,array $entries):void{$zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Fixture zip failed');foreach($entries as $entry){[$name,$content,$mode]=$entry;$zip->addFromString($name,$content);$zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,$mode<<16);}$zip->close();}
foreach(['../file','a/../file','/absolute','C:/file','a\\b',"a\nfile",'a//b','./file',''] as $path)rejected(fn()=>PackageStore::relative($path),'reject unsafe manifest path');
archiveZip($base.'/valid.zip',[['prefix/bin/program','sample',0100755],['prefix/docs/readme.txt','readme',0100644]]);
PackageStore::extract($base.'/valid.zip',$base.'/valid','zip',1);
check(file_get_contents($base.'/valid/bin/program')==='sample'&&file_get_contents($base.'/valid/docs/readme.txt')==='readme','valid zip extracts after explicit prefix removal');
if(PHP_OS_FAMILY!=='Windows')check((fileperms($base.'/valid/bin/program')&0111)!==0,'executable mode retained without special bits');
$malformed=[
 'traversal'=>[['first.txt','keep',0100644],['../outside.txt','escape',0100644]],
 'duplicate-path'=>[['a/b.txt','first',0100644],['a//b.txt','second',0100644]],
 'file-parent'=>[['a','file',0100644],['a/b.txt','child',0100644]],
 'file-parent-reverse'=>[['a/b.txt','child',0100644],['a','file',0100644]],
 'symlink'=>[['first.txt','keep',0100644],['link','outside',0120777]],
 'device'=>[['first.txt','keep',0100644],['device','',0020600]],
 'fifo'=>[['first.txt','keep',0100644],['fifo','',0010600]],
];
foreach($malformed as $name=>$entries){$path=$base.'/'.$name.'.zip';archiveZip($path,$entries);$target=$base.'/'.$name;rejected(fn()=>PackageStore::extract($path,$target,'zip'),'reject malformed archive '.$name);check(!is_dir($target)||scandir($target)===['.','..'],'no earlier member is written before malformed archive rejection');}
$target=$base.'/existing';mkdir($target,0700);file_put_contents($target.'/protected.txt','existing');
archiveZip($base.'/overwrite.zip',[['new.txt','new',0100644],['protected.txt','replace',0100644]]);
rejected(fn()=>PackageStore::extract($base.'/overwrite.zip',$target,'zip'),'preflight rejects overwrite');check(!file_exists($target.'/new.txt')&&file_get_contents($target.'/protected.txt')==='existing','overwrite rejection preserves whole original target');
rejected(fn()=>PackageStore::extract($base.'/valid.zip',$base.'/bad-strip','zip',-1),'negative strip count rejected');
// ZIP attributes may identify a directory even when its name lacks a trailing slash.
archiveZip($base.'/directory.zip',[['folder','',0040755],['folder/file','ok',0100644]]);PackageStore::extract($base.'/directory.zip',$base.'/directory','zip');check(file_get_contents($base.'/directory/folder/file')==='ok','directory attributes interpreted consistently');
$outside=$base.'/outside';mkdir($outside,0700);
if(@symlink($outside,$base.'/link')){rejected(fn()=>PackageStore::directory($base.'/link/new'),'reject symlink ancestor during mkdir');rejected(fn()=>PackageStore::extract($base.'/valid.zip',$base.'/link/target','zip'),'reject symlink ancestor during extraction');check(scandir($outside)===['.','..'],'symlink target untouched');}else{++$skipped;}
$stream=fopen('php://temp','w+');fwrite($stream,str_repeat('x',100));rewind($stream);$method=new ReflectionMethod(PackageStore::class,'streamFile');
rejected(fn()=>$method->invoke(null,$stream,$base.'/oversized-file',2,0100644),'stream with excess data rejected');check(filesize($base.'/oversized-file')===3,'excess stream writes at most declared length plus one byte');
$source=$base.'/source.php';file_put_contents($source,"one\r\ntwo\r\n");$entry=['bytes'=>8,'sha256'=>hash('sha256',"one\ntwo\n"),'normalized_lf'=>true];PackageStore::check($source,$entry);check(true,'CRLF checkout verified as declared LF-normalized source');
file_put_contents($source,"one\ntwo\n");clearstatcache(true,$source);PackageStore::check($source,$entry);check(true,'LF checkout uses identical declared source hash');
file_put_contents($source,"one\r\nchanged\r\n");clearstatcache(true,$source);rejected(fn()=>PackageStore::check($source,$entry),'source normalization does not hide content changes');
$tar=new PharData($base.'/valid.tar');$tar['nested/data.txt']='tar fixture';$tar->compress(Phar::GZ);unset($tar);
// PHP 8.3 caches a just-compressed archive with empty streams in the producing process.
// A fresh path represents the already-built archives consumed by the installer.
copy($base.'/valid.tar.gz',$base.'/ready.tar.gz');PackageStore::extract($base.'/ready.tar.gz',$base.'/tar');check(file_get_contents($base.'/tar/nested/data.txt')==='tar fixture','valid compressed TAR still extracts');
echo "PASS $checks package-store checks; $skipped symlink test group unavailable on this host\n";
