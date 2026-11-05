<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,InvoiceRecognition};
$root=sys_get_temp_dir().'/ocr-paths-'.bin2hex(random_bytes(8));mkdir($root,0700);$n=0;
function check($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);++$n;echo "PASS $message\n";}
function denied(callable $run,$message){try{$run();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
$schema=\Kulturhus\Verein\Tests\MySqlFixture::connect();Database::migrate($schema);$capacity=$schema->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_recognition' AND COLUMN_NAME='result_json'")->fetchColumn();
check((int)$capacity>=10485760,'MySQL keeps full-size OCR result JSON');
try{
 foreach(['config','runtime','runtime/lib','runtime/tessdata'] as $dir)mkdir($root.'/'.$dir,0700);
 foreach(['python','tesseract','lib/loader'] as $file)file_put_contents($root.'/runtime/'.$file,'synthetic path fixture, never executed');
 $config=['python'=>'../runtime/python','tesseract'=>'../runtime/tesseract','tessdata'=>'../runtime/tessdata','tesseract_loader'=>'../runtime/lib/loader','tesseract_library_path'=>'../runtime/lib','sandbox_read_roots'=>['../runtime'],'require_sandbox'=>true];
 $path=$root.'/config/runtime.json';file_put_contents($path,json_encode($config));$resolved=InvoiceRecognition::runtime($path);
 check($resolved['python']===realpath($root.'/runtime/python'),'relative executable resolves from config, not process cwd');
 check($resolved['tesseract_loader']===realpath($root.'/runtime/lib/loader'),'native loader follows hosting-visible path');
 check($resolved['sandbox_read_roots']===[realpath($root.'/runtime')],'sandbox boundaries follow same private runtime');
 check($resolved['require_sandbox']===true,'path resolution does not disable confinement');
 rename($root.'/runtime',$root.'/relocated');foreach($config as $key=>$value)if(is_string($value))$config[$key]=str_replace('../runtime/','../relocated/',$value);$config['sandbox_read_roots']=['../relocated'];file_put_contents($path,json_encode($config));
 check(InvoiceRecognition::runtime($path)['python']===realpath($root.'/relocated/python'),'deployment relocation works without global absolute paths');
 $config['python']=realpath($root.'/relocated/python');file_put_contents($path,json_encode($config));check(InvoiceRecognition::runtime($path)['python']===$config['python'],'existing absolute local runtime remains supported');
 unset($config['tesseract_library_path']);file_put_contents($path,json_encode($config));denied(fn()=>InvoiceRecognition::runtime($path),'incomplete loader configuration fails closed');
 $config['python']=['invalid'];file_put_contents($path,json_encode($config));denied(fn()=>InvoiceRecognition::runtime($path),'malformed executable rejected');
 file_put_contents($path,'{}');denied(fn()=>InvoiceRecognition::runtime($path),'missing executable rejected');
 denied(fn()=>InvoiceRecognition::runtime($path.'.missing'),'missing config rejected');
}finally{
 foreach(['runtime','relocated'] as $dir)if(is_dir($root.'/'.$dir)){foreach(['python','tesseract','lib/loader'] as $file)unlink($root.'/'.$dir.'/'.$file);rmdir($root.'/'.$dir.'/lib');rmdir($root.'/'.$dir.'/tessdata');rmdir($root.'/'.$dir);}
 @unlink($root.'/config/runtime.json');rmdir($root.'/config');rmdir($root);
}
echo "$n runtime path checks passed\n";
