<?php declare(strict_types=1);
/** Real correction originals in the installed synthetic database before encrypted relocation. */
function recoveryBankCorrectionsOriginal(PDO $db):array
{
    if((\Kulturhus\Native\TestSupport::config()['label']??'')!=='native-recovery'||!preg_match('/^kh_native_[a-f0-9]{24}$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Owned bank correction recovery required.');
    $club='own-bank-correction-recovery';$actor=$club.'-admin';$db->exec("INSERT INTO clubs VALUES('own-bank-correction-recovery','Own corrected bank recovery');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-bank-correction-recovery-admin','own-bank-correction-recovery','member',1)");
    $key=static fn()=>bin2hex(random_bytes(16));$finance=new \Kulturhus\Verein\Finance($db,$actor);$service=new \Kulturhus\Verein\BankImport($db,$actor);$bank=$finance->createAccount('Own correction original bank','offline','100','2020-01-01');$remote=['name'=>'Own recovered bank','account_id'=>['iban'=>'DE89370400440532013000']];$mapping=$service->map('fints','PRODUCTION',$remote,$bank);
    $actual=$finance->createTransaction(['account'=>$bank,'booked_on'=>'2020-06-01','direction'=>'expense','amount'=>'18','description'=>'Actual bank expense before duplicate'],$key());
    $records=[];$originals=[];$replays=[];foreach(['duplicate','unlink','reopen'] as $i=>$kind){
        $raw=['entry_reference'=>'','transaction_amount'=>['currency'=>'EUR','amount'=>'18'],'credit_debit_indicator'=>'DBIT','status'=>'BOOK','booking_date'=>'2020-06-01','creditor'=>['name'=>'Own original payee'],'remittance_information'=>['Own recovered bank correction '.$kind.' ä🙂 0']];
        $service->fetch($mapping,'PRODUCTION',$remote,'2020-01-01','2020-12-31',$key(),static fn()=>['transactions'=>[$raw]]);$q=$db->prepare('SELECT id FROM bank_import_records WHERE club_id=? AND description=?');$q->execute([$club,$raw['remittance_information'][0]]);$record=(string)$q->fetchColumn();$records[$kind]=$record;
        $service->decide($record,1,$kind==='duplicate'?'new':($kind==='unlink'?'existing':'ignore'),$kind==='unlink'?$actual:'','Previous original bank decision '.$kind);
        $source=$service->reviewDetail($record)['transaction'];$input=[$record,2,$kind,$kind==='duplicate'?$actual:'',$source?(int)$source['version']:0,$kind==='duplicate'?'2020-06-02':'',str_repeat('🙂',499).'0',$key(),true];$id=$service->correct(...$input);$originals[$kind]=$service->correctionOriginal($record,$id);$replays[$kind]=['input'=>$input,'id'=>$id];
    }
    $refunds=[];foreach(['existing','record'] as $i=>$mode){
        $amount=(string)(71+$i);$entry=$finance->createEntry(['kind'=>'income','category'=>'general','description'=>'Own recovered '.$mode.' actual refund','amount'=>$amount,'document_date'=>'2020-06-01','due_date'=>'2020-06-01'],$key());$finance->recordPayment($entry,1,$bank,'2020-06-01');$target=$mode==='existing'?$finance->createTransaction(['account'=>$bank,'booked_on'=>'2020-06-03','direction'=>'expense','amount'=>$amount,'description'=>'Own already recorded recovered refund'],$key()):'';
        $input=[$entry,(int)$finance->entry($entry)['version'],'2020-06-03',str_repeat('🙂',499).'0',$target,$target!==''?1:0,$finance->reversalSource($entry)['counter_review']];$finance->reverse(...$input);$source=$finance->reversalSource($entry);$counter=$source['counter']['id'];
        $raw=['entry_reference'=>'OWN-RECOVERED-REFUND-'.$mode,'transaction_amount'=>['currency'=>'EUR','amount'=>$amount],'credit_debit_indicator'=>'DBIT','status'=>'BOOK','booking_date'=>'2020-06-03','creditor'=>['name'=>'Own recovered refund payee'],'remittance_information'=>['Own actual recovered '.$mode.' refund bank proof']];$service->fetch($mapping,'PRODUCTION',$remote,'2020-01-01','2020-12-31',$key(),static fn()=>['transactions'=>[$raw]]);$q=$db->prepare('SELECT id FROM bank_import_records WHERE club_id=? AND reference=?');$q->execute([$club,$raw['entry_reference']]);$record=(string)$q->fetchColumn();$service->decide($record,(int)$service->record($record)['version'],'existing',$counter,'Own recovered actual refund evidence compared');
        $refunds[$mode]=['input'=>$input,'source'=>$finance->reversalSource($entry),'record'=>$record,'bank'=>$service->reviewDetail($record)];
    }
    $details=[];foreach($records as $kind=>$record)$details[$kind]=$service->reviewDetail($record);
    return compact('club','actor','bank','actual','records','originals','replays','details','refunds');
}
function recoveryBankCorrectionsSeal(PDO $db,array $original):array
{
    $tables=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)if(in_array('club_id',$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN),true))$tables[]=$table;
    $club=$original['club'];$original['tables']=$tables;$original['business_sha256']=ownOriginalDatabaseHash($db,$tables,static fn(string $table):array=>[' WHERE club_id=?',[$club]]);return $original;
}
function recoveryBankCorrectionsVerify(string $app,string $dsn,array $fixture,array $original):void
{
    $code=<<<'PROOF'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['app'].'/vendor/autoload.php';require $f['app'].'/vereinsverwaltung/native/bootstrap.php';require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';$o=$f['original'];$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$s=new \Kulturhus\Verein\BankImport($db,$o['actor']);$originals=[];$details=[];$replay=[];
foreach($o['records'] as $kind=>$record){$originals[$kind]=$s->correctionOriginal($record,$o['replays'][$kind]['id']);$details[$kind]=$s->reviewDetail($record);$replay[$kind]=$s->correct(...$o['replays'][$kind]['input'])===$o['replays'][$kind]['id'];}
$finance=new \Kulturhus\Verein\Finance($db,$o['actor']);$refunds=[];foreach($o['refunds'] as $mode=>$refund){$finance->reverse(...$refund['input']);$refunds[$mode]=['input'=>$refund['input'],'source'=>$finance->reversalSource($refund['input'][0]),'record'=>$refund['record'],'bank'=>$s->reviewDetail($refund['record'])];}
$club=$o['club'];echo json_encode(compact('originals','details','replay','refunds')+['business_sha256'=>ownOriginalDatabaseHash($db,$o['tables'],static fn(string $table):array=>[' WHERE club_id=?',[$club]]),'unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before,'source'=>(new ReflectionClass(\Kulturhus\Verein\BankImport::class))->getFileName()],JSON_THROW_ON_ERROR);
PROOF;
    $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'original'=>$original],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Restored bank correction proof failed: '.substr($error,0,400));$read=json_decode($out,true,64,JSON_THROW_ON_ERROR);
    recoveryCheck($read['originals']===$original['originals'],'encrypted relocated restore preserves all three exact whole bank correction originals and Unicode reasons');
    recoveryCheck($read['details']===$original['details'],'restored bank originals decisions links and actual opposite financial movement remain exact');
    recoveryCheck($read['refunds']===$original['refunds'],'restored existing and deliberately recorded refunds retain their whole original receipts counters bank proofs and Unicode correction reasons');
    recoveryCheck($read['replay']===['duplicate'=>true,'unlink'=>true,'reopen'=>true]&&$read['unchanged']&&$read['business_sha256']===$original['business_sha256'],'all restored accepted corrections replay without changing any original table cell or adding money');
    recoveryCheck(str_starts_with($read['source'],$app.'/'),'bank correction proof actually loads the independent restored application');
}
