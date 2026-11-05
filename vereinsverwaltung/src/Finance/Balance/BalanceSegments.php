<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceSegmentCalculation.php';
require_once __DIR__.'/BalanceLedger.php';
require_once __DIR__.'/BalanceClearingStatement.php';

/** Immutable, club-isolated allocation reviews. The original ledger is never changed. */
final class BalanceSegments
{
    public function __construct(private PDO $db,private string $actorId){}
    public static function defaults():array
    {
        return ['ideell'=>['name'=>'Allgemeine Vereinsarbeit','sphere'=>'1'],'vermoegen'=>['name'=>'Vermögensverwaltung','sphere'=>'2'],'zweck'=>['name'=>'Zweckbetrieb','sphere'=>'3']];
    }
    private function query(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private function actor(bool $write=false):array
    {
        $actor=Permissions::actor($this->db,$this->actorId);
        if(!in_array($actor['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if(!BalanceLedger::enabled()||!Database::tableExists($this->db,'balance_segment_reviews'))throw new DomainException('Betriebsaufteilung ist nicht freigeschaltet.');
        if($write&&((new Settings($this->db,$this->actorId))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Der Verein verwendet EÜR. Keine Bilanzaufteilung gespeichert.');
        return $actor;
    }
    private function history(string $club,int $year,array $report):array
    {
        $rows=$this->query('SELECT revision,payload,sha256 FROM balance_segment_reviews WHERE club_id=? AND tax_year=? ORDER BY revision LIMIT 1001',[$club,$year])->fetchAll();
        if(count($rows)>1000)throw new DomainException('Zu viele Aufteilungsstände.');
        $history=[];$previous=null;
        foreach($rows as $index=>$row){
            $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($p)||array_keys($p)!==['schema','club_id','year','revision','previous_sha256','journal_sha256','state','actor','at','note','carry_forward','allocation']
                ||!in_array($p['schema'],[1,2],true)||$p['club_id']!==$club||$p['year']!==$year||$p['revision']!==$index+1||(int)$row['revision']!==$index+1
                ||$p['previous_sha256']!==$previous||!is_string($p['journal_sha256'])||!in_array($p['state'],['draft','reviewed'],true)
                ||!is_string($p['actor'])||$p['actor']===''||!is_string($p['at'])||!is_string($p['note'])||!is_array($p['allocation'])
                ||!hash_equals($row['sha256'],BalanceSegmentCalculation::hash($p)))throw new DomainException('Gespeicherter Aufteilungsverlauf stimmt nicht überein.');
            $historic=$p['journal_sha256']===$report['closed_sha256']?$report:(new BalanceLedger($this->db,$this->actorId))->archivedReport($year,$p['journal_sha256']);
            $a=$p['allocation'];
            $carry=$p['carry_forward'];
            self::checkReference($carry,$year);
            if($p['schema']!==(isset($carry['clearing'])?2:1))throw new DomainException('Version des Vortragsnachweises prüfen.');
            if($history&&$carry!==end($history)['carry_forward']){
                $old=end($history)['carry_forward'];
                if($old===null||$carry===null||!self::newerReference($carry,$old)||$p['state']!=='draft'||!str_starts_with($p['note'],'Vortrag neu abgestimmt: '))throw new DomainException('Geänderte Vorjahresbindung ohne neuen Abstimmungsnachweis.');
            }
            if(!is_array($a['units']??null)||!is_array($a['allocations']??null)||BalanceSegmentCalculation::rebuild($historic,$a)!==$a||($p['state']==='reviewed'&&!$a['complete'])||(!empty($a['capital_carry'])&&$carry===null))throw new DomainException('Gespeicherte Aufteilung stimmt nicht mit der Vereinsbilanz überein.');
            if($p['state']==='reviewed'&&(!$history||end($history)['state']!=='draft'||end($history)['allocation']!==$a))throw new DomainException('Freigabe hat keinen unveränderten vorbereiteten Stand.');
            $previous=$row['sha256'];$history[]=$p+['sha256'=>$previous];
        }
        return $history;
    }
    private function state(array $actor,int $year,?array $report=null,?int $throughRevision=null,bool $originalSources=false):array
    {
        $report??=(new BalanceLedger($this->db,$this->actorId))->report($year);BalanceSegmentCalculation::sources($report);
        $history=$this->history($actor['club_id'],$year,$report);
        if($throughRevision!==null){if($throughRevision<0||$throughRevision>count($history))throw new DomainException('Ursprünglichen Aufteilungsstand erneut auswählen.',409);$history=array_slice($history,0,$throughRevision);}
        $last=$history?end($history):null;
        $allocation=$last['allocation']??BalanceSegmentCalculation::build($report,self::defaults(),[]);
        $sourceCurrent=$last===null||$last['journal_sha256']===$report['closed_sha256'];
        $current=false;
        if(($last['state']??'')==='reviewed'){
            try{$a=Permissions::actor($this->db,$last['actor']);$current=$a['club_id']===$actor['club_id']&&$a['finance_access']==='write'&&BoardAccess::allowed($this->db,$last['actor']);}catch(DomainException){}
        }
        $carry=$last['carry_forward']??null;$carryCurrent=true;$carriedUnits=[];
        if($carry!==null){
            $prior=$originalSources?$this->originalState($year-1,$carry['revision'],$carry['sha256']):$this->state($actor,$year-1);
            $historic=$sourceCurrent?$report:(new BalanceLedger($this->db,$this->actorId))->archivedReport($year,$last['journal_sha256']);
            [$expected,$carryCurrent]=$this->carried($prior,$carry,$historic,$originalSources);
            $carriedUnits=$expected['units'];
            self::checkCarriedOpening($allocation,$expected);
        }
        return ['year'=>$year,'version'=>$last['revision']??0,'state'=>$last['state']??'draft','allocation'=>$allocation,'journal'=>$report,'history'=>$history,'carry_forward'=>$carry,'carried_units'=>$carriedUnits,'carry_current'=>$carryCurrent,'source_current'=>$sourceCurrent,'current_approval'=>$current&&$carryCurrent&&$sourceCurrent,'tax_export_ready'=>false];
    }
    private static function checkReference(mixed $carry,int $year):void
    {
        if($carry===null)return;
        if(!is_array($carry))throw new DomainException('Vorjahresbindung der Aufteilung prüfen.');
        $keys=isset($carry['clearing'])?['year','revision','sha256','clearing']:['year','revision','sha256'];
        if(array_keys($carry)!==$keys||$carry['year']!==$year-1)throw new DomainException('Vorjahresbindung der Aufteilung prüfen.');
        $refs=[$carry];
        if(isset($carry['clearing'])){
            if(!is_array($carry['clearing'])||array_keys($carry['clearing'])!==['revision','sha256'])throw new DomainException('Bindung des Verrechnungsnachweises prüfen.');
            $refs[]=$carry['clearing'];
        }
        foreach($refs as $ref)if(!is_int($ref['revision'])||$ref['revision']<1||!is_string($ref['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$ref['sha256']))throw new DomainException('Quellstand des Vortrags prüfen.');
    }
    private static function newerReference(array $new,array $old):bool
    {
        return $new['revision']>=$old['revision']&&($new['clearing']['revision']??0)>=($old['clearing']['revision']??0)
            &&($new['revision']>$old['revision']||($new['clearing']['revision']??0)>($old['clearing']['revision']??0));
    }
    private function hasClearings(int $year):bool
    {
        return Database::tableExists($this->db,'balance_clearing_reviews')&&(bool)$this->query('SELECT 1 FROM balance_clearing_reviews WHERE club_id=? AND tax_year=? LIMIT 1',[$this->actor()['club_id'],$year])->fetchColumn();
    }
    /** Reconstruct the exact historical proof, independently of its current approval. */
    private function carried(array $prior,array $carry,array $report,bool $originalSources=false):array
    {
        $source=$prior['history'][$carry['revision']-1]??null;
        if(!$source||$source['sha256']!==$carry['sha256'])throw new DomainException('Aufteilung des Vorjahres stimmt nicht mit dem Vortragsnachweis überein.');
        if(isset($carry['clearing'])){
            require_once __DIR__.'/BalanceClearings.php';
            $service=new BalanceClearings($this->db,$this->actorId);$ref=$carry['clearing'];$w=$originalSources?null:$service->get($prior['year']);$checked=$originalSources?$service->original($prior['year'],$ref['revision'],$ref['sha256']):($w['history'][$ref['revision']-1]??null);
            if(!$checked||$checked['state']!=='reviewed'||$checked['sha256']!==$ref['sha256']||$checked['segment_source']!==['revision'=>$carry['revision'],'sha256'=>$carry['sha256']])throw new DomainException('Geprüfte Verrechnung gehört nicht zur vorgetragenen Aufteilung.');
            $historic=$source['journal_sha256']===$prior['journal']['closed_sha256']?$prior['journal']:(new BalanceLedger($this->db,$this->actorId))->archivedReport($prior['year'],$source['journal_sha256']);
            return [BalanceClearingStatement::carry($historic,$source['allocation'],$checked['proof'],$report),$originalSources||($w['current_approval']&&$w['version']===$ref['revision']&&$prior['version']===$carry['revision'])];
        }
        if($source['state']!=='reviewed')throw new DomainException('Vorstandsfreigabe der Vorjahresaufteilung fehlt.');
        $historic=$source['journal_sha256']===$prior['journal']['closed_sha256']?$prior['journal']:(new BalanceLedger($this->db,$this->actorId))->archivedReport($prior['year'],$source['journal_sha256']);
        return [BalanceSegmentCalculation::carry($historic,$source['allocation'],$report),$originalSources||($prior['current_approval']&&$prior['version']===$carry['revision']&&!$this->hasClearings($prior['year']))];
    }
    /** A reviewed clearing statement may carry real balances, never approve a tax projection. */
    private function forward(array $prior,array $report):array
    {
        if(!$prior['version'])throw new DomainException('Zuerst die Aufteilung des Vorjahres vorbereiten.');
        $ref=['year'=>$prior['year'],'revision'=>$prior['version'],'sha256'=>end($prior['history'])['sha256']];
        if($this->hasClearings($prior['year'])){
            require_once __DIR__.'/BalanceClearings.php';
            $w=(new BalanceClearings($this->db,$this->actorId))->get($prior['year']);
            if(!$w['current_approval']||!$w['statement']['complete'])throw new DomainException('Zuerst Buchungsaufteilung und Vorschüsse des Vorjahres vollständig abstimmen und durch den Vorstand prüfen lassen.');
            $ref['clearing']=['revision'=>$w['version'],'sha256'=>end($w['history'])['sha256']];
        }elseif(!$prior['current_approval'])throw new DomainException('Aktuelle Vorstandsfreigabe der Vorjahresaufteilung fehlt.');
        return [$this->carried($prior,$ref,$report)[0],$ref];
    }
    private static function checkCarriedOpening(array $allocation,array $expected):void
    {
        foreach($expected['units'] as $id=>$unit)if(($allocation['units'][$id]['sphere']??null)!==$unit['sphere'])throw new DomainException('Bereiche des Vorjahres unverändert fortführen.');
        foreach($expected['allocations'] as $key=>$row)if(($allocation['allocations'][$key]['parts']??null)!==$row['parts'])throw new DomainException('Vorgetragene Anfangsbestände nicht manuell verändern.');
        if(($allocation['capital_carry']??[])!==($expected['capital_carry']??[]))throw new DomainException('Kapitalbestände der Bereiche unverändert aus dem Vorjahr übernehmen.');
        foreach($expected['asset_shares']??[] as $id=>$share)if(($allocation['asset_shares'][$id]['weights']??null)!==$share['weights']||($allocation['asset_shares'][$id]['measure']??null)!==$share['measure'])throw new DomainException('Übernommene Anlagenaufteilung nicht ohne Abstimmung ändern oder entfernen.');
    }
    public function get(int $year):array
    {
        try{return $this->state($this->actor(),$year);}catch(\JsonException $e){throw new DomainException('Gespeicherten Aufteilungsverlauf prüfen.',0,$e);}
    }
    /** Build one original form basis, retaining its archived journal and allocation. */
    private function actionState(array $actor,int $year,int $version,?array $first=null):array
    {
        if($version<0||$version>=1000)throw new DomainException('Ursprünglichen Aufteilungsstand erneut auswählen.',409);
        if($first===null){
            $row=$this->query('SELECT payload FROM balance_segment_reviews WHERE club_id=? AND tax_year=? AND revision=?',[$actor['club_id'],$year,$version+1])->fetchColumn();
            if($row===false){$current=$this->state($actor,$year);if($current['version']!==$version)throw new DomainException('Ursprünglichen Aufteilungsstand erneut auswählen.',409);return $current;}
            $first=json_decode($row,true,512,JSON_THROW_ON_ERROR);
        }
        $report=(new BalanceLedger($this->db,$this->actorId))->archivedReport($year,$first['journal_sha256']);
        return $this->state($actor,$year,$report,$version,true);
    }
    public function forAction(int $year,int $version):array
    {
        try{return $this->actionState($this->actor(),$year,$version);}catch(\JsonException$e){throw new DomainException('Gespeicherten Aufteilungsverlauf prüfen.',0,$e);}
    }
    /** Exact historical basis. It never grants current approval or export authority. */
    public function originalState(int $year,int $revision,string $sha256):array
    {
        $actor=$this->actor();
        if($revision<1||$revision>1000||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglicher Ausgang der Bereichsaufteilung nicht gefunden.',404);
        $row=$this->query('SELECT payload,sha256 FROM balance_segment_reviews WHERE club_id=? AND tax_year=? AND revision=?',[$actor['club_id'],$year,$revision])->fetch();
        if(!$row||!hash_equals($row['sha256'],$sha256))throw new DomainException('Ursprünglicher Ausgang der Bereichsaufteilung nicht gefunden.',404);
        $first=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);$report=(new BalanceLedger($this->db,$this->actorId))->archivedReport($year,$first['journal_sha256']);$state=$this->state($actor,$year,$report,$revision,true);$result=$state['history'][$revision-1]??null;
        if(!$result||$result['sha256']!==$sha256)throw new DomainException('Ursprünglicher Ausgang der Bereichsaufteilung nicht gefunden.',404);
        $state['current_approval']=false;return $state;
    }
    public function original(int $year,int $revision,string $sha256):array
    {
        return $this->originalState($year,$revision,$sha256)['history'][$revision-1];
    }
    public function originalDetails(int $year,int $revision,string $sha256):array
    {
        $original=$this->original($year,$revision,$sha256);$journal=(new BalanceLedger($this->db,$this->actorId))->archivedReport($year,$original['journal_sha256']);
        return ['original'=>$original,'journal'=>$journal,'sources'=>BalanceSegmentCalculation::sources($journal)];
    }
    private function change(int $year,int $version,callable $work,bool $rebase=false,bool $board=false):array
    {
        if($this->db->inTransaction())throw new DomainException('Aufteilung benötigt einen eigenen Speichervorgang.');
        $initial=$this->actor(true);$this->db->beginTransaction();
        try{
            $this->query('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']]);$actor=$this->actor(true);
            if($actor['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung geändert.');
            if($board)BoardAccess::require($this->db,$this->actorId);
            $actual=(int)$this->query('SELECT COALESCE(MAX(revision),0)FROM balance_segment_reviews WHERE club_id=? AND tax_year=?',[$actor['club_id'],$year])->fetchColumn();
            if($actual!==$version||$version>=1000){
                $row=$version>=0&&$version<1000?$this->query('SELECT payload,sha256 FROM balance_segment_reviews WHERE club_id=? AND tax_year=? AND revision=?',[$actor['club_id'],$year,$version+1])->fetch():false;
                if($row){$first=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                    if($first['actor']===$actor['id']){
                        $old=$this->actionState($actor,$year,$version,$first);
                        if($first['carry_forward']!==null&&$first['carry_forward']!==$old['carry_forward']){
                            $ref=$first['carry_forward'];$prior=$this->originalState($year-1,$ref['revision'],$ref['sha256']);$old['_replay_forward']=[$this->carried($prior,$ref,$old['journal'],true)[0],$ref];if($old['carry_forward']!==null)$old['carry_current']=false;
                        }
                        $same=false;
                        try{$change=$work($old,true);[$allocation,$status,$note]=$change;$carry=$change[3]??$old['carry_forward'];$same=$first['allocation']===$allocation&&$first['state']===$status&&$first['note']===$note&&$first['carry_forward']===$carry&&$first['journal_sha256']===$old['journal']['closed_sha256'];}catch(DomainException){}
                        if($same){$result=$this->original($year,$version+1,$row['sha256']);$this->db->commit();return $result;}
                    }
                }
                throw new DomainException('Aufteilung inzwischen geändert. Bitte neu laden.',409);
            }
            $state=$this->state($actor,$year);
            if(!$state['carry_current']&&!$rebase)throw new DomainException('Vorjahresaufteilung nach dem Vortrag verändert. Zuerst den Vortrag neu abstimmen.');
            $change=$work($state);[$allocation,$status,$note]=$change;$carry=$change[3]??$state['carry_forward'];
            if($state['carry_forward']!==null&&!$rebase){
                $prior=$this->state($actor,$year-1);
                self::checkCarriedOpening($allocation,$this->carried($prior,$state['carry_forward'],$state['journal'])[0]);
            }
            $previous=$state['history']?end($state['history'])['sha256']:null;
            $payload=['schema'=>isset($carry['clearing'])?2:1,'club_id'=>$actor['club_id'],'year'=>$year,'revision'=>$version+1,'previous_sha256'=>$previous,'journal_sha256'=>$state['journal']['closed_sha256'],'state'=>$status,'actor'=>$actor['id'],'at'=>gmdate('c'),'note'=>$note,'carry_forward'=>$carry,'allocation'=>$allocation];
            $hash=BalanceSegmentCalculation::hash($payload);
            $this->query('INSERT INTO balance_segment_reviews(club_id,tax_year,revision,payload,sha256) VALUES(?,?,?,?,?)',[$actor['club_id'],$year,$version+1,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$hash]);
            $this->query('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$actor['club_id'],$actor['id'],'finance.balance.segments.'.$status,(string)$year,$version+1,$payload['at']]);
            $this->db->commit();return $payload+['sha256'=>$hash];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function save(int $year,int $version,array $units,array $allocations):array
    {
        return $this->change($year,$version,function($s,bool$replay=false)use($year,$units,$allocations){
            if(!$replay&&$s['version']===0&&($s['journal']['previous_sha256']??null)&&$this->query('SELECT 1 FROM balance_segment_reviews WHERE club_id=? AND tax_year=? LIMIT 1',[$this->actor()['club_id'],$year-1])->fetchColumn())throw new DomainException('Zuerst die Aufteilung des Vorjahres prüfen und übernehmen.');
            return [BalanceSegmentCalculation::build($s['journal'],$units,$allocations,$s['allocation']['capital_carry']??[],$s['allocation']['transfers']??[],$s['allocation']['asset_shares']??[]),'draft','Aufteilung bearbeitet. Vorherige Freigabe gilt nicht für diesen Stand.'];
        });
    }
    public function transfer(int $year,int $version,string $voucher,string $account,bool $reviewed,string $reason):array
    {
        return $this->change($year,$version,function($s)use($voucher,$account,$reviewed,$reason){
            if(!$reviewed)throw new DomainException('Endgültige interne Mittelübertragung ausdrücklich bestätigen. Bei Rückzahlung oder steuerlicher Unsicherheit die Buchhaltung hinzuziehen.');
            $a=$s['allocation'];$transfers=$a['transfers']??[];
            if(isset($transfers[$voucher]))throw new DomainException('Für diese Buchung ist bereits eine interne Mittelübertragung vorhanden.');
            $transfers[$voucher]=['account'=>$account,'parts'=>BalanceSegmentCalculation::transferParts($s['journal'],$a['units'],$a['allocations'],$voucher),'reason'=>$reason];
            return [BalanceSegmentCalculation::build($s['journal'],$a['units'],$a['allocations'],$a['capital_carry']??[],$transfers,$a['asset_shares']??[]),'draft','Endgültige interne Mittelübertragung zur Originalbuchung '.$voucher.' erfasst. Neue Vorstandsfreigabe erforderlich.'];
        });
    }
    public function removeTransfer(int $year,int $version,string $voucher,string $reason):array
    {
        return $this->change($year,$version,function($s)use($voucher,$reason){
            $a=$s['allocation'];$transfers=$a['transfers']??[];
            if(!isset($transfers[$voucher])||trim($reason)===''||mb_strlen($reason)>900||preg_match('/[\x00-\x1f]/',$reason))throw new DomainException('Vorhandene Mittelübertragung und Grund der Aufhebung angeben.');
            unset($transfers[$voucher]);
            return [BalanceSegmentCalculation::build($s['journal'],$a['units'],$a['allocations'],$a['capital_carry']??[],$transfers,$a['asset_shares']??[]),'draft','Mittelübertragung aufgehoben ('.$voucher.'): '.trim($reason)];
        });
    }
    public function asset(int $year,int $version,string $item,array $weights,string $measure,string $reason,bool $reviewed):array
    {
        return $this->change($year,$version,function($s)use($item,$weights,$measure,$reason,$reviewed){
            if(!$reviewed||!isset($s['journal']['asset_schedule']['items'][$item]))throw new DomainException('Vorhandenen Gegenstand und belegte Nutzungsaufteilung ausdrücklich bestätigen.');
            $a=$s['allocation'];$shares=$a['asset_shares']??[];
            $shares[$item]=['item_sha256'=>BalanceSegmentCalculation::hash($s['journal']['asset_schedule']['items'][$item]),'weights'=>$weights,'measure'=>$measure,'reason'=>$reason];
            return [BalanceSegmentCalculation::build($s['journal'],$a['units'],$a['allocations'],$a['capital_carry']??[],$a['transfers']??[],$shares),'draft','Nutzungsaufteilung der Anlage '.$item.' geprüft. Neue Vorstandsfreigabe erforderlich.'];
        });
    }
    public function confirm(int $year,int $version,bool $reviewed,string $note):array
    {
        return $this->change($year,$version,function($s)use($reviewed,$note){
            BoardAccess::require($this->db,$this->actorId);
            if(!$reviewed||!$s['source_current']||$s['state']!=='draft'||$s['version']===0||!$s['allocation']['complete']||trim($note)===''||mb_strlen($note)>1000||preg_match('/[\x00-\x1f]/',$note))throw new DomainException('Die vollständige Aufteilung und ihre Nachweise prüfen und ausdrücklich freigeben.');
            return [$s['allocation'],'reviewed',trim($note)];
        },false,true);
    }
    public function carry(int $year,int $version,bool $reviewed):array
    {
        return $this->change($year,$version,function($state)use($year,$reviewed){
            if(!$reviewed||$state['version']!==0)throw new DomainException('Vorjahr ausdrücklich prüfen und vor der ersten Aufteilung übernehmen.');
            [$opening,$ref]=$state['_replay_forward']??$this->forward($this->get($year-1),$state['journal']);
            return [$opening,'draft','Geprüfte Bereichssalden des Vorjahres übernommen; laufende Buchungen noch zuordnen.',$ref];
        });
    }
    /** Replace only the opening allocation with an explicitly re-reviewed prior version. */
    public function rebase(int $year,int $version,bool $reviewed,string $note):array
    {
        return $this->change($year,$version,function($state)use($year,$reviewed,$note){
            BoardAccess::require($this->db,$this->actorId);
            if(!$reviewed||$state['carry_forward']===null||$state['carry_current']||trim($note)===''||mb_strlen($note)>900||preg_match('/[\x00-\x1f]/',$note))throw new DomainException('Geänderten Vortrag ausdrücklich prüfen und begründen.');
            [$opening,$ref]=$state['_replay_forward']??$this->forward($this->get($year-1),$state['journal']);
            if(!self::newerReference($ref,$state['carry_forward']))throw new DomainException('Zuerst den geänderten Vorjahresstand durch den Vorstand prüfen lassen.');
            $units=$state['allocation']['units'];
            foreach($opening['units'] as $id=>$unit){
                if(isset($units[$id])&&$units[$id]['sphere']!==$unit['sphere'])throw new DomainException('Vereinsbereich eines fortgeführten Betriebs geändert. Buchhaltung hinzuziehen.');
                $units[$id]=$unit;
            }
            $allocations=array_filter($state['allocation']['allocations'],static fn($k)=>!str_starts_with($k,'opening:'),ARRAY_FILTER_USE_KEY)+$opening['allocations'];
            $shares=array_replace($state['allocation']['asset_shares']??[],$opening['asset_shares']??[]);
            return [BalanceSegmentCalculation::build($state['journal'],$units,$allocations,$opening['capital_carry']??[],$state['allocation']['transfers']??[],$shares),'draft','Vortrag neu abgestimmt: '.trim($note),$ref];
        },true,true);
    }
}
