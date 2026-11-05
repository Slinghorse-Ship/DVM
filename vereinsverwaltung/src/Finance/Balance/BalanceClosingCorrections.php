<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceClosingCorrection.php';

/** Append-only, club-locked amendments; never reopens the original posted journal. */
final class BalanceClosingCorrections
{
    public function __construct(private PDO $db,private string $actorId){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($write){
            if(!BalanceLedger::enabled())throw new DomainException('Bilanzbearbeitung ist nicht freigeschaltet.');
            if(((new Settings($this->db,$this->actorId))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Der Verein verwendet EÜR. Keine Bilanzkorrektur gespeichert.');
        }
        return $a;
    }
    private function state(array $a,int $year,?array $original=null,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele Korrektur-Vorjahre.');
        $original??=(new BalanceLedger($this->db,$this->actorId))->report($year,true);
        if($original['state']!=='closed')throw new DomainException('Nur einen festgeschriebenen Abschluss berichtigen.');
        $reports=[$original['closed_sha256']=>$original];$effective=$original;$history=[];$chain=null;$prior=null;
        if($original['previous_sha256']!==null)$prior=$this->state($a,$year-1,null,$depth+1);
        $rows=$this->q('SELECT revision,payload,sha256 FROM balance_closing_corrections WHERE club_id=? AND tax_year=? ORDER BY revision LIMIT 1001',[$a['club_id'],$year])->fetchAll();
        if(count($rows)>1000)throw new DomainException('Zu viele Korrekturstände.');
        foreach($rows as $i=>$row){
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','club_id','year','revision','previous_sha256','state','actor','at','note','proof']
                ||$p['schema']!==1||$p['club_id']!==$a['club_id']||$p['year']!==$year||$p['revision']!==$i+1||(int)$row['revision']!==$i+1
                ||$p['previous_sha256']!==$chain||!in_array($p['state'],['draft','reviewed','discarded','reaffirmed'],true)||!is_string($p['actor'])||$p['actor']===''
                ||!is_string($p['at'])||!is_array($p['proof'])||!hash_equals($row['sha256'],BalanceSegmentCalculation::hash($p)))throw new DomainException('Gespeicherten Korrekturverlauf prüfen.');
            BalanceClosingCorrection::note($p['note']);$proof=$p['proof'];$base=$reports[$proof['base_sha256']??'']??null;
            if(!$base||($p['state']!=='reaffirmed'&&$base['closed_sha256']!==$effective['closed_sha256']))throw new DomainException('Korrektur muss auf dem letzten bestätigten Abschluss aufbauen.');
            $before=($proof['prior_sha256']??null)===null?null:($prior['reports'][$proof['prior_sha256']]??null);
            if(BalanceClosingCorrection::build($base,$proof['input']??[],$before)!==$proof)throw new DomainException('Korrekturrechnung stimmt nicht mit den gespeicherten Abschlüssen überein.');
            $last=$history?end($history):null;
            if(in_array($p['state'],['reviewed','discarded'],true)&&(!$last||$last['state']!=='draft'||$last['proof']!==$proof))throw new DomainException('Nur den unveränderten Korrekturentwurf bestätigen oder verwerfen.');
            if($p['state']==='reaffirmed'&&(!$last||$last['state']==='draft'||$proof['sha256']!==$effective['closed_sha256']))throw new DomainException('Nur den unveränderten bestätigten Korrekturabschluss erneut prüfen.');
            if(in_array($p['state'],['reviewed','reaffirmed'],true)){
                $effective=BalanceClosingCorrection::report($base,$proof);
                $effective['review']=['actor'=>$p['actor'],'at'=>$p['at'],'note'=>$p['note']];
                $reports[$effective['closed_sha256']]=$effective;
            }
            $chain=$row['sha256'];$history[]=$p+['sha256'=>$chain];
        }
        $last=$history?end($history):null;$draft=($last['state']??null)==='draft';
        $carryCurrent=$prior===null||($prior['current']&&$effective['previous_sha256']===$prior['report']['closed_sha256']);
        $approved=true;
        if(isset($effective['correction']))try{
            $reviewer=Permissions::actor($this->db,$effective['review']['actor']);
            $approved=$reviewer['club_id']===$a['club_id']&&$reviewer['finance_access']==='write'&&BoardAccess::allowed($this->db,$reviewer['id']);
        }catch(DomainException){$approved=false;}
        return ['year'=>$year,'version'=>$last['revision']??0,'state'=>$last['state']??'unchanged','history'=>$history,'reports'=>$reports,
            'original'=>$original,'report'=>$effective,'draft'=>$draft?$last['proof']:null,'prior'=>$prior===null?null:$prior['report'],
            'prior_current'=>$prior===null||$prior['current'],'carry_current'=>$carryCurrent,'current'=>!$draft&&$approved&&$carryCurrent];
    }
    public function get(int $year,?array $original=null):array
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{$r=$this->state($this->actor(),$year,$original);if($own)$this->db->commit();return $r;}
        catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function original(int $year,int $revision,string $sha256):array
    {
        $s=$this->get($year);
        if($revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))
            throw new DomainException('Ursprünglicher Korrekturausgang nicht gefunden.',404);
        $row=$s['history'][$revision-1]??null;
        if($row===null||!hash_equals($row['sha256'],$sha256))
            throw new DomainException('Ursprünglicher Korrekturausgang nicht gefunden.',404);
        return $row;
    }
    public function report(int $year,?string $hash=null,?array $original=null):array
    {
        $s=$this->get($year,$original);
        if($hash!==null){if(!isset($s['reports'][$hash]))throw new DomainException('Dieser Abschlussstand gehört nicht zu diesem Verein und Jahr.');return $s['reports'][$hash];}
        $r=$s['report'];
        if(!$s['current'])$r['issues'][]=$s['draft']!==null?'Korrekturabschluss wartet auf Prüfung.':(!$s['carry_current']?'Berichtigten Vorjahresabschluss zuerst übernehmen und prüfen.':'Freigabe des Korrekturabschlusses erneut prüfen.');
        $r['closing_revision']=$s['version'];return $r;
    }
    private function sameSavedProof(array $a,array $s,int $year,string $baseHash,array $input,array $row):bool
    {
        $base=$s['reports'][$baseHash]??null;if($base===null)return false;
        $priorHash=$row['proof']['prior_sha256'];$prior=null;
        if($priorHash!==null){$before=$this->state($a,$year-1);$prior=$before['reports'][$priorHash]??null;if($prior===null)return false;}
        try{return BalanceClosingCorrection::build($base,$input,$prior)===$row['proof'];}
        catch(DomainException){return false;}
    }
    private function change(int $year,int $version,string $baseHash,string $note,string $expectedState,callable $same,callable $work,bool $board=false):array
    {
        if($this->db->inTransaction())throw new DomainException('Korrektur benötigt einen eigenen Speichervorgang.');
        $initial=$this->actor(true);$note=BalanceClosingCorrection::note($note);$this->db->beginTransaction();
        try{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);$a=$this->actor(true);
            if($initial['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            if($board)BoardAccess::require($this->db,$this->actorId);
            $s=$this->state($a,$year);
            if($s['version']!==$version||$version>=1000||$s['report']['closed_sha256']!==$baseHash){
                $first=$version>=0?($s['history'][$version]??null):null;
                $firstBase=$first===null?null:($expectedState==='reaffirmed'?$first['proof']['sha256']:$first['proof']['base_sha256']);
                if($first!==null&&$first['actor']===$a['id']&&$first['state']===$expectedState&&$first['note']===$note
                    &&$firstBase===$baseHash&&$same($first,$s,$a)){
                    $this->db->commit();return $first;
                }
                throw new DomainException('Abschluss inzwischen geändert. Bitte neu laden.',409);
            }
            [$status,$proof]=$work($s);
            $p=['schema'=>1,'club_id'=>$a['club_id'],'year'=>$year,'revision'=>$version+1,'previous_sha256'=>$s['history']?end($s['history'])['sha256']:null,
                'state'=>$status,'actor'=>$a['id'],'at'=>gmdate('c'),'note'=>$note,'proof'=>$proof];
            $json=json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(strlen($json)>8000000)throw new DomainException('Korrekturstand überschreitet 8 MB.');
            $hash=BalanceSegmentCalculation::hash($p);
            $this->q('INSERT INTO balance_closing_corrections(club_id,tax_year,revision,payload,sha256) VALUES(?,?,?,?,?)',[$a['club_id'],$year,$version+1,$json,$hash]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.balance.correction.'.$status,(string)$year,$version+1,$p['at']]);
            $this->db->commit();return $p+['sha256'=>$hash];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function save(int $year,int $version,string $baseHash,array $input):array
    {
        return $this->change($year,$version,$baseHash,is_string($input['reason']??null)?$input['reason']:'','draft',
            fn($row,$s,$a):bool=>$this->sameSavedProof($a,$s,$year,$baseHash,$input,$row),function($s)use($input){
            if(!$s['prior_current'])throw new DomainException('Zuerst die Korrektur des Vorjahres abschließen.');
            return ['draft',BalanceClosingCorrection::build($s['report'],$input,$s['prior'])];
        });
    }
    public function confirm(int $year,int $version,string $baseHash,string $proofHash,bool $reviewed,string $note):array
    {
        return $this->change($year,$version,$baseHash,$note,'reviewed',
            static fn($row):bool=>$reviewed&&$row['proof']['sha256']===$proofHash,function($s)use($proofHash,$reviewed){
            BoardAccess::require($this->db,$this->actorId);
            if(!$reviewed||$s['draft']===null||$s['draft']['sha256']!==$proofHash||!$s['prior_current']
                ||$s['draft']['prior_sha256']!==($s['prior']['closed_sha256']??null))throw new DomainException('Aktuellen Korrekturentwurf einschließlich Vorjahresbezug prüfen.');
            return ['reviewed',$s['draft']];
        },true);
    }
    public function discard(int $year,int $version,string $baseHash,string $note):array
    {
        return $this->change($year,$version,$baseHash,$note,'discarded',static fn($row):bool=>true,function($s){
            if($s['draft']===null)throw new DomainException('Kein offener Korrekturentwurf vorhanden.');
            return ['discarded',$s['draft']];
        });
    }
    public function reaffirm(int $year,int $version,string $baseHash,bool $reviewed,string $note):array
    {
        return $this->change($year,$version,$baseHash,$note,'reaffirmed',static fn($row):bool=>$reviewed,function($s)use($reviewed){
            BoardAccess::require($this->db,$this->actorId);
            if(!$reviewed||$s['draft']!==null||!$s['carry_current']||!isset($s['report']['correction']))throw new DomainException('Unveränderten Korrekturabschluss einschließlich des aktuellen Vorjahres prüfen.');
            foreach(array_reverse($s['history']) as $row)if(in_array($row['state'],['reviewed','reaffirmed'],true)&&$row['proof']['sha256']===$s['report']['closed_sha256'])return ['reaffirmed',$row['proof']];
            throw new DomainException('Bestätigter Korrekturstand fehlt.');
        },true);
    }
    public function files(int $year):array
    {
        $s=$this->get($year);if(!$s['history'])return [];
        $j=static fn($v)=>json_encode($v,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        return ['korrekturabschluss/urspruenglicher-abschluss.json'=>$j($s['original']),
            'korrekturabschluss/pruefverlauf.json'=>$j($s['history']),
            'korrekturabschluss/aktueller-abschluss.json'=>$j($this->report($year)),
            'korrekturabschluss/status.txt'=>($s['current']?'Abschlusskorrektur geprüft.':'Aktuelle Prüfung oder Vorjahresabgleich fehlt.')."\nKeine automatische Steuerübermittlung.\n"];
    }
}
