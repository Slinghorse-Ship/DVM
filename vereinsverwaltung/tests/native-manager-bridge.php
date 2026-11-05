<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/Infrastructure/Database/Database.php';

use Kulturhus\Native\Integration\{ManagerBridge,Schema};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Chat\Workspace as Chat;
use Kulturhus\Native\Communication\Workspace as Communication;
use Kulturhus\Verein\Database;

$config=TestSupport::config();
if (($config['label']??'')!=='manager-bridge')throw new RuntimeException('Eigene Manager-Bridge-Fixture erforderlich.');
$db=TestSupport::db();
if (($argv[1]??'')==='--locked-revoke') {
    $db->exec('SET innodb_lock_wait_timeout=1');
    try {Db::run($db,"UPDATE settings_roles SET website_access='{}' WHERE club_id='bridge-a' AND id='writer'");echo 'unexpected';}
    catch(PDOException $e){echo (int)($e->errorInfo[1]??0)===1205?'blocked':'failed';}
    exit;
}
if(!Database::tableExists($db,'schema_migrations'))Database::migrate($db);
Schema::install($db);
\Kulturhus\Native\Cms\Schema::install($db);
\Kulturhus\Native\Chat\Schema::install($db);
\Kulturhus\Native\Communication\Schema::install($db);
$checkCount=0;
$check=function(bool $ok,string $label)use(&$checkCount):void{if(!$ok)throw new RuntimeException('FEHLER: '.$label);$checkCount++;};
$deny=function(callable $action,string $label)use($check):void{try{$action();}catch(DomainException){$check(true,$label);return;}$check(false,$label);};
$areas=['texts','events','gallery','newsletter','social','press'];
$write=json_encode(array_fill_keys($areas,'write'),JSON_THROW_ON_ERROR);
$read=json_encode(array_fill_keys($areas,'read'),JSON_THROW_ON_ERROR);
$partial=json_encode(['texts'=>'write','events'=>'none','gallery'=>'read','newsletter'=>'write','social'=>'none','press'=>'read'],JSON_THROW_ON_ERROR);
foreach(['bridge-a','bridge-b'] as $club)Db::run($db,'INSERT IGNORE INTO clubs(id,name) VALUES(?,?)',[$club,'Synthetischer Brückenverein']);
foreach(['writer'=>$write,'reader'=>$read,'partial'=>$partial,'none'=>'{}'] as $id=>$matrix){
    Db::run($db,"INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version,website_access) VALUES('bridge-a',?,?,?,'none','none',0,1,?) ON DUPLICATE KEY UPDATE website_access=VALUES(website_access)",[$id,'Brücke '.$id,'Synthetisch',$matrix]);
}
$actors=['bridge-admin'=>['members',1,null],'bridge-writer'=>['member',0,'writer'],'bridge-reader'=>['member',0,'reader'],
    'bridge-partial'=>['member',0,'partial'],'bridge-editor'=>['editor',1,null],'bridge-member'=>['member',0,null],
    'bridge-off'=>['member',0,null],'bridge-archived'=>['member',0,null],'bridge-switch'=>['member',0,null]];
Db::run($db,"INSERT IGNORE INTO contacts(club_id,id,kind,name,archived_at) VALUES('bridge-a','archive-contact','person','Archivierte Person','2026-01-01')");
foreach($actors as $id=>[$role,$super,$assignment]){
    Db::run($db,'INSERT INTO actors(id,club_id,role,is_superuser,active) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE club_id=VALUES(club_id),role=VALUES(role),is_superuser=VALUES(is_superuser),active=1',[$id,'bridge-a',$role,$super]);
    if($assignment)Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES(?,'bridge-a',?,1) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)",[$id,$assignment]);
}
Db::run($db,"UPDATE actors SET contact_id='archive-contact' WHERE id='bridge-archived'");
Db::run($db,"UPDATE actors SET active=0 WHERE id='bridge-off'");
Db::run($db,"INSERT IGNORE INTO actors(id,club_id,role) VALUES('bridge-outsider','bridge-b','members')");
Db::run($db,"INSERT INTO processwire_identities(pw_user_id,actor_id,enabled,version) VALUES(891,'bridge-member',1,1) ON DUPLICATE KEY UPDATE enabled=1");
Db::run($db,"INSERT IGNORE INTO native_users(id,club_id,name) VALUES('unrelated-demo','demo','Unberührte Demoperson')");
$db->exec('CREATE TABLE IF NOT EXISTS native_bridge_test_sessions(actor_id VARCHAR(128) PRIMARY KEY,allowed TINYINT NOT NULL)');
Db::run($db,"INSERT INTO native_bridge_test_sessions VALUES('bridge-admin',1) ON DUPLICATE KEY UPDATE allowed=1");
Database::ensureContributionRates($db);

