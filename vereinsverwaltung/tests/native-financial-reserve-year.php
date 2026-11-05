<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Accounting/FinancialReserveYear.php';

use Kulturhus\Verein\FinancialReserveYear as Y;
use Kulturhus\Verein\FinancialReserveCalculation;

$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function denied(callable $call,string $label):void{try{$call();}catch(DomainException){check(true,$label);return;}throw new RuntimeException('Expected refusal: '.$label);}
function original(int $sale=2024,int $year=2025):array{
    return ['schema'=>1,'year'=>$year,'sale_year'=>$sale,'taxpayer_kind'=>'natural_person_sole_business',
        'held_since'=>'2016-01-01','sold_on'=>$sale.'-04-01','sale_price_cents'=>15000000,
        'sale_cost_cents'=>1000000,'sold_tax_book_cents'=>4000000,'formed_cents'=>8000000,
        'regime'=>'unconfirmed','regime_reviewed'=>false,'nonexempt_share'=>null,
        'deductions'=>[['asset_id'=>'replacement','acquired_on'=>($sale+1).'-02-01','cost_cents'=>15000000,'deduction_cents'=>2000000]],
        'releases'=>[]]+array_fill_keys(Y::FLAGS,true);
}
function tev(array $input):array{return array_replace($input,['regime'=>'teileinkuenfte_40_60','regime_reviewed'=>true,'nonexempt_share'=>['numerator'=>3,'denominator'=>5]]);}

$calendar=Y::calendar(2023,2025);
check($calendar['share_reinvestment_deadline']==='2025-12-31'&&$calendar['building_reinvestment_deadline']==='2027-12-31'&&$calendar['mandatory_release_on']==='2027-12-31','two-year share window and four-year reserve dissolution remain separate');
check($calendar['share_reinvestment_in_year']&&!$calendar['mandatory_release_due']&&$calendar['building_years_remaining']===2,'ending the share window does not force premature total reserve release');
check(!Y::calendar(2023,2026)['share_reinvestment_in_year']&&Y::calendar(2023,2026)['building_reinvestment_in_year'],'later building window is displayed without allowing a new share deduction');
check(Y::calendar(2023,2027)['mandatory_release_due']&&Y::calendar(2023,2027)['building_reinvestment_in_year'],'building deadline year also requires year-end dissolution of unused reserve');
check(!Y::calendar(2023,2028)['building_reinvestment_in_year'],'expired windows remain expired in later overview years');
check(Y::calendar(2023,2027)['original_gain_limit_cents']===50000000&&Y::calendar(2027,2031)['original_gain_limit_cents']===200000000,'future reporting year never upgrades the original gain ceiling');

$p=Y::derive(original());
check($p['original_gain_cents']===10000000&&$p['year_end']['reserve_opening_cents']===8000000&&$p['year_end']['deductions_cents']===2000000&&$p['year_end']['reserve_closing_cents']===6000000,'independent150k less10k less40k original and80k less20k reserve arithmetic');
check($p['basis_deductions']['replacement']['tax_basis_cents']===13000000&&$p['basis_deductions']['replacement']['reserve_deduction_cents']===2000000,'full20k deduction remains linked to150k replacement cost and130k tax basis');
foreach(['tax_treatment_qualified','corporate_successor_qualified','activation_ready','native_xml_qualified'] as $flag)check($p[$flag]===false,'annual arithmetic cannot grant '.$flag);
check($p['year_end']['surcharge_status']==='not_due'&&$p['year_end']['surcharge_exact_cents']===['numerator'=>0,'denominator'=>1],'valid reinvestment carries no invented release surcharge');

