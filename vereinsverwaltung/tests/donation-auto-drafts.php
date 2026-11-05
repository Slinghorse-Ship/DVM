<?php declare(strict_types=1);
require __DIR__.'/donation-forms.php';
use Kulturhus\Verein\{SettingsSchema,Settings,DonationReceipts};
$start=$checks;$db->exec("UPDATE actors SET is_superuser=1 WHERE id='writer'");$testEntry=$finance->createEntry($moneyData,$key());$finance->recordPayment($testEntry,1,$paidAccount,'2026-08-01');
check($forms->prepareForSource('money',$testEntry)['state']==='blocked','no guessed donor from counterparty');
$db->exec("INSERT INTO contacts(id,club_id,kind,name,email,address) VALUES('donor-a','a','person','Alex Actual','synthetic@example.org',CONCAT('Testweg 9',char(10),'00000 Testort')),('other-donor','b','person','Other','other@example.org','Other')");
$finance->assignDonationContact('entry',$testEntry,2,'donor-a');
$tax=array_replace(SettingsSchema::defaults('tax'),['membership_deductible'=>'no']);$taxSettings=new Settings($db,'writer');$taxSettings->save('tax',$tax,0);
$first=$forms->prepareForSource('money',$testEntry);check($first['state']==='ready'&&count($first['documents'])===1,'automatic linked money draft prepared');$autoFile=$docs->file($first['documents'][0]);$auto=json_decode($autoFile['snapshot'],true);
check($auto['donation_form']['donor']==="Alex Actual\nTestweg 9\n00000 Testort"&&$auto['non_deductible_memberships']===true,'real donor and money-only exclusion clause');
check($auto['kind']==='donation_form_draft'&&$auto['donation_form']['signature_place_date']==='Musterort, '.date('d.m.Y'),'draft has place/date, no issued signature');
check($forms->prepareForSource('money',$testEntry)['documents']===$first['documents'],'repeat returns same draft, not another PDF');
denied(fn()=>$readForms->prepareForSource('money',$testEntry),'read role cannot auto-generate');
denied(fn()=>$forms->prepareForSource('money',$foreignEntry),'cross tenant auto generation denied');
$typed=$forms->defaults('money',$testEntry);$typed['donor']="Wrong\nStreet\n00000 City";denied(fn()=>$forms->build($typed),'linked donor cannot be replaced in draft');
$db->exec("UPDATE contacts SET address=CONCAT('New Road 1',char(10),'00000 Testort') WHERE id='donor-a'");$second=$forms->prepareForSource('money',$testEntry);check($second['documents']!==$first['documents'],'changed donor address produces distinct current draft');check($docs->file($first['documents'][0])['sha256']===$autoFile['sha256'],'historical original draft remains immutable');
$testGoods=$finance->createGoods($goodsData,$key());$finance->assignDonationContact('goods',$testGoods,1,'donor-a');$gd=$forms->prepareForSource('goods',$testGoods);$gf=$docs->file($gd['documents'][0]);$gs=json_decode($gf['snapshot'],true);check($gs['donation_form']['donor']==="Alex Actual\nNew Road 1\n00000 Testort"&&!$gs['non_deductible_memberships'],'goods draft uses same donor, no invented money checkbox');
check((int)$db->query('SELECT COUNT(*) FROM donation_receipts')->fetchColumn()===0,'auto generation never issues a certificate');
$tax['notice_type']='corporate';$taxSettings->save('tax',$tax,1);check($forms->defaults('goods',$testGoods)['notice_type']==='corporate','corporate notice supported as separate setting');
check(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'auto drafts preserve foreign keys');
$partial=$finance->createEntry(array_replace($moneyData,['amount'=>'200,00','counterparty'=>'Distinct synthetic payer']),$key());$finance->assignDonationContact('entry',$partial,1,'donor-a');
$partialDocs=[];
foreach(['2026-08-03','2026-08-04'] as $day){$tx=$finance->createTransaction(['account'=>$paidAccount,'booked_on'=>$day,'direction'=>'income','amount'=>'50,00','description'=>'No matching reference','counterparty'=>'Unmatched synthetic'], $key());$finance->allocate($tx,(int)$finance->transactionDetail($tx)['version'],$partial,(int)$finance->entry($partial)['version'],'50,00',$key());$result=$forms->prepareForSource('money',$partial);$partialDocs[]=$result['documents'];}
check(count($partialDocs[0])===1&&count($partialDocs[1])===2&&in_array($partialDocs[0][0],$partialDocs[1],true),'separate payment days produce one draft each, earlier payment not duplicated');
foreach($partialDocs[1] as $id){$ps=json_decode($docs->file($id)['snapshot'],true);check($ps['donation_form']['amount']==='50,00','partial draft covers only actual payment');}
file_put_contents(dirname(__DIR__,2).'/.local/donation-auto-money.pdf',$autoFile['bytes']);file_put_contents(dirname(__DIR__,2).'/.local/donation-auto-goods.pdf',$gf['bytes']);
echo ($checks-$start)." automatic donation draft checks passed\n";