$adminBridge=new ManagerBridge($db,'bridge-admin');$admin=$adminBridge->access();
$writer=(new ManagerBridge($db,'bridge-writer'))->access();$reader=(new ManagerBridge($db,'bridge-reader'))->access();
$editor=(new ManagerBridge($db,'bridge-editor'))->access();$part=(new ManagerBridge($db,'bridge-partial'))->access();
$member=(new ManagerBridge($db,'bridge-member'))->access();$outside=(new ManagerBridge($db,'bridge-outsider'))->access();
$check($adminBridge->managerClub()==='bridge-a','Originale Vereins-ID bleibt für Membership verfügbar');
$check(strlen($admin->user()['id'])===64&&strlen($admin->club())===64,'Getrennte stabile native Kennungen');
$check($admin->club()!==$outside->club(),'Vereinsprojektionen getrennt');
$check($db->query("SELECT name FROM native_users WHERE id='unrelated-demo'")->fetchColumn()==='Unberührte Demoperson','Fremde native Benutzer unverändert');
foreach(['website','newsletter','social','press'] as $module){
    $check($writer->require($module,'publish')['active']===1,'Schreiben umfasst Freigabe '.$module);
    $reader->require($module,'read');$deny(fn()=>$reader->require($module,'edit'),'Lesen verleiht keine Schreibrechte '.$module);
}
$admin->require('website','manage');$deny(fn()=>$writer->require('website','manage'),'Schemaverwaltung benötigt technische Administration');
$editor->require('website','edit');$deny(fn()=>$editor->require('website','manage'),'Editor wird durch Superuserflag kein Techniker');
$deny(fn()=>$editor->require('chat','read'),'Editor erhält keinen Vereinschat');
$member->require('chat','edit');$deny(fn()=>$member->require('chat','manage'),'Chat hat keine globale Moderation durch Managerrolle');
$deny(fn()=>$member->require('website','read'),'Mitglied hat ohne Zuweisung kein CMS');
$deny(fn()=>$part->require('website','read'),'Fehlende Eventrechte öffnen kein gemeinsames CMS');
$part->require('newsletter','manage');$part->require('press','read');$deny(fn()=>$part->require('press','edit'),'Presse behält getrennte Leserechte');
$deny(fn()=>$part->require('social','read'),'Social bleibt getrennt gesperrt');
$deny(fn()=>$admin->require('finance','read'),'Adapter gewährt keine anderen Managerrechte');
$deny(fn()=>new ManagerBridge($db,'bridge-off'),'Inaktiver Actor kann nicht verbunden werden');
$deny(fn()=>new ManagerBridge($db,'unknown'),'Unbekannte Identität abgewiesen');
$ids=array_column($member->directory('chat'),'id');
$check(in_array(ManagerBridge::actorKey('bridge-admin','bridge-a'),$ids,true),'Live Chat-Verzeichnis enthält aktive Vereinsmitglieder');
foreach(['bridge-editor','bridge-off','bridge-archived'] as $id)$check(!in_array(ManagerBridge::actorKey($id,'bridge-a'),$ids,true),'Chatverzeichnis filtert '.$id);
$check(!in_array($outside->user()['id'],$ids,true),'Verzeichnis bleibt vereinsgebunden');

