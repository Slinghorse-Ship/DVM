<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceClearings.php';
require_once __DIR__.'/BalanceClubTaxReconciliation.php';

/** Club-scoped review history. Approval covers the computation, never transmission. */
final class BalanceTaxReconciliations
{
    public function __construct(private PDO $db,private string $actorId){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if(!BalanceLedger::enabled()||!Database::tableExists($this->db,'balance_tax_reconciliation_reviews'))throw new DomainException('Steuerliche Nebenrechnung ist nicht freigeschaltet.');
        if(((new Settings($this->db,$this->actorId))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Der Verein verwendet EÜR. Keine Bilanz-Steuerwerte bearbeiten.');
        return $a;
    }
    private static function note(string $value):string
    {
        if(trim($value)===''||mb_strlen($value)>1000||preg_match('/[\x00-\x1f]/',$value))throw new DomainException('Prüfvermerk oder Änderungsgrund angeben.');return trim($value);
    }
    private function history(array $actor,int $year,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele steuerliche Vorjahresnachweise.');
        $clearings=new BalanceClearings($this->db,$this->actorId);$sources=[];
        $rows=$this->q('SELECT revision,payload,sha256 FROM balance_tax_reconciliation_reviews WHERE club_id=? AND tax_year=? ORDER BY revision LIMIT 1001',[$actor['club_id'],$year])->fetchAll();
        if(count($rows)>1000)throw new DomainException('Zu viele steuerliche Prüfstände.');
        $history=[];$hash=null;$prior=null;
        foreach($rows as $i=>$row){
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','club_id','year','revision','previous_sha256','state','actor','at','note','prior_source','values']
                ||$p['schema']!==1||$p['club_id']!==$actor['club_id']||$p['year']!==$year||$p['revision']!==$i+1||(int)$row['revision']!==$i+1
                ||$p['previous_sha256']!==$hash||!in_array($p['state'],['draft','reviewed'],true)||!is_string($p['actor'])||$p['actor']===''
                ||!is_string($p['at'])||!is_string($p['note'])||!is_array($p['values'])||!hash_equals($row['sha256'],BalanceSegmentCalculation::hash($p)))throw new DomainException('Gespeicherten steuerlichen Prüfverlauf prüfen.');
            self::note($p['note']);BalanceClubTaxReconciliation::verify($p['values']);
            $v=$p['values'];
            if($v['year']!==$year||$v['income']['scope']['club_id']!==$actor['club_id'])throw new DomainException('Steuerwerte gehören zu einem anderen Verein oder Jahr.');
            // A mathematical scope alone cannot replace its actual stored S/W originals.
            $binding=$v['income']['scope']['review']['clearing'];
            $key=$binding['revision'].':'.$binding['sha256'];$originalIncome=$sources[$key]??=$clearings->originalIncome($year,$binding['revision'],$binding['sha256']);
            if($v['income']!==$originalIncome)throw new DomainException('Gespeicherten Ursprung der Steuerwerte prüfen.');
            $b=$p['prior_source'];
            if($b!==null){
                if(!is_array($b)||array_keys($b)!==['year','revision','sha256']||$b['year']!==$year-1||!is_int($b['revision'])||$b['revision']<1||$b['revision']>1000||!is_string($b['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$b['sha256']))throw new DomainException('Steuerlichen Vorjahresbezug prüfen.');
                $prior??=$this->history($actor,$year-1,$depth+1);$old=$prior[$b['revision']-1]??null;
                if(!$old||$old['state']!=='reviewed'||$old['sha256']!==$b['sha256']||$old['values']!==$v['previous'])throw new DomainException('Geprüfte steuerliche Vorjahreswerte fehlen.');
            }elseif($v['previous']!==null)throw new DomainException('Ungebundene steuerliche Vorjahreswerte.');
            $last=$history?end($history):null;
            if($p['state']==='reviewed'&&(!$last||$last['state']!=='draft'||$last['values']!==$v||$last['prior_source']!==$b))throw new DomainException('Nur einen unveränderten Entwurf bestätigen.');
            // Immutable revisions repeat the complete journal, income and valuation.
            // Share only strictly equal, fully verified arrays within this history read.
            // PHP copy-on-write keeps later edits independent; no cached approval survives.
            $p['values']['income']=$originalIncome;
            if($b!==null)$p['values']['previous']=$old['values'];
            if($last!==null){
                foreach($p['values']['valuation'] as $key=>$value)
                    if(is_array($value)&&array_key_exists($key,$last['values']['valuation'])&&$value===$last['values']['valuation'][$key])$p['values']['valuation'][$key]=$last['values']['valuation'][$key];
                foreach($p['values'] as $key=>$value)
                    if(is_array($value)&&array_key_exists($key,$last['values'])&&$value===$last['values'][$key])$p['values'][$key]=$last['values'][$key];
                if($p['values']===$last['values'])$p['values']=$last['values'];
            }
            $hash=$row['sha256'];$history[]=$p+['sha256'=>$hash];
        }
        return $history;
    }
    private function state(array $actor,int $year,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele steuerliche Vorjahresnachweise.');
        $clearings=new BalanceClearings($this->db,$this->actorId);$source=$clearings->get($year);
        $income=$source['current_approval']?$clearings->incomeReconciliation($year):null;
        $history=$this->history($actor,$year,$depth);
        $last=$history?end($history):null;$b=$last['prior_source']??null;$prior=$b===null?null:$this->state($actor,$year-1,$depth+1);
        $sourceCurrent=$income!==null&&($last===null||$last['values']['income']===$income);
        $carryCurrent=$b===null||($prior['current_approval']&&$prior['version']===$b['revision']);
        $approved=false;
        if(($last['state']??null)==='reviewed')try{$a=Permissions::actor($this->db,$last['actor']);$approved=$a['club_id']===$actor['club_id']&&$a['finance_access']==='write'&&BoardAccess::allowed($this->db,$last['actor']);}catch(DomainException){}
        return ['year'=>$year,'version'=>$last['revision']??0,'state'=>$last['state']??'draft','income'=>$income,'values'=>$last['values']??null,
            'history'=>$history,'source_current'=>$sourceCurrent,'carry_current'=>$carryCurrent,'current_approval'=>$approved&&$sourceCurrent&&$carryCurrent,'tax_export_ready'=>false];
    }
    /** Historical evidence only. Current approval remains a separate get() contract. */
    public function original(int $year,int $revision,string $sha256):array
    {
        $actor=$this->actor();if($revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglichen steuerlichen Nachweis vollständig auswählen.',404);
        $history=$this->history($actor,$year);$record=$history[$revision-1]??null;
        if(!$record||!hash_equals($record['sha256'],$sha256))throw new DomainException('Ursprünglicher steuerlicher Nachweis nicht gefunden.',404);
        return $record;
    }
    public function get(int $year):array
    {
        try{return $this->state($this->actor(),$year);}catch(\JsonException $e){throw new DomainException('Gespeicherten steuerlichen Nachweis prüfen.',0,$e);}
    }
    /** Current confirmed prior, including exact opening differences, for the form. */
    public function carry(int $year):array
    {
        $s=$this->get($year);if($s['income']===null)throw new DomainException('Zuerst die Bereichsaufteilung prüfen.');
        if($s['income']['scope']['previous']===null)return ['prior_source'=>null,'previous'=>null,'opening_adjustments'=>[],'asset_tax_plans'=>[]];
        $p=$this->get($year-1);if(!$p['current_approval'])throw new DomainException('Zuerst die steuerliche Nebenrechnung des Vorjahres bestätigen.');
        $last=end($p['history']);$carry=BalanceClubTaxReconciliation::carryForward($p['values'],$s['income']);
        return ['prior_source'=>['year'=>$year-1,'revision'=>$p['version'],'sha256'=>$last['sha256']],'previous'=>$p['values'],
            'opening_adjustments'=>$carry['entry']===null?[]:[$carry['entry']],'asset_tax_plans'=>$carry['asset_tax_plans']??[]];
    }
    public function draft(int $year):array
    {
        $s=$this->get($year);$carry=$this->carry($year);$i=$s['income'];
        $r=$s['values']['review']??['year'=>$year,'journal_sha256'=>$i['scope']['source']['closed_sha256'],'income_sha256'=>$i['sha256'],'complete'=>true,
            'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[]];
        $r['income_sha256']=$i['sha256'];
        $r['journal_sha256']=$i['scope']['source']['closed_sha256'];
        if($carry['previous']!==null){$r['opening_adjustments']=$carry['opening_adjustments'];$r['asset_tax_plans']=$carry['asset_tax_plans']+$r['asset_tax_plans'];}
        return $r;
    }
    /** Original form basis from actual reviewed sources, with no current approval grant. */
    private function actionState(array $actor,int $year,int $version,int $clearingRevision,string $clearingSha,?array $priorSource):array
    {
        if($version<0||$version>=1000)throw new DomainException('Ursprünglichen steuerlichen Prüfstand erneut auswählen.',409);
        $history=$this->history($actor,$year);if($version>count($history))throw new DomainException('Ursprünglichen steuerlichen Prüfstand erneut auswählen.',409);
        $income=(new BalanceClearings($this->db,$this->actorId))->originalIncome($year,$clearingRevision,$clearingSha);
        $previous=null;
        if($income['scope']['previous']===null){if($priorSource!==null)throw new DomainException('Ursprünglichen steuerlichen Vorjahresbezug prüfen.',409);}
        else{
            if(!is_array($priorSource)||array_keys($priorSource)!==['year','revision','sha256']||$priorSource['year']!==$year-1||!is_int($priorSource['revision'])||$priorSource['revision']<1||$priorSource['revision']>1000||!is_string($priorSource['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$priorSource['sha256']))throw new DomainException('Ursprünglichen steuerlichen Vorjahresbezug prüfen.',409);
            $record=$this->original($year-1,$priorSource['revision'],$priorSource['sha256']);if($record['state']!=='reviewed')throw new DomainException('Geprüfte steuerliche Vorjahreswerte fehlen.');$previous=$record['values'];
        }
        $carry=$previous===null?['entry'=>null,'asset_tax_plans'=>[]]:BalanceClubTaxReconciliation::carryForward($previous,$income);
        $carry=['prior_source'=>$priorSource,'previous'=>$previous,'opening_adjustments'=>$carry['entry']===null?[]:[$carry['entry']],'asset_tax_plans'=>$carry['asset_tax_plans']??[]];
        $history=array_slice($history,0,$version);$last=$history?end($history):null;
        $review=$last['values']['review']??['year'=>$year,'journal_sha256'=>$income['scope']['source']['closed_sha256'],'income_sha256'=>$income['sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[]];
        $review['income_sha256']=$income['sha256'];$review['journal_sha256']=$income['scope']['source']['closed_sha256'];
        if($previous!==null){$review['opening_adjustments']=$carry['opening_adjustments'];$review['asset_tax_plans']=$carry['asset_tax_plans']+$review['asset_tax_plans'];}
        $state=['year'=>$year,'version'=>$version,'state'=>$last['state']??'draft','income'=>$income,'values'=>$last['values']??null,'history'=>$history,
            'source_current'=>$last===null||$last['values']['income']===$income,'carry_current'=>$last===null||$last['prior_source']===$priorSource,'current_approval'=>false,'tax_export_ready'=>false];
        return ['state'=>$state,'draft'=>$review,'carry'=>$carry,'clearing_source'=>['revision'=>$clearingRevision,'sha256'=>$clearingSha]];
    }
    public function forAction(int $year,int $version,int $clearingRevision,string $clearingSha,?array $priorSource):array
    {
        try{return $this->actionState($this->actor(),$year,$version,$clearingRevision,$clearingSha,$priorSource);}catch(\JsonException$e){throw new DomainException('Gespeicherten steuerlichen Nachweis prüfen.',0,$e);}
    }
    private static function expectSource(array $expect,array $income,?array $prior):void
    {
        if(array_diff(array_keys($expect),['clearing_source','prior_source']))throw new DomainException('Ursprünglichen steuerlichen Quellenbezug prüfen.',409);
        if(array_key_exists('clearing_source',$expect)&&$expect['clearing_source']!==$income['scope']['review']['clearing'])throw new DomainException('Ergebnisquelle inzwischen geändert. Ursprünglichen Prüfstand erneut auswählen.',409);
        if(array_key_exists('prior_source',$expect)&&$expect['prior_source']!==$prior)throw new DomainException('Steuerliche Vorjahresquelle inzwischen geändert. Ursprünglichen Prüfstand erneut auswählen.',409);
    }
    private function change(int $year,int $version,string $incomeHash,string $note,callable $work,bool $board=false,array $expect=[]):array
    {
        if($this->db->inTransaction())throw new DomainException('Steuerprüfung benötigt einen eigenen Speichervorgang.');
        $initial=$this->actor(true);$note=self::note($note);$this->db->beginTransaction();
        try{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);$a=$this->actor(true);
            if($a['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.');
            if($board)BoardAccess::require($this->db,$this->actorId);
            $actual=(int)$this->q('SELECT COALESCE(MAX(revision),0) FROM balance_tax_reconciliation_reviews WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetchColumn();
            if($version!==$actual||$version>=1000){
                $row=$version>=0&&$version<1000?$this->q('SELECT payload,sha256 FROM balance_tax_reconciliation_reviews WHERE club_id=? AND tax_year=? AND revision=?',[$a['club_id'],$year,$version+1])->fetch():false;
                if($row){$first=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                    if($first['actor']===$a['id']&&$first['values']['income']['sha256']===$incomeHash){
                        $source=$first['values']['income']['scope']['review']['clearing'];
                        $context=$this->actionState($a,$year,$version,$source['revision'],$source['sha256'],$first['prior_source']);$old=$context['state'];
                        self::expectSource($expect,$old['income'],$first['prior_source']);
                        if($version>0&&$first['prior_source']!==end($old['history'])['prior_source'])BoardAccess::require($this->db,$this->actorId);
                        $old['_replay_forward']=$context['carry'];$same=false;
                        try{[$values,$status,$prior]=$work($old,true);$same=$values===$first['values']&&$status===$first['state']&&$prior===$first['prior_source']&&$note===$first['note'];}catch(DomainException){}
                        if($same){$result=$this->original($year,$version+1,$row['sha256']);$this->db->commit();return $result;}
                    }
                }
                throw new DomainException('Steuerwerte oder Bereichsaufteilung geändert. Bitte neu laden.',409);
            }
            $s=$this->state($a,$year);
            if($version<0||$s['income']===null||$incomeHash!==$s['income']['sha256'])throw new DomainException('Steuerwerte oder Bereichsaufteilung geändert. Bitte neu laden.',409);
            [$values,$status,$prior]=$work($s,false);self::expectSource($expect,$s['income'],$prior);
            $p=['schema'=>1,'club_id'=>$a['club_id'],'year'=>$year,'revision'=>$version+1,'previous_sha256'=>$s['history']?end($s['history'])['sha256']:null,
                'state'=>$status,'actor'=>$a['id'],'at'=>gmdate('c'),'note'=>$note,'prior_source'=>$prior,'values'=>$values];
            $json=json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if(strlen($json)>8000000)throw new DomainException('Steuernachweis größer als 8 MB. Buchhaltung hinzuziehen.');
            $hash=BalanceSegmentCalculation::hash($p);
            $this->q('INSERT INTO balance_tax_reconciliation_reviews(club_id,tax_year,revision,payload,sha256) VALUES(?,?,?,?,?)',[$a['club_id'],$year,$version+1,$json,$hash]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.balance.tax_reconciliation.'.$status,(string)$year,$version+1,$p['at']]);
            $this->db->commit();return $p+['sha256'=>$hash];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function save(int $year,int $version,array $review,string $note,array $expect=[]):array
    {
        return $this->change($year,$version,(string)($review['income_sha256']??''),$note,function($s,bool $replay)use($year,$review){
            if(!$s['carry_current'])BoardAccess::require($this->db,$this->actorId);
            $carry=$replay?$s['_replay_forward']:$this->carry($year);
            return [BalanceClubTaxReconciliation::build($s['income'],$review,$carry['previous']),'draft',$carry['prior_source']];
        },false,$expect);
    }
    public function confirm(int $year,int $version,string $incomeHash,bool $reviewed,string $note,array $expect=[]):array
    {
        return $this->change($year,$version,$incomeHash,$note,function($s)use($reviewed){
            if(!$reviewed||$s['version']===0||$s['state']!=='draft'||!$s['source_current']||!$s['carry_current'])throw new DomainException('Aktuelle steuerliche Nebenrechnung zuerst vorbereiten und ausdrücklich prüfen.');
            return [$s['values'],'reviewed',end($s['history'])['prior_source']];
        },true,$expect);
    }
    public function files(int $year):array
    {
        $s=$this->get($year);if(!$s['values'])return [];
        $j=static fn($v)=>json_encode($v,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        $status=$s['current_approval']?'Steuerliche Nebenrechnung vom Vorstand geprüft':'ENTWURF – aktuelle Prüfung fehlt';
        return ['steuerliche-nebenrechnung/nachweis.json'=>$j($s['values']),
            'steuerliche-nebenrechnung/uebersicht.txt'=>$status."\n\n".BalanceClubTaxReconciliation::summary($s['values']),
            'steuerliche-nebenrechnung/pruefverlauf.json'=>$j(array_intersect_key($s,array_flip(['version','state','history','source_current','carry_current','current_approval','tax_export_ready'])))];
    }
}
