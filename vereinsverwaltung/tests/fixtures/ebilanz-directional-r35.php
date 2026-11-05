<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceTaxValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceWholeClubReport.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceTaxValues as T,BalanceWholeClubReport as W,BalancePeriodFilingValues as P,BalanceClubTaxReport};
function directionalR35(int $year,int $index,string $mode,bool $whole,bool $dated=false):array
{
 $spec=json_decode(file_get_contents(__DIR__.'/../../resources/accounting/reviews/directional-r35.json'),true,64,JSON_THROW_ON_ERROR);$edition=array_values(array_filter($spec['years'],static fn($e)=>$e['year']===$year))[0];
 if(!in_array($mode,['debit','credit','opening-debit','opening-credit','cancelled','inactive','tax-flip-debit','tax-flip-credit'],true)||($index!==-1&&!isset($edition['groups'][$index])))throw new InvalidArgumentException('Exact synthetic direction required.');
 $groups=$index===-1?$edition['groups']:[$edition['groups'][$index]];$chart=['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],'expense'=>['name'=>'Synthetic tax difference','group'=>'expense','skr_code'=>'63000']];
 $opening=[['account'=>'bank','debit_cents'=>10000000,'credit_cents'=>0,'sphere'=>''],['account'=>'capital','debit_cents'=>0,'credit_cents'=>10000000,'sphere'=>'']];$entries=[];$adjustments=[];
 foreach($groups as $gi=>$g)foreach($g['sources'] as $i=>$source){
  $key='code-'.$source['code'];$chart[$key]=['name'=>'Schreiibfeehler '.$source['code'],'group'=>$g['group'],'skr_code'=>$source['code']];$amount=1234+$i;$debit=!str_ends_with($mode,'credit');
  $lines=[['account'=>$key,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>''],['account'=>'bank','debit_cents'=>$debit?0:$amount,'credit_cents'=>$debit?$amount:0,'sphere'=>'']];
  if(str_starts_with($mode,'opening-')){$opening[]=$lines[0];$opening[0]['debit_cents']+=$debit?-$amount:$amount;}
  elseif($mode!=='inactive'){$e=['id'=>hash('md5','synthetic-r35-'.$key),'date'=>$year.'-06-30','description'=>'Original synthetic settlement','lines'=>$lines];$entries[]=$e;
   if($mode==='cancelled'){$e['id']=hash('md5','synthetic-r35-reversal-'.$key);$e['date']=$year.'-12-31';foreach($e['lines'] as &$l)[$l['debit_cents'],$l['credit_cents']]=[$l['credit_cents'],$l['debit_cents']];unset($l);$entries[]=$e;}}
  if(str_starts_with($mode,'tax-flip-'))$adjustments[]=['id'=>'tax-'.$key,'reason'=>'Original synthetic confirmed tax claim/debt difference','lines'=>[['account'=>$key,'debit_cents'=>$debit?0:2*$amount,'credit_cents'=>$debit?2*$amount:0,'sphere'=>''],['account'=>'expense','debit_cents'=>$debit?2*$amount:0,'credit_cents'=>$debit?0:2*$amount,'sphere'=>$whole?(string)(($gi%4)+1):'4']]];
 }
 $chart=C::chart($chart);$period=['start'=>$year.'-01-01','end'=>$year.'-12-31'];$calc=C::build($year,$chart,$opening,$entries,$dated?$period:null);if($calc['issues'])throw new DomainException(implode(' ',$calc['issues']));
 $r=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$opening,$entries,$dated])),'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>$calc,'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(BalanceClubTaxReport::FIELDS,0),'issues'=>[]]];
 $review=['year'=>$year,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>$adjustments];
 if($dated){$r['period']=$period;$r['asset_schedule']=['period'=>$period,'items'=>[],'issues'=>[]];$review['period']=$period;$review['asset_tax_plans']=[];if($whole){$review['capital_movements']=[];return P::whole($r,$review);}return P::build($r,$review);}
 if($whole)$r=W::fromReport($r);return T::build($r,$review);
}
function directionalR35Carry(int $index,string $mode):array
{
 $v=directionalR35(2025,$index,$mode,true);$prior=W::rebuild($v['whole_club_scope']);
 $review=['year'=>2025,'journal_sha256'=>$prior['closed_sha256'],'complete'=>true,'opening_adjustments'=>$v['opening_adjustments'],'current_adjustments'=>$v['current_adjustments']];
 $opening=C::carryForward($prior['calculation'],'capital');$chart=$prior['accounts'];
 $r=['year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$prior['closed_sha256'],$opening,2026])),
 'previous_sha256'=>$prior['closed_sha256'],'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>[],
 'calculation'=>C::build(2026,$chart,$opening,[]),'asset_schedule'=>array_replace($prior['asset_schedule'],['year'=>2026])];
 $next=W::fromReport($r);$carry=T::carryForward($prior,$review,$next);
 $review=['year'=>2026,'journal_sha256'=>$next['closed_sha256'],'complete'=>true,'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[],'current_adjustments'=>[]];
 $after=T::build($next,$review);
 foreach($v['tax']['accounts'] as $key=>$row)if(!in_array($row['group'],['expense','revenue'],true)&&$key!=='capital'){
  if($after['tax']['accounts'][$key]['opening_cents']!==$row['closing_cents']||$after['book']['accounts'][$key]['opening_cents']!==$v['book']['accounts'][$key]['closing_cents'])throw new DomainException('Original signed carry changed.');
 }
 if($after['book']['annual_result_cents']!==0||$after['tax']['annual_result_cents']!==0||!$after['capital_comparison']['complete']||$after['tax']['asset_total_cents']!==$v['tax']['asset_total_cents']||$after['book']['asset_total_cents']!==$v['book']['asset_total_cents'])throw new DomainException('Original book/tax carry totals changed.');
 return $after;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(str_starts_with($argv[3],'carry-')?directionalR35Carry((int)$argv[2],substr($argv[3],6)):directionalR35((int)$argv[1],(int)$argv[2],$argv[3],($argv[4]??'')==='whole',($argv[5]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