$suffix=bin2hex(random_bytes(5));
$pages=new Pages($db,$writer);$page=$pages->save(['title'=>'Brücke '.$suffix,'slug'=>'bridge-'.$suffix,'template'=>'standard','body'=>'Lokaler Inhalt']);
$check($page['version']===1,'Native Mutation funktioniert ohne äußere verschachtelte Transaktion');
$pages->publish($page['id'],1);$check($pages->publicPage('bridge-'.$suffix)['body']==='Lokaler Inhalt','Publizierter Inhalt über Managerberechtigung');
try{(new Pages($db,$outside))->get($page['id']);$check(false,'CMS verweigert fremde Vereinsinhalte');}
catch(RuntimeException $e){$check($e->getMessage()==='Seite nicht gefunden.','CMS verweigert fremde Vereinsinhalte');}
$work=new Communication($db,$writer,'newsletter');
$edition=$work->save(['title'=>'Brückenausgabe','kind'=>'edition','template'=>'newsletter-standard','body'=>'Lokaler Newsletter']);
$check($edition['version']===1,'Kommunikation verwendet bestehende Managerrollen');

// Reuse this same Access object: a grant copy must never survive revocation.
Db::run($db,"UPDATE settings_roles SET website_access='{}' WHERE club_id='bridge-a' AND id='writer'");
$deny(fn()=>$work->save(['id'=>$edition['id'],'title'=>'Unberechtigte Änderung'],1),'Laufendes Accessobjekt erkennt Rechtewiderruf');
Db::run($db,"INSERT IGNORE INTO native_grants(actor_id,module,capability) VALUES(?,'newsletter','edit')",[$writer->user()['id']]);
$deny(fn()=>$writer->require('newsletter','edit'),'Manipulierte Projektion gewährt keine Rechte');
Db::run($db,"UPDATE settings_roles SET website_access=? WHERE club_id='bridge-a' AND id='writer'",[$write]);

// A second connection changes permissions after this connection established a snapshot.
$other=TestSupport::db();$db->beginTransaction();
$db->query('SELECT COUNT(*) FROM settings_roles')->fetchColumn();
Db::run($other,"UPDATE settings_roles SET website_access='{}' WHERE club_id='bridge-a' AND id='writer'");
$deny(fn()=>$writer->require('website','edit'),'Current Read erkennt Widerruf nach älterem Repeatable-Read-Snapshot');
$db->rollBack();Db::run($db,"UPDATE settings_roles SET website_access=? WHERE club_id='bridge-a' AND id='writer'",[$write]);

