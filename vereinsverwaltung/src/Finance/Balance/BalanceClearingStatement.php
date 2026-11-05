<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceSourceRead.php';

use DomainException;
require_once __DIR__.'/BalanceInternalClearing.php';

/** Internal reconciliation only. No synthetic account, external debt or tax position. */
final class BalanceClearingStatement
{
    private static function add(int $a,int $b):int
    {
        $value=$a+$b;
        if(abs($value)>BalanceCalculation::LIMIT)throw new DomainException('Betragsgrenze des Bereichsabgleichs überschritten.');
        return $value;
    }

    public static function build(array $report,array $allocation,array $proof,array $assetMovements=[]):array
    {
        return BalanceSourceRead::run(static fn()=>self::buildFromSources($report,$allocation,$proof,$assetMovements));
    }
    private static function buildFromSources(array $report,array $allocation,array $proof,array $assetMovements=[]):array
    {
        if(BalanceSegmentCalculation::rebuild($report,$allocation)!==$allocation)throw new DomainException('Originalaufteilung des Bereichsabgleichs prüfen.');
        $p=BalanceInternalClearing::rebuild($proof);
        if(BalanceInternalClearing::build($p['club_id'],$report,$allocation['units'],$allocation['allocations'],$p['instructions'],$p['previous'])!==$p)throw new DomainException('Verrechnung gehört nicht zu dieser Buchungsaufteilung.');
        $offsets=[];$internal=[];$units=[];$issues=[];
        foreach($allocation['units'] as $id=>$unit){
            $offsets[$id]=['opening'=>0];
            $internal[$id]=['opening_advance_cents'=>0,'opening_owed_cents'=>0,'closing_advance_cents'=>0,'closing_owed_cents'=>0];
        }
        foreach($p['cases'] as $case){
            foreach(['opening','closing'] as $period){
                $value=$case[$period.'_cents'];
                $internal[$case['from']][$period.'_advance_cents']=self::add($internal[$case['from']][$period.'_advance_cents'],$value);
                $internal[$case['to']][$period.'_owed_cents']=self::add($internal[$case['to']][$period.'_owed_cents'],$value);
            }
            $offsets[$case['from']]['opening']=self::add($offsets[$case['from']]['opening'],$case['opening_cents']);
            $offsets[$case['to']]['opening']=self::add($offsets[$case['to']]['opening'],-$case['opening_cents']);
        }
        foreach($p['movements'] as $m){
            if(isset($allocation['transfers'][$m['voucher']]))throw new DomainException('Dieselbe Zahlung nicht als Vorschuss und endgültige Mittelübertragung verwenden.');
            foreach([$m['from']=>$m['cents'],$m['to']=>-$m['cents']] as $unit=>$net)$offsets[$unit][$m['voucher']]=self::add($offsets[$unit][$m['voucher']]??0,$net);
        }
        if($allocation['missing'])$issues[]='Zuerst alle Anfangsbestände und Buchungszeilen zuordnen.';
        $assetEntries=[];$assetEliminations=[];
        foreach($report['asset_schedule']['items']??[] as $id=>$item)if(isset($item['sphere_change']))
            BalancePeriodSphereAllocation::checkMovements($item,$report['period'],$allocation['units'],$assetMovements[$id]??[]);
        foreach($assetMovements as $itemId=>$changes){
            $item=$report['asset_schedule']['items'][$itemId]??null;$fixed=$item['fixed_account']??'';$capital=$report['result_account'];
            if(!$item||($report['accounts'][$fixed]['group']??null)!=='fixed_assets'||($report['accounts'][$capital]['group']??null)!=='equity')throw new DomainException('Originalanlage und Kapitalkonto der Nutzungsänderung prüfen.');
            foreach($changes as $ordinal=>$change){
                if(!BalancePeriod::contains($report['period'],$change['on']))throw new DomainException('Nutzungsänderung liegt außerhalb des Zeitraums.');
                foreach(BalancePeriodAssetUse::TRANSFERS as $f)if(array_sum(array_column($change['parts'],$f))!==0)throw new DomainException('Anlagenumbuchung hebt sich nicht auf.');
                foreach($change['parts'] as $unit=>$v){
                    if((!isset($item['sphere_change'])&&($allocation['units'][$unit]['sphere']??null)!==$item['plan']['sphere'])||!is_int($v['book_transfer_cents']??null)||$v['cost_transfer_cents']-$v['accumulated_transfer_cents']!==$v['book_transfer_cents'])throw new DomainException('Anlagenumbuchung und steuerlichen Vereinsbereich prüfen.');
                    $n=$v['book_transfer_cents'];if($n===0)continue;
                    $assetEntries[$unit][]=['id'=>'asset-use:'.$itemId.':'.$ordinal,'lines'=>[
                        ['account'=>$fixed,'debit_cents'=>max(0,$n),'credit_cents'=>max(0,-$n)],
                        ['account'=>$capital,'debit_cents'=>max(0,-$n),'credit_cents'=>max(0,$n)]]];
                    if($n>0)foreach([$fixed,$capital] as $key)$assetEliminations[$key]=self::add($assetEliminations[$key]??0,$n);
                }
            }
        }
        foreach($allocation['segments'] as $id=>$segment){
            $accounts=[];$unbalanced=[];
            foreach($report['accounts'] as $key=>$account)$accounts[$key]=$account+['opening_cents'=>0,'debit_cents'=>0,'credit_cents'=>0,'closing_cents'=>0,'amount_cents'=>0];
            foreach(['opening'=>['lines'=>$segment['opening']],...array_column([...$segment['entries'],...($assetEntries[$id]??[])],null,'id')] as $voucher=>$entry){
                $difference=$offsets[$id][$voucher]??0;
                foreach($entry['lines'] as $line){
                    $difference=self::add($difference,$line['debit_cents']-$line['credit_cents']);
                    $row=&$accounts[$line['account']];
                    if($voucher==='opening')$row['opening_cents']=self::add($row['opening_cents'],$line['debit_cents']-$line['credit_cents']);
                    else foreach(['debit_cents','credit_cents'] as $f)$row[$f]=self::add($row[$f],$line[$f]);
                    unset($row);
                }
                if($difference!==0)$unbalanced[]=['voucher'=>$voucher,'difference_cents'=>$difference];
            }
            $totals=['asset_total_cents'=>0,'capital_total_cents'=>0,'liability_total_cents'=>0,'income_cents'=>0,'expense_cents'=>0];
            $fields=['asset'=>'asset_total_cents','capital'=>'capital_total_cents','liability'=>'liability_total_cents','income'=>'income_cents','expense'=>'expense_cents'];
            foreach($accounts as &$row){
                $row['closing_cents']=self::add($row['opening_cents'],$row['debit_cents']-$row['credit_cents']);
                $side=BalanceCalculation::GROUPS[$row['group']][1];
                $row['amount_cents']=in_array($side,['asset','expense'],true)?$row['closing_cents']:-$row['closing_cents'];
                require_once __DIR__.'/BalanceDirectionalClosing.php';
                $display=BalanceDirectionalClosing::presentation($report['year'],$row)??$row;
                $displaySide=BalanceCalculation::GROUPS[$display['group']][1];
                $totals[$fields[$displaySide]]=self::add($totals[$fields[$displaySide]],$display['amount_cents']);
                require_once __DIR__.'/BalanceOriginalOffsets.php';require_once __DIR__.'/BalanceOriginalReclassification.php';require_once __DIR__.'/BalanceTechnicalClosing.php';
                if(in_array($displaySide,['asset','liability'],true)&&$display['amount_cents']<0&&!BalanceOriginalOffsets::isInventoryContra($report['year'],$row)&&!BalanceOriginalReclassification::isContra($report['year'],$row))$issues[]=$segment['name'].': '.$row['name'].' auf der Gegenseite; getrennten Ausweis klären.';
            }unset($row);
            $issues=array_merge($issues,BalanceOriginalOffsets::issues($report['year'],$accounts),BalanceOriginalReclassification::issues($report['year'],$accounts),BalanceTechnicalClosing::issues($report['year'],$accounts));
            $totals['annual_result_cents']=self::add($totals['income_cents'],-$totals['expense_cents']);
            $totals['passive_total_cents']=self::add(self::add($totals['capital_total_cents'],$totals['liability_total_cents']),$totals['annual_result_cents']);
            $totals['difference_cents']=self::add(self::add($totals['asset_total_cents'],$internal[$id]['closing_advance_cents']),-self::add($totals['passive_total_cents'],$internal[$id]['closing_owed_cents']));
            if($unbalanced||$totals['difference_cents']!==0)$issues[]=$segment['name'].': Buchungsaufteilung und belegte Vorschüsse stimmen noch nicht überein.';
            $units[$id]=['name'=>$segment['name'],'sphere'=>$segment['sphere'],'accounts'=>$accounts,'internal'=>$internal[$id],'totals'=>$totals,'unbalanced'=>$unbalanced];
        }
        // Internal receivable/payable pairs never enter the club's accounts or totals.
        $eliminations=$assetEliminations;
        foreach($allocation['transfers']??[] as $t)foreach($t['parts'] as $net)if($net>0)$eliminations[$t['account']]=self::add($eliminations[$t['account']]??0,$net);
        $reconciled=!$allocation['missing'];
        if($reconciled)foreach($report['calculation']['accounts'] as $account=>$row)foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $field){
            $sum=0;foreach($units as $unit)$sum=self::add($sum,$unit['accounts'][$account][$field]);
            if(in_array($field,['debit_cents','credit_cents'],true))$sum=self::add($sum,-($eliminations[$account]??0));
            if($sum!==$row[$field])throw new DomainException('Bereichsabgleich verändert die Gesamtvereinsbilanz.');
        }
        foreach(['opening','closing'] as $period){
            $advance=0;$owed=0;
            foreach($internal as $r){$advance=self::add($advance,$r[$period.'_advance_cents']);$owed=self::add($owed,$r[$period.'_owed_cents']);}
            if($advance!==$owed||$advance!==$p['totals'][$period.'_cents'])throw new DomainException('Interne Beträge heben sich im Gesamtverein nicht vollständig auf.');
        }
        // Preserve all asset-allocation blockers even if the payment itself reconciles.
        if($allocation['asset_shares']??[]){
            $segments=$allocation['segments'];
            foreach($units as $id=>$unit)$segments[$id]['calculation']=['accounts'=>$unit['accounts']];
            $assets=BalanceAssetAllocation::build($report,$allocation['units'],$segments,$allocation['asset_shares']);
            $issues=array_merge($issues,$assets['issues']);
        }
        $result=['schema'=>1,'kind'=>'internal_clearing_statement','year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'allocation_sha256'=>$allocation['sha256'],'clearing_sha256'=>$p['sha256'],'units'=>$units,'club_calculation'=>$report['calculation'],'eliminated_internal_cents'=>$p['totals'],'issues'=>array_values(array_unique($issues)),'complete'=>!$issues,'tax_export_ready'=>false];
        if(isset($report['period']))$result['period']=BalancePeriod::check($report['period'],$report['year']);
        if($assetMovements)$result['asset_movements']=$assetMovements;
        return $result+['sha256'=>BalanceSegmentCalculation::hash($result)];
    }

