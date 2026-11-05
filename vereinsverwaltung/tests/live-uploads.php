<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Infrastructure/Media/LiveUploads.php';
use Kulturhus\Verein\LiveUploads;
$root=sys_get_temp_dir().'/live-upload-test-'.bin2hex(random_bytes(8));mkdir($root,0700);session_save_path($root);mkdir($root.'/live-uploads',0700);$_SESSION=[];
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);echo "PASS $message\n";}
function denied(callable $fn,string $message):void{try{$fn();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
$token=bin2hex(random_bytes(32));$path=$root.'/live-uploads/'.$token.'.bin';file_put_contents($path,'plain data');
$_SESSION['live_uploads'][$token]=['actor'=>'owner','action'=>'document-version','field'=>'document_file','name'=>'note.txt','size'=>10,'mime'=>'text/plain','hash'=>hash('sha256','plain data'),'created'=>time()];
try{
 check(!LiveUploads::file($path),'arbitrary local file is not an upload');
 denied(fn()=>LiveUploads::attach('other','document-version',['document_file'=>[$token]]),'actor binding');
 denied(fn()=>LiveUploads::attach('owner','document-upload',['document_file'=>[$token]]),'action binding');
 denied(fn()=>LiveUploads::attach('owner','document-version',['logo'=>[$token]]),'field binding');
 $_SESSION['live_uploads'][$token]['created']=time()-3601;
 denied(fn()=>LiveUploads::attach('owner','document-version',['document_file'=>[$token]]),'expired upload rejected');
 $_SESSION['live_uploads'][$token]['created']=time();file_put_contents($path,'changed');
 denied(fn()=>LiveUploads::attach('owner','document-version',['document_file'=>[$token]]),'modified staged bytes rejected');
 file_put_contents($path,'plain data');LiveUploads::attach('owner','document-version',['document_file'=>[$token]]);
 check(LiveUploads::file($path)&&$_FILES['document_file']['name']==='note.txt','validated token attaches ordinary upload metadata');
 LiveUploads::finish();check(!is_file($path)&&!isset($_SESSION['live_uploads'][$token])&&!LiveUploads::file($path),'successful finalization removes bytes and authorization');
}finally{if(is_file($path))unlink($path);rmdir($root.'/live-uploads');rmdir($root);}

