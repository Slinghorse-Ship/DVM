<?php declare(strict_types=1);
require __DIR__.'/fixtures/cost-assets-setup.php';
use Kulturhus\Verein\{BalancePeriodLedger,BalancePeriodSources,BalancePeriodTaxAssetValues,BalancePeriodTaxValues,BalancePeriodAssetAllocation,BalanceTaxValues};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;function check(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function denied(callable $fn,string $why):void{try{$fn();}catch(DomainException){check(true,$why);return;}throw new RuntimeException($why);}
extract(Kulturhus\Verein\Tests\costSetup());
$land=$make('land','02150','land');$financial=$make('financial','09200','financial');$proofDoc=$document('Synthetic calendar and period valuation');$ref=$evidence->reference($proofDoc);
$event=static fn(string $kind,string $on,int $book,int $tax,array $extra=[])=>['id'=>bin2hex(random_bytes(16)),'on'=>$on,'kind'=>$kind,'book_cents'=>$book,'tax_cents'=>$tax,
 'reason'=>'Synthetic evidenced '.$kind,'legal_basis'=>'Synthetic HGB 253 / EStG 6 decision','document'=>$ref,'confirmed'=>true]+$extra;
$options=['recognised_on'=>'2025-02-15','private_paid_on'=>''];$import('invoice',$land['invoice'],$options);$import('invoice',$financial['invoice'],$options);
$books->recordYear('land',1,2025,['valuation_events'=>json_encode([$event('impairment','2025-07-01',9000,12000,['permanent'=>true,'tax_election'=>false])])]);
$books->recordYear('financial',1,2025,['valuation_events'=>'[]']);$import('depreciation','land:2025');$import('depreciation','financial:2025');
$r=$ledger->report(2025);$ledger->close(2025,$r['version'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic original calendar closing');
$calendar=$ledger->report(2025);$tax=BalanceTaxValues::build($calendar,['year'=>2025,'journal_sha256'=>$calendar['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]]);
$bookCarry=BalancePeriodAssetAllocation::calendar($calendar,null);$taxCarry=BalancePeriodTaxAssetValues::calendarWhole($calendar,$tax,[]);
check($bookCarry['items']['land']['valuation']===$calendar['asset_schedule']['items']['land']['valuation']&&$taxCarry['tax_schedule']['items']['land']['schedule']['book_closing_cents']===12000,'original calendar book and tax valuation retained without fictitious new acquisition');
$bad=$tax;$bad['asset_tax']['tax_schedule']['items']['land']['book_closing_cents']++;
denied(fn()=>BalancePeriodTaxAssetValues::calendarWhole($calendar,$bad,[]),'rehashed calendar tax value cannot replace the original evidenced value');
putenv('VEREIN_BALANCE_PERIODS=1');$period=['start'=>'2026-01-01','end'=>'2026-08-20'];$periodLedger=new BalancePeriodLedger($db,'admin');$periodSources=new BalancePeriodSources($db,'admin');
$pid=$periodLedger->create($period,'Synthetic dated successor to calendar closing',true);
$recovery=[$event('writeup','2026-03-01',11000,12000,['permanent'=>false,'tax_election'=>false])];
foreach(['land'=>['valuations'=>$recovery],'financial'=>[]] as $item=>$options){
    $id=$item.':'.$pid;$p=$periodSources->preview($period,'depreciation',$id,$options);check(!$p['issues'],'calendar acquisition and valuation are available in dated successor '.$item);
    $r=$periodLedger->report($pid);$periodLedger->importSource($pid,$r['version'],'depreciation',$id,$options,$p['hash'],true);
}
$r=$periodLedger->report($pid);check(!$r['issues']&&$r['asset_schedule']['items']['land']['schedule']['book_opening_cents']===9000,'actual calendar predecessor enters dated journal once');
check($r['asset_schedule']['items']['land']['valuation']['source']['previous']===$calendar['asset_schedule']['items']['land']['valuation'],'dated valuation binds to exact retained calendar artifact');
$periodLedger->close($pid,$r['version'],$r['journal_sha256'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Synthetic reviewed calendar-to-period carry');
$r=$periodLedger->report($pid);$carry=BalancePeriodTaxValues::carryForward($calendar,$tax,$r);
$periodTax=BalancePeriodTaxValues::build($r,['period'=>$period,'year'=>2026,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[$carry['entry']],'current_adjustments'=>[],'asset_tax_plans'=>[]],
    ['period'=>['start'=>'2025-01-01','end'=>'2025-12-31'],'asset_tax'=>$taxCarry]);
check($periodTax['result_difference_cents']===-2000&&$periodTax['asset_tax']['tax_schedule']['items']['land']['schedule']['book_opening_cents']===12000,'actual separate tax carry keeps prior difference out of current profit');
Kulturhus\Verein\Tests\costCorruptOriginal($db,$proofDoc,fn()=>check((bool)$periodLedger->report($pid)['issues'],'calendar source originals remain binding after transition'));
echo "$n calendar-to-period cost asset checks passed; synthetic MySQL only\n";
