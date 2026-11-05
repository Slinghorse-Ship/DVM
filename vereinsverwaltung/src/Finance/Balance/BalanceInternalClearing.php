<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once __DIR__.'/BalanceSegmentCalculation.php';

/** Source-bound workpaper for temporary internal advances. Never a posting or tax approval. */
final class BalanceInternalClearing
{
    private const SOURCE=['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation'];
    private const KEYS=['schema','kind','club_id','source','units','allocations','instructions','previous','cases','movements','totals','tax_export_ready'];

    private static function check(bool $ok,string $message='Interne Verrechnung und ihren Originalbeleg prüfen.'):void
    {
        if(!$ok)throw new DomainException($message);
    }
    private static function id(mixed $value):void
    {
        self::check(is_string($value)&&preg_match('/^[a-f0-9]{32}$/D',$value)===1);
    }
    private static function cents(mixed $value,bool $positive=false):int
    {
        self::check(is_int($value)&&$value>=($positive?1:0)&&$value<=BalanceCalculation::LIMIT,'Betrag als ganze Cent und innerhalb der zulässigen Grenze angeben.');
        return $value;
    }
    private static function reason(mixed $value):string
    {
        self::check(is_string($value)&&trim($value)!==''&&mb_strlen($value)<=1000&&!preg_match('/[\x00-\x1f]/',$value),'Grund und Nachweis der Verrechnung angeben.');
        return trim($value);
    }
    private static function date(mixed $value):void
    {
        self::check(is_string($value)&&TaxCatalog::documentYear($value)>0,'Gültiges Rückzahlungsdatum angeben.');
    }

    /** No caller-supplied opening balance: reconstruct it from the complete prior proof. */
    public static function build(string $club,array $report,array $units,array $allocations,array $instructions,?array $previous=null):array
    {
        return self::calculate($club,$report,$units,$allocations,$instructions,$previous,0);
    }

    public static function rebuild(array $proof,int $depth=0):array
    {
        self::check($depth<=100&&array_keys($proof)===[...self::KEYS,'sha256']&&in_array($proof['schema'],[1,2],true)&&$proof['kind']==='internal_clearing_workpaper'&&$proof['tax_export_ready']===false);
        $payload=$proof;unset($payload['sha256']);
        self::check(is_string($proof['sha256'])&&hash_equals(BalanceSegmentCalculation::hash($payload),$proof['sha256']),'Verrechnungsnachweis wurde verändert.');
        self::check(is_string($proof['club_id'])&&is_array($proof['source'])&&is_array($proof['units'])&&is_array($proof['allocations'])&&is_array($proof['instructions'])&&($proof['previous']===null||is_array($proof['previous'])));
        $rebuilt=self::calculate($proof['club_id'],$proof['source'],$proof['units'],$proof['allocations'],$proof['instructions'],$proof['previous'],$depth);
        self::check($rebuilt===$proof,'Verrechnungsnachweis stimmt nicht mit den Originalen überein.');
        return $rebuilt;
    }

