<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';
require_once __DIR__.'/../../src/Finance/Balance/BalanceSubsidiaryClubValues.php';
use Kulturhus\Verein\{BalanceCalculation as SC,BalanceSegmentCalculation as SS,BalanceInternalClearing as SW,BalanceClearingStatement as CS,
    BalancePeriodAssetAllocation as SA,BalanceClubIncomeReconciliation as SI,BalanceClubTaxReconciliation as ST,BalancePeriodSphereChange as SM,BalanceSubsidiaryClubValues as SV};

function sphereFixtureChange(string $on,string $to,string $case,int $value=0,int $months=0,bool $charitable=false):array
{return ['on'=>$on,'to_sphere'=>$to,'case'=>$case,'value_cents'=>$value,'remaining_months'=>$months,'residual_cents'=>0,
    'document_id'=>str_repeat('a',32),'revision'=>1,'sha256'=>hash('sha256','Synthetic retained original'),'reason'=>'Synthetic reviewed value · <proof> & facts',
    'charitable_use'=>$charitable,'funds_reviewed'=>true,'vat_reviewed'=>true,'tax_reviewed'=>true,'history_reviewed'=>true];}

function spherePeriodFixture(string $case,bool $successor=false,bool $correction=false,bool $mixed=false,string $off=''):array
{
    $from=in_array($case,['entry','fast-book','exempt','multiple'],true)?'1':'4';
    $changes=match($case){
        'entry','fast-book'=>[sphereFixtureChange('2026-01-01','4','taxable_value',90000,30)],
        'exempt'=>[sphereFixtureChange('2026-01-01','3','exempt_to_exempt')],
        'exit-gain'=>[sphereFixtureChange('2026-01-01','2','exempt_value',130000)],
        'exit-loss'=>[sphereFixtureChange('2026-01-01','2','exempt_value',100000)],
        'book-return'=>[sphereFixtureChange('2026-01-01','1','exempt_book',112000,0,true),sphereFixtureChange('2026-03-01','4','taxable_return',108000)],
        'value-return'=>[sphereFixtureChange('2026-01-01','2','exempt_value',130000),sphereFixtureChange('2026-03-01','4','taxable_value',99000,33)],
        'later-return'=>[sphereFixtureChange('2026-01-01','1','exempt_book',112000,0,true)],
        'multiple'=>[sphereFixtureChange('2026-01-01','4','taxable_value',90000,30),sphereFixtureChange('2026-03-01','3','exempt_book',84000,0,true),sphereFixtureChange('2026-05-01','4','taxable_return',78000)],
        default=>throw new InvalidArgumentException('Unknown synthetic sphere fixture')};
    $chart=SC::chart(['cash'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'equity'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],
        'fixed'=>['name'=>'Synthetic device','group'=>'fixed_assets','skr_code'=>'06300'],'dep'=>['name'=>'Synthetic depreciation','group'=>'expense','skr_code'=>'62200']]);
    $plan=['basis_cents'=>120000,'residual_cents'=>0,'months'=>$case==='fast-book'?12:60,'acquired_on'=>'2025-09-18','method'=>'linear_monthly','account'=>'06300','sphere'=>$from];
    $units=SS::units(['ideal'=>['name'=>'Synthetic club','sphere'=>'1'],'property'=>['name'=>'Synthetic property','sphere'=>'2'],'purpose'=>['name'=>'Synthetic purpose','sphere'=>'3'],'activity'=>['name'=>'Synthetic taxable','sphere'=>'4']]);
    $unitFor=['1'=>'ideal','2'=>'property','3'=>'purpose','4'=>'activity'];$income=$tax=null;
    for($step=0;$step<=($successor?1:0);$step++){
        $period=$step?['start'=>'2026-07-01','end'=>'2026-12-31']:['start'=>'2025-07-01','end'=>'2026-06-30'];
        $old=$income['scope']['source']??null;
        $current=$step?($case==='later-return'?[sphereFixtureChange('2026-10-01','4','taxable_return',94000)]:[]):$changes;
        $sphere=SM::build($plan,$period,$current,$old['asset_schedule']['items']['device']['sphere_change']??null);
        $entries=[];$targets=[];
        $post=static function(string $id,string $date,string $debit,string $credit,int $cents,string $s)use(&$entries,&$targets,$chart,$unitFor){
            if(!$cents)return;$key=md5($id.$date);$targets[$key]=$unitFor[$s];$lines=[];
            foreach([[$debit,$cents,0],[$credit,0,$cents]] as [$a,$d,$c])$lines[]=['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$chart[$a]['group']==='expense'?$s:''];
            $entries[]=['id'=>$key,'date'=>$date,'description'=>'Synthetic '.$id,'lines'=>$lines];
        };
        if(!$step)$post('purchase',$plan['acquired_on'],'fixed','cash',120000,$from);
        if(!$step&&$correction){
            $post('old-depreciation',$period['end'],'dep','fixed',$sphere['book']['schedule']['depreciation_cents'],$from);
            $original=end($entries);$reverse=$original;$reverse['id']=md5('reversed');$reverse['reversal_of']=$original['id'];
            foreach($reverse['lines'] as &$line){$d=$line['debit_cents'];$line['debit_cents']=$line['credit_cents'];$line['credit_cents']=$d;}unset($line);
            $entries[]=$reverse;$targets[$reverse['id']]=$unitFor[$from];
        }
        foreach($sphere['book']['depreciation_by_sphere'] as $s=>$n)$post('depreciation-'.$s,$period['end'],'dep','fixed',$n,(string)$s);
        $other=null;
        if($mixed){
            $op=array_replace($plan,['sphere'=>'4']);$os=\Kulturhus\Verein\BalancePeriodAssets::calculate($op,$period);
            $other=['item_id'=>'ordinary','name'=>'Synthetic ordinary asset','fixed_account'=>'fixed','plan'=>$op,'schedule'=>$os,'posted'=>true];
            if(!$step)$post('ordinary-purchase',$op['acquired_on'],'fixed','cash',$op['basis_cents'],'4');
            $post('ordinary-depreciation',$period['end'],'dep','fixed',$os['depreciation_cents'],'4');
        }
        $opening=$old?SC::carryForward($old['calculation'],'equity'):[['account'=>'cash','debit_cents'=>2000000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>2000000,'sphere'=>'']];
        $item=['item_id'=>'device','name'=>'INV-S · Synthetic device <name>','fixed_account'=>'fixed','plan'=>$plan,'schedule'=>$sphere['book']['schedule'],'posted'=>true,'sphere_change'=>$sphere];
        $r=['year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$period,$entries,$opening,$sphere])),'previous_sha256'=>$old['closed_sha256']??null,'result_account'=>'equity','issues'=>[],
            'accounts'=>$chart,'opening'=>$opening,'entries'=>$entries,'calculation'=>SC::build(2026,$chart,$opening,$entries,$period),'asset_schedule'=>['period'=>$period,'items'=>['device'=>$item],'issues'=>[]],'period'=>$period];
        if($other!==null)$r['asset_schedule']['items']['ordinary']=$other;
        $alloc=$capital=[];
        if($old){$carry=CS::carry($old,$income['scope']['allocation'],$income['scope']['clearing'],$r,$old['assets']['movements']??[]);$alloc=$carry['allocations'];$capital=$carry['capital_carry']??[];}
        foreach(SS::sources($r) as $key=>$row)if(!isset($alloc[$key])){
            $parts=$mixed&&$from!=='4'&&$row['voucher']==='opening'?[$unitFor[$from]=>1000000,'activity'=>1000000]:[($targets[$row['voucher']]??$unitFor[$from])=>$row['cents']];
            $alloc[$key]=['parts'=>$parts,'reason'=>'Synthetic original allocation'];
        }
        $allocation=SS::build($r,$units,$alloc,$capital);$clearing=SW::build('synthetic-spheres',$r,$units,$alloc,['advances'=>[],'repayments'=>[]],$income['scope']['clearing']??null,$capital);
        $share=['item_sha256'=>SS::hash($item),'weights'=>[$unitFor[$sphere['book']['opening_sphere']]=>1],'measure'=>'uses','reason'=>'Synthetic evidenced use'];
        if($current)$share['changes']=array_map(static fn($c)=>['on'=>$c['on'],'weights'=>[$unitFor[$c['to_sphere']]=>1],'reason'=>'Synthetic evidenced change'],$current);
        $shares=['device'=>$share];
        if($other!==null)$shares['ordinary']=['item_sha256'=>SS::hash($other),'weights'=>['activity'=>1],'measure'=>'uses','reason'=>'Synthetic original use'];
        $statement=SA::statement($r,$allocation,$clearing,$shares,$old['assets']??null);
        $r['assets']=SA::build($r,$units,$statement['units'],$shares,$old['assets']??null);
        $ref=['revision'=>2,'sha256'=>hash('sha256','Synthetic area approval')];
        $income=SI::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-spheres','source'=>$r,'allocation'=>$allocation,'clearing'=>$clearing,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>$income['scope']??null]);
        $review=['year'=>2026,'journal_sha256'=>$r['closed_sha256'],'income_sha256'=>$income['sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[],'period'=>$period];
        if($mixed)$review['asset_tax_plans']['ordinary']=['months'=>120,'depreciation_account'=>'dep','reason'=>'Synthetic reviewed ordinary tax life','confirmed'=>true];
        if($tax){$carry=ST::carryForward($tax,$income);$review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];}
        $priorTax=$tax;$tax=ST::build($income,$review,$priorTax);
        if($off==='current'||($off==='prior-only'&&!$step)){
            foreach(ST::correctionSources($tax) as $key=>$row)if(isset($row['asset_id']))$review['off_balance_adjustments'][]=[
                'id'=>'asset-'.md5($row['asset_id']),'kind'=>'non_deductible_expense','sources'=>[$key=>max(1,intdiv($row['cents'],2))],
                'reason'=>'Synthetic reviewed disallowed asset expense','legal_basis'=>'Synthetic documented tax review','confirmed'=>true];
            $tax=ST::build($income,$review,$priorTax);
        }
    }
    return SV::build($tax);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
    echo json_encode(spherePeriodFixture($argv[1]??'entry',($argv[2]??'')==='successor',($argv[3]??'')==='corrected',($argv[4]??'')==='mixed',$argv[5]??''),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
