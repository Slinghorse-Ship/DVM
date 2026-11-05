<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Local bookkeeping only. This service has no bank connection or payment submission. */
final class Finance
{
    use PaymentMatching;
    use PaymentReversals;
    use PaymentGroupReversals;
    use PaymentReturns;
    use PartialPaymentReturns;
    use ReimbursementPaymentReturns;
    use NativeDebitPaymentReturns;
    use NativeDebitReturnCorrections;
    use TransferSettlements;
    use PaymentReturnOrderLinks;
    use InternalMovements;
    use ExcessPaymentReturns;
    use BankCatalogue;
    use AutomaticMatching;
    use DonationContacts;
    use GoodsDonationCorrections;
    use Accounting;
    public const KINDS=['income'=>'Einnahme','expense'=>'Ausgabe','donation'=>'Geldspende','contribution'=>'Beitrag'];
    public const CATEGORIES=['general'=>'Allgemein','membership'=>'Mitgliedsbeiträge','culture'=>'Kulturveranstaltungen','donations'=>'Spenden','rooms'=>'Räume & Betrieb','office'=>'Verwaltung','gema'=>'GEMA / Musikrechte','artist'=>'Künstlerhonorare & Bands','inventory'=>'Inventaranschaffungen','insurance'=>'Versicherungen','postage'=>'Porto & Versand'];
    public function __construct(private PDO $db,private string $actorId,private ?\DateTimeImmutable $clock=null){}
    /** German business dates are independent of the hosting server timezone; audit instants remain UTC. */
    public static function today(?\DateTimeImmutable $clock=null):string
    {
        return ($clock??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }
    private function businessDay():string{return self::today($this->clock);}
    public function hasRecordedExpense():bool{$a=$this->actor();$q=$this->db->prepare("SELECT 1 FROM finance_entries WHERE club_id=? AND kind='expense' AND status!='cancelled' LIMIT 1");$q->execute([$a['club_id']]);return (bool)$q->fetchColumn();}

    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Keine ausreichende Finanzberechtigung.');
        return $a;
    }
    public function canRead():bool{try{$this->actor();return true;}catch(DomainException){return false;}}
    public function canWrite():bool{try{$this->actor(true);return true;}catch(DomainException){return false;}}
    /** Names only, for the authenticated finance writer's transaction entry. No implicit member assignment. */
    public function counterparties():array
    {
        $a=$this->actor(true);$q=$this->db->prepare('SELECT c.name,EXISTS(SELECT 1 FROM memberships m WHERE m.club_id=c.club_id AND m.contact_id=c.id) AS is_member FROM contacts c WHERE c.club_id=? AND c.archived_at IS NULL ORDER BY c.name,c.id');$q->execute([$a['club_id']]);return $q->fetchAll();
    }
    public static function cents(string $value):int
    {
        $value=trim($value);
        // No floating point, exponents, thousands separators or silent rounding.
        if(!preg_match('/^([0-9]{1,10})(?:[,.]([0-9]{1,2}))?$/D',$value,$m))throw new DomainException('Betrag ohne Tausendertrennzeichen mit höchstens zwei Nachkommastellen eingeben.');
        $cents=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
        if($cents>100000000000)throw new DomainException('Betrag ist zu groß.');
        return $cents;
    }
    /** Only an actual bank opening may carry debt; ordinary payment amounts stay unsigned. */
    private static function openingCents(string $value,string $kind):int
    {
        $value=trim($value);$negative=str_starts_with($value,'-');$cents=self::cents($negative?substr($value,1):$value);
        if($negative&&$cents>0&&$kind==='cash')throw new DomainException('Eine Handkasse kann keinen negativen Anfangsbestand haben.');
        return $negative?-$cents:$cents;
    }
    private static function date(string $value):void
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Ungültiges Datum.');
    }
    private static function field(array $data,string $key,int $max,bool $required=false):string
    {
        $v=isset($data[$key])&&is_scalar($data[$key])?trim((string)$data[$key]):'';
        if(!mb_check_encoding($v,'UTF-8')||mb_strlen($v,'UTF-8')>$max||($required&&$v===''))throw new DomainException('Bitte '.$key.' ausfüllen und die Feldlänge beachten.');
        return $v;
    }
    private function transaction(callable $fn):mixed
    {
        $this->actor(true);
        $this->db->beginTransaction();
        try{$result=$fn();$this->db->commit();return $result;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function audit(array $a,string $action,string $id,int $version):void
    {
        if(str_starts_with($action,'donation.goods.'))DocumentCatalog::register($this->db,$a['club_id'],'goods',$id);
        elseif(str_starts_with($action,'finance.entry.')||str_starts_with($action,'finance.payment.'))DocumentCatalog::register($this->db,$a['club_id'],'entry',$id);
        elseif($action==='finance.account.created')DocumentCatalog::register($this->db,$a['club_id'],'account',$id);
        $this->db->prepare('INSERT INTO audit_events (club_id,actor_id,action,object_id,object_version,happened_at) VALUES (?,?,?,?,?,?)')->execute([$a['club_id'],$a['id'],$action,$id,$version,gmdate('Y-m-d\TH:i:s\Z')]);
    }
    public function createAccount(string $name,string $kind,string $opening,string $date):string
    {
        return $this->transaction(function()use($name,$kind,$opening,$date){
            $a=$this->actor();$name=trim($name);self::date($date);$cents=self::openingCents($opening,$kind);
            if($date>$this->businessDay())throw new DomainException('Der Anfangsbestand darf nicht in der Zukunft liegen.');
            if($name===''||!mb_check_encoding($name,'UTF-8')||mb_strlen($name,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$name)||!in_array($kind,['cash','offline'],true))throw new DomainException('Kontoname oder Kontoart prüfen.');
            $q=$this->db->prepare('SELECT 1 FROM finance_accounts WHERE club_id=? AND name=?');$q->execute([$a['club_id'],$name]);if($q->fetchColumn())throw new DomainException('Kontoname bereits vergeben.');
            $id=bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO finance_accounts VALUES (?,?,?,?,?,?)')->execute([$id,$a['club_id'],$name,$kind,$cents,$date]);$this->audit($a,'finance.account.created',$id,1);return $id;
        });
    }
    public function account(string $id):array
    {
        $a=$this->actor();$q=$this->db->prepare('SELECT * FROM finance_accounts WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row)throw new DomainException('Konto nicht verfügbar.');return $row;
    }
    private function hasTable(string $name): bool
    {
        return Database::tableExists($this->db,$name);
    }
    /** Any existing use protects account type, opening balance and deletion. */
    public function accountDeletionInfo(string $id): array
    {
        $a=$this->actor();$this->account($id);$count=static function(PDO $db,string $sql,array $args):int{$q=$db->prepare($sql.($db->inTransaction()?' FOR UPDATE':''));$q->execute($args);return (int)$q->fetchColumn();};
        $transactions=$count($this->db,'SELECT COUNT(*) FROM finance_transactions WHERE club_id=? AND account_id=?',[$a['club_id'],$id]);
        $allocations=$count($this->db,'SELECT COUNT(*) FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id WHERE l.club_id=? AND t.account_id=?',[$a['club_id'],$id]);
        $entries=$count($this->db,'SELECT COUNT(*) FROM finance_entries WHERE club_id=? AND account_id=?',[$a['club_id'],$id]);
        $mappings=$this->hasTable('bank_account_mappings')?$count($this->db,'SELECT COUNT(*) FROM bank_account_mappings WHERE club_id=? AND account_id=?',[$a['club_id'],$id]):0;
        $linkedDocuments=$this->hasTable('document_links')?$count($this->db,"SELECT COUNT(*) FROM document_links WHERE club_id=? AND source_type='account' AND source_id=?",[$a['club_id'],$id]):0;
        $sepaOrders=$this->hasTable('sepa_orders')?$count($this->db,'SELECT COUNT(*) FROM sepa_orders WHERE club_id=? AND account_id=?',[$a['club_id'],$id]):0;
        $reversalChain=$count($this->db,'SELECT COUNT(*) FROM finance_transactions t WHERE t.club_id=? AND (t.account_id=? AND (t.reversal_of IS NOT NULL OR EXISTS(SELECT 1 FROM finance_transactions r WHERE r.club_id=t.club_id AND r.reversal_of=t.id)))',[$a['club_id'],$id]);
        $blockers=[];
        if($transactions>0)$blockers[]='Kontoumsätze vorhanden';
        if($mappings>0)$blockers[]='Bankverbindung vorhanden';
        if($allocations>0)$blockers[]='Belegzuordnungen vorhanden';
        if($entries>0)$blockers[]='bezahlte Belege verweisen auf dieses Konto';
        if($linkedDocuments>0)$blockers[]='Dokumente sind mit diesem Konto verknüpft';
        if($sepaOrders>0)$blockers[]='SEPA-Aufträge oder Rücklastschriften verwenden dieses Konto';
        if($reversalChain>0)$blockers[]='Korrektur- oder Gegenbuchungen vorhanden';
        foreach(['cash_closures'=>['account_id','Kassenabschluss vorhanden'],'finance_transfer_orders'=>['account_id','Überweisungsauftrag vorhanden'],'inventory_items'=>['cash_account','Handkasse im Inventar verknüpft']] as $table=>[$column,$label]){
            if($this->hasTable($table)&&$count($this->db,'SELECT COUNT(*) FROM '.$table.' WHERE club_id=? AND '.$column.'=?',[$a['club_id'],$id])>0)$blockers[]=$label;
        }
        // Also protect JSON references, including issued invoice snapshots; no JSON parsing of historical damaged payloads.
        foreach(['club_settings'=>'In den Vereinseinstellungen verwendet','outgoing_invoices'=>'In einer Ausgangsrechnung verwendet'] as $table=>$label){
            if($this->hasTable($table)&&$count($this->db,'SELECT COUNT(*) FROM '.$table.' WHERE club_id=? AND LOCATE(?,payload)>0',[$a['club_id'],json_encode($id,JSON_THROW_ON_ERROR)])>0)$blockers[]=$label;
        }
        return ['account_id'=>$id,'transaction_count'=>$transactions,'mapping_count'=>$mappings,'allocation_count'=>$allocations,'entry_count'=>$entries,'document_count'=>$linkedDocuments,'sepa_count'=>$sepaOrders,'blockers'=>$blockers];
    }
    /** Only an unused account can be removed. Never delete bank transactions or bank history. */
    public function deleteAccount(string $id,string $confirmName,?int $version=null): void
    {
        $this->transaction(function()use($id,$confirmName,$version){
            $a=$this->actor(true);$q=$this->db->prepare('SELECT * FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE');$q->execute([$a['club_id'],$id]);$account=$q->fetch();
            if(!$account)throw new DomainException('Konto nicht verfügbar.');
            if(!hash_equals((string)$account['name'],trim($confirmName)))throw new DomainException('Zur Sicherheit den Kontonamen exakt eingeben.');
            $current=(int)$this->accountSettings($id)['version'];if($version!==null&&$version!==$current)throw new DomainException('Kontoeinstellungen wurden inzwischen geändert. Bitte neu laden.');
            $info=$this->accountDeletionInfo($id);if($info['blockers'])throw new DomainException('Dieses Konto wird bereits verwendet und kann nur deaktiviert werden: '.implode('; ',$info['blockers']).'.');
            $this->audit($a,'finance.account.deleted',$id,$current+1);
            if($this->hasTable('finance_account_settings'))$this->db->prepare('DELETE FROM finance_account_settings WHERE club_id=? AND account_id=?')->execute([$a['club_id'],$id]);
            if($this->hasTable('document_sources'))$this->db->prepare("DELETE FROM document_sources WHERE club_id=? AND source_type='account' AND source_id=?")->execute([$a['club_id'],$id]);
            $this->db->prepare('DELETE FROM finance_accounts WHERE club_id=? AND id=?')->execute([$a['club_id'],$id]);
        });
    }
    /** Handkassen are a permanent bookkeeping option for every club. */
    public function cashEnabled():bool
    {
        $this->actor();return true;
    }
    public function paymentAccounts():array{return array_values(array_filter($this->accounts(),fn($a)=>$a['active']));}
    public function accountSettings(string $id):array
    {
        $a=$this->actor();$this->account($id);
        // Older isolated callers can inspect historical accounts before the additive migration.
        if(!Database::tableExists($this->db,'finance_account_settings'))return ['active'=>1,'tax_account'=>'','fints_read'=>1,'fints_write'=>0,'version'=>0];
        $columns=Database::columns($this->db,'finance_account_settings');
        $select='active,tax_account,version'.(in_array('fints_read',$columns,true)?',fints_read':'').(in_array('fints_write',$columns,true)?',fints_write':'');
        $q=$this->db->prepare('SELECT '.$select.' FROM finance_account_settings WHERE club_id=? AND account_id=?'.($this->db->inTransaction()?' FOR UPDATE':''));$q->execute([$a['club_id'],$id]);$row=$q->fetch()?:['active'=>1,'tax_account'=>'','version'=>0];return array_replace(['fints_read'=>1,'fints_write'=>0],$row);
    }
    public function requireActiveAccount(string $id):array
    {
        $account=$this->account($id);if(!(int)$this->accountSettings($id)['active'])throw new DomainException('Dieses Konto ist deaktiviert. Für neue Zahlungen ein aktives Konto wählen.');return $account;
    }
    public function saveAccountSettings(string $id,int $version,bool $active,string $taxAccount,bool $fintsRead=true,bool $fintsWrite=false,?string $name=null,?string $kind=null,?string $opening=null,?string $openedOn=null):void
    {
        $this->transaction(function()use($id,$version,$active,$taxAccount,$fintsRead,$fintsWrite,$name,$kind,$opening,$openedOn){$a=$this->actor(true);
            // Serialize settings and name changes, including the first settings row.
            $q=$this->db->prepare('SELECT * FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE');$q->execute([$a['club_id'],$id]);$account=$q->fetch();if(!$account)throw new DomainException('Konto nicht verfügbar.');$previousName=$account['name'];
            $old=$this->accountSettings($id);if((int)$old['version']!==$version)throw new DomainException('Kontoeinstellungen wurden inzwischen geändert. Bitte neu laden.');if($fintsWrite&&!$fintsRead)throw new DomainException('FinTS-Schreiben benötigt auch FinTS-Lesen.');
            if($name!==null){$name=trim($name);if($name===''||!mb_check_encoding($name,'UTF-8')||mb_strlen($name,'UTF-8')>150||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Eine Bezeichnung mit höchstens 150 Zeichen eingeben.');$q=$this->db->prepare('SELECT 1 FROM finance_accounts WHERE club_id=? AND name=? AND id<>?');$q->execute([$a['club_id'],$name,$id]);if($q->fetchColumn())throw new DomainException('Kontoname bereits vergeben.');}
            $kind??=$account['kind'];if(!in_array($kind,['offline','cash'],true))throw new DomainException('Bankkonto oder Handkasse wählen.');
            $cents=$opening===null?(int)$account['opening_cents']:self::openingCents($opening,$kind);$openedOn??=$account['opened_on'];self::date($openedOn);if($openedOn>$this->businessDay())throw new DomainException('Der Anfangsbestand darf nicht in der Zukunft liegen.');
            $structureChanged=$kind!==$account['kind']||$cents!==(int)$account['opening_cents']||$openedOn!==$account['opened_on'];
            if($kind==='cash'&&$cents<0&&$structureChanged)throw new DomainException('Eine Handkasse kann keinen negativen Anfangsbestand haben.');
            if($structureChanged){$info=$this->accountDeletionInfo($id);if($info['blockers'])throw new DomainException('Kontoart und Anfangsbestand sind geschützt: '.implode('; ',$info['blockers']).'.');}
            if($kind==='cash'){$fintsRead=false;$fintsWrite=false;}
            $taxAccount=trim($taxAccount);if($taxAccount!==''){$r=$this->taxAccounts()[$taxAccount]??null;if(!$r||!$r['active']||$r['category']!=='current'||preg_match('/AM|AV/',$r['function']??''))throw new DomainException('Ein aktives fünfstelliges Bank- oder Kassenkonto aus dem Kontenplan wählen.');}
            if($name!==null&&$name!==$previousName){$this->db->prepare('UPDATE finance_accounts SET name=? WHERE club_id=? AND id=?')->execute([$name,$a['club_id'],$id]);$this->audit($a,'finance.account.renamed',$id,$version+1);}
            if($structureChanged){$this->db->prepare('UPDATE finance_accounts SET kind=?,opening_cents=?,opened_on=? WHERE club_id=? AND id=?')->execute([$kind,$cents,$openedOn,$a['club_id'],$id]);$this->audit($a,'finance.account.opening-or-kind.changed',$id,$version+1);}
            if($structureChanged||($name!==null&&$name!==$previousName))DocumentCatalog::register($this->db,$a['club_id'],'account',$id);
            $this->db->prepare('INSERT INTO finance_account_settings(club_id,account_id,active,tax_account,fints_read,fints_write,version) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE active=VALUES(active),tax_account=VALUES(tax_account),fints_read=VALUES(fints_read),fints_write=VALUES(fints_write),version=VALUES(version)')->execute([$a['club_id'],$id,(int)$active,$taxAccount,(int)$fintsRead,(int)$fintsWrite,$version+1]);$this->audit($a,'finance.account.settings',$id,$version+1);
        });
    }
    /** Called only inside the bank import's atomic transaction. No network or mail operation. */
    public function createBankTransactionLocked(array $data,string $key):string
    {
        $this->actor(true);if(!$this->db->inTransaction())throw new DomainException('Bankimport benötigt eine bestehende Transaktion.');$this->requireActiveAccount(self::field($data,'account',100,true));$id=$this->insertTransaction($data,$key);$this->autoMatchLocked($id);return $id;
    }
    /** Correct one unused duplicate import; the real movement and all original rows remain. */
    /** Document a reviewed already-withheld fee inside its bank-evidence transaction. */
    public function recordBankFeeLocked(string $entryId,int $entryVersion,string $accountId,string $day,int $cents,string $key):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Den tatsächlichen Gebührenabzug gemeinsam mit seinem Banknachweis speichern.');$a=$this->actor(true);
        $this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT')->execute([$a['club_id'],$entryId]);$entry=$this->currentEntry($entryId);$account=$this->requireActiveAccount($accountId);
        if($account['kind']!=='offline'||$entry['kind']!=='expense'||$entry['status']!=='open'||(int)$entry['version']!==$entryVersion||(int)$entry['allocated_cents']!==0||$cents<=0||(int)$entry['amount_cents']!==$cents)throw new DomainException('Den noch unbezahlten Gebührenbeleg und das tatsächliche Bankkonto erneut prüfen.',409);
        (new InvoiceApprovals($this->db,$this->actorId))->assertApproved($entryId,true);
        $transaction=$this->insertTransaction(['account'=>$accountId,'booked_on'=>$day,'direction'=>'expense','amount'=>self::decimal($cents),'counterparty'=>$entry['counterparty'],'description'=>$entry['description']],$key);
        $allocation=$this->insertAllocation($transaction,1,$entryId,$entryVersion,self::decimal($cents),substr(hash('sha256','bank-fee-allocation:'.$key),0,32));$this->audit($a,'finance.payment.bank_fee_recorded',$entryId,$entryVersion+1);
        return ['transaction'=>$this->transactionDetail($transaction,true),'allocation'=>$allocation,'entry'=>$this->currentEntry($entryId)];
    }
    public function correctDuplicateBankPaymentLocked(string $duplicate,int $version,string $actual,string $date,string $key):array
    {
        $a=$this->actor(true);if(!$this->db->inTransaction())throw new DomainException('Bankkorrektur benötigt einen gemeinsamen Speichervorgang.');
        $this->lockMovements([$duplicate,$actual]);(new BankPaymentGroups($this->db,$this->actorId))->assertSingle($actual);$before=$this->transactionDetail($duplicate,true);$target=$this->transactionDetail($actual,true);self::date($date);
        if($duplicate===$actual||$before['account_id']!==$target['account_id']||(int)$before['cents']!==(int)$target['cents']||$before['account_kind']!=='offline')throw new DomainException('Andere tatsächliche Zahlung auf demselben Bankkonto mit gleichem Betrag und gleicher Richtung auswählen.',422);
        if(!BankCounterpayments::actualMovement($this->db,$this->actorId,$target['id'],true))throw new DomainException('Ein Kontoumsatz wurde inzwischen geändert oder bereits korrigiert. Aktuellen Stand prüfen.',409);
        return ['duplicate'=>$before,'actual'=>$target,'counter'=>$this->duplicateBankCounterLocked($a,$before,$version,$date,$key)];
    }
    /** The actual same-account components keep their complete receipt and money originals. */
    public function correctDuplicateBankGroupLocked(string $duplicate,int $version,array $actual,string $date,string $key):array
    {
        $a=$this->actor(true);if(!$this->db->inTransaction())throw new DomainException('Bankkorrektur benötigt einen gemeinsamen Speichervorgang.');$actual=BankPaymentGroups::ids($actual);if(count($actual)<2||in_array($duplicate,$actual,true))throw new DomainException('Andere tatsächliche Teilbewegungen für die doppelte Übernahme auswählen.',422);
        $this->lockMovements([$duplicate,...$actual]);$before=$this->transactionDetail($duplicate,true);$targets=[];$total=0;
        foreach($actual as$id){$t=$this->transactionDetail($id,true);if($t['account_id']!==$before['account_id']||!BankCounterpayments::actualMovement($this->db,$this->actorId,$id,true))throw new DomainException('Nur tatsächliche Teilbewegungen auf demselben Bankkonto zusammen korrigieren.',422);$total+=(int)$t['cents'];if(!is_int($total))throw new DomainException('Die Summe der Teilbeträge ist nicht prüfbar.',422);$targets[$id]=$t;}
        if($total!==(int)$before['cents']||$before['account_kind']!=='offline')throw new DomainException('Die tatsächlichen Teilbeträge müssen genau dem Betrag der doppelten Bankübernahme entsprechen.',422);
        return ['duplicate'=>$before,'actual'=>$targets,'counter'=>$this->duplicateBankCounterLocked($a,$before,$version,$date,$key)];
    }
    /** A duplicated bank net can represent a portion of a larger immutable payment. */
    public function correctDuplicateBankSharesLocked(string $duplicate,int $version,array $portions,string $date,string $key):array
    {
        $a=$this->actor(true);if(!$this->db->inTransaction())throw new DomainException('Bankkorrektur benötigt einen gemeinsamen Speichervorgang.');$ids=BankPaymentGroups::ids(array_map('strval',array_keys($portions)));if(!$ids||in_array($duplicate,$ids,true))throw new DomainException('Andere tatsächliche Bankteilbeträge für die doppelte Übernahme auswählen.',422);
        $this->lockMovements([$duplicate,...$ids]);$before=$this->transactionDetail($duplicate,true);$targets=[];$total=0;
        foreach($ids as$id){$part=$portions[$id];$t=$this->transactionDetail($id,true);if(!is_int($part)||!$part||($part>0)!==((int)$t['cents']>0)||abs($part)>abs((int)$t['cents'])||$t['account_id']!==$before['account_id']||!BankCounterpayments::actualMovement($this->db,$this->actorId,$id,true))throw new DomainException('Nur tatsächliche vorzeichenrichtige Bankteilbeträge auf demselben Konto korrigieren.',422);$targets[$id]=$t;$total+=$part;if(!is_int($total))throw new DomainException('Die Summe der Teilbeträge ist nicht prüfbar.',422);}
        if($total!==(int)$before['cents']||$before['account_kind']!=='offline')throw new DomainException('Die Bankteilbeträge müssen genau dem doppelt übernommenen Nettobetrag entsprechen.',422);
        return ['duplicate'=>$before,'actual'=>$targets,'counter'=>$this->duplicateBankCounterLocked($a,$before,$version,$date,$key)];
    }
    private function duplicateBankCounterLocked(array $a,array $before,int $version,string $date,string $key):array
    {
        $duplicate=$before['id'];self::date($date);
        if((int)$before['version']!==$version||$before['reversal_of']||$before['reversed_on'])throw new DomainException('Ein Kontoumsatz wurde inzwischen geändert oder bereits korrigiert. Aktuellen Stand prüfen.',409);
        (new BankPaymentGroups($this->db,$this->actorId))->assertSingle($duplicate);
        if((int)$before['allocated_cents']!==0)throw new DomainException('Zuerst die Beleg- und Geldtransferzuordnungen der doppelten Erfassung mit Nachweis korrigieren.',409);
        if($date<$before['booked_on']||$date>$this->businessDay())throw new DomainException('Korrekturdatum zwischen der doppelten Buchung und heute wählen.',422);
        $q=$this->db->prepare('SELECT record_id,kind FROM bank_transaction_sources WHERE club_id=? AND transaction_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$duplicate]);$sources=$q->fetchAll();
        if(count($sources)!==1||$sources[0]['kind']!=='imported')throw new DomainException('Weitere oder anders verbundene Banknachweise verwenden diese Zahlung. Zuerst deren tatsächlichen Bezug prüfen.',409);
        $q=$this->db->prepare('SELECT id FROM finance_allocations WHERE club_id=? AND transaction_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$duplicate]);
        foreach($q as $allocation)DonationReceipts::assertPaymentMutable($this->db,$a['club_id'],$allocation['id']);
        $counter=$this->insertTransaction(['account'=>$before['account_id'],'booked_on'=>$date,'direction'=>$before['cents']>0?'expense':'income','amount'=>self::decimal(abs((int)$before['cents'])),'counterparty'=>$before['counterparty'],'description'=>'Korrektur einer doppelten Bankübernahme'],$key,$duplicate);
        $this->db->prepare('UPDATE finance_transactions SET version=version+1 WHERE club_id=? AND id=?')->execute([$a['club_id'],$duplicate]);$this->audit($a,'finance.bank-duplicate.corrected',$duplicate,$version+1);
        return $this->transactionDetail($counter,true);
    }
    /** Outgoing issuance owns one transaction with its immutable document and ledger entry. */
    public function createOutgoingEntryLocked(array $data,string $key):string
    {
        $this->actor(true);if(!$this->db->inTransaction())throw new DomainException('Rechnungsausgabe benötigt eine bestehende Transaktion.');return $this->createEntryLocked($data,$key);
    }
    public function accounts():array
    {
$a=$this->actor();$q=$this->db->prepare('SELECT a.*,a.opening_cents+COALESCE((SELECT SUM(t.cents) FROM finance_transactions t WHERE t.club_id=a.club_id AND t.account_id=a.id),0) AS balance_cents FROM finance_accounts a WHERE a.club_id=? ORDER BY name');$q->execute([$a['club_id']]);$rows=$q->fetchAll();foreach($rows as &$r){$r['balance_cents']=(int)$r['balance_cents'];$r=array_replace($r,$this->accountSettings($r['id']));}unset($r);return $rows;
    }
    public function entry(string $id):array
    {
        $a=$this->actor();$q=$this->db->prepare('SELECT e.*,r.reversed_on,r.reason,'.$this->allocatedSql().' AS allocated_cents FROM finance_entries e LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE e.club_id=? AND e.id=?');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row)throw new DomainException('Beleg nicht verfügbar.');return $this->paymentState($row);
    }
    /** Current source for a financial decision, including a caller with an older read view. */
    public function currentEntry(string $id):array
    {
        $own=!$this->db->inTransaction();
        try{
            if($own)$this->db->exec('START TRANSACTION');$a=$this->actor();
            $q=$this->db->prepare('SELECT e.*,r.reversed_on,r.reason FROM finance_entries e LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE e.club_id=? AND e.id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);$row=$q->fetch();
            if(!$row)throw new DomainException('Beleg nicht verfügbar.');
            $q=$this->db->prepare('SELECT l.id,l.cents,l.voided_at,returned.id AS return_id FROM finance_allocations l LEFT JOIN finance_transactions returned ON returned.club_id=l.club_id AND returned.reversal_of=l.transaction_id WHERE l.club_id=? AND l.entry_id=? ORDER BY l.id,returned.id FOR SHARE NOWAIT');$q->execute([$a['club_id'],$id]);
            $row['allocated_cents']=0;foreach($q as$allocation)if($allocation['voided_at']===null&&$allocation['return_id']===null)$row['allocated_cents']+=(int)$allocation['cents'];
            if(PaymentFundingLedger::hasBasis($this->db,$a['club_id'],$id,true))$row['allocated_cents']=PaymentFundingLedger::entry($this->db,$a['club_id'],$id,(int)$row['amount_cents'],true)['paid_cents'];
            $result=$this->paymentState($row);if($own)$this->db->commit();return $result;
        }catch(\Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException&&in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Beleg oder Zahlung wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
    }
    private function entryWhere(array $filter):array
    {
        foreach(['direction','kind','status','from','to','account','q']as$key)if(isset($filter[$key])&&!is_string($filter[$key]))throw new DomainException('Belegfilter prüfen.');
        $a=$this->actor();$where=['e.club_id=?'];$params=[$a['club_id']];
        if(($filter['direction']??'')!==''){if(!in_array($filter['direction'],['income','expense'],true))throw new DomainException('Ungültige Zahlungsrichtung.');$where[]=$filter['direction']==='income'?"e.kind!='expense'":"e.kind='expense'";}
        if(($filter['kind']??'')!==''){if(!isset(self::KINDS[$filter['kind']]))throw new DomainException('Ungültige Belegart.');$where[]='e.kind=?';$params[]=$filter['kind'];}
        $status=$filter['status']??'';
        if($status!==''){
            if(!in_array($status,['open','partial','paid','cancelled','reversed','archive'],true))throw new DomainException('Ungültiger Belegstatus.');
            if($status==='partial')$where[]="e.status='open' AND ".$this->allocatedSql().'>0';
            elseif($status==='reversed')$where[]='r.entry_id IS NOT NULL';
            elseif($status==='archive')$where[]="(e.status='cancelled' OR r.entry_id IS NOT NULL)";
            else{$where[]='e.status=?';$params[]=$status;if($status==='paid')$where[]='r.entry_id IS NULL';}
        }else $where[]="e.status!='cancelled' AND r.entry_id IS NULL";
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op){if(($filter[$key]??'')!==''){self::date($filter[$key]);$where[]='e.document_date'.$op.'?';$params[]=$filter[$key];}}
        if(!empty($filter['from'])&&!empty($filter['to'])&&$filter['from']>$filter['to'])throw new DomainException('Von-Datum liegt nach Bis-Datum.');
        if(($filter['account']??'')!==''){$this->account($filter['account']);$where[]='EXISTS(SELECT 1 FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id WHERE l.club_id=e.club_id AND l.entry_id=e.id AND l.voided_at IS NULL AND t.account_id=?)';$params[]=$filter['account'];}
        if(($filter['q']??'')!==''){$term=self::field($filter,'q',150);$where[]='(instr(lower(e.description),lower(?))>0 OR instr(lower(e.counterparty),lower(?))>0 OR instr(lower(COALESCE(e.notes,\'\')),lower(?))>0 OR instr(lower(COALESCE(e.display_number,\'\')),lower(?))>0)';array_push($params,$term,$term,$term,$term);}
        return [implode(' AND ',$where),$params];
    }
    private function entrySelect():string{return 'SELECT e.*,'.$this->allocatedSql().' AS allocated_cents,(SELECT group_concat(DISTINCT a.name) FROM finance_allocations l JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id WHERE l.club_id=e.club_id AND l.entry_id=e.id AND l.voided_at IS NULL) AS account_name,r.reversed_on,r.reason FROM finance_entries e LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE ';}
    public function entries(array $filter=[]):array
    {
        [$where,$params]=$this->entryWhere($filter);$q=$this->db->prepare($this->entrySelect().$where.' ORDER BY e.document_date DESC,e.number DESC LIMIT 1001');$q->execute($params);$rows=$q->fetchAll();
        if(count($rows)>1000)throw new DomainException('Mehr als 1.000 Belege. Bitte Zeitraum oder Suche eingrenzen.');return array_map($this->paymentState(...),$rows);
    }
    /** An opt-in replay handler replaces creation side effects; existing callers keep their callback contract. */
    public function createEntry(array $data,string $key,?callable $afterCreate=null,?callable $onReplay=null):string
    {
        return $this->transaction(function()use($data,$key,$afterCreate,$onReplay){
            $created=false;$id=$this->createEntryLocked($data,$key,null,$created);
            if(!$created&&$onReplay!==null){$onReplay($id);return$id;}
            if($afterCreate)$afterCreate($id);return$id;
        });
    }
    /** Compact sort/payment facts for all matches; only selected whole originals. */
    public function entriesPage(array $filter=[],mixed $page=1,mixed $sort='document_date',mixed $order='desc',mixed $size=6):array
    {
        if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000||!is_string($sort)||!in_array($sort,['number','document_date','counterparty','amount_cents'],true)||!is_string($order)||!in_array($order,['asc','desc'],true)||!is_int($size)||!in_array($size,[6,20,40,100],true))throw new DomainException('Belegseite oder Sortierung prüfen.');
        [$where,$params]=$this->entryWhere($filter);$owned=!$this->db->inTransaction();if($owned){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}
        try{
            $q=$this->db->prepare('SELECT e.id,e.number,e.document_date,e.counterparty,e.amount_cents,e.kind,e.status,r.reversed_on,'.$this->allocatedSql().' AS allocated_cents FROM finance_entries e LEFT JOIN finance_reversals r ON r.club_id=e.club_id AND r.entry_id=e.id WHERE '.$where.' ORDER BY e.document_date DESC,e.number DESC');$q->execute($params);$index=[];while($r=$q->fetch())$index[]=$this->paymentState($r);$q->closeCursor();
            $total=count($index);$totals=self::entryTotals($index);usort($index,fn($a,$b)=>(($a[$sort]<=>$b[$sort])?:($a['number']<=>$b['number']))*($order==='desc'?-1:1));$pages=max(1,(int)ceil($total/$size));$page=min((int)$page,$pages);$ids=array_column(array_slice($index,($page-1)*$size,$size),'id');$rows=[];
            if($ids){$q=$this->db->prepare($this->entrySelect().$where.' AND e.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute([...$params,...$ids]);$byId=[];while($r=$q->fetch())$byId[$r['id']]=$this->paymentState($r);foreach($ids as$id)$rows[]=$byId[$id];}
            if($owned)$this->db->commit();if($this->actor()['club_id']!==$params[0])throw new DomainException('Vereinszugang geändert.');return compact('rows','total','totals','page','pages','sort','order','size');
        }catch(\Throwable$e){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    /** Explicitly reviewed additional assignments only. The whole batch commits together. */
    public function createCatalogCharges(array $items,string $day,string $due,string $mode,?string $priceDay,string $key):array
    {
        $this->actor(true);
        return Infrastructure\Database\Transactions::run($this->db,function()use($items,$day,$due,$mode,$priceDay,$key){
            $billing=new CatalogBilling($this->db,$this->actorId);$a=$billing->actor(true);
            $items=CatalogBilling::items($items,true);self::date($day);self::date($due);
            if($day>$this->businessDay()||$due<$day||!in_array($mode,['full','days'],true)||!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Abrechnungsdatum, Zahlungsziel oder Vorgangskennung prüfen.');
            if($priceDay!==null)self::date($priceDay);
            // Serialize batches for this club, including different assignments and invoice numbers.
            $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE');$q->execute([$a['club_id']]);
            $hash=hash('sha256',json_encode([$items,$day,$due,$mode,$priceDay],JSON_THROW_ON_ERROR));
            $q=$this->db->prepare('SELECT entry_id,batch_hash FROM native_catalog_charges WHERE club_id=? AND batch_key=? ORDER BY ordinal FOR UPDATE');$q->execute([$a['club_id'],$key]);$old=$q->fetchAll(PDO::FETCH_ASSOC);
            if($old){if(count($old)!==count($items)||array_filter($old,fn($row)=>!hash_equals($row['batch_hash'],$hash)))throw new DomainException('Vorgangskennung gehört zu einer anderen Abrechnung. Neue Prüfung öffnen.');return array_column($old,'entry_id');}
            $quotes=$billing->review(array_column($items,'assignment_id'),$day,$mode,$priceDay,true);
            foreach($quotes as$i=>$quote){
                if(!hash_equals($quote['fingerprint'],$items[$i]['fingerprint']))throw new DomainException('Preisgrundlage inzwischen geändert. Abrechnung erneut prüfen.');
                if($quote['charge'])throw new DomainException('Dieser Tarifzeitraum ist bereits abgerechnet. Vorhandenen Beleg öffnen.');
            }
            $ids=[];
            foreach($quotes as$i=>$quote){
                unset($quote['charge']);$chargeId=bin2hex(random_bytes(16));$data=$quote['data'];$description=$quote['name'].' · '.$quote['period_start'].' – '.$quote['period_end'];
                $amount=self::decimal($quote['amount_cents']);$kind=$quote['kind']==='service'?'income':$quote['kind'];
                $position=['description'=>$description,'amount'=>$amount,'tax_rate'=>$data['tax_rate'],'sphere'=>$data['sphere'],'account'=>$data['tax_account']];
                $entry=$this->createEntryLocked(['kind'=>$kind,'description'=>$description,'counterparty'=>$quote['contact_name'],'category'=>$kind==='contribution'?'membership':($kind==='donation'?'donations':'general'),'document_date'=>$day,'due_date'=>$due,'positions'=>[$position],'notes'=>json_encode(['catalog_assignment'=>$quote['assignment_id'],'catalog_period'=>[$quote['period_start'],$quote['period_end']],'catalog_fingerprint'=>$quote['fingerprint']],JSON_THROW_ON_ERROR)],$chargeId);
                $this->db->prepare('INSERT INTO native_catalog_charges VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$a['club_id'],$chargeId,$quote['assignment_id'],$quote['period_start'],$quote['period_end'],$entry,$key,$hash,$i+1,json_encode($quote,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$a['id']]);$ids[]=$entry;
                $this->audit($a,'finance.catalog.charged',$entry,1);
            }
            return $ids;
        });
    }
    private function createEntryLocked(array $data,string $key,?array $admissionActor=null,?bool &$wasCreated=null):string
    {
            $wasCreated=false;
            $a=$admissionActor??$this->actor();if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');
            $q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND request_key=?');$q->execute([$a['club_id'],$key]);if($id=$q->fetchColumn())return $id;
            $kind=self::field($data,'kind',20,true);$description=self::field($data,'description',300,true);$counterparty=self::field($data,'counterparty',200);
            $category=self::field($data,'category',30,true);$notes=self::field($data,'notes',10000);
            if(!isset(self::KINDS[$kind])||!isset(self::CATEGORIES[$category]))throw new DomainException('Belegart und Kategorie prüfen.');
            $date=self::field($data,'document_date',10,true);$due=self::field($data,'due_date',10,true);self::date($date);self::date($due);
            if($due<$date)throw new DomainException('Fälligkeit liegt vor dem Belegdatum.');
            $positions=$admissionActor!==null
                ? [['ordinal'=>1,'description'=>$description,'gross_cents'=>self::cents(self::field($data,'amount',30,true)),'tax_rate'=>0,'tax_cents'=>0,'sphere'=>'','account_code'=>'','account_name'=>'','account_category'=>'','catalog_year'=>null,'booking_key'=>'','booking_key_snapshot'=>'']]
                : $this->normalizePositions($data['positions']??[['description'=>$description,'amount'=>self::field($data,'amount',30,true),'tax_rate'=>'0']],$kind,$date,[],$data['tax_rounding']??null);
            $amount=(int)array_sum(array_column($positions,'gross_cents'));
            $q=$this->db->prepare('SELECT COALESCE(MAX(number),0)+1 FROM finance_entries WHERE club_id=? FOR SHARE NOWAIT');$q->execute([$a['club_id']]);$number=(int)$q->fetchColumn();$id=bin2hex(random_bytes(16));
            $this->db->prepare("INSERT INTO finance_entries (id,club_id,number,kind,description,counterparty,amount_cents,document_date,due_date,status,category,notes,request_key) VALUES (?,?,?,?,?,?,?,?,?,'open',?,?,?)")->execute([$id,$a['club_id'],$number,$kind,$description,$counterparty,$amount,$date,$due,$category,$notes,$key]);
            $display=Numbering::allocate($this->db,$a['club_id'],$kind==='expense'?'expense':'invoice',$date,$id,$number);
            $this->db->prepare('UPDATE finance_entries SET display_number=? WHERE club_id=? AND id=?')->execute([$display,$a['club_id'],$id]);
            $this->insertPositions($a,'entry',$id,$positions,true);$this->audit($a,'finance.entry.created',$id,1);$wasCreated=true;return $id;
    }
    public function createAnnualContribution(string $contactId,int $year,string $expectedFamily,?string $invoiceDate=null):string
    {
        return Infrastructure\Database\Transactions::run($this->db,function()use($contactId,$year,$expectedFamily,$invoiceDate){
            $a=$this->actor(true);if($year<2020||$year>(int)substr($this->businessDay(),0,4)+1)throw new DomainException('Beitragsjahr prüfen.');
            $invoiceDate??=$this->businessDay();self::date($invoiceDate);if($invoiceDate>$this->businessDay())throw new DomainException('Rechnungsdatum darf nicht in der Zukunft liegen.');
            $c=DocumentCatalog::query($this->db,'SELECT * FROM contacts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$contactId])->fetch();if(!$c||!empty($c['archived_at']))throw new DomainException('Beitragszahler nicht verfügbar oder archiviert.');
            $f=DocumentCatalog::query($this->db,'SELECT f.* FROM member_families f JOIN member_family_people p ON p.club_id=f.club_id AND p.family_id=f.id WHERE p.club_id=? AND p.contact_id=? FOR SHARE NOWAIT',[$a['club_id'],$contactId])->fetch()?:null;
            if($f)$f['people']=DocumentCatalog::query($this->db,'SELECT contact_id id FROM member_family_people WHERE club_id=? AND family_id=? FOR SHARE NOWAIT',[$a['club_id'],$f['id']])->fetchAll();
            return $this->annualContributionLocked($a,$c,$f,$year,$expectedFamily,self::contributionDueDate($invoiceDate),$invoiceDate);
        });
    }
    /** Fixed dues only, inside the accepted-application transaction; no general finance authority. */
    public function createAdmissionContribution(string $applicationId,string $membershipId):?string
    {
        $a=BoardAccess::require($this->db,$this->actorId);
        $r=DocumentCatalog::query($this->db,"SELECT * FROM membership_applications WHERE club_id=? AND id=? AND status='accepted' AND reviewed_by=?",[$a['club_id'],$applicationId,$a['id']])->fetch();
        if(!$r)throw new DomainException('Angenommener Antrag dieser Prüfung erforderlich.');
        $membership=DocumentCatalog::query($this->db,'SELECT * FROM memberships WHERE club_id=? AND id=? AND contact_id=?',[$a['club_id'],$membershipId,$r['contact_id']])->fetch();
        if(!$membership)throw new DomainException('Aufgenommene Mitgliedschaft fehlt.');
        $answers=json_decode($r['answers'],true,512,JSON_THROW_ON_ERROR);
        $f=DocumentCatalog::query($this->db,'SELECT f.* FROM member_families f JOIN member_family_people p ON p.club_id=f.club_id AND p.family_id=f.id WHERE p.club_id=? AND p.contact_id=?',[$a['club_id'],$r['contact_id']])->fetch()?:null;
        if(($answers['membership_type']??'single')==='family_member'||($f&&$f['payer_contact_id']!==$r['contact_id'])){
            if($f){
                $year=(int)substr($membership['starts_on'],0,4);$key=substr(hash('sha256','annual-dues:'.$a['club_id'].':family:'.$f['id'].':'.$year),0,32);
                $entry=DocumentCatalog::query($this->db,'SELECT id FROM finance_entries WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetchColumn();
                if($entry){$previous=DocumentCatalog::query($this->db,'SELECT entry_id FROM member_dues_people WHERE club_id=? AND contact_id=? AND year=?',[$a['club_id'],$r['contact_id'],$year])->fetchColumn();if($previous&&$previous!==$entry)throw new DomainException('Eine frühere Beitragsforderung muss vor der Familienzuordnung geprüft werden.');DocumentCatalog::query($this->db,'INSERT IGNORE INTO member_dues_people VALUES(?,?,?,?)',[$a['club_id'],$r['contact_id'],$year,$entry]);}
            }
            return null;
        }
        $c=DocumentCatalog::raw($this->db,$a['club_id'],'contact',$r['contact_id']);
        if($f)$f['people']=DocumentCatalog::query($this->db,'SELECT contact_id id FROM member_family_people WHERE club_id=? AND family_id=?',[$a['club_id'],$f['id']])->fetchAll();
        return $this->annualContributionLocked($a,$c,$f,(int)substr($membership['starts_on'],0,4),$f['id']??'',self::contributionDueDate($this->businessDay()));
    }
    public static function contributionDueDate(string $invoiceDate):string
    {
        self::date($invoiceDate);$date=new \DateTimeImmutable($invoiceDate);$month=$date->modify('first day of next month');
        return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$date->format('d'),(int)$month->format('t')))->format('Y-m-d');
    }
    private function annualContributionLocked(array $a,array $c,?array $f,int $year,string $expectedFamily,?string $dueDate=null,?string $invoiceDate=null):string
    {
            $contactId=$c['id'];
            if(($f['id']??'')!==$expectedFamily)throw new DomainException('Familienzuordnung geändert. Bitte neu laden.');
            $people=$f?array_column($f['people'],'id'):[$contactId];$covered=[];
            foreach($people as $person){$q=$this->db->prepare('SELECT 1 FROM memberships WHERE club_id=? AND contact_id=? AND starts_on<=? AND (ends_on IS NULL OR ends_on>=?) FOR SHARE NOWAIT');$q->execute([$a['club_id'],$person,$year.'-12-31',$year.'-01-01']);if($q->fetchColumn())$covered[]=$person;}
            if(!$covered)throw new DomainException('Keine Mitgliedschaft im gewählten Beitragsjahr.');
            $key=substr(hash('sha256','annual-dues:'.$a['club_id'].':'.($f?'family:'.$f['id']:'person:'.$contactId).':'.$year),0,32);
            $q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND request_key=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$key]);$existing=$q->fetchColumn();
            foreach($covered as $person){$q=$this->db->prepare('SELECT entry_id FROM member_dues_people WHERE club_id=? AND contact_id=? AND year=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$person,$year]);$previous=$q->fetchColumn();if($previous&&$previous!==$existing)throw new DomainException('Für eine zugeordnete Person besteht bereits eine Beitragsforderung in diesem Jahr. Vor einer Änderung bitte die vorhandene Forderung prüfen.');}
            if(!$existing){$rate=ContributionRates::all($this->db,$a['club_id'],true)[$f?'family':'single'];$payer=$f?DocumentCatalog::query($this->db,'SELECT * FROM contacts WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$f['payer_contact_id']])->fetch():$c;$today=$invoiceDate??$this->businessDay();$entry=$this->createEntryLocked(['kind'=>'contribution','description'=>($f?'Familienbeitrag · '.$f['name']:'Einzelbeitrag · '.$c['name']).' · '.$year,'counterparty'=>$payer['name'],'category'=>'membership','amount'=>self::decimal($rate['amount_cents']),'document_date'=>$today,'due_date'=>$dueDate??self::contributionDueDate($today),'notes'=>json_encode(['year'=>$year,'family'=>$f['id']??null,'payer'=>$payer['id'],'covered_contacts'=>$covered,'contribution_rate'=>$rate],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)],$key,$a);}else{$entry=$existing;}
            foreach($covered as $person)$this->db->prepare('INSERT IGNORE INTO member_dues_people VALUES(?,?,?,?)')->execute([$a['club_id'],$person,$year,$entry]);
            return $entry;
    }
    public static function entryTotals(array $rows):array
    {
        $totals=['income'=>0,'expense'=>0,'allocated'=>0,'remaining'=>0,'net'=>0];
        foreach($rows as $r){if(in_array($r['payment_state'],['cancelled','reversed'],true))continue;$totals[$r['kind']==='expense'?'expense':'income']+=(int)$r['amount_cents'];$totals['allocated']+=(int)$r['allocated_cents'];$totals['remaining']+=(int)$r['remaining_cents'];}
        $totals['net']=$totals['income']-$totals['expense'];return $totals;
    }
    public function recordPayment(string $id,int $version,string $accountId,string $date,?string $amount=null):void
    {
        $this->transaction(function()use($id,$version,$accountId,$date,$amount){
            $a=$this->actor();$e=$this->entry($id);
            if($e['status']!=='open'||(int)$e['version']!==$version||$e['remaining_cents']<=0)throw new DomainException('Beleg inzwischen geändert oder bereits bezahlt.');
            $cents=$amount===null?$e['remaining_cents']:self::cents($amount);
            if($cents<=0||$cents>$e['remaining_cents'])throw new DomainException('Der Zahlungsbetrag muss größer als null sein und darf den offenen Rechnungsbetrag nicht überschreiten.');
            $amount=self::decimal($cents);
            $tx=$this->insertTransaction(['account'=>$accountId,'booked_on'=>$date,'direction'=>$e['kind']==='expense'?'expense':'income','amount'=>$amount,'counterparty'=>$e['counterparty'],'description'=>$e['description']],bin2hex(random_bytes(16)));
            $this->insertAllocation($tx,1,$id,$version,$amount,bin2hex(random_bytes(16)));
            $this->audit($a,'finance.payment.recorded',$id,$version+1);
        });
        DonationPreparation::afterSource($this->db,$this->actorId,'entry',$id);
    }
    private function assertNotIssuedOutgoing(string $id):void
    {
        $a=$this->actor();if(!Database::tableExists($this->db,'outgoing_invoices'))return;
        $q=$this->db->prepare("SELECT 1 FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND state IN ('issued','cancelled')".($this->db->inTransaction()?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$id]);if($q->fetchColumn())throw new DomainException('Diese Rechnung wurde ausgegeben. Die nachvollziehbare Korrektur unter Ausgangsrechnungen verwenden. Tatsächliche Zahlungen bleiben erhalten.');
    }
    public function cancel(string $id,int $version):void
    {
        try{\Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($id,$version):void{
            $a=$this->actor(true);$q=$this->db->prepare('SELECT id FROM finance_entries WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$this->assertNotIssuedOutgoing($id);$e=$this->currentEntry($id);
            if($e['kind']==='expense'){
                $q=$this->db->prepare("SELECT id FROM finance_reimbursement_claims WHERE club_id=? AND entry_id=? AND state<>'cancelled' FOR SHARE NOWAIT");$q->execute([$a['club_id'],$id]);
                if($q->fetchColumn()!==false)throw new DomainException('Den privaten Erstattungsfall zuerst berichtigen oder ausdrücklich als irrtümlich verwerfen. Der Anspruch bleibt sonst bestehen.',409);
                UnpaidExpenseChanges::assertIdle($this->db,$this->actorId,$id);
            }
            if($e['allocated_cents']>0)throw new DomainException('Zuerst bestehende Zuordnungen aufheben.');$q=$this->db->prepare("UPDATE finance_entries SET status='cancelled',version=version+1 WHERE club_id=? AND id=? AND status='open' AND version=?");$q->execute([$a['club_id'],$id,$version]);if($q->rowCount()!==1)throw new DomainException('Nur unveränderte offene Belege können verworfen werden.');$this->audit($a,'finance.entry.cancelled',$id,$version+1);
        });}catch(\PDOException$failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Beleg oder Erstattungsfall wird gerade bearbeitet. Aktuellen Stand erneut lesen.',503,$failure);throw$failure;}
        DonationPreparation::afterSource($this->db,$this->actorId,'entry',$id);
    }
    public function overview(string $from,string $to):array
    {
        $a=$this->actor();self::date($from);self::date($to);if($from>$to)throw new DomainException('Auswertungszeitraum prüfen.');
        // Cashflow comes from transactions once, independent of how many documents they cover.
        $q=$this->db->prepare('SELECT substr(t.booked_on,1,7) AS month,(t.cents-SIGN(t.cents)*'.self::INTERNAL_ALLOCATED.') AS cents,COALESCE(o.cents,t.cents) AS original_cents FROM finance_transactions t LEFT JOIN finance_transactions o ON o.club_id=t.club_id AND o.id=t.reversal_of WHERE t.club_id=? AND t.booked_on>=? AND t.booked_on<=? ORDER BY t.booked_on');$q->execute([$a['club_id'],$from,$to]);$months=[];
        foreach($q->fetchAll() as $row){$m=$row['month'];$months[$m]??=['month'=>$m,'income'=>0,'expense'=>0,'donations'=>0];if($row['original_cents']>0)$months[$m]['income']+=(int)$row['cents'];else $months[$m]['expense']-=(int)$row['cents'];}
        $sql="SELECT t.booked_on AS day,l.cents FROM finance_allocations l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id WHERE l.club_id=? AND l.voided_at IS NULL AND e.kind='donation' UNION ALL SELECT r.booked_on,-l.cents FROM finance_allocations l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id JOIN finance_transactions r ON r.club_id=l.club_id AND r.reversal_of=l.transaction_id WHERE l.club_id=? AND l.voided_at IS NULL AND e.kind='donation'";
        $q=$this->db->prepare("SELECT substr(day,1,7) AS month,SUM(cents) AS donations FROM ($sql) AS donation_movements WHERE day>=? AND day<=? GROUP BY month");$q->execute([$a['club_id'],$a['club_id'],$from,$to]);foreach($q->fetchAll() as $r)$months[$r['month']]['donations']=(int)$r['donations'];
        $remaining='e.amount_cents-'.$this->allocatedSql();
        $q=$this->db->prepare("SELECT COALESCE(SUM(CASE WHEN e.kind='expense' THEN 0 ELSE $remaining END),0) AS receivables,COALESCE(SUM(CASE WHEN e.kind='expense' THEN $remaining ELSE 0 END),0) AS payables FROM finance_entries e WHERE e.club_id=? AND e.status='open'");$q->execute([$a['club_id']]);$open=array_map('intval',$q->fetch());ksort($months);$months=array_values($months);
        $funds=(int)array_sum(array_column($this->accounts(),'balance_cents'));
        return ['funds'=>$funds,'projected'=>$funds+(int)$open['receivables']-(int)$open['payables'],'months'=>$months,'income'=>array_sum(array_column($months,'income')),'expense'=>array_sum(array_column($months,'expense')),'donations'=>array_sum(array_column($months,'donations'))]+$open;
    }
    public function createGoods(array $data,string $key):string
    {
        return $this->transaction(function()use($data,$key){$a=$this->actor();if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Formular neu öffnen.');$q=$this->db->prepare('SELECT id FROM goods_donations WHERE club_id=? AND request_key=?');$q->execute([$a['club_id'],$key]);if($id=$q->fetchColumn())return $id;
            $donor=self::field($data,'donor',200,true);$description=self::field($data,'description',2000,true);$valuation=self::field($data,'valuation',2000,true);$date=self::field($data,'received_on',10,true);self::date($date);$positions=$this->normalizePositions($data['positions']??[['description'=>self::field($data,'designation',200)?:substr($description,0,300),'amount'=>self::field($data,'amount',30,true),'tax_rate'=>'0']],'goods',$date);$amount=(int)array_sum(array_column($positions,'gross_cents'));if(!$amount||$date>$this->businessDay())throw new DomainException('Wert oder Eingangsdatum prüfen.');$designation=self::field($data,'designation',200);$origin=$data['origin']??'unknown';if(!in_array($origin,['private','business','unknown'],true))throw new DomainException('Ursprung der Zuwendung prüfen.');$id=bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO goods_donations (id,club_id,donor,description,received_on,value_cents,valuation,request_key,designation,origin,evidence_present) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$a['club_id'],$donor,$description,$date,$amount,$valuation,$key,$designation,$origin,!empty($data['evidence_present'])?1:0]);$this->insertPositions($a,'goods',$id,$positions);$this->audit($a,'donation.goods.created',$id,1);return $id;
        });
    }
    public function goodsEntry(string $id):array{$a=$this->actor();$q=$this->db->prepare('SELECT * FROM goods_donations WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row)throw new DomainException('Sachspende nicht verfügbar.');return $row;}
    private function assertUnclosed(string $accountId,string $date):void
    {
        $a=$this->actor();$q=$this->db->prepare('SELECT closed_through FROM cash_closures WHERE club_id=? AND account_id=? ORDER BY closed_through DESC LIMIT 1'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$accountId]);$closed=$q->fetchColumn();if($closed&&$date<=$closed)throw new DomainException('Dieser Kassenzeitraum ist abgeschlossen. Nur spätere Buchungen sind möglich.');
    }
    private function accountMovements(string $id):array
    {
        $a=$this->actor();$this->account($id);
        $q=$this->db->prepare('SELECT t.id,t.id AS transaction_id,t.booked_on AS day,t.counterparty,t.description,t.cents,t.reversal_of IS NOT NULL AS reversal,r.booked_on AS reversed_on,'.$this->transactionAllocatedSql().' AS allocated_cents,'.self::INTERNAL_ALLOCATED.' AS internal_cents,'.self::excessReturnAllocatedSql($this->db).' AS excess_return_cents,(SELECT group_concat(DISTINCT e.display_number) FROM '.PaymentFunding::rows($this->db).' l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id WHERE l.club_id=t.club_id AND l.transaction_id=COALESCE(t.reversal_of,t.id) AND l.voided_at IS NULL) AS receipt_numbers,0 AS number FROM finance_transactions t LEFT JOIN finance_transactions r ON r.club_id=t.club_id AND r.reversal_of=t.id WHERE t.club_id=? AND t.account_id=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''));$q->execute([$a['club_id'],$id]);$rows=$q->fetchAll();
        foreach($rows as &$r){$r['remaining_cents']=abs((int)$r['cents'])-(int)$r['allocated_cents'];if((int)$r['internal_cents']>0)$r['receipt_numbers']=implode(', ',array_filter([$r['receipt_numbers'],'Eigener Geldtransfer']));if((int)$r['excess_return_cents']>0)$r['receipt_numbers']=implode(', ',array_filter([$r['receipt_numbers'],'Rückzahlung eines Überschusses']));}unset($r);
        usort($rows,fn($x,$y)=>[$x['day'],$x['cents']<0?1:0,$x['id']]<=>[$y['day'],$y['cents']<0?1:0,$y['id']]);return $rows;
    }
    private function assertCashBalance(array $account,string $date,int $change):void
    {
        $rows=$this->accountMovements($account['id']);$rows[]=['day'=>$date,'cents'=>$change];$daily=[];foreach($rows as $row)$daily[$row['day']]=($daily[$row['day']]??0)+(int)$row['cents'];ksort($daily);$balance=(int)$account['opening_cents'];foreach($daily as $cents){$balance+=$cents;if($balance<0)throw new DomainException('Der Kassenbestand würde negativ. Buchung nicht gespeichert.');}
    }
    public function cashbook(string $id,string $from,string $to):array
    {
        $account=$this->account($id);if($account['kind']!=='cash')throw new DomainException('Dieses Konto ist keine Barkasse.');self::date($from);self::date($to);if($from>$to||$from<$account['opened_on'])throw new DomainException('Kassenzeitraum prüfen: Beginn frühestens bei Kontoeröffnung.');
        $opening=(int)$account['opening_cents'];$balance=$opening;$rows=[];$income=0;$expense=0;
        foreach($this->accountMovements($id) as $r){if($r['day']<$from){$opening+=(int)$r['cents'];$balance=$opening;continue;}if($r['day']>$to)break;$balance+=(int)$r['cents'];$r['balance']=$balance;$rows[]=$r;if($r['cents']>=0)$income+=(int)$r['cents'];else $expense-=(int)$r['cents'];}
        $a=$this->actor();$q=$this->db->prepare('SELECT * FROM cash_closures WHERE club_id=? AND account_id=? ORDER BY closed_through DESC');$q->execute([$a['club_id'],$id]);return ['account'=>$account,'from'=>$from,'to'=>$to,'opening'=>$opening,'closing'=>$balance,'income'=>$income,'expense'=>$expense,'rows'=>$rows,'closures'=>$q->fetchAll()];
    }
    public function bankJournal(?string $id=null):array
    {
        $accounts=$id!==null?[$this->account($id)]:$this->accounts();$rows=[];
        foreach($accounts as $account)foreach($this->accountMovements($account['id']) as $row)$rows[]=$row+['account_name'=>$account['name']];
        usort($rows,fn($x,$y)=>[$y['day'],$y['number'],$y['reversal']]<=>[$x['day'],$x['number'],$x['reversal']]);return $rows;
    }
    public function closeCashbook(string $id,string $through,string $counted):void
    {
        $this->transaction(function()use($id,$through,$counted){$a=$this->actor();$this->lockMovementAccount($id);$account=$this->account($id);self::date($through);if($through>$this->businessDay())throw new DomainException('Abschlussdatum darf nicht in der Zukunft liegen.');$this->assertUnclosed($id,$through);$book=$this->cashbook($id,$account['opened_on'],$through);foreach($book['rows'] as $row){$row['remaining_cents']=$this->transactionDetail($row['transaction_id'],true)['remaining_cents'];if(!$row['reversal']&&!$row['reversed_on']&&$row['remaining_cents']>0)throw new DomainException('Vor dem Kassenabschluss bitte alle Kontotransaktionen dieses Zeitraums vollständig zuordnen.');}if(self::cents($counted)!==$book['closing'])throw new DomainException('Gezählter Bestand stimmt nicht mit dem Kassenbuch überein.');$cid=bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO cash_closures VALUES (?,?,?,?,?)')->execute([$cid,$a['club_id'],$id,$through,$book['closing']]);$this->audit($a,'cashbook.closed',$cid,1);});
    }
    public function exportCashbook(string $id,string $from,string $to):string
    {
        $a=$this->actor();$book=$this->cashbook($id,$from,$to);$out=fopen('php://temp','r+');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Datum','Beleg','Name','Zweck','Einnahme EUR','Ausgabe EUR','Bestand EUR'],';','"','');$money=fn(int $c)=>intdiv($c,100).','.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);fputcsv($out,[$from,'','','Übertrag','','',$money($book['opening'])],';','"','');
        foreach($book['rows'] as $r){$cells=[$r['day'],$r['receipt_numbers']??'',$r['counterparty'],$r['description'],$r['cents']>=0?$money((int)$r['cents']):'',$r['cents']<0?$money(-(int)$r['cents']):'',$money((int)$r['balance'])];$cells=array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$cells);fputcsv($out,$cells,';','"','');}rewind($out);$csv=stream_get_contents($out);fclose($out);$this->audit($a,'cashbook.exported',$id,1);return $csv;
    }
    public function goods():array{$a=$this->actor();$q=$this->db->prepare('SELECT * FROM goods_donations WHERE club_id=? ORDER BY received_on DESC,id LIMIT 1001');$q->execute([$a['club_id']]);$rows=$q->fetchAll();if(count($rows)>1000)throw new DomainException('Anzeige auf 1.000 Sachspenden begrenzt. Erweiterte Suche noch offen.');return $rows;}
    public function goodsPage(mixed $page=1,mixed $search='',mixed $origin='',mixed $evidence='',mixed $sort='recent',mixed $columns='summary'):array
    {
        $order=['recent'=>'received_on DESC,id','oldest'=>'received_on ASC,id','donor'=>'donor ASC,id','item'=>"CASE WHEN designation='' THEN description ELSE designation END ASC,id",'value_desc'=>'value_cents DESC,id','value_asc'=>'value_cents ASC,id'];
        return $this->positionRead(function()use($page,$search,$origin,$evidence,$sort,$columns,$order):array{
            $a=$this->actor();if(!is_string($sort)||!isset($order[$sort])||!is_string($columns)||!in_array($columns,['summary','accounting'],true))throw new DomainException('Sachspendensortierung und Spalten prüfen.',422);
            if((!is_int($page)&&!is_string($page))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$page)||(int)$page>1000000||!is_string($search)||!mb_check_encoding($search,'UTF-8')||mb_strlen($search)>200||!is_string($origin)||!in_array($origin,['','private','business','unknown'],true)||!is_string($evidence)||!in_array($evidence,['','0','1'],true))throw new DomainException('Sachspendensuche prüfen.');
            $search=trim($search);$where=['club_id=?'];$args=[$a['club_id']];
            if($origin!==''){$where[]='origin=?';$args[]=$origin;}if($evidence!==''){$where[]='evidence_present=?';$args[]=(int)$evidence;}
            if($search!==''){$where[]="(donor LIKE ? ESCAPE '!' OR designation LIKE ? ESCAPE '!' OR description LIKE ? ESCAPE '!' OR valuation LIKE ? ESCAPE '!')";$pattern='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';array_push($args,$pattern,$pattern,$pattern,$pattern);}
            $sql=implode(' AND ',$where);$q=$this->db->prepare('SELECT COUNT(*) FROM goods_donations WHERE '.$sql);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));$page=min((int)$page,$pages);$offset=($page-1)*6;
            $q=$this->db->prepare('SELECT * FROM goods_donations WHERE '.$sql.' ORDER BY '.$order[$sort].' LIMIT 6 OFFSET '.$offset);$q->execute($args);$rows=$q->fetchAll();$positions=[];
            if($columns==='accounting')foreach($rows as$row)$positions[$row['id']]=$this->positions('goods',$row['id']);
            return compact('rows','positions','total','page','pages','search','origin','evidence','sort','columns');
        });
    }
    public function export(array $filter):string
    {
        $a=$this->actor();$rows=$this->exportEntries($filter);$f=fopen('php://temp','r+');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['Nummer','Art','Beschreibung','Name','Belegdatum','Zahlungsdatum','Betrag EUR','Status','Konto','Korrekturdatum','Korrekturgrund','Zugeordnet EUR','Offen EUR'],';','"','');
        foreach($rows as $r){$cells=[($r['display_number']??sprintf('KH-%05d',$r['number'])),self::KINDS[$r['kind']],$r['description'],$r['counterparty'],$r['document_date'],$r['paid_on']??'',intdiv($r['amount_cents'],100).','.str_pad((string)($r['amount_cents']%100),2,'0',STR_PAD_LEFT),$r['payment_state'],$r['account_name']??'',$r['reversed_on']??'',$r['reason']??'',self::decimal($r['allocated_cents']),self::decimal($r['remaining_cents'])];$cells=array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$cells);fputcsv($f,$cells,';','"','');}
        rewind($f);$csv=stream_get_contents($f);fclose($f);if($this->actor()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.');$this->audit($a,'finance.csv.exported','selection',1);return $csv;
    }
    private function exportEntries(array $filter):\Generator
    {
        [$where,$params]=$this->entryWhere($filter);$buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$q=null;
        try{$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=$this->db->prepare($this->entrySelect().$where.' ORDER BY e.document_date DESC,e.number DESC');$q->execute($params);while($row=$q->fetch())yield$this->paymentState($row);}
        finally{if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
    }
}
