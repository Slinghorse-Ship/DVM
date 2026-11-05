<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetTypes.php';

/** §7(2)/(3) EStG, acquisition window 2025-07-01..2026-12-31 in the verified catalogues.
 * A confirmed common book/tax plan; never infer commercial eligibility or §7g relief.
 */
final class DegressiveDepreciation
{
    public static function input(array $input,array $plan):array
    {
        if(!is_string($input['degressive_rate']??null)||($input['degressive_movable']??'')!=='1'
            ||($input['degressive_same_values']??'')!=='1'||($input['asset_reviewed']??'')!=='1')
            throw new DomainException('Beweglichen Gegenstand, zulässigen Abschreibungssatz und gemeinsame Buch-/Steuerbewertung bestätigen.');
        $proof=['rate_basis_points'=>Finance::cents($input['degressive_rate']),'movable'=>true,'same_book_tax'=>true,
            'switch_to_linear'=>($input['degressive_switch']??'')==='1'];
        self::check($proof,$plan);return $proof;
    }
    public static function check(mixed $proof,array $plan):void
    {
        if(!is_array($proof)||array_diff(array_keys($proof),['rate_basis_points','movable','same_book_tax','switch_to_linear'])
            ||!is_int($proof['rate_basis_points']??null)||!is_bool($proof['switch_to_linear']??null)
            ||($proof['movable']??null)!==true||($proof['same_book_tax']??null)!==true)
            throw new DomainException('Nachweis der degressiven Abschreibung fehlt.');
        $date=$plan['acquired_on']??'';$months=$plan['months']??null;
        if(!is_string($date)||!TaxCatalog::documentYear($date)||$date<'2025-07-01'||$date>'2026-12-31'
            ||!is_int($months)||$months<12||$months>1200||$months%12!==0
            ||!is_int($plan['basis_cents']??null)||$plan['basis_cents']<=0||$plan['basis_cents']>BalanceCalculation::LIMIT
            ||($plan['residual_cents']??null)!==0||!in_array($plan['account']??'',AssetTypes::codes('movable'),true)
            ||$proof['rate_basis_points']<=0||$proof['rate_basis_points']>3000||$proof['rate_basis_points']*$months>360000)
            throw new DomainException('Degressive AfA: Ausstattung ab Juli 2025, höchstens 30 % und höchstens das Dreifache des linearen Satzes. Restwert und Sonderfälle gesondert prüfen.');
    }
    public static function schedule(array $plan,int $year,int $throughMonth=12):array
    {
        self::check($plan['degressive']??null,$plan);
        if($year<1900||$year>2200||$throughMonth<1||$throughMonth>12)throw new DomainException('Jahr und Monat prüfen.');
        $start=(int)substr($plan['acquired_on'],0,4);$month=(int)substr($plan['acquired_on'],5,2);
        $basis=$plan['basis_cents'];$value=$basis;$elapsed=0;$linear=null;$switch=null;
        $out=['year'=>$year,'months'=>0,'opening_cents'=>$basis,'depreciation_cents'=>0,'closing_cents'=>$basis,'switched_in'=>null];
        for($y=$start;$y<=$year;$y++){
            $active=$y===$start?13-$month:12;$remaining=max(0,$plan['months']-$elapsed);
            $opening=$value;$charge=0;
            if($value>0){
                $degressive=intdiv($value*$plan['degressive']['rate_basis_points']*$active+60000,120000);
                $linearAmount=$remaining>0?intdiv($value*min($remaining,$active)+intdiv($remaining,2),$remaining):$value;
                if($linear===null&&$plan['degressive']['switch_to_linear']&&$linearAmount>=$degressive){
                    $linear=['basis'=>$value,'months'=>max(1,$remaining),'used'=>0];$switch=$y;
                }
                // Decide the method at the year's start, then prorate only the final period.
                if($y===$year){$active=max(0,$throughMonth-($y===$start?$month-1:0));$degressive=intdiv($value*$plan['degressive']['rate_basis_points']*$active+60000,120000);}
                if($linear!==null){
                    $before=$linear['used'];$linear['used']=min($linear['months'],$before+$active);
                    $rounded=static fn(int $n):int=>intdiv($linear['basis']*$n+intdiv($linear['months'],2),$linear['months']);
                    $charge=$rounded($linear['used'])-$rounded($before);
                }else $charge=$degressive;
                $charge=min($value,$charge);$value-=$charge;
            }
            $elapsed+=$active;
            $out=['year'=>$y,'months'=>$active,'opening_cents'=>$opening,'depreciation_cents'=>$charge,'closing_cents'=>$value,'switched_in'=>$switch];
        }
        return $out;
    }
}
