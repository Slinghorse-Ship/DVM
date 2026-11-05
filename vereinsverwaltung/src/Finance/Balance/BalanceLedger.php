<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Throwable;
require_once __DIR__.'/BalanceCalculation.php';
require_once __DIR__.'/BalanceSources.php';
require_once __DIR__.'/BalanceAssetSchedule.php';

/** Explicit accrual workspace, not an automatic reposting of cash transactions. */
require_once __DIR__.'/BalanceSourceDecisionSchema.php';
require_once __DIR__.'/BalanceSourceDecisionWorkflow.php';
final class BalanceLedger
{
    use BalanceSourceDecisionWorkflow;
    private bool $writing=false;
    /** Pilot until invoice/bank/asset integration and the annual tax taxonomy are released. */
    public static function enabled():bool{return getenv('VEREIN_BALANCE_WORKSPACE')==='1';}
    public function __construct(private PDO $db,private string $actorId){}
    private function access(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($write){
            if(!self::enabled())throw new DomainException('Das Bilanzjournal ist noch nicht für den Produktivbetrieb freigegeben.');
            $tax=(new Settings($this->db,$this->actorId))->section('tax');
            if(($tax['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Für diesen Verein ist die EÜR eingestellt. Keine Bilanzbuchung vorgenommen.');
            $start=(string)($tax['data']['fiscal_start']??'');
            if($start!==''&&substr($start,5)!=='01-01')throw new DomainException('Abweichende Wirtschaftsjahre sind für das Bilanzjournal noch nicht freigegeben.');
        }
        return $a;
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private static function json(mixed $data):string{return json_encode(OwnIntangibleStorage::normal($data),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function note(string $value):string
    {
        $value=trim($value);if($value===''||mb_strlen($value)>500||preg_match('/[\x00-\x1f]/',$value))throw new DomainException('Bezeichnung und Nachweis mit höchstens 500 Zeichen angeben.');return $value;
    }
    private function transaction(callable $fn):mixed
    {
        if($this->db->inTransaction())throw new DomainException('Bilanzbuchung benötigt einen eigenen Speichervorgang.');
        $initial=$this->access(true);
        $this->db->beginTransaction();try{
            // All journal writers (also year creation and close) serialize per club.
            // Establish the consistent read snapshot only AFTER acquiring this lock.
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']])->fetchColumn();
            $this->writing=true;
            $a=$this->access(true);
            if($a['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung inzwischen geändert. Bitte neu laden.');
            $r=$fn($a);$this->db->commit();return $r;
        }
        catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally{$this->writing=false;}
    }
    private function audit(array $a,string $action,int $year,int $version):void
    {
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'finance.balance.'.$action,(string)$year,$version,gmdate('c')]);
    }
    /** Internal semantic accounts. SKR42 codes must come from an explicitly reviewed mapping. */
    public static function defaultChart():array
    {
        $chart=[];foreach(BalanceCalculation::GROUPS as $id=>[$name])if($id!=='result_use')$chart[$id]=['name'=>$name,'group'=>$id,'skr_code'=>''];return BalanceCalculation::chart($chart);
    }
    private function row(array $actor,int $year):?array
    {
        BalanceCalculation::year($year);$r=$this->q('SELECT * FROM balance_years WHERE club_id=? AND tax_year=?'.($this->writing?' FOR UPDATE':''),[$actor['club_id'],$year])->fetch();
        if(!$r)return null;
        foreach(['accounts','opening','closed_snapshot'] as $key)$r[$key]=$r[$key]===null?null:json_decode($r[$key],true,512,JSON_THROW_ON_ERROR);
        $r['version']=(int)$r['version'];return $r;
    }
    private function editable(array $a,int $year,int $version,string $state):array
    {
        $r=$this->row($a,$year);if(!$r||$r['state']!==$state)throw new DomainException('Dieser Jahresstand kann hier nicht geändert werden.');
        if($r['version']!==$version)throw new DomainException('Jahresstand inzwischen geändert. Bitte neu laden.');
        if($r['previous_sha256']!==null){$prior=$this->report($year-1);if($prior['issues']||$prior['closed_sha256']!==$r['previous_sha256'])throw new DomainException('Berichtigtes Vorjahr zuerst prüfen und die Anfangsbestände übernehmen.');}
        return $r;
    }
    public function create(int $year):void
    {
        BalanceCalculation::year($year);
        $this->transaction(function($a)use($year){
            if($this->q('SELECT 1 FROM balance_periods WHERE club_id=? LIMIT 1',[$a['club_id']])->fetchColumn())throw new DomainException('Weitere Abschlüsse über die bereits eingerichteten Abschlusszeiträume anlegen.');
            if($this->row($a,$year))throw new DomainException('Bilanzjahr bereits vorhanden.');
            $last=$this->q('SELECT tax_year FROM balance_years WHERE club_id=? ORDER BY tax_year DESC LIMIT 1 FOR UPDATE',[$a['club_id']])->fetchColumn();
            if($last===false)$last=null;
            if($last!==null&&(int)$last!==$year-1)throw new DomainException('Bilanzjahre lückenlos nach dem letzten Jahresstand anlegen.');
            $previous=$last===null?null:$this->row($a,$year-1);$accounts=BalanceCalculation::chart(self::defaultChart()+(new BalanceSources($this->db,$this->actorId))->bankAccounts());$opening=[];$note='';$hash=null;$state='setup';$result='equity';
            if($previous){
                if($previous['state']!=='closed')throw new DomainException('Vorjahr zuerst prüfen und festschreiben.');
                $prior=$this->report($year-1);$accounts=$previous['accounts'];$result=$previous['result_account'];
                if($prior['issues'])throw new DomainException('Zuerst den aktuellen Vorjahresabschluss prüfen.');
                $opening=BalanceCalculation::carryForward($prior['calculation'],$result);$hash=$prior['closed_sha256'];$state='open';$note='Unveränderter Vortrag aus dem festgeschriebenen Vorjahr.';
            }
            $this->q('INSERT INTO balance_years(club_id,tax_year,version,state,accounts,opening,opening_note,result_account,previous_sha256,created_by,created_at) VALUES(?,?,1,?,?,?,?,?,?,?,?)',[$a['club_id'],$year,$state,self::json($accounts),self::json($opening),$note,$result,$hash,$a['id'],gmdate('c')]);
            $this->audit($a,'created',$year,1);
        });
    }
    public function setup(int $year,int $version,array $accounts,array $opening,string $resultAccount,string $evidence,bool $reviewed):void
    {
        $this->transaction(function($a)use($year,$version,$accounts,$opening,$resultAccount,$evidence,$reviewed){
            $r=$this->editable($a,$year,$version,'setup');
            if(!$reviewed)throw new DomainException('Anfangsbestände ausdrücklich anhand der Nachweise prüfen, auch bei null Euro.');
            $accounts=BalanceCalculation::chart($accounts);$opening=BalanceCalculation::lines($opening,$accounts,true);$evidence=self::note($evidence);
            if(($accounts[$resultAccount]['group']??'')!=='equity')throw new DomainException('Vereinskapitalkonto für den Ergebnisvortrag wählen.');
            $calculation=BalanceCalculation::build($year,$accounts,$opening,[]);
            if($calculation['issues'])throw new DomainException(implode(' ',$calculation['issues']));
            // Never validate or invent tax semantics from a first digit or a different catalog year.
            require_once __DIR__.'/BalanceTaxMapping.php';BalanceTaxMapping::checkChart($year,$accounts);
            $this->q("UPDATE balance_years SET accounts=?,opening=?,opening_note=?,result_account=?,state='open',version=version+1 WHERE club_id=? AND tax_year=?",[self::json($accounts),self::json($opening),$evidence,$resultAccount,$a['club_id'],$year]);
            $this->audit($a,'opening',$year,$version+1);
        });
    }
    public function addAccount(int $year,int $version,string $id,string $name,string $group,string $skrCode=''):void
    {
        $this->transaction(function($a)use($year,$version,$id,$name,$group,$skrCode){
            $r=$this->row($a,$year);
            if(!$r||!in_array($r['state'],['setup','open'],true)||$r['version']!==$version)throw new DomainException('Konto kann diesem Jahresstand nicht hinzugefügt werden. Bitte neu laden.');
            if(isset($r['accounts'][$id]))throw new DomainException('Dieses Bilanzkonto ist bereits vorhanden.');
            $new=['name'=>$name,'group'=>$group,'skr_code'=>$skrCode];
            if($skrCode!==''){
                require_once __DIR__.'/BalanceTaxMapping.php';$choice=BalanceTaxMapping::chartChoices($year)[$skrCode]??null;
                if(!$choice)throw new DomainException('Buchbares SKR42-Konto aus diesem Jahr auswählen.');
                // Unknown semantics require an explicit choice; never derive them from a first digit.
                // A manual bookkeeping group is not a released E-Bilanz destination.
                if($choice['group']!==null)$new['group']=$choice['group'];
                elseif(!isset(BalanceCalculation::GROUPS[$group]))throw new DomainException('Für dieses Konto den Bilanzbereich ausdrücklich auswählen.');
                if(trim($name)==='')$new['name']=$choice['name'];
            }
            $accounts=BalanceCalculation::chart($r['accounts']+[$id=>$new]);
            $this->q('UPDATE balance_years SET accounts=?,version=version+1 WHERE club_id=? AND tax_year=?',[self::json($accounts),$a['club_id'],$year]);$this->audit($a,'account-added',$year,$version+1);
        });
    }
    public function post(int $year,int $version,string $requestKey,string $date,string $description,string $evidence,array $lines):string
    {
        return $this->transaction(function($a)use($year,$version,$requestKey,$date,$description,$evidence,$lines){
            $r=$this->editable($a,$year,$version,'open');return $this->insert($a,$r,$requestKey,$date,$description,$evidence,$lines,null);
        });
    }
    /** Server-derived postings only; the browser supplies no amounts or accounting lines. */
    public function importSource(int $year,int $version,string $type,string $id,array $options,string $expectedHash,bool $reviewed):array
    {
        return $this->transaction(function($a)use($year,$version,$type,$id,$options,$expectedHash,$reviewed){
            return $this->importCheckedSource($a,$year,$version,$type,$id,$options,$expectedHash,$reviewed);
        });
    }
    /** Caller owns the transaction: annual stand and its journal either both persist or neither. */
    public function importSourceWithinTransaction(int $year,int $version,string $type,string $id,array $options,string $expectedHash,bool $reviewed):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Gemeinsamer Speichervorgang fehlt.');
        $initial=$this->access(true);
        $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']])->fetchColumn();
        $a=$this->access(true);if($initial['club_id']!==$a['club_id'])throw new DomainException('Vereinszuordnung geändert.');
        $previous=$this->writing;$this->writing=true;
        try{return $this->importCheckedSource($a,$year,$version,$type,$id,$options,$expectedHash,$reviewed);}
        finally{$this->writing=$previous;}
    }
    private function importCheckedSource(array $a,int $year,int $version,string $type,string $id,array $options,string $expectedHash,bool $reviewed):array
    {
            $r=$this->editable($a,$year,$version,'open');
            if(!$reviewed)throw new DomainException('Beleg, Leistungsdatum und Zuordnung zuerst prüfen.');
            $sources=new BalanceSources($this->db,$this->actorId);
            $p=$sources->preview($year,$type,$id,$options,true);
            if($p['issues'])throw new DomainException(implode(' ',$p['issues']));
            if(!hash_equals($p['hash'],$expectedHash))throw new DomainException('Beleg oder Zuordnung inzwischen geändert. Erneut prüfen.');
            if($sources->binding($type,$id))throw new DomainException('Dieser Beleg wurde bereits in das Bilanzjournal übernommen.');
            if($this->q('SELECT 1 FROM balance_period_sources WHERE club_id=? AND source_type=? AND source_id=?',[$a['club_id'],$type,$id])->fetchColumn())throw new DomainException('Dieser Beleg gehört bereits zu einem Abschlusszeitraum. Keine zweite Buchung im Kalenderjournal.');
            $accounts=$r['accounts'];
            foreach($p['accounts'] as $key=>$account){
                if(isset($accounts[$key])&&$accounts[$key]!==$account)throw new DomainException('Quellkonto widerspricht dem vorhandenen Bilanzkonto.');
                $accounts[$key]=$account;
            }
            $r['accounts']=BalanceCalculation::chart($accounts);
            $this->q('UPDATE balance_years SET accounts=? WHERE club_id=? AND tax_year=?',[self::json($r['accounts']),$a['club_id'],$year]);
            $ids=[];
            foreach($p['vouchers'] as $v){
                $ids[]=$this->insert($a,$r,bin2hex(random_bytes(16)),$v['date'],$v['description'],$p['evidence'],$v['lines'],null);
                $r['version']++;
            }
            if(!$ids){
                if(!AssetDisposal::nonPosting($p))throw new DomainException('Leere Buchungsquelle ist nicht zulässig.');
                $this->q('UPDATE balance_years SET version=version+1 WHERE club_id=? AND tax_year=?',[$a['club_id'],$year]);
                $this->audit($a,'asset-zero-disposal',$year,$r['version']+1);
            }
            $this->q('INSERT INTO balance_sources(club_id,source_type,source_id,tax_year,source_hash,snapshot,options,entry_ids,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$type,$id,$year,$p['hash'],self::json($p['snapshot']),self::json($p['options']),self::json($ids),$a['id'],gmdate('c')]);
            $this->sourceHistory($a,$p,$ids,[],null,'Geprüfte Erstübernahme');
            return $ids;
    }
    private function sourceHistory(array $actor,array $proposal,array $ids,array $reversals,?array $previous,string $reason):void
    {
        if($this->sourceDecisionRequest!==null&&!$this->sourceDecisionFlushing){$this->sourceDecisionPending[]=['proposal'=>$proposal,'ids'=>$ids,'reversals'=>$reversals,'previous'=>$previous,'reason'=>$reason];return;}
        $revision=$previous?(int)$previous['revision']+1:1;
        if($revision>1000)throw new DomainException('Zu viele Belegkorrekturen. Betrieb prüfen.');
        $payload=['proposal'=>$proposal,'entry_ids'=>$ids,'reversal_ids'=>$reversals,'previous_sha256'=>$previous['sha256']??null,'reason'=>$reason,'actor'=>$actor['id'],'at'=>gmdate('c')];
        if($this->sourceDecisionContext!==null)$payload['decision']=BalanceSourceDecisionSchema::context($this->sourceDecisionContext);
        $hash=BalanceSourceHistory::digest($actor['club_id'],$proposal['type'],$proposal['id'],$revision,$payload);
        $this->q('INSERT INTO balance_source_history(club_id,source_type,source_id,revision,payload,sha256) VALUES(?,?,?,?,?,?)',[$actor['club_id'],$proposal['type'],$proposal['id'],$revision,self::json($payload),$hash]);
        $this->q('UPDATE balance_sources SET history_sha256=? WHERE club_id=? AND source_type=? AND source_id=?',[$hash,$actor['club_id'],$proposal['type'],$proposal['id']]);
    }
    /** Retain originals, then append reversals/replacements and advance source pointers atomically. */
    public function correctSource(int $year,int $version,string $type,string $id,array $options,string $expectedHash,string $reason,bool $reviewed):array
    {
        return $this->transaction(fn($a)=>$this->correctCheckedSource($a,$year,$version,$type,$id,$options,$expectedHash,$reason,$reviewed));
    }
    private function correctCheckedSource(array $a,int $year,int $version,string $type,string $id,array $options,string $expectedHash,string $reason,bool $reviewed):array
    {
        $r=$this->editable($a,$year,$version,'open');$reason=self::note($reason);
        if(!$reviewed)throw new DomainException('Änderung und betroffene Buchungen zuerst prüfen.');
        $sources=new BalanceSources($this->db,$this->actorId);$plan=$sources->correctionPreview($year,$type,$id,$options,true);
        if($plan['issues'])throw new DomainException(implode(' ',$plan['issues']));
        if(!hash_equals($plan['hash'],$expectedHash))throw new DomainException('Beleg oder Folgebelege inzwischen geändert. Korrektur erneut prüfen.');
        $accounts=$r['accounts'];
        foreach($plan['items'] as $item)foreach($item['proposal']['accounts'] as $key=>$account){
            if(isset($accounts[$key])&&$accounts[$key]!==$account)throw new DomainException('Quellkonto widerspricht dem vorhandenen Bilanzkonto.');
            $accounts[$key]=$account;
        }
        $r['accounts']=BalanceCalculation::chart($accounts);
        $this->q('UPDATE balance_years SET accounts=? WHERE club_id=? AND tax_year=?',[self::json($r['accounts']),$a['club_id'],$year]);
        $result=[];
        foreach($plan['items'] as $item){
            $old=$item['before']['payload'];$p=$item['proposal'];$reversals=[];$ids=[];
            foreach($old['entry_ids'] as $index=>$entryId){
                $v=$old['proposal']['vouchers'][$index];$lines=$v['lines'];
                foreach($lines as &$line)[$line['debit_cents'],$line['credit_cents']]=[$line['credit_cents'],$line['debit_cents']];unset($line);
                $reversals[]=$this->insert($a,$r,bin2hex(random_bytes(16)),$v['date'],'Korrekturstorno: '.$entryId,$reason,$lines,$entryId);$r['version']++;
            }
            foreach($p['vouchers'] as $v){
                $ids[]=$this->insert($a,$r,bin2hex(random_bytes(16)),$v['date'],$v['description'],$p['evidence'],$v['lines'],null);$r['version']++;
            }
            if(!$ids&&!$reversals){
                if(!AssetDisposal::nonPosting($p))throw new DomainException('Leere Korrekturquelle ist nicht zulässig.');
                $this->q('UPDATE balance_years SET version=version+1 WHERE club_id=? AND tax_year=?',[$a['club_id'],$year]);$r['version']++;
            }
            $this->q('UPDATE balance_sources SET source_hash=?,snapshot=?,options=?,entry_ids=? WHERE club_id=? AND source_type=? AND source_id=? AND tax_year=?',[$p['hash'],self::json($p['snapshot']),self::json($p['options']),self::json($ids),$a['club_id'],$p['type'],$p['id'],$year]);
            $this->sourceHistory($a,$p,$ids,$reversals,$item['before'],$reason);
            $result[]=['type'=>$p['type'],'id'=>$p['id'],'entries'=>$ids,'reversals'=>$reversals];
        }
        $this->flushSourceDecisionHistory($a,$year);
        foreach($result as $item){
            $binding=$sources->binding($item['type'],$item['id']);
            (new BalanceSourceHistory($this->db,$this->actorId,true))->checked($binding);
        }
        $this->audit($a,'source-corrected',$year,$r['version']);return $result;
    }
    private function insert(array $a,array $year,string $id,string $date,string $description,string $evidence,array $lines,?string $reversal):string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||TaxCatalog::documentYear($date)!==(int)$year['tax_year'])throw new DomainException('Buchungsdatum und Vorgangskennung prüfen.');
        $description=self::note($description);$evidence=self::note($evidence);$lines=BalanceCalculation::lines($lines,$year['accounts']);
        if($reversal===null)DatevComparisonCatalog::assertPosting((int)$year['tax_year'],$year['accounts'],$lines);
        if($this->q('SELECT 1 FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$a['club_id'],$year['tax_year'],$id])->fetchColumn())throw new DomainException('Dieser Vorgang wurde bereits gebucht.');
        $vouchers=$this->entries($a,(int)$year['tax_year']);$vouchers[]=['id'=>$id,'date'=>$date,'lines'=>$lines];
        BalanceCalculation::build((int)$year['tax_year'],$year['accounts'],$year['opening'],$vouchers);
        $this->q('INSERT INTO balance_entries(club_id,tax_year,id,booked_on,description,evidence,posting_lines,reversal_of,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$year['tax_year'],$id,$date,$description,$evidence,self::json($lines),$reversal,$a['id'],gmdate('c')]);
        $this->q('UPDATE balance_years SET version=version+1 WHERE club_id=? AND tax_year=?',[$a['club_id'],$year['tax_year']]);$this->audit($a,$reversal?'reversal':'posted',(int)$year['tax_year'],$year['version']+1);return $id;
    }
    public function reverse(int $year,int $version,string $id,string $date,string $reason,string $requestKey):string
    {
        return $this->transaction(function($a)use($year,$version,$id,$date,$reason,$requestKey){
            $r=$this->editable($a,$year,$version,'open');$entry=$this->q('SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$a['club_id'],$year,$id])->fetch();
            if($this->q('SELECT 1 FROM balance_sources WHERE club_id=? AND tax_year=? AND JSON_CONTAINS(entry_ids,JSON_QUOTE(?))',[$a['club_id'],$year,$id])->fetchColumn())throw new DomainException('Übernommene Belege über „Belegänderung prüfen“ korrigieren, damit Zahlungen und Abschreibungen mitgeprüft werden.');
            if(!$entry||$entry['reversal_of']!==null||$date<$entry['booked_on']||$this->q('SELECT 1 FROM balance_entries WHERE club_id=? AND tax_year=? AND reversal_of=?',[$a['club_id'],$year,$id])->fetchColumn())throw new DomainException('Diese Buchung kann nicht nochmals oder vor ihrem Buchungsdatum storniert werden.');
            $lines=json_decode($entry['posting_lines'],true,64,JSON_THROW_ON_ERROR);foreach($lines as &$l)[$l['debit_cents'],$l['credit_cents']]=[$l['credit_cents'],$l['debit_cents']];unset($l);
            return $this->insert($a,$r,$requestKey,$date,'Storno: '.$id,self::note($reason),$lines,$id);
        });
    }
    private function entries(array $a,int $year):array
    {
        $entries=$this->q('SELECT * FROM balance_entries WHERE club_id=? AND tax_year=? ORDER BY booked_on,id LIMIT 10001'.($this->writing?' FOR UPDATE':''),[$a['club_id'],$year])->fetchAll();
        if(count($entries)>10000)throw new DomainException('Mehr als 10.000 Bilanzbuchungen in diesem Jahr.');
        foreach($entries as &$e){$e['date']=$e['booked_on'];$e['lines']=json_decode($e['posting_lines'],true,64,JSON_THROW_ON_ERROR);unset($e['posting_lines']);}unset($e);return $entries;
    }
    public function report(int $year,bool $original=false):array
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $a=$this->access();$r=$this->row($a,$year);
            if(!$r)$out=['year'=>$year,'state'=>'missing','version'=>0,'calculation'=>null,'entries'=>[],'issues'=>['Bilanzjournal und geprüfte Anfangsbestände fehlen.'],'ready_for_tax_export'=>false];
            else{
                $entries=$this->entries($a,$year);$calculation=BalanceCalculation::build($year,$r['accounts'],$r['opening'],$entries);
                $issues=$calculation['issues'];if($r['state']==='setup')$issues[]='Anfangsbestände noch nicht bestätigt.';
                if($r['state']!=='closed')$issues[]='Buchführung, Bewertung und Jahresabgrenzung noch nicht abschließend bestätigt.';
                $out=['year'=>$year,'state'=>$r['state'],'version'=>$r['version'],'accounts'=>$r['accounts'],'opening'=>$r['opening'],'opening_note'=>$r['opening_note'],'result_account'=>$r['result_account'],'previous_sha256'=>$r['previous_sha256'],'calculation'=>$calculation,'entries'=>$entries,'issues'=>$issues,'ready_for_tax_export'=>false];
                if($r['state']==='closed'){
                    $stored=$r['closed_snapshot'];
                    if(!hash_equals($r['closed_sha256'],hash('sha256',self::json($stored)))||self::json($stored['calculation'])!==self::json($calculation)||self::json($stored['entries'])!==self::json($entries)||self::json($stored['opening'])!==self::json($r['opening'])||$stored['opening_note']!==$r['opening_note']||$stored['result_account']!==$r['result_account']||$stored['previous_sha256']!==$r['previous_sha256'])throw new DomainException('Festgeschriebener Bilanzstand stimmt nicht mit dem Journal überein. Betrieb prüfen.');
                    $out['review']=$stored['review'];$out['closed_sha256']=$r['closed_sha256'];$out['sources']=$stored['sources']??[];
                    $out['asset_schedule']=$stored['asset_schedule']??null;
                }
            }
            if(!$original&&$out['state']!=='missing'&&Database::tableExists($this->db,'balance_closing_corrections')
                &&$this->q('SELECT 1 FROM balance_closing_corrections WHERE club_id=? AND tax_year<=? LIMIT 1',[$a['club_id'],$year])->fetchColumn()){
                require_once __DIR__.'/BalanceClosingCorrections.php';
                if($out['state']==='closed')$out=(new BalanceClosingCorrections($this->db,$this->actorId))->report($year,null,$out);
                elseif($out['previous_sha256']!==null){$prior=$this->report($year-1);if($prior['issues']||$prior['closed_sha256']!==$out['previous_sha256'])$out['issues'][]='Berichtigtes Vorjahr zuerst prüfen und die Anfangsbestände übernehmen.';}
            }
            if($own)$this->db->commit();return $out;
        }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function archivedReport(int $year,string $hash):array
    {
        $raw=$this->report($year,true);
        if(($raw['closed_sha256']??null)===$hash)return $raw;
        require_once __DIR__.'/BalanceClosingCorrections.php';
        return (new BalanceClosingCorrections($this->db,$this->actorId))->report($year,$hash,$raw);
    }
    /** Reconfirm a changed opening only while the following journal is still open. */
    public function reconcileOpening(int $year,int $version,string $priorHash,bool $reviewed,string $note):void
    {
        $this->transaction(function($a)use($year,$version,$priorHash,$reviewed,$note){
            BoardAccess::require($this->db,$this->actorId);$r=$this->row($a,$year);$note=self::note($note);
            if(!$reviewed||!$r||$r['state']!=='open'||$r['version']!==$version||$r['previous_sha256']===null)throw new DomainException('Aktuelles offenes Folgejahr und Vorjahresabgleich bestätigen.');
            $prior=$this->report($year-1);
            if($prior['state']!=='closed'||$prior['issues']||$prior['closed_sha256']!==$priorHash||$priorHash===$r['previous_sha256'])throw new DomainException('Geprüften berichtigten Vorjahresabschluss auswählen.');
            foreach($prior['accounts'] as $id=>$account)if(($r['accounts'][$id]??null)!==$account)throw new DomainException('Vorjahreskonten unverändert fortführen.');
            $opening=BalanceCalculation::carryForward($prior['calculation'],$r['result_account']);
            $calculation=BalanceCalculation::build($year,$r['accounts'],$opening,$this->entries($a,$year));
            if($calculation['issues'])throw new DomainException(implode(' ',$calculation['issues']));
            $proof=['year'=>$year,'version'=>$version+1,'old_opening'=>$r['opening'],'old_note'=>$r['opening_note'],'old_previous_sha256'=>$r['previous_sha256'],
                'opening'=>$opening,'previous_sha256'=>$priorHash,'actor'=>$a['id'],'at'=>gmdate('c'),'note'=>$note];
            $json=self::json($proof);
            $this->q('INSERT INTO balance_opening_corrections(club_id,tax_year,version,payload,sha256) VALUES(?,?,?,?,?)',[$a['club_id'],$year,$version+1,$json,hash('sha256',$json)]);
            $this->q('UPDATE balance_years SET opening=?,opening_note=?,previous_sha256=?,version=version+1 WHERE club_id=? AND tax_year=?',[self::json($opening),$note,$priorHash,$a['club_id'],$year]);
            $this->audit($a,'opening-reconciled',$year,$version+1);
        });
    }
    /** Locks only the explicit accrual journal, not the separate cashbook or a tax return. */
    public function close(int $year,int $version,array $checks,string $note):void
    {
        $this->transaction(function($a)use($year,$version,$checks,$note){
            BoardAccess::require($this->db,$this->actorId);$r=$this->editable($a,$year,$version,'open');
            if($year>=(int)date('Y'))throw new DomainException('Das laufende Jahr kann noch nicht festgeschrieben werden.');
            if(array_keys($checks)!==['complete','valuation','cutoff','tax']||in_array(false,array_map(static fn($v)=>$v===true,$checks),true))throw new DomainException('Vollständigkeit, Bewertung, Jahresabgrenzung und steuerliche Behandlung prüfen.');
            $report=$this->report($year);if($report['calculation']['issues'])throw new DomainException(implode(' ',$report['calculation']['issues']));
            $sourceCheck=(new BalanceSources($this->db,$this->actorId))->reviewYear($year,true,$report['calculation']);
            if($sourceCheck['issues'])throw new DomainException(implode(' ',array_slice($sourceCheck['issues'],0,10)));
            $assetSchedule=(new BalanceAssetSchedule($this->db,$this->actorId))->build($year);
            if($assetSchedule['issues'])throw new DomainException(implode(' ',array_slice($assetSchedule['issues'],0,10)));
            $snapshot=['calculation'=>$report['calculation'],'entries'=>$report['entries'],'opening'=>$r['opening'],'opening_note'=>$r['opening_note'],'result_account'=>$r['result_account'],'previous_sha256'=>$r['previous_sha256'],
                'sources'=>$sourceCheck['sources'],
                'asset_schedule'=>$assetSchedule,
                'review'=>['actor'=>$a['id'],'at'=>gmdate('c'),'checks'=>$checks,'note'=>self::note($note)]];
            $json=self::json($snapshot);$this->q("UPDATE balance_years SET state='closed',version=version+1,closed_snapshot=?,closed_sha256=? WHERE club_id=? AND tax_year=?",[$json,hash('sha256',$json),$a['club_id'],$year]);$this->audit($a,'closed',$year,$version+1);
        });
    }
}
