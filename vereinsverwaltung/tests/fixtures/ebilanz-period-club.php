<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodClubFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as PC,BalanceSegmentCalculation as PS,BalancePeriodDisposal as PD,BalanceAssetAllocation as PA,
    BalanceClearingStatement as PStatement,BalanceInternalClearing as PW,BalancePeriodAssetAllocation as PAssets,
    BalanceClubIncomeReconciliation as PI,BalancePeriodClubReport as PR,BalancePeriodClubFilingValues as PV,BalancePeriodTaxValues as PT};

function periodClubIncome(?array $previous=null,bool $reversals=false):array
{
    $period=$previous?['start'=>'2026-07-01','end'=>'2026-12-31']:['start'=>'2025-07-01','end'=>'2026-06-30'];
    $units=['club'=>['name'=>'Synthetic exempt activity','sphere'=>'1'],'food'=>['name'=>'Synthetic food','sphere'=>'4'],'shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
    $chart=PC::chart(['cash'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'equity'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],
        'fixed'=>['name'=>'Synthetic equipment','group'=>'fixed_assets','skr_code'=>'06300'],'dep'=>['name'=>'Synthetic depreciation','group'=>'expense','skr_code'=>'62200'],
        'revenue'=>['name'=>'Synthetic sales exempt under section 19 UStG','group'=>'revenue','skr_code'=>'41840'],'expense'=>['name'=>'Synthetic general cost','group'=>'expense','skr_code'=>'63000'],
        'receivables'=>['name'=>'Synthetic claims','group'=>'receivables','skr_code'=>'12000']]);
    $line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
    $old=$previous['scope']['source']??null;$opening=$old?PC::carryForward($old['calculation'],'equity'):[$line('cash',1000000,0),$line('equity',0,1000000)];$entries=[];$rows=[];$items=[];$shares=[];
    $post=static function(string $key,string $date,array $lines,array $parts)use(&$entries,&$rows):string{
        $id=md5($key.$date);$entries[]=['id'=>$id,'date'=>$date,'description'=>'Synthetic '.$key,'lines'=>$lines,'reversal_of'=>null];
        foreach($parts as $i=>$p)$rows[$id.':'.$i]=['parts'=>$p,'reason'=>'Synthetic retained source'];return $id;
    };
    if(!$old&&$reversals){
        $parts=['food'=>34,'shop'=>66];$cancelled=$post('cancelled-acquisition','2025-08-01',[$line('fixed',100,0),$line('cash',0,100)],[$parts,$parts]);
        $post('exact-cancellation','2025-08-02',[$line('fixed',0,100),$line('cash',100,0)],[$parts,$parts]);$entries[array_key_last($entries)]['reversal_of']=$cancelled;
    }
    foreach(['shared'=>['4',120001,['food'=>2,'shop'=>3]],'exempt'=>['1',60001,['club'=>1]]] as $id=>[$sphere,$cost,$weights]){
        $plan=['basis_cents'=>$cost,'residual_cents'=>0,'months'=>60,'acquired_on'=>'2025-09-01','account'=>'06300','sphere'=>$sphere,'method'=>'linear_monthly','reason'=>'Synthetic original plan'];
        $s=PD::calculate($plan,$period,$old['asset_schedule']['items'][$id]['schedule']??null,null);
        if(!$old){$parts=PA::cents($cost,$weights);$post('acquisition-'.$id,$plan['acquired_on'],[$line('fixed',$cost,0),$line('cash',0,$cost)],[$parts,$parts]);}
        $parts=PA::cents($s['depreciation_cents'],$weights);$post('depreciation-'.$id,$period['end'],[$line('dep',$s['depreciation_cents'],0,$sphere),$line('fixed',0,$s['depreciation_cents'])],[$parts,$parts]);
        $items[$id]=['item_id'=>$id,'name'=>'Synthetic '.$id,'fixed_account'=>'fixed','plan'=>$plan,'schedule'=>$s,'posted'=>true];
        $shares[$id]=['item_sha256'=>PS::hash($items[$id]),'weights'=>$weights,'measure'=>'uses','reason'=>'Synthetic original usage'];
    }
    if(!$old){$parts=['food'=>3333,'shop'=>6664];$post('revenue',$period['end'],[$line('cash',9997,0),$line('revenue',0,9997,'4')],[$parts,$parts]);
        $parts=['club'=>12345];$post('club-income',$period['end'],[$line('cash',12345,0),$line('revenue',0,12345,'1')],[$parts,$parts]);
        $transfer=$post('permanent-funding',$period['end'],[$line('cash',3001,0),$line('cash',0,3001)],[['food'=>3001],['club'=>3001]]);
    }
    $r=['year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$period,$entries])),'previous_sha256'=>$old['closed_sha256']??null,'result_account'=>'equity','issues'=>[],
        'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>PC::build(2026,$chart,$opening,$entries,$period),'asset_schedule'=>['period'=>$period,'items'=>$items,'issues'=>[]],'period'=>$period];
    $carry=$old?PStatement::carry($old,$previous['scope']['allocation'],$previous['scope']['clearing'],$r):null;
    if(!$old)foreach($opening as $i=>$l)$rows['opening:'.$i]=['parts'=>['club'=>500000,'food'=>250000,'shop'=>250000],'reason'=>'Synthetic opening'];
    $a=PS::build($r,$units,($carry['allocations']??[])+$rows,$carry['capital_carry']??[],isset($transfer)?[$transfer=>['account'=>'equity','parts'=>['club'=>3001,'food'=>-3001],'reason'=>'Synthetic permanent funding']]:[]);
    $w=PW::build('synthetic-period-club',$r,$units,$a['allocations'],['advances'=>[],'repayments'=>[]],$previous['scope']['clearing']??null);
    $statement=PStatement::build($r,$a,$w);$r['assets']=PAssets::build($r,$units,$statement['units'],$shares,$old['assets']??null);
    $scope=['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-period-club','source'=>$r,'allocation'=>$a,'clearing'=>$w,
        'review'=>['allocation'=>['revision'=>2,'sha256'=>hash('sha256','Synthetic area review')],'clearing'=>['revision'=>2,'sha256'=>hash('sha256','Synthetic area review')]],'previous'=>$previous['scope']??null];
    return PI::rebuild($scope);
}
function periodClubFixture(string $variant='book'):array
{
    if($variant==='internal-advance'){
        $income=periodClubAdvanceIncome(true);$r=PR::build($income);
        return PV::build($income,['period'=>$r['period'],'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]]);
    }
    if(!in_array($variant,['book','tax','book-successor','tax-successor','reversal','reversal-successor'],true))throw new InvalidArgumentException('Unknown synthetic case');
    $income=periodClubIncome(null,str_starts_with($variant,'reversal'));$r=PR::build($income);$review=['period'=>$r['period'],'year'=>2026,'journal_sha256'=>$r['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
    if(str_starts_with($variant,'tax'))$review['asset_tax_plans']['shared']=['months'=>120,'depreciation_account'=>'dep','reason'=>'Synthetic reviewed tax life','confirmed'=>true];
    $first=PV::build($income,$review);
    if(!str_ends_with($variant,'successor'))return $first;
    $next=periodClubIncome($income);$report=PR::build($next);$carry=PT::carryForward($r,PT::build($r,$review),$report);
    $review['period']=$report['period'];$review['journal_sha256']=$report['closed_sha256'];$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
    return PV::build($next,$review,$first);
}
function periodClubAdvanceIncome(bool $internal):array
{
    require_once __DIR__.'/balance-internal-clearing.php';
    [$source,$units,$rows,$input]=clearingFixture();
    if($internal){$units['club']['sphere']='4';foreach($source['entries'] as &$entry)foreach($entry['lines'] as &$line)if($line['sphere']==='1')$line['sphere']='4';unset($entry,$line);}
    $source['period']=['start'=>'2025-01-01','end'=>'2025-12-31'];$source['asset_schedule']=['period'=>$source['period'],'items'=>[],'issues'=>[]];
    $source['calculation']=PC::build(2025,$source['accounts'],$source['opening'],$source['entries'],$source['period']);
    $source=array_replace(array_fill_keys(\Kulturhus\Verein\BalanceWholeClubReport::FIELDS,null),$source);
    $a=PS::build($source,$units,$rows);$w=PW::build('synthetic-period-club',$source,$units,$rows,$input);
    $ref=['revision'=>2,'sha256'=>hash('sha256','Synthetic advance approval')];
    return PI::rebuild(['schema'=>2,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-period-club','source'=>$source,'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>null]);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(periodClubFixture($argv[1]??'book'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
