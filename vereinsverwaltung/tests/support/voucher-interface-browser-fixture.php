<?php declare(strict_types=1);
require __DIR__.'/free-list-interface-browser-fixture.php';
$vbFixture=ownFreeListInterfaceBrowser($db,[]);$vbRoot=$vbFixture['input']['root'];
$vbActive=$viReader->cardsPage($viEvents[0],['state'=>'active'],true)['rows'][0];
$vbFixture['input']['data']=['event'=>$viEvents[0],'closed'=>$viEvents[1],'cancelled'=>$viEvents[2],'large'=>$vrLargeEvent,'person'=>$viPerson,'other'=>$viOther,'events'=>30,'history'=>227,'first_reason'=>'Own original reversal reason 0','active'=>['identity'=>$vbActive['ticket_id'],'code'=>$vbActive['code'],'qr'=>\Kulturhus\Verein\EventVoucherQr::image($viEvents[0],$vbActive['code']),'payload'=>\Kulturhus\Verein\EventVoucherQr::payload($viEvents[0],$vbActive['code'])]];
file_put_contents($vbRoot.'/fixture.json',json_encode($vbFixture['input'],JSON_THROW_ON_ERROR));
$vbProtected=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$vbBefore=ownOriginalDatabaseHash($db,$vbProtected);$vbCounts=[];foreach($vbProtected as$vbTable)$vbCounts[$vbTable]=(int)$db->query('SELECT COUNT(*) FROM '.chr(96).$vbTable.chr(96))->fetchColumn();
$vbKeys=[];foreach(['auth','private']as$vbKey)$vbKeys[$vbKey]=hash_file('sha256',$vbRoot.'/'.$vbKey.'.key');
try{$vbProcess=proc_open(['node',dirname(__DIR__).'/voucher-interface-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$vbPipes);fwrite($vbPipes[0],json_encode($vbFixture['input'],JSON_THROW_ON_ERROR));fclose($vbPipes[0]);if(proc_close($vbProcess)!==0)throw new RuntimeException('Actual personal voucher interface failed.');}
finally{foreach($vbFixture['servers']as[$vbProcess,$vbLog]){proc_terminate($vbProcess);proc_close($vbProcess);fclose($vbLog);}}
$check(ownOriginalDatabaseHash($db,$vbProtected)===$vbBefore,'all actual personal voucher pages QR codes searches history and downloads preserve every complete business row and original');
foreach($vbCounts as$vbTable=>$vbCount)$check((int)$db->query('SELECT COUNT(*) FROM '.chr(96).$vbTable.chr(96))->fetchColumn()===$vbCount,'actual personal voucher browsing changes no business table count: '.$vbTable);
foreach($vbKeys as$vbKey=>$vbHash)$check(hash_file('sha256',$vbRoot.'/'.$vbKey.'.key')===$vbHash,'actual personal voucher browsing preserves the exact original private '.$vbKey.' key');
