<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodSphereChange.php';

/** Subsidiary computation only. Internal changes create no whole-club income or capital. */
final class BalancePeriodSphereTax
{
    public static function item(array $item):array
    {
        $p=BalancePeriodSphereChange::rebuild($item['sphere_change']);$b=$p['book'];$t=$p['tax'];
        if($p['plan']!==$item['plan']||$b['schedule']!==$item['schedule']||isset($item['disposal']))throw new DomainException('Bereichswechsel und ursprüngliche Anlage abstimmen.');
        $book=['opening_cents'=>$b['opening_sphere']==='4'?$b['schedule']['book_opening_cents']:0,
            'additions_cents'=>$item['plan']['sphere']==='4'?$b['schedule']['additions_cents']:0,
            'contributions_cents'=>0,'withdrawals_cents'=>0,'depreciation_cents'=>$b['depreciation_by_sphere']['4'],
            'closing_cents'=>$p['closing_sphere']==='4'?$b['schedule']['book_closing_cents']:0];
        $movements=[];
        foreach($b['movements'] as $i=>$m){
            $v=$t['movements'][$i];$c=$p['changes'][$i];
            if($m['on']!==$v['on']||$m['on']!==$c['on'])throw new DomainException('Datierten Bewertungsnachweis prüfen.');
            $incoming=$m['to_sphere']==='4'?$m['book_cents']:0;$outgoing=$m['from_sphere']==='4'?$m['book_cents']:0;
            $book['contributions_cents']+=$incoming;$book['withdrawals_cents']+=$outgoing;
            $movements[]=$v+['book_contribution_cents'=>$incoming,'book_withdrawal_cents'=>$outgoing,
                'document_id'=>$c['document_id'],'revision'=>$c['revision'],'sha256'=>$c['sha256'],'reason'=>$c['reason']];
        }
        if($book['opening_cents']+$book['additions_cents']+$book['contributions_cents']-$book['withdrawals_cents']-$book['depreciation_cents']!==$book['closing_cents'])throw new DomainException('Buchwerte des steuerpflichtigen Bereichs sind nicht abgestimmt.');
        $difference=$t['valuation_result_cents']+$book['depreciation_cents']-$t['depreciation_cents'];
        // Independent net-asset/capital comparison, not an invented taxable whole balance.
        $taxResult=$t['closing_cents']-$t['opening_cents']-$t['additions_cents']-$t['contributions_cents']+$t['withdrawals_cents'];
        if($taxResult!==$t['valuation_result_cents']-$t['depreciation_cents']||abs($difference)>BalanceCalculation::LIMIT)throw new DomainException('Steuerliche Kapital- und Ergebnisüberleitung prüfen.');
        $v=['item_id'=>$item['item_id'],'sphere_change_sha256'=>$p['sha256'],'book_taxable'=>$book,
            'tax'=>array_diff_key($t,['movements'=>true]),'movements'=>$movements,'valuation_difference_cents'=>$difference];
        return $v+['sha256'=>BalanceSegmentCalculation::hash($v)];
    }
    public static function build(array $report):?array
    {
        $items=[];$total=0;
        foreach($report['asset_schedule']['items']??[] as $id=>$item)if(isset($item['sphere_change'])){
            $items[$id]=self::item($item);$total+=$items[$id]['valuation_difference_cents'];
            if(abs($total)>BalanceCalculation::LIMIT)throw new DomainException('Betragsgrenze der Bereichsüberleitung überschritten.');
        }
        if(!$items)return null;
        return ['items'=>$items,'valuation_difference_cents'=>$total];
    }
}
