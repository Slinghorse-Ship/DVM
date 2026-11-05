<?php declare(strict_types=1);
require_once dirname(__DIR__).'/native/bootstrap.php';
require_once dirname(__DIR__).'/src/autoload.php';
require_once __DIR__.'/support/original-database-hash.php';

use Kulturhus\Native\Auth\{Schema as AuthSchema,Service,SessionAccess};
use Kulturhus\Native\Cms\{Pages,PublicationSchedule};
use Kulturhus\Native\Communication\Workspace;
use Kulturhus\Native\Integration\{ManagerBridge,Schema as IntegrationSchema};
use Kulturhus\Native\Shared\{Access,Authority,Db};
use Kulturhus\Native\TestSupport;
use Kulturhus\Verein\{Database,Settings};

// TestSupport rejects every non-disposable/non-local DSN before any write.
$config=TestSupport::config();
if(PHP_SAPI!=='cli'||($config['label']??'')!=='native-session-authority'||($config['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Own isolated session-authority fixture required.');
$db=TestSupport::db();Database::migrate($db);IntegrationSchema::install($db);AuthSchema::install($db);
\Kulturhus\Native\Cms\Schema::install($db);\Kulturhus\Native\Communication\Schema::install($db);\Kulturhus\Native\Chat\Schema::install($db);
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;};
$deny=static function(callable $call,string $label)use($check):void{try{$call();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};

// Preserve every pre-existing complete row, including originals in tables to which
// this fixture adds its own new identities, role revisions and draft records.
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$keys=[];$quote=static fn(string $s):string=>'`'.str_replace('`','``',$s).'`';
foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);if(!$columns)throw new RuntimeException('Original primary key required.');$rows=$db->query('SELECT '.implode(',',array_map($quote,$columns)).' FROM '.$quote($table))->fetchAll(PDO::FETCH_NUM);$keys[$table]=['columns'=>$columns,'rows'=>$rows];}
$originalFilter=static function(string $table)use($keys,$quote):array{$key=$keys[$table];if(!$key['rows'])return[' WHERE 0=1',[]];$arguments=[];foreach($key['rows']as$row)array_push($arguments,...$row);return[' WHERE ('.implode(',',array_map($quote,$key['columns'])).') IN ('.implode(',',array_fill(0,count($key['rows']),'('.implode(',',array_fill(0,count($key['columns']),'?')).')')).')',$arguments];};
$originalHash=ownOriginalDatabaseHash($db,$tables,$originalFilter);

$run='own-session-'.bin2hex(random_bytes(5));$club=$run.'-club';$foreign=$run.'-other';
Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?),(?,?)',[$club,'Own live session club',$foreign,'Own other session club']);
foreach(['admin','writer','peer','switch']as$kind)Db::run($db,'INSERT INTO actors(id,club_id,role,is_superuser,active) VALUES(?,?,?,?,1)',[$run.'-'.$kind,$club,$kind==='admin'?'members':'member',$kind==='admin'?1:0]);
Db::run($db,'INSERT INTO actors(id,club_id,role,is_superuser,active) VALUES(?,?,?,0,1)',[$run.'-foreign',$foreign,'member']);
$settings=new Settings($db,$run.'-admin');$levels=array_fill_keys(['texts','events','gallery','newsletter','social','press'],'write');
$roleData=['name'=>'Own session writer','description'=>'Own original / editorial role','members_access'=>'none','finance_access'=>'none','settings_access'=>false,'website_custom'=>true,'website_levels'=>$levels];
$writerRole=$settings->saveRole('',0,$roleData);$peerRole=$settings->saveRole('',0,array_replace($roleData,['name'=>'Own independent peer']));
$settings->assignRole($run.'-writer',$writerRole,0);$settings->assignRole($run.'-peer',$peerRole,0);$settings->assignRole($run.'-switch',$writerRole,0);

