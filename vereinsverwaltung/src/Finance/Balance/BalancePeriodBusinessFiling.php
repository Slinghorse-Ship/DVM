<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodWholeFiling.php';

/** One separately kept business journal, never an implicitly filtered club. */
final class BalancePeriodBusinessFiling
{
    public function __construct(private PDO $db,private string $actor){}
    public static function assertSource(array $source):void
    {
        if(isset($source['club_scope'])||isset($source['whole_club_scope'])||isset($source['period_club_scope']))throw new DomainException('Eigenes Journal des Geschäftsbetriebs auswählen, keine Vereinsaufteilung.');
        if(!is_array($source['opening']??null)||!is_array($source['entries']??null)||!is_array($source['asset_schedule']['items']??null))throw new DomainException('Vollständiges Betriebsjournal einschließlich Anlagen nachweisen.');
        foreach(array_merge([['lines'=>$source['opening']]],$source['entries']) as $entry)foreach($entry['lines'] as $line)
            if(!in_array($line['sphere'],['','4'],true))throw new DomainException('Dieses Journal enthält andere Vereinsbereiche. Gesamtbilanz und steuerliches Ergebnis verwenden.');
        foreach($source['asset_schedule']['items'] as $item)
            if(($item['plan']['sphere']??$item['sphere']??null)!=='4'||isset($item['sphere_change']))throw new DomainException('Anlagen anderer Vereinsbereiche benötigen die geprüfte Gesamtbilanz und steuerliche Nebenrechnung.');
    }
    public function current(string $id,int $depth=0):array
    {
        if($depth>100||!BalancePeriodLedger::enabled())throw new DomainException('Betriebsabschluss und Vorzeitraumkette prüfen.');
        $original=(new BalancePeriodLedger($this->db,$this->actor))->report($id);
        if(!in_array($original['year'],[2025,2026],true)||$original['period']['start']<'2025-01-01'||!$original['approval_current'])throw new DomainException('Geprüften Abschlusszeitraum für die E-Bilanz auswählen.');
        $original['previous_sha256']=$original['previous']['sha256']??null;$original['entries']=array_values($original['entries']);
        $r=BalanceWholeClubReport::fromReport($original)['whole_club_scope']['source'];self::assertSource($r);
        // An empty successor is not a new independent business. Its opening may
        // include other spheres even when no current income carries a sphere.
        if($original['previous']!==null){
            $ref=$original['previous'];
            $prior=$ref['kind']==='period'?$this->current($ref['id'],$depth+1):(new BalanceLedger($this->db,$this->actor))->report((int)$ref['id']);
            self::assertSource($prior);
            if($prior['closed_sha256']!==$ref['sha256'])throw new DomainException('Ursprünglichen Betriebs-Vorabschluss unverändert nachweisen.');
        }
        return $r;
    }
    public static function matches(array $b,array $r):void
    {
        BalancePeriodFilingSource::binding($b);self::assertSource($r);
        if($b['period']!==$r['period']||($b['business_source']??null)!==$r||($b['journal_sha256']??null)!==$r['closed_sha256']
            ||($b['closing_source_sha256']??null)!==$r['closed_sha256'])throw new DomainException('Geschäftsbetrieb, Abschluss oder Zeitraum geändert. Einen neuen Entwurf anlegen.');
    }
    public function historical(array $b,string $hash):array
    {
        if(!is_array($b['business_source']??null))throw new DomainException('Ursprüngliches Betriebsjournal fehlt.');
        $r=$b['business_source'];self::matches($b,$r);
        $whole=$b;$whole['whole_source']=BalanceWholeClubReport::fromReport($r)['whole_club_scope'];
        return (new BalancePeriodWholeFiling($this->db,$this->actor))->historical($whole,$hash)['whole_club_scope']['source'];
    }
    public static function values(array $b,array $report,bool $preview=false):array
    {
        $review=$b['review'];if($preview)$review['complete']=true;
        return BalancePeriodFilingValues::build($report,$review,$b['carry_forward']['period_values']??null);
    }
    public static function carry(array $prior,array $values,array $report,array $review):array
    {
        self::assertSource($prior);self::assertSource($report);
        if(!isset($values['period'])){
            $packet=BalanceCalendarFilingSource::businessPacket($prior,$values,$review);
            return BalancePeriodTaxValues::carryForward($prior,$values,$report)+['asset_tax_plans'=>$review['asset_tax_plans']??[],'period_values'=>$packet];
        }
        $proof=$values['period_source']??null;
        if(!is_array($proof)||array_keys($proof)!==['source','review','previous']||$proof['source']!==$prior||self::values(['review'=>$review,'carry_forward'=>['period_values'=>$proof['previous']]],$prior)!==$values)throw new DomainException('Unveränderte Steuerwerte des vorherigen Geschäftsbetriebs nachweisen.');
        $raw=$values;unset($raw['period_source'],$raw['sha256'],$raw['transmission']);
        $raw+=['sha256'=>BalanceSegmentCalculation::hash($raw),'transmission'=>'unavailable'];
        return BalancePeriodTaxValues::carryForward($prior,$raw,$report)+['asset_tax_plans'=>$review['asset_tax_plans'],'period_values'=>$values];
    }
}
