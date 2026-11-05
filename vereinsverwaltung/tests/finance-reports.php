<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Accounting/FinanceReports.php';
use Kulturhus\Verein\{Database,Finance,FinanceReports,Settings};

$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Verein A'),('b','Synthetischer Verein B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('finance','a','treasurer',0),('reader','a','member',0),('member','a','member',0),('editor','a','editor',0),('foreign','b','treasurer',0)");
$settings=new Settings($db,'admin');$role=$settings->saveRole('',0,['name'=>'Finanzleser','finance_access'=>'read','members_access'=>'none','settings_access'=>false]);$settings->assignRole('reader',$role,0);
$finance=new Finance($db,'finance');$foreign=new Finance($db,'foreign');$report=new FinanceReports($db,'finance');
$key=fn()=>bin2hex(random_bytes(16));
$bank=$finance->createAccount('Synthetisches Bankkonto','offline','100.00','2023-01-01');$cash=$finance->createAccount('Synthetische Kasse','cash','50.00','2023-01-01');$other=$foreign->createAccount('Fremde Bank','offline','0','2023-01-01');
$entry=function(string $kind,string $amount,string $date,string $description,array $positions=[])use($finance,$key):string{
    $data=['kind'=>$kind,'amount'=>$amount,'document_date'=>$date,'due_date'=>$date,'description'=>$description,'counterparty'=>'Synthetische Person','category'=>$kind==='donation'?'donations':'general'];if($positions)$data['positions']=$positions;return $finance->createEntry($data,$key());
};
$position=fn(string $description,string $amount,string $account='40000')=>['description'=>$description,'amount'=>$amount,'sphere'=>'1','account'=>$account,'tax_rate'=>'0'];
$move=function(string $amount,string $day,string $direction='income',?string $account=null)use($finance,$bank,$key):string{return $finance->createTransaction(['account'=>$account??$bank,'booked_on'=>$day,'direction'=>$direction,'amount'=>$amount,'description'=>'Synthetische Zahlung','counterparty'=>'Synthetischer Zahler'],$key());};
$allocate=function(string $tx,string $id,string $amount)use($finance,$key):void{$finance->allocate($tx,(int)$finance->transactionDetail($tx)['version'],$id,(int)$finance->entry($id)['version'],$amount,$key());};
$sale=$entry('income','30.00','2026-01-01','Zwei Positionen',[$position('Position A','10.01'),$position('Position B','19.99')]);
$donation=$entry('donation','20.00','2026-01-01','Geldspende');
$expense=$entry('expense','9.00','2026-01-01','Ausgabe',[$position('Büromaterial','9.00','68150')]);
$first=$move('100.00','2026-01-10');$allocate($first,$sale,'20.00');$allocate($first,$donation,'20.00');
$second=$move('10.00','2026-01-20');$allocate($second,$sale,'10.00');
$out=$move('9.00','2026-01-25','expense');$allocate($out,$expense,'9.00');
$corrected=$entry('donation','5.00','2026-01-28','Später korrigierte Spende');$finance->recordPayment($corrected,1,$bank,'2026-01-28');$finance->reverse($corrected,2,'2026-02-03','Synthetische Gegenbuchung');
$foreign->createTransaction(['account'=>$other,'booked_on'=>'2026-01-10','direction'=>'income','amount'=>'9999.00','description'=>'Fremder Umsatz'],$key());
$january=['from'=>'2026-01-01','to'=>'2026-01-31'];
$beforeAudit=(int)$db->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();$result=$report->income($january);
check($result['totals']===['income_cents'=>11500,'expense_cents'=>900,'result_cents'=>10600],'cash totals exclude opening balances and foreign data');
check($result['previous_totals']===null&&$result['rows'][0]['previous']===null,'comparison off has no invented baseline');
check(array_sum(array_column($result['breakdowns']['income'],'amount_cents'))===11500&&array_sum(array_column($result['breakdowns']['expense'],'amount_cents'))===900,'breakdowns reconcile exactly to cash totals');
$incomeKinds=array_column($result['breakdowns']['income'],'amount_cents','key');check($incomeKinds===['donation'=>2500,'income'=>3000,'unallocated'=>6000],'partial and shared payments classify only their allocated amounts');
$february=$report->income(['from'=>'2026-02-01','to'=>'2026-02-28']);check($february['totals']===['income_cents'=>-500,'expense_cents'=>0,'result_cents'=>-500],'later reversal corrects original income direction');
check($february['breakdowns']['income'][0]['amount_cents']===-500&&$report->income($january)['totals']['income_cents']===11500,'reversal breakdown remains signed and prior period unchanged');
$empty=$report->income(['from'=>'2026-07-01','to'=>'2026-08-31']);check(count($empty['rows'])===2&&$empty['rows'][0]['key']==='2026-08'&&$empty['rows'][1]['key']==='2026-07'&&$empty['totals']['result_cents']===0,'empty months are complete and descending');
$weekly=$report->income(['from'=>'2026-01-01','to'=>'2026-01-08','resolution'=>'week']);check(count($weekly['rows'])===2&&$weekly['rows'][0]['from']==='2026-01-05'&&$weekly['rows'][1]['from']==='2026-01-01'&&$weekly['rows'][1]['to']==='2026-01-04','week boundaries are Monday-based and clipped to selected dates');
$annual=$report->income(['from'=>'2025-06-01','to'=>'2026-02-28','resolution'=>'year']);check(array_column($annual['rows'],'key')===['2026','2025'],'year resolution produces descending calendar years');
check($report->income($january+['account'=>$cash])['totals']['income_cents']===0,'account filter applies to cashflow without adding opening balances');
check((new FinanceReports($db,'reader'))->income($january)['totals']===$result['totals'],'finance read permission can report');
foreach(['member','editor','missing'] as $actor)denied(fn()=>(new FinanceReports($db,$actor))->income($january),$actor.' cannot read financial report');
denied(fn()=>$report->income($january+['account'=>$other]),'foreign account filter is rejected');
foreach([['from'=>'2026-02-30','to'=>'2026-03-01'],['from'=>'2026-03-01','to'=>'2026-01-01'],['from'=>'1900-01-01','to'=>'1900-01-02'],['from'=>'0001-01-01','to'=>'0001-01-02','compare'=>true],['from'=>'2026-01-01','to'=>'2040-01-01']] as $bad)denied(fn()=>$report->income($bad),'invalid or unbounded date range rejected');
foreach([['resolution'=>'day'],['compare'=>'maybe'],['from'=>[]],['tags'=>['Sommerfest']],['account'=>[]]] as $bad)denied(fn()=>$report->income(array_replace($january,$bad)),'invalid or unsupported filter rejected');
check($report->availableTags()===[],'missing transaction tags remain explicit');
$detail=$report->drilldown($january);check($detail['count']===4&&$detail['totals']===$result['totals']&&array_sum(array_column($detail['rows'],'signed_cents'))===10600,'drilldown uses exactly the cashflow basis');
$journal=$report->journal($january);check($journal['signed_total_cents']===10600&&array_sum(array_column($journal['rows'],'signed_cents'))===10600,'journal reconciles split and unassigned cash movements');
$saleRows=array_values(array_filter($journal['rows'],fn($r)=>$r['entry_id']===$sale));$positions=[];foreach($saleRows as $r)$positions[$r['description']]=($positions[$r['description']]??0)+$r['amount_cents'];ksort($positions);
check($positions===['Position A'=>1001,'Position B'=>1999],'all partial payments add to each position exact gross amount');
$incomeRow=array_values(array_filter($journal['rows'],fn($r)=>$r['entry_id']===$sale))[0];check($incomeRow['debit_account_number']===''&&$incomeRow['debit_account_name']==='Synthetisches Bankkonto'&&$incomeRow['credit_account_number']==='40000','incoming journal shows actual debit bank and credit position without invented bank code');
$expenseRow=array_values(array_filter($journal['rows'],fn($r)=>$r['entry_id']===$expense))[0];check($expenseRow['debit_account_number']==='68150'&&$expenseRow['credit_account_number']===''&&$expenseRow['credit_account_name']==='Synthetisches Bankkonto','expense journal reverses debit and credit sides');
$correctionRows=$report->journal(['from'=>'2026-02-01','to'=>'2026-02-28'])['rows'];check(count($correctionRows)===1&&$correctionRows[0]['signed_cents']===-500&&str_contains($correctionRows[0]['info'],'Gegenbuchung'),'correction journal uses original allocation with opposite signed amount');
check(count(array_filter($journal['rows'],fn($r)=>$r['entry_id']===null&&$r['amount_cents']===6000))===1,'unassigned remainder is a separate journal line');
check((int)$db->query('SELECT COUNT(*) FROM audit_events')->fetchColumn()===$beforeAudit,'reporting makes no audit or business data writes');

