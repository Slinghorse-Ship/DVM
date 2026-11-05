<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodFilingValues.php';
require_once __DIR__.'/BalancePeriodLedger.php';

/** Persisted complete, non-privileged corporation; never a filtered club area. */
final class BalancePeriodWholeFiling
{
    public function __construct(private PDO $db,private string $actor){}
    public function current(string $id):array
    {
        if(!BalancePeriodLedger::enabled())throw new DomainException('Abschlusszeiträume sind in dieser Umgebung nicht freigeschaltet.');
        $r=(new BalancePeriodLedger($this->db,$this->actor))->report($id);
        if(!in_array($r['year'],[2025,2026],true)||$r['period']['start']<'2025-01-01'||!$r['approval_current'])throw new DomainException('Geprüften Abschlusszeitraum für die E-Bilanz auswählen.');
        $r['previous_sha256']=$r['previous']['sha256']??null;$r['entries']=array_values($r['entries']);
        return BalanceWholeClubReport::fromReport($r);
    }
    public static function matches(array $b,array $r):void
    {
        BalancePeriodFilingSource::binding($b);
        if($b['period']!==$r['period']||($b['journal_sha256']??null)!==$r['closed_sha256']||($b['closing_source_sha256']??null)!==$r['closed_sha256']
            ||($b['whole_source']??null)!==$r['whole_club_scope'])throw new DomainException('Gesamtabschluss oder Zeitraum geändert. Einen neuen Entwurf anlegen.');
    }
    /** The approval signs the original full snapshot; the immutable event chain anchors its closing. */
    public function historical(array $b,string $hash):array
    {
        $id=BalancePeriodFilingSource::binding($b);
        if(!is_array($b['whole_source']??null))throw new DomainException('Ursprünglicher Gesamtabschluss fehlt.');
        $r=BalanceWholeClubReport::rebuild($b['whole_source']);self::matches($b,$r);
        $events=(new BalancePeriodLedger($this->db,$this->actor))->history($id);$found=false;
        foreach($events as $event)if($event['sha256']===$hash&&in_array($event['payload']['kind'],['closed','correction_confirmed'],true))$found=true;
        if(!$found||$r['closed_sha256']!==$hash)throw new DomainException('Ursprünglicher Abschlussnachweis fehlt.');
        return $r;
    }
    public static function values(array $b,array $report,bool $preview=false):array
    {
        $review=$b['review'];if($preview)$review['complete']=true;
        $previous=$b['carry_forward']['period_values']??null;
        if(!$preview)return BalancePeriodFilingValues::whole($report,$review,$previous);
        // An incomplete capital decision is visible and editable, but never exportable.
        $v=BalancePeriodTaxValues::build($report,$review,$previous,true);unset($v['sha256'],$v['transmission']);
        $v['whole_club_scope']=$report['whole_club_scope'];
        $v['capital_comparison']=BalanceCapitalComparison::build($report,array_replace($review,['current_adjustments'=>$v['current_adjustments']]),$v['tax']);
        return $v+['sha256'=>BalanceSegmentCalculation::hash($v),'transmission'=>'unavailable'];
    }
    public static function carry(array $prior,array $values,array $report,?array $review=null):array
    {
        if(!isset($values['period'])&&$review!==null){
            $packet=BalanceCalendarFilingSource::wholePacket($values,$review);
            return BalancePeriodTaxValues::carryForward($prior,$values,$report)+['asset_tax_plans'=>$review['asset_tax_plans']??[],'period_values'=>$packet];
        }
        if(!isset($values['period_source'],$values['whole_club_scope']))throw new DomainException('Bestätigte datierte Gesamtbilanz des unmittelbaren Vorzeitraums auswählen.');
        $raw=$values;unset($raw['period_source'],$raw['sha256'],$raw['transmission'],$raw['whole_club_scope'],$raw['capital_comparison']);
        $raw+=['sha256'=>BalanceSegmentCalculation::hash($raw),'transmission'=>'unavailable'];
        return BalancePeriodTaxValues::carryForward($prior,$raw,$report)+['asset_tax_plans'=>$values['period_source']['review']['asset_tax_plans'],'period_values'=>$values];
    }
    /** Mapping presentation needs original account/disposal metadata with the checked tax movements. */
    public static function mappingSchedule(array $values):array
    {
        $out=$values['asset_schedule'];$tax=$values['asset_tax']['tax_schedule'];
        if($tax['period']!==$out['period']||array_keys($tax['items'])!==array_keys($out['items']))throw new DomainException('Steuer-Anlagen mit dem Original abstimmen.');
        foreach($out['items'] as $id=>&$item){
            if(($tax['items'][$id]['original_sha256']??null)!==BalanceSegmentCalculation::hash($item))throw new DomainException('Ursprüngliche Steuer-Anlage nicht nachgewiesen.');
            $item['schedule']=$tax['items'][$id]['schedule'];
            if(($item['plan']['method']??null)==='cost')$item['valuation_side']=($values['period_section']??null)==='books'?'book':'tax';
            $sale=$values['asset_tax']['items'][$id]['sale']??null;if($sale!==null)$item['sale_values']=$sale['tax'];
        }unset($item);
        $out['totals']=$tax['totals'];return $out;
    }
}