$deadline=original(2023,2025);$d=Y::derive($deadline);
check($d['year_end']['reserve_closing_cents']===6000000&&$d['year_end']['mandatory_release_cents']===0,'2023 original with2025 share-window end may retain unused reserve for later building use');
$deadline['year']=2027;$d=Y::derive($deadline);
check($d['year_end']['reserve_opening_cents']===6000000&&$d['year_end']['mandatory_release_cents']===6000000&&$d['year_end']['reserve_closing_cents']===0,'unused2023 reserve is dissolved at the actual fourth-following-year end2027');
check($d['year_end']['nonexempt_release_exact_cents']===null&&$d['year_end']['surcharge_exact_cents']===null&&$d['year_end']['surcharge_status']==='original_nonexempt_share_required','missing original taxable share remains unknown instead of silently assuming60percent or zero');
$d=Y::derive(tev($deadline));
check($d['year_end']['nonexempt_release_exact_cents']===['numerator'=>3600000,'denominator'=>1]&&$d['year_end']['surcharge_exact_cents']===['numerator'=>864000,'denominator'=>1],'explicitTEV:60k gross release times3/5 times6percent times4full years equals8640EUR');
$deadline['year']=2028;$later=Y::derive(tev($deadline));
check($later['year_end']['reserve_opening_cents']===0&&$later['year_end']['mandatory_release_cents']===0&&$later['year_end']['reserve_closing_cents']===0,'a completed dissolution is not posted again in later years');
check($later['basis_deductions']===$d['basis_deductions'],'cost-basis deduction persists after reserve closure and is never restored like impairment');

$partial=original(2024,2026);$partial['deductions'][]=['asset_id'=>'second','acquired_on'=>'2026-12-31','cost_cents'=>6000000,'deduction_cents'=>6000000];$v=Y::derive(tev($partial));
check($v['years'][2025]['reserve_closing_cents']===6000000&&$v['year_end']['reserve_opening_cents']===6000000&&$v['year_end']['reserve_closing_cents']===0,'complete history reconstructs opening reserve and full second-year reinvestment');
check($v['basis_deductions']['second']['tax_basis_cents']===0&&$v['year_end']['surcharge_status']==='not_due','full allowed reserve deduction may reduce exact acquisition basis to zero');
$reverse=$partial;$reverse['deductions']=array_reverse($reverse['deductions']);check(Y::derive(tev($reverse))===$v,'independent event order does not change year ledger or basis marks');
$early=original();$early['releases']=[['year'=>2025,'gross_cents'=>1000000]];$e=Y::derive(tev($early));
check($e['year_end']['reserve_closing_cents']===5000000&&$e['year_end']['surcharge_exact_cents']===['numerator'=>36000,'denominator'=>1],'voluntary year-end release of10k with explicit60percent share adds360EUR for one full year');
$tiny=original(2023,2027);$tiny['sale_price_cents']=1;$tiny['sale_cost_cents']=0;$tiny['sold_tax_book_cents']=0;$tiny['formed_cents']=1;$tiny['deductions']=[];$t=Y::derive(tev($tiny));
check($t['year_end']['nonexempt_release_exact_cents']===['numerator'=>3,'denominator'=>5]&&$t['year_end']['surcharge_exact_cents']===['numerator'=>18,'denominator'=>125],'one-cent release retains exact0.144cent surcharge instead of rounding the60percent basis first');

$unknownEarly=$early;$unknownEarly['regime']='unconfirmed';$unknownEarly['regime_reviewed']=false;$unknownEarly['nonexempt_share']=null;
check(Y::derive($unknownEarly)['year_end']['surcharge_exact_cents']===null,'early voluntary release also requires an explicit original taxable share');
foreach([2022,2028,'2024',2024.0,true,null] as $sale)denied(fn()=>Y::calendar($sale,2025),'unsupported or noninteger original year refused '.get_debug_type($sale));
foreach([2023,2100,'2025',2025.0,true,null] as $year)denied(fn()=>Y::calendar(2024,$year),'invalid reporting year refused '.get_debug_type($year));
$base=original();
foreach(array_keys($base) as $key){$bad=$base;unset($bad[$key]);denied(fn()=>Y::derive($bad),'missing full-history source field refused '.$key);}
foreach(Y::FLAGS as $key)foreach([false,1,'1',null] as $value){$bad=$base;$bad[$key]=$value;denied(fn()=>Y::derive($bad),'source assertion remains strictly true '.$key);}
foreach(['sale_price_cents','sale_cost_cents','sold_tax_book_cents','formed_cents'] as $key)foreach([-1,'100',1.5,true,FinancialReserveCalculation::LIMIT+1] as $value){$bad=$base;$bad[$key]=$value;denied(fn()=>Y::derive($bad),'original cents stay exact bounded integers '.$key);}

