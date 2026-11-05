<?php declare(strict_types=1);
require __DIR__.'/ebilanz-period-calendar.php';
use Kulturhus\Verein\{BalanceWholeClubReport as W,BalanceCalendarFilingSource as Calendar,BalanceTaxValues as T,BalancePeriodTaxValues as PV,BalancePeriodFilingValues as F,BalanceCalculation as C};
function businessCalendarFixture(string $variant):array
{
    if(!in_array($variant,['book','tax','tax-successor'],true))throw new InvalidArgumentException('Unknown business calendar case');
    $base=calendarPeriodFixture('book');
    $source=W::fromReport($base['subsidiary']['previous']['income']['scope']['source'])['whole_club_scope']['source'];
    foreach($source['entries'] as &$entry)foreach($entry['lines'] as &$line)if($line['sphere']!=='')$line['sphere']='4';unset($entry,$line);
    foreach($source['asset_schedule']['items'] as &$item)$item['sphere']='4';unset($item);
    $source['calculation']=C::build($source['year'],$source['accounts'],$source['opening'],$source['entries']);
    $review=['year'=>$source['year'],'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[]];
    if($variant!=='book')foreach($source['asset_schedule']['items'] as $id=>$item)$review['asset_tax_plans'][$id]=['months'=>120,'depreciation_account'=>'depreciation','reason'=>'Synthetic separate tax plan','confirmed'=>true];
    $old=T::build($source,$review);$packet=Calendar::businessPacket($source,$old,$review);
    $r=W::fromReport($base['whole']['period_source']['source'])['whole_club_scope']['source'];
    foreach($r['entries'] as &$entry)foreach($entry['lines'] as &$line)if($line['sphere']!=='')$line['sphere']='4';unset($entry,$line);
    foreach($r['asset_schedule']['items'] as &$item){$item['plan']['sphere']='4';$item['plan']['reason']='Synthetic retained plan';}unset($item);
    $r['calculation']=C::build($r['year'],$r['accounts'],$r['opening'],$r['entries'],$r['period']);
    $carry=PV::carryForward($source,$old,$r);
    $next=['period'=>$r['period'],'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'complete'=>true,
        'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[],'current_adjustments'=>[],'asset_tax_plans'=>$review['asset_tax_plans']];
    $values=F::build($r,$next,$packet);
    if($variant!=='tax-successor')return $values;
    $p=['start'=>'2026-07-01','end'=>'2026-12-31'];$later=$r;$later['period']=$p;$later['closed_sha256']=hash('sha256','Synthetic later business');$later['previous_sha256']=$r['closed_sha256'];
    $later['opening']=C::carryForward($r['calculation'],'equity');$later['entries']=[];$later['asset_schedule']['period']=$p;
    foreach($later['asset_schedule']['items'] as $id=>&$item){
        $item['schedule']=\Kulturhus\Verein\BalancePeriodDisposal::calculate($item['plan'],$p,$r['asset_schedule']['items'][$id]['schedule'],$item['disposal']??null);
        $n=$item['schedule']['depreciation_cents'];if($n)$later['entries'][]=['id'=>md5('Synthetic later depreciation'.$id),'date'=>$p['end'],'lines'=>[
            ['account'=>'depreciation','debit_cents'=>$n,'credit_cents'=>0,'sphere'=>'4'],['account'=>$item['fixed_account'],'debit_cents'=>0,'credit_cents'=>$n,'sphere'=>'']]];
    }unset($item);
    $later['calculation']=C::build(2026,$later['accounts'],$later['opening'],$later['entries'],$p);
    // Use the already reconstructed predecessor's raw valuation hash, exactly as the service does.
    $raw=$values;unset($raw['period_source'],$raw['sha256'],$raw['transmission']);$raw+=['sha256'=>\Kulturhus\Verein\BalanceSegmentCalculation::hash($raw),'transmission'=>'unavailable'];
    $carry=PV::carryForward($r,$raw,$later);$next['period']=$p;$next['journal_sha256']=$later['closed_sha256'];$next['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
    return F::build($later,$next,$values);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(businessCalendarFixture($argv[1]??'tax'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
