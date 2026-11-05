<?php declare(strict_types=1);
/** Complete immutable originals; helper is used only inside the owned recovery suite. */
function recoveryDayOriginal(PDO $db):array{
 $db->exec("INSERT INTO clubs VALUES('owned-recovery-day','Owned internal daily originals');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('owned-recovery-day-admin','owned-recovery-day','treasurer',1)");
 $settings=array_replace(\Kulturhus\Verein\SettingsSchema::defaults('tax'),['accounting_basis'=>'balance','fiscal_start'=>'2025-01-01']);
 $db->prepare("INSERT INTO club_settings(club_id,section,payload,version,updated_at) VALUES('owned-recovery-day','tax',?,1,UTC_TIMESTAMP())")->execute([json_encode($settings,JSON_THROW_ON_ERROR)]);
 $old=[];foreach(['VEREIN_MODE'=>'production','VEREIN_BALANCE_WORKSPACE'=>'1','VEREIN_BALANCE_PERIODS'=>'1']as$key=>$value){$old[$key]=getenv($key);putenv($key.'='.$value);}
 try{
  $ledger=new \Kulturhus\Verein\BalancePeriodLedger($db,'owned-recovery-day-admin');$id=$ledger->create(['start'=>'2025-01-01','end'=>'2025-06-15'],'Own preserved internal daily definition ä🙂',true,true);
  $ledger->setup($id,1,[['account'=>'cash','debit_cents'=>100000,'credit_cents'=>0,'sphere'=>''],['account'=>'equity','debit_cents'=>0,'credit_cents'=>100000,'sphere'=>'']],'Own exact original opening',true);$r=$ledger->report($id);
  $ledger->close($id,$r['version'],$r['journal_sha256'],['complete'=>true,'valuation'=>true,'cutoff'=>true,'tax'=>true],'Own retained internal board review');$first=$ledger->report($id);
  $next=$ledger->create(['start'=>'2025-06-16','end'=>'2025-12-31'],'Own confirmed original daily carry',true);$second=$ledger->report($next);$rows=[];
  foreach(['balance_periods','balance_period_events']as$table)$rows[$table]=$db->query("SELECT * FROM ".$table." WHERE club_id='owned-recovery-day' ORDER BY 1,2,3")->fetchAll();
  return ['first'=>$first,'second'=>$second,'rows'=>$rows];
 }finally{foreach($old as$key=>$value)putenv($key.($value===false?'':'='.$value));}
}
function recoveryDayVerify(PDO $restored,string $app,string $dsn,array $fixture,array $original):void{
 $rows=[];foreach(array_keys($original['rows'])as$table)$rows[$table]=$restored->query("SELECT * FROM ".$table." WHERE club_id='owned-recovery-day' ORDER BY 1,2,3")->fetchAll();
 recoveryCheck($rows===$original['rows'],'encrypted relocation preserves every daily period definition, reviewed policy, original event and confirmed carry byte exactly');
 $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);require $f['app'].'/vereinsverwaltung/src/bootstrap.php';$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$l=new \Kulturhus\Verein\BalancePeriodLedger($db,'owned-recovery-day-admin');$first=$l->report('20250101-20250615');$second=$l->report('20250616-20251231');$blocked=false;try{\Kulturhus\Verein\BalancePeriodDayAllocation::assertTaxQualified($second);}catch(DomainException){$blocked=true;}$plan=['basis_cents'=>120001,'residual_cents'=>0,'months'=>60,'acquired_on'=>'2025-09-18','method'=>'linear_monthly','account'=>'06300'];$a=\Kulturhus\Verein\BalancePeriodDayAllocation::linear($plan,['start'=>'2025-09-01','end'=>'2025-09-23']);$b=\Kulturhus\Verein\BalancePeriodDayAllocation::linear($plan,['start'=>'2025-09-24','end'=>'2025-09-30'],$a);echo json_encode(['first'=>$first,'second'=>$second,'blocked'=>$blocked,'charges'=>[$a['depreciation_cents'],$b['depreciation_cents']],'calculator'=>(new ReflectionClass(\Kulturhus\Verein\BalancePeriodDayAllocation::class))->getFileName()],JSON_THROW_ON_ERROR);
PHP;
 $p=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);if(!is_resource($p))throw new RuntimeException('Relocated day runtime unavailable.');fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password']],JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Relocated day replay failed: '.substr($error,0,900));$proof=json_decode($out,true,64,JSON_THROW_ON_ERROR);
 recoveryCheck($proof['first']===$original['first']&&$proof['second']===$original['second'],'independent restored application replays both complete original day journals and their exact confirmed predecessor');
 recoveryCheck($proof['blocked']&&$proof['charges']===[923,1077]&&str_starts_with($proof['calculator'],$app.'/vereinsverwaltung/src/Finance/Balance/'),'actual relocated day calculator retains original monthly cents and its explicit tax qualification boundary');
}
