<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
ini_set('zend.exception_ignore_args','1');
require_once dirname(__DIR__).'/native/bootstrap.php';require_once dirname(__DIR__).'/src/Infrastructure/Database/Database.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();if(!in_array($c['label']??'',['integrated-manager','workflow-manager'],true))throw new RuntimeException('Eigene integrierte Manager-Fixture erforderlich.');
$db=TestSupport::db();
if(!Kulturhus\Verein\Database::tableExists($db,'schema_migrations'))Kulturhus\Verein\Database::migrate($db);
foreach([Kulturhus\Native\Integration\Schema::class,Kulturhus\Native\Cms\Schema::class,Kulturhus\Native\Chat\Schema::class,Kulturhus\Native\Communication\Schema::class,Kulturhus\Native\Auth\Schema::class] as $schema)$schema::install($db);
$runtime=dirname((string)getenv('KH_NATIVE_TEST_CONFIG')).'/'.$c['label'].'-runtime';
foreach(['','/sessions','/media','/chat-files'] as $sub)if(!is_dir($runtime.$sub)&&!mkdir($runtime.$sub,0700,true))throw new RuntimeException('Eigener privater Speicher fehlt.');
$keyPath=$runtime.'/auth.key';
foreach(['auth.key','private.key'] as $keyName){
    $fixtureKey=$runtime.'/'.$keyName;
    if(!file_exists($fixtureKey)&&!is_link($fixtureKey)){$handle=fopen($fixtureKey,'x');if(!$handle)throw new RuntimeException('Schlüsseldatei bereits belegt.');try{if(fwrite($handle,random_bytes(32))!==32)throw new RuntimeException('Schlüssel nicht vollständig gespeichert.');}finally{fclose($handle);}chmod($fixtureKey,0600);}
    if(is_link($fixtureKey)||!is_file($fixtureKey)||(fileperms($fixtureKey)&0077)||strlen((string)file_get_contents($fixtureKey))!==32)throw new RuntimeException('Eigener privater Fixture-Schlüssel nicht sicher gespeichert.');
}
putenv('KH_NATIVE_AUTH_KEY_FILE='.$keyPath);$auth=Kulturhus\Native\Auth\Runtime::service($db);
foreach(['manager-demo','manager-other'] as $club)Db::run($db,'INSERT IGNORE INTO clubs(id,name) VALUES(?,?)',[$club,'Kulturhus · eigener Integrationstest']);
$fixtures=['admin'=>['manager-demo','members',1,'Jan Beispiel'],'member'=>['manager-demo','member',0,'Lea Beispiel'],'editor'=>['manager-demo','editor',0,'Mara Redaktion'],'outsider'=>['manager-other','members',1,'Anderer Verein']];
foreach($fixtures as $key=>[$club,$role,$super,$name]){
    Db::run($db,"INSERT IGNORE INTO contacts(club_id,id,kind,name,email) VALUES(?,?,'person',?,?)",[$club,'manager-'.$key,$name,$key.'@example.invalid']);
    Db::run($db,'INSERT IGNORE INTO actors(id,club_id,role,is_superuser,contact_id) VALUES(?,?,?,?,?)',['manager-'.$key,$club,$role,$super,'manager-'.$key]);
}
$bootstrapGuard=static function():void{if(PHP_SAPI!=='cli')throw new DomainException('Nur explizite lokale Fixture-Einrichtung.');};
$bridge=new ManagerBridge($db,'manager-admin','native',$bootstrapGuard);$access=$bridge->access();
$other=(new ManagerBridge($db,'manager-outsider','native',$bootstrapGuard))->access();
Db::run($db,"INSERT IGNORE INTO native_users(id,club_id,name) VALUES('manager-public-reader',?,'Öffentliche Website')",[$access->club()]);
Db::run($db,"INSERT IGNORE INTO native_grants(actor_id,module,capability) VALUES('manager-public-reader','website','read')");
$credentialsPath=$runtime.'/browser-credentials.json';$credentials=is_file($credentialsPath)?json_decode(file_get_contents($credentialsPath),true,512,JSON_THROW_ON_ERROR):[];
foreach($fixtures as $key=>[$club]){
    if(isset($credentials[$key]))continue;
    $email='manager-'.$key.'@example.invalid';$password=bin2hex(random_bytes(16)).'Ab7!';
    $invitation=$auth->createInvitation($key==='outsider'?$other:$access,ManagerBridge::actorKey('manager-'.$key,$club),$email);
    $auth->acceptInvitation($invitation['token'],$password);$credentials[$key]=['email'=>$email,'password'=>$password];
    file_put_contents($credentialsPath,json_encode($credentials,JSON_THROW_ON_ERROR));
}
file_put_contents($runtime.'/REVIEW-ZUGANG.txt',"Ausschließlich lokale synthetische Testkonten\n\nWebsite: http://127.0.0.1:8111/\nAnmeldung: http://127.0.0.1:8111/login/\nVereinsmanager: http://127.0.0.1:8111/vereinsverwaltung/\n\nAdministration\nE-Mail: ".$credentials['admin']['email']."\nTestpasswort: ".$credentials['admin']['password']."\n\nMitglied (nur eigener Chat/Profil)\nE-Mail: ".$credentials['member']['email']."\nTestpasswort: ".$credentials['member']['password']."\n\nDiese Daten dürfen nicht für echte Konten verwendet werden. Kein Mailversand.\n");
Kulturhus\Verein\Database::ensureContributionRates($db);
if($c['label']==='workflow-manager'){
    $reviewPath=$runtime.'/REVIEW-ZUGANG.txt';
    file_put_contents($reviewPath,str_replace('http://127.0.0.1:8111','http://127.0.0.1:8118',(string)file_get_contents($reviewPath)));
}
$pages=new Kulturhus\Native\Cms\Pages($db,$access);
if(!$pages->list()){
    $page=$pages->save(['title'=>'Willkommen im Kulturhus','slug'=>'start','template'=>'standard','body'=>'Lokaler Manager und eigene Konten.']);$pages->publish($page['id'],1);
}
if(!array_filter($pages->list(),static fn(array $p):bool=>$p['template']==='news')){$post=$pages->save(['title'=>'Was uns bewegt','slug'=>'aktuelles/was-uns-bewegt','template'=>'news','nav_visible'=>false,'news_date'=>'2026-10-08','body'=>'Dieser synthetische Beitrag wird im Vereinsmanager gepflegt.']);$pages->publish($post['id'],1);}
foreach($pages->list() as $existing)if(in_array($existing['slug'],['was-uns-bewegt','aktuelles/was-uns-bewegt'],true)&&($existing['slug']==='was-uns-bewegt'||$existing['nav_visible'])){$existing['slug']='aktuelles/was-uns-bewegt';$existing['nav_visible']=false;$updated=$pages->save($existing,$existing['version']);$pages->publish($updated['id'],$updated['version']);}
foreach(['aktuelles'=>['Aktuelles','standard'],'galerie'=>['Galerie','gallery'],'mitglied-werden'=>['Mitglied werden','standard']] as $slug=>[$title,$template])if(!$pages->publicPage($slug)){
    $anchor=$pages->save(['title'=>$title,'slug'=>$slug,'template'=>$template,'body'=>'Lokaler Integrationstest mit synthetischen Daten.']);$pages->publish($anchor['id'],1);
}
$chat=new Kulturhus\Native\Chat\Workspace($db,$access);
if(!$chat->list())$chat->create('Kultur gemeinsam planen',[ManagerBridge::actorKey('manager-member','manager-demo')]);
echo 'Integrierte Manager-Fixture vorbereitet: Port '.($c['label']==='workflow-manager'?'8118':'8111').", Verein manager-demo. Private Zugangsdaten ausschließlich in der Fixturedatei.\n";
