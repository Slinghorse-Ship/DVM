<?php declare(strict_types=1);
require __DIR__.'/einvoice-inspection.php';
use Kulturhus\Verein\{EInvoiceValidator,Documents};
$service=new EInvoiceValidator($db,'finance');
check($service->canRun(),'finance writer can explicitly validate');
check(!(new EInvoiceValidator($db,'member'))->canRun(),'member cannot launch validator');
check($service->latest($id)===null,'uploaded original starts without validation');
denied(fn()=>$service->validate($id,1,hash('sha256',$xml)),'old version POST denied before execution');
denied(fn()=>(new EInvoiceValidator($db,'foreign'))->validate($id,2,hash('sha256',$next)),'foreign document denied before execution');
denied(fn()=>(new EInvoiceValidator($db,'member'))->validate($id,2,hash('sha256',$next)),'wrong role denied before execution');
denied(fn()=>EInvoiceValidator::run('<Invoice/>'),'unknown XML cannot launch JVM');
foreach(['output','timeout'] as $guard){
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'kh-kosit-guard-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $code=$guard==='output'?'echo str_repeat("x",2048);':'sleep(5);';
    $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['file',$dir.'/out','w'],2=>['file',$dir.'/err','w']],$pipes);fclose($pipes[0]);
    try{denied(fn()=>EInvoiceValidator::monitorProcess($process,$dir,1,1024),'process '.$guard.' bound terminates without validator result');}
    finally{proc_terminate($process,9);proc_close($process);foreach(scandir($dir) as $name)if(!in_array($name,['.','..'],true))unlink($dir.'/'.$name);rmdir($dir);}
}
$testFile=(string)getenv('KULTURHUS_KOSIT_TEST_XML');
if($testFile&&EInvoiceValidator::configured()){
    $bytes=(string)file_get_contents($testFile);$key=bin2hex(random_bytes(16));
    $good=$documents->upload('synthetic-invoice.xml',$bytes,['title'=>'Synthetic official validator test','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-07','links'=>[]],$key);
    $timeLimit=(int)ini_get('max_execution_time');$result=$service->validate($good,1,hash('sha256',$bytes));
    check((int)ini_get('max_execution_time')===$timeLimit,'native XML validation preserves enclosing workflow time budget');
    check($result['state']==='valid'&&str_contains($result['scenario'],'XRechnung'),'official XRechnung rules accept official synthetic fixture');
    check($service->latest($good)['current']&&$service->latest($good)['state']==='valid','validation survives reload for original');
    $runtimePath=(string)getenv('VEREIN_KOSIT_RUNTIME');$base=dirname(realpath($runtimePath));$portable=json_decode(file_get_contents($runtimePath),true);
    foreach(['java','jar','repository','scenarios','workdir'] as $field){$normalized=str_replace('\\','/',$portable[$field]);$prefix=str_replace('\\','/',$base).'/';if(str_starts_with($normalized,$prefix))$portable[$field]=substr($normalized,strlen($prefix));}
    $relativePath=$base.'/relative-test-'.bin2hex(random_bytes(8)).'.json';file_put_contents($relativePath,json_encode($portable));
    try{putenv('VEREIN_KOSIT_RUNTIME='.$relativePath);check(EInvoiceValidator::run($bytes)['state']==='valid','relative runtime paths validate from the configuration directory');}finally{putenv('VEREIN_KOSIT_RUNTIME='.$runtimePath);unlink($relativePath);}
    $dom=new DOMDocument();$dom->loadXML($bytes,LIBXML_NONET);$xp=new DOMXPath($dom);$xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');$amount=$xp->query('//cbc:PayableAmount')->item(0);if(!$amount)throw new RuntimeException('Expected official UBL fixture');$amount->nodeValue='9999999.99';$bad=$dom->saveXML();
    $documents->newVersion($good,1,'synthetic-invoice.xml',$bad,'Synthetic incorrect payment total');
    check(!$service->latest($good)['current'],'replacement invalidates displayed current validation');
    $result=$service->validate($good,2,hash('sha256',$bad));
    check($result['state']==='invalid','well-formed XML with incorrect invoice total fails real Schematron validation');
    check(count(EInvoiceValidator::messages($result['report']))>0,'official rule errors available as plain text');
    denied(fn()=>EInvoiceValidator::parseReport($result['report'],str_repeat('0',64),1),'report hash mismatch denied');
    denied(fn()=>EInvoiceValidator::parseReport($result['report'],hash('sha256',$bad),0),'exit/report disagreement denied');
    try{$db->exec('UPDATE einvoice_validations SET state=\'valid\'');throw new RuntimeException('history mutable');}catch(PDOException){check(true,'validation history immutable');}
    $cfg=json_decode(file_get_contents(getenv('VEREIN_KOSIT_RUNTIME')),true);$lock=fopen($cfg['workdir'].'/validation.lock','c');flock($lock,LOCK_EX);
    denied(fn()=>EInvoiceValidator::run($bytes),'concurrent validation denied by shared lock');flock($lock,LOCK_UN);fclose($lock);
    $entry=(new \Kulturhus\Verein\Finance($db,'finance'))->createEntry(['kind'=>'expense','description'=>'Synthetic invoice','amount'=>'42.50','due_date'=>'2026-09-30','document_date'=>'2026-09-07','category'=>'culture'],bin2hex(random_bytes(16)));
    $doc=$documents->document($good);$documents->update($good,(int)$doc['version'],['title'=>$doc['title'],'kind'=>'receipt','document_date'=>'2026-09-07','scope'=>'finance','links'=>['entry:'.$entry],'reason'=>'Synthetic export association']);
    $bundle=(new \Kulturhus\Verein\FinanceBundle($db,'finance'))->build([]);$path=tempnam(sys_get_temp_dir(),'kh-einvoice-');file_put_contents($path,$bundle['bytes']);$zip=new ZipArchive();$zip->open($path);
    try{check($zip->getFromName('prueforiginale/'.hash('sha256',$bytes).'.xml')===$bytes,'bundle retains old validated original bytes');check($zip->getFromName('pruefberichte/'.hash('sha256',$result['report']).'.xml')===$result['report'],'bundle retains exact official report');}finally{$zip->close();unlink($path);}
}else echo "SKIP official runtime: set VEREIN_KOSIT_RUNTIME and KULTURHUS_KOSIT_TEST_XML\n";
echo "$checks combined E-invoice checks passed\n";
