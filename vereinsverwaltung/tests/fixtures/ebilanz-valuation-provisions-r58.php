<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/balance-club-tax.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceTaxMapping as M,BalanceCombinedClubValues as V,BalanceWholeClubReport as W,BalanceTaxValues as T};
function valuationProvisionsR58(int $year,string $kind,bool $opening=false,string $bad='',bool $dated=false):array {
 $code=$kind;$provision=in_array($code,['30920','30980'],true);$legacy=$code==='30980';$gain=$code==='48430';$reverse=$bad==='reverse';
 if(!in_array($code,['30920','30980','48430','68830'],true)||($legacy&&($year!==2025||!$opening)))throw new DomainException('Original qualified year and legacy opening required.');
 $chart=C::chart(['bank'=>['name'=>'Synthetic actual cash fund','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic retained capital','group'=>'equity','skr_code'=>'25000'],
 'hb'=>['name'=>'Original synthetic cash-fund valuation or commercial provision','group'=>$provision?'provisions':($gain?'revenue':'expense'),'skr_code'=>$code],
 'hb-result'=>['name'=>'Original synthetic provision profit effect','group'=>'expense','skr_code'=>''],
 'sales'=>['name'=>'Original synthetic exempt and taxable income','group'=>'revenue','skr_code'=>'']]);
 $line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
 $start=[$line('bank',100000,0),$line('capital',0,100000)];$adjust=[];
 if($provision&&$opening){$start[]=$line('hb',0,1000);$start[1]['credit_cents']-=1000;$adjust=[['id'=>'original-commercial-opening','reason'=>'Synthetic separately confirmed elimination of original opening provision','lines'=>[$line('hb',1000,0),$line('capital',0,1000)]]];}
 $amount=$legacy?800:1234;
 if($provision){
  $release=$legacy&&$bad!=='new-addition';$entry=['id'=>md5('synthetic-provision-'.$year.'-'.$kind),'date'=>$year.'-06-30','description'=>$legacy?'Release of independently evidenced pre2010 legacy provision':'Original documented non-hedged imminent loss',
   'lines'=>[$line('hb',$release?$amount:0,$release?0:$amount),$line('hb-result',$release?0:$amount,$release?$amount:0,'4')]];
  $current=[['id'=>'original-provision-current','reason'=>'Synthetic separately confirmed elimination of actual current provision movement','lines'=>[$line('hb',$release?0:$amount,$release?$amount:0),$line('hb-result',$release?$amount:0,$release?0:$amount,'4')]]];
 }else{
  $credit=$gain!==$reverse;$entry=['id'=>md5('synthetic-fund-'.$year.'-'.$kind.'-'.($reverse?'reverse':'valuation')),'date'=>$year.'-06-30','description'=>'Original documented cash-fund valuation; explicit nature classified before XML',
   'lines'=>[$line('hb',$credit?0:$amount,$credit?$amount:0,'4'),$line('bank',$credit?$amount:0,$credit?0:$amount)]];
  $difference=322;$current=[['id'=>'original-fund-tax-value','reason'=>'Synthetic individually reviewed book and tax valuation difference; no rate or deduction inferred','lines'=>[$line('hb',$credit?$difference:0,$credit?0:$difference,'4'),$line('bank',$credit?0:$difference,$credit?$difference:0)]]];
 }
 if($bad==='missing-current')$current=[];
 if($bad==='missing-opening')$adjust=[];
 if($bad==='one-cent'){foreach($current[0]['lines'] as &$l){if($l['debit_cents'])--$l['debit_cents'];if($l['credit_cents'])--$l['credit_cents'];}unset($l);}
 $units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic assets','sphere'=>'2'],'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic taxable food','sphere'=>'4']];$entries=[$entry];$dest=[];
 foreach($units as $unit=>$u){$id=md5('synthetic-hb-income-'.$year.'-'.$unit);$n=101+count($dest)*31;$dest[$id]=$unit;$entries[]=['id'=>$id,'date'=>$year.'-11-01','description'=>'Original synthetic income','lines'=>[$line('bank',$n,0),$line('sales',0,$n,$u['sphere'])]];}
 $source=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$start,$entries],JSON_THROW_ON_ERROR)),'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],
  'accounts'=>$chart,'opening'=>$start,'entries'=>$entries,'calculation'=>C::build($year,$chart,$start,$entries),
  'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(R::FIELDS,0),'issues'=>[]]];
 if($dated){$source['period']=['start'=>$year.'-03-01','end'=>$year.'-12-31'];$source['asset_schedule']=['period'=>$source['period'],'items'=>[],'issues'=>[]];$source['calculation']=C::build($year,$chart,$start,$entries,$source['period']);$source['closed_sha256']=hash('sha256',$source['closed_sha256'].'-original-dated');}
 if($source['calculation']['issues'])throw new DomainException(implode(' ',$source['calculation']['issues']));
 $alloc=[];foreach(S::sources($source) as $key=>$l)$alloc[$key]=['parts'=>[($dest[$l['voucher']]??'food')=>$l['cents']],'reason'=>'Synthetic original allocation'];
 if($dated){
  $a=S::build($source,$units,$alloc,[]);$w=\Kulturhus\Verein\BalanceInternalClearing::build('synthetic-original-hb',$source,$a['units'],$a['allocations'],['advances'=>[],'repayments'=>[]]);
  $statement=\Kulturhus\Verein\BalanceClearingStatement::build($source,$a,$w);$source['assets']=\Kulturhus\Verein\BalancePeriodAssetAllocation::build($source,$a['units'],$statement['units'],[]);
  $ref=['revision'=>2,'sha256'=>hash('sha256','Synthetic original HGB complete area review')];
  $income=\Kulturhus\Verein\BalanceClubIncomeReconciliation::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-original-hb','source'=>$source,'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>null]);
  $part=\Kulturhus\Verein\BalancePeriodClubReport::build($income);
 }else{$state=clubTaxState($source,$units,$alloc);$part=R::fromState($state);}

 $review=['year'=>$year,'journal_sha256'=>$part['closed_sha256'],'complete'=>true,'opening_adjustments'=>$adjust,'current_adjustments'=>$current];
 if($dated){
  $review['period']=$source['period'];$review['asset_tax_plans']=[];$values=\Kulturhus\Verein\BalancePeriodCombinedValues::build($income,$review);
  $single=\Kulturhus\Verein\BalancePeriodFilingValues::whole($source,array_replace($review,['journal_sha256'=>$source['closed_sha256'],'capital_movements'=>[]]));
 }else{
  $values=V::build($state,['year'=>$year,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'taxable_review'=>$review]);
  $single=T::build(W::fromReport($source),array_replace($review,['journal_sha256'=>$source['closed_sha256']]));
 }

 return ['year'=>$year,'kind'=>$kind,'opening'=>$opening,'dated'=>$dated,'values'=>$values,'whole_taxable'=>$single,'source'=>$source];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(valuationProvisionsR58((int)$argv[1],$argv[2],($argv[3]??'')==='opening',$argv[4]??'',($argv[5]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
