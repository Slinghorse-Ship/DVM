<?php declare(strict_types=1);
require_once __DIR__.'/free-list-interface-browser-fixture.php';
$cases=[];foreach(['front-nojs','front-js','direct-nojs','direct-js'] as $i=>$case){
    $index=[0,3,4,5][$i];$auto=1000+$i;$db->prepare('UPDATE finance_transactions SET description=? WHERE club_id=? AND id=?')->execute(['CAT-'.sprintf('%05d',$auto),'catalogue-own','bank-'.sprintf('%04d',$auto)]);
    $source=$f->createTransaction(['account'=>$bank,'booked_on'=>'2021-06-01','direction'=>'expense','amount'=>'2.50','description'=>'Tatsächlicher eigener Übertrag '.$case],$key());
    $cases[$case]=['source'=>'bank-'.sprintf('%04d',$index),'receipt'=>'receipt-'.$index,'reference'=>'CAT-'.sprintf('%05d',$index),'auto_source'=>'bank-'.sprintf('%04d',$auto),'auto_receipt'=>'receipt-'.$auto,'reverse_source'=>'bank-'.sprintf('%04d',6+$i),'reverse_receipt'=>'receipt-'.(6+$i),'outgoing'=>$source,'counter'=>'counter-'.sprintf('%04d',$i)];
}
$fixture=ownFreeListInterfaceBrowser($db,['bank'=>$bank,'second'=>$second,'cases'=>$cases]);
try{
    $process=proc_open(['node',dirname(__DIR__).'/bank-catalogue-browser.mjs',$fixture['input']['root'].'/fixture.json'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
    if(!is_resource($process)||proc_close($process)!==0){foreach(['front','direct'] as $app)echo substr((string)file_get_contents($fixture['input']['root'].'/'.$app.'.log'),-2000);throw new RuntimeException('Actual bank catalogue browser failed.');}
    foreach($cases as $case){$check($f->entry($case['auto_receipt'])['payment_state']==='paid'&&$f->transactionDetail($case['auto_source'])['remaining_cents']===0,'real browser automatically matches only the selected filtered page');$check($f->entry($case['receipt'])['payment_state']==='paid'&&$f->transactionDetail($case['source'])['remaining_cents']===0,'real browser assigns the selected original receipt once');$check($f->entry($case['reverse_receipt'])['payment_state']==='paid'&&$f->transactionDetail($case['reverse_source'])['remaining_cents']===0,'real browser assigns from an original receipt using the complete bank search');$check($f->transactionDetail($case['outgoing'])['remaining_cents']===0&&$f->transactionDetail($case['counter'])['remaining_cents']===0,'real browser assigns the oldest own counterpart once');}
}finally{foreach($fixture['servers'] as [$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