$mutations=[
    'corporate-recipient'=>fn($s)=>array_replace($s,['taxpayer_kind'=>'corporation']),
    'fake-regime-without-review'=>fn($s)=>array_replace(tev($s),['regime_reviewed'=>false]),
    'unknown-with-hidden-share'=>fn($s)=>array_replace($s,['nonexempt_share'=>['numerator'=>3,'denominator'=>5]]),
    '95percent-confusion'=>fn($s)=>array_replace(tev($s),['nonexempt_share'=>['numerator'=>19,'denominator'=>20]]),
    'fraction-string'=>fn($s)=>array_replace(tev($s),['nonexempt_share'=>['numerator'=>'3','denominator'=>5]]),
    'extra-fraction-field'=>fn($s)=>array_replace(tev($s),['nonexempt_share'=>['numerator'=>3,'denominator'=>5,'reviewed'=>true]]),
    'wrong-sale-date'=>fn($s)=>array_replace($s,['sold_on'=>'2023-04-01']),
    'invalid-held-date'=>fn($s)=>array_replace($s,['held_since'=>'2016-02-30']),
    'short-holding'=>fn($s)=>array_replace($s,['held_since'=>'2018-04-02']),
    'gain-too-small'=>fn($s)=>array_replace($s,['sale_price_cents'=>14999999,'formed_cents'=>10000000]),
    'extra-period-scope'=>fn($s)=>$s+['period'=>['start'=>'2024-04-01']],
    'unchecked-opening'=>fn($s)=>$s+['available_cents'=>6000000],
];
foreach($mutations as $label=>$change)denied(fn()=>Y::derive($change($base)),'closed original contract rejects '.$label);
foreach(['same-year','too-late','future-event','duplicate-asset','cost-too-small','zero-deduction','missing-date','extra-valuation','non-list'] as $label){
    $bad=$base;
    switch($label){
        case 'same-year':$bad['deductions'][0]['acquired_on']='2024-12-31';break;
        case 'too-late':$bad['year']=2027;$bad['deductions'][0]['acquired_on']='2027-01-01';break;
        case 'future-event':$bad['deductions'][0]['acquired_on']='2026-01-01';break;
        case 'duplicate-asset':$bad['deductions'][]=$bad['deductions'][0];break;
        case 'cost-too-small':$bad['deductions'][0]['cost_cents']=1999999;break;
        case 'zero-deduction':$bad['deductions'][0]['deduction_cents']=0;break;
        case 'missing-date':unset($bad['deductions'][0]['acquired_on']);break;
        case 'extra-valuation':$bad['deductions'][0]['impairment']=true;break;
        case 'non-list':$bad['deductions']=['unlisted'=>$bad['deductions'][0]];break;
    }
    denied(fn()=>Y::derive($bad),'deduction history rejects '.$label);
}
foreach(['before-formation','after-dissolution','future','duplicate-year','excess','string-cents'] as $label){
    $bad=$base;$bad['releases']=[['year'=>2025,'gross_cents'=>1000000]];
    switch($label){
        case 'before-formation':$bad['releases'][0]['year']=2024;break;
        case 'after-dissolution':$bad['year']=2029;$bad['releases'][0]['year']=2029;break;
        case 'future':$bad['releases'][0]['year']=2026;break;
        case 'duplicate-year':$bad['releases'][]=$bad['releases'][0];break;
        case 'excess':$bad['releases'][0]['gross_cents']=6000001;break;
        case 'string-cents':$bad['releases'][0]['gross_cents']='1000000';break;
    }
    denied(fn()=>Y::derive($bad),'voluntary release history rejects '.$label);
}
echo json_encode(['suite'=>'native-financial-reserve-year','checks'=>$checks,'database_writes'=>0,'external_actions'=>0,'activation_ready'=>false,'native_xml_qualified'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
