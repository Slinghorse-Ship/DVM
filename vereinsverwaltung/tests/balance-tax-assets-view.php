<?php declare(strict_types=1);
require __DIR__.'/fixtures/ebilanz-values.php';
use Kulturhus\Verein\BalanceTaxValues;
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function input(array $a,string $k,string $default=''):string{return is_string($a[$k]??null)?$a[$k]:$default;}
function hidden(string $k,mixed $v):string{return '<input type="hidden" name="'.e($k).'" value="'.e($v).'">';}
function assetView(string $state):string{
    [$report,$review]=ebilanzFixture('asset-tax-slower');
    if($state==='scrapped')[$report,$review]=ebilanzFixture('asset-tax-scrap-same');
    if($state==='scrapped-before')[$report,$review]=ebilanzFixture('asset-tax-scrap-after');
    if(str_starts_with($state,'sold'))[$report,$review]=ebilanzFixture('asset-tax-sale-'.match($state){'sold-zero'=>'book-zero','sold-before'=>'after',default=>'cross'});
    if($state==='mixed')[$report,$review]=ebilanzFixture('asset-tax-mixed-cross');
    foreach($review['asset_tax_plans'] as &$plan)$plan['reason']='Synthetic proof <script>bad()</script>';unset($plan);
    if($state==='unsupported')[$report,$review]=ebilanzFixture('asset-gwg');
    if($state==='unchanged')unset($review['asset_tax_plans']);
    $values=BalanceTaxValues::build($report,$review);$b=['review'=>$review];
    if($state==='inherited')$b['carry_forward']['asset_tax_plans']=$review['asset_tax_plans'];
    $editable=$state!=='readonly';$fid='synthetic-filing';$hidden=hidden('csrf','synthetic-csrf').hidden('filing',$fid).hidden('version','1');
    $money=static fn(int $v):string=>number_format($v/100,2,',','.').' €';$_POST=[];
    if($state==='invalid')$_POST=['action'=>'tax-filing-balance-asset','filing'=>$fid,'asset_item'=>'asset-0','tax_years'=>'3','tax_plan_reason'=>'My preserved draft','tax_plan_confirmed'=>'1'];
    ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-assets.php';return ob_get_clean();
}
$n=0;$check=static function(bool $v)use(&$n):void{if(!$v)throw new RuntimeException('Asset view check '.($n+1));$n++;};
foreach(['unchanged','changed','readonly','inherited','invalid','scrapped','scrapped-before','sold','sold-zero','sold-before','mixed'] as $state){
    $html=assetView($state);
    $check(str_contains($html,'Für die Buchhaltung: abweichende Abschreibung'));
    $check(!str_contains($html,'<script>bad()'));
    $check(str_contains($html,'Steuerwerte berechnen')===!in_array($state,['readonly','inherited'],true));
    if($state==='changed'){$check(str_contains($html,'Steuerlich: 2 Jahre'));$check(str_contains($html,'name="remove_confirmed"'));}
    if($state==='invalid'){$check(str_contains($html,'My preserved draft'));$check(str_contains($html,'name="tax_years" type="number" min="1" max="100" step="1" value="3"'));}
    if($state==='inherited')$check(str_contains($html,'Aus dem bestätigten Vorjahr übernommen.'));
    if($state==='scrapped'){$check(str_contains($html,'Ausgemustert am 15.09.2025'));$check(str_contains($html,'Ausgebuchter Restwert im Jahr 2025: 40,00'));$check(str_contains($html,'Steuerlich ausgebuchter Restwert im Jahr 2025: 80,00'));}
    if($state==='scrapped-before')$check(str_contains($html,'Steuerlich ausgebuchter Restwert im Jahr 2026: 0,00'));
    if($state==='mixed'){
        $check(str_contains($html,'Verkauft am')&&str_contains($html,'Ausgemustert am'));
        $assetDom=new DOMDocument();$oldErrors=libxml_use_internal_errors(true);try{$assetDom->loadHTML($html,LIBXML_NONET);$assetXp=new DOMXPath($assetDom);$actualAssetActions=$assetXp->query('//form[.//input[@name="action"][@value="tax-filing-balance-asset"]]//button[normalize-space(.)="Steuerwerte berechnen"]');$check($actualAssetActions->length===2);}finally{libxml_clear_errors();libxml_use_internal_errors($oldErrors);}
        $check(str_contains($html,'name="asset_item" value="sold"')&&str_contains($html,'name="asset_item" value="scrapped"'));
    }
    if(str_starts_with($state,'sold')){
        $check(str_contains($html,'Verkauft am 15.09.2025')&&!str_contains($html,'Ausgemustert am'));
        $check(str_contains($html,'Verkaufsergebnis: Buch')===($state!=='sold-before'));
        if($state==='sold')$check(str_contains($html,'Verkaufsergebnis: Buch 10,00 € · Steuer -30,00 €'));
        if($state==='sold-zero')$check(str_contains($html,'Steuerlich ausgebuchter Restwert im Jahr 2025: 37,50'));
    }
}
$check(assetView('unsupported')==='');
$dir=getenv('VEREIN_TAX_ASSET_UI_OUTPUT');
if($dir){
    $root=realpath(dirname(__DIR__,2).'/.local');$real=realpath($dir);
    if(!$root||!$real||!str_starts_with(str_replace('\\','/',$real),str_replace('\\','/',$root).'/'))throw new RuntimeException('Private existing local output directory required.');
    $css=file_get_contents(__DIR__.'/../public/style.css').file_get_contents(__DIR__.'/../public/tax-filings.css');
    foreach(['unchanged','changed','readonly','inherited','invalid','scrapped','scrapped-before','sold','sold-zero','sold-before','mixed'] as $state)file_put_contents($real.'/'.$state.'.html','<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Erfundene Testdaten</title><style>'.$css.'</style><body><main style="margin:0;padding:16px">'.assetView($state).'</main></body></html>');
}

foreach(['de'=>'Automatisch: ','en'=>'Automatic: ']as$locale=>$prefix){
 $annualTaxMessages=\Kulturhus\Native\Language\BalanceFilingMessages::messages($locale);
 $literal='0 & Übernahme <original> 🙂';$account=['name'=>'Own original cash','skr_code'=>'','amount_cents'=>0];$fieldName='mapping[cash]';$choices=['own-a'=>'Own first','own-b'=>'Own second'];$default=['status'=>'assigned','label'=>$literal];$selected='';$money=static fn(int $value):string=>'0,00 €';
 ob_start();require __DIR__.'/../views/Finance/Balance/balance-tax-account.php';$mappingHtml=ob_get_clean();
 $mappingDom=new DOMDocument();$previousErrors=libxml_use_internal_errors(true);try{
  $mappingDom->loadHTML('<?xml encoding="UTF-8">'.$mappingHtml,LIBXML_NONET);$mappingXp=new DOMXPath($mappingDom);
  $check($mappingXp->query('//select[@name="mapping[cash]"]/option[1]')->item(0)?->textContent===$prefix.$literal);
  $check($mappingXp->query('//original')->length===0&&$mappingXp->query('//select[@name="mapping[cash]"]/option[1]')->item(0)?->getAttribute('value')==='');
 }finally{libxml_clear_errors();libxml_use_internal_errors($previousErrors);}
}
echo "$n tax asset view checks passed; synthetic only\n";
