<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetBooks.php';
require_once __DIR__.'/BalanceAssetAllocationDisposal.php';
require_once __DIR__.'/BalanceCostAssetAllocation.php';

/** Internal analytical shares, not new assets or a tax-ownership election. */
final class BalanceAssetAllocation
{
    public const FIELDS=['cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents'];
    public const MEASURES=['hours'=>'Nutzungsstunden','days'=>'Nutzungstage','uses'=>'Nutzungen','area'=>'Flächeneinheiten'];

    /** Always validate the whole original asset, never a usage fraction as a new GWG. */
    private static function plan(array $item):array
    {
        $method=$item['method']??'linear_monthly';
        if(!in_array($method,['linear_monthly','gwg_immediate','degressive'],true)
            ||($method!=='gwg_immediate'&&array_key_exists('gwg',$item))||($method!=='degressive'&&array_key_exists('degressive',$item))
            ||(!in_array($item['skr_code']??null,AssetTypes::codes($method==='gwg_immediate'?'gwg':'linear'),true)&&!(($item['depreciation_code']??null)==='62620'&&AssetOriginalDepreciation::code($item)==='62620')))
            throw new DomainException('Nutzungsaufteilung: ursprünglichen Abschreibungsplan prüfen.');
        $plan=['basis_cents'=>array_key_exists('disposal',$item)?($item['basis_cents']??null):($item['cost_closing_cents']??null),'account'=>$item['skr_code']??null,'method'=>$method];
        foreach(['acquired_on','months','residual_cents','gwg','degressive','depreciation_code','original_leasing'] as $key)if(array_key_exists($key,$item))$plan[$key]=$item[$key];
        if(!is_string($plan['acquired_on']??null)||TaxCatalog::documentYear($plan['acquired_on'])<1900||TaxCatalog::documentYear($plan['acquired_on'])>2200)throw new DomainException('Anschaffungsdatum der Anlage prüfen.');
        // The original asset's eligibility and limits must hold before any splitting.
        AssetBooks::schedule($plan,TaxCatalog::documentYear($plan['acquired_on']));
        BalanceAssetAllocationDisposal::proof($item,$plan);
        return $plan;
    }

    public static function checkYear(array $item,int $year):void
    {
        if(($item['method']??null)==='cost'){
            $plan=$item['cost_plan']??null;$value=$item['valuation']??null;$source=$value['source']??null;
            if(!is_array($plan)||!is_array($source)||AssetValuation::fromBookPlan($plan)!==$source['plan']||$source['period']!==BalancePeriod::calendar($year)
                ||AssetValuation::calculate($source['plan'],$source['period'],$source['events'],$source['previous'])!==$value
                ||($item['depreciation_cents']??null)!==0||($item['expected_depreciation_cents']??null)!==0
                ||($item['skr_code']??null)!==$plan['account']||($item['sphere']??null)!==$plan['sphere'])
                throw new DomainException('Ursprünglichen Kostenbewertungsplan und unveränderte Jahreswerte nachweisen.');
            foreach(AssetValuation::FIELDS as $field)if(($item[$field]??null)!==$value['book'][$field])throw new DomainException('Kostenbewertung und Anlagenverzeichnis stimmen nicht überein.');
            return;
        }
        $expected=BalanceAssetAllocationDisposal::annual($item,self::plan($item),$year);
        $expected['expected_depreciation_cents']=$expected['depreciation_cents'];
        foreach($expected as $key=>$value)if(($item[$key]??null)!==$value)throw new DomainException('Jahreswerte stimmen nicht mit dem ursprünglichen Abschreibungsplan überein.');
    }

