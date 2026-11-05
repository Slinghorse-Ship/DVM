<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;

/** Durable AIS ingestion. No payment initiation. No source ever deletes a local transaction. */
final class BankImport
{
 use BankImportCatalogue, BankImportCorrections, BankImportGroups, BankImportFees;
 public function __construct(private PDO $db,private string $actor){}
 private function access():array{return Permissions::finance($this->db,$this->actor,true);}
 private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 private function tx(callable $work):mixed{$this->db->beginTransaction();try{$this->assertWritableSchema(true);$v=$work();$this->db->commit();return $v;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
 private function assertWritableSchema(bool $current=false):void{$version=(int)$this->q('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1'.($current?' FOR SHARE NOWAIT':''))->fetchColumn();if($version!==Database::SCHEMA_VERSION)throw new DomainException('Bankänderungen benötigen den ausdrücklich gewarteten aktuellen Paketstand. Gespeicherte Originale bleiben lesbar.',503);}
 public static function date(string $value):string{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Gültiges Bankdatum eingeben.');return $value;}
 private static function text(mixed $v,int $max):string{if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||mb_strlen($v,'UTF-8')>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new DomainException('Unvollständige oder zu lange Bankangabe.');return trim($v);}
 private static function key(string $key):void{if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Bankformular neu öffnen.');}
 private static function completedRun(array $run):array{
  if($run['state']!=='complete')throw new DomainException($run['state']==='fetching'?'Der Abschluss dieses Abrufs ist noch nicht bestätigt. Den gespeicherten Bankstand prüfen.':'Dieser ursprüngliche Abruf ist als abgebrochen gespeichert. Den Bankstand vor einem neuen Abruf prüfen.',$run['state']==='fetching'?503:409);
  return $run;
 }
 /** Read the exact known outcome without needing a still-open provider dialog. */
 public function savedRun(string $key,string $provider,string $account):?array{
  self::key($key);return $this->importRead(function()use($key,$provider,$account):array{
   $a=$this->access();$run=$this->q('SELECT * FROM bank_import_runs WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if(!$run)return ['run'=>null];$map=$this->mapping($run['mapping_id']);
   if($run['actor_id']!==$a['id']||$map['provider']!==$provider||$map['account_id']!==$account)throw new DomainException('Dieser gespeicherte Abruf gehört zu einer anderen ursprünglichen Kontowahl oder Person.',409);
   return ['run'=>self::completedRun($run)];
  })['run'];
 }
 private function audit(array $a,string $action,string $id,int $version=1):void{$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],$action,$id,$version,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);}
 public static function identity(array $account):string{
  $iban=$account['account_id']['iban']??$account['iban']??'';
  if(is_string($iban)&&$iban!=='')return hash('sha256','iban:'.Iban::normalize($iban));
  // Alternative hashes are explicitly fuzzy matches in the provider contract.
  $primary=$account['identification_hash']??'';if(!is_string($primary)||$primary==='')throw new DomainException('Die Bank liefert keine beständige Kontoidentität. Bitte Bankverbindung erneut prüfen.');
  return hash('sha256','primary:'.self::text($primary,1000));
 }
 public function mapping(string $id):array{$a=$this->access();$r=$this->q('SELECT * FROM bank_account_mappings WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$r)throw new DomainException('Bankzuordnung nicht verfügbar.');return $r;}
 public function mappings():array{$a=$this->access();return $this->q('SELECT m.*,a.name account_name FROM bank_account_mappings m JOIN finance_accounts a ON a.club_id=m.club_id AND a.id=m.account_id WHERE m.club_id=? ORDER BY a.name',[$a['club_id']])->fetchAll();}
 /** Only server-obtained, currently authorised account metadata may reach this method. */
 public function map(string $provider,string $environment,array $remote,string $accountId):string{
  if($environment!=='PRODUCTION'||!in_array($provider,['enable','fints','paypal'],true))throw new DomainException('Nur produktive Bankkonten können der Buchhaltung zugeordnet werden.');
  return $this->tx(function()use($provider,$remote,$accountId){$a=$this->access();$f=new Finance($this->db,$this->actor);$account=$f->requireActiveAccount($accountId);if($account['kind']!=='offline')throw new DomainException('Ein Bankkonto auswählen, keine Handkasse.');$identity=self::identity($remote);
   $old=$this->q('SELECT id,account_id FROM bank_account_mappings WHERE club_id=? AND provider=? AND identity_hash=?',[$a['club_id'],$provider,$identity])->fetch();if($old){if($old['account_id']!==$accountId)throw new DomainException('Dieses Bankkonto ist bereits einem anderen Vereinskonto zugeordnet. Bestehende Umsätze dürfen nicht umgebucht werden.');return $old['id'];}
   $id=bin2hex(random_bytes(16));$label=self::text((string)($remote['name']??'Bankkonto'),150);$this->q('INSERT INTO bank_account_mappings VALUES(?,?,?,?,?,?,?,?,?)',[$a['club_id'],$id,$provider,'PRODUCTION',$identity,$accountId,$label,$a['id'],(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->audit($a,'bank.account.mapped',$id);return $id;
  });
 }
 /** Normalize the actual Enable Banking contract; transaction_id is NOT a stable identity. */
 public static function normalise(array $row):array{
  $status=self::text($row['status']??'',16);$currency=self::text($row['transaction_amount']['currency']??'',3);
  $amount=self::text($row['transaction_amount']['amount']??'',30);$cents=Finance::cents($amount);$direction=$row['credit_debit_indicator']??'';
  if(!in_array($direction,['CRDT','DBIT'],true))throw new DomainException('Bankantwort ohne eindeutige Zahlungsrichtung.');if($direction==='DBIT')$cents=-$cents;
  $date=self::text($row['booking_date']??'',10);if($date!=='')self::date($date);
  $lines=$row['remittance_information']??[];if(!is_array($lines)||count($lines)>100)throw new DomainException('Bankverwendungszweck prüfen.');foreach($lines as $line)self::text($line,500);
  $description=trim(implode(' ',$lines));$reference=self::text($row['entry_reference']??'',500);$party=self::text($row[$direction==='CRDT'?'debtor':'creditor']['name']??'',200);
  $description=self::text($description,500);if($description==='')$description='Bankumsatz'.($reference!==''?' · '.mb_substr($reference,0,120):'');
  $out=['reference'=>$reference,'bank_status'=>$status,'booked_on'=>$date,'currency'=>$currency,'cents'=>$cents,'counterparty'=>$party,'description'=>$description];
  $out['content_hash']=hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));return $out;
 }
 /** Fetch all pages before making any financial booking. A failed run remains explicit. */
 public function fetch(string $mapping,string $environment,array $remote,string $from,string $to,string $key,callable $pages):array{
  if($this->db->inTransaction())throw new DomainException('Bankabruf benötigt einen eigenen abgeschlossenen Speichervorgang.',409);
  if($environment!=='PRODUCTION')throw new DomainException('Sandboxdaten werden niemals in die Buchhaltung übernommen.');self::date($from);self::date($to);self::key($key);if($from>$to||$to>Finance::today())throw new DomainException('Abrufzeitraum muss spätestens heute enden.');
  $map=$this->mapping($mapping);if(!hash_equals($map['identity_hash'],self::identity($remote)))throw new DomainException('Freigegebenes Bankkonto und Zuordnung stimmen nicht überein.');$a=$this->access();
  $old=$this->q('SELECT * FROM bank_import_runs WHERE club_id=? AND request_key=?',[$a['club_id'],$key])->fetch();if($old){if($old['actor_id']!==$a['id']||$old['mapping_id']!==$mapping||$old['from_date']!==$from||$old['to_date']!==$to)throw new DomainException('Abrufformular bereits anders verwendet.',409);if($this->access()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);return self::completedRun($old);}
  $this->assertWritableSchema();
  $run=bin2hex(random_bytes(16));$this->q("INSERT INTO bank_import_runs(club_id,id,mapping_id,actor_id,request_key,from_date,to_date,state,started_at) VALUES(?,?,?,?,?,?,?,'fetching',?)",[$a['club_id'],$run,$mapping,$a['id'],$key,$from,$to,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);
  $newIds=[];
  try{
   $all=[];$seen=[];$continuation='';$pageCount=0;$payloadBytes=0;
   do{if($this->access()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);if(++$pageCount>100)throw new DomainException('Zu viele Bankseiten. Kleineren Zeitraum wählen.');$page=$pages($continuation);if(!is_array($page)||!is_array($page['transactions']??null))throw new DomainException('Bankantwort unvollständig.');foreach($page['transactions'] as $raw){if(!is_array($raw)||count($all)>=10000)throw new DomainException('Zu viele oder unlesbare Bankumsätze.');$bytes=strlen(json_encode($raw,JSON_THROW_ON_ERROR));$payloadBytes+=$bytes;if($bytes>131072||$payloadBytes>16*1024*1024)throw new DomainException('Bankabruf ist zu umfangreich. Kleineren Zeitraum wählen.');$all[]=['value'=>self::normalise($raw),'raw'=>$raw];}
    $continuation=$page['continuation_key']??'';if($continuation===null)$continuation='';self::text($continuation,4096);if($continuation!==''&&isset($seen[$continuation]))throw new DomainException('Die Bank wiederholt dieselbe Seite. Abruf nicht übernommen.');$seen[$continuation]=true;
   }while($continuation!=='');
   $conflicts=[];$refs=[];foreach($all as $item){$v=$item['value'];if($v['reference']!=='')$refs[$v['reference']][]=$v['content_hash'];}foreach($refs as $ref=>$hashes)if(count(array_unique($hashes))>1)$conflicts[$ref]=self::observationHash($hashes);
   // Compare the latest committed financial evidence while holding its account.
   // An older repeatable-read view must never hide a just-recorded actual refund.
   $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
   $newIds=$this->tx(function()use($all,$map,$a,$run,$conflicts,&$newIds){if($this->access()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$this->q('SELECT id FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$map['account_id']]);$f=new Finance($this->db,$this->actor);$account=$f->requireActiveAccount($map['account_id']);$new=0;$review=0;$ids=[];$occurrences=[];
    foreach($all as $ordinal=>$item){$v=$item['value'];$base=$v['reference']!==''?'ref:'.hash('sha256',$v['reference']):'missing:'.$v['content_hash'];$identity=$base;if($v['reference']==='')$identity.=':'.($occurrences[$base]=($occurrences[$base]??0)+1);
     $old=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND mapping_id=? AND identity=?',[$a['club_id'],$map['id'],$identity])->fetch();$now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
     if(!$old){$id=bin2hex(random_bytes(16));$this->q("INSERT INTO bank_import_records(club_id,id,mapping_id,identity,reference,bank_status,booked_on,currency,cents,counterparty,description,content_hash,state,reason,first_seen,last_seen) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'review','new',?,?)",[$a['club_id'],$id,$map['id'],$identity,$v['reference'],$v['bank_status'],$v['booked_on'],$v['currency'],$v['cents'],$v['counterparty'],$v['description'],$v['content_hash'],$now,$now]);$old=$v+['id'=>$id,'state'=>'review','version'=>1,'reason'=>'new'];++$new;}
     else{$id=$old['id'];$this->q('UPDATE bank_import_records SET last_seen=? WHERE club_id=? AND id=?',[$now,$a['club_id'],$id]);
      if(!hash_equals($old['content_hash'],$v['content_hash'])&&!($old['state']==='ignored'&&$old['reason']==='ack:'.($conflicts[$v['reference']]??$v['content_hash']))){
       if($old['state']==='pending'&&$old['reference']!==''&&$v['bank_status']==='BOOK'){$this->q("UPDATE bank_import_records SET bank_status=?,booked_on=?,currency=?,cents=?,counterparty=?,description=?,content_hash=?,state='review',reason='new',version=version+1 WHERE club_id=? AND id=?",[$v['bank_status'],$v['booked_on'],$v['currency'],$v['cents'],$v['counterparty'],$v['description'],$v['content_hash'],$a['club_id'],$id]);$old=$v+['id'=>$id,'state'=>'review','reason'=>'new','version'=>(int)$old['version']+1];}
       else{$this->q("UPDATE bank_import_records SET state='conflict',reason='changed_bank_record',version=version+1 WHERE club_id=? AND id=?",[$a['club_id'],$id]);$old['state']='conflict';}
      }
     }
     $payload=json_encode($item['raw'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(strlen($payload)>131072)throw new DomainException('Bankumsatz ist zu umfangreich.');$this->q('INSERT INTO bank_import_observations VALUES(?,?,?,?,?,?)',[$a['club_id'],$run,$id,$ordinal,$payload,hash('sha256',$payload)]);
     if(isset($conflicts[$v['reference']])&&!($old['state']==='ignored'&&$old['reason']==='ack:'.$conflicts[$v['reference']])){$this->q("UPDATE bank_import_records SET state='conflict',reason='changed_bank_record' WHERE club_id=? AND id=?",[$a['club_id'],$id]);$old['state']='conflict';}
     if($old['state']==='review'&&$old['reason']==='new'){
      $reason=$v['bank_status']!=='BOOK'?'not_booked':($v['currency']!=='EUR'?'currency':($v['cents']===0?'zero_amount':($v['booked_on']===''?'missing_date':($v['booked_on']<$account['opened_on']?'before_opening':($v['booked_on']>Finance::today()?'future_date':($v['reference']===''?'missing_reference':''))))));
      $candidates=$reason===''?$this->candidatesFor($a['club_id'],$map['account_id'],$v):[];
      if($reason===''){
       if(count($candidates)===1&&$this->sameManual($candidates[0],$v))$this->link($a,$id,$candidates[0]['id'],'reconciled');
       elseif($candidates)$reason='possible_existing_payment';
       elseif($this->possibleGroup($a['club_id'],$map['account_id'],$v))$reason='possible_grouped_payment';elseif($this->possibleShares($a['club_id'],$map['account_id'],$v))$reason='possible_partial_payment';
       else{$tx=$this->book($f,$map,$old);$this->link($a,$id,$tx,'imported');$ids[]=$tx;}
      }
      if($reason!=='')$this->q('UPDATE bank_import_records SET state=?,reason=? WHERE club_id=? AND id=?',[$reason==='not_booked'?'pending':'review',$reason,$a['club_id'],$id]);
     }
    }
    $review=(int)$this->q("SELECT COUNT(DISTINCT r.id) FROM bank_import_records r JOIN bank_import_observations o ON o.club_id=r.club_id AND o.record_id=r.id WHERE o.club_id=? AND o.run_id=? AND r.state IN('review','conflict')",[$a['club_id'],$run])->fetchColumn();
    $this->q("UPDATE bank_import_runs SET state='complete',finished_at=?,rows_count=?,new_count=?,review_count=? WHERE club_id=? AND id=?",[(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),count($all),$new,$review,$a['club_id'],$run]);$this->audit($a,'bank.import.completed',$run);$newIds=$ids;return $ids;
   });
  }catch(\Throwable $e){
   // A thrown COMMIT acknowledgement is not evidence of a rollback. Read the
   // durable outcome before changing a run or asserting that nothing was booked.
   try{if($this->access()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);$stored=$this->q('SELECT * FROM bank_import_runs WHERE club_id=? AND id=?',[$a['club_id'],$run])->fetch();}
   catch(DomainException $denied){throw $denied;}
   catch(\Throwable $unconfirmed){throw new DomainException('Die Bankübernahme ist noch nicht bestätigt. Den gespeicherten Bankstand und denselben Abruf erneut prüfen.',503,$unconfirmed);}
   if(!$stored||$stored['state']!=='complete'){
    try{$this->q("UPDATE bank_import_runs SET state='failed',finished_at=?,error_code='fetch_or_import_failed' WHERE club_id=? AND id=? AND state='fetching'",[(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),$a['club_id'],$run]);}
    catch(\Throwable $unconfirmed){throw new DomainException('Die Bankübernahme ist noch nicht bestätigt. Den gespeicherten Bankstand und denselben Abruf erneut prüfen.',503,$unconfirmed);}
    throw $e instanceof DomainException?$e:new DomainException('Bankabruf abgebrochen. Der gespeicherte Bankstand muss vor einem neuen Abruf geprüft werden.',503,$e);
   }
  }
  foreach($newIds as $id)DonationPreparation::afterTransaction($this->db,$this->actor,$id);
  if($this->access()['club_id']!==$a['club_id'])throw new DomainException('Vereinszugang geändert.',403);
  return $this->q('SELECT * FROM bank_import_runs WHERE club_id=? AND id=?',[$a['club_id'],$run])->fetch();
 }
 private function book(Finance $f,array $map,array $v):string{return $f->createBankTransactionLocked(['account'=>$map['account_id'],'booked_on'=>$v['booked_on'],'direction'=>$v['cents']<0?'expense':'income','amount'=>Finance::decimal(abs((int)$v['cents'])),'counterparty'=>$v['counterparty'],'description'=>$v['description']],substr(hash('sha256','bank:'.$map['club_id'].':'.$v['id']),0,32));}
 private function link(array $a,string $record,string $tx,string $kind):void{$this->q('INSERT INTO bank_transaction_sources VALUES(?,?,?,?,?,?)',[$a['club_id'],$record,$tx,$kind,$a['id'],(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->q("UPDATE bank_import_records SET state='imported',reason='',version=version+1 WHERE club_id=? AND id=?",[$a['club_id'],$record]);$this->audit($a,'bank.transaction.'.$kind,$record);}
 private function candidatesFor(string $club,string $account,array $v):array{
  $proof=BankEvidence::table($this->db);
  // An unconfirmed recorded payment remains plausible even when its manual date differs.
  // Once proved, use the actual bank date to distinguish later equal payments.
  return $this->q("SELECT t.*,EXISTS(SELECT 1 FROM $proof s WHERE s.club_id=t.club_id AND s.transaction_id=t.id) bank_linked FROM finance_transactions t WHERE t.club_id=? AND t.account_id=? AND t.cents=? AND (NOT EXISTS(SELECT 1 FROM $proof previous_proof WHERE previous_proof.club_id=t.club_id AND previous_proof.transaction_id=t.id) OR EXISTS(SELECT 1 FROM $proof previous_proof JOIN bank_import_records previous_record ON previous_record.club_id=previous_proof.club_id AND previous_record.id=previous_proof.record_id WHERE previous_proof.club_id=t.club_id AND previous_proof.transaction_id=t.id AND previous_record.booked_on BETWEEN DATE_SUB(?,INTERVAL 7 DAY) AND DATE_ADD(?,INTERVAL 7 DAY))) AND ".BankCounterpayments::candidateSql($this->db)." ORDER BY t.booked_on,t.id LIMIT 101",[$club,$account,$v['cents'],$v['booked_on'],$v['booked_on']])->fetchAll();}
 private function sameManual(array $tx,array $v):bool{return !$tx['bank_linked']&&$tx['source']==='manual'&&$tx['booked_on']===$v['booked_on']&&$tx['counterparty']===$v['counterparty']&&$tx['description']===$v['description']&&$v['reference']!==''&&str_contains($tx['description'],$v['reference']);}
 private static function observationHash(array $hashes):string{$hashes=array_values(array_unique($hashes));sort($hashes,SORT_STRING);return count($hashes)===1?$hashes[0]:hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR));}
 public function records(string $mapping='',bool $reviewOnly=false):array{$a=$this->access();$sql='SELECT r.*,m.account_id,a.name account_name,s.transaction_id FROM bank_import_records r JOIN bank_account_mappings m ON m.club_id=r.club_id AND m.id=r.mapping_id JOIN finance_accounts a ON a.club_id=m.club_id AND a.id=m.account_id LEFT JOIN bank_transaction_sources s ON s.club_id=r.club_id AND s.record_id=r.id WHERE r.club_id=?';$args=[$a['club_id']];if($mapping!==''){$this->mapping($mapping);$sql.=' AND r.mapping_id=?';$args[]=$mapping;}if($reviewOnly)$sql.=" AND r.state IN('review','conflict')";return $this->q($sql.' ORDER BY r.last_seen DESC,r.id LIMIT 200',$args)->fetchAll();}
 public function record(string $id):array{$a=$this->access();$r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=?',[$a['club_id'],$id])->fetch();if(!$r)throw new DomainException('Bankumsatz nicht verfügbar.');return $r;}
 public function candidates(string $id):array{$r=$this->record($id);$m=$this->mapping($r['mapping_id']);return $this->candidatesFor($m['club_id'],$m['account_id'],$r);}
 /** Verify the stored decision source, including every original in the latest bank response. */
 private function reviewOriginals(array $r):array{
  $canonical=[];foreach(['reference','bank_status','booked_on','currency','cents','counterparty','description'] as $name)$canonical[$name]=$name==='cents'?(int)$r[$name]:$r[$name];
  if(!hash_equals($r['content_hash'],hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR))))throw new DomainException('Die gespeicherten Bankangaben weichen von ihrem ursprünglichen Prüfstand ab.',409);
  $run=$this->q('SELECT o.run_id FROM bank_import_observations o JOIN bank_import_runs b ON b.club_id=o.club_id AND b.id=o.run_id WHERE o.club_id=? AND o.record_id=? ORDER BY b.started_at DESC,b.finished_at DESC,b.id DESC LIMIT 1 FOR SHARE NOWAIT',[$r['club_id'],$r['id']])->fetchColumn();
  if(!$run)throw new DomainException('Ursprüngliche Bankdaten fehlen. Gespeicherten Stand prüfen.',409);
  $hashes=[];foreach($this->q('SELECT payload,sha256 FROM bank_import_observations WHERE club_id=? AND record_id=? AND run_id=? ORDER BY ordinal FOR SHARE NOWAIT',[$r['club_id'],$r['id'],$run]) as $row){
   if(!hash_equals($row['sha256'],hash('sha256',$row['payload'])))throw new DomainException('Die gespeicherten Bankoriginalbytes weichen von ihrer Prüfsumme ab.',409);
   $hashes[]=self::normalise(json_decode($row['payload'],true,128,JSON_THROW_ON_ERROR))['content_hash'];
  }
  if($r['state']!=='conflict'&&!in_array($r['content_hash'],$hashes,true))throw new DomainException('Bankoriginal und gespeicherte Bankangaben stimmen nicht überein.',409);
  return $hashes;
 }
 public function decide(string $id,int $version,string $decision,string $transaction,string $reason):void{
  $new=null;
  try{$this->tx(function()use($id,$version,$decision,$transaction,$reason,&$new){
   $a=$this->access();$reason=self::text($reason,500);
   $r=$this->q('SELECT * FROM bank_import_records WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$id])->fetch();if(!$r)throw new DomainException('Bankumsatz nicht verfügbar.',403);
   $old=$this->q('SELECT * FROM bank_import_decisions WHERE club_id=? AND record_id=? AND record_version=? ORDER BY created_at,id LIMIT 1 FOR SHARE NOWAIT',[$a['club_id'],$id,$version])->fetch();
   if($old){$source=$decision==='existing'?$this->q('SELECT transaction_id FROM bank_transaction_sources WHERE club_id=? AND record_id=? FOR SHARE NOWAIT',[$a['club_id'],$id])->fetchColumn():null;
    if($old['actor_id']!==$a['id']||$old['decision']!==$decision||$old['reason']!==$reason||($decision==='existing'&&$source!==$transaction))throw new DomainException('Dieser Bankprüfstand wurde bereits anders entschieden. Den ursprünglichen Verlauf prüfen.',409);
    return;
   }
   if($reason===''||!in_array($r['state'],['review','conflict'],true)||(int)$r['version']!==$version)throw new DomainException('Unveränderten ungeklärten Bankumsatz und Begründung prüfen.',409);
   $m=$this->mapping($r['mapping_id']);$hashes=$this->reviewOriginals($r);
   if($r['state']==='conflict'){
    if($decision!=='keep')throw new DomainException('Bei einer Bankabweichung kann nur der gespeicherte Stand ausdrücklich beibehalten werden.',422);
    $this->q('UPDATE bank_import_records SET state=?,reason=?,version=version+1 WHERE club_id=? AND id=?',['ignored','ack:'.self::observationHash($hashes),$a['club_id'],$id]);
   }elseif($decision==='ignore')$this->q("UPDATE bank_import_records SET state='ignored',reason=?,version=version+1 WHERE club_id=? AND id=?",[$reason,$a['club_id'],$id]);
   elseif($r['bank_status']!=='BOOK'||$r['currency']!=='EUR'||!$r['cents']||$r['booked_on']==='')throw new DomainException('Nur gebuchte EUR-Umsätze mit Betrag und Buchungsdatum können übernommen werden.',422);
   elseif($decision==='existing'){
    $locked=$this->q('SELECT account_id FROM finance_transactions WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$transaction])->fetchColumn();if(!$locked)throw new DomainException('Vorhandene Zahlung nicht verfügbar.',403);
    $this->q('SELECT id FROM finance_accounts WHERE club_id=? AND id=? FOR UPDATE NOWAIT',[$a['club_id'],$locked])->fetchColumn();
    $t=(new Finance($this->db,$this->actor))->transactionDetail($transaction,true);
    $this->paymentGroups()->assertSingle($transaction);
    if($t['account_id']!==$m['account_id']||(int)$t['cents']!==(int)$r['cents']||!BankCounterpayments::actualMovement($this->db,$this->actor,$t['id'],true))throw new DomainException('Vorhandene tatsächliche Zahlung muss zu Konto, Richtung und Betrag passen.',422);
    $this->link($a,$id,$transaction,'reconciled');
   }elseif($decision==='new'){$new=$this->book(new Finance($this->db,$this->actor),$m,$r);$this->link($a,$id,$new,'imported');}
   else throw new DomainException('Zuordnung auswählen.',422);
   $this->q('INSERT INTO bank_import_decisions VALUES(?,?,?,?,?,?,?,?)',[$a['club_id'],bin2hex(random_bytes(16)),$id,$a['id'],$decision,$reason,$version,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);$this->audit($a,'bank.review.decided',$id,$version+1);
  });}catch(\PDOException $failure){if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Bankzeile oder Zahlung wird gerade bearbeitet. Aktuellen Stand erneut prüfen.',503,$failure);throw $failure;}
  if($new)DonationPreparation::afterTransaction($this->db,$this->actor,$new);
 }
}
