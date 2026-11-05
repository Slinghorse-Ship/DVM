<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DateTimeImmutable;
use DomainException;

require_once __DIR__.'/FinancialReserveCalculation.php';

/** Calendar arithmetic for an original personal reserve, never an export approval. */
final class FinancialReserveYear
{
    public const FLAGS=[...FinancialReserveCalculation::FLAGS,'complete_history'];
    public const KEYS=['schema','year','sale_year','taxpayer_kind','held_since','sold_on',
        'sale_price_cents','sale_cost_cents','sold_tax_book_cents','formed_cents',
        'regime','regime_reviewed','nonexempt_share','deductions','releases',...self::FLAGS];

    private static function need(bool $ok):void
    {
        if(!$ok)throw new DomainException('Ursprüngliche Kalender-Rücklage, vollständige Bewegungen und ausdrücklich bestätigten Steueranteil prüfen; keine Körperschafts- oder Exportfreigabe.');
    }

    private static function keys(array $value,array $expected):bool
    {
        $actual=array_keys($value);sort($actual);sort($expected);return $actual===$expected;
    }

    private static function money(mixed $value,bool $positive=false):int
    {
        self::need(is_int($value)&&$value>=($positive?1:0)&&$value<=FinancialReserveCalculation::LIMIT);
        return $value;
    }

