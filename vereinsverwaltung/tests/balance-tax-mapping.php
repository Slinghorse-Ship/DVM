<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-values.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxMapping.php';
use Kulturhus\Verein\{BalanceTaxMapping as M,BalanceTaxValues as T,TaxCatalog};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
[$report,$review]=ebilanzFixture('asset-mixed');$values=T::build($report,$review);
$before=serialize($report);$out=M::preview(2025,$report['calculation']['accounts'],$report['asset_schedule']);
check($before===serialize($report),'preview does not mutate retained report');
check($out['review_count']===5,'unnumbered accounts and generic counterparty accounts need explicit review');
check($out['mapping']['equipment-0']==='bs.ass.fixAss.tan.otherEquipm.factory','06300 maps equipment without account class guessing');
check($out['mapping']['software-1']==='bs.ass.fixAss.intan.concessionBrands.software','01350 maps acquired software');
check(str_ends_with($out['mapping']['equipment-depreciation'],'tan.other'),'equipment depreciation requires reconciled assets');
check(str_ends_with($out['mapping']['software-depreciation'],'otherIntan'),'software depreciation requires reconciled assets');
check($values['asset_schedule']===$report['asset_schedule'],'immutable tax-value payload includes retained assets');
check($out['transmission']==='unavailable','mapping preview grants no submission permission');
require_once __DIR__.'/../src/Finance/Balance/BalanceTaxFiling.php';
foreach([2025,2026] as $y){
    foreach(['68000'=>'communication','68050'=>'communication','68100'=>'communication','67000'=>'freight','67100'=>'freight','67400'=>'freight','67600'=>'freight','67700'=>'fees','67800'=>'fees','65200'=>'vehicles','65300'=>'vehicles','65700'=>'vehicles','65800'=>'vehicles','65950'=>'vehicles','68350'=>'leaseMoveable.other','68360'=>'leaseMoveable.other','68790'=>'custodyFee'] as $code=>$suffix){
        $p=M::preview($y,['cost'=>['name'=>'Synthetic expense','group'=>'expense','skr_code'=>(string)$code]]);
        check(($p['mapping']['cost']??null)==='is.netIncome.regular.operatingTC.otherCost.'.$suffix,'exact expense mapping '.$y.' '.$code);
    }
    $p=M::preview($y,['repair'=>['name'=>'Synthetic vehicle repair','group'=>'expense','skr_code'=>'65400']]);
    check(($p['mapping']['repair']??null)==='is.netIncome.regular.operatingTC.otherCost.vehicles','vehicle repair follows verified original source '.$y);
    foreach(['63200','63250'] as $code){
        $p=M::preview($y,['energy'=>['name'=>'Synthetic energy','group'=>'expense','skr_code'=>$code]]);
        check(($p['mapping']['energy']??null)==='is.netIncome.regular.operatingTC.otherCost.energyCost','verified energy source '.$y.' '.$code);
    }
    $p=M::preview($y,['sale'=>['name'=>'Synthetic sale','group'=>'revenue','skr_code'=>'43340']]);
    check(str_ends_with($p['mapping']['sale']??'','reducedRateVAT'),'verified reduced-rate source '.$y);
}
foreach([2025,2026] as $y)foreach(['receivables'=>['12000','bs.ass.currAss.receiv.trade.other'],'liabilities'=>['33000','bs.eqLiab.liab.trade.genOther']] as $group=>[$code,$target]){
    $account=['name'=>'Synthetic ordinary trade','group'=>$group,'skr_code'=>$code];$p=M::preview($y,[$group=>$account]);
    check(!isset($p['mapping'][$group])&&$p['rows'][$group]['status']==='review','generic trade code does not infer counterparty');
    $options=M::choices($y,$account,\Kulturhus\Verein\BalanceTaxFiling::targets());
    check(count($options)===3&&isset($options[$target]),'only explicit trade detail choices offered');
    check(!isset($options['bs.eqLiab.liab.trade'])&&!isset($options['bs.ass.currAss.receiv.trade']),'unusable parent-only mapping removed');
}
foreach([2024,2027] as $year)denied(fn()=>M::preview($year,$report['accounts']),"no silent catalogue fallback for $year");
$bad=$report['calculation']['accounts'];$bad['bank']['group']='revenue';
check(M::preview(2025,$bad)['rows']['bank']['status']==='review','contradictory account group is not automatically assigned');
$bad=$report['calculation']['accounts'];$bad['bank']['skr_code']='99999';
check(M::preview(2025,$bad)['rows']['bank']['status']==='review','unknown custom code remains open');
$outNoAssets=M::preview(2025,$report['calculation']['accounts']);
check($outNoAssets['rows']['equipment-depreciation']['status']==='review','general depreciation code not guessed without asset schedule');
$bad=$report['asset_schedule'];$bad['totals']['depreciation_cents']++;$bad['issues'][]='Synthetic mismatch';
check(M::preview(2025,$report['calculation']['accounts'],$bad)['rows']['software-depreciation']['status']==='review','unreconciled schedule never clears depreciation mapping');
$bad=$report['calculation']['accounts'];$bad['second-depreciation']=$bad['equipment-depreciation'];
check(M::preview(2025,$bad,$report['asset_schedule'])['rows']['equipment-depreciation']['status']==='review','multiple accounts sharing depreciation code require explicit review');
$rules=json_decode(file_get_contents(__DIR__.'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);$ruleCodes=[];
foreach($rules['rules'] as $rule)foreach($rule['codes'] as $code)$ruleCodes[$code]=$rule;
$taxonomy=json_decode(file_get_contents(__DIR__.'/../resources/ebilanz/taxonomy-6.9.json'),true,512,JSON_THROW_ON_ERROR);
$bookable=array_filter(TaxCatalog::accounts(2025),static fn($a)=>!$a['blocked']&&!TaxCatalog::reserved($a['code'],2025)&&$a['category']!=='carry');
check(count(M::chartChoices(2025))===count($bookable),'entire bookable annual catalogue offered without inventing mappings');
check(M::chartChoices(2025)['02350']['group']==='fixed_assets'&&M::chartChoices(2025)['02350']['review_required'],'printed asset position supplies bookkeeping group without granting XML approval');
check(M::preview(2025,['land'=>['name'=>'Synthetic land','skr_code'=>'02350','group'=>'fixed_assets']])['rows']['land']['status']==='review','unconfirmed land cost mapping remains blocked');
M::checkChart(2025,['building'=>['skr_code'=>'03000','group'=>'fixed_assets']]);check(true,'explicit exceptional bookkeeping account allowed');
denied(fn()=>M::checkChart(2025,['carry'=>['skr_code'=>'90000','group'=>'equity']]),'statistical opening-transfer account is not an ordinary balance account');
M::checkChart(2025,['bank'=>['skr_code'=>'18000','group'=>'cash']]);check(true,'reviewed chart accepted');
denied(fn()=>M::checkChart(2025,['bank'=>['skr_code'=>'18000','group'=>'revenue']]),'contradictory setup group blocked');
denied(fn()=>M::checkChart(2025,['bank'=>['skr_code'=>'99999','group'=>'cash']]),'unknown setup code blocked');
denied(fn()=>M::chartChoices(2027),'setup has no silent next-year fallback');
foreach([2025,2026] as $year){
    // A rule may be split differently in each annual catalog. Never overwrite
    // a 2025 rule with its 2026 entry before checking the selected year.
    $ruleCodes=[];
    foreach($rules['rules'] as $rule)if(!isset($rule['years'])||in_array($year,$rule['years'],true))foreach($rule['codes'] as $code)$ruleCodes[$code]=$rule;
    $catalog=TaxCatalog::accounts($year);$chart=[];
    foreach($catalog as $code=>$account)$chart['code-'.$code]=['name'=>$account['name'],'skr_code'=>(string)$code,'group'=>$ruleCodes[$code]['group']??'other_assets'];
    $coverage=M::preview($year,$chart);
    check(count($coverage['rows'])===count($catalog),"all $year catalogue accounts have explicit assigned/review status");
    check(count($coverage['mapping'])===count(array_filter($ruleCodes,static fn($r)=>!isset($r['condition'])&&(!isset($r['years'])||in_array($year,$r['years'],true)))),"only reviewed unconditional codes automatically assigned in $year");
    foreach($coverage['mapping'] as $id=>$target)if(!isset($taxonomy['concepts']['de-gaap-ci'][$target]))throw new RuntimeException('Invented taxonomy target');
    check(true,"every mapped $year target exists in official taxonomy");
}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
require_once __DIR__.'/mysql-bootstrap.php';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();
\Kulturhus\Verein\Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('mapping-club','Synthetic mapping view');INSERT INTO actors(id,club_id,role) VALUES('mapping-reader','mapping-club','treasurer')");
$_SESSION=['actor'=>'mapping-reader'];
$balanceReport=$report;$balanceReport['calculation']['accounts']['bank']['name']='<img src=x onerror=alert(1)>';
ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-mapping.php';$html=ob_get_clean();
check(!str_contains($html,'<img')&&str_contains($html,'&lt;img'),'account labels escaped');
check(!preg_match('/<(form|input|button|script)\b/i',$html),'preview has no financial write, live-send or approval controls');
$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
check($xp->query('//details/summary')->length>=1&&$xp->query('//div[@aria-label="Kontenzuordnung"]//tbody/tr')->length===count($report['calculation']['accounts']),'compact mapping table contains all relevant accounts');
$template=file_get_contents(__DIR__.'/../views/Finance/Balance/balance-ledger.php');
check(str_contains($template,"if(BalanceLedger::enabled()&&in_array(\$balanceReport['state'],['open','closed'],true))require __DIR__.'/balance-tax-mapping.php'"),'mapping preview remains behind the existing balance feature gate');
echo "$n mapping checks passed; synthetic MySQL view and catalogue data, no transport\n";
