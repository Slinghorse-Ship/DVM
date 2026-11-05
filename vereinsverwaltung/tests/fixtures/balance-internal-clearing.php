<?php declare(strict_types=1);
require_once __DIR__.'/balance-segments.php';
use Kulturhus\Verein\{BalanceCalculation as ClearingC,BalanceInternalClearing as ClearingW};

function clearingFixture(int $repayment=100):array
{
    [$r,$units,$alloc]=segmentFixture();$r['previous_sha256']=null;
    $v=str_repeat('a',32);$alloc[$v.':1']['parts']=['club'=>1003];
    $instructions=['advances'=>[],'repayments'=>[]];
    foreach(['food'=>334,'shop'=>669] as $to=>$amount){
        $id=substr(hash('sha256','clearing '.$to),0,32);
        $instructions['advances'][$id]=['voucher'=>$v,'from'=>'club','to'=>$to,'cents'=>$amount,'repay_by'=>$to==='food'?'2025-06-30':'2026-06-30','reason'=>'Synthetischer Vorschuss mit Rückzahlungsvereinbarung.'];
        if($to==='food'&&$repayment){
            $pay=str_repeat('b',32);$r['entries'][]=['id'=>$pay,'date'=>'2025-04-01','description'=>'Synthetische interne Rückzahlung','lines'=>[['account'=>'cash','debit_cents'=>$repayment,'credit_cents'=>0,'sphere'=>''],['account'=>'cash','debit_cents'=>0,'credit_cents'=>$repayment,'sphere'=>'']]];
            $alloc[$pay.':0']=['parts'=>['club'=>$repayment],'reason'=>'Zahlungseingang ursprünglicher Bereich'];
            $alloc[$pay.':1']=['parts'=>['food'=>$repayment],'reason'=>'Zahlungsausgang begünstigter Bereich'];
            $instructions['repayments'][str_repeat('c',32)]=['voucher'=>$pay,'advance'=>$id,'cents'=>$repayment,'reason'=>'Synthetischer Rückzahlungsbeleg'];
        }
    }
    $r['calculation']=ClearingC::build(2025,$r['accounts'],$r['opening'],$r['entries']);
    return [$r,$units,$alloc,$instructions];
}
function clearingNext(array $previous,int $amount=0):array
{
    $r=$previous['source'];$r['year']++;$r['previous_sha256']=$r['closed_sha256'];$r['closed_sha256']=hash('sha256','clearing next '.$r['year']);
    $r['opening']=ClearingC::carryForward($r['calculation'],'equity');$r['entries']=[];$alloc=[];$input=['advances'=>[],'repayments'=>[]];
    if($amount){
        $case=substr(hash('sha256','clearing food'),0,32);$voucher=str_repeat('d',32);
        $r['entries'][]=['id'=>$voucher,'date'=>$r['year'].'-02-01','description'=>'Synthetische Rückzahlung im Folgejahr','lines'=>[['account'=>'cash','debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>''],['account'=>'cash','debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>'']]];
        foreach(['club','food'] as $i=>$unit)$alloc[$voucher.':'.$i]=['parts'=>[$unit=>$amount],'reason'=>'Synthetischer Folgejahresbeleg'];
        $input['repayments'][str_repeat('e',32)]=['voucher'=>$voucher,'advance'=>$case,'cents'=>$amount,'reason'=>'Offenen Rest ausgeglichen'];
    }
    $r['calculation']=ClearingC::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);
    return [$r,$previous['units'],$alloc,$input];
}
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    require __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceInternalClearing.php';
    $w=ClearingW::build(str_repeat('1',32),...clearingFixture());
    $n=clearingNext($w,234);
    echo json_encode(['initial'=>$w,'next'=>ClearingW::build(str_repeat('1',32),...array_merge($n,[$w]))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
