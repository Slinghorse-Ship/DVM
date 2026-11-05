<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Shared\Db};
use Kulturhus\Verein\{Inventory,Registry,Database};
$db=TestSupport::db();
if(($argv[1]??'')==='--race'){
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$inventory=new Inventory($db,$input['actor']);while(microtime(true)<$input['start'])usleep(1000);
    try{$id=$inventory->reserve($input['payload'],$input['key']);echo json_encode(['saved'=>$id]);}catch(DomainException){echo json_encode(['blocked'=>true]);}exit;
}
Kulturhus\Native\Installer\SchemaRegistry::install($db);
Db::run($db,"INSERT INTO clubs VALUES('own-catalog-a','Own timed loans A'),('own-catalog-b','Own timed loans B')");
foreach(['own-price-admin'=>'own-catalog-a','own-race-peer'=>'own-catalog-a','own-price-other'=>'own-catalog-b']as$id=>$club)Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'members',1)",[$id,$club]);
$inventory=new Inventory($db,'own-price-admin');$foreign=new Inventory($db,'own-price-other');$registry=new Registry($db,'own-price-admin');$person=$registry->saveContact(null,0,'person','Own timed borrower','timed@example.invalid','');$key=static fn()=>bin2hex(random_bytes(16));$n=0;
$check=static function(bool$ok,string$label)use(&$n):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$n;echo 'PASS '.$label."\n";};
$deny=static function(callable$run,string$label)use($check):void{try{$run();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Accepted '.$label);};
$location=$inventory->saveLocation('',0,['name'=>'Own timed warehouse'],$key());
$makeItem=static function(string$code,int$quantity)use($inventory,$location,$key):string{$id=$inventory->saveItem('',0,['code'=>$code,'name'=>'Own '.$code,'home_location'=>$location],$key());$inventory->move($id,(int)$inventory->item($id)['stock_version'],['kind'=>'receive','quantity'=>(string)$quantity,'location'=>$location,'reason'=>'Own synthetic receipt'],$key());return$id;};
$item=$makeItem('TIMED-ORIGINAL',2);
$oldItem=$makeItem('TIMED-LEGACY',1);$old=$inventory->reserve(['contact'=>$person,'purpose'=>'Own complete legacy original','starts_on'=>'2020-01-01','due_on'=>'2020-01-02','lines'=>[['item'=>$oldItem,'location'=>$location,'quantity'=>'1','stock_version'=>(string)$inventory->item($oldItem)['stock_version']]]],$key());
$oldColumns=array_values(array_diff(Database::columns($db,'inventory_loans'),['window_start','window_end']));$oldQuery='SELECT `'.implode('`,`',$oldColumns).'` FROM inventory_loans WHERE id=?';$oldValues=Db::run($db,$oldQuery,[$old])->fetch();
$db->exec('ALTER TABLE inventory_loans DROP CHECK kh113_loan_window');$db->exec('ALTER TABLE inventory_loans DROP COLUMN window_start,DROP COLUMN window_end');Db::run($db,'DELETE FROM schema_migrations WHERE version>=113');
$check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===112&&!array_intersect(['window_start','window_end'],Database::columns($db,'inventory_loans')),'isolated upgrade fixture really starts at112 without timed-loan columns');
Database::migrate($db);Database::migrate($db);$inventory=new Inventory($db,'own-price-admin');$migrated=$inventory->loan($old);
$check(Db::run($db,$oldQuery,[$old])->fetch()===$oldValues&&array_key_exists('window_start',$migrated)&&array_key_exists('window_end',$migrated)&&$migrated['window_start']===null&&$migrated['window_end']===null,'actual repeated schema-112 upgrade preserves every legacy loan column without inventing an interval');
$payload=static function(string$item,string$start,string$end,int$quantity=2)use($inventory,$person,$location):array{return['reservation_mode'=>'window','contact'=>$person,'purpose'=>'Own exact timed use','window_start'=>$start,'window_end'=>$end,'notes'=>'Own unchanged complete agreement ä🙂','lines'=>[['item'=>$item,'location'=>$location,'quantity'=>(string)$quantity,'stock_version'=>(string)$inventory->item($item)['stock_version']]]];};
$physical=ownOriginalDatabaseHash($db,['inventory_items','inventory_stock','inventory_movements','finance_entries','finance_transactions']);
$oneData=$payload($item,'2028-06-01T09:00','2028-06-01T10:00');$oneKey=$key();$one=$inventory->reserve($oneData,$oneKey);$two=$inventory->reserve($payload($item,'2028-06-01T10:00','2028-06-01T11:00'),$key());
$check($one!==$two&&$inventory->loan($one)['window_start']==='2028-06-01 07:00:00'&&$inventory->loan($one)['due_on']==='2028-06-01','exact Berlin window is stored as UTC and retains legacy calendar dates');
$check($inventory->reserve($oneData,$oneKey)===$one&&count($inventory->loans('planned'))===2,'lost acknowledgement replays the same immutable time-window intention');
$deny(fn()=>$inventory->reserve(array_replace($oneData,['notes'=>'Changed original']),$oneKey),'changed replay cannot replace the accepted agreement');
$deny(fn()=>$inventory->reserve($payload($item,'2028-06-01T09:59','2028-06-01T10:01',1),$key()),'actual interval overlap cannot exceed the pooled quantity');
$check(ownOriginalDatabaseHash($db,['inventory_items','inventory_stock','inventory_movements','finance_entries','finance_transactions'])===$physical,'future planning and rejected overlap change no physical ledger price item or financial original');
$immediate=['contact'=>$person,'purpose'=>'Own immediate conflict','starts_on'=>Inventory::today(),'due_on'=>Inventory::today(),'lines'=>$oneData['lines']];
$deny(fn()=>$inventory->reserve($immediate,$key()),'indefinite immediate hold cannot silently consume promised future window stock');
$deny(fn()=>$inventory->transition($one,1,'issue',['date'=>'2028-06-01','reason'=>'Own premature issue','current_place'=>'Own room'],$key()),'future physical issue is not pretended');
$before=ownOriginalDatabaseHash($db,['inventory_loans','inventory_loan_lines','inventory_events','audit_events']);
$deny(fn()=>$inventory->transition($one,1,'reschedule',['window_start'=>'2028-06-01T09:30','window_end'=>'2028-06-01T10:30','reason'=>'Own conflicting shift'],$key()),'conflicting reschedule preserves original planned interval');
$check(ownOriginalDatabaseHash($db,['inventory_loans','inventory_loan_lines','inventory_events','audit_events'])===$before,'failed reschedule rolls back every proposed event and original timestamp');
$inventory->transition($one,1,'reschedule',['window_start'=>'2028-06-01T08:00','window_end'=>'2028-06-01T09:00','reason'=>'Own conscious earlier interval'],$key());
$deny(fn()=>$inventory->transition($one,1,'reschedule',['window_start'=>'2028-06-01T07:00','window_end'=>'2028-06-01T08:00','reason'=>'Own stale shift'],$key()),'stale shift cannot overwrite a newer confirmed interval');
$cancel=$key();$cancelData=['date'=>Inventory::today(),'reason'=>'Own deliberate cancellation'];$inventory->transition($one,2,'cancel',$cancelData,$cancel);$inventory->transition($one,2,'cancel',$cancelData,$cancel);
$check($inventory->loan($one)['state']==='cancelled'&&$inventory->stock($item,$location)[0]['reserved']===0,'timed cancellation and its HTTP replay never release another physical hold');
$gap=$inventory->reserve($payload($item,'2028-06-01T08:00','2028-06-01T09:00'),$key());
$peakItem=$makeItem('TIMED-PEAK',3);$inventory->reserve($payload($peakItem,'2028-07-01T09:00','2028-07-01T10:00'),$key());$inventory->reserve($payload($peakItem,'2028-07-01T10:00','2028-07-01T11:00'),$key());
$peak=$inventory->reserve($payload($peakItem,'2028-07-01T09:00','2028-07-01T11:00',1),$key());$check($inventory->loan($peak)['state']==='reserved','availability uses simultaneous peak rather than adding disjoint bookings');
foreach([['2028-06-01T10:00','2028-06-01T10:00'],['2028-06-01T11:00','2028-06-01T10:00'],['2028-03-26T02:30','2028-03-26T04:00'],['2028-10-29T02:30','2028-10-29T04:00'],['2028-02-30T09:00','2028-03-01T10:00']]as[$from,$to])$deny(fn()=>$inventory->reserve($payload($item,$from,$to),$key()),'invalid zero reversed or DST-ambiguous interval rejected '.$from);
$deny(fn()=>$foreign->reserve($payload($item,'2028-06-02T08:00','2028-06-02T09:00'),$key()),'foreign club cannot plan another original contact or stock');
$reviewData=['window_start'=>'2028-06-01T08:00','window_end'=>'2028-06-01T09:00','lines'=>[['stock'=>$item.':'.$location.':'.$inventory->item($item)['stock_version'],'quantity'=>'1']]];$allTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$original=ownOriginalDatabaseHash($db,$allTables);$review=$inventory->reviewLoanWindow($reviewData);
$check($review['rows'][0]['available']===0&&!$review['rows'][0]['fits']&&ownOriginalDatabaseHash($db,$allTables)===$original,'on-demand current availability preview is bounded and read-only');
$liveItem=$makeItem('TIMED-PHYSICAL',2);$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));$start=$now->modify('-5 minutes')->format('Y-m-d\TH:i');$end=$now->modify('+1 hour')->format('Y-m-d\TH:i');$live=$inventory->reserve($payload($liveItem,$start,$end),$key());$line=$inventory->loan($live)['lines'][0];
$issue=['date'=>Inventory::today(),'reason'=>'Own actual handover','current_place'=>'Own event room'];$issueKey=$key();$inventory->transition($live,1,'issue',$issue,$issueKey);$inventory->transition($live,1,'issue',$issue,$issueKey);
$check($inventory->stock($liveItem,$location)[0]['lent']===2&&$inventory->stock($liveItem,$location)[0]['shelf']===0,'actual issue and replay move exactly one physical quantity without an immediate reserve delta');
$later=$now->modify('+1 hour')->format('Y-m-d\TH:i');$latest=$now->modify('+2 hours')->format('Y-m-d\TH:i');$after=$inventory->reserve($payload($liveItem,$later,$latest),$key());
$deny(fn()=>$inventory->transition($live,2,'extend',['window_end'=>$latest,'reason'=>'Own conflicting extension'],$key()),'extension cannot overlap another confirmed future use');
$inventory->returnLoan($live,2,['date'=>Inventory::today(),'reason'=>'Own good and damaged return','lines'=>[['line'=>$line['id'],'good'=>'1','damaged'=>'1','lost'=>'0','location'=>$location]]],$key());
$stock=$inventory->stock($liveItem,$location)[0];$check($stock['shelf']===1&&$stock['blocked']===1&&$stock['lent']===0,'real damage remains recordable even when it creates a future planning shortage');
$check(!$inventory->loanWindowStatus($after)[0]['fits'],'existing future agreement exposes a current shortage after actual damage');
$deny(fn()=>$inventory->reserve($payload($liveItem,$later,$latest,1),$key()),'known damaged capacity cannot accept another promise');
$overdueItem=$makeItem('TIMED-OVERDUE',1);$overdue=$inventory->reserve($payload($overdueItem,$start,$end,1),$key());$inventory->transition($overdue,1,'issue',$issue,$key());
// Only the isolated fixture clock is represented by its deliberately overdue original timestamp.
Db::run($db,'UPDATE inventory_loans SET window_start=?,window_end=? WHERE club_id=? AND id=?',[gmdate('Y-m-d H:i:s',time()-7200),gmdate('Y-m-d H:i:s',time()-3600),'own-catalog-a',$overdue]);
$deny(fn()=>$inventory->reserve($payload($overdueItem,$later,$latest,1),$key()),'actual overdue material remains unavailable after its planned end until returned');
$check(Inventory::loanOverdue($inventory->loan($overdue))&&in_array($overdue,array_column($inventory->loans('overdue'),'id'),true),'timed overdue filter follows the exact UTC end on the same local day');
$raceItem=$makeItem('TIMED-RACE',1);$racePayload=$payload($raceItem,'2028-08-01T10:00','2028-08-01T12:00',1);$children=[];$raceStart=microtime(true)+.4;
for($i=0;$i<2;$i++){$process=proc_open([...TestSupport::phpCommand(),__FILE__,'--race'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['start'=>$raceStart,'payload'=>$racePayload,'key'=>$key(),'actor'=>$i===0?'own-price-admin':'own-race-peer']));fclose($pipes[0]);$children[]=[$process,$pipes];}
$outcomes=[];foreach($children as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Actual race child failed '.$errors);$outcomes[]=json_decode($output,true,16,JSON_THROW_ON_ERROR);}
$check(count(array_filter($outcomes,static fn($r)=>isset($r['saved'])))===1&&count(array_filter($outcomes,static fn($r)=>isset($r['blocked'])))===1,'two actual concurrent native PDO workers cannot overbook the same remaining unit');
$calendar=(new Kulturhus\Verein\Events\Calendar\LoanSource($inventory))->rows('2028-06-01','2028-06-02');$check(count(array_filter($calendar,static fn($r)=>$r['id']==='loan:'.$two.':window'&&!$r['all_day']&&$r['end_known']&&$r['starts_at']==='2028-06-01T08:00:00Z'&&$r['ends_at']==='2028-06-01T09:00:00Z'))===1,'canonical calendar exposes actual UTC interval rather than a copied all-day event');
$midnight=$inventory->reserve($payload($item,'2028-06-02T23:00','2028-06-03T00:00'),$key());$nextDay=(new Kulturhus\Verein\Events\Calendar\LoanSource($inventory))->rows('2028-06-03','2028-06-04');$check(!in_array('loan:'.$midnight.':window',array_column($nextDay,'id'),true),'midnight end respects the exclusive next-day calendar boundary');
$csv=$inventory->export('loans',['state'=>'planned']);$check(str_contains($csv,'Europe/Berlin')&&str_contains($csv,'01.06.2028 10:00')&&str_contains($csv,'Geplant'),'actual full loan CSV retains precise local window times and planning status');
if(getenv('DVM_INVENTORY_LOAN_WINDOWS_BROWSER')==='1'){
    require __DIR__.'/support/catalog-pricing-browser-fixture.php';$browser=ownCatalogPricingBrowser($db,['item'=>$item,'location'=>$location,'person'=>$person,'shortage'=>$after]);
    Db::run($db,"UPDATE settings_roles SET inventory_access='read',loans_access='read' WHERE id='own-price-reader'");
    $original=ownOriginalDatabaseHash($db,['contacts','inventory_stock','finance_transactions','finance_entries']);
    try{$process=proc_open(['node',__DIR__.'/inventory-loan-windows-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual timed loan browser failed');$check(ownOriginalDatabaseHash($db,['contacts','inventory_stock','finance_transactions','finance_entries'])===$original,'actual planning browser never mutates original contacts physical stock or finances');}
    finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
}
echo json_encode(['suite'=>'native-inventory-loan-windows','checks'=>$n,'external_actions'=>0])."\n";
