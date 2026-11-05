<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalanceClearings.php';
require_once __DIR__.'/BalancePeriodAssetAllocation.php';

/** Read-only bridge. Legacy journals/reviews keep their original hashes and format. */
final class BalanceCalendarAreaSource
{
    public function __construct(private PDO $db,private string $actor){}

    public function current(int $year):array
    {
        $a=Permissions::actor($this->db,$this->actor);
        $has=(bool)DocumentCatalog::query($this->db,'SELECT 1 FROM balance_clearing_reviews WHERE club_id=? AND tax_year=? LIMIT 1',[$a['club_id'],$year])->fetchColumn();
        return $this->read('calendar:'.$year.':'.($has?'clearing':'segments'));
    }

    /** A stored reference is reconstructed independently of subsequent approvals. */
    public function read(string $id,?array $reference=null,int $depth=0):array
    {
        return $reference===null?$this->liveRead($id,null,$depth):$this->original($id,$reference,$depth);
    }
    private function liveRead(string $id,?array $reference=null,int $depth=0):array
    {
        if($depth>100||!preg_match('/^calendar:([0-9]{4}):(segments|clearing)$/D',$id,$m))throw new DomainException('Ursprüngliche Kalender-Bereichsaufteilung auswählen.');
        $year=(int)$m[1];$kind=$m[2];$actor=Permissions::actor($this->db,$this->actor);
        $segments=(new BalanceSegments($this->db,$this->actor))->get($year);
        $state=$kind==='clearing'?(new BalanceClearings($this->db,$this->actor))->get($year):$segments;
        $revision=$reference['revision']??$state['version'];
        if(!is_int($revision)||$revision<1)throw new DomainException('Bereichsaufteilung des Kalenderjahres zuerst bestätigen.');
        $row=$state['history'][$revision-1]??null;
        if(!$row||$row['state']!=='reviewed'||($reference!==null&&($reference['period_id']!==$id||$reference['sha256']!==$row['sha256'])))throw new DomainException('Unveränderten bestätigten Kalendernachweis vorlegen.');
        $segment=$kind==='clearing'?($segments['history'][$row['segment_source']['revision']-1]??null):$row;
        if(!$segment||($kind==='clearing'&&$segment['sha256']!==$row['segment_source']['sha256']))throw new DomainException('Originalaufteilung des Kalenderjahres fehlt.');
        $report=$segment['journal_sha256']===$segments['journal']['closed_sha256']?$segments['journal']:(new BalanceLedger($this->db,$this->actor))->archivedReport($year,$segment['journal_sha256']);
        $allocation=$segment['allocation'];$prior=null;
        if($report['previous_sha256']!==null){
            $carry=$kind==='clearing'?$row['carry_source']:$segment['carry_forward'];
            if(!is_array($carry)||($carry['year']??null)!==$year-1)throw new DomainException('Vollständigen Bereichsvortrag des Kalenderjahres nachweisen.');
            $priorKind=$kind==='clearing'||isset($carry['clearing'])?'clearing':'segments';
            $r=$kind==='segments'&&isset($carry['clearing'])?$carry['clearing']:$carry;
            $priorId='calendar:'.($year-1).':'.$priorKind;
            $prior=$this->liveRead($priorId,['period_id'=>$priorId,'revision'=>$r['revision'],'sha256'=>$r['sha256']],$depth+1);
        }
        $clearing=$kind==='clearing'?$row['proof']:BalanceInternalClearing::build($actor['club_id'],$report,$allocation['units'],$allocation['allocations'],['advances'=>[],'repayments'=>[]],$prior['proof']['clearing']??null);
        $statement=BalanceClearingStatement::build($report,$allocation,$clearing);
        if(!$statement['complete'])throw new DomainException('Bereiche und Vorschüsse des Kalenderjahres vollständig abstimmen.');
        if($prior!==null){
            $expected=BalanceClearingStatement::carry($prior['source'],$prior['proof']['allocation'],$prior['proof']['clearing'],$report);
            foreach($expected['allocations'] as $key=>$value)if(($allocation['allocations'][$key]['parts']??null)!==$value['parts'])throw new DomainException('Kalender-Anfangsbestände stimmen nicht mit der Bereichsaufteilung überein.');
            if(($allocation['capital_carry']??[])!==($expected['capital_carry']??[]))throw new DomainException('Kapitalvortrag des Kalenderjahres prüfen.');
        }
        $source=array_intersect_key($report,array_flip(['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule']));
        $proof=['allocation'=>$allocation,'clearing'=>$clearing,'statement'=>$statement,'issues'=>[],'complete'=>true,'tax_export_ready'=>false];
        $assets=BalancePeriodAssetAllocation::calendar($report,$allocation);if($assets!==null)$proof['assets']=$assets;
        if($reference!==null&&isset($reference['proof'])&&$reference['proof']!==$proof)throw new DomainException('Gespeicherter Kalender-Bereichsnachweis wurde verändert.');
        $current=$state['current_approval']&&$revision===$state['version']&&($prior===null||$prior['current_approval']);
        // A newly introduced advance review must not be bypassed by an old plain allocation.
        if($kind==='segments'&&DocumentCatalog::query($this->db,'SELECT 1 FROM balance_clearing_reviews WHERE club_id=? AND tax_year=? LIMIT 1',[$actor['club_id'],$year])->fetchColumn())$current=false;
        return ['period_id'=>$id,'year'=>$year,'version'=>$revision,'sha256'=>$row['sha256'],'source'=>$source,'proof'=>$proof,
            'input'=>['units'=>$allocation['units'],'allocations'=>$allocation['allocations'],'capital_carry'=>$allocation['capital_carry']??[],'transfers'=>$allocation['transfers']??[],'instructions'=>$clearing['instructions']],
            'review'=>['allocation'=>['revision'=>$segment['revision'],'sha256'=>$segment['sha256']],'clearing'=>['revision'=>$revision,'sha256'=>$row['sha256']]],
            'prior'=>$prior,'current_approval'=>$current,'tax_export_ready'=>false];
    }