// Projection is deliberately limited to fixture setup. Revocation assertions below
// use the same actual authenticated SessionAccess without access()/projectClub().
$guard=static function(PDO $connection,string $actor):void{};
$initial=(new ManagerBridge($db,$run.'-admin','native',$guard))->access();
$nativeClub=ManagerBridge::clubKey($club);$writerId=ManagerBridge::actorKey($run.'-writer',$club);$peerId=ManagerBridge::actorKey($run.'-peer',$club);$adminId=ManagerBridge::actorKey($run.'-admin',$club);
$plainId=$run.'-plain';$inviteId=$run.'-inviter';
foreach([$plainId,$inviteId]as$id)Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$id,$nativeClub,'Own independent / native account']);
Db::run($db,"INSERT INTO native_grants VALUES(?,'accounts','manage')",[$inviteId]);
foreach([['website','read'],['website','publish'],['newsletter','edit'],['chat','read']]as[$module,$cap])Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',[$plainId,$module,$cap]);
$inviter=new Access($db,$inviteId);$now=1800000000;$auth=new Service($db,random_bytes(32),static fn():int=>$now);$password='Own original / authenticated password 2026!';$tokens=[];
foreach(['writer'=>$writerId,'admin'=>$adminId,'plain'=>$plainId]as$kind=>$id){$email=$run.'-'.$kind.'@example.invalid';$invitation=$auth->createInvitation($inviter,$id,$email);$auth->acceptInvitation($invitation['token'],$password);$tokens[$kind]=$auth->passwordLogin($email,$password,$run.'-'.$kind)['session_token'];}
$direct=new SessionAccess($db,$auth,$tokens['writer']);$plain=new SessionAccess($db,$auth,$tokens['plain']);$adminSession=new SessionAccess($db,$auth,$tokens['admin']);
$check($direct->user()['id']===$writerId&&$direct->club()===$nativeClub,'actual authenticated source maps to its own original club and actor');
foreach(['website','newsletter','social','press']as$module)$check($direct->require($module,'publish')['id']===$writerId,'initial actual write right '.$module);
$deny(fn()=>$direct->require('website','manage'),'ordinary writer never receives technical management');
$deny(fn()=>$direct->require('finance','read'),'manager finance role is outside the native editorial module map');
$check($adminSession->require('accounts','manage')['id']===$adminId,'bound technical account uses its current server role');
$plainWorker=Authority::resolve($db,['created_by'=>$plainId,'authority'=>'native','club_id'=>$nativeClub]);
$check($plainWorker->require('website','publish')['id']===$plainId,'genuinely independent native worker retains its actual native grant');

