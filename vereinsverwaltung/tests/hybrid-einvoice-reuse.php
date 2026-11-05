<?php declare(strict_types=1);
// Reuse guards use the base suite's isolated native MySQL database only.
$reuseRuntime=getenv('VEREIN_HYBRID_RUNTIME');
putenv('VEREIN_HYBRID_RUNTIME');
require __DIR__.'/hybrid-einvoice.php';
if($reuseRuntime!==false)putenv('VEREIN_HYBRID_RUNTIME='.$reuseRuntime);
if(Kulturhus\Verein\HybridEInvoice::configured()){
    $document=$hybrid->saveHybrid($entry,1,$confirmation);
}else{
    // Reuse reads an already stored candidate without invoking a converter.
    // Synthetic validation metadata exercises that path, not PDF/A or XML conformance.
    $storedSource=$hybrid->hybridSource($entry,1);
    $storedXml=Kulturhus\Verein\CiiInvoice::build($storedSource['invoice'],$confirmation,$storedSource['original']['sha256']);
    $requestKey=substr(hash('sha256','hybrid|'.$storedSource['invoice']['id'].'|'.$storedSource['original']['sha256'].'|'.$storedXml),0,32);
    $bytes="%PDF-1.4\nSynthetic stored candidate for reuse guards only\n%%EOF";
    $snapshot=['kind'=>'outgoing_hybrid_einvoice','invoice_id'=>$storedSource['invoice']['id'],'original_document_id'=>$storedSource['invoice']['document_id'],'domestic_confirmation'=>['country'=>'DE','source_hash'=>$storedSource['source_hash']],'validation'=>['state'=>'valid','sha256'=>hash('sha256',$bytes)]];
    $document=(new Kulturhus\Verein\Documents($db,'admin'))->generated('synthetic-reuse.pdf',$bytes,['title'=>'Synthetic reuse fixture','kind'=>'invoice','scope'=>'finance','document_date'=>'2026-09-08','links'=>['entry:'.$entry]],$requestKey,$snapshot);
    echo "Stored synthetic candidate: reuse checks only; actual conformance validation remains skipped\n";
}
check($hybrid->saveHybrid($entry,1,$confirmation)===$document,'source-bound repeat accepted');
check(str_contains($view($entry),'ZUGFeRD-PDF herunterladen'),'source-bound candidate is shown before legacy evidence is removed');
$file=(new Kulturhus\Verein\Documents($db,'admin'))->file($document,1);
$legacy=json_decode($file['snapshot'],true,512,JSON_THROW_ON_ERROR);
unset($legacy['domestic_confirmation']);
// Model a legacy backup missing the new evidence. Normal application writes
// cannot change this snapshot: immutable-version triggers protect it.
$immutableTriggers=[];
foreach($db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='document_files' AND EVENT_MANIPULATION='UPDATE'")->fetchAll(PDO::FETCH_COLUMN) as $name){
    $quoted='`'.str_replace('`','``',$name).'`';
    $immutableTriggers[$quoted]=$db->query('SHOW CREATE TRIGGER '.$quoted)->fetch()['SQL Original Statement'];
}
check($immutableTriggers!==[],'native immutable document-version update guard exists');
$update=$db->prepare('UPDATE document_files SET snapshot=? WHERE document_id=?');
try{
    foreach($immutableTriggers as $name=>$sql)$db->exec('DROP TRIGGER '.$name);
    $update->execute([json_encode($legacy,JSON_THROW_ON_ERROR),$document]);
    denied(fn()=>$hybrid->saveHybrid($entry,1,$confirmation),'legacy candidate without recorded domestic confirmation cannot be reused');
    check(!str_contains($view($entry),'ZUGFeRD-PDF herunterladen'),'legacy candidate with no country proof not shown as download');
}finally{
    $update->execute([$file['snapshot'],$document]);
    foreach($immutableTriggers as $sql)$db->exec($sql);
}
try{$update->execute([$file['snapshot'],$document]);throw new RuntimeException('Restored document guard accepted an update');}catch(PDOException $e){check($e->getCode()==='45000','native immutable document-version guard restored');}
$db->prepare("UPDATE documents SET state='archived' WHERE id=?")->execute([$document]);
denied(fn()=>$hybrid->saveHybrid($entry,1,$confirmation),'archived candidate cannot be reused');
echo "7 hybrid reuse guards passed\n";