    /** Independently selected actual originals. This path grants no current approval. */
    private function original(string $id,array $reference,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Verkettung der ursprünglichen Kalender-Bereichsaufteilung prüfen.');
        require_once __DIR__.'/BalanceReadSnapshot.php';
        return BalanceReadSnapshot::remember($this->db,__METHOD__,[$this->actor,$id,$reference],fn()=>$this->originalUncached($id,$reference,$depth));
    }
    private function originalUncached(string $id,array $reference,int $depth):array
    {
        if($depth>100||!preg_match('/\Acalendar:([0-9]{4}):(segments|clearing)\z/D',$id,$m)||array_diff(array_keys($reference),['period_id','revision','sha256','proof'])||($reference['period_id']??null)!==$id||!is_int($reference['revision']??null)||$reference['revision']<1||$reference['revision']>1000||!is_string($reference['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$reference['sha256']))throw new DomainException('Ursprünglichen Kalender-Bereichsnachweis vollständig auswählen.');
        $year=(int)$m[1];$kind=$m[2];$actor=Permissions::actor($this->db,$this->actor);
        if($kind==='clearing'){$details=(new BalanceClearings($this->db,$this->actor))->originalDetails($year,$reference['revision'],$reference['sha256']);$row=$details['original'];$basis=$details['segments'];$segment=end($basis['history']);}
        else{$basis=(new BalanceSegments($this->db,$this->actor))->originalState($year,$reference['revision'],$reference['sha256']);$row=end($basis['history']);$segment=$row;}
        if(!$row||$row['state']!=='reviewed'||$row['revision']!==$reference['revision']||$row['sha256']!==$reference['sha256'])throw new DomainException('Ursprünglichen bestätigten Kalendernachweis auswählen.');
        $report=$basis['journal'];$allocation=$segment['allocation'];$prior=null;
        if($report['previous_sha256']!==null){
            $carry=$kind==='clearing'?$row['carry_source']:$segment['carry_forward'];
            if(!is_array($carry)||($carry['year']??null)!==$year-1)throw new DomainException('Vollständigen Bereichsvortrag des Kalenderjahres nachweisen.');
            $priorKind=$kind==='clearing'||isset($carry['clearing'])?'clearing':'segments';$r=$kind==='segments'&&isset($carry['clearing'])?$carry['clearing']:$carry;$priorId='calendar:'.($year-1).':'.$priorKind;
            $prior=$this->original($priorId,['period_id'=>$priorId,'revision'=>$r['revision'],'sha256'=>$r['sha256']],$depth+1);
        }
        $clearing=$kind==='clearing'?$row['proof']:BalanceInternalClearing::build($actor['club_id'],$report,$allocation['units'],$allocation['allocations'],['advances'=>[],'repayments'=>[]],$prior['proof']['clearing']??null);
        $statement=BalanceClearingStatement::build($report,$allocation,$clearing);if(!$statement['complete'])throw new DomainException('Bereiche und Vorschüsse des Kalenderjahres vollständig abstimmen.');
        if($prior!==null){$expected=BalanceClearingStatement::carry($prior['source'],$prior['proof']['allocation'],$prior['proof']['clearing'],$report);foreach($expected['allocations']as$key=>$value)if(($allocation['allocations'][$key]['parts']??null)!==$value['parts'])throw new DomainException('Kalender-Anfangsbestände stimmen nicht mit der Bereichsaufteilung überein.');if(($allocation['capital_carry']??[])!==($expected['capital_carry']??[]))throw new DomainException('Kapitalvortrag des Kalenderjahres prüfen.');}
        $source=array_intersect_key($report,array_flip(['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule']));
        $proof=['allocation'=>$allocation,'clearing'=>$clearing,'statement'=>$statement,'issues'=>[],'complete'=>true,'tax_export_ready'=>false];$assets=BalancePeriodAssetAllocation::calendar($report,$allocation);if($assets!==null)$proof['assets']=$assets;
        if(array_key_exists('proof',$reference)&&$reference['proof']!==$proof)throw new DomainException('Gespeicherter Kalender-Bereichsnachweis wurde verändert.');
        return ['period_id'=>$id,'year'=>$year,'version'=>$row['revision'],'sha256'=>$row['sha256'],'source'=>$source,'proof'=>$proof,'input'=>['units'=>$allocation['units'],'allocations'=>$allocation['allocations'],'capital_carry'=>$allocation['capital_carry']??[],'transfers'=>$allocation['transfers']??[],'instructions'=>$clearing['instructions']],
            'review'=>['allocation'=>['revision'=>$segment['revision'],'sha256'=>$segment['sha256']],'clearing'=>['revision'=>$row['revision'],'sha256'=>$row['sha256']]],'prior'=>$prior,'current_approval'=>false,'tax_export_ready'=>false];
    }
    public function originalIncome(string $id,int $revision,string $sha256,int $depth=0):array
    {
        return $this->originalIncomeFromState($this->original($id,['period_id'=>$id,'revision'=>$revision,'sha256'=>$sha256],$depth),$depth);
    }
    private function originalIncomeFromState(array $state,int $depth):array
    {
        if($depth>100)throw new DomainException('Verkettung des ursprünglichen Kalender-Ergebnisabgleichs prüfen.');
        $previous=$state['prior']===null?null:$this->originalIncomeFromState($state['prior'],$depth+1);
        $keys=['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'];$source=array_replace(array_fill_keys($keys,null),array_intersect_key($state['source'],array_flip($keys)));
        return BalanceClubIncomeReconciliation::rebuild(['schema'=>1,'kind'=>'club_income_reconciliation','club_id'=>$state['proof']['clearing']['club_id'],'source'=>$source,'allocation'=>$state['proof']['allocation'],'clearing'=>$state['proof']['clearing'],'review'=>$state['review'],'previous'=>$previous['scope']??null]);
    }
    public function income(array $state):array
    {
        if(!$state['current_approval'])throw new DomainException('Kalender-Bereichsaufteilung zuerst erneut bestätigen.');
        $previous=$state['prior']===null?null:$this->income($state['prior']);
        return BalanceClubIncomeReconciliation::fromCalendarAreas($state,$previous);
    }
}
