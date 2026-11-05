<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceTaxAssetSale.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetTypes.php';
require_once __DIR__.'/BalanceCostAssetTax.php';

/** Explicitly reviewed linear tax lives; the closed book ledger and original plans stay untouched. */
final class BalanceTaxAssetValues
{
    public static function hash(array $value):string{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    public static function depreciationCode(string $code):?string{return AssetTypes::depreciationCode($code);}
    private static function pair(string $asset,string $other,int $difference,string $sphere):array
    {
        return [['account'=>$asset,'debit_cents'=>max(0,$difference),'credit_cents'=>max(0,-$difference),'sphere'=>''],
            ['account'=>$other,'debit_cents'=>max(0,-$difference),'credit_cents'=>max(0,$difference),'sphere'=>$sphere]];
    }
    public static function prepare(array $report,array $review):array
    {
        $plans=$review['asset_tax_plans']??[];
        if(!is_array($plans)||count($plans)>200)throw new DomainException('Steuerliche Anlagenpläne prüfen.');
        foreach($plans as $id=>$plan){
            if(($report['asset_schedule']['items'][$id]['method']??null)==='exceptional_original')throw new DomainException('AfaA: abweichenden Steuerplan gesondert qualifizieren.');
            if(($report['asset_schedule']['items'][$id]['depreciation_code']??null)==='62500')
                throw new DomainException('Kaufleasing: abweichende steuerliche Nutzungsdauer gesondert prüfen. Hier sind nur gleiche Buch- und Steuer-Anlagenwerte qualifiziert.');
        }
        $costItems=array_filter($report['asset_schedule']['items']??[],static fn($item)=>($item['method']??null)==='cost');
        foreach($report['asset_schedule']['items']??[] as $id=>$item){
            if(($item['skr_code']??null)!=='01500')continue;
            if(($item['method']??null)==='exceptional_original'){
                $exceptional=$item['exceptional']??null;
                if(!is_array($exceptional)||!is_array($exceptional['source']['plan']??null)||!is_array($exceptional['source']['proof']??null))throw new DomainException('Originaler Firmenwert-Jahresnachweis fehlt.');
                OriginalExceptionalDepreciation::snapshot(['plan'=>$exceptional['source']['plan'],'exceptional'=>$exceptional]);
                if(($exceptional['source']['plan']['account']??null)!=='01500'||($item['months']??null)!==180||($item['residual_cents']??null)!==0)throw new DomainException('Firmenwert-Jahresnachweis und ursprünglicher 15-Jahres-Plan stimmen nicht überein.');
                continue;
            }
            $months=$plans[$id]['months']??$item['months']??null;
            if($months!==180||($item['residual_cents']??null)!==0||($item['method']??'linear_monthly')!=='linear_monthly')throw new DomainException('Erworbener Geschäfts- oder Firmenwert: steuerlich 15 Jahre, kein Restwert; abweichende Buchwerte bestätigt überleiten.');
        }
        if(!$plans&&!$costItems)return ['review'=>$review,'evidence'=>null];
        foreach(['opening_adjustments','current_adjustments'] as $period){
            if(!is_array($review[$period]??null)||!array_is_list($review[$period]))throw new DomainException('Steuerliche Wertänderungen vollständig angeben.');
            foreach($review[$period] as $entry){
                if(!is_array($entry)||!is_array($entry['lines']??null))throw new DomainException('Steuerliche Buchungszeilen prüfen.');
                BalanceCalculation::lines($entry['lines'],$report['accounts'],$period==='opening_adjustments');
            }
        }
        $schedule=$report['asset_schedule']??null;
        if(!is_array($schedule)||!empty($schedule['issues'])||($schedule['year']??null)!==$report['year'])throw new DomainException('Abgestimmtes, festgeschriebenes Anlagenverzeichnis erforderlich.');
        $chart=$report['accounts'];$taxSchedule=$schedule;$items=$schedule['items']??[];$year=$report['year'];$proof=[];$current=[];$opening=[];$managed=[];$desiredOpening=[];
        BalanceDisposalAccounts::checkMovements($report['calculation']['accounts'],$items,$year);
        $equity=$report['result_account']??null;
        if($costItems){
            $cost=BalanceCostAssetTax::annual($report);$taxSchedule=$cost['tax_schedule'];$proof=$cost['proof'];$current=$cost['current'];
            $opening=$cost['opening'];$managed=$cost['managed'];$desiredOpening=$cost['desired_opening'];
        }
        ksort($plans,SORT_STRING);
        foreach($plans as $id=>$p){
            $item=$items[$id]??null;
            if(!is_string($id)||!is_array($p)||array_diff(array_keys($p),['months','depreciation_account','reason','confirmed'])
                ||!is_int($p['months']??null)||$p['months']<12||$p['months']>1200||$p['months']%12||($p['confirmed']??null)!==true
                ||!is_string($p['reason']??null)||trim($p['reason'])===''||mb_strlen($p['reason'])>1400||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$p['reason'])
                ||!is_string($p['depreciation_account']??null)||!is_array($item))throw new DomainException('Gegenstand, steuerliche Nutzungsdauer, Nachweis und fachliche Bestätigung vollständig angeben.');
            $code=AssetOriginalDepreciation::code($item,false);$dep=$p['depreciation_account'];$asset=$item['account']??'';
            $disposal=$item['disposal']??null;
            if(!$code||($item['method']??'linear_monthly')!=='linear_monthly'||isset($item['gwg'])||isset($item['degressive'])
                ||($disposal!==null&&(!is_array($disposal)||!in_array($disposal['kind']??null,['scrap','sale','partial_scrap','software_end'],true)))
                ||!in_array($item['sphere']??'',isset($report['whole_club_scope'])?['1','2','3','4']:['4'],true)||($chart[$dep]['group']??'')!=='expense'||($chart[$dep]['skr_code']??'')!==$code||($chart[$asset]['group']??'')!=='fixed_assets')
                throw new DomainException('Abweichende Steuer-Nutzungsdauer hier nur für bestätigte lineare Anlagen im Geschäftsbetrieb; bei Abgang nur vollständig belegter Verkauf oder Ausmusterung ohne Erlös. GWG und Sonderabschreibung gesondert klären.');
            $cost=$disposal!==null?($item['basis_cents']??null):($item['cost_closing_cents']??null);
            if(!is_int($cost)||$cost<=0||$p['months']===$item['months'])throw new DomainException('Eine tatsächlich abweichende steuerliche Nutzungsdauer angeben.');
            $base=['basis_cents'=>$cost,'residual_cents'=>$item['residual_cents'],'months'=>$item['months'],'acquired_on'=>$item['acquired_on'],'method'=>'linear_monthly','account'=>$item['skr_code']];
            $partial=($disposal['kind']??null)==='partial_scrap';$partialValues=null;
            if($partial){
                $original=$item['partial_plan']??null;if(!is_array($original))throw new DomainException('Ursprünglichen Teilabgangsplan prüfen.');
                foreach($base as $key=>$value)if(($original[$key]??null)!==$value)throw new DomainException('Teilabgang und steuerliche Ausgangswerte stimmen nicht überein.');
                if(($original['sphere']??null)!==$item['sphere'])throw new DomainException('Vereinsbereich des Teilabgangs prüfen.');
                $base=$original;$partialValues=AssetPartialDisposal::preview($base,$disposal,$year,['months'=>$p['months'],'reason'=>$p['reason'],'confirmed'=>true]);
                foreach($partialValues['book'] as $key=>$value)if(!in_array($key,['year','remaining_residual_cents'],true)&&($item[$key]??null)!==$value)throw new DomainException('Festgeschriebene Teilabgangswerte sind nicht abgestimmt.');
                $t=$partialValues['tax'];$tax=['opening_cents'=>$t['book_opening_cents']+$t['additions_cents'],'depreciation_cents'=>$t['depreciation_cents'],
                    'closing_cents'=>$t['book_closing_cents'],'disposal_cents'=>$t['book_disposals_cents']];
            }else $tax=AssetDisposal::schedule(array_replace($base,['months'=>$p['months']]),$year,$disposal);
            $book=AssetDisposal::schedule($base,$year,$disposal);
            if($disposal!==null&&(int)substr($disposal['date'],0,4)>$year)throw new DomainException('Anlagenabgang gehört nicht in diesen Jahresstand.');
            $oldAcquisition=(int)substr($item['acquired_on'],0,4)<$year;
            $bookOpening=$oldAcquisition?$book['opening_cents']:0;$taxOpening=$oldAcquisition?$tax['opening_cents']:0;
            if($bookOpening!==$item['book_opening_cents']||$book['closing_cents']!==$item['book_closing_cents']||$book['depreciation_cents']!==$item['depreciation_cents'])throw new DomainException('Buch-Anlagenplan und festgeschriebene Jahreswerte stimmen nicht überein.');
            $updated=array_replace($item,['months'=>$p['months'],'accumulated_opening_cents'=>$oldAcquisition?$base['basis_cents']-$tax['opening_cents']:0,
                'depreciation_cents'=>$tax['depreciation_cents'],'expected_depreciation_cents'=>$tax['depreciation_cents'],
                'accumulated_closing_cents'=>$base['basis_cents']-$tax['closing_cents'],'book_opening_cents'=>$taxOpening,'book_closing_cents'=>$tax['closing_cents']]);
            $disposalDifference=0;$loss=null;$sale=null;
            if($disposal!==null){
                if(($item['book_disposals_cents']??null)!==$book['disposal_cents'])throw new DomainException('Festgeschriebener Restwertabgang stimmt nicht mit dem Buch-Anlagenplan überein.');
                $disposedBefore=(int)substr($disposal['date'],0,4)<$year;
                $updated['accumulated_opening_cents']=$disposedBefore?0:($oldAcquisition?$cost-$tax['opening_cents']:0);
                $updated['accumulated_closing_cents']=0;
                $updated['accumulated_disposals_cents']=$disposedBefore?0:$cost-$tax['disposal_cents'];
                $updated['book_disposals_cents']=$tax['disposal_cents'];
                if($partialValues){
                    foreach($partialValues['tax'] as $key=>$value)if(!in_array($key,['year','remaining_residual_cents'],true))$updated[$key]=$value;
                }
                $disposalDifference=$tax['disposal_cents']-$book['disposal_cents'];
                if($disposal['kind']==='sale'){
                    $sale=BalanceTaxAssetSale::prepare($chart,$item,$base,$p['months'],$year,$p['reason']);
                    foreach($sale['managed'] as $key)$managed[$key]=true;
                }elseif($disposalDifference){
                    $loss=BalanceDisposalAccounts::choose($chart,$item,$disposal['kind']==='software_end'?'68960':'68950','expense');$managed[$loss]=true;
                }
            }
            foreach($taxSchedule['totals'] as $field=>$_)$taxSchedule['totals'][$field]+=($updated[$field]??0)-($item[$field]??0);
            $taxSchedule['items'][$id]=$updated;
            $openDifference=$taxOpening-$bookOpening;$depreciationDifference=$tax['depreciation_cents']-$book['depreciation_cents'];
            $managed[$asset]=true;$managed[$dep]=true;$desiredOpening[$asset]=($desiredOpening[$asset]??0)+$openDifference;
            if($openDifference){
                if(!is_string($equity)||($chart[$equity]['group']??'')!=='equity')throw new DomainException('Bestätigtes Ergebnisvortragskonto für den steuerlichen Anfangsunterschied fehlt.');
                $opening[]=['id'=>'asset-opening-'.substr(hash('sha256',$id),0,24),'reason'=>'Steuerlicher Anfangswert: '.$p['reason'],'lines'=>self::pair($asset,$equity,$openDifference,'')];
            }
            if($depreciationDifference)$current[]=['id'=>'asset-tax-'.substr(hash('sha256',$id),0,24),'reason'=>'Steuerliche Abschreibung: '.$p['reason'],'lines'=>self::pair($asset,$dep,-$depreciationDifference,$item['sphere'])];
            if($sale!==null){if($sale['entry']!==null)$current[]=$sale['entry'];}
            elseif($disposalDifference)$current[]=['id'=>'asset-scrap-'.substr(hash('sha256',$id),0,24),'reason'=>($disposal['kind']==='software_end'?'Steuerlicher Restwert bei Lizenzende: ':'Steuerlicher Restwert bei Ausmusterung: ').$p['reason'],'lines'=>self::pair($asset,$loss,-$disposalDifference,$item['sphere'])];
            $proof[$id]=['book_item_sha256'=>self::hash($item),'plan'=>$p,'opening_difference_cents'=>$openDifference,'depreciation_difference_cents'=>$depreciationDifference];
            if($sale!==null)$proof[$id]+=['disposal_difference_cents'=>$disposalDifference,'sale'=>$sale['proof']];
            elseif($disposal!==null)$proof[$id]+=['disposal_difference_cents'=>$disposalDifference,'disposal_account'=>$loss];
        }
        // Manual postings may not duplicate or cancel the generated asset valuation.
        $carry=[];$hasCarry=false;
        foreach($review['current_adjustments']??[] as $entry)foreach($entry['lines']??[] as $line)if(isset($managed[$line['account']??'']))throw new DomainException('Manuelle und automatisch berechnete steuerliche Anlagenänderung würden sich überschneiden.');
        foreach($review['opening_adjustments']??[] as $entry){
            $isCarry=is_string($entry['id']??null)&&str_starts_with($entry['id'],'carry-');$hasCarry=$hasCarry||$isCarry;
            foreach($entry['lines']??[] as $line)if(isset($managed[$line['account']??''])){
                if(!$isCarry||!isset($desiredOpening[$line['account']]))throw new DomainException('Steuerlichen Anlagen-Anfangswert aus dem bestätigten Vorjahr übernehmen, nicht doppelt buchen.');
                $carry[$line['account']]=($carry[$line['account']]??0)+$line['debit_cents']-$line['credit_cents'];
            }
        }
        if($hasCarry){foreach($desiredOpening as $account=>$amount)if(($carry[$account]??0)!==$amount)throw new DomainException('Steuerliche Nutzungsdauer passt nicht zu den übernommenen Vorjahreswerten.');$opening=[];}
        $review['opening_adjustments']=array_merge($review['opening_adjustments'],$opening);
        $review['current_adjustments']=array_merge($review['current_adjustments'],$current);
        return ['review'=>$review,'evidence'=>['book_schedule_sha256'=>self::hash($schedule),'items'=>$proof,'equity_account'=>$equity,
            'generated_opening'=>$opening,'generated_current'=>$current,'tax_schedule'=>$taxSchedule]];
    }
}
