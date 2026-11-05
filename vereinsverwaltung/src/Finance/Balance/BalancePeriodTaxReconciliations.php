<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodAreas.php';
require_once __DIR__.'/BalanceTaxReconciliations.php';

/** Immutable, tenant-bound tax workpapers keyed by BOTH fiscal dates. No transmission gate. */
final class BalancePeriodTaxReconciliations
{
    public function __construct(private PDO $db,private string $actor){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function access(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if(!BalancePeriodLedger::enabled()||!Database::tableExists($this->db,'balance_period_tax_reviews'))throw new DomainException('Steuerprüfung für Abschlusszeiträume ist nicht freigeschaltet.');
        if(((new Settings($this->db,$this->actor))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Der Verein verwendet EÜR. Keine Bilanz-Steuerwerte bearbeiten.');
        return $a;
    }
    private static function note(string $text):string
    {if(trim($text)===''||mb_strlen($text)>1000||preg_match('/[\x00-\x1f]/',$text))throw new DomainException('Prüfvermerk oder Änderungsgrund angeben.');return trim($text);}
    private function prior(array $reference,int $depth,array &$states):array
    {
        if(array_keys($reference)!==['kind','id','revision','sha256']||!in_array($reference['kind'],['period','calendar'],true)||!is_string($reference['id'])||!is_int($reference['revision'])||$reference['revision']<1
            ||!is_string($reference['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$reference['sha256']))throw new DomainException('Steuerlichen Vorzeitraum nachweisen.');
        $key=$reference['kind'].':'.$reference['id'];
        if($reference['kind']==='period')$s=$states[$key]??= $this->get($reference['id'],$depth+1);
        elseif($reference['kind']==='calendar'&&preg_match('/^[0-9]{4}$/D',$reference['id']))$s=$states[$key]??= (new BalanceTaxReconciliations($this->db,$this->actor))->get((int)$reference['id']);
        else throw new DomainException('Steuerlichen Vorzeitraum prüfen.');
        $row=$s['history'][$reference['revision']-1]??null;
        if(!$row||$row['state']!=='reviewed'||$row['sha256']!==$reference['sha256'])throw new DomainException('Ursprüngliche Steuerfreigabe des Vorzeitraums fehlt.');
        return ['state'=>$s,'row'=>$row];
    }
    /** Fully verified actual tax originals, with no current approval grant. */
    private function history(array $a,string $id,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele ursprüngliche steuerliche Vorzeiträume.');
        $rows=$this->q('SELECT revision,payload,sha256 FROM balance_period_tax_reviews WHERE club_id=? AND period_id=? ORDER BY revision LIMIT 1001',[$a['club_id'],$id])->fetchAll();
        if(count($rows)>1000)throw new DomainException('Zu viele steuerliche Prüfstände.');$history=[];$hash=null;$prior=null;$sources=[];$priorOriginals=[];
        foreach($rows as $i=>$row){
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','club_id','period_id','revision','previous_sha256','state','actor','at','note','prior_source','values']
                ||$p['schema']!==1||$p['club_id']!==$a['club_id']||$p['period_id']!==$id||$p['revision']!==$i+1||(int)$row['revision']!==$i+1
                ||$p['previous_sha256']!==$hash||!in_array($p['state'],['draft','reviewed'],true)||!is_string($p['actor'])||$p['actor']===''
                ||!is_string($p['at'])||!hash_equals($row['sha256'],BalanceSegmentCalculation::hash($p)))throw new DomainException('Gespeicherten Steuer-Prüfverlauf prüfen.');
            self::note($p['note']);$v=$p['values'];BalanceClubTaxReconciliation::verify($v);
            if(BalancePeriod::key($v['period'])!==$id||$v['income']['scope']['club_id']!==$a['club_id'])throw new DomainException('Steuerwerte gehören zu einem anderen Verein oder Zeitraum.');
            $scope=$v['income']['scope'];$binding=$scope['review']['allocation'];
            if($binding!==$scope['review']['clearing'])throw new DomainException('Steuerwerte und ursprüngliche Bereichsfreigabe stimmen nicht überein.');
            $key=$binding['revision'].':'.$binding['sha256'];$originalIncome=$sources[$key]??=(new BalancePeriodAreas($this->db,$this->actor))->originalIncome($id,$binding['revision'],$binding['sha256'],$depth);
            if($v['income']!==$originalIncome)throw new DomainException('Steuerwerte stammen nicht aus diesem festgeschriebenen Original.');
            $b=$p['prior_source'];$prior=null;
            if($b!==null){$priorKey=json_encode($b,JSON_THROW_ON_ERROR);$prior=$priorOriginals[$priorKey]??=$this->originalPrior($b,$depth);if($prior['values']!==$v['previous'])throw new DomainException('Unveränderte steuerliche Vorwerte fehlen.');}
            elseif($v['previous']!==null)throw new DomainException('Ungebundene steuerliche Vorwerte.');
            $last=$history?end($history):null;
            if($p['state']==='reviewed'&&(!$last||$last['state']!=='draft'||$last['values']!==$v||$last['prior_source']!==$b))throw new DomainException('Nur unveränderte vorbereitete Steuerwerte bestätigen.');
            $p['values']['income']=$originalIncome;if($prior!==null)$p['values']['previous']=$prior['values'];
            if($last!==null)$p['values']=self::shareEqual($p['values'],$last['values']);
            $hash=$row['sha256'];$history[]=$p+['sha256'=>$hash];
        }
        return $history;
    }
    private static function shareEqual(array $value,array $previous,int $depth=0):array
    {
        if($value===$previous)return $previous;
        if($depth<4)foreach($value as$key=>$part)if(is_array($part)&&isset($previous[$key])&&is_array($previous[$key]))$value[$key]=self::shareEqual($part,$previous[$key],$depth+1);
        return $value;
    }
    private function originalPrior(array $reference,int $depth):array
    {
        if($depth>100||array_keys($reference)!==['kind','id','revision','sha256']||!in_array($reference['kind'],['period','calendar'],true)||!is_string($reference['id'])||!is_int($reference['revision'])||$reference['revision']<1||$reference['revision']>1000||!is_string($reference['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$reference['sha256']))throw new DomainException('Ursprünglichen steuerlichen Vorzeitraum vollständig auswählen.');
        if($reference['kind']==='period'&&preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$reference['id']))$row=$this->original($reference['id'],$reference['revision'],$reference['sha256'],$depth+1);
        elseif($reference['kind']==='calendar'&&preg_match('/\A[0-9]{4}\z/D',$reference['id']))$row=(new BalanceTaxReconciliations($this->db,$this->actor))->original((int)$reference['id'],$reference['revision'],$reference['sha256']);
        else throw new DomainException('Ursprünglichen steuerlichen Vorzeitraum vollständig auswählen.');
        if($row['state']!=='reviewed')throw new DomainException('Ursprüngliche Steuerfreigabe des Vorzeitraums fehlt.');return $row;
    }
    public function original(string $id,int $revision,string $sha256,int $depth=0):array
    {
        if($depth>100||!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$id)||$revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprüngliche datierte Steuerwerte vollständig auswählen.',404);
        return BalanceReadSnapshot::run($this->db,function()use($id,$revision,$sha256,$depth){$history=$this->history($this->access(),$id,$depth);$row=$history[$revision-1]??null;if(!$row||!hash_equals($row['sha256'],$sha256))throw new DomainException('Ursprüngliche datierte Steuerwerte nicht gefunden.',404);return $row;});
    }
    public function get(string $id,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Zu viele steuerliche Vorzeiträume.');
        $kind=__METHOD__;
        return BalanceReadSnapshot::run($this->db,fn()=>BalanceReadSnapshot::remember($this->db,$kind,[$this->actor,$id],fn()=>$this->state($id,$depth)));
    }
    /** The page needs both the reviewed state and any missing carry prerequisite. */
    public function overview(string $id):array
    {
        return BalanceReadSnapshot::run($this->db,function()use($id){
            $s=$this->get($id);$issue='';
            if($s['income']!==null)try{$this->carry($id);}catch(DomainException $ex){$issue=$ex->getMessage();}
            return ['state'=>$s,'carry_issue'=>$issue];
        });
    }
    private function state(string $id,int $depth):array
    {
        if($depth>100)throw new DomainException('Zu viele steuerliche Vorzeiträume.');$a=$this->access();
        $loaded=(new BalancePeriodAreas($this->db,$this->actor))->reviewWithIncome($id);$source=$loaded['state'];$income=$loaded['income'];
        $history=$this->history($a,$id,$depth);
        $last=$history?end($history):null;$b=$last['prior_source']??null;$authority=false;
        $sourceCurrent=$income!==null&&($last===null||$last['values']['income']===$income);
        $priorStates=[];$prior=$b===null?null:$this->prior($b,$depth,$priorStates);
        $carryCurrent=$b===null||($prior['state']['current_approval']&&$prior['state']['version']===$b['revision']);
        if(($last['state']??null)==='reviewed')try{$reviewer=Permissions::actor($this->db,$last['actor']);$authority=$reviewer['club_id']===$a['club_id']&&$reviewer['finance_access']==='write'&&BoardAccess::allowed($this->db,$last['actor']);}catch(DomainException){}
        return ['period_id'=>$id,'period'=>$source['source']['period'],'version'=>$last['revision']??0,'state'=>$last['state']??'draft','income'=>$income,'values'=>$last['values']??null,
            'history'=>$history,'source_current'=>$sourceCurrent,'carry_current'=>$carryCurrent,'current_approval'=>$authority&&$sourceCurrent&&$carryCurrent,'tax_export_ready'=>false];
    }
    public function carry(string $id):array
    {
        $s=$this->get($id);$income=$s['income'];if($income===null)throw new DomainException('Zuerst die aktuelle Bereichsaufteilung bestätigen.');
        $old=$income['scope']['previous'];
        if($old===null)return ['prior_source'=>null,'previous'=>null,'opening_adjustments'=>[],'asset_tax_plans'=>[]];
        $dated=isset($old['source']['period']);$key=$dated?BalancePeriod::key($old['source']['period']):(string)$old['source']['year'];
        $p=$dated?$this->get($key):(new BalanceTaxReconciliations($this->db,$this->actor))->get((int)$key);
        if(!$p['current_approval'])throw new DomainException('Zuerst die Steuerwerte des Vorzeitraums bestätigen.');
        $last=end($p['history']);$carry=BalanceClubTaxReconciliation::carryForward($p['values'],$income);
        return ['prior_source'=>['kind'=>$dated?'period':'calendar','id'=>$key,'revision'=>$p['version'],'sha256'=>$last['sha256']],'previous'=>$p['values'],
            'opening_adjustments'=>$carry['entry']===null?[]:[$carry['entry']],'asset_tax_plans'=>$carry['asset_tax_plans']??[]];
    }
    public function draft(string $id):array
    {
        return BalanceReadSnapshot::run($this->db,fn()=>$this->draftUncached($id));
    }
    private function draftUncached(string $id):array
    {
        $s=$this->get($id);$carry=$this->carry($id);$i=$s['income'];
        $r=$s['values']['review']??['year'=>$i['year'],'journal_sha256'=>$i['scope']['source']['closed_sha256'],'income_sha256'=>$i['sha256'],'complete'=>true,
            'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[],'period'=>$i['period']];
        $r['income_sha256']=$i['sha256'];$r['journal_sha256']=$i['scope']['source']['closed_sha256'];
        if($carry['previous']!==null){$r['opening_adjustments']=$carry['opening_adjustments'];$r['asset_tax_plans']=$carry['asset_tax_plans']+$r['asset_tax_plans'];}return $r;
    }
    /** Reconstruct the exact submitted form basis from actual original area/prior rows. */
    private function actionState(array $actor,string $id,int $version,int $areaRevision,string $areaSha,?array $priorSource):array
    {
        if($version<0||$version>=1000)throw new DomainException('Ursprünglichen datierten Steuerprüfstand vollständig auswählen.',409);
        $history=$this->history($actor,$id);if($version>count($history))throw new DomainException('Ursprünglichen datierten Steuerprüfstand erneut auswählen.',409);
        $income=(new BalancePeriodAreas($this->db,$this->actor))->originalIncome($id,$areaRevision,$areaSha);$previous=null;$old=$income['scope']['previous'];
        if($old===null){if($priorSource!==null)throw new DomainException('Ursprünglichen steuerlichen Vorzeitraum prüfen.',409);}
        else{
            if(!is_array($priorSource))throw new DomainException('Ursprünglichen steuerlichen Vorzeitraum vollständig auswählen.',409);
            $dated=isset($old['source']['period']);$expected=$dated?BalancePeriod::key($old['source']['period']):(string)$old['source']['year'];
            if(($priorSource['kind']??null)!==($dated?'period':'calendar')||($priorSource['id']??null)!==$expected)throw new DomainException('Ursprünglicher steuerlicher Bezug gehört nicht zum tatsächlichen Vorzeitraum.',409);
            $previous=$this->originalPrior($priorSource,0)['values'];
        }
        $forward=$previous===null?['entry'=>null,'asset_tax_plans'=>[]]:BalanceClubTaxReconciliation::carryForward($previous,$income);
        $carry=['prior_source'=>$priorSource,'previous'=>$previous,'opening_adjustments'=>$forward['entry']===null?[]:[$forward['entry']],'asset_tax_plans'=>$forward['asset_tax_plans']??[]];
        $history=array_slice($history,0,$version);$last=$history?end($history):null;
        $review=$last['values']['review']??['year'=>$income['year'],'journal_sha256'=>$income['scope']['source']['closed_sha256'],'income_sha256'=>$income['sha256'],'complete'=>true,'opening_adjustments'=>[],'current_adjustments'=>[],'asset_tax_plans'=>[],'off_balance_adjustments'=>[],'period'=>$income['period']];
        $review['income_sha256']=$income['sha256'];$review['journal_sha256']=$income['scope']['source']['closed_sha256'];
        if($previous!==null){$review['opening_adjustments']=$carry['opening_adjustments'];$review['asset_tax_plans']=$carry['asset_tax_plans']+$review['asset_tax_plans'];}
        $state=['period_id'=>$id,'period'=>$income['period'],'version'=>$version,'state'=>$last['state']??'draft','income'=>$income,'values'=>$last['values']??null,'history'=>$history,'source_current'=>$last===null||$last['values']['income']===$income,'carry_current'=>$last===null||$last['prior_source']===$priorSource,'current_approval'=>false,'tax_export_ready'=>false];
        return ['state'=>$state,'draft'=>$review,'carry'=>$carry,'area_source'=>['revision'=>$areaRevision,'sha256'=>$areaSha]];
    }
    public function forAction(string $id,int $version,int $areaRevision,string $areaSha,?array $priorSource):array
    {
        return BalanceReadSnapshot::run($this->db,fn()=>$this->actionState($this->access(),$id,$version,$areaRevision,$areaSha,$priorSource));
    }
    private static function expectSources(array $expect,array $income,?array $prior):void
    {
        if(array_diff(array_keys($expect),['area_source','prior_source']))throw new DomainException('Ursprüngliche datierte Steuerquellen prüfen.',409);
        if(array_key_exists('area_source',$expect)&&$expect['area_source']!==$income['scope']['review']['allocation'])throw new DomainException('Ursprüngliche Bereichsquelle der Steuerwerte stimmt nicht überein.',409);
        if(array_key_exists('prior_source',$expect)&&$expect['prior_source']!==$prior)throw new DomainException('Ursprüngliche steuerliche Vorwerte stimmen nicht überein.',409);
    }
    private function change(string $id,int $version,string $incomeHash,string $note,callable $fn,bool $board=false,array $expect=[]):array
    {
        if($this->db->inTransaction())throw new DomainException('Steuerprüfung benötigt einen eigenen Speichervorgang.');$initial=$this->access(true);$note=self::note($note);$this->db->beginTransaction();
        try{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);$a=$this->access(true);
            if($a['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert.');if($board)BoardAccess::require($this->db,$this->actor);
            $actual=(int)$this->q('SELECT COALESCE(MAX(revision),0)FROM balance_period_tax_reviews WHERE club_id=? AND period_id=?',[$a['club_id'],$id])->fetchColumn();
            if($version!==$actual||$version>=1000){
                $row=$version>=0&&$version<1000?$this->q('SELECT payload,sha256 FROM balance_period_tax_reviews WHERE club_id=? AND period_id=? AND revision=?',[$a['club_id'],$id,$version+1])->fetch():false;
                if($row){$first=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                    if($first['actor']===$a['id']&&$first['values']['income']['sha256']===$incomeHash){
                        $binding=$first['values']['income']['scope']['review']['allocation'];
                        $context=BalanceReadSnapshot::run($this->db,fn()=>$this->actionState($a,$id,$version,$binding['revision'],$binding['sha256'],$first['prior_source']));$old=$context['state'];
                        self::expectSources($expect,$old['income'],$first['prior_source']);
                        if($version>0&&$first['prior_source']!==end($old['history'])['prior_source'])BoardAccess::require($this->db,$this->actor);
                        $old['_replay_forward']=$context['carry'];$same=false;
                        try{[$values,$state,$prior]=$fn($old,true);$same=$values===$first['values']&&$state===$first['state']&&$prior===$first['prior_source']&&$note===$first['note'];}catch(DomainException){}
                        if($same){$result=$this->original($id,$version+1,$row['sha256']);$this->db->commit();return $result;}
                    }
                }
                throw new DomainException('Steuerwerte oder Bereichsaufteilung geändert. Bitte neu laden.',409);
            }
            // Discard this read-only memo before either insert; never cache a mutation.
            [$s,$values,$state,$prior]=BalanceReadSnapshot::run($this->db,function()use($id,$version,$incomeHash,$fn,$expect){
                $s=$this->get($id);
                if($version<0||$version!==$s['version']||$s['income']===null||$incomeHash!==$s['income']['sha256'])throw new DomainException('Steuerwerte oder Bereichsaufteilung geändert. Bitte neu laden.',409);
                [$values,$state,$prior]=$fn($s,false);self::expectSources($expect,$s['income'],$prior);return [$s,$values,$state,$prior];
            });
            $p=['schema'=>1,'club_id'=>$a['club_id'],'period_id'=>$id,'revision'=>$version+1,'previous_sha256'=>$s['history']?end($s['history'])['sha256']:null,'state'=>$state,'actor'=>$a['id'],'at'=>gmdate('c'),'note'=>$note,'prior_source'=>$prior,'values'=>$values];
            $json=BalancePeriodLedger::json($p);if(strlen($json)>8000000)throw new DomainException('Steuernachweis größer als 8 MB. Buchhaltung hinzuziehen.');$hash=BalanceSegmentCalculation::hash($p);
            $this->q('INSERT INTO balance_period_tax_reviews(club_id,period_id,revision,payload,sha256) VALUES(?,?,?,?,?)',[$a['club_id'],$id,$version+1,$json,$hash]);
            $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.balance.period_tax.'.$state,$id,$version+1,$p['at']]);$this->db->commit();return $p+['sha256'=>$hash];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function save(string $id,int $version,array $review,string $note,array $expect=[]):array
    {
        return $this->change($id,$version,(string)($review['income_sha256']??''),$note,function($s,bool $replay)use($id,$review){
            if(!$s['carry_current'])BoardAccess::require($this->db,$this->actor);$carry=$replay?$s['_replay_forward']:$this->carry($id);
            return [BalanceClubTaxReconciliation::build($s['income'],$review,$carry['previous']),'draft',$carry['prior_source']];
        },false,$expect);
    }
    public function confirm(string $id,int $version,string $hash,bool $reviewed,string $note,array $expect=[]):array
    {
        return $this->change($id,$version,$hash,$note,function($s)use($reviewed){
            if(!$reviewed||!$s['version']||$s['state']!=='draft'||!$s['source_current']||!$s['carry_current'])throw new DomainException('Aktuelle Steuerwerte zuerst vorbereiten und ausdrücklich prüfen.');
            return [$s['values'],'reviewed',end($s['history'])['prior_source']];
        },true,$expect);
    }
    public function files(string $id):array
    {
        $s=$this->get($id);if(!$s['values'])return [];
        if(!$s['source_current']||!$s['carry_current'])throw new DomainException('Steuerwerte zuerst mit dem aktuellen Abschluss und Vorzeitraum abstimmen.');
        return ['steuerliche-nebenrechnung/nachweis.json'=>BalancePeriodLedger::json($s['values']),
            'steuerliche-nebenrechnung/uebersicht.txt'=>($s['current_approval']?'Steuerwerte vom Vorstand geprüft':'ENTWURF – aktuelle Prüfung fehlt')."\n\n".BalanceClubTaxReconciliation::summary($s['values']),
            'steuerliche-nebenrechnung/pruefverlauf.json'=>BalancePeriodLedger::json(array_intersect_key($s,array_flip(['period','period_id','version','state','history','source_current','carry_current','current_approval','tax_export_ready'])))];
    }
}