// Stable cent attribution requires all payments, including payments outside the requested period.
$tiny=$entry('income','0.02','2026-03-01','Zwei Cent',[$position('Erster Cent','0.01'),$position('Zweiter Cent','0.01')]);
$tiny1=$move('0.01','2026-03-01');$allocate($tiny1,$tiny,'0.01');$tiny2=$move('0.01','2026-03-02');$allocate($tiny2,$tiny,'0.01');
$tinyRows=$report->journal(['from'=>'2026-03-01','to'=>'2026-03-02'])['rows'];check(count($tinyRows)===2&&array_column($tinyRows,'description')===['Zweiter Cent','Erster Cent'],'separate one-cent payments cover separate one-cent positions');
$tinyLater=$report->journal(['from'=>'2026-03-02','to'=>'2026-03-02']);check($tinyLater['rows']===[array_values(array_filter($tinyRows,fn($r)=>$r['payment_date']==='2026-03-02'))[0]],'filtered journal preserves earlier partial-payment allocation');
// This family of weights exhibits the Alabama paradox under largest-remainder apportionment.
$weights=[15,15,9,5,5,2];$weighted=$entry('income','0.51','2026-03-10','Rundungsfolge',array_map(fn($w,$i)=>$position('Gewicht '.$i,Finance::decimal($w)),$weights,array_keys($weights)));
for($i=0;$i<51;++$i){$date=(new DateTimeImmutable('2026-03-10'))->modify('+'.$i.' days')->format('Y-m-d');$tx=$move('0.01',$date);$allocate($tx,$weighted,'0.01');}
$weightedRows=array_values(array_filter($report->journal(['from'=>'2026-03-10','to'=>'2026-04-29'])['rows'],fn($r)=>$r['entry_id']===$weighted));$byPosition=[];foreach($weightedRows as $r)$byPosition[$r['description']]=($byPosition[$r['description']]??0)+$r['amount_cents'];ksort($byPosition);
check(count($weightedRows)===51&&array_values($byPosition)===$weights&&min(array_column($weightedRows,'amount_cents'))===1,'many partial cents remain monotone and fully reconcile every position');

