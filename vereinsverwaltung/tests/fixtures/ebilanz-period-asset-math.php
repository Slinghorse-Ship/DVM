<?php declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodDisposal.php';
use Kulturhus\Verein\{BalancePeriodDisposal as D,AssetPartialDisposal as P};
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$out=[];
foreach([120001,33333,99999999999] as $basis)foreach(['linear','degressive','gwg','scrap','partial'] as $method)foreach([12,60,120] as $life){
    $plan=['basis_cents'=>$basis,'residual_cents'=>$method==='linear'||$method==='partial'?17:0,'months'=>$life,'acquired_on'=>'2025-09-18','sphere'=>'4','account'=>'06300','reason'=>'Synthetic arithmetic grid','method'=>'linear_monthly'];
    if($method==='degressive'){$plan['method']='degressive';$plan['degressive']=['rate_basis_points'=>min(3000,intdiv(360000,$life)),'movable'=>true,'same_book_tax'=>true,'switch_to_linear'=>true];}
    if($method==='gwg'){$plan['account']='06700';$plan['basis_cents']=95200;$plan['months']=1;$plan['method']='gwg_immediate';$plan['gwg']=['net_cents'=>80000,'vat_cents'=>15200,'deductible_cents'=>0,'independent_movable'=>true,'no_pool'=>true];}
    $proof=null;
    if(in_array($method,['scrap','partial'],true)){
        $proof=['kind'=>$method==='partial'?'partial_scrap':'scrap','date'=>'2026-01-15','reason'=>'Synthetic original event','document_id'=>str_repeat('d',32),'revision'=>1,'sha256'=>hash('sha256','Synthetic original'),'no_proceeds'=>true,'complete'=>$method!=='partial'];
        if($method==='partial')$proof+=['schema'=>1,'plan_sha256'=>P::hash($plan),'allocation'=>['basis'=>'documented_component_cost','cost_cents'=>intdiv($basis,3),'residual_cents'=>3,'document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>hash('sha256','Synthetic component'),'reason'=>'Synthetic original component','same_use_and_life'=>true,'confirmed'=>true]];
    }
    $periods=[['start'=>'2025-07-01','end'=>'2026-06-30'],['start'=>'2026-07-01','end'=>'2026-12-31']];
    for($year=2027;$year<=2037;$year++)$periods[]=['start'=>$year.'-01-01','end'=>$year.'-12-31'];
    $previous=null;
    foreach($periods as $period){
        $schedule=D::calculate($plan,$period,$previous,$proof);
        $row=['plan'=>$plan,'period'=>$period,'previous'=>$previous,'disposal'=>$proof,'schedule'=>$schedule];
        if($method==='partial')$row['tax_schedule']=P::periodValuation($plan,$proof,$period,['months'=>$life===120?60:120,'reason'=>'Synthetic tax life','confirmed'=>true])['tax'];
        $out[]=$row;$previous=$schedule;
    }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
