<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Reviewed capital movements; every original capital line remains traceable. */
final class BalanceCapitalComparison
{
    public static function build(array $report,array $review,array $tax):array
    {
        $period=isset($report['period'])?BalancePeriod::check($report['period'],$report['year']):null;
        if($period!==null&&(($review['period']??null)!==$period||($tax['period']??null)!==$period))throw new DomainException('Kapitalvergleich gehört nicht zum bestätigten Abschlusszeitraum.');
        $decisions=$review['capital_movements']??[];
        if(!is_array($decisions)||count($decisions)>2000)throw new DomainException('Kapitalbewegungen vollständig prüfen.');
        $sources=[];$openingBook=$openingTax=0;$closing=$tax['annual_result_cents'];
        foreach($tax['accounts'] as $key=>$account)if(in_array($account['group'],['equity','reserves','result_use'],true)){
            $openingBook-=$report['calculation']['accounts'][$key]['opening_cents'];$openingTax-=$account['opening_cents'];$closing+=$account['amount_cents'];
        }
        foreach(['journal'=>$report['entries'],'tax'=>$review['current_adjustments']] as $type=>$entries)foreach($entries as $entry)foreach($entry['lines'] as $ordinal=>$line){
            if(!in_array($tax['accounts'][$line['account']]['group'],['equity','reserves','result_use'],true))continue;
            $key=$type.':'.$entry['id'].':'.$ordinal;
            $sources[$key]=['voucher'=>$type.':'.$entry['id'],'date'=>$entry['date']??($period['end']??$report['year'].'-12-31'),'description'=>$entry['description']??$entry['reason']??$entry['id'],'account'=>$line['account'],'debit_cents'=>$line['debit_cents'],'credit_cents'=>$line['credit_cents']];
        }
        if(count($sources)>2000||array_diff_key($decisions,$sources))throw new DomainException('Kapitalzuordnung enthält fremde oder zu viele Zeilen.');
        $normalized=[];$issues=[];$internal=[];$contributions=$withdrawals=0;
        foreach($sources as $key=>$source){
            if(!isset($decisions[$key])){$issues[]='Kapitalbewegung noch zuordnen: '.$key;continue;}
            $d=$decisions[$key];$effect=$source['credit_cents']-$source['debit_cents'];
            if(!is_array($d)||array_diff(array_keys($d),['kind','reason'])||!in_array($d['kind']??null,['contribution','withdrawal','reclassification'],true)||!is_string($d['reason']??null)||trim($d['reason'])===''||mb_strlen($d['reason'])>1000||preg_match('/[\x00-\x1f]/',$d['reason']))throw new DomainException('Kapitalbewegung mit Art und Nachweis prüfen.');
            if($d['kind']==='contribution'){if($effect<=0)throw new DomainException('Kapitalzuführung muss das Kapital erhöhen.');$contributions+=$effect;}
            elseif($d['kind']==='withdrawal'){if($effect>=0)throw new DomainException('Kapitalentnahme muss das Kapital vermindern.');$withdrawals-=$effect;}
            else $internal[$source['voucher']]=($internal[$source['voucher']]??0)+$effect;
            $normalized[$key]=['kind'=>$d['kind'],'reason'=>trim($d['reason'])];
        }
        foreach($internal as $voucher=>$difference)if($difference!==0)$issues[]='Kapitalumbuchung ist nicht innerhalb derselben Buchung ausgeglichen: '.$voucher;
        if(!$issues&&$closing-$openingTax+$withdrawals-$contributions!==$tax['annual_result_cents'])throw new DomainException('Betriebsvermögensvergleich und Jahresergebnis stimmen nicht überein.');
        ksort($sources,SORT_STRING);ksort($normalized,SORT_STRING);
        return ['year'=>$report['year'],'book_opening_cents'=>$openingBook,'opening_adjustment_cents'=>$openingTax-$openingBook,'tax_opening_cents'=>$openingTax,'tax_closing_cents'=>$closing,'contributions_cents'=>$contributions,'withdrawals_cents'=>$withdrawals,'profit_cents'=>$tax['annual_result_cents'],'sources'=>$sources,'decisions'=>$normalized,'issues'=>$issues,'complete'=>!$issues]+($period!==null?['period'=>$period]:[]);
    }
}