$db->beginTransaction();$writer->require('website','edit');
$pipes=[];$process=proc_open([PHP_BINARY,'-d','extension=pdo_mysql',__FILE__,'--locked-revoke'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
$db->rollBack();$check($code===0&&trim($out)==='blocked'&&$err==='','Berechtigungszeilen bleiben bis Transaktionsende gegen konkurrierenden Widerruf gesperrt');

$chat=new Chat($db,$admin);$thread=$chat->create('Vereinschat '.$suffix,[$member->user()['id']]);
$msg=$chat->send($thread['id'],'Eigener Chat',bin2hex(random_bytes(16)));
$check((new Chat($db,$member))->messages($thread['id'])[0]['body']==='Eigener Chat','Eigenständiger Chat funktioniert mit Manageridentitäten');
$deny(fn()=>(new Chat($db,$writer))->get($thread['id']),'Gleicher Verein ersetzt keine Chatteilnahme');
$deny(fn()=>$chat->addPeople($thread['id'],[$outside->user()['id']]),'Fremde Chatteilnehmer abgewiesen');
Db::run($db,"UPDATE processwire_identities SET enabled=0 WHERE actor_id='bridge-member'");
$deny(fn()=>(new Chat($db,$member))->send($thread['id'],'Gesperrt',bin2hex(random_bytes(16))),'Gesperrte Legacy-Anmeldebindung stoppt Chat sofort');
$deny(fn()=>$chat->leave($thread['id']),'Ownertransfer berücksichtigt aktuell gesperrte Mitspieler');
$admin->directory('chat');
$check(!(int)Db::run($db,'SELECT active FROM native_users WHERE id=?',[ManagerBridge::actorKey('bridge-member','bridge-a')])->fetchColumn(),'Gesperrte Projektion deaktiviert');
Db::run($db,"UPDATE processwire_identities SET enabled=1 WHERE actor_id='bridge-member'");

$switch=(new ManagerBridge($db,'bridge-switch'))->access();
Db::run($db,"UPDATE actors SET club_id='bridge-b' WHERE id='bridge-switch'");
$deny(fn()=>$switch->require('chat','read'),'Vereinswechsel benötigt neue Identitätsbindung');
Db::run($db,"UPDATE actors SET club_id='bridge-a' WHERE id='bridge-switch'");

// Native authentication is mandatory, independent of PW tables and PW runtime.
$deny(fn()=>new ManagerBridge($db,'bridge-admin','native'),'Native Modus ohne Sessionprüfung abgewiesen');
$guard=static function(PDO $connection,string $actor):void {
    $allowed=Db::run($connection,'SELECT allowed FROM native_bridge_test_sessions WHERE actor_id=?'.($connection->inTransaction()?' FOR SHARE':''),[$actor])->fetchColumn();
    if(!$allowed)throw new DomainException('Sitzung widerrufen.');
};
$native=(new ManagerBridge($db,'bridge-admin','native',$guard))->access();
putenv('VEREIN_MODE=production');
try{$native->require('website','manage');$check(!class_exists('ProcessWire\\ProcessWire',false),'Native Auth benötigt keine PW-Laufzeit');}
finally{putenv('VEREIN_MODE');}
Db::run($db,"UPDATE native_bridge_test_sessions SET allowed=0 WHERE actor_id='bridge-admin'");
$deny(fn()=>$native->require('website','edit'),'Native Sessionwiderruf wird auch im vorhandenen Accessobjekt geprüft');
Db::run($db,"UPDATE native_bridge_test_sessions SET allowed=1 WHERE actor_id='bridge-admin'");

// Real native authentication resolves back to a manager actor, never a posted id.
\Kulturhus\Native\Auth\Schema::install($db);$auth=new \Kulturhus\Native\Auth\Service($db,random_bytes(32));
$sourceId='bridge-auth-'.bin2hex(random_bytes(5));
Db::run($db,"INSERT INTO actors(id,club_id,role) VALUES(?,'bridge-a','member')",[$sourceId]);
Db::run($db,"INSERT INTO settings_role_assignments(actor_id,club_id,role_id,version) VALUES(?,'bridge-a','writer',1)",[$sourceId]);
$target=(new ManagerBridge($db,$sourceId))->access();$email=$sourceId.'@example.invalid';$password=bin2hex(random_bytes(16));
$invitation=$auth->createInvitation($admin,$target->user()['id'],$email);$auth->acceptInvitation($invitation['token'],$password);
$login=$auth->passwordLogin($email,$password,'bridge-test-'.bin2hex(random_bytes(8)));
$realBridge=ManagerBridge::fromNativeSession($db,$auth,$login['session_token']);$real=$realBridge->access();
$check($realBridge->managerActor()===$sourceId&&$realBridge->managerClub()==='bridge-a','Native Session besitzt verifizierte Rückzuordnung zum bestehenden Vereinsactor');
$realPage=(new Pages($db,$real))->save(['title'=>'Native Anmeldung','slug'=>'native-login-'.bin2hex(random_bytes(5)),'body'=>'Eigenes Konto','template'=>'standard']);
$check($realPage['version']===1,'Native Session kann innerhalb fachlicher Transaktion erneut geprüft werden');
$deny(fn()=>ManagerBridge::fromNativeSession($db,$auth,bin2hex(random_bytes(32))),'Unbekannter Sessiontoken vermittelt keine Identität');
$auth->logout($login['session_token']);$deny(fn()=>$real->require('website','edit'),'Echter Sessionwiderruf sperrt wiederverwendetes ManagerAccess');
$admin->require('accounts','manage');$deny(fn()=>$editor->require('accounts','manage'),'Kontoverwaltung bleibt technische Administration');

echo json_encode(['passed'=>$checkCount,'fixture'=>'manager-bridge','club'=>'bridge-a','manager_actor'=>'bridge-admin','legacy_data_migrated'=>false,'external_requests'=>0],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
