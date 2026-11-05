<?php declare(strict_types=1);
/** Synthetic nonempty original, first receipt and exact PDF for actual encrypted relocation. */
function recoveryEvidenceOriginal(PDO $db,string $actor):array
{
 $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',"<< /Length 0 >>\nstream\n\nendstream"];
 $bytes="%PDF-1.4\n";$offsets=[];foreach($objects as$i=>$object){$offsets[]=strlen($bytes);$bytes.=($i+1)." 0 obj\n".$object."\nendobj\n";}$xref=strlen($bytes);$bytes.="xref\n0 5\n0000000000 65535 f \n";foreach($offsets as$offset)$bytes.=sprintf("%010d 00000 n \n",$offset);$bytes.="trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
 $service=new \Kulturhus\Verein\DocumentChecks($db,$actor,new \Kulturhus\Verein\Registry($db,$actor));$request=bin2hex(random_bytes(16));
 $input=array_replace(\Kulturhus\Verein\DocumentChecks::defaults(),['title'=>str_repeat('🙂',300),'responsible'=>'0','notes'=>"0\r\nOwn exact original recovery policy ä🙂",'review_on'=>'2099-06-01']);
 $id=$service->createOnce($input,$request,['name'=>'Own exact original policy.pdf','bytes'=>$bytes]);$receipt=$service->creationReceipt($request);$first=$service->get($id);
 $service->save($id,1,array_replace($input,['title'=>'Own later deliberately reviewed recovery policy']));
 return ['actor'=>$actor,'request'=>$request,'id'=>$id,'first'=>$first,'current'=>$service->get($id),'receipt'=>$receipt,'bytes_sha256'=>hash('sha256',$bytes),'receipt_row'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM document_check_creations WHERE request_key=?',[$request])->fetch()];
}
function recoveryEvidenceVerify(PDO $db,string $app,string $dsn,array $fixture,array $original):void
{
 $receipt=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM document_check_creations WHERE request_key=?',[$original['request']])->fetch();
 recoveryCheck($receipt===$original['receipt_row'],'encrypted relocation preserves the entire first evidence intention and original receipt byte exactly');
 $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';
$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);
$registry=new \Kulturhus\Verein\Registry($db,$f['actor']);$checks=new \Kulturhus\Verein\DocumentChecks($db,$f['actor'],$registry);$docs=new \Kulturhus\Verein\Documents($db,$f['actor']);$current=$checks->get($f['id']);$receipt=$checks->creationReceipt($f['request']);
$state=\Kulturhus\Verein\Documents\DocumentCheckState::prepare($db,$checks,$docs,$registry,['check_receipt'=>$f['request']],[],'');
$frame=['check_complete'=>'1','id'=>$f['id'],'version'=>'1','request_key'=>$f['request'],'check_actor'=>$f['actor'],'check_club'=>$receipt['original']['club_id']];foreach(\Kulturhus\Verein\DocumentChecks::defaults()as$name=>$value)if(!in_array($name,['id','version'],true))$frame[$name]=(string)$receipt['original'][$name];$prepared=\Kulturhus\Verein\Documents\DocumentCheckForm::read($frame);
$paths=[];foreach([\Kulturhus\Verein\DocumentChecks::class,\Kulturhus\Verein\DocumentCheckCreations::class,\Kulturhus\Verein\Documents\DocumentCheckState::class,\Kulturhus\Verein\Documents\DocumentCheckForm::class]as$class)$paths[]=(new ReflectionClass($class))->getFileName();
echo json_encode(['current'=>$current,'receipt'=>$receipt,'state_receipt'=>$state['receipt'],'frame_version'=>$prepared['version'],'frame_data'=>$prepared['data'],'file_sha256'=>hash('sha256',$docs->file($receipt['original']['document_id'],1)['bytes']),'paths'=>$paths,'unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before,'schema'=>\Kulturhus\Verein\Database::SCHEMA_VERSION,'database_schema'=>(int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()],JSON_THROW_ON_ERROR);
PHP;
 $p=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);if(!is_resource($p))throw new RuntimeException('Restored evidence runtime unavailable.');
 fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password']]+$original,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Restored evidence proof failed: '.substr($error,0,900));$proof=json_decode($out,true,64,JSON_THROW_ON_ERROR);
 recoveryCheck($proof['receipt']===$original['receipt']&&$proof['state_receipt']===$original['receipt']&&$proof['current']===$original['current']&&$proof['receipt']['original']===$original['first'],'independent restored evidence interface reads the exact first original separately from its deliberately later current record');
 recoveryCheck($proof['frame_version']===1&&$proof['frame_data']['title']===$original['first']['title']&&$proof['frame_data']['notes']===$original['first']['notes']&&$proof['file_sha256']===$original['bytes_sha256'],'actual recovered native adapter preserves the reviewed earlier version full Unicode CRLF zero and every original PDF byte');
 recoveryCheck($proof['schema']===\Kulturhus\Verein\Database::SCHEMA_VERSION&&$proof['database_schema']===\Kulturhus\Verein\Database::SCHEMA_VERSION&&$proof['unchanged']&&count(array_filter($proof['paths'],static fn(string $path):bool=>str_starts_with($path,$app.'/vereinsverwaltung/src/Documents/')))===4,'all four independent evidence helpers come from the actually restored application and change no restored business row');
}
