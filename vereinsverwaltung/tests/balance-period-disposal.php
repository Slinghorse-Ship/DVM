<?php declare(strict_types=1);
require __DIR__.'/balance-period.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodDisposal.php';
use Kulturhus\Verein\{BalancePeriodDisposal as D,BalancePeriodAssets,AssetPartialDisposal,AssetDisposal,BalancePeriod};
$base=$n;$n=0;
$plan=['basis_cents'=>120001,'residual_cents'=>0,'acquired_on'=>'2025-09-18','months'=>60,'sphere'=>'1','account'=>'06300','reason'=>'Synthetic invoice','method'=>'linear_monthly'];
$fiscal=['start'=>'2025-07-01','end'=>'2026-06-30'];$next=['start'=>'2026-07-01','end'=>'2026-12-31'];
$proof=['kind'=>'scrap','date'=>'2026-01-15','reason'=>'Synthetic irreparable damage','document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>str_repeat('b',64),'no_proceeds'=>true,'complete'=>true];
$a=D::calculate($plan,$fiscal,null,$proof);
check($a['depreciation_cents']===10000&&$a['book_disposals_cents']===110001,'five months only through disposal month, exact residual cost');
check($a['cost_closing_cents']===0&&$a['accumulated_disposals_cents']===10000&&$a['disposals_cents']===120001,'cost and accumulated depreciation removed separately');
$b=D::calculate($plan,$next,$a,$proof);check($b['depreciation_cents']===0&&$b['disposals_cents']===0&&$b['book_opening_cents']===0,'following short year cannot repeat disposal or depreciation');
denied(fn()=>D::calculate($plan,$next,null,$proof),'historical disposal needs confirmed prior closing');
$bad=$a;$bad['cost_closing_cents']=1;denied(fn()=>D::calculate($plan,$next,$bad,$proof),'one-cent corrupted carry rejected');
denied(fn()=>D::calculate($plan,$fiscal,null,array_replace($proof,['date'=>'2026-07-01'])),'future disposal cannot be counted early');
$partial=array_replace($proof,['schema'=>1,'kind'=>'partial_scrap','complete'=>false,'plan_sha256'=>AssetPartialDisposal::hash($plan),'allocation'=>[
    'basis'=>'documented_component_cost','cost_cents'=>30000,'residual_cents'=>0,'document_id'=>str_repeat('c',32),'revision'=>2,'sha256'=>str_repeat('d',64),
    'reason'=>'Synthetic separately evidenced component','same_use_and_life'=>true,'confirmed'=>true]]);
$a=D::calculate($plan,$fiscal,null,$partial);
check($a['depreciation_cents']===17500&&$a['book_disposals_cents']===27500&&$a['book_closing_cents']===75001,'partial disposal advances remaining original component through actual period end');
check($a['accumulated_disposals_cents']===2500&&$a['cost_closing_cents']===90001,'partial historical cost and accumulated depreciation stay distinct');
$b=D::calculate($plan,$next,$a,$partial);check($b['book_opening_cents']===75001&&$b['depreciation_cents']===9000&&$b['book_closing_cents']===66001&&$b['disposals_cents']===0,'partial next-period carry preserves original cent allocation');
$bad=$partial;$bad['allocation']['cost_cents']=$plan['basis_cents'];denied(fn()=>D::calculate($plan,$fiscal,null,$bad),'whole cost cannot masquerade as partial disposal');
foreach([120001,33333,10001,999] as $cost){
    foreach([0,1,137] as $residual){
        $p=array_replace($plan,['basis_cents'=>$cost,'residual_cents'=>$residual,'acquired_on'=>'2025-01-01']);
        $proofPart=array_replace($partial,['date'=>'2025-05-14','plan_sha256'=>AssetPartialDisposal::hash($p),'allocation'=>array_replace($partial['allocation'],['cost_cents'=>intdiv($cost,3),'residual_cents'=>intdiv($residual,3)])]);
        $old=null;
        foreach([2025,2026,2027,2028,2029,2030] as $year){
            $s=D::calculate($p,BalancePeriod::calendar($year),$old,$proofPart);$legacy=AssetPartialDisposal::preview($p,$proofPart,$year)['book'];
            foreach($legacy as $key=>$value)if($key!=='year')check($s[$key]===$value,'calendar cent compatibility '.$cost.'/'.$residual.'/'.$year.'/'.$key);
            $old=$s;
        }
    }
}
$deg=array_replace($plan,['basis_cents'=>120000,'method'=>'degressive','degressive'=>['rate_basis_points'=>3000,'movable'=>true,'same_book_tax'=>true,'switch_to_linear'=>true]]);
$a=D::calculate($deg,$fiscal,null,$proof);check($a['depreciation_cents']===15000&&$a['book_disposals_cents']===105000,'degressive five-month disposal does not restart on January first');
check(D::calculate($deg,$next,$a,$proof)['book_closing_cents']===0,'disposed degressive asset needs no phantom method-switch state');
$software=array_replace($plan,['account'=>'01350']);$softwareProof=array_replace($proof,['kind'=>'software_end','rights_ended'=>true]);
check(D::calculate($software,$fiscal,null,$softwareProof)['book_disposals_cents']===110001,'documented software rights end follows actual dated period');
denied(fn()=>D::calculate($software,$fiscal,null,array_replace($softwareProof,['rights_ended'=>false])),'mere non-use is not software rights end');
$first=D::calculate($plan,['start'=>'2025-07-01','end'=>'2025-12-31'],null,null);
$event=D::calculate($plan,['start'=>'2026-01-01','end'=>'2026-06-30'],$first,$proof);
check($event['book_opening_cents']===112001&&$event['depreciation_cents']===2000&&$event['book_disposals_cents']===110001,'prior period charge never repeated in disposal period');
$gwg=array_replace($plan,['basis_cents'=>11900,'months'=>1,'account'=>'06700','method'=>'gwg_immediate','gwg'=>['net_cents'=>10000,'vat_cents'=>1900,'deductible_cents'=>0,'independent_movable'=>true,'no_pool'=>true]]);
$g=D::calculate($gwg,$fiscal,null,$proof);check($g['depreciation_cents']===11900&&$g['book_disposals_cents']===0&&$g['disposals_cents']===11900,'fully expensed GWG disposal removes cost without a second expense');
check(D::calculate($gwg,$next,$g,$proof)['depreciation_cents']===0,'disposed GWG does not return in a following period');
$sameMonth=D::calculate($plan,$fiscal,null,array_replace($proof,['date'=>'2025-09-18']));
check($sameMonth['depreciation_cents']===2000&&$sameMonth['book_disposals_cents']===118001,'acquisition-month disposal charges one month only');
$lastMonth=D::calculate($plan,$fiscal,null,array_replace($proof,['date'=>'2026-06-30']));
check($lastMonth['depreciation_cents']===20000&&$lastMonth['book_disposals_cents']===100001,'last-day disposal stays in its actual fiscal period');
echo "$n dated disposal arithmetic checks passed plus $base period checks\n";
