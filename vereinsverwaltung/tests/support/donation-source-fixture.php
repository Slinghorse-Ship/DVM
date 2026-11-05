<?php declare(strict_types=1);
ob_start();require __DIR__.'/member-profile-fixture.php';ob_end_clean();
use Kulturhus\Verein\{Finance,Inventory};
$finance=new Finance($db,$actor);$key=static fn()=>bin2hex(random_bytes(16));$account=$finance->createAccount('Synthetic donation bank','offline','0','2026-01-01');
$data=['kind'=>'donation','description'=>'Synthetic Bühnenhilfe','counterparty'=>"Synthetic Donor\nTestweg 7\n01234 Musterort",'category'=>'donations','amount'=>'200','document_date'=>'2026-08-01','due_date'=>'2026-08-01'];
$entry=$finance->createEntry($data,$key());$allocations=[];
foreach([['50','2026-08-03'],['75','2026-08-04']] as [$amount,$day]){$tx=$finance->createTransaction(['account'=>$account,'booked_on'=>$day,'direction'=>'income','amount'=>$amount,'description'=>'Synthetic partial payment '.$key(),'counterparty'=>'Synthetic payer'],$key());$allocations[]=$finance->allocate($tx,$finance->transactionDetail($tx)['version'],$entry,$finance->entry($entry)['version'],$amount,$key());}
$unpaid=$finance->createEntry(array_replace($data,['description'=>'Synthetic unpaid promise']),$key());
$goods=$finance->createGoods(['donor'=>"Synthetic Goods Donor\nTestweg 8\n01234 Musterort",'designation'=>'Synthetic Sachspende','description'=>'Testtisch, zwei Jahre alt, guter Zustand','amount'=>'80','received_on'=>'2026-08-02','valuation'=>'Synthetic comparison','origin'=>'private','evidence_present'=>true],$key());
$inventory=new Inventory($db,$actor);$item=$inventory->saveItem('',0,['name'=>'Synthetic Bühne Tisch','code'=>'SYN-GOODS','source_kind'=>'goods','source_id'=>$goods,'unit'=>'Stück'],$key());
$fixture=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$fixture['donation_sources']=['entry'=>$entry,'allocations'=>$allocations,'goods'=>$goods,'item'=>$item,'unpaid'=>$unpaid];file_put_contents($file,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo $file,"\n";
