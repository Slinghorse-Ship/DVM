<?php declare(strict_types=1);
namespace Kulturhus\Verein {function date(string $format,?int $time=null):string{return \gmdate($format,$time??\strtotime('2029-01-15 UTC'));}}
namespace {
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClearingStatement.php';require_once __DIR__.'/../src/Finance/Balance/BalanceClubTaxReport.php';
require __DIR__.'/fixtures/balance-internal-clearing.php';require __DIR__.'/fixtures/balance-club-tax.php';require __DIR__.'/fixtures/balance-shared-assets.php';
use Kulturhus\Verein\{BalanceClearingStatement as R,BalanceSegmentCalculation as S,BalanceInternalClearing as W,BalanceCalculation as C};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function denied(callable $work,string $why):void{try{$work();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
$club='synthetic';$empty=['advances'=>[],'repayments'=>[]];
for($paid=0;$paid<=334;$paid++){
    [$r,$u,$a,$i]=clearingFixture($paid);$orig=[$r,$u,$a,$i];$allocation=S::build($r,$u,$a);$proof=W::build($club,$r,$u,$a,$i);$statement=R::build($r,$allocation,$proof);
    check($statement['complete']&&!$statement['tax_export_ready'],'fully reconciled internal statement, never a tax approval');
    check($statement['club_calculation']===$r['calculation']&&[$r,$u,$a,$i]===$orig,'original club balance unchanged');
    check($statement['units']['club']['internal']['closing_advance_cents']===1003-$paid,'exact lender outstanding amount');
    check($statement['units']['food']['internal']['closing_owed_cents']===334-$paid,'exact partial repayment');
    foreach($statement['units'] as $unit){
        check(array_keys($unit['accounts'])===array_keys($r['accounts']),'no invented debt or asset account');
        check($unit['totals']['difference_cents']===0&&!$unit['unbalanced'],'unit opening every voucher and closing reconcile');
    }
    [$next,$nu,$na,$ni]=clearingNext($proof,334-$paid);
    $carried=R::carry($r,$allocation,$proof,$next);$nextAllocation=S::build($next,$nu,$carried['allocations']+$na,$carried['capital_carry']??[]);
    $nextProof=W::build($club,$next,$nu,$nextAllocation['allocations'],$ni,$proof);$nextStatement=R::build($next,$nextAllocation,$nextProof);
    check($nextStatement['complete']&&$nextStatement['eliminated_internal_cents']['opening_cents']===1003-$paid&&$nextStatement['eliminated_internal_cents']['closing_cents']===669,'nonempty carry and settlement reconcile');
    foreach($statement['units'] as $id=>$unit)foreach($unit['accounts'] as $key=>$account){
        $expected=$account['closing_cents'];if(in_array($account['group'],['revenue','expense'],true))$expected=0;
        elseif($key==='equity')$expected-=$unit['totals']['annual_result_cents'];
        check($nextStatement['units'][$id]['accounts'][$key]['opening_cents']===$expected,'only original balances and retained result carried');
    }
}
[$r,$u,$a,$i]=clearingFixture();$s=S::build($r,$u,$a);$w=W::build($club,$r,$u,$a,$i);$r0=R::build($r,$s,$w);
$n=clearingNext($w,234);$carried=R::carry($r,$s,$w,$n[0]);
$b=$s;$b['sha256']=str_repeat('0',64);denied(fn()=>R::build($r,$b,$w),'tampered allocation');
$b=$w;$b['totals']['closing_cents']++;denied(fn()=>R::build($r,$s,$b),'tampered proof');
$b=$a;$b['opening:0']['reason']='other source';$different=S::build($r,$u,$b);denied(fn()=>R::build($r,$different,$w),'proof is bound to exact allocation');
$emptyProof=W::build($club,$r,$u,$a,$empty);check(!R::build($r,$s,$emptyProof)['complete'],'unexplained unit differences cannot be concealed by empty instructions');
denied(fn()=>R::carry($r,$s,$emptyProof,$n[0]),'incomplete reconciliation cannot carry');
$b=$a;unset($b['opening:0']);$missing=S::build($r,$u,$b);$missingProof=W::build($club,$r,$u,$b,$i);check(!R::build($r,$missing,$missingProof)['complete'],'missing original opening remains a blocker');
$t=[str_repeat('a',32)=>['account'=>'equity','parts'=>S::transferParts($r,$u,$a,str_repeat('a',32)),'reason'=>'Synthetic permanent funding']];
denied(fn()=>R::build($r,S::build($r,$u,$a,[],$t),$w),'same voucher cannot be both refundable and final');
$b=$n[0];$b['previous_sha256']=str_repeat('0',64);denied(fn()=>R::carry($r,$s,$w,$b),'wrong prior source');
$b=$n[0];$b['accounts']['cash']['skr_code']='18100';$b['calculation']=C::build($b['year'],$b['accounts'],$b['opening'],$b['entries']);denied(fn()=>R::carry($r,$s,$w,$b),'changed account map');
$b=$n[0];$b['opening'][0]['debit_cents']++;$b['opening'][1]['credit_cents']++;$b['calculation']=C::build($b['year'],$b['accounts'],$b['opening'],$b['entries']);denied(fn()=>R::carry($r,$s,$w,$b),'balanced but altered club opening');
// Different signs of retained unit capital must not be lost through club-level netting.
$opposite=$a;$opposite['opening:0']['parts']=['club'=>100000];$opposite['opening:1']['parts']=['club'=>100000];
$op=S::build($r,$u,$opposite);$ow=W::build($club,$r,$u,$opposite,$i);check(R::build($r,$op,$ow)['complete'],'different unit capital contributions reconcile');
$on=clearingNext($ow);$oc=R::carry($r,$op,$ow,$on[0]);check(R::build($on[0],$oc,W::build($club,$on[0],$u,$oc['allocations'],$empty,$ow))['complete'],'unit beginning balance including outstanding advances reconciles');
$loss=$r;$lossAlloc=$a;$foodRevenue=substr(hash('sha256','food'),0,32);
foreach($loss['entries'] as &$entry)if($entry['id']===$foodRevenue)foreach($entry['lines'] as $index=>&$line){$line['debit_cents']=$line['debit_cents']?100:0;$line['credit_cents']=$line['credit_cents']?100:0;$lossAlloc[$foodRevenue.':'.$index]['parts']=['food'=>100];}unset($entry,$line);
foreach(['opening:0','opening:1'] as $key)$lossAlloc[$key]['parts']=array_replace($lossAlloc[$key]['parts'],['club'=>39900,'food'=>100]);
$loss['calculation']=C::build(2025,$loss['accounts'],$loss['opening'],$loss['entries']);$ls=S::build($loss,$u,$lossAlloc);$lw=W::build($club,$loss,$u,$lossAlloc,$i);
$ln=clearingNext($lw);$lc=R::carry($loss,$ls,$lw,$ln[0]);check(($lc['capital_carry']['equity']['food']??null)===134,'opposing retained capital is carried without loss through netting');
check(R::build($ln[0],$lc,W::build($club,$ln[0],$u,$lc['allocations'],$empty,$lw))['complete'],'opposing capital and outstanding advance reconcile together');
// Three closed years retain the original case, without adding it to external liabilities.
$ns=S::build($n[0],$n[1],$carried['allocations']+$n[2],$carried['capital_carry']??[]);$nw=W::build($club,$n[0],$n[1],$ns['allocations'],$n[3],$w);
$third=clearingNext($nw);$ts=R::carry($n[0],$ns,$nw,$third[0]);$tw=W::build($club,$third[0],$third[1],$ts['allocations'],$empty,$nw);
check(R::build($third[0],$ts,$tw)['complete']&&$tw['totals']['closing_cents']===669,'third year retains outstanding original case');
// Assets and permanent funding remain checked independently of temporary cash advances.
foreach(['linear_monthly','gwg_immediate','degressive'] as $method){
    $state=sharedAssetFixture(false,$method);$ar=$state['journal'];$aa=$state['allocation'];$ar['previous_sha256']=null;
    $rows=$aa['allocations'];$rows[str_repeat('a',32).':1']['parts']=['club'=>1003];
    $instructions=$i;$instructions['repayments']=[];
    $as=S::build($ar,$aa['units'],$rows,[],[],$aa['asset_shares']);$aw=W::build($club,$ar,$aa['units'],$rows,$instructions);
    check(R::build($ar,$as,$aw)['complete'],'asset reconciliation retained with advances '.$method);
    $next=sharedAssetNext($state);$next['journal']['previous_sha256']=$ar['closed_sha256'];
    $opening=R::carry($ar,$as,$aw,$next['journal']);$running=array_filter($next['allocation']['allocations'],fn($k)=>!str_starts_with($k,'opening:'),ARRAY_FILTER_USE_KEY);
    $nas=S::build($next['journal'],$aa['units'],$opening['allocations']+$running,$opening['capital_carry']??[],[],$opening['asset_shares']);
    $naw=W::build($club,$next['journal'],$aa['units'],$nas['allocations'],$empty,$aw);
    check(R::build($next['journal'],$nas,$naw)['complete'],'original asset methods and shares carry with advances '.$method);
    $badShares=$aa['asset_shares'];foreach($badShares as &$share)if(count($share['weights'])>1)$share['weights']=['food'=>1,'shop'=>1];unset($share);
    check(!R::build($ar,S::build($ar,$aa['units'],$rows,[],[],$badShares),$aw)['complete'],'settled payments cannot hide mismatched asset use '.$method);
    $funded=sharedAssetFixture(true,$method);$fr=$funded['journal'];$fr['previous_sha256']=null;$fa=$funded['allocation'];$fw=W::build($club,$fr,$fa['units'],$fa['allocations'],$empty);
    check(R::build($fr,$fa,$fw)['complete'],'permanent capital turnover correctly eliminated '.$method);
}
echo "$checks internal statement checks passed; synthetic, no database or transport\n";
}
