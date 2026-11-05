<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceTaxValues.php';

/** Original whole-club B/GuV AND reviewed taxable SGEB, never a taxable whole club. */
final class BalanceCombinedClubValues
{
    public static function build(array $state,array $review,?array $previous=null):array
    {
        if($previous!==null){
            $p=$previous;unset($p['sha256'],$p['transmission']);
            if(array_keys($p)!==['schema','kind','year','journal_sha256','whole','taxable','reconciliation']||$p['schema']!==1||$p['kind']!=='tax_privileged_club_with_sgeb'
                ||($previous['transmission']??null)!=='unavailable'||($previous['sha256']??null)!==BalanceSegmentCalculation::hash($p))throw new DomainException('Unveränderten gemeinsamen Vorjahresabschluss nachweisen.');
            $pw=BalanceWholeClubReport::rebuild($p['whole']['whole_club_scope']??[]);
            if(BalanceWholeClubReport::fromReport($p['taxable']['club_scope']['source']??[])!==$pw||$p['journal_sha256']!==$pw['closed_sha256']||$p['year']!==$pw['year'])throw new DomainException('Vorjahres-Gesamtbilanz und Teilbilanz gehören nicht zusammen.');
            $pr=['year'=>$pw['year'],'journal_sha256'=>$pw['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
            if(BalanceTaxValues::books($pw,$p['whole']['own_hgb_sources']??null)!==$p['whole'])throw new DomainException('Gesamtvereinswerte des Vorjahres wurden verändert.');
        }
        $prior=$previous===null?null:BalanceClubTaxReport::rebuild($previous['taxable']['club_scope']??[]);
        $taxable=BalanceClubTaxReport::fromState($state,$prior);
        $whole=BalanceWholeClubReport::fromReport($state['journal']);
        if($whole['previous_sha256']!==null){
            if(!isset($pw)||$whole['previous_sha256']!==$pw['closed_sha256']||$whole['year']!==$pw['year']+1)throw new DomainException('Zugehörigen Gesamtabschluss des Vorjahres nachweisen.');
            $net=static function(array $lines):array{$out=[];foreach($lines as $line)$out[$line['account']]=$line['debit_cents']-$line['credit_cents'];ksort($out);return $out;};
            if($net(BalanceCalculation::carryForward($pw['calculation'],$whole['result_account']))!==$net($whole['opening']))throw new DomainException('Anfangsbestände des Gesamtvereins stimmen nicht mit dem Vorjahr überein.');
        }elseif($previous!==null)throw new DomainException('Dieser Erstabschluss hat keinen Vorjahresbezug.');
        return self::fromReport($taxable,$review);
    }

    /** The service obtains this report from current authorized allocation history. */
    public static function fromReport(array $taxable,array $review,?array $ownHgb=null,?array $pensionHgb=null):array
    {
        if(BalanceClubTaxReport::rebuild($taxable['club_scope']??[])!==$taxable)throw new DomainException('Unveränderten steuerlichen Teilabschluss nachweisen.');
        $scope=$taxable['club_scope'];$whole=BalanceWholeClubReport::fromReport($scope['source']);
        // Check every whole-club opening, not merely the taxable portion. Historic
        // tax differences are separately carried by the filing service and receipt.
        while($scope['previous']!==null){
            $current=BalanceWholeClubReport::fromReport($scope['source']);$prior=BalanceWholeClubReport::fromReport($scope['previous']['source']);
            $net=static function(array $lines):array{$out=[];foreach($lines as $line)$out[$line['account']]=$line['debit_cents']-$line['credit_cents'];ksort($out);return $out;};
            if($current['previous_sha256']!==$prior['closed_sha256']||$current['year']!==$prior['year']+1
                ||$net(BalanceCalculation::carryForward($prior['calculation'],$current['result_account']))!==$net($current['opening']))throw new DomainException('Anfangsbestände des Gesamtvereins stimmen nicht mit dem Vorjahr überein.');
            $scope=$scope['previous'];
        }
        if(array_diff(array_keys($review),['year','journal_sha256','complete','taxable_review'])
            ||($review['year']??null)!==$whole['year']||($review['journal_sha256']??null)!==$whole['closed_sha256']
            ||($review['complete']??null)!==true||!is_array($review['taxable_review']??null))throw new DomainException('Gesamtabschluss und zugehörige steuerliche Teilbilanz gemeinsam bestätigen.');
        // Whole-club values remain the original books, not the taxable subset plus
        // invented exempt balances. Only the taxable section accepts tax changes.
        $bookReview=['year'=>$whole['year'],'journal_sha256'=>$whole['closed_sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[]];
        $taxReview=$review['taxable_review'];
        if(array_diff(array_keys($taxReview),['year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans','own_intangible_sources']))throw new DomainException('Wertkorrekturen ausschließlich dem steuerpflichtigen Teil zuordnen.');
        $w=BalanceTaxValues::books($whole,$ownHgb,$pensionHgb);$t=BalanceTaxValues::build($taxable,$taxReview);
        if(array_diff($t['source_spheres'],['4']))throw new DomainException('Steuerliche Korrekturen dürfen keine steuerfreien Vereinsbereiche enthalten.');
        $payload=['schema'=>1,'kind'=>'tax_privileged_club_with_sgeb','year'=>$w['year'],'journal_sha256'=>$w['journal_sha256'],
            'whole'=>$w,'taxable'=>$t,'reconciliation'=>self::reconciliation($w,$t)];
        return $payload+['sha256'=>BalanceSegmentCalculation::hash($payload),'transmission'=>'unavailable'];
    }

    /** Account and result reconciliation is evidence, never an extra journal. */
    public static function reconciliation(array $whole,array $taxable):array
    {
        $rows=[];$eliminations=[];$allocation=$taxable['period_club']['income']['scope']['allocation']??$taxable['club_scope']['allocation'];
        foreach($allocation['transfers']??[] as $transfer){
            $selected=0;foreach($transfer['parts'] as $unit=>$net)if($allocation['units'][$unit]['sphere']==='4')$selected+=$net;
            $eliminations[$transfer['account']]=($eliminations[$transfer['account']]??0)+abs($selected);
        }
        foreach($whole['book']['accounts'] as $key=>$a){
            $t=$taxable['book']['accounts'][$key];$tax=$taxable['tax']['accounts'][$key];$r=[];
            foreach(['opening_cents','debit_cents','credit_cents','amount_cents'] as $field)$r[$field]=[
                'whole'=>$a[$field],'taxable_book'=>$t[$field],'other_areas'=>$a[$field]-$t[$field],
                'tax_adjustment'=>$tax[$field]-$t[$field],'taxable_tax'=>$tax[$field]];
            if(!empty($allocation['transfers']))foreach($r as $field=>&$value){
                $eliminated=in_array($field,['debit_cents','credit_cents'],true)?($eliminations[$key]??0):0;
                $value['other_areas']+=$eliminated;$value['internal_elimination']=$eliminated;
            }unset($value);
            $rows[$key]=$r;
        }
        $w=$whole['book']['annual_result_cents'];$b=$taxable['book']['annual_result_cents'];$t=$taxable['tax']['annual_result_cents'];
        return ['accounts'=>$rows,'result'=>['whole'=>$w,'taxable_book'=>$b,'other_areas'=>$w-$b,'tax_adjustment'=>$t-$b,'taxable_tax'=>$t]];
    }
}
