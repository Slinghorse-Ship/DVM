<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Four reviewed receivable contra accounts; genuine calculative pairs per sphere. */
final class BalanceAccountClosing
{
    private const BASE='bs.ass.currAss.receiv.trade';
    private const ALLOWANCES=['12460'=>'upTo1year','12470'=>'above1year','12480'=>'upTo1year','12490'=>'above1year','12760'=>'upTo1year','12770'=>'above1year','12960'=>'upTo1year','12970'=>'above1year'];
    private const FAMILIES=['12760'=>'affil','12770'=>'affil','12960'=>'particip','12970'=>'particip'];
    private const PAIRS=[['69700','69800'],['69720','69820'],['69740','69840'],['69760','69860'],['69780','69880'],['69790','69890'],['69900','69920','69940','69990']];
    private static function require(bool $ok,string $message):void{if(!$ok)throw new DomainException($message);}
    private static function rules(int $year):array
    {
        require_once __DIR__.'/BalanceTaxMapping.php';BalanceTaxMapping::preview($year,[]);
        $registry=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json'),true,64,JSON_THROW_ON_ERROR);$out=[];
        foreach($registry['rules'] as $rule)if(!isset($rule['years'])||in_array($year,$rule['years'],true))foreach($rule['codes'] as $code){
            $family=self::FAMILIES[$code]??'trade';$base='bs.ass.currAss.receiv.'.$family;$targets=$family==='trade'?[self::BASE.'.other',self::BASE.'.shareholders',self::BASE.'.misc']:[$base];
            if(isset($rule['contra_receivable_maturity']))self::require(($rule['contra_receivable_family']??'trade')===$family&&(self::ALLOWANCES[$code]??null)===$rule['contra_receivable_maturity']&&$rule['group']==='receivables'
                &&($rule['condition']??null)==='detail_choice'&&($rule['allowed_targets']??null)===$targets
                &&($rule['disclosures']??null)===[$base.'.'.$rule['contra_receivable_maturity']],'Wertberichtigung benötigt ihre ursprüngliche Jahresregel.');
            if(isset($rule['calculation_only_pair']))self::require(in_array($code,self::PAIRS[(int)$rule['calculation_only_pair']]??[],true)&&$rule['group']==='expense'
                &&($rule['condition']??null)==='detail_choice'&&($rule['allowed_targets']??null)===['is.netIncome.regular.operatingTC.otherCost.otherOrdinary'],'Kalkulatorische Gegenbuchung benötigt ihre ursprüngliche Jahresregel.');
            $out[$code]=$rule;
        }
        return $out;
    }
    public static function isAllowance(int $year,array $row):bool
    {
        if(!isset(self::ALLOWANCES[$row['skr_code']])||!in_array($year,[2025,2026],true)||$row['group']!=='receivables')return false;
        $rule=self::rules($year)[$row['skr_code']]??[];
        return ($rule['contra_receivable_maturity']??null)===self::ALLOWANCES[$row['skr_code']]&&($rule['group']??null)===$row['group'];
    }
    public static function receivables(int $year,array $rows):array
    {
        if(!in_array($year,[2025,2026],true))return [];
        $rules=self::rules($year);$totals=[];foreach(['trade','affil','particip'] as $family)foreach(['upTo1year','above1year'] as $m)$totals[$family.'.'.$m]=['opening_cents'=>0,'closing_cents'=>0];$issues=[];
        foreach($rows as $row){
            $rule=$rules[$row['skr_code']]??[];
            if(isset(self::ALLOWANCES[$row['skr_code']])){
                if(!self::isAllowance($year,$row)||$row['opening_cents']>0||$row['closing_cents']>0)$issues[]=$row['name'].': Wertberichtigung muss eine negative Korrektur der ursprünglichen Forderungen sein.';
            }
            if($row['group']!=='receivables'||($rule['group']??null)!=='receivables')continue;
            $targets=$rule['allowed_targets']??[$rule['target']];
            foreach($totals as $suffix=>&$fields){[$family,$maturity]=explode('.',$suffix);$base='bs.ass.currAss.receiv.'.$family;
                if(array_filter($targets,static fn($t)=>$t!==$base&&!str_starts_with($t,$base.'.')))continue;
                if(in_array($base.'.'.$maturity,$rule['disclosures']??[],true))foreach($fields as $field=>&$v)$v+=$row[$field];unset($v);
            }unset($fields);
        }
        foreach($totals as $fields)if(array_filter($fields,static fn($v)=>$v<0||$v>BalanceCalculation::LIMIT))$issues[]='Wertberichtigung überschreitet die ursprünglichen Forderungen derselben Restlaufzeit.';
        return array_values(array_unique($issues));
    }
    public static function calculative(int $year,array $chart,array $entries):void
    {
        $codes=[];foreach(self::PAIRS as $i=>$pair)foreach($pair as $code)$codes[$code]=(string)$i;
        $selected=array_filter($chart,static fn($a)=>isset($codes[$a['skr_code']]));if(!$selected)return;
        $rules=self::rules($year);$totals=[];foreach(self::PAIRS as $i=>$pair)$totals[(string)$i]=array_fill_keys(['1','2','3','4'],0);
        foreach($selected as $row)self::require($row['group']==='expense'&&($rules[$row['skr_code']]['calculation_only_pair']??null)===$codes[$row['skr_code']],'Kalkulatorisches Konto und ursprüngliche Jahresregel stimmen nicht überein.');
        foreach($entries as $entry)foreach($entry['lines'] as $line)if(isset($selected[$line['account']])){
            $pair=$codes[$selected[$line['account']]['skr_code']];$sphere=$line['sphere'];self::require(isset($totals[$pair][$sphere]),'Kalkulatorische Sphäre fehlt.');
            $totals[$pair][$sphere]+=$line['debit_cents']-$line['credit_cents'];self::require(abs($totals[$pair][$sphere])<=BalanceCalculation::LIMIT,'Betragsgrenze kalkulatorischer Gegenbuchungen überschritten.');
        }
        foreach($totals as $fields)self::require(!array_filter($fields),'Kalkulatorische Gegenbuchungen müssen in derselben Sphäre vollständig ausgeglichen sein.');
    }
}