    /** Carry only real accounts; outstanding internal cases stay in their own proof. */
    public static function carry(array $priorReport,array $priorAllocation,array $proof,array $report,array $assetMovements=[]):array
    {
        $statement=self::build($priorReport,$priorAllocation,$proof,$assetMovements);
        if(!$statement['complete']||!BalanceSegmentCalculation::follows($priorReport,$report)||($report['result_account']??null)!==$priorReport['result_account'])throw new DomainException('Vollständig abgestimmten Bereichs- und Verrechnungsnachweis des direkten Vorzeitraums übernehmen.');
        foreach($priorReport['accounts'] as $id=>$a)foreach(['group','skr_code'] as $f)if(($report['accounts'][$id][$f]??null)!==$a[$f])throw new DomainException('Konten des Vorjahres unverändert fortführen.');
        $net=static function(array $rows):array{$out=[];foreach($rows as $l)$out[$l['account']]=$l['debit_cents']-$l['credit_cents'];ksort($out);return $out;};
        if($net(BalanceCalculation::carryForward($priorReport['calculation'],$priorReport['result_account']))!==$net($report['opening']))throw new DomainException('Eröffnungsbilanz stimmt nicht mit dem Gesamtverein des Vorjahres überein.');
        $carried=[];
        foreach($statement['units'] as $unit=>$s)foreach($s['accounts'] as $id=>$row){
            if(in_array($row['group'],['revenue','expense'],true))continue;
            $amount=$row['closing_cents'];
            if($id===$priorReport['result_account'])$amount=self::add($amount,-$s['totals']['annual_result_cents']);
            if($amount!==0)$carried[$id][$unit]=$amount;
        }
        $alloc=[];$capital=[];$used=[];
        foreach($carried as $id=>$parts)if($report['accounts'][$id]['group']==='equity'&&min($parts)<0&&max($parts)>0){$capital[$id]=$parts;$used[$id]=true;}
        foreach(BalanceSegmentCalculation::sources($report) as $key=>$source){
            if($source['voucher']!=='opening')continue;
            $id=$source['line']['account'];if(isset($capital[$id]))continue;
            $parts=[];$debit=$source['line']['debit_cents']>0;
            foreach($carried[$id]??[] as $unit=>$amount){
                if(($amount>0)!==$debit)throw new DomainException('Gegenläufige Bereichssalden vor dem Vortrag getrennt ausweisen.');
                $parts[$unit]=abs($amount);
            }
            if(!$parts||array_sum($parts)!==$source['cents'])throw new DomainException('Bereichsbestände stimmen nicht mit den Original-Anfangsbeständen überein.');
            $alloc[$key]=['parts'=>$parts,'reason'=>'Vortrag aus geprüftem Bereichsabgleich einschließlich offener Vorschüsse '.$priorReport['year'].'.'];$used[$id]=true;
        }
        if(array_diff_key($carried,$used))throw new DomainException('Vorgetragener Bereichssaldo fehlt in der Eröffnungsbilanz.');
        // Dated asset shares are verified separately against original plans and exact prior schedules.
        $shares=isset($report['period'])?[]:BalanceSegmentCalculation::carryAssetShares($priorReport,$priorAllocation,$report);
        return BalanceSegmentCalculation::build($report,$priorAllocation['units'],$alloc,$capital,[],$shares);
    }
}
