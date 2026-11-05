<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Throwable;
require_once __DIR__.'/BalanceLedger.php';
require_once __DIR__.'/BalanceTaxMapping.php';
require_once __DIR__.'/BalancePeriodSources.php';
require_once __DIR__.'/BalancePeriodCorrectionWorkflow.php';
require_once __DIR__.'/BalancePeriodJournalWorkflow.php';

/** Dated journals. Every change is appended; neither a booking nor a closing is overwritten. */
final class BalancePeriodLedger
{
    use BalancePeriodCorrectionWorkflow;
    use BalancePeriodJournalWorkflow;
    public function __construct(private PDO $db,private string $actorId){}
    public static function enabled():bool{return BalanceLedger::enabled()&&getenv('VEREIN_BALANCE_PERIODS')==='1'&&in_array(getenv('VEREIN_MODE'),['synthetic','production'],true);}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    public static function json(mixed $data):string{return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private static function note(string $text):string
    {
        $text=trim($text);if($text===''||mb_strlen($text)>500||preg_match('/[\x00-\x1f]/',$text))throw new DomainException('Bezeichnung und Nachweis mit höchstens 500 Zeichen angeben.');return $text;
    }
    private static function id(string $id):void
    {
        if(!preg_match('/^[0-9]{8}-[0-9]{8}$/D',$id))throw new DomainException('Abschlusszeitraum auswählen.');
    }
    private function access(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        if($write){
            if(!self::enabled())throw new DomainException('Buchungen für abweichende Abschlusszeiträume sind noch nicht freigegeben.');
            if(((new Settings($this->db,$this->actorId))->section('tax')['data']['accounting_basis']??'euer')!=='balance')throw new DomainException('Für diesen Verein gilt die EÜR. Keine Bilanzbuchung vorgenommen.');
        }
        return $a;
    }
    private function transaction(callable $fn):mixed
    {
        if($this->db->inTransaction())throw new DomainException('Eigener Speichervorgang erforderlich.');
        $initial=$this->access(true);$this->db->beginTransaction();
        try{
            $this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE',[$initial['club_id']])->fetchColumn();
            $a=$this->access(true);if($a['club_id']!==$initial['club_id'])throw new DomainException('Vereinszuordnung geändert. Bitte neu laden.');
            $result=$fn($a);$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function periods():array
    {
        $a=$this->access();return $this->q('SELECT id,starts_on,ends_on FROM balance_periods WHERE club_id=? ORDER BY starts_on',[$a['club_id']])->fetchAll();
    }
    private static function digest(string $club,string $id,int $revision,array $payload):string
    {
        return hash('sha256',self::json(['balance-period-event-v1',$club,$id,$revision,$payload]));
    }
    private function append(array $actor,string $id,int $version,?string $previous,string $kind,array $data):array
    {
        // Retained partial originals never acquire a new accounting policy implicitly.
        $eventPeriod=[
            'start'=>substr($id,0,4).'-'.substr($id,4,2).'-'.substr($id,6,2),
            'end'=>substr($id,9,4).'-'.substr($id,13,2).'-'.substr($id,15,2),
        ];
        if(getenv('VEREIN_MODE')==='production'&&!BalancePeriod::isFullMonths($eventPeriod)){
            $definition=$kind==='created'?($data['definition']??[]):json_decode((string)$this->q('SELECT definition FROM balance_periods WHERE club_id=? AND id=?',[$actor['club_id'],$id])->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            if(BalancePeriodDayAllocation::policy($definition)===null)BalancePeriod::fullMonths($eventPeriod);
        }
        if($version>=20000)throw new DomainException('Zu viele Änderungen in diesem Zeitraum. Betrieb prüfen.');
        $payload=['previous_sha256'=>$previous,'kind'=>$kind,'data'=>$data,'actor'=>$actor['id'],'at'=>gmdate('c')];
        $hash=self::digest($actor['club_id'],$id,$version+1,$payload);
        $this->q('INSERT INTO balance_period_events(club_id,period_id,revision,payload,sha256) VALUES(?,?,?,?,?)',[$actor['club_id'],$id,$version+1,self::json($payload),$hash]);
        $this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$actor['club_id'],$actor['id'],'finance.balance.period.'.$kind,$id,$version+1,$payload['at']]);
        return ['revision'=>$version+1,'payload'=>$payload,'sha256'=>$hash];
    }
    /** A predecessor is explicit, including the exact immutable legacy closing at the boundary. */
    public function create(array $period,string $evidence,bool $reviewed,bool $dayAllocationReviewed=false,?array $expectation=null):string
    {
        $period=BalancePeriod::check($period);$id=BalancePeriod::key($period);$year=(int)substr($period['end'],0,4);BalanceCalculation::year($year);
        $evidence=self::note($evidence);if(!$reviewed)throw new DomainException('Beginn, Ende und Nachweis für den Abschlusszeitraum zuerst prüfen.');
        return $this->transaction(function($a)use($period,$id,$year,$evidence,$dayAllocationReviewed,$expectation){
            $existing=$this->q('SELECT definition FROM balance_periods WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetchColumn();
            if($existing!==false){
                $definition=json_decode($existing,true,512,JSON_THROW_ON_ERROR);$head=$this->q('SELECT sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=1',[$a['club_id'],$id])->fetchColumn();
                if($head===false)throw new DomainException('Prüfverlauf des Abschlusszeitraums beschädigt.');
                if($expectation!==null&&$expectation!==['previous'=>$definition['previous']])throw new DomainException('Geprüfter Buchungsstand geändert. Erneut prüfen.');
                $first=$this->originalEvent($id,1,$head);$policy=BalancePeriodDayAllocation::policy($definition);
                if($first['payload']['kind']==='created'&&$first['payload']['actor']===$a['id']&&$first['payload']['data']['definition']===$definition&&$definition['period']===$period&&$definition['evidence']===$evidence&&(BalancePeriod::isFullMonths($period)||($dayAllocationReviewed&&$policy!==null)))return $id;
                throw new DomainException('Dieser Abschlusszeitraum ist bereits vorhanden. Ursprünglichen Vorgang prüfen.');
            }
            $last=$this->q('SELECT id FROM balance_periods WHERE club_id=? ORDER BY ends_on DESC LIMIT 1',[$a['club_id']])->fetchColumn();
            $legacy=$this->q('SELECT tax_year FROM balance_years WHERE club_id=? ORDER BY tax_year DESC LIMIT 1',[$a['club_id']])->fetchColumn();
            $prior=null;$ref=null;
            if($last!==false){$prior=$this->report((string)$last);$ref=['kind'=>'period','id'=>(string)$last,'sha256'=>$prior['closed_sha256']];}
            elseif($legacy!==false){$prior=(new BalanceLedger($this->db,$this->actorId))->report((int)$legacy);$prior['period']=BalancePeriod::calendar((int)$legacy);$ref=['kind'=>'calendar','id'=>(string)$legacy,'sha256'=>$prior['closed_sha256']];}
            if($prior&&($prior['state']!=='closed'||$prior['issues']||!BalancePeriod::follows($prior['period'],$period)))throw new DomainException('Der neue Zeitraum muss unmittelbar auf den geprüften, festgeschriebenen Vorzeitraum folgen.');
            if($expectation!==null&&$expectation!==['previous'=>$ref])throw new DomainException('Geprüfter Buchungsstand geändert. Erneut prüfen.');
            $accounts=$prior?$prior['accounts']:BalanceLedger::defaultChart()+(new BalanceSources($this->db,$this->actorId))->bankAccounts();
            $result=$prior?$prior['result_account']:'equity';
            $opening=$prior?BalanceCalculation::carryForward($prior['calculation'],$result):[];
            $definition=['period'=>$period,'year'=>$year,'previous'=>$ref,'evidence'=>$evidence];
            $inherited=$prior===null?null:BalancePeriodDayAllocation::policy($prior['definition']??[]);
            if($inherited!==null||(!BalancePeriod::isFullMonths($period)&&$dayAllocationReviewed))$definition['day_allocation']=['policy'=>BalancePeriodDayAllocation::POLICY,'scope'=>'internal_books','reviewed'=>true];
            $this->q('INSERT INTO balance_periods(club_id,id,starts_on,ends_on,definition) VALUES(?,?,?,?,?)',[$a['club_id'],$id,$period['start'],$period['end'],self::json($definition)]);
            $this->append($a,$id,0,null,'created',['definition'=>$definition,'accounts'=>BalanceCalculation::chart($accounts),'opening'=>$opening,'result_account'=>$result]);
            return $id;
        });
    }
    private function editable(string $id,int $version,array $states):array
    {
        $r=$this->report($id);if($r['version']!==$version)throw new DomainException('Buchungsstand inzwischen geändert. Bitte neu laden.');
        if(!in_array($r['state'],$states,true))throw new DomainException('Dieser Buchungsstand kann nicht mehr geändert werden.');
        if($r['issues'])throw new DomainException(implode(' ',$r['issues']));return $r;
    }
    public function setup(string $id,int $version,array $opening,string $evidence,bool $reviewed,?string $expectedJournal=null):array
    {
        return $this->journalDecision($id,$version,['setup'],'opened',static function($r)use($opening,$evidence,$reviewed){
            if(!$reviewed)throw new DomainException('Anfangsbestände ausdrücklich prüfen, auch bei null Euro.');
            $opening=BalanceCalculation::lines($opening,$r['accounts'],true);$evidence=self::note($evidence);
            DatevComparisonCatalog::assertPosting($r['year'],$r['accounts'],$opening);$calc=BalanceCalculation::build($r['year'],$r['accounts'],$opening,[],$r['period']);if($calc['issues'])throw new DomainException(implode(' ',$calc['issues']));
            return ['opening'=>$opening,'evidence'=>$evidence];
        },$expectedJournal);
    }
    public function addAccount(string $id,int $version,string $accountId,string $name,string $group,string $code='',?string $expectedJournal=null):array
    {
        return $this->journalDecision($id,$version,['setup','open'],'account',static function($r)use($accountId,$name,$group,$code){
            if(isset($r['accounts'][$accountId]))throw new DomainException('Dieses Konto ist bereits vorhanden.');
            if($code!==''){$choice=BalanceTaxMapping::chartChoices($r['year'])[$code]??null;if(!$choice)throw new DomainException('Buchbares SKR42-Konto des Abschlussjahres auswählen.');if($choice['group']!==null)$group=$choice['group'];if(trim($name)==='')$name=$choice['name'];}
            $new=BalanceCalculation::chart([$accountId=>['name'=>$name,'group'=>$group,'skr_code'=>$code]]);BalanceCalculation::chart($r['accounts']+$new);return ['accounts'=>$new];
        },$expectedJournal);
    }
    public function post(string $id,int $version,string $requestKey,string $date,string $description,string $evidence,array $lines,?string $reverseOf=null,?string $expectedJournal=null):array
    {
        return $this->journalDecision($id,$version,['open'],'posted',static function($r)use($requestKey,$date,$description,$evidence,$lines,$reverseOf){
            if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey)||isset($r['entries'][$requestKey]))throw new DomainException('Buchung bereits gespeichert oder Kennung ungültig. Bitte neu laden.');
            if(!BalancePeriod::contains($r['period'],$date))throw new DomainException('Buchungsdatum muss im gewählten Abschlusszeitraum liegen.');
            $description=self::note($description);$evidence=self::note($evidence);
            if($reverseOf!==null){
                foreach($r['sources']as$source)if(in_array($reverseOf,$source['entry_ids'],true))throw new DomainException('Diese Buchung stammt aus einem Beleg. Die Belegübernahme berichtigen.');
                $original=$r['entries'][$reverseOf]??null;if(!$original||$original['reversal_of']!==null||$date<$original['date']||in_array($reverseOf,array_column($r['entries'],'reversal_of'),true))throw new DomainException('Ursprüngliche, noch nicht stornierte Buchung und Stornodatum prüfen.');
                if($lines)throw new DomainException('Stornobeträge werden aus der ursprünglichen Buchung übernommen.');
                foreach($original['lines']as$line)$lines[]=['account'=>$line['account'],'debit_cents'=>$line['credit_cents'],'credit_cents'=>$line['debit_cents'],'sphere'=>$line['sphere']];
            }
            $lines=BalanceCalculation::lines($lines,$r['accounts']);if($reverseOf===null)DatevComparisonCatalog::assertPosting($r['year'],$r['accounts'],$lines);
            $entry=['id'=>$requestKey,'date'=>$date,'description'=>$description,'evidence'=>$evidence,'lines'=>$lines,'reversal_of'=>$reverseOf];BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],[...array_values($r['entries']),$entry],$r['period']);return ['entry'=>$entry];
        },$expectedJournal);
    }
    public function close(string $id,int $version,string $expectedHash,array $checks,string $evidence):array
    {
        return $this->journalDecision($id,$version,['open'],'closed',static function($r)use($checks,$evidence){
            if(array_keys($checks)!==['complete','valuation','cutoff','tax']||in_array(false,$checks,true)||count(array_filter($checks,static fn($v)=>$v===true))!==4)throw new DomainException('Vollständigkeit, Bewertung, Zeitraum und Steuerzuordnung ausdrücklich prüfen.');
            return ['checks'=>$checks,'evidence'=>self::note($evidence)];
        },$expectedHash,true,function($r){
            $sources=new BalancePeriodSources($this->db,$this->actorId);$missing=[...$sources->missing($r['period']),...$sources->bankIssues($r['period'],$r['calculation'])];if($missing)throw new DomainException(implode(' ',$missing));
            $assetReview=(new BalancePeriodDepreciation($this->db,$this->actorId))->review($r);if($assetReview['issues'])throw new DomainException(implode(' ',$assetReview['issues']));
            if($r['period']['end']>=date('Y-m-d'))throw new DomainException('Der Abschlusszeitraum ist noch nicht beendet.');if($r['calculation']['issues'])throw new DomainException(implode(' ',$r['calculation']['issues']));
        });
    }
    /** New board authority, same accounting figures and same immutable closing identity. */
    public function reaffirm(string $id,int $version,string $closedHash,bool $reviewed,string $evidence):array
    {
        return $this->transaction(function($a)use($id,$version,$closedHash,$reviewed,$evidence){
            BoardAccess::require($this->db,$this->actorId);$r=$this->report($id);$evidence=self::note($evidence);
            $first=$this->repeatCorrection($a,$r,$version,$closedHash,'reaffirmed',static fn($e,$before):bool=>$reviewed&&$e['payload']['data']['evidence']===$evidence&&$e['payload']['data']['closed_sha256']===$closedHash);if($first!==null)return $first;
            if($r['state']!=='closed'||$r['version']!==$version||!$reviewed||!hash_equals($r['closed_sha256'],$closedHash))throw new DomainException('Unveränderten Abschluss und neuen Prüfvermerk ausdrücklich bestätigen.');
            if($r['predecessor_issues']||$r['source_issues']||$r['calculation']['issues'])throw new DomainException('Vorzeitraum, Belege und Buchungswerte zuerst abstimmen.');
            return $this->append($a,$id,$version,$r['journal_sha256'],'reaffirmed',['closed_sha256'=>$closedHash,'evidence'=>self::note($evidence)]);
        });
    }
    /** Source amounts come only from a fresh server-side proposal, never from the form. */
    public function importSource(string $id,int $version,string $type,string $sourceId,array $options,string $expectedHash,bool $reviewed,?string $expectedJournal=null):array
    {
        return $this->saveSource($id,$version,$type,$sourceId,$options,$expectedHash,$reviewed,null,'',$expectedJournal);
    }
    public function correctSource(string $id,int $version,string $type,string $sourceId,array $options,string $expectedHash,bool $reviewed,string $previousHash,string $reason,?string $expectedJournal=null):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$previousHash))throw new DomainException('Ursprüngliche Belegübernahme erneut laden.');
        return $this->saveSource($id,$version,$type,$sourceId,$options,$expectedHash,$reviewed,$previousHash,self::note($reason),$expectedJournal);
    }
    private function saveSource(string $id,int $version,string $type,string $sourceId,array $options,string $expectedHash,bool $reviewed,?string $previousHash,string $reason,?string $expectedJournal):array
    {
        self::id($id);BalancePeriodSources::identity($type,$sourceId);
        if($version<1||$version>=20000||!preg_match('/\A[a-f0-9]{64}\z/D',$expectedHash)||($expectedJournal!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$expectedJournal)))throw new DomainException('Ursprüngliche Angaben zum Abschlusszeitraum vollständig prüfen.',422);
        if(!$reviewed)throw new DomainException('Beleg und vorgeschlagene Buchung zuerst prüfen.');
        return $this->transaction(function($a)use($id,$version,$type,$sourceId,$options,$expectedHash,$reviewed,$previousHash,$reason,$expectedJournal){
            // Authenticate the whole stored chain without treating a historical
            // source result as a new approval of its current financial source.
            $whole=$this->reportUncached($id,0,false,null,false,true);
            if($version>$whole['version'])throw new DomainException('Buchungsstand inzwischen geändert. Bitte neu laden.',409);
            $before=$version===$whole['version']?$whole:$this->reportUncached($id,0,false,$version,false,true);
            if($expectedJournal!==null&&!hash_equals($before['journal_sha256'],$expectedJournal))throw new DomainException('Geprüfter Buchungsstand geändert. Erneut prüfen.',409);
            if($before['state']!=='open')throw new DomainException('Offenen Buchungsstand und Vorzeitraum erneut prüfen.');
            if($version!==$whole['version']){
                $first=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=? AND revision=?',[$a['club_id'],$id,$version+1])->fetch();
                if($first){
                    $first['revision']=(int)$first['revision'];$first['payload']=json_decode($first['payload'],true,512,JSON_THROW_ON_ERROR);$event=$first['payload'];$data=$event['data'];$proposal=$data['proposal']??[];
                    if($event['actor']===$a['id']&&$event['kind']===($previousHash===null?'source_imported':'source_corrected')&&$event['previous_sha256']===$before['journal_sha256']&&($proposal['type']??null)===$type&&($proposal['id']??null)===$sourceId&&($proposal['options']??null)===$options&&($proposal['hash']??null)===$expectedHash&&($data['previous_source_sha256']??null)===$previousHash&&($data['reason']??null)===$reason)return $first;
                }
                throw new DomainException('Buchungsstand inzwischen geändert. Bitte neu laden.',409);
            }
            $r=$this->reportUncached($id,0,true);$sources=new BalancePeriodSources($this->db,$this->actorId);
            if($r['state']!=='open'||$r['version']!==$version||$r['predecessor_issues'])throw new DomainException('Offenen Buchungsstand und Vorzeitraum erneut prüfen.');
            if(!$reviewed)throw new DomainException('Beleg und vorgeschlagene Buchung zuerst prüfen.');
            if($type==='depreciation'&&$r['period']['end']>=date('Y-m-d'))throw new DomainException('Abschreibung erst nach Ende dieses Abschlusszeitraums bestätigen.');
            $p=$sources->preview($r['period'],$type,$sourceId,$options,true);
            if($p['issues'])throw new DomainException(implode(' ',$p['issues']));
            if(!hash_equals($p['hash'],$expectedHash))throw new DomainException('Beleg oder Eingaben geändert. Vorschau erneut prüfen.');
            if($this->q('SELECT 1 FROM balance_sources WHERE club_id=? AND source_type=? AND source_id=?',[$a['club_id'],$type,$sourceId])->fetchColumn())throw new DomainException('Beleg ist bereits im Kalenderjournal gebucht. Übergang zuerst abstimmen.');
            $key=$type.':'.$sourceId;$old=$r['sources'][$key]??null;$owner=$sources->owner($type,$sourceId);
            if($previousHash===null){
                if($owner||$old)throw new DomainException('Beleg bereits übernommen. Bei Änderungen die Belegübernahme berichtigen.');
            }elseif(!$old||!$owner||$owner['period_id']!==$id||!hash_equals($old['proposal']['hash'],$previousHash)||hash_equals($previousHash,$p['hash']))throw new DomainException('Geänderte Belegübernahme und ursprünglichen Prüfstand erneut prüfen.');
            $accounts=self::sourceAccounts($r['accounts'],$p['accounts']);$entries=[];
            if($old)foreach($old['entry_ids'] as $entryId){
                $original=$r['entries'][$entryId];$lines=[];
                foreach($original['lines'] as $line)$lines[]=['account'=>$line['account'],'debit_cents'=>$line['credit_cents'],'credit_cents'=>$line['debit_cents'],'sphere'=>$line['sphere']];
                $entries[]=['id'=>bin2hex(random_bytes(16)),'date'=>$original['date'],'description'=>'Berichtigung: '.$original['description'],'evidence'=>$reason,'lines'=>$lines,'reversal_of'=>$entryId];
            }
            foreach($p['vouchers'] as $voucher)$entries[]=['id'=>bin2hex(random_bytes(16))]+$voucher+['evidence'=>$p['evidence'],'reversal_of'=>null];
            foreach($entries as $entry)if($entry['reversal_of']===null)DatevComparisonCatalog::assertPosting($r['year'],$accounts,$entry['lines']);
            BalanceCalculation::build($r['year'],$accounts,$r['opening'],[...array_values($r['entries']),...$entries],$r['period']);
            $accepted=$this->append($a,$id,$version,$r['journal_sha256'],$old?'source_corrected':'source_imported',['proposal'=>$p,'entries'=>$entries,'previous_source_sha256'=>$previousHash,'reason'=>$reason,'original_source'=>$this->sourceOriginalLabel($a['club_id'],$p)]);
            if(!$old)$this->q('INSERT INTO balance_period_sources(club_id,source_type,source_id,period_id,event_revision) VALUES(?,?,?,?,?)',[$a['club_id'],$type,$sourceId,$id,$version+1]);
            if($type==='depreciation'&&($p['snapshot']['disposal']['kind']??null)==='sale'&&BalancePeriod::contains($r['period'],$p['snapshot']['disposal']['date'])){
                $item=$p['snapshot']['item_id'];
                if(!$this->q('SELECT 1 FROM balance_period_asset_sales WHERE club_id=? AND item_id=?',[$a['club_id'],$item])->fetchColumn())
                    $this->q('INSERT INTO balance_period_asset_sales(club_id,item_id,entry_id,period_id,event_revision) VALUES(?,?,?,?,?)',[$a['club_id'],$item,$p['snapshot']['disposal']['sale_entry'],$id,$old['first_revision']??$version+1]);
            }
            return $accepted;
        });
    }
    /** Human labels belong to the first decision, not to a later live lookup. */
    private function sourceOriginalLabel(string $club,array $proposal):array
    {
        $type=$proposal['type'];$id=$proposal['id'];$description='';$counterparty='';$account='';
        if($type==='invoice'){$entry=$proposal['snapshot']['entry'];$description=(string)$entry['description'];}
        elseif($type==='depreciation')$description=(string)$proposal['snapshot']['name'];
        else{
            $row=$this->q('SELECT t.description,t.counterparty,a.name account_name FROM finance_transactions t JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE t.club_id=? AND t.id=? FOR SHARE NOWAIT',[$club,$id])->fetch();
            if(!$row)throw new DomainException('Ursprünglichen eigenen Kontoumsatz prüfen.');
            $description=(string)$row['description'];$counterparty=(string)$row['counterparty'];$account=(string)$row['account_name'];
        }
        return ['type'=>$type,'id'=>$id,'description'=>$description,'counterparty'=>$counterparty,'account_name'=>$account];
    }
    private static function sourceAccounts(array $current,array $new):array
    {
        $new=BalanceCalculation::chart($new);
        foreach($new as $id=>$account)if(isset($current[$id])&&$current[$id]!==$account)throw new DomainException('Vorhandenes Buchungskonto stimmt nicht mit dem Beleg überein.');
        return BalanceCalculation::chart($current+$new);
    }
    /** Replay validates event shape as well as hashes: an anchor never replaces original evidence. */
    private static function replaySource(array &$r,array $data,int $revision,bool $correction):void
    {
        $p=$data['proposal'];BalancePeriodSources::identity($p['type'],$p['id']);$key=$p['type'].':'.$p['id'];$old=$r['sources'][$key]??null;
        if(array_key_exists('original_source',$data)){$facts=$data['original_source'];if(!is_array($facts)||array_keys($facts)!==['type','id','description','counterparty','account_name']||count(array_filter($facts,'is_string'))!==5||$facts['type']!==$p['type']||$facts['id']!==$p['id'])throw new DomainException('Ursprüngliche Quellenangaben im Buchungsverlauf prüfen.');}
        if($p['period']!==$r['period']||$p['year']!==$r['year']||$p['issues']||(!$p['vouchers']&&!BalancePeriodSources::nonPosting($p))||!hash_equals($p['hash'],BalancePeriodSources::hash($p))||($correction!==($old!==null))||$data['previous_source_sha256']!==($old['proposal']['hash']??null))throw new DomainException('Belegnachweis im Buchungsverlauf beschädigt.');
        $r['accounts']=self::sourceAccounts($r['accounts'],$p['accounts']);$expected=[];
        if($old)foreach($old['entry_ids'] as $entryId){
            $original=$r['entries'][$entryId];$lines=[];
            foreach($original['lines'] as $line)$lines[]=['account'=>$line['account'],'debit_cents'=>$line['credit_cents'],'credit_cents'=>$line['debit_cents'],'sphere'=>$line['sphere']];
            $expected[]=['date'=>$original['date'],'description'=>'Berichtigung: '.$original['description'],'evidence'=>self::note($data['reason']),'lines'=>$lines,'reversal_of'=>$entryId];
        }
        foreach($p['vouchers'] as $voucher)$expected[]=$voucher+['evidence'=>$p['evidence'],'reversal_of'=>null];
        if(count($data['entries'])!==count($expected))throw new DomainException('Unvollständige Belegbuchung im Prüfverlauf.');
        $ids=[];
        foreach($data['entries'] as $i=>$entry){
            $entryId=$entry['id'];unset($entry['id']);
            if(!preg_match('/^[a-f0-9]{32}$/D',$entryId)||isset($r['entries'][$entryId])||$entry!=$expected[$i])throw new DomainException('Beleg und Buchungszeilen stimmen nicht überein.');
            $r['entries'][$entryId]=['id'=>$entryId]+$entry;
            if($entry['reversal_of']===null)$ids[]=$entryId;
        }
        $r['sources'][$key]=['proposal'=>$p,'entry_ids'=>$ids,'first_revision'=>$old['first_revision']??$revision];
    }
    public function report(string $id,int $depth=0,bool $reviewSources=true):array
    {
        if($depth>100)throw new DomainException('Verkettung der Abschlusszeiträume prüfen.');
        require_once __DIR__.'/BalanceReadSnapshot.php';
        return BalanceReadSnapshot::remember($this->db,__METHOD__,[$this->actorId,$id,$reviewSources],fn()=>$this->reportUncached($id,$depth,$reviewSources));
    }
    private function reportUncached(string $id,int $depth,bool $reviewSources,?int $throughRevision=null,bool $originalSources=false,bool $historicalContext=false):array
    {
        self::id($id);if($depth>100)throw new DomainException('Verkettung der Abschlusszeiträume prüfen.');$a=$this->access();
        $row=$this->q('SELECT * FROM balance_periods WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$row)throw new DomainException('Abschlusszeitraum nicht vorhanden.');
        $d=json_decode($row['definition'],true,512,JSON_THROW_ON_ERROR);$period=BalancePeriod::check($d['period'],$d['year']);
        BalancePeriodDayAllocation::policy($d);
        if($id!==BalancePeriod::key($period)||$period['start']!==$row['starts_on']||$period['end']!==$row['ends_on'])throw new DomainException('Gespeicherten Abschlusszeitraum prüfen.');
        $events=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=?'.($throughRevision===null?'':' AND revision<=?').' ORDER BY revision',$throughRevision===null?[$a['club_id'],$id]:[$a['club_id'],$id,$throughRevision])->fetchAll();
        $r=['id'=>$id,'year'=>$d['year'],'period'=>$period,'previous'=>$d['previous'],'definition'=>$d,'state'=>'missing','version'=>0,'accounts'=>[],'opening'=>[],'entries'=>[],'sources'=>[],'result_account'=>'equity','journal_sha256'=>null,'closed_sha256'=>null,'issues'=>[],'correction_draft'=>null];
        foreach($events as $event){
            $p=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);$seq=(int)$event['revision'];
            if($seq!==$r['version']+1||$p['previous_sha256']!==$r['journal_sha256']||!hash_equals($event['sha256'],self::digest($a['club_id'],$id,$seq,$p)))throw new DomainException('Prüfverlauf des Abschlusszeitraums beschädigt.');
            $v=$p['data'];$kind=$p['kind'];
            if(self::replayPeriodCorrection($r,$event,$p)){
                $r['version']=$seq;$r['journal_sha256']=$event['sha256'];continue;
            }
            if($r['state']==='closed'&&$kind==='reaffirmed'&&$v['closed_sha256']===$r['closed_sha256']){
                $r['closing_review']=['actor'=>$p['actor'],'at'=>$p['at'],'checks'=>$r['closing_review']['checks'],'evidence'=>$v['evidence']];
                $r['version']=$seq;$r['journal_sha256']=$event['sha256'];continue;
            }
            if($r['state']==='closed')throw new DomainException('Änderung nach festgeschriebenem Buchungsstand gefunden.');
            if($kind==='created'&&$seq===1){
                if($v['definition']!==$d)throw new DomainException('Zeitraumnachweis widerspricht dem Prüfverlauf.');
                $r['accounts']=BalanceCalculation::chart($v['accounts']);$r['opening']=BalanceCalculation::lines($v['opening'],$r['accounts'],true);$r['result_account']=$v['result_account'];
                if(($r['accounts'][$r['result_account']]['group']??null)!=='equity')throw new DomainException('Ergebniskonto prüfen.');
                $r['state']=$d['previous']===null?'setup':'open';
            }elseif($kind==='opened'&&$r['state']==='setup'){$r['opening']=BalanceCalculation::lines($v['opening'],$r['accounts'],true);$r['state']='open';}
            elseif($kind==='account'&&in_array($r['state'],['setup','open'],true)){
                if(array_intersect_key($r['accounts'],$v['accounts']))throw new DomainException('Doppeltes Bilanzkonto im Prüfverlauf.');$r['accounts']=BalanceCalculation::chart($r['accounts']+$v['accounts']);
            }elseif($kind==='posted'&&$r['state']==='open'){
                $entry=$v['entry'];if(isset($r['entries'][$entry['id']]))throw new DomainException('Doppelte Buchung im Prüfverlauf.');$r['entries'][$entry['id']]=$entry;
            }elseif(in_array($kind,['source_imported','source_corrected'],true)&&$r['state']==='open'){self::replaySource($r,$v,$seq,$kind==='source_corrected');}
            elseif($kind==='closed'&&$r['state']==='open'){$r['state']='closed';$r['closed_sha256']=$event['sha256'];$r['closing_review']=['actor'=>$p['actor'],'at'=>$p['at']]+$v;}
            else throw new DomainException('Unzulässiger Schritt im Prüfverlauf.');
            $r['version']=$seq;$r['journal_sha256']=$event['sha256'];
        }
        if(!$r['version'])throw new DomainException('Buchungsstand fehlt.');
        $r['calculation']=BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],array_values($r['entries']),$period);
        if($r['previous']!==null){
            $ref=$r['previous'];
            if($ref['kind']==='period')$prior=($originalSources||$historicalContext)?$this->originalSource($ref['id'],$ref['sha256'],$depth+1):$this->report($ref['id'],$depth+1,$reviewSources);
            elseif($ref['kind']==='calendar'){$prior=(($originalSources||$historicalContext)?(new BalanceLedger($this->db,$this->actorId))->archivedReport((int)$ref['id'],$ref['sha256']):(new BalanceLedger($this->db,$this->actorId))->report((int)$ref['id']));$prior['period']=BalancePeriod::calendar((int)$ref['id']);}
            else throw new DomainException('Vorzeitraum prüfen.');
            if($prior['state']!=='closed'||$prior['issues']||$prior['closed_sha256']!==$ref['sha256']||!BalancePeriod::follows($prior['period'],$period)||BalanceCalculation::carryForward($prior['calculation'],$r['result_account'])!==$r['opening'])$r['issues'][]='Vorzeitraum wurde geändert oder ist nicht mehr freigegeben. Anfangsbestände erneut abstimmen.';
        }
        $r['predecessor_issues']=$r['issues'];
        $r['source_issues']=[];
        if($reviewSources){
            $sources=new BalancePeriodSources($this->db,$this->actorId);
            foreach($r['sources'] as $key=>$source){
                $p=$source['proposal'];$fresh=$sources->preview($period,$p['type'],$p['id'],$p['options']);$owner=$sources->owner($p['type'],$p['id']);
                if($fresh['issues']||!hash_equals($p['hash'],$fresh['hash'])||!$owner||$owner['period_id']!==$id||(int)$owner['event_revision']!==$source['first_revision'])$r['source_issues'][]='Belegübernahme geändert oder unvollständig: '.$p['evidence'].'. Bitte berichtigen.';
            }
            $r['asset_schedule']=(new BalancePeriodDepreciation($this->db,$this->actorId))->review($r);
            if($r['state']==='closed')$r['source_issues']=[...$r['source_issues'],...$sources->missing($period),...$sources->bankIssues($period,$r['calculation']),...$r['asset_schedule']['issues']];
            $r['issues']=array_merge($r['issues'],$r['source_issues']);
        }
        if($originalSources){if($r['state']!=='closed'||$r['closed_sha256']!==$r['journal_sha256'])throw new DomainException('Ursprünglichen festgeschriebenen Buchungsstand auswählen.',404);$r['asset_schedule']=self::originalAssetSchedule($r);}
        $r['approval_current']=!$originalSources&&!$historicalContext&&$r['state']==='closed'&&BoardAccess::allowed($this->db,$r['closing_review']['actor']);
        if(!$originalSources&&!$historicalContext&&$r['state']==='closed'&&!$r['approval_current'])$r['issues'][]='Die Freigabeberechtigung für diesen Buchungsstand ist nicht mehr gültig.';
        return $r;
    }
    /** An actual stored closed event, with historical sources and no current approval. */
    public function originalSource(string $id,string $sha256,int $depth=0):array
    {
        if($depth>100)throw new DomainException('Verkettung der ursprünglichen Abschlusszeiträume prüfen.');
        require_once __DIR__.'/BalanceReadSnapshot.php';
        return BalanceReadSnapshot::remember($this->db,__METHOD__,[$this->actorId,$id,$sha256],fn()=>$this->originalSourceUncached($id,$sha256,$depth));
    }
    private function originalSourceUncached(string $id,string $sha256,int $depth):array
    {
        self::id($id);$a=$this->access();if($depth>100||!preg_match('/\A[a-f0-9]{64}\z/D',$sha256))throw new DomainException('Ursprünglichen Buchungsstand dieses Abschlusszeitraums vollständig auswählen.',404);
        $row=$this->q('SELECT revision FROM balance_period_events WHERE club_id=? AND period_id=? AND sha256=?',[$a['club_id'],$id,$sha256])->fetch();
        if(!$row||(int)$row['revision']<1||(int)$row['revision']>20000)throw new DomainException('Ursprünglicher Buchungsstand dieses Abschlusszeitraums nicht gefunden.',404);
        $r=$this->reportUncached($id,$depth,false,(int)$row['revision'],true);
        if($r['state']!=='closed'||$r['closed_sha256']!==$sha256||$r['issues']||$r['calculation']['issues'])throw new DomainException('Ursprünglicher festgeschriebener Buchungsstand dieses Abschlusszeitraums fehlt.',404);
        return $r;
    }
    /** Read-only reconstruction from actual replay-validated original source bindings.
     * Caller must select and verify the real original closed period event first.
     */
    private static function originalAssetSchedule(array $r):array
    {
        $items=[];$expected=[];
        foreach($r['sources']as$binding){
            $p=$binding['proposal'];if($p['type']!=='depreciation')continue;
            if($p['period']!==$r['period']||$p['year']!==$r['year']||$p['issues']||!hash_equals($p['hash'],BalancePeriodSources::hash($p)))throw new DomainException('Ursprünglichen Anlagenbezug dieses Abschlusszeitraums prüfen.');
            $snapshot=$p['snapshot'];$id=$snapshot['item_id']??null;
            if(!is_string($id)||$p['id']!==$id.':'.$r['id']||isset($items[$id])||!is_array($snapshot['plan']??null)||!is_array($snapshot['schedule']??null)||$snapshot['schedule']['period']!==$r['period']||!is_string($snapshot['fixed_account']??null)||!isset($r['accounts'][$snapshot['fixed_account']]))throw new DomainException('Ursprünglichen Anlagenstand dieses Abschlusszeitraums prüfen.');
            $item=['source_id'=>$p['id'],'source_sha256'=>$p['hash'],'posted'=>true]+$snapshot;
            if(($snapshot['disposal']['kind']??null)==='sale'&&BalancePeriod::contains($r['period'],$snapshot['disposal']['date']))$item['sale_posting']=BalancePeriodSale::postingEvidence($r,$item);
            $items[$id]=$item;$account=$snapshot['fixed_account'];$expected[$account]=($expected[$account]??0)+$snapshot['schedule']['book_closing_cents'];
        }
        // The live candidate list is ordered by inventory id; use only the retained ids.
        ksort($items,SORT_STRING);
        foreach($r['calculation']['accounts']as$key=>$account)if($account['group']==='fixed_assets'&&$account['closing_cents']!==($expected[$key]??0))throw new DomainException('Ursprüngliches Anlagenverzeichnis und Buchungsstand stimmen nicht überein.');
        $flat=[];
        foreach($items as$id=>$item)$flat[$id]=['method'=>$item['plan']['method']??'linear_monthly','account'=>$item['fixed_account'],'cost_plan'=>$item['plan'],'valuation'=>$item['valuation']??null,'valuation_accounts'=>$item['valuation_accounts']??[]]+$item['schedule'];
        BalanceCostAssetSources::checkMovements($r['calculation']['accounts'],$flat);BalanceCostAssetSources::checkFixed($r['calculation']['accounts'],$flat,$r['entries']);
        return ['period'=>$r['period'],'items'=>$items,'issues'=>[]];
    }
    /** Original event bytes are revalidated by report() before an authenticated export. */
    public function history(string $id):array
    {
        $this->report($id);$a=$this->access();$events=$this->q('SELECT revision,payload,sha256 FROM balance_period_events WHERE club_id=? AND period_id=? ORDER BY revision',[$a['club_id'],$id])->fetchAll();
        foreach($events as &$event){$event['revision']=(int)$event['revision'];$event['payload']=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);}unset($event);return $events;
    }
}
