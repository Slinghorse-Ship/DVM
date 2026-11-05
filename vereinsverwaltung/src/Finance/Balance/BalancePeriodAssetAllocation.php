<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once __DIR__.'/BalancePeriodDisposal.php';
require_once __DIR__.'/BalanceAssetAllocation.php';
require_once __DIR__.'/BalancePeriodSale.php';
require_once __DIR__.'/BalancePeriodAssetUse.php';
require_once __DIR__.'/BalancePeriodSphereAllocation.php';

/** Analytical use shares of verified WHOLE assets, never additional assets or GWG elections. */
final class BalancePeriodAssetAllocation
{
    private const PLAN=['basis_cents','residual_cents','months','acquired_on','account','sphere','method','gwg','degressive','depreciation_code'];
    public const FIELDS=[...BalanceAssetAllocation::FIELDS,...BalanceAssetAllocationDisposal::FIELDS];
    private static function plan(array $p):array
    {return array_replace(array_fill_keys(self::PLAN,null),['method'=>'linear_monthly'],array_intersect_key($p,array_flip(self::PLAN)));}
    private static function amounts(array $s):array
    {$fields=self::FIELDS;if(array_intersect(['impairment_cents','writeup_cents'],array_keys($s)))$fields=[...$fields,'impairment_cents','writeup_cents'];return array_replace(array_fill_keys($fields,0),array_intersect_key($s,array_flip($fields)));}
    public static function flat(array $item,array $period):array
    {
        $p=$item['plan'];$s=self::amounts($item['schedule']);
        $flat=$s+['item_id'=>$item['item_id'],'name'=>$item['name'],'account'=>$item['fixed_account'],'skr_code'=>$p['account'],'sphere'=>$p['sphere'],
            'expected_depreciation_cents'=>$s['depreciation_cents'],'method'=>$p['method']??'linear_monthly'];
        if(($p['method']??null)==='cost')return $flat+['cost_plan'=>$p,'valuation'=>$item['valuation'],'valuation_accounts'=>$item['valuation_accounts']];
        foreach(['acquired_on','months','residual_cents','gwg','degressive','depreciation_code'] as $k)if(array_key_exists($k,$p))$flat[$k]=$p[$k];
        if(isset($item['disposal'])){
            $flat['disposal']=$item['disposal'];$flat['disposal_hash']=BalanceSegmentCalculation::hash($item['disposal']);$flat['basis_cents']=$p['basis_cents'];
            if($item['disposal']['kind']==='partial_scrap')$flat['partial_plan']=$p;
        }
        return $flat;
    }
    /** Bridge the actual historic schedule and already reviewed use shares, without rewriting it. */
    public static function calendar(array $report,?array $allocation):?array
    {
        if(empty($report['asset_schedule']['items']))return null;
        $shares=$allocation['asset_shares']??[];$schedules=$allocation['asset_schedules']??[];$items=[];
        foreach($report['asset_schedule']['items'] as $id=>$item){
            if($allocation!==null&&!isset($shares[$id]))throw new DomainException('Nutzung der Anlage im Kalender-Vorjahr zuerst vollständig aufteilen.');
            BalanceAssetAllocation::checkYear($item,$report['year']);
            if(($item['method']??null)==='cost'){
                if($allocation!==null){$parts=BalanceAssetAllocation::item($item,$shares[$id]['weights'],$report['year']);foreach($parts as $u=>$v)foreach($v as $f=>$n)if(($schedules[$u]['items'][$id][$f]??null)!==$n)throw new DomainException('Originale Kostenanteile des Kalenderjahres unverändert vortragen.');}
                $value=$item['valuation'];
                $items[$id]=['item_id'=>$id,'name'=>$item['name']??$item['receipt_number']??$id,'fixed_account'=>$item['account'],
                    'plan'=>$item['cost_plan'],'schedule'=>['period'=>BalancePeriod::calendar($report['year']),'depreciation_cents'=>0]+$value['book'],
                    'valuation'=>$value,'valuation_accounts'=>$item['valuation_accounts']];
                continue;
            }
            $p=['basis_cents'=>$item['basis_cents']??$item['cost_closing_cents'],'account'=>$item['skr_code'],'sphere'=>$item['sphere'],'method'=>$item['method']??'linear_monthly'];
            foreach(['acquired_on','months','residual_cents','gwg','degressive','depreciation_code'] as $k)if(array_key_exists($k,$item))$p[$k]=$item[$k];
            $p=$item['partial_plan']??$p;
            $schedule=null;
            for($year=TaxCatalog::documentYear($p['acquired_on']);$year<=$report['year'];$year++){
                $disposal=isset($item['disposal'])&&$item['disposal']['date']<=$year.'-12-31'?$item['disposal']:null;
                $schedule=BalancePeriodDisposal::calculate($p,BalancePeriod::calendar($year),$schedule,$disposal);
            }
            if($schedule===null||self::amounts($schedule)!==self::amounts($item))throw new DomainException('Kalender-Anlagenplan und ursprüngliche Werte stimmen nicht überein.');
            $items[$id]=['item_id'=>$id,'name'=>$item['name']??$item['receipt_number']??$id,'fixed_account'=>$item['account'],'plan'=>$p,'schedule'=>$schedule];
            if(isset($item['disposal']))$items[$id]['disposal']=$item['disposal'];
        }
        return ['period'=>BalancePeriod::calendar($report['year']),'items'=>$items,'shares'=>$shares,'schedules'=>$schedules,'issues'=>[]];
    }
    public static function build(array $report,array $units,array $statements,array $shares,?array $previous=null,bool $verifyStatements=true):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($report,$units,$statements,$shares,$previous,$verifyStatements));
    }
    private static function buildFromSources(array $report,array $units,array $statements,array $shares,?array $previous=null,bool $verifyStatements=true):array
    {
        $period=BalancePeriod::check($report['period']);$schedule=$report['asset_schedule'];$items=$schedule['items'];
        if(($schedule['period']??null)!==$period||($schedule['issues']??null)!==[]||!is_array($items)||count($items)>10000||array_diff_key($shares,$items))throw new DomainException('Bestätigtes Anlagenverzeichnis dieses Zeitraums nachweisen.');
        if($previous!==null&&!BalancePeriod::follows($previous['period'],$period))throw new DomainException('Nutzung aus dem unmittelbaren Anlagen-Vorzeitraum übernehmen.');
        if($previous!==null&&array_diff_key($previous['items'],$items))throw new DomainException('Historische Anlagen nicht aus dem Nutzungsnachweis entfernen.');
        $out=[];$normalized=[];$issues=[];$accountTotals=[];$depreciation=[];$disposals=[];$movements=[];$valuationCodes=[];
        $fields=self::FIELDS;foreach($items as $item)if(($item['plan']['method']??null)==='cost'){$fields=[...$fields,'impairment_cents','writeup_cents'];break;}
        foreach($units as $u=>$_)$out[$u]=['items'=>[],'totals'=>array_fill_keys($fields,0)];
        foreach($items as $id=>$item){
            if(($item['item_id']??null)!==$id||($item['posted']??null)!==true||!is_array($item['plan']??null)||!is_array($item['schedule']??null))throw new DomainException('Ursprünglichen gebuchten Anlagenplan nachweisen.');
            $p=$item['plan'];$old=$previous['items'][$id]??null;$cost=($p['method']??null)==='cost';
            if($p['acquired_on']<$period['start']&&$old===null)throw new DomainException('Bestätigte Anlagennutzung des Vorzeitraums fehlt.');
            if($old!==null&&(($cost?$old['plan']!==$p:self::plan($old['plan'])!==self::plan($p))||$old['fixed_account']!==$item['fixed_account']))throw new DomainException('Ursprünglichen Anlagenplan unverändert vortragen.');
            if(isset($old['disposal'])&&($item['disposal']??null)!==$old['disposal'])throw new DomainException('Bestätigten Anlagenabgang unverändert fortführen.');
            if($cost){
                $v=$item['valuation']??null;$proof=$v['source']??null;
                if(!is_array($proof)||AssetValuation::fromBookPlan($p)!==$proof['plan']||$proof['period']!==$period||$proof['previous']!==($old['valuation']??null)
                    ||isset($item['disposal'])||isset($item['sphere_allocation'])||isset($item['sphere_change'])||AssetValuation::calculate($proof['plan'],$period,$proof['events'],$proof['previous'])!==$v)
                    throw new DomainException('Unveränderte Kostenbewertung und Originalvortrag dieses Zeitraums nachweisen.');
                $expected=['period'=>$period,'depreciation_cents'=>0]+$v['book'];$sphereProof=null;
            }else{$expected=BalancePeriodDisposal::calculate($p,$period,$old['schedule']??null,$item['disposal']??null,$item['day_allocation_policy']??null);$sphereProof=BalancePeriodSphereAllocation::proof($item,$period,$old);}
            if($expected!==$item['schedule'])throw new DomainException('Anlagenwerte stimmen nicht mit Plan und Vorzeitraum überein.');
            $s=self::amounts($expected);if($cost)AssetValuation::reconcile(array_intersect_key($s,array_flip(AssetValuation::FIELDS)));else BalancePeriodDisposal::reconcile($s);$fixed=$item['fixed_account'];
            if(($report['accounts'][$fixed]['group']??null)!=='fixed_assets'||$report['accounts'][$fixed]['skr_code']!==$p['account'])throw new DomainException('Ursprüngliches Anlagenkonto prüfen.');
            foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $f=>$v)$accountTotals[$fixed][$f]=($accountTotals[$fixed][$f]??0)+$s[$v];
            $share=$shares[$id]??null;if($share===null){$issues[]=$item['name'].': Nutzung einem Bereich zuordnen.';continue;}
            $hasChanges=is_array($share)&&array_key_exists('changes',$share);
            if($cost&&$hasChanges)throw new DomainException('Geänderte Nutzung der Kostenanlage benötigt einen eigenen geprüften Bewegungsnachweis.');
            if(!is_array($share)||array_keys($share)!==($hasChanges?['item_sha256','weights','measure','reason','changes']:['item_sha256','weights','measure','reason'])||$share['item_sha256']!==BalanceSegmentCalculation::hash($item)
                ||!is_array($share['weights'])||!$share['weights']||array_diff_key($share['weights'],$units)||!isset(BalanceAssetAllocation::MEASURES[$share['measure']])
                ||!is_string($share['reason'])||trim($share['reason'])===''||mb_strlen($share['reason'])>1000||preg_match('/[\x00-\x1f]/',$share['reason']))throw new DomainException('Nutzungsanteile mit unverändertem Originalgegenstand und Nachweis angeben.');
            $weights=$share['weights'];ksort($weights,SORT_STRING);
            foreach($weights as $u=>$_)if($units[$u]['sphere']!==($sphereProof['book']['opening_sphere']??$p['sphere']))throw new DomainException('Anlagennutzung und steuerlicher Bereich müssen übereinstimmen. Bereichswechsel benötigt einen eigenen Nachweis.');
            if($old!==null&&($weights!==BalancePeriodAssetUse::closingWeights($previous['shares'][$id])||$share['measure']!==$previous['shares'][$id]['measure']))throw new DomainException('Geänderte Anlagennutzung nicht als unveränderten Vortrag behandeln.');
            $changes=[];
            if($sphereProof!==null){
                if($hasChanges&&!is_array($share['changes']))throw new DomainException('Belegte Nutzungswechsel prüfen.');
                $use=BalancePeriodSphereAllocation::changes($share['changes']??[],$item,$period,$weights,$units,$sphereProof);$changes=$use['changes'];
                if($use['missing']){$issues[]=$item['name'].': Nutzung ab '.implode(', ',$use['missing']).' dem neuen Bereich zuordnen.';continue;}
                $split=BalancePeriodAssetUse::split($item,$period,$weights,$changes,$old['schedule']??null);$parts=$split['parts'];
                if($split['movements'])$movements[$id]=$split['movements'];
                BalancePeriodSphereAllocation::checkMovements($item,$period,$units,$split['movements']);
                foreach($sphereProof['book']['depreciation_by_sphere'] as $sphere=>$amount){$sum=0;foreach($parts as $u=>$v)if($units[$u]['sphere']===(string)$sphere)$sum+=$v['depreciation_cents'];if($sum!==$amount)throw new DomainException('Bereichsnutzung verändert die ursprüngliche Bereichsabschreibung.');}
            }elseif($hasChanges){
                if(!is_array($share['changes']))throw new DomainException('Belegte Nutzungswechsel prüfen.');
                $changes=BalancePeriodAssetUse::changes($share['changes'],$item,$period,$weights,$units);
                $split=BalancePeriodAssetUse::split($item,$period,$weights,$changes,$old['schedule']??null);$parts=$split['parts'];$movements[$id]=$split['movements'];
            }else $parts=BalanceAssetAllocation::item(self::flat($item,$period),$weights,$report['year'],$period);
            if($old!==null)foreach($units as $u=>$_)foreach(['cost','accumulated','book'] as $f)if(($previous['schedules'][$u]['items'][$id][$f.'_closing_cents']??0)!==($parts[$u][$f.'_opening_cents']??0))throw new DomainException('Gerundete Anlagenanteile des Vorzeitraums unverändert übernehmen.');
            $normalized[$id]=['item_sha256'=>$share['item_sha256'],'weights'=>$weights,'measure'=>$share['measure'],'reason'=>trim($share['reason'])];
            if($hasChanges)$normalized[$id]['changes']=$changes;
            foreach($parts as $u=>$values){$values=array_replace(array_fill_keys($fields,0),$values);$out[$u]['items'][$id]=$values;foreach($fields as $f)$out[$u]['totals'][$f]+=$values[$f];
                if($hasChanges)foreach(BalancePeriodAssetUse::TRANSFERS as $f)$out[$u]['totals'][$f]=($out[$u]['totals'][$f]??0)+$values[$f];
                if(!$cost){$code=AssetOriginalDepreciation::code($p);if($code===null)throw new DomainException('Abschreibungsart fehlt.');$depreciation[$u][$code]=($depreciation[$u][$code]??0)+$values['depreciation_cents'];}}
            if($cost){$movement=BalanceCostAssetAllocation::movements(self::flat($item,$period),$report['accounts'],$weights);$valuationCodes+=$movement['codes'];foreach($movement['amounts'] as $u=>$amounts)foreach($amounts as $key=>$n)$disposals[$u][$key]=($disposals[$u][$key]??0)+$n;}
            if(isset($item['disposal'])&&BalancePeriod::contains($period,$item['disposal']['date'])){
                $sale=$item['sale_values']??null;$code=$sale?$sale['book_code']:($item['disposal']['kind']==='software_end'?'68960':'68950');$key=$item['disposal_accounts'][$code]??null;
                if(!is_string($key)||($report['accounts'][$key]['skr_code']??null)!==$code)throw new DomainException('Bestätigtes Restbuchwertkonto des Abgangs fehlt.');
                foreach($parts as $u=>$v)$disposals[$u][$key]=($disposals[$u][$key]??0)+(($sale['gain']??false)?-1:1)*$v['book_disposals_cents'];
                if($sale){$saleKey=BalancePeriodSale::postingAccount($report,$item);
                    foreach(BalanceAssetAllocation::cents($sale['net_cents'],BalancePeriodAssetUse::closingWeights($normalized[$id])) as $u=>$n)$disposals[$u][$saleKey]=($disposals[$u][$saleKey]??0)+($sale['gain']?1:-1)*$n;}
            }
        }
        foreach($report['calculation']['accounts'] as $key=>$row)if($row['group']==='fixed_assets')foreach(['opening','closing'] as $f)if(($accountTotals[$key][$f]??0)!==$row[$f.'_cents'])throw new DomainException('Originalanlagen und Vereinsjournal stimmen nicht überein.');
        if(!$issues&&$verifyStatements)foreach($out as $u=>$values){$totals=[];foreach($values['items'] as $id=>$v)foreach(['opening'=>'book_opening_cents','closing'=>'book_closing_cents'] as $f=>$field){$key=$items[$id]['fixed_account'];$totals[$key][$f]=($totals[$key][$f]??0)+$v[$field];}
            foreach($statements[$u]['accounts']??[] as $key=>$row){
                if($row['group']==='fixed_assets')foreach(['opening','closing'] as $f)if(($totals[$key][$f]??0)!==$row[$f.'_cents'])$issues[]=$units[$u]['name'].': Anlagenwerte und Belegaufteilung abstimmen.';
                if((isset($valuationCodes[$row['skr_code']])||in_array($row['skr_code'],['48550','68950','68960','48450','48490','68850','68890','48440','48480','68840','68880'],true))&&$row['amount_cents']!==($disposals[$u][$key]??0))$issues[]=$units[$u]['name'].': Anlagenbewertung oder Restbuchwert und Belegaufteilung abstimmen.';
            }
            foreach(['62000','62050','62200','62210','62600','62220','62620'] as $code){$actual=0;foreach($statements[$u]['accounts']??[] as $row)if($row['group']==='expense'&&$row['skr_code']===$code)$actual+=$row['closing_cents'];if($actual!==($depreciation[$u][$code]??0))$issues[]=$units[$u]['name'].': Abschreibung und Nutzung abstimmen.';}
        }
        ksort($normalized,SORT_STRING);foreach($out as &$v)ksort($v['items'],SORT_STRING);unset($v);
        $result=['period'=>$period,'items'=>$items,'shares'=>$normalized,'schedules'=>$out,'issues'=>array_values(array_unique($issues))];
        if($movements)$result['movements']=$movements;return $result;
    }

    /** Replay a proved use change into analytical balance sheets, never the club journal. */
    public static function statement(array $source,array $allocation,array $clearing,array $shares,?array $previous=null):array
    {
        $changes=false;foreach($shares as $share)if(isset($share['changes'])){$changes=true;break;}
        $movements=$changes?self::build($source,$allocation['units'],[],$shares,$previous,false)['movements']??[]:[];
        return BalanceClearingStatement::build($source,$allocation,$clearing,$movements);
    }
}
