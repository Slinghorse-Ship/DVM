<?php declare(strict_types=1);
// Original PHP journal and retained plans, entirely synthetic and without a database.
$mode=$argv[1]??'annual';$whole=($argv[2]??'single')==='whole';
if($mode==='annual')require __DIR__.'/ebilanz-values.php';
elseif($mode==='dated')require __DIR__.'/ebilanz-period-assets.php';
else throw new InvalidArgumentException('Unknown synthetic mode');
use Kulturhus\Verein\{BalanceCalculation,BalanceWholeClubReport,BalanceTaxValues,BalanceTaxMapping,BalanceAssetAllocation};
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodWholeFiling.php';
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
function goodwillDatedFixture(string $variant,?string $assetCode=null,bool $whole=false,?int $bookMonths=null):array {
    $kind=explode('-',$variant)[0];$gwg=$kind==='gwg';
    $chart=['cash'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],
        'equity'=>['name'=>'Synthetic club capital','group'=>'equity','skr_code'=>'25000'],
        'fixed'=>['name'=>'Synthetic original asset','group'=>'fixed_assets','skr_code'=>$gwg?'06700':($assetCode??'06300')],
        'dep'=>['name'=>'Synthetic depreciation','group'=>'expense','skr_code'=>$gwg?'62600':(\Kulturhus\Verein\AssetTypes::depreciationCode($assetCode??'06300')??'62200')]];
    $plan=['basis_cents'=>100001,'residual_cents'=>0,'months'=>$kind==='fully'?12:60,'acquired_on'=>'2025-07-01','sphere'=>'4','account'=>$chart['fixed']['skr_code'],'method'=>'linear_monthly','reason'=>'Synthetic method'];
    if($gwg)$plan=array_replace($plan,['basis_cents'=>95200,'months'=>1,'method'=>'gwg_immediate','gwg'=>['net_cents'=>80000,'vat_cents'=>15200,'deductible_cents'=>0,'independent_movable'=>true,'no_pool'=>true]]);
    if($kind==='degressive')$plan+=['degressive'=>['rate_basis_points'=>3000,'movable'=>true,'same_book_tax'=>true,'switch_to_linear'=>true]];
    if($kind==='degressive')$plan['method']='degressive';
    if($bookMonths!==null)$plan['months']=$bookMonths;
    $prior=null;$previous=null;
    foreach([['start'=>'2025-07-01','end'=>'2026-06-30'],['start'=>'2026-07-01','end'=>'2026-12-31']] as $period){
        $schedule=\Kulturhus\Verein\BalancePeriodDisposal::calculate($plan,$period,$prior['asset_schedule']['items']['method']['schedule']??null,null);
        $line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
        $opening=$prior?\Kulturhus\Verein\BalanceCalculation::carryForward($prior['calculation'],'equity'):[$line('cash',1000000,0),$line('equity',0,1000000)];$entries=[];
        if(!$prior)$entries[]=['id'=>md5('synthetic-acquisition'),'date'=>$plan['acquired_on'],'lines'=>[$line('fixed',$plan['basis_cents'],0),$line('cash',0,$plan['basis_cents'])]];
        if($schedule['depreciation_cents'])$entries[]=['id'=>md5('synthetic-depreciation'.$period['end']),'date'=>$period['end'],'lines'=>[$line('dep',$schedule['depreciation_cents'],0,'4'),$line('fixed',0,$schedule['depreciation_cents'])]];
        $source=['period'=>$period,'year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$period,$plan,$entries])),'previous_sha256'=>$prior['closed_sha256']??null,
            'result_account'=>'equity','accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>\Kulturhus\Verein\BalanceCalculation::build(2026,$chart,$opening,$entries,$period),'issues'=>[],
            'asset_schedule'=>['period'=>$period,'items'=>['method'=>['item_id'=>'method','name'=>'Synthetic original asset','posted'=>true,'fixed_account'=>'fixed','plan'=>$plan,'schedule'=>$schedule]],'issues'=>[]]];
        $review=['period'=>$period,'year'=>2026,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
        if($whole)$review['capital_movements']=[];
        if(str_starts_with($variant,'tax'))$review['asset_tax_plans']['method']=['months'=>(($GLOBALS['argv'][3]??'')==='bad-life'?168:180),'depreciation_account'=>'dep','reason'=>'Bestätigter entgeltlich erworbener Firmenwert, EStG § 7 Absatz 1, 15 Jahre.','confirmed'=>true];
        if($previous!==null){
            $pv=$previous;unset($pv['period_source'],$pv['sha256'],$pv['transmission'],$pv['whole_club_scope'],$pv['capital_comparison'],$pv['period_section']);
            $pv+=['sha256'=>\Kulturhus\Verein\BalanceSegmentCalculation::hash($pv),'transmission'=>'unavailable'];
            $carry=\Kulturhus\Verein\BalancePeriodTaxValues::carryForward($prior,$pv,$source);
            $review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
        }
        $value=$whole?\Kulturhus\Verein\BalancePeriodFilingValues::whole($source,$review,$previous):\Kulturhus\Verein\BalancePeriodFilingValues::build($source,$review,$previous);
        if(!str_ends_with($variant,'successor'))return $value;
        $prior=$source;$previous=$value;
    }
    return $value;
}

$out=[];
foreach(['01500'] as $code){
    $variants=$mode==='annual'?['asset-december','asset-prior','asset-cents','asset-tax-faster','asset-tax-carry']:['linear','linear-successor','tax','tax-successor'];
    foreach($variants as $variant){
        if($mode==='dated')$v=goodwillDatedFixture($variant,$code,$whole,str_starts_with($variant,'tax')?120:180);
        else{
            [$r,$review]=ebilanzFixture($variant,$code,str_starts_with($variant,'asset-tax-')?120:180,($argv[3]??'')==='bad-life'?168:180);
            if($whole){
                foreach($r['entries'] as &$entry)$entry['id']=md5('synthetic-linear-r27-'.$entry['id']);unset($entry);
                $r['calculation']=BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);
                $r+=['previous_sha256'=>null,'result_account'=>'capital'];$r=BalanceWholeClubReport::fromReport($r);
            }
            $v=BalanceTaxValues::build($r,$review);
        }
        $schedule=$mode==='dated'?\Kulturhus\Verein\BalancePeriodWholeFiling::mappingSchedule($v):($v['asset_tax']['tax_schedule']??$v['asset_schedule']);
        $preview=BalanceTaxMapping::preview($v['year'],$v['tax']['accounts'],$schedule);$shared=[];
        if($mode==='annual')foreach($v['asset_schedule']['items'] as $id=>$item)$shared[$id]=BalanceAssetAllocation::item($item,['first'=>2,'second'=>3],$v['year']);
        $out[]=['code'=>$code,'mode'=>$mode,'whole'=>$whole,'variant'=>$variant,'values'=>$v,'preview'=>$preview,'shared'=>$shared];
    }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
