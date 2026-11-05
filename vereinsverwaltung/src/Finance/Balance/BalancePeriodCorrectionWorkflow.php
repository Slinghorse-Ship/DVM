<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceClosingCorrection.php';

/** Uses the period ledger's immutable event log, lock and repeated authorization. */
trait BalancePeriodCorrectionWorkflow
{
    private static function correctionBase(array $r):array
    {
        return ['year'=>$r['year'],'period'=>$r['period'],'state'=>'closed','closed_sha256'=>$r['state']==='closed'?$r['closed_sha256']:$r['journal_sha256'],
            'previous_sha256'=>$r['previous']['sha256']??null,'result_account'=>$r['result_account'],'accounts'=>$r['accounts'],
            'opening'=>$r['opening'],'entries'=>array_values($r['entries']),'calculation'=>$r['calculation']];
    }
    private function correctionPrior(array $r):?array
    {
        $ref=$r['previous'];if($ref===null)return null;
        if($ref['kind']==='calendar'){
            $prior=(new BalanceLedger($this->db,$this->actorId))->report((int)$ref['id']);
            if($prior['state']!=='closed'||$prior['issues']||$prior['calculation']['issues']
                ||BalanceCalculation::build($prior['year'],$prior['accounts'],$prior['opening'],array_values($prior['entries']))!==$prior['calculation'])throw new DomainException('Kalenderabschluss zuerst vollständig prüfen und freigeben.');
            // Normalize the proven calendar interval for the dated calculation only.
            // Keep the original/corrected closing hash; never rewrite its stored journal.
            $prior['period']=BalancePeriod::calendar($prior['year']);
            $prior['calculation']=BalanceCalculation::build($prior['year'],$prior['accounts'],$prior['opening'],array_values($prior['entries']),$prior['period']);
            $base=self::correctionBase($prior);$base['previous_sha256']=$prior['previous_sha256'];return $base;
        }
        if($ref['kind']!=='period')throw new DomainException('Vorherigen Abschlusszeitraum prüfen.');
        $prior=$this->report($ref['id']);
        if($prior['state']!=='closed'||$prior['issues']||$prior['calculation']['issues'])throw new DomainException('Vorherigen Abschlusszeitraum zuerst vollständig prüfen und freigeben.');
        return self::correctionBase($prior);
    }
    private static function correctionTarget(array $r,int $version,string $hash,string $state):void
    {
        $actual=$state==='closed'?$r['closed_sha256']:$r['journal_sha256'];
        if($r['state']!==$state||$r['version']!==$version||!is_string($actual)||!hash_equals($actual,$hash))throw new DomainException('Buchungsstand wurde geändert. Bitte neu laden und erneut prüfen.');
        if($r['source_issues']||$r['calculation']['issues'])throw new DomainException('Belege, Anlagen und Buchungswerte zuerst abstimmen.');
    }
    /** Reuse only the first exact event after the original client revision. */
    private function repeatCorrection(array $actor,array $current,int $version,string $source,string $kind,callable $same,bool $journal=false):?array
    {
        if($current['version']===$version||$version<1||$version>=20000)return null;
        $row=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=?',[$actor['club_id'],$current['id'],$version+1])->fetch();
        if(!$row)return null;$row['revision']=(int)$row['revision'];$row['payload']=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);$p=$row['payload'];
        if($p['kind']!==$kind||$p['actor']!==$actor['id'])return null;
        $before=$this->reportUncached($current['id'],0,false,$version);
        if($before['version']!==$version||($journal?$before['journal_sha256']:$before['closed_sha256'])!==$source)return null;
        try{return $same($row,$before)?$row:null;}catch(DomainException){return null;}
    }
    public function originalCorrection(string $id,int $revision,string $sha256):array
    {
        $this->report($id);$a=$this->access();
        if($revision<1||$revision>20000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);
        $row=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=?',[$a['club_id'],$id,$revision])->fetch();
        if(!$row||!hash_equals($row['sha256'],$sha256))throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);
        $row['revision']=(int)$row['revision'];$row['payload']=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        if(!in_array($row['payload']['kind'],['correction_prepared','correction_confirmed','correction_discarded','carry_refreshed','reaffirmed'],true))throw new DomainException('Ursprünglichen Vorgang dieses Abschlusszeitraums nicht gefunden.',404);
        return $row;
    }
    public function originalCorrectionDetails(string $id,int $revision,string $sha256):array
    {
        $event=$this->originalCorrection($id,$revision,$sha256);$before=$this->reportUncached($id,0,false,$revision-1);$after=$this->reportUncached($id,0,false,$revision);
        $proof=match($event['payload']['kind']){
            'correction_prepared','carry_refreshed'=>$event['payload']['data']['proof'],
            'correction_confirmed','correction_discarded'=>$before['correction_draft']['proof'],
            'reaffirmed'=>$before['correction']['proof']??null,
        };
        return ['event'=>$event,'proof'=>$proof,'calculation'=>$after['calculation']];
    }
    public function prepareClosingCorrection(string $id,int $version,string $baseHash,array $input):array
    {
        return $this->transaction(function($a)use($id,$version,$baseHash,$input){
            $r=$this->report($id);$first=$this->repeatCorrection($a,$r,$version,$baseHash,'correction_prepared',static fn($e,$before):bool=>BalanceClosingCorrection::build(self::correctionBase($before),$input,$e['payload']['data']['prior'])===$e['payload']['data']['proof']);if($first!==null)return $first;
            self::correctionTarget($r,$version,$baseHash,'closed');
            $prior=$this->correctionPrior($r);$proof=BalanceClosingCorrection::build(self::correctionBase($r),$input,$prior);
            return $this->append($a,$id,$version,$r['journal_sha256'],'correction_prepared',['proof'=>$proof,'prior'=>$prior]);
        });
    }
    public function confirmClosingCorrection(string $id,int $version,string $baseHash,string $proofHash,bool $reviewed,string $note):array
    {
        return $this->transaction(function($a)use($id,$version,$baseHash,$proofHash,$reviewed,$note){
            BoardAccess::require($this->db,$this->actorId);$r=$this->report($id);$note=self::note($note);
            $first=$this->repeatCorrection($a,$r,$version,$baseHash,'correction_confirmed',static fn($e,$before):bool=>$reviewed&&$e['payload']['data']['proof_sha256']===$proofHash&&$e['payload']['data']['evidence']===$note);if($first!==null)return $first;
            self::correctionTarget($r,$version,$baseHash,'closed');
            $draft=$r['correction_draft'];
            if(!$reviewed||$draft===null||!hash_equals($draft['proof']['sha256'],$proofHash))throw new DomainException('Korrekturentwurf und Nachweise ausdrücklich prüfen.');
            $fresh=BalanceClosingCorrection::build(self::correctionBase($r),$draft['proof']['input'],$this->correctionPrior($r));
            if($fresh!==$draft['proof'])throw new DomainException('Vorzeitraum oder Korrektur geändert. Entwurf erneut vorbereiten.');
            return $this->append($a,$id,$version,$r['journal_sha256'],'correction_confirmed',['proof_sha256'=>$proofHash,'evidence'=>self::note($note)]);
        });
    }
    public function discardClosingCorrection(string $id,int $version,string $baseHash,string $note):array
    {
        return $this->transaction(function($a)use($id,$version,$baseHash,$note){
            $r=$this->report($id);$note=self::note($note);
            $first=$this->repeatCorrection($a,$r,$version,$baseHash,'correction_discarded',static fn($e,$before):bool=>$e['payload']['data']['evidence']===$note);if($first!==null)return $first;
            if($r['state']!=='closed'||$r['version']!==$version||!hash_equals($r['closed_sha256'],$baseHash)||$r['correction_draft']===null)throw new DomainException('Korrekturentwurf erneut laden.');
            return $this->append($a,$id,$version,$r['journal_sha256'],'correction_discarded',['evidence'=>self::note($note)]);
        });
    }
    public function refreshPeriodOpening(string $id,int $version,string $journalHash,bool $reviewed,string $note):array
    {
        return $this->transaction(function($a)use($id,$version,$journalHash,$reviewed,$note){
            BoardAccess::require($this->db,$this->actorId);
            $r=$this->report($id);$note=self::note($note);
            $first=$this->repeatCorrection($a,$r,$version,$journalHash,'carry_refreshed',static fn($e,$before):bool=>$reviewed&&BalanceClosingCorrection::build(self::correctionBase($before),['kind'=>'carry','reason'=>$note,'legal_basis'=>'Unveränderter Vortrag aus dem geprüften Vorzeitraum','confirmed'=>true,'entries'=>[]],$e['payload']['data']['prior'])===$e['payload']['data']['proof'],true);if($first!==null)return $first;
            self::correctionTarget($r,$version,$journalHash,'open');
            if(!$reviewed)throw new DomainException('Anfangsbestände aus dem berichtigten Vorzeitraum ausdrücklich prüfen.');
            $prior=$this->correctionPrior($r);$note=self::note($note);
            $proof=BalanceClosingCorrection::build(self::correctionBase($r),['kind'=>'carry','reason'=>$note,'legal_basis'=>'Unveränderter Vortrag aus dem geprüften Vorzeitraum','confirmed'=>true,'entries'=>[]],$prior);
            return $this->append($a,$id,$version,$r['journal_sha256'],'carry_refreshed',['proof'=>$proof,'prior'=>$prior]);
        });
    }
    /** Recompute the frozen input, not merely its hash, when reading historical events. */
    private static function replayPeriodCorrection(array &$r,array $event,array $payload):bool
    {
        $kind=$payload['kind'];$v=$payload['data'];
        if(!in_array($kind,['correction_prepared','correction_confirmed','correction_discarded','carry_refreshed'],true))return false;
        if($r['state']!==($kind==='carry_refreshed'?'open':'closed'))throw new DomainException('Abschlusskorrektur im falschen Buchungsstand.');
        $r['calculation']=BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],array_values($r['entries']),$r['period']);
        if(in_array($kind,['correction_prepared','carry_refreshed'],true)){
            $proof=BalanceClosingCorrection::build(self::correctionBase($r),$v['proof']['input'],$v['prior']);
            if($proof!==$v['proof']||($kind==='carry_refreshed'&&$proof['input']['kind']!=='carry'))throw new DomainException('Korrekturwerte im Prüfverlauf stimmen nicht überein.');
            if($kind==='correction_prepared'){$r['correction_draft']=$v;return true;}
        }elseif($kind==='correction_discarded'){
            if($r['correction_draft']===null)throw new DomainException('Verworfener Korrekturentwurf fehlt.');
            self::note($v['evidence']);$r['correction_draft']=null;return true;
        }else{
            $proof=$r['correction_draft']['proof']??null;
            if($proof===null||$proof['base_sha256']!==$r['closed_sha256']||!hash_equals($proof['sha256'],$v['proof_sha256']))throw new DomainException('Bestätigung passt nicht zum Korrekturentwurf.');
            $r['original_closed_sha256']??=$r['closed_sha256'];$r['closed_sha256']=$event['sha256'];
            $r['closing_review']=['actor'=>$payload['actor'],'at'=>$payload['at'],'checks'=>$r['closing_review']['checks'],'evidence'=>self::note($v['evidence'])];
            $r['correction']=['proof'=>$proof,'confirmed_at'=>$payload['at']];$r['correction_draft']=null;
        }
        $r['opening']=$proof['opening'];$r['entries']=array_column($proof['entries'],null,'id');$r['calculation']=$proof['calculation'];
        if($r['previous']!==null)$r['previous']['sha256']=$proof['prior_sha256'];
        return true;
    }
}
