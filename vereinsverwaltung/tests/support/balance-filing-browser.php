<?php declare(strict_types=1);
function runOwnedBalanceFilingBrowser(PDO $db,array $data,?string $browserScript=null):void
{
 $cfg=\Kulturhus\Native\TestSupport::config();
 if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-balance-filing-interface'||($cfg['port']??0)!==13371||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Exact own balance filing browser only');
 $db->exec("INSERT INTO actors(id,club_id,role,is_superuser)VALUES('env-reader','own-balance-environment','member',0),('env-general','own-balance-environment','member',0);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('own-balance-environment','own-balance-browser-reader','Own balance browser reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('env-reader','own-balance-environment','own-balance-browser-reader',1)");
 require_once __DIR__.'/free-list-interface-browser-fixture.php';
 $data['balance_runtime_file']=getenv('VEREIN_ERIC_RUNTIME');$fixture=ownFreeListInterfaceBrowser($db,$data);
 $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));
 $oldRequests=$db->query('SELECT request_key FROM balance_filing_commands ORDER BY request_key')->fetchAll(PDO::FETCH_COLUMN);
 $oldFilings=$db->query('SELECT id FROM tax_filings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
 $mutable=getenv('DVM_BALANCE_RESERVE_BROWSER')==='1'?($data['reserve']['id']??null):null;$initialMutable=null;
 if($mutable!==null){if(!is_string($mutable)||!preg_match('/\A[a-f0-9]{32}\z/D',$mutable))throw new RuntimeException('Exact explicit existing source draft required');$q=$db->prepare("SELECT id FROM tax_filings WHERE club_id='own-reserve-source' AND id=?");$q->execute([$mutable]);if($q->fetchColumn()!==$mutable)throw new RuntimeException('Actual own original source draft required');$initialMutable=(new \Kulturhus\Verein\TaxFilings($db,'reserve-board'))->get($mutable);}
 $protectedFilings=array_values(array_diff($oldFilings,$mutable===null?[]:[$mutable]));
 $maxAudit=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();
 $filter=static function(string $table)use($oldRequests,$protectedFilings,$maxAudit):array{
  if($table==='audit_events')return [' WHERE sequence<=?',[$maxAudit]];
  if($table==='balance_filing_commands'&&!$oldRequests)return [' WHERE 1=0',[]];
  if($table==='balance_filing_commands')return [' WHERE request_key IN ('.implode(',',array_fill(0,count($oldRequests),'?')).')',$oldRequests];
  if($table==='tax_filings'&&!$protectedFilings)return [' WHERE 1=0',[]];
  if($table==='tax_filings')return [' WHERE id IN ('.implode(',',array_fill(0,count($protectedFilings),'?')).')',$protectedFilings];
  return ['',[]];
 };
 $before=ownOriginalDatabaseHash($db,$tables,$filter);$keys=[];
 foreach(['auth','private']as $name)$keys[$name]=hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key');
 try{
  $process=proc_open(['node',$browserScript??__DIR__.'/balance-filing-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
  fwrite($pipes[0],json_encode($fixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);
  if(proc_close($process)!==0)throw new RuntimeException('Actual balance filing browser failed');
  if(ownOriginalDatabaseHash($db,$tables,$filter)!==$before)throw new RuntimeException('Earlier whole balance browser originals changed');
  $out=json_decode(file_get_contents($fixture['input']['root'].'/balance-filing-browser-results.json'),true,128,JSON_THROW_ON_ERROR);
  $actor=getenv('DVM_BALANCE_RESERVE_BROWSER')==='1'?'reserve-board':'env-board';$service=new \Kulturhus\Verein\BalanceFilingCommands($db,$actor);$tax=new \Kulturhus\Verein\TaxFilings($db,$actor);
  $all=$db->query('SELECT request_key FROM balance_filing_commands ORDER BY request_key')->fetchAll(PDO::FETCH_COLUMN);
  $new=array_values(array_diff($all,$oldRequests));$expected=array_column($out['accepted'],'key');sort($new);sort($expected);if($new!==$expected)throw new RuntimeException('Exactly explicit accepted local balance commands required');
  $created=[];$latest=[];$audits=[];
  foreach($out['accepted']as $accepted){
   $first=$service->receipt($accepted['key']);if(!$first||$first['sha256']!==$accepted['sha256']||$first['operation']!==$accepted['operation'])throw new RuntimeException('Exact first browser balance result differs');
   if($mutable!==null){if($first['result']['id']!==$mutable||$first['before']!==$initialMutable)throw new RuntimeException('Exact successive original source versions required');$initialMutable=$first['result'];}
   $r=$first['result'];if($first['operation']==='create')$created[]=$r['id'];$latest[$r['id']]=$r;
   $audits[]=['action'=>'tax.balance.command.'.$first['operation'],'object_id'=>$first['key'],'object_version'=>$r['version']];
  }
  $actual=array_values(array_diff($db->query('SELECT id FROM tax_filings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),$oldFilings));sort($actual);sort($created);if($actual!==$created)throw new RuntimeException('Only exact explicit local new balance drafts required');
  foreach($latest as $id=>$record)if($tax->get($id)!==$record||$tax->attempts($id)!==[])throw new RuntimeException('Exact current local balance differs or was sent');
  $q=$db->prepare('SELECT action,object_id,object_version FROM audit_events WHERE sequence>? ORDER BY sequence');$q->execute([$maxAudit]);if($q->fetchAll()!==$audits)throw new RuntimeException('Exactly corresponding local balance audits required');
  foreach($keys as $name=>$hash)if(hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key')!==$hash)throw new RuntimeException('Own browser private key changed');
  require_once __DIR__.'/balance-filing-busy-browser.php';$busyKey=$actor==='env-board'?$out['accepted'][0]['key']:$db->query("SELECT request_key FROM balance_filing_commands WHERE club_id='own-balance-environment' ORDER BY request_key LIMIT 1")->fetchColumn();runOwnedBalanceFilingBusyBrowser($db,$fixture['input'],$busyKey);
  echo json_encode(['suite'=>'native-balance-filing-browser-originals','protected_tables'=>count($tables),'all_other_whole_rows_columns_primary_keys_files_money_and_keys_exact'=>true,'exact_explicit_commands'=>count($new),'exact_new_local_filings'=>count($created),'exact_explicit_audits'=>count($audits),'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
 }finally{foreach($fixture['servers']as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}}
}
