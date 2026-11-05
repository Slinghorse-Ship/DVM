<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodClubFilingValues.php';
require_once __DIR__.'/BalancePeriodAreas.php';
require_once __DIR__.'/BalancePeriodCombinedValues.php';

/** Persisted dated aggregate, tied to immutable approved whole-club allocation. */
final class BalancePeriodClubFiling
{
    public function __construct(private PDO $db,private string $actor){}
    public function current(string $id):array
    {
        if(!BalancePeriodLedger::enabled())throw new DomainException('Abschlusszeiträume sind hier nicht freigeschaltet.');
        $income=(new BalancePeriodAreas($this->db,$this->actor))->incomeReconciliation($id);$p=$income['scope'];$calendar=null;
        while($p['previous']!==null){$p=$p['previous'];if(!isset($p['source']['period'])){$calendar=$this->calendarReport($p['source']['year'])['club_scope'];break;}}
        $r=BalancePeriodClubReport::build($income,0,$calendar);
        if(!in_array($r['year'],[2025,2026],true)||$r['period']['start']<'2025-01-01')throw new DomainException('Geprüften Abschlusszeitraum auswählen.');return $r;
    }
    private function calendarReport(int $year,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Kalender-Betriebskette prüfen.');
        (new BalanceClearings($this->db,$this->actor))->assertExportable($year);
        $s=(new BalanceSegments($this->db,$this->actor))->get($year);
        return BalanceClubTaxReport::fromState($s,$s['journal']['previous_sha256']===null?null:$this->calendarReport($year-1,$depth+1));
    }
    public static function binding(array $r):array
    {
        $i=$r['period_club_scope']['income'];
        return ['year'=>$r['year'],'period_id'=>BalancePeriod::key($r['period']),'period'=>$r['period']]+$i['scope']['review']['allocation'];
    }
    public static function matches(array $b,array $r):void
    {
        BalancePeriodFilingSource::binding($b);
        if($b['period']!==$r['period']||($b['source_binding']??null)!==self::binding($r)||($b['period_club_source']??null)!==$r['period_club_scope']['income']
            ||($b['period_club_calendar']??null)!==($r['period_club_scope']['calendar_scope']??null)
            ||$b['journal_sha256']!==$r['closed_sha256']||$b['closing_source_sha256']!==$r['period_club_scope']['income']['scope']['source']['closed_sha256'])throw new DomainException('Betriebsaufteilung oder ursprünglicher Abschluss geändert. Neuen Entwurf anlegen.');
        if(($b['profile']['kind']??null)==='tax_privileged_club_with_sgeb'&&($b['whole_journal_sha256']??null)!==$b['closing_source_sha256'])throw new DomainException('Gesamtabschluss und Teilbilanz gehören nicht zusammen.');
    }
    public function historical(array $b,string $hash):array
    {
        if(!is_array($b['period_club_source']??null))throw new DomainException('Ursprüngliche Betriebsaufteilung fehlt.');
        $r=BalancePeriodClubReport::build($b['period_club_source'],0,$b['period_club_calendar']??null);self::matches($b,$r);$i=$b['period_club_source'];
        $state=(new BalancePeriodAreas($this->db,$this->actor))->view(BalancePeriod::key($r['period']));$ref=self::binding($r);$row=$state['history'][$ref['revision']-1]??null;
        if(!$row||$row['state']!=='reviewed'||$row['sha256']!==$ref['sha256']||$row['source']['closed_sha256']!==$hash
            ||$row['proof']['allocation']!==$i['scope']['allocation']||$row['proof']['clearing']!==$i['scope']['clearing']
            ||($row['proof']['assets']??null)!==($i['scope']['source']['assets']??null))throw new DomainException('Unveränderte ursprüngliche Bereichsfreigabe nachweisen.');return $r;
    }
    public static function values(array $b,array $r,bool $preview=false):array
    {
        $review=$b['review'];if($preview)$review['complete']=true;
        if($b['profile']['kind']==='tax_privileged_club_with_sgeb')return BalancePeriodCombinedValues::build($r['period_club_scope']['income'],$review,$b['carry_forward']['period_values']??null);
        return BalancePeriodClubFilingValues::build($r['period_club_scope']['income'],$review,$b['carry_forward']['period_values']??null);
    }
    public static function carry(array $prior,array $values,array $r,?array $review=null):array
    {
        if(!isset($values['period'])&&$review!==null){
            $income=BalanceClubIncomeReconciliation::rebuild($r['period_club_scope']['income']['scope']['previous']??[]);
            $partial=$values['taxable']??$values;
            if(isset($values['taxable'])&&BalanceCombinedClubValues::fromReport($prior,['year'=>$prior['year'],'journal_sha256'=>$income['scope']['source']['closed_sha256'],'complete'=>true,'taxable_review'=>$review])!==$values)throw new DomainException('Gemeinsamen Kalenderabschluss unverändert übernehmen.');
            $record=BalanceCalendarClubSource::record($income,$prior,$partial,$review);
            if(isset($values['taxable']))$record['combined']=$values;
            return BalancePeriodTaxValues::carryForward($prior,$partial,$r)+['asset_tax_plans'=>$review['asset_tax_plans']??[],'period_values'=>$record];
        }
        $retained=$values;
        if(($values['kind']??null)==='tax_privileged_club_with_sgeb'){
            if(BalancePeriodCombinedValues::fromPartial($values['taxable'])!==$values)throw new DomainException('Gemeinsamen Vorabschluss unverändert übernehmen.');
            $values=$values['taxable'];
        }
        if(!isset($values['period_club'])||$values['period_club']['income']!==$prior['period_club_scope']['income'])throw new DomainException('Bestätigte datierte Teilbilanz des unmittelbaren Vorzeitraums auswählen.');
        $raw=BalancePeriodClubFilingValues::raw($values);unset($raw['period_source'],$raw['sha256'],$raw['transmission']);
        $raw+=['sha256'=>BalanceSegmentCalculation::hash($raw),'transmission'=>'unavailable'];
        return BalancePeriodTaxValues::carryForward($prior,$raw,$r)+['asset_tax_plans'=>$values['period_source']['review']['asset_tax_plans'],'period_values'=>$retained];
    }
}