// Large products must not use floating point or overflow 64-bit intermediate multiplication.
$large=$entry('income','1000000000.00','2026-06-01','Großer synthetischer Beleg',[$position('Groß A','333333333.33'),$position('Groß B','333333333.33'),$position('Groß C','333333333.34')]);
$large1=$move('500000000.00','2026-06-01');$allocate($large1,$large,'500000000.00');$large2=$move('500000000.00','2026-06-02');$allocate($large2,$large,'500000000.00');
$largeRows=$report->journal(['from'=>'2026-06-01','to'=>'2026-06-02'])['rows'];$largeTotals=[];foreach($largeRows as $r)$largeTotals[$r['description']]=($largeTotals[$r['description']]??0)+$r['amount_cents'];ksort($largeTotals);
check(array_values($largeTotals)===[33333333333,33333333333,33333333334]&&array_sum(array_column($largeRows,'signed_cents'))===100000000000,'maximum permitted amounts split with exact integer cents');

// Comparison uses the same prior-year dates; leap-day boundaries never double-count a day.
$move('11.00','2025-01-10');$compare=$report->income($january+['compare'=>'1']);check($compare['previous_totals']['income_cents']===1100&&$compare['previous_from']==='2025-01-01'&&$compare['previous_to']==='2025-01-31','prior-year date range and totals are explicit');
$move('1.00','2023-02-28');$move('2.00','2023-03-01');$leap=$report->income(['from'=>'2024-02-26','to'=>'2024-03-04','resolution'=>'week','compare'=>true]);
check($leap['previous_totals']['income_cents']===300&&array_sum(array_map(fn($r)=>$r['previous']['income_cents'],$leap['rows']))===300,'leap-year week comparison partitions the prior-year totals exactly');
$singleLeap=$report->income(['from'=>'2024-02-29','to'=>'2024-02-29','compare'=>true]);check($singleLeap['previous_from']==='2023-02-28'&&$singleLeap['previous_to']==='2023-02-28'&&$singleLeap['previous_totals']['income_cents']===100,'February 29 compares to February 28 without rolling into March');

// Summary aggregation is independent of the 1,000-row entry/transaction browser cap.
$insert=$db->prepare("INSERT INTO finance_transactions(id,club_id,account_id,booked_on,description,cents,source,request_key,fingerprint) VALUES(?,'a',?,'2026-05-01','Synthetischer Massentest',1,'manual',?,'test')");
for($i=0;$i<1001;++$i){$id=$key();$insert->execute([$id,$bank,$id]);}
$many=$report->income(['from'=>'2026-05-01','to'=>'2026-05-01']);check($many['totals']['income_cents']===1001&&$report->drilldown(['from'=>'2026-05-01','to'=>'2026-05-01'])['count']===1001,'all rows above the old browser cap contribute to summaries and drilldown');
$db->exec("UPDATE actors SET active=0 WHERE id='finance'");denied(fn()=>$report->journal($january),'revoked actor cannot reuse a report service');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'synthetic fixture integrity preserved');
echo "$checks finance report checks passed\n";