$pages=new Pages($db,$direct);$page=$pages->save(['title'=>'Own immutable session page','slug'=>$run,'template'=>'standard','body'=>'Own original / draft']);
$page=$pages->publish($page['id'],$page['version']);$work=new Workspace($db,$direct,'newsletter');$edition=$work->save(['title'=>'Own original edition','kind'=>'edition','template'=>'newsletter-standard','body'=>'Own original / newsletter']);
$beforePage=$pages->get($page['id']);$beforeEdition=$work->get($edition['id']);
$scheduledPage=$pages->save(['title'=>'Own queued original','slug'=>$run.'-queued','template'=>'standard','body'=>'Own unpublished / original']);
$schedule=(new PublicationSchedule($db,$direct,static fn():int=>$now))->plan($scheduledPage['id'],$scheduledPage['version'],$now+60);
$storedSchedule=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$schedule['id']])->fetch();
$check($storedSchedule['authority']==='native','existing direct-session job retains its original native authority marker');
$worker=Authority::resolve($db,['created_by'=>$writerId,'authority'=>'native','club_id'=>$nativeClub]);
$deny(fn()=>Authority::resolve($db,['created_by'=>$writerId,'authority'=>'native','club_id'=>ManagerBridge::clubKey($foreign)]),'native-marked bound worker rejects a foreign job club before executing');
$grantRows=static fn():array=>Db::run($db,'SELECT * FROM native_grants ORDER BY actor_id,module,capability')->fetchAll();
$grantBefore=$grantRows();$readData=array_replace($roleData,['website_levels'=>array_fill_keys(array_keys($levels),'read')]);$settings->saveRole($writerRole,1,$readData);
$check($auth->resolve($tokens['writer'])['actor_id']===$writerId,'role revocation leaves the actual authenticated session valid');
$deny(fn()=>$direct->require('website','edit'),'same existing direct SessionAccess denies edit immediately after actual role save');
$deny(fn()=>$pages->save(array_replace($beforePage,['body'=>'Forbidden replacement']),$beforePage['version']),'real page save cannot exploit earlier projected grant');
$deny(fn()=>$work->save(array_replace($beforeEdition,['body'=>'Forbidden replacement']),$beforeEdition['version']),'real newsletter save cannot exploit earlier projected grant');
$deny(fn()=>$worker->require('website','publish'),'existing worker authority from native-marked bound job also sees the current revoked role');
$jobs=PublicationSchedule::runDue($db,null,10,$now+61,$nativeClub);
$check(count($jobs)===1&&$jobs[0]['status']==='blocked','actual queued local publication is blocked after current role withdrawal');
$afterSchedule=Db::run($db,'SELECT * FROM khcms_publication_schedule WHERE id=?',[$schedule['id']])->fetch();
$check($afterSchedule['authority']===$storedSchedule['authority']&&$afterSchedule['snapshot_json']===$storedSchedule['snapshot_json']&&$afterSchedule['snapshot_hash']===$storedSchedule['snapshot_hash'],'worker rejection preserves exact original job authority, payload and hash');
$check($pages->get($scheduledPage['id'])===$scheduledPage&&$pages->publicPage($scheduledPage['slug'])===null,'revoked queued publication leaves the exact original draft private');
$deny(fn()=>Authority::resolve($db,['created_by'=>ManagerBridge::actorKey($run.'-switch',$club),'authority'=>'native','club_id'=>$nativeClub]),'native-marked bound worker also requires an actual confirmed account');
foreach(['website','newsletter','social','press']as$module){$check($direct->require($module,'read')['id']===$writerId,'current read access preserved '.$module);$deny(fn()=>$direct->require($module,'publish'),'old publication grant cannot survive current read-only role '.$module);}
$check($grantRows()===$grantBefore,'rights and rejected real mutations do not reproject, delete or refresh the stored grant cache');
$check($pages->get($page['id'])===$beforePage&&$work->get($edition['id'])===$beforeEdition,'rejections retain exact private versions and original payloads');
$check($plain->require('website','publish')['id']===$plainId,'unbound independent account keeps its actual native grant');

// A missing role entry must close its area rather than inheriting cached grants.
$noneData=array_replace($roleData,['website_levels'=>array_fill_keys(array_keys($levels),'none')]);$settings->saveRole($writerRole,2,$noneData);
foreach(['website','newsletter','social','press']as$module)$deny(fn()=>$direct->require($module,'read'),'explicit none revokes the entire current module '.$module);
$check($grantRows()===$grantBefore,'none revocation is effective without any projection refresh');
$settings->saveRole($writerRole,3,$readData);

// Current directory must not include a peer whose actual additional role was
// removed while its stored projected read grant still exists.
$settings->assignRole($run.'-peer','',1,true);
$check((bool)Db::run($db,"SELECT 1 FROM native_grants WHERE actor_id=? AND module='newsletter' AND capability='read'",[$peerId])->fetchColumn(),'peer projected read grant is deliberately stale before directory read');
$directory=$direct->directory('newsletter');$ids=array_column($directory,'id');
$check(in_array($writerId,$ids,true)&&in_array($adminId,$ids,true),'current directory retains actually authorized own accounts');
$check(!in_array($peerId,$ids,true),'actual assignment reset removes the peer from the direct authenticated directory');
$check(!in_array(ManagerBridge::actorKey($run.'-foreign',$foreign),$ids,true)&&!in_array($plainId,$ids,true),'bound manager directory does not expand to foreign or unbound identities');
$readGrants=$grantRows();$settings->saveRole($writerRole,4,$roleData);
$check($direct->require('website','edit')['id']===$writerId,'new actual write authority works without an old cache grant');
$check($grantRows()===$readGrants,'freshly granted authority also does not need a projection write');

