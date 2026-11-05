<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T,BalanceWholeClubReport as W,BalancePeriodFilingValues as P,BalanceClubTaxReport};
function ownSharesR56(int $year,string $variant,bool $dated=false):array
{
 if(!in_array($year,[2025,2026],true)||!in_array($variant,['purchase','opening','release','purchase-below-par','opening-below-par','release-below-par','overpool','no-nominal','wrong-sign'],true))throw new DomainException('Exact synthetic own-share transaction required.');
 $below=str_ends_with($variant,'-below-par');$base=$below?substr($variant,0,-10):$variant;$difference=$below?-1000:1000;
 $line=static fn($a,$d,$c)=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>''];
 $chart=C::chart(['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Original subscribed capital','group'=>'equity','skr_code'=>'29000'],'ordinary-reserve'=>['name'=>'Original free reserve pool','group'=>'reserves','skr_code'=>'29600'],'own-nominal'=>['name'=>'Original nominal own-share deduction','group'=>'equity','skr_code'=>'29090'],'own-cost'=>['name'=>'Original acquisition difference reserve deduction','group'=>'reserves','skr_code'=>'29610']]);
 $opening=[$line('bank',10020000,0),$line('capital',0,10000000),$line('ordinary-reserve',0,20000)];$entries=[];$previous=null;$previousValues=null;
 if($base==='release'){
  if($year!==2026)throw new DomainException('Actual preceding2025 source required.');
  $old=ownSharesR56(2025,$below?'purchase-below-par':'purchase',$dated);$previous=$old['source'];$previousValues=$old['values'];$opening=C::carryForward($previous['calculation'],'capital');
  $entries[]=['id'=>md5('original-own-shares-resale'),'date'=>'2026-06-30','description'=>'Synthetic documented resale at unchanged original acquisition price; no invented current profit','lines'=>[$line('bank',20000+$difference,0),$line('own-nominal',0,20000),$line('own-cost',max(-$difference,0),max($difference,0))]];
 }elseif($base==='opening'){
  $opening[0]['debit_cents']-=20000+$difference;$opening[]=$line('own-nominal',20000,0);$opening[]=$line('own-cost',max($difference,0),max(-$difference,0));
 }else{
  $entries[]=['id'=>md5('original-own-shares-purchase-'.$year),'date'=>$year.'-06-30','description'=>'Synthetic documented nominal20000 and original acquisition difference'.$difference.'; no acquisition fees or inferred profit','lines'=>[$line('own-nominal',20000,0),$line('own-cost',max($difference,0),max(-$difference,0)),$line('bank',0,20000+$difference)]];
 }
 if($variant==='overpool'){$opening[2]['credit_cents']=500;$opening[0]['debit_cents']-=19500;}
 if($variant==='no-nominal'){$entries[0]['lines'][0]['account']='capital';}
 if($variant==='wrong-sign'){$entries[0]['lines'][1]=$line('own-cost',0,21000);$entries[0]['lines'][2]=$line('bank',1000,0);}
 $period=['start'=>$year.'-01-01','end'=>$year.'-12-31'];$calc=C::build($year,$chart,$opening,$entries,$dated?$period:null);if($calc['issues'])throw new DomainException(implode(' ',$calc['issues']));
 $source=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$opening,$entries,$dated],JSON_THROW_ON_ERROR)),'previous_sha256'=>$previous['closed_sha256']??null,'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>$calc,'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(BalanceClubTaxReport::FIELDS,0),'issues'=>[]]];
 $movements=[];foreach($entries as $entry)foreach($entry['lines'] as $ordinal=>$l){if(!in_array($chart[$l['account']]['group'],['equity','reserves'],true))continue;$movements['journal:'.$entry['id'].':'.$ordinal]=['kind'=>$l['debit_cents']>0?'withdrawal':'contribution','reason'=>'Synthetic documented own-share acquisition or resale; actual original capital flow, no invented income'];}
 $review=['year'=>$year,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'capital_movements'=>$movements];
 if($dated){$source['period']=$period;$source['asset_schedule']=['period'=>$period,'items'=>[],'issues'=>[]];$review['period']=$period;$review['asset_tax_plans']=[];$values=P::whole($source,$review,$previousValues);}
 else $values=T::build(W::fromReport($source),$review);
 return ['year'=>$year,'variant'=>$variant,'dated'=>$dated,'values'=>$values,'source'=>$source,'review'=>$review];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(ownSharesR56((int)$argv[1],$argv[2],($argv[3]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
