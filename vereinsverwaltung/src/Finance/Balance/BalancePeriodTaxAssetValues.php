<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalancePeriodAssetAllocation.php';
require_once __DIR__.'/BalancePeriodSale.php';
require_once __DIR__.'/BalanceTaxAssetValues.php';
require_once __DIR__.'/BalancePeriodSphereTax.php';

/** Separate dated tax values. Original assets, use shares and invoices remain untouched. */
final class BalancePeriodTaxAssetValues
{
    private static function require(bool $ok,string $why):void{if(!$ok)throw new DomainException($why);}
    private static function pair(string $fixed,string $other,int $assetDifference,string $sphere):array
    {return [['account'=>$fixed,'debit_cents'=>max(0,$assetDifference),'credit_cents'=>max(0,-$assetDifference),'sphere'=>''],['account'=>$other,'debit_cents'=>max(0,-$assetDifference),'credit_cents'=>max(0,$assetDifference),'sphere'=>$sphere]];}
    /** Reconstruct the last calendar tax schedule from its reviewed original, not the current plan. */
    public static function calendar(array $income,array $valuation,array $plans):array
    {
        return self::calendarValues($income['scope']['source'],$income['scope']['allocation'],$valuation,$plans);
    }
    /** A complete corporation does not need fictitious analytical area allocations. */
    public static function calendarWhole(array $source,array $valuation,array $plans):array
    {return self::calendarValues($source,null,$valuation,$plans);}
    private static function calendarValues(array $source,?array $allocation,array $valuation,array $plans):array
    {
        self::require(!isset($source['period']),'Kalender-Vorabschluss erforderlich.');
        $original=$source;
        foreach($original['asset_schedule']['items'] as $id=>&$item)$item['name']??=$item['receipt_number']??$id;unset($item);
        $book=BalancePeriodAssetAllocation::calendar($original,$allocation);
        $stored=$valuation['asset_tax']['tax_schedule']??$source['asset_schedule'];$items=[];
        foreach($book['items']??[] as $id=>$item){
            $p=$item['plan'];$review=$plans[$id]??null;$taxPlan=$review?array_replace($p,['months'=>$review['months']]):$p;$schedule=null;
            if(($p['method']??null)==='cost'){
                self::require($review===null&&isset($stored['items'][$id]),'Ursprünglichen Steuer-Kostenbewertungsstand unverändert vortragen.');
                $side=($valuation['annual_section']??null)==='books'?'book':'tax';
                $value=$item['valuation'];$schedule=['period'=>$value['source']['period'],'depreciation_cents'=>0]+$value[$side];
                foreach(AssetValuation::FIELDS as $field)self::require(($stored['items'][$id][$field]??null)===$value[$side][$field],
                    'Kalender-Steuerwerte stimmen nicht mit dem ursprünglichen Kostenbewertungsnachweis überein.');
                $items[$id]=['tax_review'=>null,'schedule'=>$schedule,'valuation_sha256'=>$value['sha256']];continue;
            }
            for($year=TaxCatalog::documentYear($p['acquired_on']);$year<=$source['year'];$year++){
                $period=BalancePeriod::calendar($year);$disposal=isset($item['disposal'])&&$item['disposal']['date']<=$period['end']?$item['disposal']:null;
                $schedule=$review&&($disposal['kind']??null)==='partial_scrap'
                    ?AssetPartialDisposal::periodValuation($p,$disposal,$period,['months'=>$review['months'],'reason'=>$review['reason'],'confirmed'=>true])['tax']
                    :BalancePeriodDisposal::calculate($taxPlan,$period,$schedule,$disposal);
            }
            self::require($schedule!==null&&isset($stored['items'][$id]),'Ursprünglichen Steuer-Anlagenvortrag nachweisen.');
            foreach(BalancePeriodAssetAllocation::FIELDS as $field)self::require(($stored['items'][$id][$field]??0)===($schedule[$field]??0),'Kalender-Steuerwerte und unveränderter Anlagenplan stimmen nicht überein.');
            $items[$id]=['tax_review'=>$review,'schedule'=>$schedule];
        }
        self::require(!array_diff_key($stored['items'],$items),'Historische Steuer-Anlagen vollständig vortragen.');
        return ['period'=>BalancePeriod::calendar($source['year']),'tax_schedule'=>['items'=>$items]];
    }
    public static function prepare(array $report,array $plans,?array $previous=null,bool $wholeTaxable=false,string $section=''):array
    {
        $period=BalancePeriod::check($report['period'],$report['year']);$items=$report['asset_schedule']['items'];$chart=$report['accounts'];
        self::require(count($plans)<=200&&!array_diff_key($plans,$items),'Steuerliche Pläne vorhandenen Gegenständen zuordnen.');
        if($previous!==null)self::require(BalancePeriod::follows($previous['period'],$period),'Steuerliche Anlagenwerte des unmittelbaren Vorzeitraums verwenden.');
        $out=[];$current=[];$opening=[];$managed=[];$proof=[];$added=[];$totals=array_fill_keys(BalancePeriodAssetAllocation::FIELDS,0);
        $hasCost=(bool)array_filter($items,static fn($item)=>($item['plan']['method']??null)==='cost');
        if($hasCost)$totals+=array_fill_keys(['impairment_cents','writeup_cents'],0);
        foreach($items as $id=>$item){
            $original=$item['plan'];$taxPlan=$original;$review=$plans[$id]??null;$disposal=$item['disposal']??null;$fixed=$item['fixed_account'];
            $old=$previous['tax_schedule']['items'][$id]??null;
            if($section!=='books'&&($original['account']??null)==='01500')self::require(($review['months']??$original['months']??null)===180&&($original['residual_cents']??null)===0&&($original['method']??'linear_monthly')==='linear_monthly','Erworbener Geschäfts- oder Firmenwert: bestätigte steuerliche Nutzungsdauer von 15 Jahren ohne Restwert erforderlich.');
            if(($original['method']??null)==='cost'){
                self::require($review===null&&!isset($item['sphere_change'])&&$disposal===null,'Nicht planmäßig abzuschreibende Anlage aus ihrem belegten Buch-/Steuerbewertungsverlauf überleiten.');
                $flat=['item_id'=>$id,'account'=>$fixed,'method'=>'cost','cost_plan'=>$original,'valuation'=>$item['valuation']??null,
                    'valuation_accounts'=>$item['valuation_accounts']??[]]+$item['schedule'];
                $derived=BalanceCostAssetTax::item($flat,$chart,$period);$value=$item['valuation'];
                $side=$section==='books'?'book':'tax';
                if($original['acquired_on']<$period['start']){
                    $prior=$value['source']['previous'];
                    self::require(is_array($prior)&&$old!==null&&($old['valuation_sha256']??null)===$prior['sha256']
                        &&$old['schedule']===(['period'=>$prior['source']['period'],'depreciation_cents'=>0]+$prior[$side]),
                        'Steuerlichen Kostenbewertungsvortrag aus dem unveränderten unmittelbaren Vorzeitraum übernehmen.');
                }else self::require($old===null,'Anschaffung nicht mit einem zweiten steuerlichen Anfangsbestand versehen.');
                $opening[$fixed]=($opening[$fixed]??0)+($side==='book'?0:$derived['opening_difference_cents']);
                if($side==='tax')array_push($current,...$derived['generated_current']);
                foreach($derived['managed_accounts'] as $key)$managed[$key]=true;
                $tax=['period'=>$period,'depreciation_cents'=>0]+$value[$side];
                $out[$id]=['original_sha256'=>BalanceSegmentCalculation::hash($item),'tax_review'=>null,'schedule'=>$tax,'valuation_sha256'=>$value['sha256']];
                $proof[$id]=['original_plan_sha256'=>BalanceSegmentCalculation::hash($original),'cost'=>$derived['proof']];
                if($side==='book')$proof[$id]['cost_side']='book';
                foreach($totals as $field=>$_)$totals[$field]+=$tax[$field]??0;continue;
            }
            $sphere=$item['sphere_change']??null;
            self::require(!isset($old['sphere_change'])||$sphere!==null,'Bisherigen Bereichswechsel unverändert weiterführen.');
            if($sphere!==null){
                self::require(!$wholeTaxable&&in_array($section,['books','subsidiary'],true)&&$review===null&&$disposal===null,'Steuerlichen Bereichswechsel mit Kapitalüberleitung und Anlagenspiegel abstimmen.');
                self::require(BalancePeriodSphereChange::build($original,$period,$sphere['changes'],$old['sphere_change']??null)===$sphere,'Unveränderten unmittelbaren Bereichsvortrag verwenden.');
                BalancePeriodSphereTax::item($item);
                self::require(($old['tax_review']??null)===null,'Frühere abweichende Steuerpläne gesondert abstimmen.');
            }
            self::require($original['acquired_on']>=$period['start']||$old!==null,'Vorgetragene Steuerwerte der Anlage fehlen.');
            if($review!==null){
                self::require(is_array($review)&&array_keys($review)===['months','depreciation_account','reason','confirmed']
                    &&is_int($review['months'])&&$review['months']>=12&&$review['months']<=1200&&$review['months']%12===0&&$review['months']!==$original['months']
                    &&$review['confirmed']===true&&is_string($review['reason'])&&trim($review['reason'])!==''&&mb_strlen($review['reason'])<=1400&&!preg_match('/[\x00-\x1f]/',$review['reason'])
                    &&in_array($original['sphere'],$wholeTaxable?['1','2','3','4']:['4'],true)&&($original['method']??'linear_monthly')==='linear_monthly'&&!isset($original['gwg'])&&!isset($original['degressive']),
                    'Abweichende lineare Steuer-Nutzungsdauer im Geschäftsbetrieb vollständig bestätigen.');
                $dep=$review['depreciation_account'];$code=AssetOriginalDepreciation::code($original,false);
                self::require($code!==null&&is_string($dep)&&($chart[$dep]['group']??null)==='expense'&&$chart[$dep]['skr_code']===$code,'Ursprüngliches Abschreibungskonto für die Steuerwerte angeben.');
                $taxPlan['months']=$review['months'];$managed[$dep]=true;
                if(($disposal['kind']??null)==='partial_scrap'){
                    $values=AssetPartialDisposal::periodValuation($original,$disposal,$period,['months'=>$review['months'],'reason'=>$review['reason'],'confirmed'=>true]);
                    self::require($values['book']===$item['schedule'],'Originalwerte des Teilabgangs wurden verändert.');$tax=$values['tax'];
                }else $tax=BalancePeriodDisposal::calculate($taxPlan,$period,$old['schedule']??null,$disposal);
            }else $tax=$item['schedule'];
            if($old!==null){
                self::require(($old['tax_review']??null)===$review,'Steuerlichen Anlagenplan des Vorzeitraums unverändert fortführen; Änderungen benötigen eine Berichtigung.');
                foreach(['cost','accumulated','book'] as $f)self::require($tax[$f.'_opening_cents']===$old['schedule'][$f.'_closing_cents'],'Steuerliche Anfangswerte und Vorzeitraum stimmen nicht überein.');
            }
            $book=array_replace(array_fill_keys(BalancePeriodAssetAllocation::FIELDS,0),$item['schedule']);$tax=array_replace(array_fill_keys(BalancePeriodAssetAllocation::FIELDS,0),$tax);
            BalancePeriodDisposal::reconcile($book);BalancePeriodDisposal::reconcile($tax);$managed[$fixed]=true;
            $difference=['opening_cents'=>$tax['book_opening_cents']-$book['book_opening_cents'],'depreciation_cents'=>$tax['depreciation_cents']-$book['depreciation_cents'],
                'disposal_cents'=>$tax['book_disposals_cents']-$book['book_disposals_cents'],'closing_cents'=>$tax['book_closing_cents']-$book['book_closing_cents']];
            self::require($difference['opening_cents']-$difference['depreciation_cents']-$difference['disposal_cents']===$difference['closing_cents'],'Steuerliche Anlagenbewegungen stimmen nicht überein.');
            $opening[$fixed]=($opening[$fixed]??0)+$difference['opening_cents'];
            if($difference['depreciation_cents'])$current[]=['id'=>'asset-tax-'.substr(hash('sha256',$id),0,24),'reason'=>'Steuerliche Abschreibung: '.$review['reason'],'lines'=>self::pair($fixed,$review['depreciation_account'],-$difference['depreciation_cents'],$original['sphere'])];
            $saleEvidence=null;
            if($disposal!==null&&BalancePeriod::contains($period,$disposal['date'])){
                foreach($item['disposal_accounts'] as $key)$managed[$key]=true;
                if($disposal['kind']==='sale'){
                    $bookSale=BalancePeriodSale::values($disposal,$book['book_disposals_cents']);$taxSale=BalancePeriodSale::values($disposal,$tax['book_disposals_cents']);
                    self::require(($item['sale_values']??null)===$bookSale,'Verkaufsrechnung und ursprünglicher Restbuchwert stimmen nicht überein.');
                    $net=[$fixed=>$bookSale['book_cents']-$taxSale['book_cents']];
                    $bookKey=$item['disposal_accounts'][$bookSale['book_code']]??null;
                    self::require(is_string($bookKey)&&($chart[$bookKey]['skr_code']??null)===$bookSale['book_code']&&$chart[$bookKey]['group']===$bookSale['group'],'Ursprüngliches Restbuchwertkonto des Verkaufs nachweisen.');
                    $retained=[$bookSale['book_code']=>$bookKey,$bookSale['sale_code']=>BalancePeriodSale::postingAccount($report,$item)];
                    foreach([$taxSale['book_code'],$taxSale['sale_code']] as $code)if(!isset($retained[$code])){
                        // A changed tax gain/loss branch has no book posting. Keep it in
                        // a distinct tax-only account; never guess between book accounts.
                        $catalog=TaxCatalog::accounts($report['year'])[$code]??null;
                        self::require((bool)($catalog['active']??false),'Jahresbezogenes Steuerkonto des Anlagenverkaufs fehlt.');
                        $key='tax_period_disposal_'.$code;
                        self::require(!isset($report['accounts'][$key]),'Steuerliches Gegenkonto kollidiert mit einem Originalkonto.');
                        $added[$key]=['name'=>'Steuerlicher Anlagenverkauf · '.$code,'group'=>$taxSale['group'],'skr_code'=>$code];
                        $chart[$key]=$added[$key];$retained[$code]=$key;
                    }
                    foreach($retained as $key)$managed[$key]=true;
                    foreach([[$bookSale['book_code'],-$bookSale['book_cents']],[$taxSale['book_code'],$taxSale['book_cents']],[$bookSale['sale_code'],$bookSale['net_cents']],[$taxSale['sale_code'],-$taxSale['net_cents']]] as [$code,$amount]){
                        self::require(is_string($retained[$code]??null),'Steuerlichen Verkauf über die nachgewiesenen Gegenkonten ausweisen.');$key=$retained[$code];$net[$key]=($net[$key]??0)+$amount;
                    }
                    $lines=[];foreach($net as $key=>$amount)if($amount)$lines[]=['account'=>$key,'debit_cents'=>max(0,$amount),'credit_cents'=>max(0,-$amount),'sphere'=>$key===$fixed?'':$original['sphere']];
                    if($lines)$current[]=['id'=>'asset-sale-'.substr(hash('sha256',$id),0,24),'reason'=>'Steuerlicher Anlagenverkauf: '.$review['reason'],'lines'=>BalanceCalculation::lines($lines,$chart)];
                    $saleEvidence=['book'=>$bookSale,'tax'=>$taxSale,'accounts'=>$retained];
                }elseif($difference['disposal_cents']){
                    $code=$disposal['kind']==='software_end'?'68960':'68950';$key=$item['disposal_accounts'][$code]??null;
                    self::require(is_string($key)&&($chart[$key]['skr_code']??null)===$code&&$chart[$key]['group']==='expense','Ursprüngliches Restbuchwertkonto erforderlich.');
                    $current[]=['id'=>'asset-disposal-'.substr(hash('sha256',$id),0,24),'reason'=>'Steuerlicher Restbuchwert: '.$review['reason'],'lines'=>self::pair($fixed,$key,-$difference['disposal_cents'],$original['sphere'])];
                }
            }
            // The original plan is retained alongside, not replaced by a synthetic tax acquisition.
            $out[$id]=['original_sha256'=>BalanceSegmentCalculation::hash($item),'tax_review'=>$review,'schedule'=>$tax];
            $proof[$id]=['original_plan_sha256'=>BalanceSegmentCalculation::hash($original),'tax_review'=>$review,'difference'=>$difference,'sale'=>$saleEvidence];
            if($sphere!==null){$out[$id]['sphere_change']=$sphere;$proof[$id]['sphere_change_sha256']=$sphere['sha256'];}
            foreach($totals as $field=>$_)$totals[$field]+=$tax[$field]??0;
        }
        if($previous!==null)self::require(!array_diff_key($previous['tax_schedule']['items'],$out),'Historische Steuer-Anlage nicht aus dem Folgestand entfernen.');
        return ['period'=>$period,'items'=>$proof,'desired_opening'=>$opening,'generated_current'=>$current,'managed_accounts'=>array_keys($managed),'additional_accounts'=>$added,'tax_schedule'=>['period'=>$period,'items'=>$out,'totals'=>$totals]];
    }
}
