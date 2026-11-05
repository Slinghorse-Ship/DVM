<?php declare(strict_types=1);
require_once __DIR__.'/free-list-interface-browser-fixture.php';
$gbFixture=ownFreeListInterfaceBrowser($db,['first_revision'=>$gsRequests->revision('club','1')]);$gbRoot=$gbFixture['input']['root'];
$gbTables=array_values(array_diff($tables,['native_auth_sessions','native_auth_limits','native_language_preferences']));$gbVersions=[];foreach(['club','salutations','fields']as$section)$gbVersions[$section]=$gs->section($section)['version'];$gbAuditMax=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();$gbCount=(int)$db->query('SELECT COUNT(*) FROM native_settings_requests')->fetchColumn();$gbRevisionCount=(int)$db->query('SELECT COUNT(*) FROM settings_revisions')->fetchColumn();
$gbBeforeFilter=static fn(string $table):array=>$table==='club_settings'?[' WHERE NOT(club_id=? AND section IN (?,?,?))',['own-list-club','club','salutations','fields']]:['',[]];
$gbBefore=ownOriginalDatabaseHash($db,$gbTables,$gbBeforeFilter);$gbKeys=[];foreach(['auth','private']as$name)$gbKeys[$name]=hash_file('sha256',$gbRoot.'/'.$name.'.key');
try{$process=proc_open(['node',dirname(__DIR__).'/general-settings-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($gbFixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Actual general settings browser failed.');}
finally{foreach($gbFixture['servers']as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}}
$gbResult=json_decode(file_get_contents($gbRoot.'/general-settings-actions.json'),true,64,JSON_THROW_ON_ERROR);$gbNewKeys=array_column($gbResult['originals'],'key');
$gbFilter=static function(string $table)use($gbVersions,$gbAuditMax,$gbNewKeys,$gbBeforeFilter):array{
    if($table==='settings_revisions')return[' WHERE NOT(club_id=? AND ((section=? AND version>?) OR(section=? AND version>?) OR(section=? AND version>?)))',['own-list-club','club',$gbVersions['club'],'salutations',$gbVersions['salutations'],'fields',$gbVersions['fields']]];
    if($table==='audit_events')return[' WHERE sequence<=?',[$gbAuditMax]];
    if($table==='native_settings_requests')return[' WHERE request_key NOT IN ('.implode(',',array_fill(0,count($gbNewKeys),'?')).')',$gbNewKeys];
    return$gbBeforeFilter($table);
};
$check(ownOriginalDatabaseHash($db,$gbTables,$gbFilter)===$gbBefore,'actual native general settings variants retain every full earlier business row all earlier revisions and original confirmations outside their three explicitly accepted current settings');
$check(count($gbNewKeys)===count(array_unique($gbNewKeys))&&(int)$db->query('SELECT COUNT(*) FROM native_settings_requests')->fetchColumn()===$gbCount+count($gbNewKeys)&&(int)$db->query('SELECT COUNT(*) FROM settings_revisions')->fetchColumn()===$gbRevisionCount+count($gbNewKeys),'actual native general settings browser creates exactly its accepted first keys and one matching canonical revision each');
$gbLast=[];$gbPerSection=array_fill_keys(['club','salutations','fields'],0);
foreach($gbResult['originals']as$original){$stored=$gsRequests->receipt($original['section'],$original['key']);$check($stored['original_json']===$original['json']&&$stored['sha256']===$original['sha256'],'actual native general settings JSON download is exactly its first stored original bytes and SHA-256');}
foreach($gbResult['actions']as$action){$gbLast[$action['section']]=$action['result'];$gbPerSection[$action['section']]++;}
foreach($gbLast as$section=>$state)$check($gs->section($section)===$state&&$state['version']===$gbVersions[$section]+$gbPerSection[$section],'actual '.$section.' current state contains exactly its consciously accepted fields and monotonic revisions');
$gbAudits=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM audit_events WHERE sequence>?',[$gbAuditMax])->fetchAll();$check(count($gbAudits)===count($gbNewKeys)&&count(array_filter($gbAudits,static fn(array $row):bool=>$row['actor_id']==='own-list-admin'&&$row['action']==='settings.updated'&&in_array($row['object_id'],['club','salutations','fields'],true)))===count($gbNewKeys),'each real browser acceptance has only its own canonical general settings audit');
foreach($gbKeys as$name=>$sha)$check(hash_file('sha256',$gbRoot.'/'.$name.'.key')===$sha,'native general settings preserves its exact private '.$name.' key');
