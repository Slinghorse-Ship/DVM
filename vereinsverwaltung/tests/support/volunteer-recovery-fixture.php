<?php declare(strict_types=1);
/** Personal certificate originals in an independent synthetic club, never the installer account. */
function recoveryVolunteerOriginal(PDO $db):array
{
 $db->exec("INSERT INTO clubs VALUES('own-volunteer-recovery','Own synthetic volunteer recovery');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-volunteer-recovery-admin','own-volunteer-recovery','members',1),('own-volunteer-recovery-reader','own-volunteer-recovery','member',0)");
 $registry=new \Kulturhus\Verein\Registry($db,'own-volunteer-recovery-admin');
 $person=$registry->saveContact(null,0,'person','Own First Original Member','own-recovery@example.invalid',"Own original address\n00000 Own city");
 $chair=$registry->saveContact(null,0,'person','Own Original Recovery Chair','','');
 $registry->addMembership($person,'0','aktiv','2020-01-01');
 \Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$person,'own-volunteer-recovery-reader']);
 \Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$chair,'own-volunteer-recovery-admin']);
 (new \Kulturhus\Verein\AdmissionVotes($db,'own-volunteer-recovery-admin'))->grant('own-volunteer-recovery-admin',0,'1. Vorstand',true,true);
 (new \Kulturhus\Verein\Settings($db,'own-volunteer-recovery-admin'))->save('club',array_replace(\Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own original recovery club','street'=>'Own street 1','postcode'=>'00000','city'=>'Own original city']),0);
 $service=new \Kulturhus\Verein\VolunteerForm($db,'own-volunteer-recovery-reader','general');
 $input=['first_name'=>'Own','last_name'=>'First Original Member','birthday'=>'','street'=>'','city'=>'','activity'=>"0\r\nOwn complete original recovery activity ä🙂",'effort'=>'0'];
 $data=$service->build($person,$input,false);$hash=\Kulturhus\Verein\VolunteerForm::digest($data);$preview=\Kulturhus\Verein\DocumentPreview::pdf($data);$request=bin2hex(random_bytes(16));$document=$service->save($person,$input,false,$request,$hash);
 $registry->saveContact($person,1,'person','Own Later Current Member','own-recovery@example.invalid','Own later current address');
 return ['actor'=>'own-volunteer-recovery-reader','admin'=>'own-volunteer-recovery-admin','person'=>$person,'key'=>$request,'hash'=>$hash,'input'=>$input,'original'=>$data,'receipt'=>$service->confirmation($request),'document'=>$document,'preview_sha256'=>hash('sha256',$preview)];
}
function recoveryVolunteerVerify(PDO $db,string $app,string $dsn,array $fixture,array $original):void
{
 $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/src/People/Members/VolunteerFormState.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';
$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$o=$f['original'];
$service=new \Kulturhus\Verein\VolunteerForm($db,$o['actor'],'general');$receipt=$service->confirmation($o['key']);$file=$service->file($o['document']);$same=$service->save($o['person'],$o['input'],false,$o['key'],$o['hash']);
$state=\Kulturhus\Verein\VolunteerFormState::prepare($db,$o['actor'],['volunteer_kind'=>'general','volunteer_receipt'=>$o['key']],[],[]);
$foreign=false;try{(new \Kulturhus\Verein\VolunteerForm($db,$o['admin'],'general'))->confirmation($o['key']);}catch(DomainException){$foreign=true;}
$paths=[];foreach([\Kulturhus\Verein\VolunteerForm::class,\Kulturhus\Verein\VolunteerFormReads::class,\Kulturhus\Verein\VolunteerFormState::class]as$class)$paths[]=(new ReflectionClass($class))->getFileName();
echo json_encode(['receipt'=>$receipt,'same_document'=>$same,'file_sha256'=>hash('sha256',$file['bytes']),'prepared_receipt'=>$state['receipt'],'current_person_name'=>$state['prefill']['person']['name'],'foreign_creator_refused'=>$foreign,'paths'=>$paths,'unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before],JSON_THROW_ON_ERROR);
PHP;
 $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);
 if(!is_resource($process))throw new RuntimeException('Actual recovered volunteer runtime unavailable.');
 fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'original'=>$original],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($process)!==0)throw new RuntimeException('Actual recovered volunteer original failed: '.substr($error,0,900));
 $proof=json_decode($output,true,64,JSON_THROW_ON_ERROR);
 recoveryCheck($proof['receipt']===$original['receipt']&&$proof['prepared_receipt']===$original['receipt']&&$proof['current_person_name']==='Own Later Current Member','independent restored certificate reads its complete first original confirmation separately from the later current profile');
 recoveryCheck($proof['same_document']===$original['document']&&$proof['file_sha256']===$original['preview_sha256'],'actual recovered same-request retry retains the first reviewed PDF bytes without rendering or filing another original');
 recoveryCheck($proof['foreign_creator_refused']&&$proof['unchanged'],'restored certificate confirmation preserves its creating account boundary and changes no complete restored business row');
 recoveryCheck(count(array_filter($proof['paths'],static fn(string $path):bool=>str_starts_with($path,$app.'/vereinsverwaltung/src/People/Members/')))===3,'all three private certificate helpers execute only from the actually restored package');
}