    private static function calculate(string $club,array $report,array $units,array $allocations,array $instructions,?array $previous,int $depth):array
    {
        self::check($depth<=100,'Zu viele aufeinanderfolgende Verrechnungsjahre.');
        self::check(preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$club)===1,'Vereinskennung prüfen.');
        $report['previous_sha256']??=null;
        self::check(!array_diff(self::SOURCE,array_keys($report)));
        $source=array_intersect_key($report,array_flip(self::SOURCE));
        // Stable field order independent of PHP caller construction.
        $source=array_replace(array_fill_keys(self::SOURCE,null),$source);
        if(isset($report['period']))$source['period']=BalancePeriod::check($report['period'],$report['year']);
        $base=BalanceSegmentCalculation::build($source,$units,$allocations);
        self::check(!array_filter($base['missing'],static fn($key)=>!str_starts_with($key,'opening:')),'Vor der Verrechnung alle laufenden Originalzeilen zuordnen.');
        $units=$base['units'];$allocations=$base['allocations'];
        self::check(($source['accounts'][$source['result_account']]['group']??null)==='equity');
        $year=$source['year'];$cases=[];$events=[];$normalized=['advances'=>[],'repayments'=>[]];
        self::check(array_keys($instructions)===['advances','repayments']&&is_array($instructions['advances'])&&is_array($instructions['repayments'])&&count($instructions['advances'])+count($instructions['repayments'])<=10000,'Vorschüsse und Rückzahlungen getrennt angeben.');
        if($previous!==null){
            $p=self::rebuild($previous,$depth+1);
            self::check($p['club_id']===$club&&BalanceSegmentCalculation::follows($p['source'],$source)&&$source['result_account']===$p['source']['result_account'],'Unveränderten Nachweis des direkten Vorzeitraums desselben Vereins übernehmen.');
            foreach($p['units'] as $id=>$unit)self::check(($units[$id]['sphere']??null)===$unit['sphere'],'Fortgeführte Vereinsbereiche nicht ändern oder entfernen.');
            foreach($p['source']['accounts'] as $id=>$a)foreach(['group','skr_code'] as $field)self::check(($source['accounts'][$id][$field]??null)===$a[$field],'Kontenzuordnung des Vorjahres unverändert fortführen.');
            $net=static function(array $lines):array{$out=[];foreach($lines as $l)$out[$l['account']]=$l['debit_cents']-$l['credit_cents'];ksort($out);return $out;};
            self::check($net(BalanceCalculation::carryForward($p['source']['calculation'],$source['result_account']))===$net($source['opening']),'Eröffnungsbilanz stimmt nicht mit dem Vorjahr überein.');
            foreach($p['cases'] as $id=>$case){
                // Retain settled cases, too: their IDs cannot silently be reused.
                $case['opening_cents']=$case['closing_cents'];$case['advanced_cents']=0;$case['repaid_cents']=0;
                $cases[$id]=$case;
            }
        }else self::check($source['previous_sha256']===null,'Bei einem Folgejahr ist der vollständige Verrechnungsnachweis des Vorjahres erforderlich, auch wenn keine Beträge offen waren.');
        $historicIds=[];$historicVouchers=[];
        for($prior=$previous;$prior!==null;$prior=$prior['previous'])foreach($prior['movements'] as $id=>$movement){$historicIds[$id]=true;$historicVouchers[$movement['voucher']]=true;}
        $entryMap=array_column($source['entries'],null,'id');$sourceRows=BalanceSegmentCalculation::sources($source);
        $voucherParts=[];$voucherCash=[];$cashOnly=[];$voucherAllocations=[];
        foreach($sourceRows as $key=>$r){
            $v=$r['voucher'];if($v==='opening')continue;
            $isCash=$source['accounts'][$r['line']['account']]['group']==='cash';
            $cashOnly[$v]=($cashOnly[$v]??true)&&$isCash;$voucherAllocations[$v][$key]=$allocations[$key];
            foreach($allocations[$key]['parts'] as $unit=>$value){
                $net=$r['line']['credit_cents']>0?$value:-$value;
                $voucherParts[$v][$unit]=($voucherParts[$v][$unit]??0)+$net;
                if($isCash)$voucherCash[$v][$unit]=($voucherCash[$v][$unit]??0)+$net;
            }
        }
        foreach($voucherParts as &$parts){$parts=array_filter($parts,static fn($value)=>$value!==0);ksort($parts);}unset($parts);
        foreach($voucherAllocations as &$rows)ksort($rows);unset($rows);
        foreach($instructions as $type=>$rows)foreach($rows as $id=>$row){
            self::id($id);self::check(is_array($row)&&!isset($historicIds[$id]),'Bereits verwendete Verrechnungskennung nicht erneut verwenden.');
            $keys=$type==='advances'?['voucher','from','to','cents','repay_by','reason']:['voucher','advance','cents','reason'];
            self::check(!array_diff(array_keys($row),$keys)&&!array_diff($keys,array_keys($row)));
            self::id($row['voucher']);self::check(isset($entryMap[$row['voucher']])&&!isset($historicVouchers[$row['voucher']]),'Bereits verwendeten Originalbeleg nicht erneut verrechnen.');
            $cents=self::cents($row['cents'],true);$reason=self::reason($row['reason']);$entry=$entryMap[$row['voucher']];
            if($type==='advances'){
                self::check(!isset($cases[$id]),'Dieser Vorschuss wurde bereits erfasst.');
                $from=$row['from'];$to=$row['to'];
                self::check(is_string($from)&&is_string($to)&&isset($units[$from],$units[$to])&&$from!==$to,'Zahlenden und begünstigten Bereich getrennt wählen.');
                $due=$row['repay_by'];self::date($due);self::check($due>=$entry['date'],'Die geplante Rückzahlung liegt vor dem Vorschuss.');
                $normalized[$type][$id]=['voucher'=>$row['voucher'],'from'=>$from,'to'=>$to,'cents'=>$cents,'repay_by'=>$due,'reason'=>$reason];
            }else{
                self::id($row['advance']);
                $normalized[$type][$id]=['voucher'=>$row['voucher'],'advance'=>$row['advance'],'cents'=>$cents,'reason'=>$reason];
            }
            self::check(!isset($events[$id]),'Vorschuss und Rückzahlung benötigen verschiedene Kennungen.');
            $events[$id]=['type'=>$type,'date'=>$entry['date'],'row'=>$normalized[$type][$id]];
        }
        self::check(count($cases)+count($normalized['advances'])<=10000,'Zu viele Verrechnungsfälle.');
        ksort($events);
        uasort($events,static fn($a,$b)=>[$a['date'],$a['type']==='advances'?0:1,$a['row']['voucher']]<=>[$b['date'],$b['type']==='advances'?0:1,$b['row']['voucher']]);
        $used=[];$movements=[];$sum=['opening_cents'=>0,'advanced_cents'=>0,'repaid_cents'=>0,'closing_cents'=>0];
        foreach($events as $id=>$event){
            $row=$event['row'];$voucher=$row['voucher'];$entry=$entryMap[$voucher];$advance=$event['type']==='advances';$caseId=$advance?$id:$row['advance'];
            if($advance){
                $proof=['entry'=>$entry,'allocations'=>$voucherAllocations[$voucher]];
                $cases[$caseId]=['origin_year'=>$year,'origin_voucher'=>$voucher,'origin_sha256'=>BalanceSegmentCalculation::hash($proof),'from'=>$row['from'],'to'=>$row['to'],'original_cents'=>$row['cents'],'opening_cents'=>0,'advanced_cents'=>$row['cents'],'repaid_cents'=>0,'closing_cents'=>$row['cents'],'repay_by'=>$row['repay_by'],'reason'=>$row['reason'],'status'=>'open'];
                if(isset($source['period']))$cases[$caseId]['origin_period']=$source['period'];
            }else{
                self::check(isset($cases[$caseId]),'Rückzahlung ohne vorherigen Vorschuss.');
                self::check($row['cents']<=$cases[$caseId]['closing_cents'],'Die Rückzahlung überschreitet den offenen Betrag.');
                $cases[$caseId]['repaid_cents']=self::cents($cases[$caseId]['repaid_cents']+$row['cents']);$cases[$caseId]['closing_cents']-=$row['cents'];
            }
            $case=$cases[$caseId];$giver=$advance?$case['from']:$case['to'];$receiver=$advance?$case['to']:$case['from'];
            $parts=$voucherParts[$voucher];
            self::check(($parts[$giver]??0)>0&&($parts[$receiver]??0)<0,'Zahlungsrichtung passt nicht zum Originalbeleg.');
            self::check(!isset($used[$voucher])||$used[$voucher]['type']===$event['type'],'Vorschuss und Rückzahlung nicht mit demselben Beleg erfassen.');
            $used[$voucher]??=['type'=>$event['type'],'parts'=>[]];
            foreach([$giver=>$row['cents'],$receiver=>-$row['cents']] as $unit=>$net)$used[$voucher]['parts'][$unit]=($used[$voucher]['parts'][$unit]??0)+$net;
            $movements[$id]=['case'=>$caseId,'voucher'=>$voucher,'date'=>$entry['date'],'kind'=>$advance?'advance':'repayment','from'=>$giver,'to'=>$receiver,'cents'=>$row['cents'],'reason'=>$row['reason']];
        }
        foreach($used as $voucher=>$use){
            $parts=$use['parts'];ksort($parts);self::check($parts===$voucherParts[$voucher],'Die Verrechnungen müssen den Bereichsausgleich des Originalbelegs exakt abdecken.');
            $cash=$voucherCash[$voucher]??[];
            if($use['type']==='repayments')self::check($cashOnly[$voucher],'Eine Rückzahlung benötigt eine reine Bank-/Kassenumbuchung, keine neue Ausgabe oder Stornierung.');
            foreach($parts as $unit=>$net)if($net>0)self::check(($cash[$unit]??0)>=$net,'Der zahlende Bereich hat laut Originalbeleg keinen ausreichenden Geldabgang.');
        }
        foreach($cases as &$case){
            $case['status']=$case['closing_cents']===0?'settled':($case['repay_by']<($source['period']['end']??$year.'-12-31')?'overdue':'open');
            foreach(array_keys($sum) as $field)$sum[$field]=self::cents($sum[$field]+$case[$field]);
            self::check($case['opening_cents']+$case['advanced_cents']-$case['repaid_cents']===$case['closing_cents']);
        }unset($case);
        self::check($sum['opening_cents']+$sum['advanced_cents']-$sum['repaid_cents']===$sum['closing_cents']);
        ksort($cases);ksort($movements);foreach($normalized as &$rows)ksort($rows);unset($rows);
        $result=['schema'=>isset($source['period'])?2:1,'kind'=>'internal_clearing_workpaper','club_id'=>$club,'source'=>$source,'units'=>$units,'allocations'=>$allocations,'instructions'=>$normalized,'previous'=>$previous,'cases'=>$cases,'movements'=>$movements,'totals'=>$sum,'tax_export_ready'=>false];
        return $result+['sha256'=>BalanceSegmentCalculation::hash($result)];
    }
}
