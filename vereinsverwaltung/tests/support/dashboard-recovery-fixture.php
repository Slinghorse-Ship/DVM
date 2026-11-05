<?php declare(strict_types=1);
/** First club acknowledgement and current permitted entry, restored without re-acceptance. */
function recoveryDashboardOriginal(PDO $db):array
{
 $db->exec("INSERT INTO clubs VALUES('own-dashboard-recovery','Ünicode original dashboard ä🙂');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-dashboard-recovery-admin','own-dashboard-recovery','members',1),('own-dashboard-recovery-member','own-dashboard-recovery','member',0)");
 require_once dirname(__DIR__,2).'/src/Web/DashboardState.php';
 $actor='own-dashboard-recovery-admin';$registry=new \Kulturhus\Verein\Registry($db,$actor);$person=$registry->saveContact(null,0,'person','Finanzübersicht original human ä🙂','own-dashboard-recovery@example.invalid','Own exact original place',['birthday'=>'2000-'.date('m-d')]);$registry->addMembership($person,'aktiv','aktiv','2020-01-01');\Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$person,'own-dashboard-recovery-member']);
 $day=(new DateTimeImmutable('today',new DateTimeZone('Europe/Berlin')))->modify('+2 days')->format('Y-m-d');$registry->saveAppointment(null,0,['title'=>'Own literal dashboard original ä🙂','start'=>$day.'T18:00','end'=>$day.'T20:00','location'=>'Own original room','description'=>str_repeat('Own private original unselected body ',100),'category'=>'verein'],[]);
 (new \Kulturhus\Verein\Finance($db,$actor))->createAccount('Own exact original 7.23','offline','7.23','2020-01-01');
 $intro=new \Kulturhus\Verein\MembershipApplications($db,'own-dashboard-recovery',$actor);$intro->completeIntro();$ack=$intro->introAcknowledgement();$intro->completeIntro();
 $rows=[];foreach(['setup_acknowledgements','audit_events']as$table)$rows[$table]=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM '.$table.' WHERE club_id=? ORDER BY 1,2',['own-dashboard-recovery'])->fetchAll();
 $state=(new \Kulturhus\Verein\Web\DashboardState($db,$actor))->prepare(['setup'=>'setup','period'=>'3']);if($state['http_status']!==0)throw new RuntimeException('Original recovery dashboard unavailable.');
 return ['ack'=>$ack,'rows'=>$rows,'state'=>$state,'personal'=>(new \Kulturhus\Verein\Web\DashboardState($db,'own-dashboard-recovery-member'))->prepare([])];
}
function recoveryDashboardVerify(PDO $db,string $app,string $dsn,array $fixture,array $original):void
{
 $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/src/Web/DashboardState.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';
$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$intro=new \Kulturhus\Verein\MembershipApplications($db,'own-dashboard-recovery','own-dashboard-recovery-admin');$ack=$intro->introAcknowledgement();$intro->completeIntro();
$rows=[];foreach(['setup_acknowledgements','audit_events']as$table)$rows[$table]=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM '.$table.' WHERE club_id=? ORDER BY 1,2',['own-dashboard-recovery'])->fetchAll();$paths=[];foreach([\Kulturhus\Verein\Web\DashboardState::class,\Kulturhus\Verein\Web\DashboardSetup::class,\Kulturhus\Verein\MembershipApplications::class]as$class)$paths[]=(new ReflectionClass($class))->getFileName();
echo json_encode(['ack'=>$ack,'rows'=>$rows,'state'=>(new \Kulturhus\Verein\Web\DashboardState($db,'own-dashboard-recovery-admin'))->prepare(['setup'=>'setup','period'=>'3']),'personal'=>(new \Kulturhus\Verein\Web\DashboardState($db,'own-dashboard-recovery-member'))->prepare([]),'unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before,'paths'=>$paths],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
PHP;
 $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);if(!is_resource($process))throw new RuntimeException('Independent recovered dashboard unavailable.');fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password']],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Recovered dashboard failed: '.substr($error,0,900));$proof=json_decode($output,true,64,JSON_THROW_ON_ERROR);
 recoveryCheck($proof['ack']===$original['ack']&&$proof['rows']===$original['rows'],'independent encrypted relocation retains the exact first introduction account timestamp and audit without replacing or accepting it again');
 recoveryCheck($proof['state']===$original['state']&&$proof['personal']===$original['personal'],'recovered native general and personal dashboards retain their current exact permitted figures setup state and original metadata');
 recoveryCheck($proof['unchanged']&&count(array_filter($proof['paths'],static fn(string $path):bool=>str_starts_with($path,$app.'/vereinsverwaltung/src/')))===3,'restored dashboard helpers execute only from the recovered package and preserve every complete business original');
}
