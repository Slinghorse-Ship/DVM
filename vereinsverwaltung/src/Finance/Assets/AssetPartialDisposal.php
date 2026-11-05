<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

require_once __DIR__.'/AssetBooks.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceCalculation.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/TaxCatalog.php';

/**
 * Calculation-only preparation of ONE documented partial scrapping of linear equipment.
 * Pure arithmetic only: permission, documents, retained history and atomic journal adoption
 * are checked separately by AssetPartialDisposalReview/Records and BalanceSources.
 */
final class AssetPartialDisposal
{
    public static function hash(array $value):string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function keys(array $value,array $keys):void
    {
        if(count($value)!==count($keys)||array_diff(array_keys($value),$keys))
            throw new DomainException('Angaben zum Teilabgang sind unvollständig oder enthalten nicht unterstützte Zusätze.');
    }

    private static function reason(mixed $value):bool
    {
        return is_string($value)&&mb_check_encoding($value,'UTF-8')&&trim($value)!==''&&mb_strlen($value)<=2000
            &&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value);
    }

    private static function document(array $value):void
    {
        if(!is_string($value['document_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$value['document_id'])
            ||!is_int($value['revision']??null)||$value['revision']<1
            ||!is_string($value['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$value['sha256']))
            throw new DomainException('Beleg, Dateistand und Prüfsumme des Nachweises fehlen.');
    }

    private static function check(array $plan,array $proof,int $year):void
    {
        if(PHP_INT_SIZE<8)throw new DomainException('64-Bit-Laufzeit für Centbeträge erforderlich.');
        self::keys($plan,['basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method']);
        $start=is_string($plan['acquired_on'])?TaxCatalog::documentYear($plan['acquired_on']):0;
        if($start<2000||$start>2200||$year<$start||$year>2200||$plan['method']!=='linear_monthly'
            ||!in_array($plan['account'],AssetTypes::codes('movable'),true)||!in_array($plan['sphere'],['1','2','3','4'],true)
            ||!self::reason($plan['reason']))
            throw new DomainException('Teilabgang hier nur für belegte lineare Ausstattung mit unveränderter Nutzungsdauer berechnen.');
        AssetBooks::schedule($plan,$year);
        self::keys($proof,['schema','kind','date','reason','document_id','revision','sha256','plan_sha256','allocation','no_proceeds','complete']);
        $end=is_string($proof['date'])?TaxCatalog::documentYear($proof['date']):0;
        if($proof['schema']!==1||$proof['kind']!=='partial_scrap'||$proof['complete']!==false||$proof['no_proceeds']!==true
            ||$end<$start||$end>2200||$proof['date']<$plan['acquired_on']||!self::reason($proof['reason'])
            ||!is_string($proof['plan_sha256'])||!hash_equals(self::hash($plan),$proof['plan_sha256'])||!is_array($proof['allocation']))
            throw new DomainException('Teilabgang ohne Erlös muss zum ursprünglichen Anlagenplan passen.');
        self::document($proof);
        $a=$proof['allocation'];
        self::keys($a,['basis','cost_cents','residual_cents','document_id','revision','sha256','reason','same_use_and_life','confirmed']);
        self::document($a);
        if($a['basis']!=='documented_component_cost'||$a['same_use_and_life']!==true||$a['confirmed']!==true||!self::reason($a['reason'])
            ||!is_int($a['cost_cents'])||$a['cost_cents']<=0||$a['cost_cents']>=$plan['basis_cents']
            ||!is_int($a['residual_cents'])||$a['residual_cents']<0||$a['residual_cents']>$plan['residual_cents']
            ||$a['residual_cents']>=$a['cost_cents']
            ||$plan['residual_cents']-$a['residual_cents']>=$plan['basis_cents']-$a['cost_cents'])
            throw new DomainException('Nachgewiesene Teilkosten und Restwert abgrenzen; Stückzahl oder Prozent allein genügen nicht.');
    }

    /** round(total * share / whole), half up, without overflowing a 64-bit product. */
    private static function allocate(int $total,int $share,int $whole):int
    {
        $q=0;$r=0;
        foreach(str_split(decbin($total)) as $bit){
            $q*=2;$r=$r*2+($bit==='1'?$share:0);
            $q+=intdiv($r,$whole);$r%=$whole;
        }
        return $q+(int)($r*2>=$whole);
    }

    /** Cumulative depreciation is split; independent component schedules could rewrite old cents. */
    private static function cumulative(array $plan,array $allocation,int $months):array
    {
        $months=max(0,min($plan['months'],$months));
        $whole=$plan['basis_cents']-$plan['residual_cents'];
        $total=intdiv($whole*$months+intdiv($plan['months'],2),$plan['months']);
        $part=self::allocate($total,$allocation['cost_cents']-$allocation['residual_cents'],$whole);
        return ['total'=>$total,'part'=>$part,'remaining'=>$total-$part];
    }

    private static function calculate(array $plan,array $proof,int $year):array
    {
        $a=$proof['allocation'];$start=(int)substr($plan['acquired_on'],0,4);$month=(int)substr($plan['acquired_on'],5,2);
        $end=(int)substr($proof['date'],0,4);$endMonth=(int)substr($proof['date'],5,2);
        $before=self::cumulative($plan,$a,($year-$start)*12-($month-1));
        $after=self::cumulative($plan,$a,($year-$start)*12+12-($month-1));
        $event=self::cumulative($plan,$a,($end-$start)*12+$endMonth-($month-1));
        $old=$end<$year;$now=$end===$year;$remaining=$plan['basis_cents']-$a['cost_cents'];
        $costOpen=$year===$start?0:($old?$remaining:$plan['basis_cents']);
        $accumOpen=$year===$start?0:$before[$old?'remaining':'total'];
        $depreciation=$now?$event['total']-$before['total']+$after['remaining']-$event['remaining']
            :$after[$old?'remaining':'total']-$before[$old?'remaining':'total'];
        $costClose=$end<=$year?$remaining:$plan['basis_cents'];
        $accumClose=$after[$end<=$year?'remaining':'total'];
        return [
            'year'=>$year,'cost_opening_cents'=>$costOpen,'additions_cents'=>$year===$start?$plan['basis_cents']:0,
            'disposals_cents'=>$now?$a['cost_cents']:0,'cost_closing_cents'=>$costClose,
            'accumulated_opening_cents'=>$accumOpen,'depreciation_cents'=>$depreciation,
            'accumulated_disposals_cents'=>$now?$event['part']:0,'accumulated_closing_cents'=>$accumClose,
            'book_opening_cents'=>$costOpen-$accumOpen,'book_disposals_cents'=>$now?$a['cost_cents']-$event['part']:0,
            'book_closing_cents'=>$costClose-$accumClose,
            'remaining_residual_cents'=>$end<=$year?$plan['residual_cents']-$a['residual_cents']:$plan['residual_cents'],
        ];
    }

    /** Same component-cost evidence and cumulative cent allocation, for an actual fiscal period. */
    public static function period(array $plan,array $proof,array $period,?string $dayPolicy=null):array
    {
        $period=BalancePeriod::check($period);if($dayPolicy===null)BalancePeriod::fullMonths($period);
        elseif($dayPolicy!==BalancePeriodDayAllocation::POLICY)throw new DomainException('Bestätigten internen Teilmonatsnachweis verwenden.');
        self::check($plan,$proof,(int)substr($period['end'],0,4));
        if($plan['acquired_on']>$period['end']||$proof['date']>$period['end'])throw new DomainException('Anschaffung und Teilabgang müssen bis zum Ende des Zeitraums erfolgt sein.');
        return self::periodAmounts($plan,$proof,$period,$dayPolicy!==null);
    }
    /** Tax life changes values, never the identity of the original component evidence. */
    public static function periodValuation(array $plan,array $proof,array $period,array $taxReview):array
    {
        $book=self::period($plan,$proof,$period);self::keys($taxReview,['months','reason','confirmed']);
        if($plan['sphere']!=='4'||!is_int($taxReview['months'])||$taxReview['months']<12||$taxReview['months']>1200||$taxReview['months']%12
            ||$taxReview['months']===$plan['months']||$taxReview['confirmed']!==true||!self::reason($taxReview['reason']))throw new DomainException('Abweichende steuerliche Nutzungsdauer im Geschäftsbetrieb gesondert bestätigen.');
        $tax=self::periodAmounts(array_replace($plan,['months'=>$taxReview['months']]),$proof,BalancePeriod::check($period));
        return ['book'=>$book,'tax'=>$tax,'review_sha256'=>self::hash($taxReview),'plan_sha256'=>self::hash($plan),'proof_sha256'=>self::hash($proof)];
    }
    private static function periodAmounts(array $plan,array $proof,array $period,bool $daily=false):array
    {
        $elapsed=static fn(string $date):int=>((int)substr($date,0,4)-(int)substr($plan['acquired_on'],0,4))*12+(int)substr($date,5,2)-(int)substr($plan['acquired_on'],5,2);
        if($daily){
            $at=static function(string$date)use($plan,$proof):array{
                $total=BalancePeriodDayAllocation::cumulative($plan['basis_cents'],$plan['residual_cents'],$plan['months'],$plan['acquired_on'],$date);
                $part=self::allocate($total,$proof['allocation']['cost_cents']-$proof['allocation']['residual_cents'],$plan['basis_cents']-$plan['residual_cents']);return ['total'=>$total,'part'=>$part,'remaining'=>$total-$part];
            };
            $before=$at(BalancePeriodDayAllocation::before($period['start']));$after=$at($period['end']);
        }else{
            $before=self::cumulative($plan,$proof['allocation'],$elapsed($period['start']));
            $after=self::cumulative($plan,$proof['allocation'],$elapsed($period['end'])+1);
        }
        $event=self::cumulative($plan,$proof['allocation'],$elapsed($proof['date'])+1);
        $old=$proof['date']<$period['start'];$acquired=$plan['acquired_on']>=$period['start'];
        $part=$proof['allocation']['cost_cents'];$remaining=$plan['basis_cents']-$part;
        $costOpen=$acquired?0:($old?$remaining:$plan['basis_cents']);
        $accumOpen=$acquired?0:$before[$old?'remaining':'total'];
        $depreciation=$old?$after['remaining']-$before['remaining']:$event['total']-$before['total']+$after['remaining']-$event['remaining'];
        return ['period'=>$period,'cost_opening_cents'=>$costOpen,'additions_cents'=>$acquired?$plan['basis_cents']:0,
            'disposals_cents'=>$old?0:$part,'cost_closing_cents'=>$remaining,'accumulated_opening_cents'=>$accumOpen,
            'depreciation_cents'=>$depreciation,'accumulated_disposals_cents'=>$old?0:$event['part'],
            'accumulated_closing_cents'=>$after['remaining'],'book_opening_cents'=>$costOpen-$accumOpen,
            'book_disposals_cents'=>$old?0:$part-$event['part'],'book_closing_cents'=>$remaining-$after['remaining'],
            'remaining_residual_cents'=>$plan['residual_cents']-$proof['allocation']['residual_cents']];
    }

    /** A preview is NOT a persisted source, tax approval or permission to export. */
    public static function preview(array $plan,array $proof,int $year,?array $taxReview=null):array
    {
        self::check($plan,$proof,$year);$book=self::calculate($plan,$proof,$year);
        $out=['schema'=>1,'postable'=>false,'exportable'=>false,'plan_sha256'=>self::hash($plan),
            'proof_sha256'=>self::hash($proof),'book'=>$book,'tax'=>null,'tax_review_sha256'=>null,'differences'=>null];
        if($taxReview===null)return $out;
        self::keys($taxReview,['months','reason','confirmed']);
        if($plan['sphere']!=='4'||!is_int($taxReview['months'])||$taxReview['months']<12||$taxReview['months']>1200||$taxReview['months']%12
            ||$taxReview['months']===$plan['months']||$taxReview['confirmed']!==true||!self::reason($taxReview['reason']))
            throw new DomainException('Abweichende steuerliche Nutzungsdauer im Geschäftsbetrieb gesondert bestätigen.');
        $tax=self::calculate(array_replace($plan,['months'=>$taxReview['months']]),$proof,$year);
        $d=['opening_cents'=>$tax['book_opening_cents']-$book['book_opening_cents'],
            'depreciation_cents'=>$tax['depreciation_cents']-$book['depreciation_cents'],
            'disposal_cents'=>$tax['book_disposals_cents']-$book['book_disposals_cents'],
            'closing_cents'=>$tax['book_closing_cents']-$book['book_closing_cents']];
        $d['result_cents']=-$d['depreciation_cents']-$d['disposal_cents'];
        if($d['opening_cents']+$d['result_cents']!==$d['closing_cents'])throw new DomainException('Teilabgang und steuerliche Wertüberleitung sind nicht abgestimmt.');
        $out['tax']=$tax;$out['tax_review_sha256']=self::hash($taxReview);$out['differences']=$d;return $out;
    }
}