    private static function day(mixed $value):DateTimeImmutable
    {
        self::need(is_string($value)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)===1);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        self::need($date!==false&&$date->format('Y-m-d')===$value);
        return $date;
    }

    /** Separate investment windows from the statutory final reserve dissolution. */
    public static function calendar(mixed $saleYear,mixed $year):array
    {
        $limit=FinancialReserveCalculation::calendarLimit($saleYear);
        self::need(is_int($year)&&$year>=$saleYear&&$year<=2099);
        return ['sale_year'=>$saleYear,'year'=>$year,'original_gain_limit_cents'=>$limit,
            'share_reinvestment_deadline'=>($saleYear+2).'-12-31',
            'building_reinvestment_deadline'=>($saleYear+4).'-12-31',
            'mandatory_release_on'=>($saleYear+4).'-12-31',
            'share_reinvestment_in_year'=>$year<=$saleYear+2,
            'building_reinvestment_in_year'=>$year<=$saleYear+4,
            'mandatory_release_due'=>$year>=$saleYear+4,
            'share_years_remaining'=>max(0,$saleYear+2-$year),
            'building_years_remaining'=>max(0,$saleYear+4-$year)];
    }

    /** Reduced exact cents; no undocumented cent or whole-euro rounding. */
    private static function fraction(int $numerator,int $denominator):array
    {
        if($numerator===0)return ['numerator'=>0,'denominator'=>1];
        $a=$numerator;$b=$denominator;
        while($b!==0){$next=$a%$b;$a=$b;$b=$next;}
        return ['numerator'=>intdiv($numerator,$a),'denominator'=>intdiv($denominator,$a)];
    }

    private static function releaseAmounts(int $gross,int $years,?array $share):array
    {
        if($gross===0)return ['nonexempt_release_exact_cents'=>self::fraction(0,1),
            'surcharge_exact_cents'=>self::fraction(0,1),'surcharge_status'=>'not_due'];
        if($share===null)return ['nonexempt_release_exact_cents'=>null,
            'surcharge_exact_cents'=>null,'surcharge_status'=>'original_nonexempt_share_required'];
        return ['nonexempt_release_exact_cents'=>self::fraction($gross*$share['numerator'],$share['denominator']),
            'surcharge_exact_cents'=>self::fraction($gross*$share['numerator']*6*$years,$share['denominator']*100),
            'surcharge_status'=>'calculated_from_explicit_original_share'];
    }

    /**
     * Reconstruct all gross annual movements instead of accepting an opening balance.
     * Regime/confirmations are input assertions, not an assessment of their evidence.
     * Voluntary releases are year-end decisions; buildings/other uses are excluded.
     */
    public static function derive(mixed $input):array
    {
        self::need(is_array($input)&&self::keys($input,self::KEYS)&&$input['schema']===1);
        $calendar=self::calendar($input['sale_year'],$input['year']);
        $saleYear=$input['sale_year'];$year=$input['year'];
        self::need($input['taxpayer_kind']==='natural_person_sole_business');
        foreach(self::FLAGS as $flag)self::need($input[$flag]===true);
        $held=self::day($input['held_since']);$sold=self::day($input['sold_on']);
        self::need((int)$sold->format('Y')===$saleYear&&$held<$sold
            &&$sold->format('m-d')!=='02-29'&&$held<=$sold->modify('-6 years'));
        $gain=self::money($input['sale_price_cents'])-self::money($input['sale_cost_cents'])-self::money($input['sold_tax_book_cents']);
        $formed=self::money($input['formed_cents'],true);
        self::need($formed<=$gain&&$formed<=$calendar['original_gain_limit_cents']);

        $share=$input['nonexempt_share'];
        if($input['regime']==='unconfirmed')self::need($input['regime_reviewed']===false&&$share===null);
        else{
            self::need($input['regime']==='teileinkuenfte_40_60'&&$input['regime_reviewed']===true
                &&is_array($share)&&self::keys($share,['numerator','denominator'])
                &&$share['numerator']===3&&$share['denominator']===5);
        }
        self::need(is_array($input['deductions'])&&array_is_list($input['deductions'])&&count($input['deductions'])<=100
            &&is_array($input['releases'])&&array_is_list($input['releases'])&&count($input['releases'])<=100);
        $deductions=[];$assets=[];
        foreach($input['deductions'] as $entry){
            self::need(is_array($entry)&&self::keys($entry,['asset_id','acquired_on','cost_cents','deduction_cents']));
            $id=$entry['asset_id'];self::need(is_string($id)&&preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)===1&&!isset($assets[$id]));
            $acquired=self::day($entry['acquired_on']);$acquiredYear=(int)$acquired->format('Y');
            self::need($acquiredYear>$saleYear&&$acquiredYear<=$saleYear+2&&$acquiredYear<=$year);
            $cost=self::money($entry['cost_cents'],true);$deduction=self::money($entry['deduction_cents'],true);
            self::need($deduction<=$cost);
            $deductions[$acquiredYear]=($deductions[$acquiredYear]??0)+$deduction;
            $assets[$id]=['asset_id'=>$id,'acquired_on'=>$entry['acquired_on'],'deduction_year'=>$acquiredYear,
                'cost_cents'=>$cost,'reserve_deduction_cents'=>$deduction,'tax_basis_cents'=>$cost-$deduction,
                'basis_reduction_retained'=>true,'future_disposal_review_required'=>true];
        }
        $releases=[];
        foreach($input['releases'] as $entry){
            self::need(is_array($entry)&&self::keys($entry,['year','gross_cents'])&&is_int($entry['year']));
            $releasedYear=$entry['year'];
            self::need($releasedYear>$saleYear&&$releasedYear<=$saleYear+4&&$releasedYear<=$year&&!isset($releases[$releasedYear]));
            $releases[$releasedYear]=self::money($entry['gross_cents'],true);
        }
        ksort($assets,SORT_STRING);
        $rows=[];$remaining=0;
        for($current=$saleYear;$current<=$year;$current++){
            $opening=$remaining;$formation=$current===$saleYear?$formed:0;$remaining+=$formation;
            $deducted=$deductions[$current]??0;$voluntary=$releases[$current]??0;
            self::need($deducted<=$remaining&&$voluntary<=$remaining-$deducted);
            $remaining-=$deducted+$voluntary;
            $mandatory=$current===$saleYear+4?$remaining:0;$remaining-=$mandatory;
            $grossReleased=$voluntary+$mandatory;
            $rows[$current]=['year'=>$current,'reserve_opening_cents'=>$opening,'reserve_formed_cents'=>$formation,
                'deductions_cents'=>$deducted,'voluntary_release_cents'=>$voluntary,'mandatory_release_cents'=>$mandatory,
                'release_without_reinvestment_cents'=>$grossReleased,'reserve_closing_cents'=>$remaining,
                'full_calendar_years_held'=>$current-$saleYear]
                +self::releaseAmounts($grossReleased,$current-$saleYear,$share);
        }
        return ['schema'=>1,'scope'=>'original_natural_person_calendar_share_reinvestment_calculation_only',
            'year'=>$year,'sale_year'=>$saleYear,'original_gain_cents'=>$gain,
            'formed_cents'=>$formed,'nonexempt_share'=>$share,'calendar'=>$calendar,
            'years'=>$rows,'year_end'=>$rows[$year],'basis_deductions'=>$assets,
            'tax_treatment_qualified'=>false,'corporate_successor_qualified'=>false,
            'activation_ready'=>false,'native_xml_qualified'=>false];
    }
}
