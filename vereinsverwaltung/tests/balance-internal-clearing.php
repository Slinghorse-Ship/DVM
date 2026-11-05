<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceInternalClearingExport.php';require __DIR__.'/fixtures/balance-internal-clearing.php';
use Kulturhus\Verein\{BalanceInternalClearing as W,BalanceInternalClearingExport as E,BalanceSegmentCalculation as S,BalanceCalculation as C};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function denied(callable $work,string $label):void{try{$work();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$club=str_repeat('1',32);$food=substr(hash('sha256','clearing food'),0,32);$shop=substr(hash('sha256','clearing shop'),0,32);$pay=str_repeat('c',32);
for($paid=0;$paid<=334;$paid++){
    $args=clearingFixture($paid);$original=$args;$w=W::build($club,...$args);
    check($args===$original,'original source untouched');check(W::rebuild($w)===$w,'deterministic rebuild');
    check($w['totals']===['opening_cents'=>0,'advanced_cents'=>1003,'repaid_cents'=>$paid,'closing_cents'=>1003-$paid],'exact totals for every cent');
    check($w['cases'][$food]['closing_cents']===334-$paid,'partial or complete food repayment');
    check($w['cases'][$food]['status']===($paid===334?'settled':'overdue'),'status at closing date, not current time');
    check($w['cases'][$shop]['closing_cents']===669&&$w['cases'][$shop]['status']==='open','independent other case');
    check($w['tax_export_ready']===false,'not a tax approval');
}
[$r,$units,$alloc,$input]=clearingFixture();$w=W::build($club,$r,$units,$alloc,$input);
$n=clearingNext($w,234);$next=W::build($club,...array_merge($n,[$w]));
check($next['totals']===['opening_cents'=>903,'advanced_cents'=>0,'repaid_cents'=>234,'closing_cents'=>669],'next year exact carry, no new advance');
check($next['cases'][$food]['status']==='settled'&&$next['cases'][$food]['original_cents']===334,'origin survives settlement');
check($next['cases'][$shop]['status']==='overdue','prior future due date now overdue');
check(W::rebuild($next)===$next,'recursive reconstruction');
check($next['cases'][$food]['origin_sha256']===$w['cases'][$food]['origin_sha256'],'original proof retained');
denied(fn()=>W::build($club,...$n),'no invented opening without proof');
denied(fn()=>W::build(str_repeat('2',32),...array_merge($n,[$w])),'no cross-club carry');
$b=$n;$b[0]['previous_sha256']=str_repeat('0',64);denied(fn()=>W::build($club,...array_merge($b,[$w])),'wrong prior journal');
$b=$n;$b[0]['opening'][0]['debit_cents']++;$b[0]['opening'][1]['credit_cents']++;$b[0]['calculation']=C::build(2026,$b[0]['accounts'],$b[0]['opening'],$b[0]['entries']);denied(fn()=>W::build($club,...array_merge($b,[$w])),'changed balanced opening rejected');
$b=$n;$b[1]['food']['sphere']='3';denied(fn()=>W::build($club,...array_merge($b,[$w])),'no silent sphere change');
$b=$n;$b[0]['accounts']['cash']['skr_code']='18100';$b[0]['calculation']=C::build(2026,$b[0]['accounts'],$b[0]['opening'],$b[0]['entries']);denied(fn()=>W::build($club,...array_merge($b,[$w])),'changed account carry');
$b=$n;$b[3]['repayments'][$pay]=$b[3]['repayments'][str_repeat('e',32)];unset($b[3]['repayments'][str_repeat('e',32)]);denied(fn()=>W::build($club,...array_merge($b,[$w])),'historic movement identity cannot be reused');
foreach([true,100.0,'100',0,-1,C::LIMIT+1] as $bad){$b=$input;$b['repayments'][$pay]['cents']=$bad;denied(fn()=>W::build($club,$r,$units,$alloc,$b),'strict cents type and bounds');}
foreach(['',"bad\nline",str_repeat('x',1001),false] as $bad){$b=$input;$b['advances'][$food]['reason']=$bad;denied(fn()=>W::build($club,$r,$units,$alloc,$b),'mandatory valid evidence');}
foreach(['2025-02-30','2025-13-01','2025-01-01','2025-2-01','n/a',false] as $bad){$b=$input;$b['advances'][$food]['repay_by']=$bad;denied(fn()=>W::build($club,$r,$units,$alloc,$b),'valid due date after advance');}
foreach(['unknown','food'] as $bad){$b=$input;$b['advances'][$food]['from']=$bad;denied(fn()=>W::build($club,$r,$units,$alloc,$b),'valid distinct payer');}
foreach([99,101,335] as $bad){$b=$input;$b['repayments'][$pay]['cents']=$bad;denied(fn()=>W::build($club,$r,$units,$alloc,$b),'repayment must match the actual source and outstanding amount');}
$b=$input;unset($b['advances'][$shop]);denied(fn()=>W::build($club,$r,$units,$alloc,$b),'entire original imbalance must be explained');
$b=$input;$b['advances'][$food]['account']='receivables';denied(fn()=>W::build($club,$r,$units,$alloc,$b),'cannot assign invented third-party receivable');
$b=$input;$b['opening_cents']=123;denied(fn()=>W::build($club,$r,$units,$alloc,$b),'cannot supply opening balance');
$b=$input;$b['repayments'][$pay]['advance']=str_repeat('f',32);denied(fn()=>W::build($club,$r,$units,$alloc,$b),'orphan repayment');
$b=$input;$b['repayments'][$pay]['voucher']=str_repeat('a',32);denied(fn()=>W::build($club,$r,$units,$alloc,$b),'advance cannot repay itself');
$b=$alloc;unset($b[str_repeat('a',32).':1']);denied(fn()=>W::build($club,$r,$units,$b,$input),'missing source allocation');
$b=$alloc;$b[str_repeat('b',32).':0']['parts']=['shop'=>100];denied(fn()=>W::build($club,$r,$units,$b,$input),'repayment to a different area');
$b=$r;$b['entries'][6]['date']='2025-02-28';$b['calculation']=C::build(2025,$b['accounts'],$b['opening'],$b['entries']);denied(fn()=>W::build($club,$b,$units,$alloc,$input),'no repayment before advance');
foreach(['closing_cents','opening_cents','advanced_cents','repaid_cents'] as $field){$b=$w;$b['cases'][$food][$field]++;$p=$b;unset($p['sha256']);$b['sha256']=S::hash($p);denied(fn()=>W::rebuild($b),'rehashed forged figures cannot pass source reconstruction');}
$b=$w;$b['sha256']=str_repeat('0',64);denied(fn()=>W::rebuild($b),'changed payload fingerprint');
$b=$w;$b['tax_export_ready']=true;denied(fn()=>W::rebuild($b),'workpaper cannot become approval');
$b=$w;$b['source']['entries'][5]['description']='Changed evidence';$p=$b;unset($p['sha256']);$b['sha256']=S::hash($p);denied(fn()=>W::rebuild($b),'origin digest binds full voucher');
$b=$input;$b['advances']=array_reverse($b['advances'],true);check(W::build($club,$r,$units,$alloc,$b)===$w,'entry order cannot change result');
$b=$r;$b['entries'][6]['lines'][0]=['account'=>'expense','debit_cents'=>100,'credit_cents'=>0,'sphere'=>'1'];$b['calculation']=C::build(2025,$b['accounts'],$b['opening'],$b['entries']);denied(fn()=>W::build($club,$b,$units,$alloc,$input),'expense cannot be claimed as repayment');
$b=$r;$b['entries'][5]['lines'][1]['account']='liabilities';$b['calculation']=C::build(2025,$b['accounts'],$b['opening'],$b['entries']);denied(fn()=>W::build($club,$b,$units,$alloc,$input),'unpaid liability is not a cash advance');
$b=$input;$b['repayments'][str_repeat('f',32)]=['voucher'=>str_repeat('b',32),'advance'=>$food,'cents'=>50,'reason'=>'Zweite Teilzahlung desselben Belegs'];$b['repayments'][$pay]['cents']=50;
$split=W::build($club,$r,$units,$alloc,$b);check($split['totals']===$w['totals']&&count($split['movements'])===4,'several partial movements within one voucher match exactly');
$b['repayments']=array_reverse($b['repayments'],true);check(W::build($club,$r,$units,$alloc,$b)===$split,'stable ordering of same-day repayments');
$args=clearingFixture(0);$args[0]['entries'][5]['lines'][0]=['account'=>'cash','debit_cents'=>1003,'credit_cents'=>0,'sphere'=>''];$args[0]['calculation']=C::build(2025,$args[0]['accounts'],$args[0]['opening'],$args[0]['entries']);
$cash=W::build($club,...$args);check($cash['totals']['closing_cents']===1003,'internal cash advance without expense');
check($cash['source']['calculation']['annual_result_cents']===$args[0]['calculation']['annual_result_cents'],'cash advance does not invent expense or income');
$args=clearingFixture(0);$args[2][str_repeat('a',32).':1']['parts']=['club'=>400,'rent'=>603];
$args[3]['advances'][$shop]['cents']=66;$newId=str_repeat('9',32);
$args[3]['advances'][$newId]=['voucher'=>str_repeat('a',32),'from'=>'rent','to'=>'shop','cents'=>603,'repay_by'=>'2026-06-30','reason'=>'Zweiter zahlender Bereich laut Beleg'];
$many=W::build($club,...$args);check(count($many['cases'])===3&&$many['totals']['closing_cents']===1003,'multiple payers and beneficiaries in one source voucher');
check(W::rebuild($many)===$many,'explicit many-to-many relationships retained');
$bad=$args;$bad[3]['advances'][$newId]['to']='club';denied(fn()=>W::build($club,...$bad),'artificial circular counterflow rejected');
$args=clearingFixture(0);$args[2][str_repeat('a',32).':1']['parts']=['food'=>1003];unset($args[3]['advances'][$food]);$args[3]['advances'][$shop]['from']='food';
$within=W::build($club,...$args);check($within['totals']['closing_cents']===669,'own cost is not a loan when payer shares the invoice');
check($within['cases'][$shop]['from']==='food'&&$within['cases'][$shop]['to']==='shop','two businesses within one sphere supported');
$args=clearingFixture(334);$full=W::build($club,...$args);$carry=clearingNext($full,1);denied(fn()=>W::build($club,...array_merge($carry,[$full])),'settled prior advance cannot be repaid again');
$args=clearingFixture(0);$args[0]['entries'][5]['date']='2025-06-30';$args[0]['calculation']=C::build(2025,$args[0]['accounts'],$args[0]['opening'],$args[0]['entries']);
check(W::build($club,...$args)['cases'][$food]['closing_cents']===334,'same-day due date allowed');
// Generate larger multi-case originals; pairing is an explicit decision, not guessed.
for($i=1;$i<=60;$i++){
    $args=clearingFixture(0);$a=17*$i;$b=31*$i;$total=$a+$b;
    $args[0]['entries'][5]['lines'][0]['debit_cents']=$total;$args[0]['entries'][5]['lines'][1]['credit_cents']=$total;
    $args[2][str_repeat('a',32).':0']['parts']=['food'=>$a,'shop'=>$b];$args[2][str_repeat('a',32).':1']['parts']=['club'=>$total];
    $args[3]['advances'][$food]['cents']=$a;$args[3]['advances'][$shop]['cents']=$b;
    $args[0]['calculation']=C::build(2025,$args[0]['accounts'],$args[0]['opening'],$args[0]['entries']);$result=W::build($club,...$args);
    check($result['totals']['closing_cents']===$total,'many exact multi-recipient combinations');check(W::rebuild($result)===$result,'rebuild arbitrary cent combinations');
}
$args=clearingNext($w);$withoutPayment=W::build($club,...array_merge($args,[$w]));check($withoutPayment['totals']['opening_cents']===903&&$withoutPayment['totals']['closing_cents']===903,'year without movements retains all balances');
$empty=clearingFixture(0);$empty[3]=['advances'=>[],'repayments'=>[]];$emptyProof=W::build($club,...$empty);check($emptyProof['cases']===[]&&$emptyProof['totals']['closing_cents']===0,'empty workpaper is not invented funding');
$en=clearingNext($emptyProof);check(W::build($club,...array_merge($en,[$emptyProof]))['cases']===[],'empty prior proof can be continued');
$files=E::files($next);check(count($files)===4,'four explicit workpapers');
check(json_decode($files['verrechnungen/nachweis.json'],true,512,JSON_THROW_ON_ERROR)===$next,'lossless source and carry proof export');
check(str_contains($files['verrechnungen/LESEN.txt'],'ENTWURF')&&str_contains($files['verrechnungen/LESEN.txt'],'keine Vorstandsfreigabe'),'no false release in workpaper');
function csvRows(string $value):array{$f=fopen('php://temp','r+');fwrite($f,substr($value,3));rewind($f);$out=[];while(($row=fgetcsv($f,0,';','"',''))!==false)$out[]=$row;fclose($f);return $out;}
$csv=csvRows($files['verrechnungen/offene-betraege.csv']);check(count($csv)===3&&array_sum(array_column(array_slice($csv,1),9))===669,'CSV includes settled and open cases with exact total');
$u=$units;$u['club']['name']='=1+2';$b=$input;$b['advances'][$food]['reason']='@SUM(1;2)';$f=E::files(W::build($club,$r,$u,$alloc,$b));
check(str_contains($f['verrechnungen/offene-betraege.csv'],"'=1+2")&&str_contains($f['verrechnungen/offene-betraege.csv'],"'@SUM"),'spreadsheet formulas treated as text');
$b=$w;$b['cases'][$food]['closing_cents']++;denied(fn()=>E::files($b),'export also rejects stale or modified calculation');
echo "$checks internal clearing checks passed; synthetic, no database or transport\n";
