<?php declare(strict_types=1);
require_once __DIR__.'/ebilanz-advance-clearing-r65.php';require_once __DIR__.'/balance-club-tax.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceCombinedClubValues as V};
function advanceCompositeR65(int $year,int $index,string $mode,bool $dated,?string $legacyCode=null):array {
 $original=advanceR65($year,$index,$mode,true,$dated,true);$source=$original['whole_club_scope']['source'];
 if($legacyCode!==null){if(!in_array($legacyCode,['13700','36300'],true)||$index!==0||!in_array($mode,['debit','credit'],true))throw new DomainException('Exact legacy clearing source required.');$source['accounts']['code-14970']['skr_code']=$legacyCode;$source['accounts']['code-14970']['group']=\Kulturhus\Verein\BalanceDirectionalClosing::CONTRACTS[$legacyCode][0];}
 $source['accounts']['sales']=['name'=>'Original synthetic income from all club areas','group'=>'revenue','skr_code'=>''];$source['accounts']=C::chart($source['accounts']);
 $units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic assets','sphere'=>'2'],'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic taxable food','sphere'=>'4']];$dest=[];
 foreach($units as $key=>$u){$id=md5('synthetic-original-advance-income-'.$year.'-'.$key);$n=101+count($dest)*31;$dest[$id]=$key;$source['entries'][]=['id'=>$id,'date'=>$year.'-11-01','description'=>'Original synthetic documented income','lines'=>[['account'=>'bank','debit_cents'=>$n,'credit_cents'=>0,'sphere'=>''],['account'=>'sales','debit_cents'=>0,'credit_cents'=>$n,'sphere'=>$u['sphere']]]];}
 $source['calculation']=C::build($year,$source['accounts'],$source['opening'],$source['entries'],$dated?$source['period']:null);if($source['calculation']['issues'])throw new DomainException(implode(' ',$source['calculation']['issues']));
 $source['closed_sha256']=hash('sha256',json_encode([$source['accounts'],$source['opening'],$source['entries'],$dated?$source['period']:null],JSON_THROW_ON_ERROR));
 $alloc=[];foreach(S::sources($source) as $key=>$l)$alloc[$key]=['parts'=>[($dest[$l['voucher']]??'food')=>$l['cents']],'reason'=>'Original synthetic complete area allocation'];
 if($dated){
  $allocation=S::build($source,$units,$alloc,[]);$clearing=\Kulturhus\Verein\BalanceInternalClearing::build('synthetic-original-advance',$source,$allocation['units'],$allocation['allocations'],['advances'=>[],'repayments'=>[]]);
  $statement=\Kulturhus\Verein\BalanceClearingStatement::build($source,$allocation,$clearing);$source['assets']=\Kulturhus\Verein\BalancePeriodAssetAllocation::build($source,$allocation['units'],$statement['units'],[]);
  $ref=['revision'=>2,'sha256'=>hash('sha256','Original synthetic complete advance area review')];
  $income=\Kulturhus\Verein\BalanceClubIncomeReconciliation::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-original-advance','source'=>$source,'allocation'=>$allocation,'clearing'=>$clearing,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>null]);
  $part=\Kulturhus\Verein\BalancePeriodClubReport::build($income);
 }else{$state=clubTaxState($source,$units,$alloc);$part=R::fromState($state);}
 $review=['year'=>$year,'journal_sha256'=>$part['closed_sha256'],'complete'=>true,'opening_adjustments'=>$original['opening_adjustments'],'current_adjustments'=>$original['current_adjustments']];
 if($dated){$review['period']=$source['period'];$review['asset_tax_plans']=[];return \Kulturhus\Verein\BalancePeriodCombinedValues::build($income,$review);}
 return V::build($state,['year'=>$year,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'taxable_review'=>$review]);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(advanceCompositeR65((int)$argv[1],(int)$argv[2],$argv[3],($argv[4]??'')==='dated',($argv[5]??'')!==''?$argv[5]:null),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
