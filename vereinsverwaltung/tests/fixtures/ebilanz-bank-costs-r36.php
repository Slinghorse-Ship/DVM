<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceWholeClubReport.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T,BalanceWholeClubReport as W,BalancePeriodFilingValues as P,BalanceClubTaxReport};
/** Actual original synthetic journals, no database or inferred financial data. */
function bankCostsR36(int $year,int $index,string $mode,bool $whole,bool $dated=false):array
{
 $spec=json_decode(file_get_contents(__DIR__.'/../../resources/accounting/reviews/bank-costs-r36.json'),true,64,JSON_THROW_ON_ERROR);
 $edition=array_values(array_filter($spec['years'],static fn($e)=>$e['year']===$year))[0];
 if(!in_array($mode,['normal','opening','cancelled','negative','inactive'],true)||($index!==-1&&!isset($edition['groups'][$index])))throw new InvalidArgumentException('Exact synthetic source required.');
 $groups=$index===-1?$edition['groups']:[$edition['groups'][$index]];
 $chart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000']];
 $opening=[['account'=>'bank','debit_cents'=>10000000,'credit_cents'=>0,'sphere'=>''],['account'=>'capital','debit_cents'=>0,'credit_cents'=>10000000,'sphere'=>'']];$entries=[];
 foreach($groups as $gi=>$g)foreach($g['sources'] as $i=>$source){
  $key='code-'.$source['code'];$chart[$key]=['name'=>'Schreiibfeehler '.$source['code'],'group'=>$g['group'],'skr_code'=>$source['code']];
  $pl=in_array($g['group'],['revenue','expense'],true);$debit=in_array($g['group'],['cash','prepaid','expense'],true);if($mode==='negative')$debit=!$debit;
  if($source['code']==='60750'){
   // A genuine wage grant is credited against actual original wage costs.
   $debit=$mode==='negative';$chart['wages']=['name'=>'Original wages','group'=>'expense','skr_code'=>'60000'];
   if($mode!=='inactive')$entries[]=['id'=>hash('md5','synthetic-r36-original-wages'),'date'=>$year.'-05-31','description'=>'Original synthetic gross wages','lines'=>[['account'=>'wages','debit_cents'=>5000,'credit_cents'=>0,'sphere'=>$whole?(string)(($gi%4)+1):'4'],['account'=>'bank','debit_cents'=>0,'credit_cents'=>5000,'sphere'=>'']]];
  }
  $sphere=$pl?($whole?(string)(($gi%4)+1):'4'):'';$amount=1234+$i;
  $lines=[['account'=>$key,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>$sphere],['account'=>'bank','debit_cents'=>$debit?0:$amount,'credit_cents'=>$debit?$amount:0,'sphere'=>'']];
  if($mode==='opening'&&!$pl){$opening[]=$lines[0];if($debit)$opening[0]['debit_cents']-=$amount;else $opening[0]['debit_cents']+=$amount;}
  elseif($mode!=='inactive'){$entry=['id'=>hash('md5','synthetic-r36-'.$key),'date'=>$year.'-06-30','description'=>'Original synthetic position','lines'=>$lines];$entries[]=$entry;
   if($mode==='cancelled'){$entry['id']=hash('md5','synthetic-r36-reversal-'.$key);$entry['date']=$year.'-12-31';foreach($entry['lines'] as &$l)[$l['debit_cents'],$l['credit_cents']]=[$l['credit_cents'],$l['debit_cents']];unset($l);$entries[]=$entry;}}
 }
 $chart=C::chart($chart);$period=['start'=>$year.'-01-01','end'=>$year.'-12-31'];$calc=C::build($year,$chart,$opening,$entries,$dated?$period:null);
 if($calc['issues'])throw new DomainException(implode(' ',$calc['issues']));
 $r=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$opening,$entries,$dated])),'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>$calc,'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(BalanceClubTaxReport::FIELDS,0),'issues'=>[]]];
 $review=['year'=>$year,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
 if($dated){$r['period']=$period;$r['asset_schedule']=['period'=>$period,'items'=>[],'issues'=>[]];$review['period']=$period;$review['asset_tax_plans']=[];if($whole){$review['capital_movements']=[];return P::whole($r,$review);}return P::build($r,$review);}
 if($whole)$r=W::fromReport($r);return T::build($r,$review);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(bankCostsR36((int)$argv[1],(int)$argv[2],$argv[3],($argv[4]??'')==='whole',($argv[5]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
