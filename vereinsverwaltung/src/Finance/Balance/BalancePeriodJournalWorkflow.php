<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Exact first ordinary decisions, projected from the actual original event prefix. */
trait BalancePeriodJournalWorkflow
{
    private function journalDecision(string$id,int$version,array$states,string$kind,callable$project,?string$expectedJournal=null,bool$board=false,?callable$fresh=null):array
    {
        self::id($id);
        if($version<1||$version>=20000||($expectedJournal!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$expectedJournal)))throw new DomainException('Ursprüngliche Angaben zum Abschlusszeitraum vollständig prüfen.',422);
        return $this->transaction(function($actor)use($id,$version,$states,$kind,$project,$expectedJournal,$board,$fresh){
            if($board)BoardAccess::require($this->db,$this->actorId);
            $current=$this->reportUncached($id,0,true);
            if($version>$current['version'])throw new DomainException('Buchungsstand inzwischen geändert. Bitte neu laden.',409);
            $before=$current['version']===$version?$current:$this->reportUncached($id,0,false,$version,false,true);
            if($expectedJournal!==null&&!hash_equals($before['journal_sha256'],$expectedJournal))throw new DomainException('Geprüfter Buchungsstand geändert. Erneut prüfen.',409);
            if(!in_array($before['state'],$states,true))throw new DomainException('Dieser Buchungsstand kann nicht mehr geändert werden.');
            $data=$project($before);
            if($current['version']!==$version){
                $first=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=?',[$actor['club_id'],$id,$version+1])->fetch();
                if($first){$first['revision']=(int)$first['revision'];$first['payload']=json_decode($first['payload'],true,512,JSON_THROW_ON_ERROR);$p=$first['payload'];if($p['actor']===$actor['id']&&$p['kind']===$kind&&$p['previous_sha256']===$before['journal_sha256']&&$p['data']===$data)return $first;}
                throw new DomainException('Buchungsstand inzwischen geändert. Bitte neu laden.',409);
            }
            if($current['issues'])throw new DomainException(implode(' ',$current['issues']));
            if($fresh!==null)$fresh($current);
            return $this->append($actor,$id,$version,$current['journal_sha256'],$kind,$data);
        });
    }
    public function creationSource():?array
    {
        $actor=$this->access();$last=$this->q('SELECT id FROM balance_periods WHERE club_id=? ORDER BY ends_on DESC LIMIT 1',[$actor['club_id']])->fetchColumn();
        if($last!==false){$prior=$this->report((string)$last);return ['kind'=>'period','id'=>(string)$last,'sha256'=>$prior['closed_sha256']??$prior['journal_sha256']];}
        $year=$this->q('SELECT tax_year FROM balance_years WHERE club_id=? ORDER BY tax_year DESC LIMIT 1',[$actor['club_id']])->fetchColumn();if($year===false)return null;
        $prior=(new BalanceLedger($this->db,$this->actorId))->report((int)$year);return ['kind'=>'calendar','id'=>(string)$year,'sha256'=>$prior['closed_sha256']??$prior['journal_sha256']];
    }
    public function creationOriginal(string$id):array
    {
        self::id($id);$actor=$this->access();$hash=$this->q('SELECT sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=1',[$actor['club_id'],$id])->fetchColumn();
        if(!is_string($hash))throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);return $this->originalEvent($id,1,$hash);
    }
    public function journalForAction(string$id,int$version,string$hash):array
    {
        if($version<1||$version>=20000||!preg_match('/\A[a-f0-9]{64}\z/D',$hash))throw new DomainException('Ursprüngliche Angaben zum Abschlusszeitraum vollständig prüfen.',422);
        $current=$this->reportUncached($id,0,false,null,false,true);if($version>$current['version'])throw new DomainException('Geprüfter Buchungsstand geändert. Erneut prüfen.',409);
        $original=$this->reportUncached($id,0,false,$version,false,true);if(!hash_equals($original['journal_sha256'],$hash))throw new DomainException('Geprüfter Buchungsstand geändert. Erneut prüfen.',409);return $original;
    }
    /** Every original event is authenticated by the complete actual stored chain. */
    public function originalEvent(string$id,int$revision,string$sha256):array
    {
        self::id($id);$actor=$this->access();
        if($revision<1||$revision>20000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);
        $current=$this->reportUncached($id,0,false,null,false,true);
        if($revision>$current['version'])throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);
        $row=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=?',[$actor['club_id'],$id,$revision])->fetch();
        if(!$row||!hash_equals($row['sha256'],$sha256))throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);
        $row['revision']=(int)$row['revision'];$row['payload']=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);return $row;
    }
    public function originalEventDetails(string$id,int$revision,string$sha256):array
    {
        $event=$this->originalEvent($id,$revision,$sha256);
        $after=$this->reportUncached($id,0,false,$revision,false,true);
        return ['event'=>$event,'report'=>$after];
    }
}
