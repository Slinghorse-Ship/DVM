<?php declare(strict_types=1);
/** Filled role originals are read in a separate recovered package, never reconstructed. */
function recoveryRolesOriginal(PDO $db):array
{
    $db->exec("INSERT INTO clubs VALUES('own-roles-recovery','Own original roles ä🙂');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-roles-recovery-admin','own-roles-recovery','members',1),('own-roles-recovery-target','own-roles-recovery','member',0)");
    $service=new \Kulturhus\Verein\Settings\RoleRequests($db,'own-roles-recovery-admin');$data=['name'=>str_repeat('ä',100),'description'=>str_repeat('🙂',1000),'settings_access'=>'0','website_custom'=>'1','website_levels'=>array_fill_keys(array_keys(\Kulturhus\Verein\EditorialPolicy::AREAS),'none')];foreach(\Kulturhus\Verein\Settings\RoleInput::RIGHTS as$field)$data[$field]=$field==='members_access'?'read':'none';
    $receipts=[];$accept=static function(string $operation,array $input)use($service,&$receipts):array{$review=$service->prepare($operation,$input);$requestKey=bin2hex(random_bytes(16));$receipt=$service->accept($operation,$input,$requestKey,$review['hash'],true);$receipts[]=$receipt;return$receipt;};
    $role=$accept('role-save',['id'=>'','version'=>'0','data'=>$data]);$roleId=$role['result']['object_id'];
    $accept('role-assign',['user'=>'own-roles-recovery-target','version'=>'0','role'=>$roleId]);
    $accept('board-grant',['user'=>'own-roles-recovery-target','version'=>'0','office'=>'2. Vorstand','enabled'=>'1']);
    $accept('role-save',['id'=>$roleId,'version'=>'1','data'=>array_replace($data,['description'=>'0'])]);
    $state=(new \Kulturhus\Verein\Web\RoleState($db,'own-roles-recovery-admin'))->prepare(['edit'=>$roleId,'role_user'=>'own-roles-recovery-target','role_receipt'=>$receipts[0]['key']]);
    if($state['http_status']!==0||$state['selected_role']['description']!=='0')throw new RuntimeException('Filled role recovery source unavailable.');
    return['receipts'=>$receipts,'state'=>$state,'role_id'=>$roleId];
}

function recoveryRolesVerify(PDO $db,string $app,string $dsn,array $fixture,array $original):void
{
    $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['app'].'/vereinsverwaltung/native/bootstrap.php';require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';
$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$service=new \Kulturhus\Verein\Settings\RoleRequests($db,'own-roles-recovery-admin');$receipts=[];$same=true;
foreach($f['original']['receipts']as$offered){$read=$service->receipt($offered['key']);$receipts[]=$read;$same=$same&&$service->accept($read['operation'],$read['input'],$read['key'],json_decode($read['original_json'],true,64,JSON_THROW_ON_ERROR)['review']['hash'],true)===$read;}
$state=(new \Kulturhus\Verein\Web\RoleState($db,'own-roles-recovery-admin'))->prepare(['edit'=>$f['original']['role_id'],'role_user'=>'own-roles-recovery-target','role_receipt'=>$receipts[0]['key']]);$paths=[];foreach([\Kulturhus\Verein\Settings\RoleRequests::class,\Kulturhus\Verein\Web\RoleState::class,\Kulturhus\Verein\RoleCatalogue::class]as$class)$paths[]=(new ReflectionClass($class))->getFileName();echo json_encode(['receipts'=>$receipts,'state'=>$state,'same'=>$same,'unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before,'paths'=>$paths],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
PHP;
    $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);if(!is_resource($process))throw new RuntimeException('Independent recovered role process unavailable.');fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'original'=>$original],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Recovered role originals failed: '.substr($error,0,900));$proof=json_decode($output,true,64,JSON_THROW_ON_ERROR);
    recoveryCheck($proof['receipts']===$original['receipts']&&$proof['same'],'independent encrypted relocation retains all first role assignment personal board and later role originals byte-for-byte without repeated acceptance');
    recoveryCheck($proof['state']===$original['state'],'recovered role workspace keeps current version two literal zero separate personal office and exact earlier full Unicode original');
    recoveryCheck($proof['unchanged']&&count(array_filter($proof['paths'],static fn(string $path):bool=>str_starts_with($path,$app.'/vereinsverwaltung/src/')))===3,'independent role helpers execute only from the recovered package and preserve every complete restored business row');
}
