<?php declare(strict_types=1);
/** Real filled settings originals are read from a separately restored package. */
function recoveryGeneralSettingsOriginal(PDO $db):array
{
    $db->exec("INSERT INTO clubs VALUES('own-general-recovery','Own settings originals ä🙂');INSERT INTO actors(id,club_id,role,is_superuser)VALUES('own-general-recovery-admin','own-general-recovery','members',1)");
    $requests=new \Kulturhus\Verein\Settings\GeneralRequests($db,'own-general-recovery-admin');$settings=new \Kulturhus\Verein\Settings($db,'own-general-recovery-admin');$originals=[];
    foreach(['club','salutations','fields','club']as$index=>$section){
        $state=$settings->section($section);$data=$state['data'];
        if($section==='club'){$data=\Kulturhus\Verein\SettingsSchema::defaults('club');$data['name']=str_repeat('ä',180);$data['alternate']=$index===0?str_repeat('🙂',180):'0';}
        elseif($section==='salutations')$data=['mode'=>'formal','titles'=>str_repeat('🙂',2000),'rows'=>[['name'=>str_repeat('ä',60),'personal'=>str_repeat('🙂',300),'formal'=>'0 {{NACHNAME}}']]];
        else $data=['rows'=>[['id'=>bin2hex(random_bytes(16)),'name'=>str_repeat('ä',100),'type'=>'select','options'=>str_repeat('🙂',1000),'required'=>'0']]];
        $input=['version'=>(string)$state['version'],'data'=>$data];$plan=$requests->prepare($section,$input);$requestKey=bin2hex(random_bytes(16));$originals[]=$requests->accept($section,$input,$requestKey,$plan['hash'],true);
    }
    $current=[];foreach(['club','salutations','fields']as$section)$current[$section]=$settings->section($section);
    return['originals'=>$originals,'current'=>$current,'first_club_revision'=>$requests->revision('club','1')];
}
function recoveryGeneralSettingsVerify(string $app,string $dsn,array $fixture,array $original):void
{
    $code=<<<'PHP'
ini_set('zend.exception_ignore_args','1');$f=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);require $f['app'].'/vereinsverwaltung/native/bootstrap.php';require $f['app'].'/vereinsverwaltung/src/bootstrap.php';require $f['app'].'/vereinsverwaltung/tests/support/original-database-hash.php';$db=new PDO($f['dsn'],$f['username'],$f['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$requests=new \Kulturhus\Verein\Settings\GeneralRequests($db,'own-general-recovery-admin');$settings=new \Kulturhus\Verein\Settings($db,'own-general-recovery-admin');$originals=[];$same=true;foreach($f['original']['originals']as$offered){$read=$requests->receipt($offered['section'],$offered['key']);$originals[]=$read;$same=$same&&$requests->accept($read['section'],$read['input'],$read['key'],$read['review']['hash'],true)===$read;}$current=[];foreach(['club','salutations','fields']as$section)$current[$section]=$settings->section($section);$paths=[];foreach([\Kulturhus\Verein\Settings\GeneralRequests::class,\Kulturhus\Verein\Settings\GeneralValues::class,\Kulturhus\Verein\Settings::class]as$class)$paths[]=(new ReflectionClass($class))->getFileName();echo json_encode(['originals'=>$originals,'current'=>$current,'first_club_revision'=>$requests->revision('club','1'),'same'=>$same,'unchanged'=>ownOriginalDatabaseHash($db,$tables)===$before,'paths'=>$paths],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
PHP;
    $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$app);if(!is_resource($process))throw new RuntimeException('Independent recovered settings process unavailable.');fwrite($pipes[0],json_encode(['app'=>$app,'dsn'=>$dsn,'username'=>$fixture['username'],'password'=>$fixture['password'],'original'=>$original],JSON_THROW_ON_ERROR));fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Recovered settings originals failed: '.substr($error,0,900));$proof=json_decode($output,true,64,JSON_THROW_ON_ERROR);
    recoveryCheck($proof['originals']===$original['originals']&&$proof['same'],'independent encrypted relocation retains exact first club salutation custom-field and later settings originals without repeated acceptance');
    recoveryCheck($proof['current']===$original['current']&&$proof['first_club_revision']===$original['first_club_revision'],'recovered canonical settings retain current version two literal zero full Unicode and the distinct first saved revision bytes');
    recoveryCheck($proof['unchanged']&&count(array_filter($proof['paths'],static fn(string $path):bool=>str_starts_with($path,$app.'/vereinsverwaltung/src/')))===3,'independent settings helpers run from the recovered package and retain every complete restored business row');
}
