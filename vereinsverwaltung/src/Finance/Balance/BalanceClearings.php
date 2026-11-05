<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceSegments.php';
require_once __DIR__.'/BalanceInternalClearingExport.php';
require_once __DIR__.'/BalanceClubIncomeReconciliation.php';

/** Authorized, immutable workpaper review; never treats internal advances as final funding. */
final class BalanceClearings
{
    public function __construct(private PDO $db,private string $actorId){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if(!BalanceLedger::enabled()||!Database::tableExists($this->db,'balance_clearing_reviews'))throw new DomainException('Verrechnungsnachweise sind nicht freigeschaltet.');
        if(((new Settings($this->db,$this->actorId))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Der Verein verwendet EÜR. Keine Bilanz-Verrechnung bearbeiten.');
        return $a;
    }
    private static function note(string $note):string
    {
        if(trim($note)===''||mb_strlen($note)>1000||preg_match('/[\x00-\x1f]/',$note))throw new DomainException('Prüfvermerk oder Änderungsgrund angeben.');
        return trim($note);
    }
    private static function noPermanent(array $allocation,array $instructions):void
    {
        if(array_keys($instructions)!==['advances','repayments'])throw new DomainException('Vorschüsse und Rückzahlungen getrennt angeben.');
        foreach($instructions as $rows){
            if(!is_array($rows))throw new DomainException('Verrechnungszeilen prüfen.');
            foreach($rows as $r){
                if(!is_array($r)||!is_string($r['voucher']??null))throw new DomainException('Originalbeleg der Verrechnung prüfen.');
                if(isset($allocation['transfers'][$r['voucher']]))throw new DomainException('Diese Buchung ist bereits als endgültige Mittelübertragung erfasst. Zuerst diesen Nachweis berichtigen.');
            }
        }
    }
    private function history(array $actor,int $year,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele Vorjahresnachweise.');
        $segments=new BalanceSegments($this->db,$this->actorId);$sources=[];
        $rows=$this->q('SELECT revision,payload,sha256 FROM balance_clearing_reviews WHERE club_id=? AND tax_year=? ORDER BY revision LIMIT 1001',[$actor['club_id'],$year])->fetchAll();
        if(count($rows)>1000)throw new DomainException('Zu viele Verrechnungsstände.');
        $history=[];$prior=null;$chain=null;
        foreach($rows as $i=>$row){
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','club_id','year','revision','previous_sha256','state','actor','at','note','segment_source','carry_source','proof']||$p['schema']!==1||$p['club_id']!==$actor['club_id']||$p['year']!==$year||$p['revision']!==$i+1||(int)$row['revision']!==$i+1||$p['previous_sha256']!==$chain||!in_array($p['state'],['draft','reviewed'],true)||!is_string($p['actor'])||$p['actor']===''||!is_string($p['at'])||!is_string($p['note'])||!is_array($p['proof'])||!hash_equals($row['sha256'],BalanceSegmentCalculation::hash($p)))throw new DomainException('Gespeicherten Verrechnungsverlauf prüfen.');
            self::note($p['note']);$bind=$p['segment_source'];$carry=$p['carry_source'];$previous=null;
            if(!is_array($bind)||array_keys($bind)!==['revision','sha256']||!is_int($bind['revision'])||$bind['revision']<1||$bind['revision']>1000||!is_string($bind['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$bind['sha256']))throw new DomainException('Quellbindung der Verrechnung prüfen.');
            $basis=$sources[$bind['revision'].':'.$bind['sha256']]??=$segments->originalState($year,$bind['revision'],$bind['sha256']);$source=end($basis['history']);
            if(!$source||$bind['sha256']!==$source['sha256'])throw new DomainException('Zugehörige Buchungsaufteilung fehlt.');
            if($carry!==null){
                if(!is_array($carry)||array_keys($carry)!==['year','revision','sha256']||$carry['year']!==$year-1||!is_int($carry['revision'])||$carry['revision']<1||$carry['revision']>1000||!is_string($carry['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$carry['sha256']))throw new DomainException('Vorjahresbindung der Verrechnung prüfen.');
                $prior??=$this->history($actor,$year-1,$depth+1);$old=$prior[$carry['revision']-1]??null;
                if(!$old||$old['state']!=='reviewed'||$old['sha256']!==$carry['sha256'])throw new DomainException('Geprüften Verrechnungsstand des Vorjahres nachweisen.');
                $previous=$old['proof'];
            }
            $instructions=$p['proof']['instructions']??null;if(!is_array($instructions))throw new DomainException('Verrechnungsangaben prüfen.');
            $a=$source['allocation'];self::noPermanent($a,$instructions);
            $historic=$basis['journal'];
            if(BalanceInternalClearing::build($actor['club_id'],$historic,$a['units'],$a['allocations'],$instructions,$previous)!==$p['proof'])throw new DomainException('Verrechnung stimmt nicht mit den gespeicherten Originalquellen überein.');
            $last=$history?end($history):null;
            if($p['state']==='reviewed'&&(!$last||$last['state']!=='draft'||$last['proof']!==$p['proof']||$last['segment_source']!==$bind||$last['carry_source']!==$carry))throw new DomainException('Freigabe benötigt einen unveränderten vorbereiteten Nachweis.');
            $chain=$row['sha256'];$history[]=$p+['sha256'=>$chain];
        }
        return $history;
    }
    private function state(array $actor,int $year,int $depth=0,?int $throughRevision=null,?array $segmentBasis=null,bool $originalBasis=false):array
    {
        if($depth>100)throw new DomainException('Zu viele Vorjahresnachweise.');
        $segments=$segmentBasis??(new BalanceSegments($this->db,$this->actorId))->get($year);$history=$this->history($actor,$year,$depth);
        if($throughRevision!==null){if($throughRevision<0||$throughRevision>count($history))throw new DomainException('Ursprünglichen Verrechnungsstand erneut auswählen.',409);$history=array_slice($history,0,$throughRevision);}
        $last=$history?end($history):null;$carry=$last['carry_source']??null;
        $sourceCurrent=$last===null||($last['segment_source']['revision']===$segments['version']&&$last['segment_source']['sha256']===end($segments['history'])['sha256']);
        $prior=$carry!==null&&!$originalBasis?$this->state($actor,$year-1,$depth+1):null;
        $carryCurrent=$carry===null||$originalBasis||($prior['current_approval']&&$prior['version']===$carry['revision']);
        $current=false;
        if(!$originalBasis&&($last['state']??null)==='reviewed')try{$a=Permissions::actor($this->db,$last['actor']);$current=$a['club_id']===$actor['club_id']&&$a['finance_access']==='write'&&BoardAccess::allowed($this->db,$last['actor']);}catch(DomainException){}
        $sourceCurrent=$sourceCurrent&&$segments['source_current'];
        $historicSource=$last?(new BalanceSegments($this->db,$this->actorId))->originalDetails($year,$last['segment_source']['revision'],$last['segment_source']['sha256']):null;
        $statement=$last?BalanceClearingStatement::build($historicSource['journal'],$historicSource['original']['allocation'],$last['proof']):null;
        return ['year'=>$year,'version'=>$last['revision']??0,'state'=>$last['state']??'draft','proof'=>$last['proof']??null,'statement'=>$statement,'history'=>$history,'segments'=>$segments,'source_current'=>$sourceCurrent,'carry_current'=>$carryCurrent,'current_approval'=>$current&&$sourceCurrent&&$carryCurrent&&$segments['carry_current']&&($statement['complete']??false),'tax_export_ready'=>false];
    }
    private function actionState(array $actor,int $year,int $version,int $segmentVersion,?array $first=null):array
    {
        if($version<0||$version>=1000||$segmentVersion<1||$segmentVersion>1000)throw new DomainException('Ursprünglichen Verrechnungsstand erneut auswählen.',409);
        $actual=(int)$this->q('SELECT COALESCE(MAX(revision),0)FROM balance_clearing_reviews WHERE club_id=? AND tax_year=?',[$actor['club_id'],$year])->fetchColumn();
        if($version>$actual)throw new DomainException('Ursprünglichen Verrechnungsstand erneut auswählen.',409);
        if($first===null&&$actual===$version)try{$state=$this->state($actor,$year);if($state['segments']['version']===$segmentVersion)return $state;}catch(DomainException){/* Original form evidence remains separate from a newly blocked current source. */}
        $sha=$this->q('SELECT sha256 FROM balance_segment_reviews WHERE club_id=? AND tax_year=? AND revision=?',[$actor['club_id'],$year,$segmentVersion])->fetchColumn();
        if(!is_string($sha))throw new DomainException('Ursprüngliche Buchungsaufteilung stimmt nicht überein.',409);
        $basis=(new BalanceSegments($this->db,$this->actorId))->originalState($year,$segmentVersion,$sha);
        return $this->state($actor,$year,0,$version,$basis,true);
    }
    public function forAction(int $year,int $version,int $segmentVersion):array
    {
        try{return $this->actionState($this->actor(),$year,$version,$segmentVersion);}catch(\JsonException$e){throw new DomainException('Gespeicherten Verrechnungsnachweis prüfen.',0,$e);}
    }
    public function originalDetails(int $year,int $revision,string $sha256):array
    {
        $actor=$this->actor();if($revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglicher Verrechnungsnachweis nicht gefunden.',404);
        $history=$this->history($actor,$year);$original=$history[$revision-1]??null;
        if(!$original||!hash_equals($original['sha256'],$sha256))throw new DomainException('Ursprünglicher Verrechnungsnachweis nicht gefunden.',404);
        $bind=$original['segment_source'];$segments=(new BalanceSegments($this->db,$this->actorId))->originalState($year,$bind['revision'],$bind['sha256']);
        return ['original'=>$original,'segments'=>$segments,'statement'=>BalanceClearingStatement::build($segments['journal'],$segments['allocation'],$original['proof'])];
    }
    public function original(int $year,int $revision,string $sha256):array
    {
        return $this->originalDetails($year,$revision,$sha256)['original'];
    }
    public function get(int $year):array
    {
        try{return $this->state($this->actor(),$year);}catch(\JsonException $e){throw new DomainException('Gespeicherten Verrechnungsnachweis prüfen.',0,$e);}
    }
    /** Read-only evidence; retains all tax-value and transmission gates. */
    public function incomeReconciliation(int $year,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele Ergebnisnachweise des Vorjahres.');
        $state=$this->get($year);
        if(!$state['current_approval'])throw new DomainException('Zuerst den aktuellen Verrechnungsnachweis vom Vorstand prüfen lassen.');
        $previous=$state['segments']['journal']['previous_sha256']!==null?$this->incomeReconciliation($year-1,$depth+1):null;
        return BalanceClubIncomeReconciliation::fromState($state,$previous);
    }
    private function change(int $year,int $version,int $segmentVersion,callable $work,bool $board=false):array
    {
        if($this->db->inTransaction())throw new DomainException('Verrechnung benötigt einen eigenen Speichervorgang.');
        $initial=$this->actor(true);$this->db->beginTransaction();
        try{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);$actor=$this->actor(true);
            if($actor['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            if($board)BoardAccess::require($this->db,$this->actorId);
            $actual=(int)$this->q('SELECT COALESCE(MAX(revision),0)FROM balance_clearing_reviews WHERE club_id=? AND tax_year=?',[$actor['club_id'],$year])->fetchColumn();
            if($actual!==$version||$version>=1000){
                $row=$version>=0&&$version<1000?$this->q('SELECT payload,sha256 FROM balance_clearing_reviews WHERE club_id=? AND tax_year=? AND revision=?',[$actor['club_id'],$year,$version+1])->fetch():false;
                if($row){$first=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                    if($first['actor']===$actor['id']&&$first['segment_source']['revision']===$segmentVersion){
                        $old=$this->actionState($actor,$year,$version,$segmentVersion,$first);$ref=$first['carry_source'];$previous=null;
                        if($ref!==null){$prior=$this->history($actor,$year-1);$selected=$prior[$ref['revision']-1]??null;if(!$selected||$selected['sha256']!==$ref['sha256']||$selected['state']!=='reviewed')throw new DomainException('Geprüften Verrechnungsstand des Vorjahres nachweisen.');$previous=$selected['proof'];}
                        if($old['version']>0&&$ref!==end($old['history'])['carry_source'])BoardAccess::require($this->db,$this->actorId);
                        $old['_replay_forward']=[$previous,$ref];$same=false;
                        try{[$proof,$status,$note,$carry]=$work($old,$actor,true);$note=self::note($note);$same=$proof===$first['proof']&&$status===$first['state']&&$note===$first['note']&&$carry===$first['carry_source'];}catch(DomainException){}
                        if($same){$result=$this->original($year,$version+1,$row['sha256']);$this->db->commit();return $result;}
                    }
                }
                throw new DomainException('Verrechnung oder Buchungsaufteilung inzwischen geändert. Bitte neu laden.',409);
            }
            $s=$this->state($actor,$year);
            if($s['segments']['version']!==$segmentVersion||$segmentVersion<1||$version<0)throw new DomainException('Verrechnung oder Buchungsaufteilung inzwischen geändert. Bitte neu laden.',409);
            if(!$s['segments']['carry_current'])throw new DomainException('Zuerst die geänderte Vorjahresaufteilung abstimmen.');
            [$proof,$status,$note,$carry]=$work($s,$actor);$note=self::note($note);
            $source=end($s['segments']['history']);
            $p=['schema'=>1,'club_id'=>$actor['club_id'],'year'=>$year,'revision'=>$version+1,'previous_sha256'=>$s['history']?end($s['history'])['sha256']:null,'state'=>$status,'actor'=>$actor['id'],'at'=>gmdate('c'),'note'=>$note,'segment_source'=>['revision'=>$segmentVersion,'sha256'=>$source['sha256']],'carry_source'=>$carry,'proof'=>$proof];
            $json=json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(strlen($json)>8000000)throw new DomainException('Verrechnungsnachweis größer als 8 MB. Buchhaltung hinzuziehen.');
            $hash=BalanceSegmentCalculation::hash($p);
            $this->q('INSERT INTO balance_clearing_reviews(club_id,tax_year,revision,payload,sha256) VALUES(?,?,?,?,?)',[$actor['club_id'],$year,$version+1,$json,$hash]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$actor['club_id'],$actor['id'],'finance.balance.clearings.'.$status,(string)$year,$version+1,$p['at']]);
            $this->db->commit();return $p+['sha256'=>$hash];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function save(int $year,int $version,int $segmentVersion,array $instructions,bool $reviewed,string $note):array
    {
        return $this->change($year,$version,$segmentVersion,function($s,$actor,bool $replay=false)use($year,$instructions,$reviewed,$note){
            if(!$reviewed)throw new DomainException('Vorschüsse, Rückzahlungen und Nachweise ausdrücklich prüfen.');
            $previous=null;$carry=null;
            if($replay){[$previous,$carry]=$s['_replay_forward'];}
            elseif(($s['segments']['journal']['previous_sha256']??null)!==null){
                $prior=$this->state($actor,$year-1);
                if(!$prior['current_approval'])throw new DomainException('Zuerst den Verrechnungsnachweis des Vorjahres durch den Vorstand prüfen lassen, auch wenn er leer ist.');
                $last=end($prior['history']);$previous=$last['proof'];$carry=['year'=>$year-1,'revision'=>$prior['version'],'sha256'=>$last['sha256']];
                if($s['version']>0&&!$s['carry_current'])BoardAccess::require($this->db,$this->actorId);
            }
            $a=$s['segments']['allocation'];self::noPermanent($a,$instructions);
            $proof=BalanceInternalClearing::build($actor['club_id'],$s['segments']['journal'],$a['units'],$a['allocations'],$instructions,$previous);
            return [$proof,'draft',$note,$carry];
        });
    }
    public function confirm(int $year,int $version,int $segmentVersion,bool $reviewed,string $note):array
    {
        return $this->change($year,$version,$segmentVersion,function($s)use($reviewed,$note){
            BoardAccess::require($this->db,$this->actorId);
            if(!$reviewed||$s['version']===0||$s['state']!=='draft'||!$s['source_current']||!$s['carry_current']||!($s['statement']['complete']??false))throw new DomainException('Zuerst Buchungsaufteilung, Vorschüsse und Rückzahlungen vollständig abstimmen und ausdrücklich prüfen.');
            return [$s['proof'],'reviewed',$note,end($s['history'])['carry_source']];
        },true);
    }
    /** Until tax projection is implemented, neither old approvals nor another profile may bypass it. */
    public function assertExportable(int $year):void
    {
        $a=$this->actor();
        if(!$this->q('SELECT 1 FROM balance_clearing_reviews WHERE club_id=? AND tax_year=? LIMIT 1',[$a['club_id'],$year])->fetchColumn())return;
        $s=$this->get($year);
        if($s['version']>0&&(!$s['current_approval']||$s['proof']['cases']))throw new DomainException('Interne Verrechnungen sind noch nicht für den steuerlichen Abschluss abgestimmt. Keine E-Bilanz freigeben.');
    }
    /** Rebuild a historically reviewed income scope from actual stored original S/W rows.
     * This is immutable evidence and grants no current approval or export authority.
     */
    public function originalIncome(int $year,int $revision,string $sha256,int $depth=0):array
    {
        if($depth<0||$depth>100)throw new DomainException('Zu viele Ergebnisnachweise des Vorjahres.');
        $details=$this->originalDetails($year,$revision,$sha256);$original=$details['original'];
        if($original['state']!=='reviewed')throw new DomainException('Geprüften ursprünglichen Verrechnungsnachweis auswählen.');
        $prior=$original['carry_source'];$previous=$prior===null?null:$this->originalIncome($prior['year'],$prior['revision'],$prior['sha256'],$depth+1);
        $segments=$details['segments'];$keys=['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'];
        $source=array_replace(array_fill_keys($keys,null),array_intersect_key($segments['journal'],array_flip($keys)));
        return BalanceClubIncomeReconciliation::rebuild(['schema'=>1,'kind'=>'club_income_reconciliation','club_id'=>$original['club_id'],
            'source'=>$source,'allocation'=>$segments['allocation'],'clearing'=>$original['proof'],
            'review'=>['allocation'=>$original['segment_source'],'clearing'=>['revision'=>$original['revision'],'sha256'=>$original['sha256']]],
            'previous'=>$previous['scope']??null]);
    }
    public function files(int $year):array
    {
        $s=$this->get($year);if(!$s['proof'])throw new DomainException('Zuerst einen Verrechnungsnachweis vorbereiten.');
        $files=BalanceInternalClearingExport::files($s['proof']);
        $files['verrechnungen/bereichsabgleich.json']=json_encode($s['statement'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        $status=$s['current_approval']?'Verrechnungsnachweis vom Vorstand geprüft':'ENTWURF – aktuelle Prüfung fehlt';
        $files['verrechnungen/LESEN.txt']=str_replace('ENTWURF – keine Vorstandsfreigabe und keine Steuererklärung.',$status.'. Keine Freigabe einer Bilanz oder Steuerabgabe.',$files['verrechnungen/LESEN.txt']);
        $files['verrechnungen/pruefverlauf.json']=json_encode(['revision'=>$s['version'],'source_current'=>$s['source_current'],'carry_current'=>$s['carry_current'],'current_approval'=>$s['current_approval'],'tax_export_ready'=>false,'history'=>$s['history']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        if($s['current_approval']){
            $result=$this->incomeReconciliation($year);
            $files['verrechnungen/ergebnisabgleich.json']=json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
            $files['verrechnungen/ergebnisabgleich.txt']=BalanceClubIncomeReconciliation::summary($result);
            $files['verrechnungen/LESEN.txt'].="\nErgebnisabgleich: Gesamtverein und alle steuerpflichtigen Betriebe. Grundlage sind die Originalbuchungen, nicht der Zahlungsweg. Vorschüsse und Rückzahlungen verändern den Gewinn nicht. Steuerliche Wertänderungen und die Steuererklärung sind gesondert zu prüfen.\n";
        }
        return $files;
    }
}