    /** Monotone highest-average apportionment. Never split each annual movement independently. */
    public static function cents(int $total,array $weights):array
    {
        if($total<0||$total>BalanceCalculation::LIMIT||!$weights||count($weights)>32)throw new DomainException('Betrag und Nutzungsanteile prüfen.');
        ksort($weights,SORT_STRING);$sum=0;
        foreach($weights as $key=>$w){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$key)||!is_int($w)||$w<1||$w>1000000)throw new DomainException('Nutzungsanteile als positive ganze Einheiten bis 1.000.000 angeben.');
            $sum+=$w;
        }
        $out=[];foreach($weights as $key=>$w)$out[$key]=intdiv($total*$w,$sum);
        $remaining=$total-array_sum($out);
        while($remaining-->0){
            $winner=array_key_first($weights);
            foreach($weights as $key=>$w)if($w*($out[$winner]+1)>$weights[$winner]*($out[$key]+1))$winner=$key;
            ++$out[$winner];
        }
        return $out;
    }

    public static function item(array $item,array $weights,?int $year=null,?array $period=null):array
    {
        if(($item['method']??null)==='cost'){
            if($year!==null&&$period===null)self::checkYear($item,$year);
            $value=$item['valuation']??null;$plan=$item['cost_plan']??null;
            if(!is_array($value)||!is_array($plan)||AssetValuation::fromBookPlan($plan)!==($value['source']['plan']??null)
                ||($period!==null&&$period!==($value['source']['period']??null))||($item['depreciation_cents']??null)!==0
                ||($item['expected_depreciation_cents']??null)!==0||($item['skr_code']??null)!==$plan['account']||($item['sphere']??null)!==$plan['sphere'])
                throw new DomainException('Originale Kostenanlage vor der Nutzung nachweisen.');
            foreach(AssetValuation::FIELDS as $f)if(($item[$f]??null)!==$value['book'][$f])throw new DomainException('Originale Bewertungsbewegungen prüfen.');
            return BalanceCostAssetAllocation::split($value,$weights)['parts'];
        }
        if(array_intersect(['impairment_cents','writeup_cents'],array_keys($item)))throw new DomainException('Kostenbewertungsbewegungen benötigen die originale Kostenanlage.');
        $base=[];$fields=BalanceAssetAllocationDisposal::fields($item);
        foreach($fields as $field){$v=$item[$field]??null;if(!is_int($v)||$v<0||$v>BalanceCalculation::LIMIT)throw new DomainException('Vollständige Anlagenwerte in ganzen Cent erforderlich.');$base[$field]=$v;}
        if($base['cost_opening_cents']+$base['additions_cents']-($base['disposals_cents']??0)!==$base['cost_closing_cents']
            ||$base['accumulated_opening_cents']+$base['depreciation_cents']-($base['accumulated_disposals_cents']??0)!==$base['accumulated_closing_cents']
            ||($base['disposals_cents']??0)-($base['accumulated_disposals_cents']??0)!==($base['book_disposals_cents']??0)
            ||$base['cost_opening_cents']-$base['accumulated_opening_cents']!==$base['book_opening_cents']
            ||$base['cost_closing_cents']-$base['accumulated_closing_cents']!==$base['book_closing_cents']
            ||($item['expected_depreciation_cents']??null)!==$base['depreciation_cents'])throw new DomainException('Anlagenbewegungen vor der Aufteilung abstimmen.');
        self::plan($item);
        if(($item['disposal']['kind']??null)==='partial_scrap'){
            if($year===null)throw new DomainException('Jahr für den belegten Teilabgang erforderlich.');
            return BalanceAssetAllocationDisposal::partial($item,$weights,$year,$period);
        }
        $split=[];foreach(['cost_opening_cents','cost_closing_cents','accumulated_opening_cents','accumulated_closing_cents'] as $f)$split[$f]=self::cents($base[$f],$weights);
        $costBefore=self::cents($base['cost_opening_cents']+$base['additions_cents'],$weights);
        $depBefore=self::cents($base['accumulated_opening_cents']+$base['depreciation_cents'],$weights);
        $out=[];
        foreach($split['cost_closing_cents'] as $unit=>$unused){
            $v=['cost_opening_cents'=>$split['cost_opening_cents'][$unit],
                'additions_cents'=>$costBefore[$unit]-$split['cost_opening_cents'][$unit],
                'cost_closing_cents'=>$split['cost_closing_cents'][$unit],
                'accumulated_opening_cents'=>$split['accumulated_opening_cents'][$unit],
                'depreciation_cents'=>$depBefore[$unit]-$split['accumulated_opening_cents'][$unit],
                'accumulated_closing_cents'=>$split['accumulated_closing_cents'][$unit],
                'book_opening_cents'=>$split['cost_opening_cents'][$unit]-$split['accumulated_opening_cents'][$unit],
                'book_closing_cents'=>$split['cost_closing_cents'][$unit]-$split['accumulated_closing_cents'][$unit]];
            if(count($fields)===11){$v['disposals_cents']=$costBefore[$unit]-$v['cost_closing_cents'];$v['accumulated_disposals_cents']=$depBefore[$unit]-$v['accumulated_closing_cents'];$v['book_disposals_cents']=$v['disposals_cents']-$v['accumulated_disposals_cents'];}
            if(min($v)<0)throw new DomainException('Negativer Anlagenanteil. Bewegungen gesondert prüfen.');
            $out[$unit]=$v;
        }
        foreach($fields as $f)if(array_sum(array_column($out,$f))!==$base[$f])throw new DomainException('Anlagenanteile ergeben nicht den Originalwert.');
        return $out;
    }

    public static function build(array $report,array $units,array $segments,array $shares):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($report,$units,$segments,$shares));
    }
    private static function buildFromSources(array $report,array $units,array $segments,array $shares):array
    {
        $schedule=$report['asset_schedule']??[];$items=$schedule['items']??[];
        if(!$shares||!is_array($items)||!$items||($schedule['year']??null)!==$report['year']||($schedule['issues']??null)!==[]||count($items)>10000||array_diff_key($shares,$items))throw new DomainException('Anlagenverzeichnis und zugehörige Gegenstände prüfen.');
        $fields=BalanceAssetAllocationDisposal::fields($schedule['totals']??[]);
        $normalized=[];$out=[];$issues=[];$all=array_fill_keys($fields,0);$accountTotals=[];$disposalMovements=[];$disposalCodes=[];
        foreach($units as $unit=>$_)$out[$unit]=['items'=>[],'totals'=>array_fill_keys($fields,0)];
        foreach($items as $id=>$item){
            if(!is_string($id)||$id===''||strlen($id)>160||preg_match('/[\x00-\x1f]/',$id)||!is_array($item)||($item['item_id']??null)!==$id||($report['accounts'][$item['account']??'']['group']??null)!=='fixed_assets'||($report['accounts'][$item['account']]['skr_code']??null)!==($item['skr_code']??null))throw new DomainException('Originalgegenstand und Anlagenkonto prüfen.');
            // Validate every original item, even when its allocation is still missing.
            self::item($item,['validation'=>1],$report['year']);
            self::checkYear($item,$report['year']);
            $itemFields=BalanceAssetAllocationDisposal::fields($item);
            if($itemFields!==$fields&&!(count($fields)===13&&array_diff($itemFields,$fields)===[]))throw new DomainException('Einheitliche Anlagenbewegungen erforderlich.');
            foreach($fields as $f)$all[$f]+=$item[$f]??0;
            foreach(['opening_cents'=>'book_opening_cents','closing_cents'=>'book_closing_cents'] as $field=>$f)$accountTotals[$item['account']][$field]=($accountTotals[$item['account']][$field]??0)+$item[$f];
            $share=$shares[$id]??null;
            if($share===null){$issues[]='Gegenstand '.($item['name']??$id).': Betrieb oder Nutzungsanteile angeben.';continue;}
            if(!is_array($share)||array_keys($share)!==['item_sha256','weights','measure','reason']||($share['item_sha256']??null)!==BalanceSegmentCalculation::hash($item)
                ||!is_array($share['weights']??null)||!$share['weights']||array_diff_key($share['weights'],$units)
                ||!is_string($share['measure']??null)||!isset(self::MEASURES[$share['measure']])||!is_string($share['reason']??null)||trim($share['reason'])===''||mb_strlen($share['reason'])>1000||preg_match('/[\x00-\x1f]/',$share['reason']))throw new DomainException('Unveränderten Gegenstand, Nutzungsmaßstab und Nachweis angeben.');
            $weights=$share['weights'];ksort($weights,SORT_STRING);
            foreach($weights as $unit=>$_)if($units[$unit]['sphere']!==$item['sphere'])throw new DomainException('Die Nutzungsaufteilung darf den steuerlichen Vereinsbereich nicht ändern. Bereichsübergreifende Nutzung gesondert klären.');
            $parts=self::item($item,$weights,$report['year']);
            if(($item['method']??null)==='cost'){
                $movement=BalanceCostAssetAllocation::movements($item,$report['accounts'],$weights);$disposalCodes+=$movement['codes'];$amountsByUnit=$movement['amounts'];
            }else{
                if(isset($item['disposal']))foreach(BalanceDisposalAccounts::codes($item['disposal']) as $code=>$_)$disposalCodes[$code]=true;
                $amountsByUnit=BalanceAssetAllocationDisposal::movements($item,$report['accounts'],$weights,$parts,$report['year']);
            }
            foreach($amountsByUnit as $u=>$amounts)foreach($amounts as $key=>$n)$disposalMovements[$u][$key]=($disposalMovements[$u][$key]??0)+$n;
            $normalized[$id]=['item_sha256'=>$share['item_sha256'],'weights'=>$weights,'measure'=>$share['measure'],'reason'=>trim($share['reason'])];
            foreach($parts as $unit=>$v){$v=array_replace(array_fill_keys($fields,0),$v);$out[$unit]['items'][$id]=$v;foreach($fields as $f)$out[$unit]['totals'][$f]+=$v[$f];}
        }
        foreach($fields as $f)if(($schedule['totals'][$f]??null)!==$all[$f])throw new DomainException('Originalsummen des Anlagenverzeichnisses stimmen nicht.');
        foreach($report['calculation']['accounts'] as $account=>$r)if($r['group']==='fixed_assets')foreach(['opening_cents','closing_cents'] as $f)if(($accountTotals[$account][$f]??0)!==$r[$f])throw new DomainException('Originalanlagen und Gesamtjournal stimmen nicht überein.');
        if(!$issues)foreach($out as $unit=>$s){
            $calc=$segments[$unit]['calculation']??null;if(!$calc)continue;
            $totals=[];$dep=[];
            foreach($s['items'] as $id=>$v){$item=$items[$id];$key=$item['account'];
                foreach(['opening_cents'=>'book_opening_cents','closing_cents'=>'book_closing_cents'] as $f=>$source)$totals[$key][$f]=($totals[$key][$f]??0)+$v[$source];
                if(($item['method']??null)!=='cost'){$code=AssetOriginalDepreciation::code($item);if($code===null)throw new DomainException('Abschreibungsart fehlt.');$dep[$code]=($dep[$code]??0)+$v['depreciation_cents'];}
            }
            foreach($calc['accounts'] as $key=>$row){
                if($row['group']==='fixed_assets')foreach(['opening_cents','closing_cents'] as $f)if(($totals[$key][$f]??0)!==$row[$f])$issues[]=$units[$unit]['name'].': Anlagenwerte und Buchungsaufteilung abstimmen.';
                if(isset($disposalCodes[$row['skr_code']])&&($disposalMovements[$unit][$key]??0)!==$row['amount_cents'])$issues[]=$units[$unit]['name'].': Anlagenabgang und zugeordnete Erlöse oder Restwerte abstimmen.';
            }
            foreach(['62000','62050','62200','62210','62600','62220','62620'] as $code){$actual=0;foreach($calc['accounts'] as $row)if($row['group']==='expense'&&$row['skr_code']===$code)$actual+=$row['closing_cents'];
                if($actual!==($dep[$code]??0))$issues[]=$units[$unit]['name'].': Abschreibung und Nutzungsaufteilung abstimmen.';
            }
        }
        ksort($normalized,SORT_STRING);foreach($out as &$s)ksort($s['items'],SORT_STRING);unset($s);
        return ['shares'=>$normalized,'schedules'=>$out,'issues'=>array_values(array_unique($issues))];
    }
}
