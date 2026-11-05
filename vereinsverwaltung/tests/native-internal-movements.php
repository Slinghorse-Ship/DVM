<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry};
use Kulturhus\Verein\{Finance,FinanceReports,TaxAnnualReport,Database};
$db=TestSupport::db();SchemaRegistry::install($db);$n=0;
$check=static function(bool $v,string $label)use(&$n){if(!$v)throw new RuntimeException($label);++$n;echo "PASS $label\n";};
$deny=static function(callable $fn,string $label)use($check){try{$fn();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);};
$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('internal-own','Own synthetic'),('internal-other','Foreign synthetic');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('internal-admin','internal-own','member',1),('internal-reader','internal-own','member',0),('internal-foreign','internal-other','member',1)");
$f=new Finance($db,'internal-admin');$foreign=new Finance($db,'internal-foreign');
$bank=$f->createAccount('Own bank','offline','1000','2020-01-01');$cash=$f->createAccount('Own cash','cash','100','2020-01-01');$second=$f->createAccount('Second bank','offline','0','2020-01-01');
$tx=static fn($account,$direction,$amount,$date='2020-06-05')=>$f->createTransaction(['account'=>$account,'direction'=>$direction,'amount'=>$amount,'booked_on'=>$date,'description'=>'Synthetic actual movement'],$key());
$link=static function($a,$b,$amount='100',$reason='Cash withdrawal',$request=null)use($f,$key){return $f->linkInternalMovement($a,(int)$f->transactionDetail($a)['version'],$b,(int)$f->transactionDetail($b)['version'],$amount,$reason,$request??$key());};
$out=$tx($bank,'expense','103');$in=$tx($cash,'income','100');$before=$db->query('SELECT id,cents,account_id,booked_on FROM finance_transactions ORDER BY id')->fetchAll();$request=$key();
$id=$link($out,$in,'100','Actual withdrawal',$request);
$check($f->transactionDetail($out)['remaining_cents']===300&&$f->transactionDetail($in)['remaining_cents']===0,'withdrawal matches cash; bank fee remains open');
$check($f->linkInternalMovement($out,1,$in,1,'100','Actual withdrawal',$request)===$id,'lost response retries same original without duplicate');
$check($before===$db->query('SELECT id,cents,account_id,booked_on FROM finance_transactions ORDER BY id')->fetchAll(),'classification preserves original movements and dates');
$check(count($f->transactions(['available'=>true]))===1,'fully assigned own movement leaves open queue');
$deny(fn()=>$link($out,$in,'1'),'already assigned cash cannot be reused');
$deny(fn()=>$link($out,$in,'100','Changed reason',$request),'request key binds exact reason');
$fee=$f->createEntry(['kind'=>'expense','category'=>'office','description'=>'Actual fee','counterparty'=>'Bank','amount'=>'3','document_date'=>'2020-06-05','due_date'=>'2020-06-05'],$key());
$f->allocate($out,(int)$f->transactionDetail($out)['version'],$fee,(int)$f->entry($fee)['version'],'3',$key());
$check($f->entry($fee)['payment_state']==='paid'&&$f->transactionDetail($out)['remaining_cents']===0,'fee alone pays expense; withdrawal pays no invoice');
$reports=new FinanceReports($db,'internal-admin');$range=['from'=>'2020-01-01','to'=>'2020-12-31'];
$check($reports->income($range)['totals']===['income_cents'=>0,'expense_cents'=>300,'result_cents'=>-300],'income report excludes both transfer legs');
$check($f->overview('2020-01-01','2020-12-31')['expense']===300,'manager overview excludes cash withdrawal');
$check($reports->journal($range)['count']===1&&$reports->drilldown($range)['count']===1,'business journal contains fee only');
$check((new TaxAnnualReport($db,'internal-admin'))->build(2020)['totals']['expense_cents']===300,'annual tax preparation excludes own transfers');
$cashOut=$tx($cash,'expense','50');$deposit=$tx($bank,'income','50');$depositId=$link($cashOut,$deposit,'50','Actual deposit');
$check($f->transactionDetail($deposit)['remaining_cents']===0,'cash deposit linked in opposite direction');
$ownOut=$tx($bank,'expense','20');$ownIn=$tx($second,'income','20');$link($ownOut,$ownIn,'20','Own bank transfer');
$check($f->transactionDetail($ownOut)['remaining_cents']===0,'own bank-to-bank transfer supported without a payment order');
$a=$tx($bank,'expense','7');$b=$tx($second,'expense','7');$deny(fn()=>$link($a,$b,'7'),'same direction refused');
$c=$tx($bank,'income','7');$deny(fn()=>$link($a,$c,'7'),'same account refused');
$d=$tx($second,'income','7');$deny(fn()=>$link($a,$d,'8'),'over-allocation refused');$deny(fn()=>$link($a,$d,'0'),'zero refused');
$deny(fn()=>$f->linkInternalMovement($a,99,$d,1,'7','Stale',$key()),'stale version refused');
$other=$foreign->createAccount('Foreign','offline','0','2020-01-01');$foreignTx=$foreign->createTransaction(['account'=>$other,'direction'=>'income','amount'=>'7','booked_on'=>'2020-06-05','description'=>'Foreign'],$key());
$deny(fn()=>$link($a,$foreignTx,'7'),'cross-club link refused');
$deny(fn()=>(new Finance($db,'internal-reader'))->linkInternalMovement($a,1,$d,1,'7','No write',$key()),'read-only actor cannot classify');
$f->releaseInternalMovement($depositId,'Wrong cash account selected');$f->releaseInternalMovement($depositId,'Repeat');
$check($f->transactionDetail($deposit)['remaining_cents']===5000&&count($f->internalMovements($deposit))===1,'release retains both originals and correction history');
$newOut=$tx($bank,'expense','10','2020-07-01');$cashKey=$key();$cashLink=$f->recordCashMovement($newOut,1,$cash,'2020-07-01','10','Actual cash received',$cashKey);$count=count($f->transactions());
$check($f->recordCashMovement($newOut,1,$cash,'2020-07-01','10','Actual cash received',$cashKey)===$cashLink&&count($f->transactions())===$count,'record actual cash counter movement exactly once');
$bad=$tx($bank,'income','9999');$count=count($f->transactions());$deny(fn()=>$f->recordCashMovement($bad,1,$cash,'2020-07-01','9999','Too much cash',$key()),'cash deposit cannot make cash balance negative');
$check(count($f->transactions())===$count,'failed cash operation leaves no partial counter movement');
$db->exec("CREATE TRIGGER internal_fail BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='finance.internal.created' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'; END IF; END");
$rollback=$tx($bank,'expense','5');$count=count($f->transactions());try{$f->recordCashMovement($rollback,1,$cash,'2020-07-01','5','Rollback',$key());throw new RuntimeException('Audit failure accepted');}catch(PDOException){}finally{$db->exec('DROP TRIGGER internal_fail');}
$check(count($f->transactions())===$count&&$f->transactionDetail($rollback)['remaining_cents']===500,'audit failure rolls back cash, link and both versions');
$db->prepare('INSERT INTO cash_closures VALUES(?,?,?,?,?)')->execute([$key(),'internal-own',$cash,'2020-12-31',0]);
$deny(fn()=>$f->releaseInternalMovement($id,'Closed cash period'),'closed cash history protects existing link');
$deny(fn()=>$f->recordCashMovement($rollback,1,$cash,'2020-07-01','5','Closed cash',$key()),'closed cash period rejects a new movement');
Database::migrate($db);$check((int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===Database::SCHEMA_VERSION&&count($f->internalMovements($out))===1,'repeatable migration preserves financial history');
if(getenv('DVM_INTERNAL_MOVEMENTS_BROWSER')==='1'){
    require __DIR__.'/support/free-list-interface-browser-fixture.php';
    $cases=[];foreach(['front-js','front-nojs','direct-js','direct-nojs'] as $case){
        $source=$tx($bank,'expense','12','2021-06-01');$counter=$tx($second,'income','12','2021-06-01');$cashSource=$tx($bank,'expense','13','2021-06-01');
        $cases[$case]=['source'=>$source,'counter'=>$counter,'cash_source'=>$cashSource,'cash'=>$cash];
    }
    $fixture=ownFreeListInterfaceBrowser($db,$cases);
    try{
        $proc=proc_open(['node',__DIR__.'/internal-movements-browser.mjs',$fixture['input']['root'].'/fixture.json'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
        if(!is_resource($proc)||proc_close($proc)!==0){foreach(['front','direct'] as $app)echo substr((string)file_get_contents($fixture['input']['root'].'/'.$app.'.log'),-5000);throw new RuntimeException('Internal movements browser failed.');}
        foreach($cases as $case){$check($f->transactionDetail($case['source'])['remaining_cents']===1200,'browser correction retains original bank movement');$check($f->transactionDetail($case['cash_source'])['remaining_cents']===0,'browser records and classifies actual cash');}
    }finally{foreach($fixture['servers'] as [$server,$log]){proc_terminate($server);proc_close($server);fclose($log);}}
}
echo json_encode(['suite'=>'native-internal-movements','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
