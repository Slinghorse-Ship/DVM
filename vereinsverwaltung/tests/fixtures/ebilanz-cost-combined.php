<?php declare(strict_types=1);
// Synthetic source postings and actual composite constructors; no database or transport.
ob_start();require __DIR__.'/ebilanz-cost-assets.php';$rows=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';
require_once __DIR__.'/balance-club-tax.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';
use Kulturhus\Verein\{BalanceCombinedClubValues as Combined,BalanceClubTaxReport as Club,BalanceTaxValues as Tax,
    BalanceSegmentCalculation as Segments,BalanceCalculation as Calculation,BalanceWholeClubReport as Whole,BalanceTaxMapping as TaxMapping};
$results=[];$previousCombined=null;$previousState=null;$shared=($argv[4]??null)==='shared'||($argv[6]??null)==='shared';$weights=['food'=>2,'shop'=>3];
foreach($rows as $row){
    $source=$row['source'];$source=$source['whole_club_scope']['source']??$source;
    if(isset($source['period'])&&!$shared){
        $book=Kulturhus\Verein\BalancePeriodFilingValues::books($source,$previousCombined);
        $map=TaxMapping::preview($source['year'],$book['tax']['accounts'],Kulturhus\Verein\BalancePeriodWholeFiling::mappingSchedule($book));
        $results[]=['values'=>$book,'automatic'=>$map['mapping']];$previousCombined=$book;continue;
    }
    $units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic capital','sphere'=>'2'],
        'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic food','sphere'=>'4'],'shop'=>['name'=>'Synthetic shop','sphere'=>'4']];
    $source['accounts']['revenue']=['name'=>'Synthetic evidenced income','group'=>'revenue','skr_code'=>''];
    $source['accounts']=Calculation::chart($source['accounts']);$destinations=[];
    foreach($units as $unit=>$u){
        $id=md5('synthetic-income-'.$source['year'].'-'.$unit);$n=13+count($destinations)*7;$destinations[$id]=$unit;
        $source['entries'][]=['id'=>$id,'date'=>$source['year'].'-11-01','lines'=>[
            ['account'=>'bank','debit_cents'=>$n,'credit_cents'=>0,'sphere'=>''],
            ['account'=>'revenue','debit_cents'=>0,'credit_cents'=>$n,'sphere'=>$u['sphere']]]];
        if(isset($source['period']))$source['entries'][array_key_last($source['entries'])]['date']=$source['period']['end'];
    }
    if($previousState!==null){
        $old=$previousState['journal']??$previousState['scope']['source'];
        $source['previous_sha256']=$old['closed_sha256'];
        $source['opening']=Calculation::carryForward($old['calculation'],$source['result_account']);
    }
    $source['calculation']=Calculation::build($source['year'],$source['accounts'],$source['opening'],$source['entries'],$source['period']??null);
    $source['closed_sha256']=hash('sha256',json_encode([$source['year'],$source['entries'],$source['opening']],JSON_THROW_ON_ERROR));
    $alloc=[];$capitalCarry=[];
    if($previousState!==null){$carry=isset($source['period'])?Kulturhus\Verein\BalanceClearingStatement::carry($previousState['scope']['source'],$previousState['scope']['allocation'],$previousState['scope']['clearing'],$source):Segments::carry($previousState['journal'],$previousState['allocation'],$source);$alloc=$carry['allocations'];$capitalCarry=$carry['capital_carry']??[];}
    $assetShares=[];$postingParts=[];
    if($shared){
        foreach($source['asset_schedule']['items'] as $id=>$item){
            $assetShares[$id]=['item_sha256'=>Segments::hash($item),'weights'=>($id==='synthetic-cost'?$weights:['food'=>1]),'measure'=>'uses','reason'=>'Synthetic original shared use evidence'];
            if($id!=='synthetic-cost')continue;
            $value=$item['valuation'];$projection=Kulturhus\Verein\BalanceCostAssetAllocation::split($value,$weights);$i=0;
            foreach($value['movements'] as $movement){$type=Kulturhus\Verein\BalanceCostAssetSources::codes($value['source']['plan']['kind'],$movement['kind'],$value['source']['events'][$i]['permanent']??true,$value['source']['plan']['financial_profile']??null,$value['source']['events'][$i]['counter_code']??null,$value['source']['plan']['account']);
                $field=$type['group']==='revenue'?'writeup_cents':($movement['kind']==='disposal'?'book_disposals_cents':'impairment_cents');
                if($movement['book'][$field]>0){$parts=[];foreach($projection['events'][$movement['event_id']] as $u=>$v)if($v[$field]>0)$parts[$u]=$v[$field];$postingParts[md5('synthetic-valuation-'.$source['year'].'-'.count($postingParts))]=$parts;}++$i;}
            if($value['book']['additions_cents'])$postingParts[md5('synthetic-purchase')]=Kulturhus\Verein\BalanceAssetAllocation::cents($value['book']['additions_cents'],$weights);
        }
    }
    foreach(Segments::sources($source) as $key=>$line){
        if($line['voucher']==='opening'&&$previousState!==null)continue;
        $unit=$destinations[$line['voucher']]??'food';$parts=$postingParts[$line['voucher']]??[$unit=>$line['cents']];
        if($shared&&$line['voucher']==='opening'){$parts=Kulturhus\Verein\BalanceAssetAllocation::cents(12000,$weights);if($line['cents']===24000)$parts['food']+=12000;}
        $alloc[$key]=['parts'=>$parts,'reason'=>'Synthetic original line allocation'];
    }
    if(isset($source['period'])){
        $a=Segments::build($source,$units,$alloc,$capitalCarry);$w=Kulturhus\Verein\BalanceInternalClearing::build('synthetic-period-cost',$source,$a['units'],$a['allocations'],['advances'=>[],'repayments'=>[]],$previousState['scope']['clearing']??null);
        $statement=Kulturhus\Verein\BalanceClearingStatement::build($source,$a,$w);
        $source['assets']=Kulturhus\Verein\BalancePeriodAssetAllocation::build($source,$a['units'],$statement['units'],$assetShares,$previousState['scope']['source']['assets']??null);
        $ref=['revision'=>2,'sha256'=>hash('sha256','Synthetic actual cost area review')];
        $state=Kulturhus\Verein\BalanceClubIncomeReconciliation::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-period-cost','source'=>$source,'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>$previousState['scope']??null]);
        $part=Kulturhus\Verein\BalancePeriodClubReport::build($state);
        $taxReview=['year'=>$source['year'],'period'=>$source['period'],'journal_sha256'=>$part['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
        if($previousCombined!==null){$previousPart=$previousCombined['taxable'];$carry=Kulturhus\Verein\BalancePeriodTaxValues::carryForward($previousPart['period_source']['source'],Kulturhus\Verein\BalancePeriodTaxValues::build($previousPart['period_source']['source'],$previousReview),$part);if($carry['entry'])$taxReview['opening_adjustments']=[$carry['entry']];}
        $combined=Kulturhus\Verein\BalancePeriodCombinedValues::build($state,$taxReview,$previousCombined);
    }else{
    $state=clubTaxState($source,$units,$alloc,2,$capitalCarry,[],$assetShares);$part=Club::fromState($state,$previousCombined['taxable']??null);
    $taxReview=['year'=>$source['year'],'journal_sha256'=>$part['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
    if($previousCombined!==null){$carry=Tax::carryForward(Club::rebuild($previousCombined['taxable']['club_scope']),$previousReview,$part);if($carry['entry'])$taxReview['opening_adjustments']=[$carry['entry']];}
    $combined=Combined::build($state,['year'=>$source['year'],'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'taxable_review'=>$taxReview],$previousCombined);
    }
    $maps=[];foreach(['whole','taxable'] as $section){$value=$combined[$section];$schedule=isset($value['period'])?Kulturhus\Verein\BalancePeriodWholeFiling::mappingSchedule($value):($value['asset_tax']['tax_schedule']??$value['asset_schedule']);$maps[$section]=TaxMapping::preview($source['year'],$value['tax']['accounts'],$schedule)['mapping'];}
    $result=['values'=>$combined,'automatic'=>$maps];if($shared)$result['asset_use']=isset($source['period'])?$source['assets']:['shares'=>$state['allocation']['asset_shares'],'schedules'=>$state['allocation']['asset_schedules']];
    $results[]=$result;$previousState=$state;$previousCombined=$combined;$previousReview=$taxReview;
}
echo json_encode($results,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