// Current locking role reads must outrun an older repeatable-read snapshot.
$other=TestSupport::db();$otherSettings=new Settings($other,$run.'-admin');$db->beginTransaction();
try{$db->query('SELECT COUNT(*) FROM settings_roles')->fetchColumn();$otherSettings->saveRole($writerRole,5,$readData);$deny(fn()=>$direct->require('website','edit'),'direct session sees actual revocation after its earlier repeatable-read snapshot');}
finally{if($db->inTransaction())$db->rollBack();}
$settings->saveRole($writerRole,6,$roleData);
$db->beginTransaction();
try{$direct->require('website','edit');$other->exec('SET innodb_lock_wait_timeout=1');try{Db::run($other,"UPDATE settings_roles SET website_access='{}' WHERE club_id=? AND id=?",[$club,$writerRole]);throw new RuntimeException('Expected original role row lock.');}catch(PDOException $failure){$check((int)($failure->errorInfo[1]??0)===1205,'current direct authority retains its actual role-row share lock until transaction end');}}
finally{if($db->inTransaction())$db->rollBack();}

// Do not allow a pinned manager identity to silently become an independent one.
$binding=Db::run($db,'SELECT * FROM native_manager_identities WHERE native_id=?',[$writerId])->fetch();
Db::run($db,'DELETE FROM native_manager_identities WHERE native_id=?',[$writerId]);
try{$deny(fn()=>$direct->require('website','edit'),'deleting bound identity cannot fall back to native grants');$deny(fn()=>$direct->directory('chat'),'identity loss also closes direct directory');$deny(fn()=>$worker->require('website','publish'),'existing bound worker cannot fall back to native grants after identity deletion');$check($auth->resolve($tokens['writer'])['actor_id']===$writerId,'binding-loss rejection is distinct from session logout');}
finally{Db::run($db,'INSERT INTO native_manager_identities(native_id,manager_actor_id,manager_club_id) VALUES(?,?,?)',[$binding['native_id'],$binding['manager_actor_id'],$binding['manager_club_id']]);}
Db::run($db,'UPDATE native_manager_identities SET manager_actor_id=?,manager_club_id=? WHERE native_id=?',[$run.'-foreign',$foreign,$writerId]);
try{$deny(fn()=>$direct->require('website','read'),'foreign rebind never inherits same native projected grants');$deny(fn()=>$worker->require('website','publish'),'existing worker rejects a foreign rebind of the same native identity');}
finally{Db::run($db,'UPDATE native_manager_identities SET manager_actor_id=?,manager_club_id=? WHERE native_id=?',[$binding['manager_actor_id'],$binding['manager_club_id'],$writerId]);}
Db::run($db,'UPDATE actors SET active=0 WHERE id=?',[$run.'-writer']);
try{$deny(fn()=>$direct->require('website','edit'),'source actor deactivation stops the same existing authenticated session');$deny(fn()=>$worker->require('website','publish'),'source actor deactivation also stops the existing native-marked worker');}
finally{Db::run($db,'UPDATE actors SET active=1 WHERE id=?',[$run.'-writer']);}

// Chat uses the same live module mapping; an editor must lose membership APIs.
Db::run($db,"UPDATE actors SET role='editor' WHERE id=?",[$run.'-writer']);
try{$deny(fn()=>$direct->require('chat','read'),'source role change to editor removes direct chat access');$deny(fn()=>$direct->directory('chat'),'source role change to editor removes direct chat directory');}
finally{Db::run($db,"UPDATE actors SET role='member' WHERE id=?",[$run.'-writer']);}
Db::run($db,"DELETE FROM native_grants WHERE actor_id=? AND module='website' AND capability='read'",[$plainId]);
$deny(fn()=>$plain->require('website','read'),'independent account also observes actual native grant withdrawal');
$deny(fn()=>$plainWorker->require('website','read'),'independent worker also observes actual native grant withdrawal');
$check($plain->require('website','publish')['id']===$plainId,'independent native capabilities are not replaced by manager role mapping');
$auth->logout($tokens['writer']);$deny(fn()=>$direct->require('website','read'),'actual logout stops current direct rights');$deny(fn()=>$direct->directory('chat'),'actual logout stops current direct directory');
$check($worker->require('website','publish')['id']===$writerId,'ordinary device logout does not erase separately approved worker authority while the actual account and role remain valid');
$check(ownOriginalDatabaseHash($db,$tables,$originalFilter)===$originalHash,'every earlier complete original row and schema metadata remains exact');
echo json_encode(['suite'=>'native-session-authority','checks'=>$checks,'original_tables'=>count($tables),'original_bytes'=>'exact','real_role_service_changes'=>true,'cached_access_reused'=>true,'grant_projection_before_revoke_checks'=>false,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
