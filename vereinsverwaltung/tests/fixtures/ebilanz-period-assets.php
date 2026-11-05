<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/balance-period-tax.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalancePeriodFilingValues as F,BalancePeriodTaxValues as T,BalanceCalculation as C,BalancePeriodDisposal as D};

function datedMethodFixture(string $variant,?string $assetCode=null,bool $whole=false,?int $bookMonths=null):array {
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
        $schedule=D::calculate($plan,$period,$prior['asset_schedule']['items']['method']['schedule']??null,null);
        $line=fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
        $opening=$prior?C::carryForward($prior['calculation'],'equity'):[$line('cash',1000000,0),$line('equity',0,1000000)];$entries=[];
        if(!$prior)$entries[]=['id'=>md5('synthetic-acquisition'),'date'=>$plan['acquired_on'],'lines'=>[$line('fixed',$plan['basis_cents'],0),$line('cash',0,$plan['basis_cents'])]];
        if($schedule['depreciation_cents'])$entries[]=['id'=>md5('synthetic-depreciation'.$period['end']),'date'=>$period['end'],'lines'=>[$line('dep',$schedule['depreciation_cents'],0,'4'),$line('fixed',0,$schedule['depreciation_cents'])]];
        $source=['period'=>$period,'year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$period,$plan,$entries])),'previous_sha256'=>$prior['closed_sha256']??null,
            'result_account'=>'equity','accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>C::build(2026,$chart,$opening,$entries,$period),'issues'=>[],
            'asset_schedule'=>['period'=>$period,'items'=>['method'=>['item_id'=>'method','name'=>'Synthetic original asset','posted'=>true,'fixed_account'=>'fixed','plan'=>$plan,'schedule'=>$schedule]],'issues'=>[]]];
        $review=['period'=>$period,'year'=>2026,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
        if($whole)$review['capital_movements']=[];
        $value=$whole?F::whole($source,$review,$previous):F::build($source,$review,$previous);
        if(!str_ends_with($variant,'successor'))return $value;
        $prior=$source;$previous=$value;
    }
    return $value;
}

/** Only deterministic synthetic journal data; no database, certificate or transport. */
function datedAssetFixture(string $variant):array {
    if(in_array($variant,['gwg','degressive','fully','gwg-successor','degressive-successor','fully-successor'],true))return datedMethodFixture($variant);
    if(!in_array($variant,['book','tax','book-successor','tax-successor'],true))throw new InvalidArgumentException('Unknown synthetic variant');
    $income=periodTaxIncomeFixture();$source=$income['scope']['source'];$review=periodTaxReview($income);
    unset($review['income_sha256'],$review['off_balance_adjustments']);
    if(str_starts_with($variant,'tax'))foreach($source['asset_schedule']['items'] as $id=>$item)
        $review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>$id==='software'?'softwaredep':'dep','reason'=>'Synthetic reviewed tax life','confirmed'=>true];
    $first=F::build($source,$review);
    if(!str_ends_with($variant,'successor'))return $first;
    $next=periodTaxIncomeFixture($income)['scope']['source'];$carry=T::carryForward($source,T::build($source,$review),$next);
    $review['period']=$next['period'];$review['journal_sha256']=$next['closed_sha256'];$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
    return F::build($next,$review,$first);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(datedAssetFixture($argv[1]??'book'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
