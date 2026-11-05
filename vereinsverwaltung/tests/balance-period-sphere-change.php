<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodSphereChange.php';
use Kulturhus\Verein\{BalancePeriod as P,BalancePeriodSphereChange as S,BalanceSegmentCalculation as H};
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
function change(string $on,string $to,string $case,int $value=0,int $months=0,bool $charitable=false):array{return [
    'on'=>$on,'to_sphere'=>$to,'case'=>$case,'value_cents'=>$value,'remaining_months'=>$months,'residual_cents'=>0,
    'document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>str_repeat('b',64),'reason'=>'Synthetic expert evidence',
    'charitable_use'=>$charitable,'funds_reviewed'=>true,'vat_reviewed'=>true,'tax_reviewed'=>true,'history_reviewed'=>true];}
$plan=['basis_cents'=>120000,'residual_cents'=>0,'months'=>60,'acquired_on'=>'2025-01-18','method'=>'linear_monthly','account'=>'06300','sphere'=>'1'];
$original=$plan;$period=P::calendar(2025);
$enter=change('2025-07-01','4','taxable_value',90000,45);
$r=S::build($plan,$period,[$enter]);
check($r['book']['depreciation_by_sphere']===[1=>12000,2=>0,3=>0,4=>12000],'book depreciation follows actual six-month uses');
check($r['book']['schedule']['book_closing_cents']===96000&&$r['book']['movements'][0]['book_cents']===108000,'book cost and original useful life do not restart');
check($r['tax']['additions_cents']===0&&$r['tax']['contributions_cents']===90000&&$r['tax']['depreciation_cents']===12000&&$r['tax']['closing_cents']===78000,'tax contribution is separately valued without fictitious original purchase');
check($plan===$original&&$r['plan']===$plan,'input plan remains byte-equivalent');
$next=S::build($plan,P::calendar(2026),[],$r);
check($next['changes']===[]&&$next['history']===[$enter]&&$next['previous_sha256']===$r['sha256'],'successor keeps all history but no new transfer');
check($next['tax']['opening_cents']===78000&&$next['tax']['contributions_cents']===0&&$next['tax']['closing_cents']===54000,'successor carries tax clock without repeating contribution');
check($next['book']['opening_sphere']==='4'&&$next['book']['depreciation_by_sphere'][4]===24000,'successor book depreciation follows current rather than original sphere');

$taxable=array_replace($plan,['sphere'=>'4']);
$leave=change('2025-07-01','1','exempt_book',108000,0,true);
$return=change('2025-10-01','4','taxable_return',102000);
$r=S::build($taxable,$period,[$leave,$return]);
check($r['book']['depreciation_by_sphere']===[1=>6000,2=>0,3=>0,4=>18000],'return keeps original monthly book allocations');
check($r['tax']['additions_cents']===120000&&$r['tax']['withdrawals_cents']===108000&&$r['tax']['contributions_cents']===102000&&$r['tax']['valuation_result_cents']===0&&$r['tax']['depreciation_cents']===18000&&$r['tax']['closing_cents']===96000,'charitable book exit and hypothetical continuous return reconcile');
$r=S::build($taxable,$period,[$leave]);
$r2=S::build($taxable,P::calendar(2026),[change('2026-04-01','4','taxable_return',90000)],$r);
check($r2['tax']['opening_cents']===0&&$r2['tax']['contributions_cents']===90000&&$r2['tax']['depreciation_cents']===18000&&$r2['tax']['closing_cents']===72000,'return across financial years includes intervening exempt months');
$r=S::build($taxable,$period,[change('2025-07-01','2','exempt_value',130000)]);
check($r['tax']['valuation_result_cents']===22000&&$r['tax']['withdrawals_cents']===130000&&$r['tax']['closing_cents']===0,'non-book exit separates value gain and withdrawal');
$r=S::build($taxable,$period,[change('2025-07-01','2','exempt_value',100000)]);
check($r['tax']['valuation_result_cents']===-8000&&$r['tax']['closing_cents']===0,'evidenced loss does not become positive income');
$r=S::build($taxable,$period,[change('2025-07-01','2','exempt_value',130000),change('2025-10-01','4','taxable_value',99000,33)]);
check($r['tax']['valuation_result_cents']===22000&&$r['tax']['closing_cents']===90000,'return after valued exit starts evidenced separate remaining tax life');
$r=S::build($plan,$period,[change('2025-07-01','3','exempt_to_exempt')]);
check($r['tax']['contributions_cents']===0&&$r['tax']['depreciation_cents']===0&&$r['book']['depreciation_by_sphere'][3]===12000,'exempt-area change has no invented taxable transfer');
$r=S::build($taxable,$period,[$leave,change('2025-08-01','3','exempt_to_exempt'),$return]);
check($r['tax']['closing_cents']===96000,'intermediate exempt usage retains book-return rule');

