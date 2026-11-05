<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{AssetBooks,AssetDisposal,AssetSale,BalanceDisposalAccounts};
$checks=0;
function check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function rejected(callable $fn):void{try{$fn();}catch(DomainException){check(true,'rejected');return;}throw new RuntimeException('Invalid software termination accepted');}
$plan=['basis_cents'=>12000,'residual_cents'=>0,'months'=>12,'acquired_on'=>'2025-02-02','method'=>'linear_monthly','account'=>'01350','sphere'=>'4'];
$proof=['kind'=>'software_end','date'=>'2025-09-15','reason'=>'Synthetic termination of all rights','document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>str_repeat('b',64),'no_proceeds'=>true,'complete'=>true,'rights_ended'=>true];
$year=AssetDisposal::schedule($plan,2025,$proof);
check($year['opening_cents']===12000&&$year['depreciation_cents']===8000&&$year['disposal_cents']===4000&&$year['closing_cents']===0,'exact software rest value');
check(AssetDisposal::schedule($plan,2024,$proof)===AssetBooks::schedule($plan,2024),'prior years unchanged');
$after=AssetDisposal::schedule($plan,2026,$proof);unset($after['year']);check(array_sum($after)===0,'no values after termination');
check(BalanceDisposalAccounts::codes($proof)===['68960'=>'expense'],'intangible rest value account');
$item=['disposal'=>$proof,'book_disposals_cents'=>4000];
check(AssetSale::movement($item,'68960',2025)===4000,'software movement');
check(AssetSale::movement($item,'68950',2025)===null,'not equipment');
check(AssetSale::movement($item,'68960',2026)===null,'no later rebooking');
foreach(['rights_ended','no_proceeds','complete'] as $key){
    foreach([false,1,'1',null,[]] as $bad)rejected(fn()=>AssetDisposal::check($plan,array_replace($proof,[$key=>$bad])));
    $missing=$proof;unset($missing[$key]);rejected(fn()=>AssetDisposal::check($plan,$missing));
}
foreach(['01300','01440','06300','06500','06700'] as $code)rejected(fn()=>AssetDisposal::check(array_replace($plan,['account'=>$code]),$proof));
foreach(['degressive','gwg_immediate'] as $method)rejected(fn()=>AssetDisposal::check(array_replace($plan,['method'=>$method]),$proof));
foreach(['kind'=>'scrap','date'=>'2025-02-01','revision'=>true,'compensation_cents'=>1,'complete'=>false,'sale_entry'=>str_repeat('c',32)] as $key=>$value)
    rejected(fn()=>AssetDisposal::check($plan,array_replace($proof,[$key=>$value])));
foreach([2025,2026] as $year){
    $catalog=Kulturhus\Verein\TaxCatalog::accounts($year);
    check(str_contains($catalog['68960']['name'],'immaterielle'),'intangible account in pinned annual catalog');
}
echo "$checks software termination checks passed; no database or transport\n";
