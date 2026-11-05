<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodLedger.php';
require_once __DIR__.'/BalanceSegments.php';
require_once __DIR__.'/BalanceClearingStatement.php';
require_once __DIR__.'/BalanceClubIncomeReconciliation.php';
require_once __DIR__.'/BalanceCalendarAreaSource.php';
require_once __DIR__.'/BalancePeriodAssetAllocation.php';
require_once __DIR__.'/BalanceReadSnapshot.php';

/** One source-bound review for allocations and advances, identified by both dates. */
final class BalancePeriodAreas
{
    public function __construct(private PDO $db,private string $actor){}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function access(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actor);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($write&&(!BalancePeriodLedger::enabled()||((new Settings($this->db,$this->actor))->section('tax')['data']['accounting_basis']??'euer')!=='balance'))throw new DomainException('Für diesen Verein ist keine Bereichsaufteilung der Bilanz freigeschaltet.');
        return $a;
    }
    private static function note(string $value):string
    {
        $value=trim($value);if($value===''||mb_strlen($value)>500||preg_match('/[\x00-\x1f]/',$value))throw new DomainException('Nachvollziehbaren Prüfvermerk angeben.');return $value;
    }
    private static function source(array $r):array
    {
        if($r['state']!=='closed'||$r['issues']||$r['calculation']['issues'])throw new DomainException('Zuerst den Buchungsstand des Zeitraums vollständig prüfen und bestätigen.');
        $s=array_intersect_key($r,array_flip(['year','period','state','closed_sha256','result_account','accounts','opening','calculation','asset_schedule']));
        $s['previous_sha256']=$r['previous']['sha256']??null;$s['issues']=[];$s['entries']=array_values($r['entries']);
        BalanceSegmentCalculation::sources($s);return $s;
    }
    private static function calculate(string $club,array $source,array $input,?array $previous):array
    {
        $assetReview=array_key_exists('asset_shares',$input);
        if(array_keys($input)!==($assetReview?['units','allocations','capital_carry','transfers','instructions','asset_shares']:['units','allocations','capital_carry','transfers','instructions']))throw new DomainException('Bereichsaufteilung vollständig angeben.');
        $allocation=BalanceSegmentCalculation::build($source,$input['units'],$input['allocations'],$input['capital_carry'],$input['transfers']);
        // Missing current allocations are an editable draft, not an invented zero balance.
        $clearing=null;$statement=null;$issues=$allocation['issues'];
        if(!$allocation['missing']){
            try{
                $clearing=BalanceInternalClearing::build($club,$source,$allocation['units'],$allocation['allocations'],$input['instructions'],$previous['clearing']??null);
                $statement=BalancePeriodAssetAllocation::statement($source,$allocation,$clearing,$input['asset_shares']??[],$previous['assets']??null);$issues=$statement['issues'];
            }catch(DomainException $ex){$issues[]=$ex->getMessage();}
        }
        $assets=null;
        if($assetReview){$assets=BalancePeriodAssetAllocation::build($source,$allocation['units'],$statement['units']??[],$input['asset_shares'],$previous['assets']??null);$issues=array_merge($issues,$assets['issues']);}
        elseif(!empty($source['asset_schedule']['items']))$issues[]='Anlagennutzung je Bereich vor der abschließenden Freigabe abstimmen.';
        $result=['allocation'=>$allocation,'clearing'=>$clearing,'statement'=>$statement,'issues'=>array_values(array_unique($issues)),'complete'=>!$issues,'tax_export_ready'=>false];
        if($assetReview)$result['assets']=$assets;return $result;
    }
    /** Equal verified evidence shares storage only inside the current read. */
    private static function shareEqual(array $value,array $previous,int $depth=0):array
    {
        if($value===$previous)return $previous;
        if($depth<4)foreach($value as$key=>$part)if(is_array($part)&&isset($previous[$key])&&is_array($previous[$key]))$value[$key]=self::shareEqual($part,$previous[$key],$depth+1);
        return $value;
    }
    private function history(string $club,string $id,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Verkettung der ursprünglichen Bereichsaufteilung prüfen.');
        return BalanceReadSnapshot::run($this->db,fn()=>BalanceReadSnapshot::remember($this->db,__METHOD__,[$this->actor,$club,$id],fn()=>$this->historyUncached($club,$id,$depth)));
    }
    private function historyUncached(string $club,string $id,int $depth):array
    {
        if($depth>100)throw new DomainException('Verkettung der ursprünglichen Bereichsaufteilung prüfen.');
        $journals=[];$sources=[];$priors=[];
        $rows=$this->q('SELECT revision,payload,sha256 FROM balance_period_area_reviews WHERE club_id=? AND period_id=? ORDER BY revision LIMIT 1001',[$club,$id]);
        $out=[];$previousHash=null;
        while($row=$rows->fetch()){
            if(count($out)>=1000)throw new DomainException('Zu viele Aufteilungsstände.');
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','club_id','period_id','revision','previous_sha256','state','actor','at','note','source','input','carry','proof']
                ||!is_string($p['actor'])||$p['actor']===''||!is_string($p['at'])||!is_string($p['note'])||!is_array($p['source'])||!is_array($p['input'])||!is_array($p['proof'])
                ||$p['schema']!==1||$p['club_id']!==$club||$p['period_id']!==$id||$p['revision']!==count($out)+1||(int)$row['revision']!==$p['revision']
                ||$p['previous_sha256']!==$previousHash||!in_array($p['state'],['draft','reviewed'],true)||BalancePeriod::key($p['source']['period'])!==$id
                ||!hash_equals($row['sha256'],BalanceSegmentCalculation::hash($p)))throw new DomainException('Prüfverlauf der Bereichsaufteilung ist beschädigt.');
            self::note($p['note']);
            $sourceHash=$p['source']['closed_sha256'];
            if(!is_string($sourceHash)||!preg_match('/\A[a-f0-9]{64}\z/D',$sourceHash))throw new DomainException('Ursprünglichen Buchungsstand der Bereichsaufteilung prüfen.');
            $journal=$journals[$sourceHash]??=(new BalancePeriodLedger($this->db,$this->actor))->originalSource($id,$sourceHash,$depth);
            $actualSource=$sources[$sourceHash]??=self::source($journal);
            if($p['source']!==$actualSource)throw new DomainException('Gespeicherte Bereichsquelle entspricht nicht ihrem tatsächlichen Original.');
            $p['source']=$actualSource;
            // JSON repeats the complete original/carry in every immutable revision. Share
            // byte-equivalent arrays in THIS read only, without dropping any history or check.
            $last=$out?end($out):null;
            if($last!==null)foreach(['source','carry','input'] as $key)if($p[$key]===$last[$key])$p[$key]=$last[$key];
            $calculated=$last!==null&&$p['source']===$last['source']&&$p['carry']===$last['carry']&&$p['input']===$last['input']
                ?$last['proof']:self::calculate($club,$p['source'],$p['input'],$p['carry']['proof']??null);
            if($calculated!==$p['proof'])throw new DomainException('Prüfverlauf der Bereichsaufteilung ist beschädigt.');
            $p['proof']=$calculated;
            if($p['carry']!==null){
                $carry=$p['carry'];
                if(!is_array($carry)||array_keys($carry)!==['period_id','revision','sha256','proof']||!is_string($carry['period_id'])||!is_int($carry['revision'])||$carry['revision']<1)throw new DomainException('Vortrag der Bereichsaufteilung prüfen.');
                if($carry['revision']>1000||!is_string($carry['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$carry['sha256']))throw new DomainException('Ursprünglichen Bereichsvortrag vollständig auswählen.');
                if($journal['previous']===null)throw new DomainException('Bereichsvortrag ohne tatsächlichen Vorzeitraum.');
                $priorId=$journal['previous']['kind']==='calendar'?'calendar:'.$journal['previous']['id'].':':$journal['previous']['id'];
                if($journal['previous']===null||($journal['previous']['kind']==='calendar'?!in_array($carry['period_id'],[$priorId.'segments',$priorId.'clearing'],true):$carry['period_id']!==$priorId))throw new DomainException('Bereichsvortrag gehört nicht zum tatsächlichen Vorzeitraum.');
                $priorKey=$carry['period_id'].':'.$carry['revision'].':'.$carry['sha256'];
                $prior=$priors[$priorKey]??=str_starts_with($carry['period_id'],'calendar:')
                    ?(new BalanceCalendarAreaSource($this->db,$this->actor))->read($carry['period_id'],$carry,$depth+1)
                    :$this->originalState($carry['period_id'],$carry['revision'],$carry['sha256'],$depth+1);
                if(($prior['state']??'reviewed')!=='reviewed'||$prior['proof']!==$carry['proof'])throw new DomainException('Unveränderte Bereichsfreigabe des Vorzeitraums nachweisen.');
                if($prior['source']['closed_sha256']!==$journal['previous']['sha256'])throw new DomainException('Bereichsvortrag und ursprünglicher Buchungsvortrag stimmen nicht überein.');
                $p['carry']['proof']=$prior['proof'];
                $expected=BalanceClearingStatement::carry($prior['source'],$prior['proof']['allocation'],$prior['proof']['clearing'],$p['source'],$prior['proof']['assets']['movements']??[]);
                $actualOpening=array_filter($p['input']['allocations'],static fn($key)=>str_starts_with($key,'opening:'),ARRAY_FILTER_USE_KEY);
                if($actualOpening!==$expected['allocations']||$p['input']['capital_carry']!==($expected['capital_carry']??[]))throw new DomainException('Vorgetragene Bereichsbestände wurden verändert.');
            }elseif($p['source']['previous_sha256']!==null)throw new DomainException('Vortragsnachweis der Bereichsaufteilung fehlt.');
            if($p['state']==='reviewed'&&(!$out||end($out)['state']!=='draft'||end($out)['source']!==$p['source']||end($out)['input']!==$p['input']||end($out)['carry']!==$p['carry']||!$p['proof']['complete']))throw new DomainException('Bestätigung ohne unveränderte vorbereitete Bereichsaufteilung.');
            if($last!==null){$p['input']=self::shareEqual($p['input'],$last['input']);$p['proof']=self::shareEqual($p['proof'],$last['proof']);}
            $previousHash=$row['sha256'];$out[]=$p+['sha256'=>$previousHash];
        }
        return $out;
    }
    /** Exact actual historical state. It never grants current approval. */
    public function originalState(string $id,int $revision,string $sha256,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Verkettung der ursprünglichen Bereichsaufteilung prüfen.');
        return BalanceReadSnapshot::run($this->db,fn()=>BalanceReadSnapshot::remember($this->db,__METHOD__,[$this->actor,$id,$revision,$sha256],fn()=>$this->originalStateUncached($id,$revision,$sha256,$depth)));
    }
    private function originalStateUncached(string $id,int $revision,string $sha256,int $depth):array
    {
        $a=$this->access();
        if($depth>100||!preg_match('/\A[0-9]{8}-[0-9]{8}\z/D',$id)||$revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprüngliche Bereichsaufteilung vollständig auswählen.',404);
        $history=$this->history($a['club_id'],$id,$depth);$row=$history[$revision-1]??null;
        if(!$row||!hash_equals($row['sha256'],$sha256))throw new DomainException('Ursprüngliche Bereichsaufteilung nicht gefunden.',404);
        $prior=null;$carry=$row['carry'];
        if($carry!==null)$prior=str_starts_with($carry['period_id'],'calendar:')?(new BalanceCalendarAreaSource($this->db,$this->actor))->read($carry['period_id'],$carry,$depth+1):$this->originalState($carry['period_id'],$carry['revision'],$carry['sha256'],$depth+1);
        return ['period_id'=>$id,'source'=>$row['source'],'version'=>$revision,'sha256'=>$row['sha256'],'state'=>$row['state'],
            'input'=>$row['input'],'proof'=>$row['proof'],'history'=>array_slice($history,0,$revision),'prior'=>$prior,
            'source_current'=>true,'carry_current'=>true,'current_approval'=>false,'tax_export_ready'=>false];
    }
    public function original(string $id,int $revision,string $sha256):array
    {
        $state=$this->originalState($id,$revision,$sha256);return end($state['history']);
    }
    public function originalIncome(string $id,int $revision,string $sha256,int $depth=0):array
    {
        return $this->originalIncomeFromState($this->originalState($id,$revision,$sha256,$depth),$depth);
    }
    private function originalIncomeFromState(array $state,int $depth):array
    {
        if($depth>100||$state['state']!=='reviewed')throw new DomainException('Ursprüngliche bestätigte Bereichsaufteilung auswählen.');
        $prior=$state['prior'];$previous=$prior===null?null:(str_starts_with($prior['period_id'],'calendar:')?(new BalanceCalendarAreaSource($this->db,$this->actor))->originalIncome($prior['period_id'],$prior['version'],$prior['sha256'],$depth+1):$this->originalIncomeFromState($prior,$depth+1));
        $keys=['year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule'];
        $source=array_replace(array_fill_keys($keys,null),array_intersect_key($state['source'],array_flip($keys)));$source['period']=BalancePeriod::check($state['source']['period'],$source['year']);
        $hasAssets=isset($state['proof']['assets']);if($hasAssets)$source['assets']=$state['proof']['assets'];
        $last=end($state['history']);$ref=['revision'=>$last['revision'],'sha256'=>$last['sha256']];
        return BalanceClubIncomeReconciliation::rebuild(['schema'=>$hasAssets?3:2,'kind'=>'club_income_reconciliation','club_id'=>$last['club_id'],'source'=>$source,
            'allocation'=>$state['proof']['allocation'],'clearing'=>$state['proof']['clearing'],'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>$previous['scope']??null]);
    }
    public function view(string $id,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Verkettung der Bereichsaufteilung prüfen.');
        $kind=__METHOD__; // PHP 8.3 closure names are not unique read-model identities.
        return BalanceReadSnapshot::run($this->db,fn()=>BalanceReadSnapshot::remember($this->db,$kind,[$this->actor,$id],fn()=>$this->viewUncached($id,$depth)));
    }
    private function viewUncached(string $id,int $depth):array
    {
        if($depth>100)throw new DomainException('Verkettung der Bereichsaufteilung prüfen.');$a=$this->access();
        $r=(new BalancePeriodLedger($this->db,$this->actor))->report($id);$source=self::source($r);
        $history=$this->history($a['club_id'],$id);$last=$history?end($history):null;$prior=null;$carryCurrent=true;
        if($r['previous']!==null){
            if($r['previous']['kind']==='period'){
                $prior=$this->view($r['previous']['id'],$depth+1);
                $carryCurrent=$prior['current_approval']&&($last===null||($last['carry']['sha256']??null)===$prior['sha256']);
            }else{
                $prior=(new BalanceCalendarAreaSource($this->db,$this->actor))->current((int)$r['previous']['id']);
                $carryCurrent=$prior['current_approval']&&($last===null||(($last['carry']['sha256']??null)===$prior['sha256']&&($last['carry']['period_id']??null)===$prior['period_id']));
            }
        }
        $sourceCurrent=$last===null||$last['source']===$source;$authority=false;
        if(($last['state']??null)==='reviewed'){
            try{$reviewer=Permissions::actor($this->db,$last['actor']);$authority=$reviewer['club_id']===$a['club_id']&&$reviewer['finance_access']==='write'&&BoardAccess::allowed($this->db,$last['actor']);}catch(DomainException){}
        }
        return ['period_id'=>$id,'source'=>$source,'version'=>$last['revision']??0,'sha256'=>$last['sha256']??null,'state'=>$last['state']??'missing',
            'input'=>$last['input']??null,'proof'=>$last['proof']??null,'history'=>$history,'prior'=>$prior,'source_current'=>$sourceCurrent,'carry_current'=>$carryCurrent,
            'current_approval'=>$authority&&$sourceCurrent&&$carryCurrent,'tax_export_ready'=>false];
    }
    /** Form/retry basis selected from actual originals, independent of newer reviews. */
    private function actionState(array $actor,string $id,int $version,string $sourceHash,?array $carry):array
    {
        if($version<0||$version>=1000)throw new DomainException('Ursprüngliche Bereichsversion vollständig auswählen.',409);
        $history=$this->history($actor['club_id'],$id);if($version>count($history))throw new DomainException('Ursprüngliche Bereichsversion nicht gefunden.',409);
        $journal=(new BalancePeriodLedger($this->db,$this->actor))->originalSource($id,$sourceHash);$source=self::source($journal);$prior=null;
        if($journal['previous']===null){if($carry!==null)throw new DomainException('Ursprünglichen Bereichsvortrag prüfen.',409);}
        else{
            if(!is_array($carry)||array_keys($carry)!==['period_id','revision','sha256','proof']||!is_string($carry['period_id'])||!is_int($carry['revision'])||$carry['revision']<1||$carry['revision']>1000||!is_string($carry['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$carry['sha256']))throw new DomainException('Ursprünglichen Bereichsvortrag vollständig auswählen.',409);
            $prior=str_starts_with($carry['period_id'],'calendar:')?(new BalanceCalendarAreaSource($this->db,$this->actor))->read($carry['period_id'],$carry):$this->originalState($carry['period_id'],$carry['revision'],$carry['sha256']);
            $expected=$journal['previous']['kind']==='calendar'?'calendar:'.$journal['previous']['id'].':':$journal['previous']['id'];
            if(($prior['state']??'reviewed')!=='reviewed'||$prior['proof']!==$carry['proof']||$prior['source']['closed_sha256']!==$journal['previous']['sha256']||($journal['previous']['kind']==='calendar'?!in_array($carry['period_id'],[$expected.'segments',$expected.'clearing'],true):$carry['period_id']!==$expected))throw new DomainException('Ursprünglicher Bereichs- und Buchungsvortrag stimmen nicht überein.',409);
        }
        $history=array_slice($history,0,$version);$last=$history?end($history):null;
        return ['period_id'=>$id,'source'=>$source,'version'=>$version,'sha256'=>$last['sha256']??null,'state'=>$last['state']??'missing','input'=>$last['input']??null,'proof'=>$last['proof']??null,'history'=>$history,'prior'=>$prior,
            'source_current'=>$last===null||$last['source']===$source,'carry_current'=>$last===null||$last['carry']===$carry,'current_approval'=>false,'tax_export_ready'=>false,'_original_journal'=>$journal];
    }
    public function forAction(string $id,int $version,string $sourceHash,?array $priorReference):array
    {
        $actor=$this->access();$carry=null;
        if($priorReference!==null){
            if(array_keys($priorReference)!==['period_id','revision','sha256']||!is_string($priorReference['period_id'])||!is_int($priorReference['revision'])||$priorReference['revision']<1||$priorReference['revision']>1000||!is_string($priorReference['sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$priorReference['sha256']))throw new DomainException('Ursprünglichen Bereichsvortrag vollständig auswählen.',409);
            $ref=$priorReference;$prior=str_starts_with($ref['period_id'],'calendar:')?(new BalanceCalendarAreaSource($this->db,$this->actor))->read($ref['period_id'],$ref):$this->originalState($ref['period_id'],$ref['revision'],$ref['sha256']);
            $carry=$ref+['proof'=>$prior['proof']];
        }
        return $this->actionState($actor,$id,$version,$sourceHash,$carry);
    }
    private function write(string $id,int $version,string $sourceHash,string $note,callable $fn,bool $board=false,array $expect=[]):array
    {
        if($this->db->inTransaction())throw new DomainException('Eigener Speichervorgang erforderlich.');$a=$this->access(true);$note=self::note($note);$this->db->beginTransaction();
        try{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$a['club_id']])->fetchColumn();$again=$this->access(true);
            if($again['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            if($board)BoardAccess::require($this->db,$this->actor);
            $actual=(int)$this->q('SELECT COALESCE(MAX(revision),0) FROM balance_period_area_reviews WHERE club_id=? AND period_id=?',[$a['club_id'],$id])->fetchColumn();
            if($version!==$actual||$version>=1000){
                $history=$version>=0&&$version<1000?$this->history($a['club_id'],$id):[];$first=$history[$version]??null;
                if($first&&$first['actor']===$a['id']&&$first['source']['closed_sha256']===$sourceHash){
                    $s=$this->actionState($a,$id,$version,$sourceHash,$first['carry']);
                    try{
                        [$input,$carry,$state]=$fn($a,$s,true);$proof=self::calculate($a['club_id'],$s['source'],$input,$carry['proof']??null);
                        self::expectPrior($expect,$carry);
                        if($input===$first['input']&&$carry===$first['carry']&&$state===$first['state']&&$proof===$first['proof']&&$note===$first['note']){$this->db->commit();return $first;}
                    }catch(DomainException){}
                }
                throw new DomainException('Buchungsstand inzwischen geändert oder zu viele Prüfstände. Bitte neu laden.',409);
            }
            $s=$this->view($id);if($version<0||$s['version']!==$version||!hash_equals($s['source']['closed_sha256'],$sourceHash))throw new DomainException('Buchungsstand inzwischen geändert oder zu viele Prüfstände. Bitte neu laden.',409);
            [$input,$carry,$state]=$fn($a,$s,false);self::expectPrior($expect,$carry);
            $result=$this->append($a,$s,$input,$carry,$state,$note);$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function expectPrior(array $expect,?array $carry):void
    {
        if(array_diff(array_keys($expect),['prior_source']))throw new DomainException('Ursprünglichen Bereichsvortrag prüfen.',409);
        $ref=$carry===null?null:array_intersect_key($carry,array_flip(['period_id','revision','sha256']));
        if(array_key_exists('prior_source',$expect)&&$expect['prior_source']!==$ref)throw new DomainException('Bereichsvortrag inzwischen geändert. Ursprüngliches Formular erneut auswählen.',409);
    }
    private function append(array $a,array $s,array $input,?array $carry,string $state,string $note):array
    {
        $proof=self::calculate($a['club_id'],$s['source'],$input,$carry['proof']??null);
        if($state==='reviewed'&&!$proof['complete'])throw new DomainException(implode(' ',$proof['issues']));
        $p=['schema'=>1,'club_id'=>$a['club_id'],'period_id'=>$s['period_id'],'revision'=>$s['version']+1,'previous_sha256'=>$s['sha256'],'state'=>$state,'actor'=>$a['id'],'at'=>gmdate('c'),'note'=>self::note($note),'source'=>$s['source'],'input'=>$input,'carry'=>$carry,'proof'=>$proof];
        $hash=BalanceSegmentCalculation::hash($p);
        $this->q('INSERT INTO balance_period_area_reviews(club_id,period_id,revision,payload,sha256) VALUES(?,?,?,?,?)',[$a['club_id'],$s['period_id'],$p['revision'],BalancePeriodLedger::json($p),$hash]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.balance.period.areas.'.$state,$s['period_id'],$p['revision'],$p['at']]);
        return $p+['sha256'=>$hash];
    }
    /** Start or rebase a draft. Historical snapshots remain intact after corrections. */
    public function prepare(string $id,int $version,string $sourceHash,array $units,string $note,array $expect=[]):array
    {
        return $this->write($id,$version,$sourceHash,$note,function($a,$s,bool $replay)use($units,$note){
            $units=BalanceSegmentCalculation::units($units);$carry=null;$opening=[];$capital=[];
            if($s['source']['previous_sha256']!==null){
                $prior=$s['prior'];if(!$prior||(!$replay&&!$prior['current_approval']))throw new DomainException('Bereichsaufteilung des Vorzeitraums zuerst bestätigen.');
                foreach($prior['input']['units'] as $key=>$u)if(($units[$key]['sphere']??null)!==$u['sphere'])throw new DomainException('Bereiche des Vorzeitraums unverändert fortführen.');
                $c=BalanceClearingStatement::carry($prior['source'],$prior['proof']['allocation'],$prior['proof']['clearing'],$s['source'],$prior['proof']['assets']['movements']??[]);
                $opening=$c['allocations'];$capital=$c['capital_carry']??[];
                $carry=['period_id'=>$prior['period_id'],'revision'=>$prior['version'],'sha256'=>$prior['sha256'],'proof'=>$prior['proof']];
            }
            $rows=BalanceSegmentCalculation::sources($s['source']);$old=$s['input']['allocations']??[];$retained=[];
            $oldSource=$s['history']?end($s['history'])['source']:null;$oldRows=$oldSource?BalanceSegmentCalculation::sources($oldSource):[];
            foreach($old as $key=>$value)if(isset($rows[$key],$oldRows[$key])&&$rows[$key]===$oldRows[$key]&&!array_diff_key($value['parts'],$units)&&(!str_starts_with($key,'opening:')||$carry===null))$retained[$key]=$value;
            $input=['units'=>$units,'allocations'=>$opening+$retained+BalanceSegmentCalculation::suggest($s['source'],$units),'capital_carry'=>$capital,'transfers'=>[],'instructions'=>['advances'=>[],'repayments'=>[]]];
            // A new name or correcting entry must not silently discard real advances.
            if($s['input']!==null){$input['transfers']=$s['input']['transfers'];$input['instructions']=$s['input']['instructions'];}
            if(!empty($s['source']['asset_schedule']['items'])){
                $input['asset_shares']=[];$priorShares=$carry['proof']['assets']['shares']??[];
                foreach($s['source']['asset_schedule']['items'] as $key=>$item){$share=$priorShares[$key]??$s['input']['asset_shares'][$key]??null;
                    if(isset($priorShares[$key]))$share=array_replace($share,['weights'=>BalancePeriodAssetUse::closingWeights($share)]);
                    if($share===null){$eligible=array_keys(array_filter($units,static fn($u)=>$u['sphere']===($item['sphere_change']['book']['opening_sphere']??$item['plan']['sphere'])));if(count($eligible)===1)$share=['weights'=>[$eligible[0]=>1],'measure'=>'uses','reason'=>'Eindeutiger Vereinsbereich des bestätigten Originalplans.'];}
                    if($share!==null){$input['asset_shares'][$key]=['item_sha256'=>BalanceSegmentCalculation::hash($item),'weights'=>$share['weights'],'measure'=>$share['measure'],'reason'=>$share['reason']];
                        // Retain current-period evidence when rebasing; never replay last period's changes.
                        if(isset($s['input']['asset_shares'][$key]['changes']))$input['asset_shares'][$key]['changes']=$s['input']['asset_shares'][$key]['changes'];
                        if(isset($item['sphere_change'])){
                            $changes=BalancePeriodSphereAllocation::suggest($item['sphere_change'],$units,$input['asset_shares'][$key]['changes']??[]);
                            if($changes)$input['asset_shares'][$key]['changes']=$changes;
                        }}}
            }
            return [$input,$carry,'draft'];
        },false,$expect);
    }
    public function edit(string $id,int $version,string $sourceHash,string $operation,array $data,string $note,array $expect=[]):array
    {
        return $this->write($id,$version,$sourceHash,$note,function($a,$s,bool $replay)use($operation,$data,$note){
            if(!$s['input']||!$s['source_current']||!$s['carry_current'])throw new DomainException('Aufteilung zuerst mit dem aktuellen Buchungsstand abstimmen.');
            $input=$s['input'];$last=end($s['history']);
            if($operation==='voucher'){
                if(array_keys($data)!==['voucher','unit']||!is_string($data['voucher'])||!is_string($data['unit'])||!isset($input['units'][$data['unit']]))throw new DomainException('Beleg und Bereich auswählen.');
                if($last['carry']!==null&&$data['voucher']==='opening')throw new DomainException('Vorgetragene Anfangsbestände nicht ändern.');$found=false;
                foreach(BalanceSegmentCalculation::sources($s['source']) as $key=>$row)if($row['voucher']===$data['voucher']){$found=true;$input['allocations'][$key]=['parts'=>[$data['unit']=>$row['cents']],'reason'=>self::note($note)];}
                if(!$found)throw new DomainException('Originalbeleg nicht gefunden.');
            }elseif($operation==='line'){
                if(array_keys($data)!==['key','parts']||!is_string($data['key'])||!is_array($data['parts']))throw new DomainException('Buchungszeile und Aufteilung prüfen.');
                if($last['carry']!==null&&str_starts_with($data['key'],'opening:'))throw new DomainException('Vorgetragene Bereichsbestände nicht manuell ändern.');
                $input['allocations'][$data['key']]=['parts'=>$data['parts'],'reason'=>self::note($note)];
            }elseif($operation==='asset'){
                if(array_keys($data)!==['item','weights','measure']||!is_string($data['item'])||!isset($s['source']['asset_schedule']['items'][$data['item']])||!is_array($data['weights'])||!is_string($data['measure']))throw new DomainException('Gegenstand und Nutzungsanteile auswählen.');
                if(isset($input['asset_shares'][$data['item']]['changes']))throw new DomainException('Belegte Nutzungswechsel nicht durch eine pauschale Aufteilung ersetzen. Zuerst die betreffende Änderung berichtigen.');
                $input['asset_shares'][$data['item']]=['item_sha256'=>BalanceSegmentCalculation::hash($s['source']['asset_schedule']['items'][$data['item']]),'weights'=>$data['weights'],'measure'=>$data['measure'],'reason'=>self::note($note)];
            }elseif(in_array($operation,['asset-use-change','asset-use-remove'],true)){
                $keys=$operation==='asset-use-change'?['item','on','weights']:['item','on'];
                if(array_keys($data)!==$keys||!is_string($data['item'])||!is_string($data['on'])||!isset($input['asset_shares'][$data['item']]))throw new DomainException('Zuerst den Gegenstand und seine bisherige Nutzung zuordnen.');
                $share=&$input['asset_shares'][$data['item']];$changes=$share['changes']??[];$found=false;
                foreach($changes as $i=>$change)if($change['on']===$data['on']){$found=true;if($operation==='asset-use-remove')unset($changes[$i]);}
                if($operation==='asset-use-remove'&&!$found)throw new DomainException('Nutzungswechsel nicht gefunden.');
                if($operation==='asset-use-change'){
                    if($found||!is_array($data['weights']))throw new DomainException('Nutzungswechsel dieses Monats ist bereits erfasst.');
                    $changes[]=['on'=>$data['on'],'weights'=>$data['weights'],'reason'=>self::note($note)];usort($changes,static fn($a,$b)=>strcmp($a['on'],$b['on']));
                }
                if($changes)$share['changes']=array_values($changes);else unset($share['changes']);unset($share);
                // Source-bound depreciation/disposal pairs are unambiguous: users must not
                // calculate the changed expense shares or enter the same use evidence twice.
                $assets=BalancePeriodAssetAllocation::build($s['source'],$input['units'],[],$input['asset_shares'],$last['carry']['proof']['assets']??null,false);
                $journal=$replay?$s['_original_journal']:(new BalancePeriodLedger($this->db,$this->actor))->report($s['period_id']);$item=$s['source']['asset_schedule']['items'][$data['item']];
                foreach($journal['sources'] as $binding){$proposal=$binding['proposal'];
                    if($proposal['type']!=='depreciation'||($proposal['snapshot']['item_id']??null)!==$data['item'])continue;
                    foreach($binding['entry_ids'] as $entryId){$entry=$journal['entries'][$entryId];$field=null;$expenseSphere=null;
                        foreach($entry['lines'] as $line){$account=$s['source']['accounts'][$line['account']];
                            if($account['group']==='expense'&&in_array($account['skr_code'],['62000','62200','62600'],true)){$field='depreciation_cents';$expenseSphere=$line['sphere'];}
                            elseif(in_array($line['account'],$item['disposal_accounts']??[],true))$field='book_disposals_cents';}
                        if($field===null||count($entry['lines'])!==2)throw new DomainException('Ursprüngliche Anlagenbuchung vor der neuen Nutzungsaufteilung prüfen.');
                        if(!isset($assets['shares'][$data['item']]))continue; // Missing destination of THIS asset remains editable; other unassigned assets do not suppress this update.
                        $parts=[];foreach($assets['schedules'] as $unit=>$values){if($expenseSphere!==null&&$input['units'][$unit]['sphere']!==$expenseSphere)continue;$n=$values['items'][$data['item']][$field]??0;if($n>0)$parts[$unit]=$n;}
                        foreach($entry['lines'] as $ordinal=>$line){if(array_sum($parts)!==$line['debit_cents']+$line['credit_cents'])throw new DomainException('Anlagenbuchung und neue Nutzung ergeben nicht denselben Gesamtbetrag.');
                            $input['allocations'][$entryId.':'.$ordinal]=['parts'=>$parts,'reason'=>'Belegte Anlagennutzung: '.self::note($note)];}
                    }
                }
            }elseif($operation==='transfer'){
                if(array_keys($data)!==['voucher']||!is_string($data['voucher']))throw new DomainException('Originalzahlung auswählen.');
                $input['transfers'][$data['voucher']]=['account'=>$s['source']['result_account'],'parts'=>BalanceSegmentCalculation::transferParts($s['source'],$input['units'],$input['allocations'],$data['voucher']),'reason'=>self::note($note)];
            }elseif(in_array($operation,['advance','repayment'],true)){
                $type=$operation==='advance'?'advances':'repayments';$key=$data['id']??null;unset($data['id']);
                if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key)||isset($input['instructions']['advances'][$key])||isset($input['instructions']['repayments'][$key]))throw new DomainException('Eindeutige Verrechnungskennung erforderlich.');
                $input['instructions'][$type][$key]=$data;
            }elseif($operation==='remove'){
                if(array_keys($data)!==['type','key']||!is_string($data['key'])||!in_array($data['type'],['transfers','advances','repayments'],true))throw new DomainException('Aufzuhebende Zuordnung auswählen.');
                if(!isset(($data['type']==='transfers'?$input['transfers']:$input['instructions'][$data['type']])[$data['key']]))throw new DomainException('Interne Zuordnung ist nicht vorhanden.');
                if($data['type']==='transfers')unset($input['transfers'][$data['key']]);else unset($input['instructions'][$data['type']][$data['key']]);
            }else throw new DomainException('Unbekannte Bereichsänderung.');
            return [$input,$last['carry'],'draft'];
        },false,$expect);
    }
    public function confirm(string $id,int $version,string $sourceHash,string $hash,bool $reviewed,string $note,array $expect=[]):array
    {
        return $this->write($id,$version,$sourceHash,$note,function($a,$s)use($hash,$reviewed,$note){
            BoardAccess::require($this->db,$this->actor);
            if(!$reviewed||$s['state']!=='draft'||!$s['source_current']||!$s['carry_current']||!hash_equals($s['sha256'],$hash))throw new DomainException('Unveränderte Aufteilung ausdrücklich prüfen.');
            return [$s['input'],end($s['history'])['carry'],'reviewed'];
        },true,$expect);
    }

    public function incomeReconciliation(string $id,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Verkettung des Ergebnisabgleichs prüfen.');
        return $this->incomeFromState($this->view($id),$depth);
    }
    /** Load the full, verified chain once; never cache it between requests or writes. */
    public function reviewWithIncome(string $id):array
    {
        $s=$this->view($id);return ['state'=>$s,'income'=>$s['current_approval']?$this->incomeFromState($s,0):null];
    }
    private function incomeFromState(array $s,int $depth):array
    {
        if($depth>100)throw new DomainException('Verkettung des Ergebnisabgleichs prüfen.');
        if(!$s['current_approval'])throw new DomainException('Bereichsaufteilung zuerst bestätigen.');
        $previous=$s['prior']===null?null:(str_starts_with($s['prior']['period_id'],'calendar:')?(new BalanceCalendarAreaSource($this->db,$this->actor))->income($s['prior']):$this->incomeFromState($s['prior'],$depth+1));
        return BalanceClubIncomeReconciliation::fromPeriodState($s,$previous);
    }
}
