<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** A reviewed bank net proves existing physical components; it never creates or reallocates money. */
final class BankPaymentGroups
{
    use BankPaymentShares, BankPaymentFees;
    public function __construct(private PDO $db,private string $actor){}
    private function access():array{return Permissions::finance($this->db,$this->actor,true);}
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    public function available():bool{$this->access();return BankEvidence::groupsAvailable($this->db);}
    public function writable():bool{return $this->available()&&(int)$this->db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===Database::SCHEMA_VERSION;}
    private function requireWritable():void{if(!$this->writable())throw new DomainException('Zusammengehörige Bankzahlungen benötigen das geprüfte Paketupgrade.',503);}
    public static function ids(array $ids):array
    {
        if(count($ids)>40)throw new DomainException('Höchstens vierzig vorhandene Geldbewegungen zusammen prüfen.',422);
        foreach($ids as$id)if(!is_string($id)||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D',$id))throw new DomainException('Vorhandene eigene Geldbewegungen erneut auswählen.',422);
        if(count(array_unique($ids))!==count($ids))throw new DomainException('Jede vorhandene Geldbewegung nur einmal auswählen.',422);
        sort($ids,SORT_STRING);return $ids;
    }
    public static function reason(string $reason):string
    {
        if(!mb_check_encoding($reason,'UTF-8')||mb_strlen($reason,'UTF-8')>500||trim($reason)===''||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason))throw new DomainException('Zusammenhang mit höchstens fünfhundert Zeichen begründen.',422);
        return $reason;
    }
    private static function json(array $value):string{$prepare=static function(mixed $item)use(&$prepare):mixed{if(!is_array($item))return $item;foreach($item as$key=>$part)$item[$key]=$prepare($part);if(isset($item['record'],$item['mapping'],$item['transactions'],$item['total_cents'])){$item['transactions']=(object)$item['transactions'];foreach(['portions','proofs']as$key)if(isset($item[$key]))$item[$key]=(object)$item[$key];}if(isset($item['record'],$item['transactions'],$item['amounts'],$item['reason']))$item['amounts']=(object)$item['amounts'];return $item;};return json_encode($prepare($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function verified(array $row):array
    {
        if(!hash_equals($row['original_sha256'],hash('sha256',$row['original_json'])))throw new DomainException('Das ursprüngliche Sammelbankoriginal ist nicht unverändert lesbar. Zuerst den gespeicherten Nachweis prüfen.',409);
        $original=json_decode($row['original_json'],true,128,JSON_THROW_ON_ERROR);$link=isset($row['record_version']);$fields=$link?['club_id','id','record_id','record_version','group_id','operation','actor_id','reason','created_at']:['club_id','id','account_id','record_id','total_cents','created_by','created_at'];
        if(!is_array($original)||!is_array($original['identity']??null)||!in_array($original['schema']??null,[1,2],true)||($original['kind']??null)!==($link?'bank-payment-group-link':'bank-payment-group')||array_keys($original['identity'])!==$fields)throw new DomainException('Identität und ursprünglicher Sammelbanknachweis stimmen nicht überein.',409);
        foreach($fields as$field){$expected=$original['identity'][$field];$actual=$row[$field];if(in_array($field,['record_version','total_cents'],true)){$expected=(int)$expected;$actual=(int)$actual;}if($expected!==$actual)throw new DomainException('Identität und ursprünglicher Sammelbanknachweis stimmen nicht überein.',409);}
        return $original;
    }
    /** Pure original validation is also used by finance readers under current physical table locks. */
    public static function verifyOriginal(array $row):array{return self::verified($row);}
    public static function verifyGroup(array $g,array $lines):array
    {
        $original=self::verified($g);$total=0;$seen=[];
        foreach($lines as$line){$old=$original['source']['transactions'][$line['transaction_id']]??null;$part=(int)$line['cents'];$amount=(int)($old['cents']??0);$expected=(int)($original['schema']===2?($original['source']['portions'][$line['transaction_id']]??0):$amount);if(!$old||$old['club_id']!==$g['club_id']||$old['account_id']!==$g['account_id']||!$part||$part!==$expected||($part>0)!==($amount>0)||abs($part)>abs($amount)||(isset($line['actual_cents'])&&((int)$line['actual_cents']!==$amount||$line['actual_account_id']!==$g['account_id'])))throw new DomainException('Teilbewegungen und ursprüngliches Sammelbankoriginal stimmen nicht überein.',409);$total+=(int)$line['cents'];$seen[]=$line['transaction_id'];}
        $ids=array_map('strval',array_keys($original['source']['transactions']??[]));$ordinals=array_map('intval',array_column($lines,'ordinal'));
        if(count($lines)<($original['schema']===2?1:2)||count($lines)>40||$ids!==$seen||$ordinals!==range(1,count($lines))||$total!==(int)$g['total_cents']||(int)($original['source']['record']['cents']??0)!==$total)throw new DomainException('Die vollständige ursprüngliche Summe des Sammelbanknachweises stimmt nicht.',409);
        return $original;
    }
    private function read(callable $work):mixed
    {
        $own=!$this->db->inTransaction();if($own){$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();}
        try{$result=$work();if($own)$this->db->commit();return $result;}catch(\Throwable$error){if($own&&$this->db->inTransaction())$this->db->rollBack();if($error instanceof \PDOException&&in_array((int)($error->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Zusammengehörige Bankzahlungen werden gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$error);throw $error;}
    }
    public function group(string $id,bool $current=false):array
    {
        $a=$this->access();if(!$this->available())throw new DomainException('Gespeicherte Sammelbanknachweise benötigen das geprüfte Paketupgrade.',503);if($current&&!$this->db->inTransaction())throw new DomainException('Aktuellen Sammelbanknachweis gemeinsam prüfen.');$lock=$current?' FOR SHARE NOWAIT':'';
        $g=$this->q('SELECT * FROM bank_payment_groups WHERE club_id=? AND id=?'.$lock,[$a['club_id'],$id])->fetch();if(!$g)throw new DomainException('Eigener Sammelbanknachweis nicht verfügbar.',404);$lines=$this->q('SELECT * FROM bank_payment_group_items WHERE club_id=? AND group_id=? ORDER BY ordinal'.$lock,[$a['club_id'],$id])->fetchAll();$original=self::verifyGroup($g,$lines);
        return ['group'=>$g,'items'=>$lines,'original'=>$original];
    }
    public function first(string $record,int $version,bool $current=false):?array
    {
        $a=$this->access();if(!$this->available())return null;$row=$this->q('SELECT * FROM bank_payment_group_links WHERE club_id=? AND record_id=? AND record_version=?'.($current?' FOR SHARE NOWAIT':''),[$a['club_id'],$record,$version])->fetch();if(!$row)return null;self::verified($row);$this->group($row['group_id'],$current);return $row;
    }
    public function active(string $record,bool $current=false):?array
    {
        $a=$this->access();if(!$this->available())return null;if($current&&!$this->db->inTransaction())throw new DomainException('Aktuellen Sammelbezug gemeinsam prüfen.');$rows=$this->q('SELECT * FROM bank_payment_group_links WHERE club_id=? AND record_id=? AND released_at IS NULL ORDER BY record_version DESC,id LIMIT 2'.($current?' FOR UPDATE NOWAIT':''),[$a['club_id'],$record])->fetchAll();if(!$rows)return null;if(count($rows)!==1)throw new DomainException('Mehrere aktive Sammelbezüge zum selben Bankoriginal. Gespeicherten Stand prüfen.',409);$link=$rows[0];self::verified($link);return $this->group($link['group_id'],$current)+['link'=>$link];
    }
    public function original(string $record,string $link):array
    {
        $a=$this->access();if(!$this->available())throw new DomainException('Sammelbankoriginal nicht verfügbar.',404);$r=$this->q('SELECT * FROM bank_payment_group_links WHERE club_id=? AND record_id=? AND id=?',[$a['club_id'],$record,$link])->fetch();if(!$r)throw new DomainException('Sammelbankoriginal nicht verfügbar.',404);self::verified($r);$this->group($r['group_id']);return $r;
    }
    public function hasProof(string $transaction,bool $current=false):bool
    {
        $a=$this->access();if(!$current)return (bool)$this->q('SELECT evidence.record_id FROM '.BankEvidence::table($this->db).' evidence WHERE evidence.club_id=? AND evidence.transaction_id=? LIMIT 1',[$a['club_id'],$transaction])->fetchColumn();
        if(!$this->db->inTransaction())throw new DomainException('Aktuelle Bankbezüge gemeinsam prüfen.');
        if($this->q('SELECT record_id FROM bank_transaction_sources WHERE club_id=? AND transaction_id=? LIMIT 1 FOR SHARE NOWAIT',[$a['club_id'],$transaction])->fetchColumn())return true;
        return $this->available()&&(bool)$this->q('SELECT binding.id FROM bank_payment_group_items line JOIN bank_payment_group_links binding ON binding.club_id=line.club_id AND binding.group_id=line.group_id WHERE line.club_id=? AND line.transaction_id=? AND binding.released_at IS NULL LIMIT 1 FOR SHARE NOWAIT',[$a['club_id'],$transaction])->fetchColumn();
    }
    public function assertSingle(string $transaction):void
    {
        $a=$this->access();if(!$this->available())return;
        if($this->q('SELECT 1 FROM bank_payment_group_items line JOIN bank_payment_group_links binding ON binding.club_id=line.club_id AND binding.group_id=line.group_id WHERE line.club_id=? AND line.transaction_id=? AND binding.released_at IS NULL LIMIT 1'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''),[$a['club_id'],$transaction])->fetchColumn())throw new DomainException('Diese Bewegung gehört bereits zu einem Sammelbanknachweis. Den vollständigen Zusammenhang prüfen und gegebenenfalls zuerst dessen Bankbezug lösen.',409);
    }
    public function preview(string $record,array $ids,string $reason,string $reuse='',string $duplicate=''):array
    {
        return $this->read(fn()=>$this->reviewLocked($record,$ids,$reason,$reuse,$duplicate));
    }
    private function reviewContextLocked(string $record,string $duplicate):array
    {
        $this->requireWritable();$a=$this->access();
        $r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$record])->fetch();if(!$r)throw new DomainException('Eigene Bankzeile nicht verfügbar.',404);$m=$this->q('SELECT * FROM bank_account_mappings WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$r['mapping_id']])->fetch();
        if(!$m||$r['bank_status']!=='BOOK'||$r['currency']!=='EUR'||!(int)$r['cents']||$r['booked_on']===''||!in_array($r['state'],$duplicate!==''?['imported']:['review'],true))throw new DomainException('Nur einen ungeklärten gebuchten Euro-Banknachweis mit tatsächlichem Datum zusammenführen.',409);
        $this->q('SELECT id FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$m['account_id']]);$f=new Finance($this->db,$this->actor);$account=$f->requireActiveAccount($m['account_id']);if($account['kind']!=='offline')throw new DomainException('Zusammengehörige Umsätze desselben Bankkontos wählen.',422);
        if($r['booked_on']<$account['opened_on']||$r['booked_on']>Finance::today())throw new DomainException('Sammelbanknachweis zwischen dem Anfangsbestand und heute prüfen.',422);
        $duplicateFacts=null;if($duplicate!==''){$proof=$this->q("SELECT * FROM bank_transaction_sources WHERE club_id=? AND record_id=? AND transaction_id=? AND kind='imported' FOR SHARE NOWAIT",[$a['club_id'],$record,$duplicate])->fetch();if(!$proof)throw new DomainException('Die doppelte ursprüngliche Bankübernahme zuerst prüfen.',409);$duplicateFacts=$f->transactionDetail($duplicate,true);if($duplicateFacts['account_id']!==$m['account_id']||(int)$duplicateFacts['cents']!==(int)$r['cents']||$duplicateFacts['reversal_of']||$duplicateFacts['reversed_on']||(int)$duplicateFacts['allocated_cents']!==0)throw new DomainException('Zuerst Belege und Geldtransferbezüge der doppelten Bankübernahme mit Nachweis klären.',409);}
        return ['record'=>$r,'mapping'=>$m,'finance'=>$f,'duplicate'=>$duplicateFacts];
    }
    /** All monetary and prior evidence reads are current under the shared account/movement locks. */
    public function reviewLocked(string $record,array $ids,string $reason,string $reuse='',string $duplicate=''):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Sammelbankzahlungen gemeinsam mit dem aktuellen Bankstand prüfen.');$this->requireWritable();$a=$this->access();$ids=self::ids($ids);self::reason($reason);
        if($reuse!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$reuse))throw new DomainException('Frühere eigene Zusammenfassung erneut auswählen.',422);
        ['record'=>$r,'mapping'=>$m,'finance'=>$f,'duplicate'=>$duplicateFacts]=$this->reviewContextLocked($record,$duplicate);
        $prior=null;if($reuse!==''){$prior=$this->group($reuse,true);$bindings=$this->q('SELECT * FROM bank_payment_group_links WHERE club_id=? AND group_id=? AND released_at IS NULL FOR SHARE NOWAIT',[$a['club_id'],$reuse])->fetchAll();if(!$bindings)throw new DomainException('Früheren Sammelbanknachweis zuerst erneut bewusst zuordnen.',409);foreach($bindings as$binding)self::verified($binding);$expected=array_column($prior['items'],'transaction_id');sort($expected,SORT_STRING);if($ids!==$expected||$prior['group']['account_id']!==$m['account_id']||(int)$prior['group']['total_cents']!==(int)$r['cents'])throw new DomainException('Nur dieselbe vollständige frühere Zusammenfassung mit gleichem Bankbetrag verwenden.',409);}
        if(count($ids)<2)throw new DomainException('Mindestens zwei vorhandene Teilbewegungen auswählen. Einzelzahlungen über die vorhandene Zahlung zuordnen.',422);
        $priorAmounts=$prior?array_column($prior['items'],'cents','transaction_id'):[];$money=[];$total=0;foreach($ids as$id){if($id===$duplicate)throw new DomainException('Die doppelte Bankübernahme darf nicht selbst Teil ihrer tatsächlichen Originalzahlungen sein.',422);$this->q('SELECT id FROM finance_transactions WHERE club_id=? AND id=? FOR SHARE NOWAIT',[$a['club_id'],$id]);$t=$f->transactionDetail($id,true);if($t['account_id']!==$m['account_id']||!BankCounterpayments::actualMovement($this->db,$this->actor,$id,true))throw new DomainException('Nur tatsächliche vorhandene Teilbewegungen auf genau diesem Bankkonto auswählen.',422);if($prior&&(int)$t['cents']!==(int)$priorAmounts[$id])throw new DomainException('Eine frühere Teilbewegung stimmt nicht mehr mit ihrem ursprünglichen Betrag überein.',409);if(!$prior&&$this->hasProof($id,true))throw new DomainException('Eine ausgewählte Bewegung hat bereits einen Banknachweis. Frühere vollständige Zusammenfassung verwenden oder den tatsächlichen Bankbezug zuerst klären.',409);$money[$id]=$t;$total+=(int)$t['cents'];if(!is_int($total))throw new DomainException('Die ausgewählten Teilbeträge überschreiten den prüfbaren Bankbetrag.',422);}
        if($total!==(int)$r['cents'])throw new DomainException('Die Summe der ausgewählten Teilbewegungen muss genau dem Bankbetrag entsprechen. Eingänge und Gebühren behalten ihr jeweiliges Vorzeichen.',422);
        $source=['record'=>$r,'mapping'=>$m,'transactions'=>$money,'total_cents'=>$total,'reuse'=>$prior?['id'=>$prior['group']['id'],'original_sha256'=>$prior['group']['original_sha256']]:null,'duplicate'=>$duplicateFacts];$input=['record'=>$record,'transactions'=>$ids,'reason'=>$reason,'reuse'=>$reuse,'duplicate'=>$duplicate];return ['input'=>$input,'source'=>$source,'hash'=>hash('sha256',self::json(['bank-net-proof-v1',$a['club_id'],$a['id'],$input,$source]))];
    }
    /** Caller owns the record, account and reviewed components; this adds provenance only. */
    public function linkLocked(array $review,string $operation):array
    {
        if(!$this->db->inTransaction())throw new DomainException('Sammelbezug gemeinsam speichern.');$this->requireWritable();$a=$this->access();$source=$review['source'];$r=$source['record'];$format=(int)($review['schema']??1);$now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');$group=$source['reuse']['id']??bin2hex(random_bytes(16));
        if(!$source['reuse']){$identity=['club_id'=>$a['club_id'],'id'=>$group,'account_id'=>$source['mapping']['account_id'],'record_id'=>$r['id'],'total_cents'=>$source['total_cents'],'created_by'=>$a['id'],'created_at'=>$now];$original=self::json(['schema'=>$format,'kind'=>'bank-payment-group','identity'=>$identity,'source'=>$source]);$this->q('INSERT INTO bank_payment_groups VALUES(?,?,?,?,?,?,?,?,?)',[$a['club_id'],$group,$source['mapping']['account_id'],$r['id'],$source['total_cents'],$original,hash('sha256',$original),$a['id'],$now]);$ordinal=0;foreach($source['transactions']as$t)$this->q('INSERT INTO bank_payment_group_items VALUES(?,?,?,?,?)',[$a['club_id'],$group,$t['id'],++$ordinal,$format===2?$source['portions'][$t['id']]:$t['cents']]);}
        $id=bin2hex(random_bytes(16));$identity=['club_id'=>$a['club_id'],'id'=>$id,'record_id'=>$r['id'],'record_version'=>(int)$r['version'],'group_id'=>$group,'operation'=>$operation,'actor_id'=>$a['id'],'reason'=>$review['input']['reason'],'created_at'=>$now];$original=self::json(['schema'=>$format,'kind'=>'bank-payment-group-link','identity'=>$identity,'input'=>$review['input'],'review'=>$review,'group_id'=>$group,'operation'=>$operation]);$this->q('INSERT INTO bank_payment_group_links(club_id,id,record_id,record_version,group_id,operation,actor_id,reason,original_json,original_sha256,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$r['id'],$r['version'],$group,$operation,$a['id'],$review['input']['reason'],$original,hash('sha256',$original),$now]);return $this->active($r['id'],true);
    }
    public function releaseLocked(array $group,string $reason):void
    {
        if(!$this->db->inTransaction())throw new DomainException('Sammelbezug gemeinsam lösen.');$a=$this->access();self::reason($reason);$link=$group['link'];(new Finance($this->db,$this->actor))->assertReturnOrderBankReferenceMutable($link['record_id']);$changed=$this->q('UPDATE bank_payment_group_links SET released_at=?,released_by=?,release_reason=?,version=version+1 WHERE club_id=? AND id=? AND version=1 AND released_at IS NULL',[(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),$a['id'],$reason,$a['club_id'],$link['id']]);if($changed->rowCount()!==1)throw new DomainException('Der Sammelbezug wurde inzwischen bereits geändert. Aktuellen Stand prüfen.',409);
    }
}