// Partition every possible month, including odd cents and a leap-year successor.
foreach([1,17,120001,999999] as $basis){
    $p=array_replace($plan,['basis_cents'=>$basis,'acquired_on'=>'2023-03-19','months'=>36]);
    $span=['start'=>'2023-03-01','end'=>'2024-02-29'];$c=change('2023-09-01','4','taxable_value',max(1,$basis-1),27);
    $full=S::build($p,$span,[$c]);
    for($i=1;$i<12;$i++){
        $boundary=(new DateTimeImmutable($span['start']))->modify('+'.$i.' months')->format('Y-m-d');
        $a=['start'=>$span['start'],'end'=>(new DateTimeImmutable($boundary))->modify('-1 day')->format('Y-m-d')];
        $b=['start'=>$boundary,'end'=>$span['end']];
        $left=S::build($p,$a,$c['on']<$boundary?[$c]:[]);$right=S::build($p,$b,$c['on']>=$boundary?[$c]:[],$left);
        check($left['tax']['depreciation_cents']+$right['tax']['depreciation_cents']===$full['tax']['depreciation_cents']&&$right['tax']['closing_cents']===$full['tax']['closing_cents']
            &&$left['book']['schedule']['depreciation_cents']+$right['book']['schedule']['depreciation_cents']===$full['book']['schedule']['depreciation_cents'],'exact split-month boundary carry '.$basis.' '.$i);
    }
}
$zero=array_replace($taxable,['acquired_on'=>'2020-01-10','months'=>12]);
$r=S::build($zero,$period,[change('2025-07-01','1','exempt_book',0,0,true),change('2025-10-01','4','taxable_return',0)]);
check($r['tax']['closing_cents']===0&&array_sum($r['book']['depreciation_by_sphere'])===0&&count($r['book']['movements'])===2,'fully depreciated asset still records transfers without zero journal entries');
$residual=array_replace($taxable,['residual_cents'=>60000]);
$r=S::build($residual,$period,[change('2025-07-01','1','exempt_book',114000,0,true),change('2025-10-01','4','taxable_return',111000)]);
check($r['book']['schedule']['book_closing_cents']===108000&&$r['tax']['closing_cents']===108000,'residual value retained in book return');

foreach([
    ['on'=>'2025-07-02'],['on'=>'2025-01-01'],['on'=>'2026-01-01'],['to_sphere'=>'1'],['to_sphere'=>4],['case'=>'exempt_book'],
    ['value_cents'=>1.2],['value_cents'=>0],['value_cents'=>100000000001],['remaining_months'=>0],['remaining_months'=>1201],['residual_cents'=>90000],
    ['document_id'=>''],['revision'=>0],['sha256'=>'no'],['reason'=>"bad\nline"],['charitable_use'=>'false'],['funds_reviewed'=>false],['vat_reviewed'=>false],['tax_reviewed'=>false],['history_reviewed'=>false],
] as $i=>$bad)denied(fn()=>S::build($plan,$period,[array_replace($enter,$bad)]),'invalid or incomplete evidence '.$i);
denied(fn()=>S::build($plan,$period,[$enter,$enter]),'same-day duplicate denied');
denied(fn()=>S::build($taxable,$period,[$return,$leave]),'history must be chronological');
denied(fn()=>S::build($taxable,$period,[array_replace($leave,['value_cents'=>108001])]),'one-cent book exit mismatch denied');
denied(fn()=>S::build($taxable,$period,[array_replace($leave,['charitable_use'=>false])]),'book exit requires affirmative charitable-use evidence');
denied(fn()=>S::build($taxable,$period,[$leave,array_replace($return,['remaining_months'=>51])]),'return may not restart tax life');
denied(fn()=>S::build($taxable,$period,[$leave,array_replace($return,['value_cents'=>108000])]),'return may not ignore hypothetical intervening depreciation');
denied(fn()=>S::build($plan,$period,[change('2025-07-01','4','taxable_return',108000)]),'first taxable use is not a book-value return');
foreach([['method'=>'degressive'],['method'=>'gwg_immediate'],['gwg'=>[]],['degressive'=>[]],['account'=>'06700']] as $bad)denied(fn()=>S::build(array_replace($plan,$bad),$period,[$enter]),'unsupported method/asset is not silently converted '.json_encode($bad));
$r=S::build($plan,$period,[$enter]);$bad=$r;$bad['tax']['closing_cents']++;
denied(fn()=>S::build($plan,P::calendar(2026),[],$bad),'tampered predecessor hash denied');
unset($bad['sha256']);$bad['sha256']=H::hash($bad);
denied(fn()=>S::build($plan,P::calendar(2026),[],$bad),'resealed but arithmetically false predecessor denied');
denied(fn()=>S::build(array_replace($plan,['months'=>72]),P::calendar(2026),[],$r),'changed original life cannot enter successor');
denied(fn()=>S::build($plan,['start'=>'2026-02-01','end'=>'2026-12-31'],[],$r),'gap cannot masquerade as prior period');
denied(fn()=>S::build($plan,['start'=>'2025-01-02','end'=>'2025-12-31'],[$enter]),'partial-month treatment is not guessed');
$bad=$r;$bad['changes']=[];unset($bad['sha256']);$bad['sha256']=H::hash($bad);
denied(fn()=>S::build($plan,P::calendar(2026),[],$bad),'resealed history cannot omit current-period movements');
foreach(['period','history','changes','book','tax','sha256'] as $field){$bad=$r;$bad[$field]=null;denied(fn()=>S::build($plan,P::calendar(2026),[],$bad),'malformed predecessor '.$field.' has a domain error');}
echo "$n sphere-transfer arithmetic checks passed; no database or transport\n";
