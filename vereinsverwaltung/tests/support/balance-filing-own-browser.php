<?php declare(strict_types=1);
function runOwnedBalanceOwnBrowser(PDO $db,array $data):void
{
 $cfg=\Kulturhus\Native\TestSupport::config();
 if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-balance-filing-interface'||($cfg['host']??'')!=='127.0.0.1'||($cfg['port']??0)!==13371||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Exact own balance filing browser only');
$exactColumns=static function(string$table)use($db):string{if(!in_array($table,['documents','document_files','document_history','audit_events'],true))throw new RuntimeException('Exact original source table required');$names=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);return implode(',',array_map(static fn(string$name):string=>'`'.str_replace('`','``',$name).'`',$names));};
 $db->exec("INSERT INTO actors(id,club_id,role,is_superuser)VALUES('env-reader','own-balance-environment','member',0),('env-general','own-balance-environment','member',0);INSERT INTO settings_roles(club_id,id,name,description,members_access,finance_access,settings_access,version)VALUES('own-balance-environment','own-balance-browser-reader','Own balance browser reader','','none','read',0,1);INSERT INTO settings_role_assignments VALUES('env-reader','own-balance-environment','own-balance-browser-reader',1)");
 require_once __DIR__.'/free-list-interface-browser-fixture.php';
 $data['balance_runtime_file']=getenv('VEREIN_ERIC_RUNTIME');$fixture=ownFreeListInterfaceBrowser($db,$data);
 $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences']));
 $oldRequests=$db->query('SELECT request_key FROM balance_filing_commands ORDER BY request_key')->fetchAll(PDO::FETCH_COLUMN);
 $oldFilings=$db->query('SELECT id FROM tax_filings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
 $mutable=$data['own']['id']??null;$initialMutable=null;if($mutable===null)throw new RuntimeException('Explicit existing original development source draft required');
 if($mutable!==null){if(!is_string($mutable)||!preg_match('/\A[a-f0-9]{32}\z/D',$mutable))throw new RuntimeException('Exact explicit existing source draft required');$q=$db->prepare("SELECT id FROM tax_filings WHERE club_id='own-ownintangible-source' AND id=?");$q->execute([$mutable]);if($q->fetchColumn()!==$mutable)throw new RuntimeException('Actual own original source draft required');$initialMutable=(new \Kulturhus\Verein\TaxFilings($db,'ownintangible-board'))->get($mutable);}
 $protectedFilings=array_values(array_diff($oldFilings,$mutable===null?[]:[$mutable]));
 $maxAudit=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();
 $document=$data['own']['source']['fiscal_document_id']??null;if(!is_string($document)||!preg_match('/\A[a-f0-9]{32}\z/D',$document))throw new RuntimeException('Exact original election document required');$q=$db->prepare("SELECT ".$exactColumns('documents')." FROM documents WHERE club_id='own-ownintangible-source' AND id=?");$q->execute([$document]);$initialDocument=$q->fetch();if(!$initialDocument||$initialDocument['state']!=='active'||!in_array('finance',json_decode($initialDocument['scopes'],true,16,JSON_THROW_ON_ERROR),true)||$initialDocument['origin']==='generated')throw new RuntimeException('Actual active own financial original required');$q=$db->prepare("SELECT MAX(revision) FROM document_files WHERE club_id='own-ownintangible-source' AND document_id=?");$q->execute([$document]);$oldRevision=(int)$q->fetchColumn();if($oldRevision!==$data['own']['election_revision'])throw new RuntimeException('Current actual election revision required');$maxHistory=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM document_history')->fetchColumn();
 $filter=static function(string $table)use($oldRequests,$protectedFilings,$maxAudit,$document,$oldRevision,$maxHistory):array{
  if($table==='audit_events')return [' WHERE sequence<=?',[$maxAudit]];
  if($table==='documents')return [" WHERE NOT (club_id='own-ownintangible-source' AND id=?)",[$document]];
  if($table==='document_files')return [" WHERE club_id<>'own-ownintangible-source' OR document_id<>? OR revision<=?",[$document,$oldRevision]];
  if($table==='document_history')return [' WHERE sequence<=?',[$maxHistory]];
  if($table==='balance_filing_commands'&&!$oldRequests)return [' WHERE 1=0',[]];
  if($table==='balance_filing_commands')return [' WHERE request_key IN ('.implode(',',array_fill(0,count($oldRequests),'?')).')',$oldRequests];
  if($table==='tax_filings'&&!$protectedFilings)return [' WHERE 1=0',[]];
  if($table==='tax_filings')return [' WHERE id IN ('.implode(',',array_fill(0,count($protectedFilings),'?')).')',$protectedFilings];
  return ['',[]];
 };
 $before=ownOriginalDatabaseHash($db,$tables,$filter);$keys=[];
 foreach(['auth','private']as $name)$keys[$name]=hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key');
 try{
  $process=proc_open(['node',__DIR__.'/balance-filing-own-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w'],2=>STDERR],$pipes);
  fwrite($pipes[0],json_encode($fixture['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);while(($line=fgets($pipes[1]))!==false){echo$line;flush();}fclose($pipes[1]);
  if(proc_close($process)!==0)throw new RuntimeException('Actual balance filing browser failed');
  if(ownOriginalDatabaseHash($db,$tables,$filter)!==$before)throw new RuntimeException('Earlier whole balance browser originals changed');
  $out=json_decode(file_get_contents($fixture['input']['root'].'/balance-filing-browser-results.json'),true,128,JSON_THROW_ON_ERROR);
  $actor='ownintangible-board';$service=new \Kulturhus\Verein\BalanceFilingCommands($db,$actor);$tax=new \Kulturhus\Verein\TaxFilings($db,$actor);
  $all=$db->query('SELECT request_key FROM balance_filing_commands ORDER BY request_key')->fetchAll(PDO::FETCH_COLUMN);
  $new=array_values(array_diff($all,$oldRequests));$expected=array_column($out['accepted'],'key');sort($new);sort($expected);if($new!==$expected)throw new RuntimeException('Exactly explicit accepted local balance commands required');
  $created=[];$latest=[];$audits=[];$fullAudits=[];
  foreach($out['accepted']as $accepted){
   $first=$service->receipt($accepted['key']);if(!$first||$first['sha256']!==$accepted['sha256']||$first['operation']!==$accepted['operation'])throw new RuntimeException('Exact first browser balance result differs');
   if($mutable!==null){if($first['result']['id']!==$mutable||$first['before']!==$initialMutable)throw new RuntimeException('Exact successive original source versions required');$initialMutable=$first['result'];}
   $r=$first['result'];if($first['operation']==='create')$created[]=$r['id'];$latest[$r['id']]=$r;
   $audits[]=['action'=>'tax.balance.command.'.$first['operation'],'object_id'=>$first['key'],'object_version'=>$r['version']];$fullAudits[]=['club_id'=>$r['club_id'],'actor_id'=>$first['actor_id'],'action'=>'tax.balance.command.'.$first['operation'],'object_id'=>$first['key'],'object_version'=>$r['version'],'happened_at'=>$first['created_at']];
  }
  $actual=array_values(array_diff($db->query('SELECT id FROM tax_filings ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),$oldFilings));sort($actual);sort($created);if($actual!==$created)throw new RuntimeException('Only exact explicit local new balance drafts required');
  foreach($latest as $id=>$record)if($tax->get($id)!==$record||$tax->attempts($id)!==[])throw new RuntimeException('Exact current local balance differs or was sent');
  $mutations=$out['document_mutations']??null;if(!is_array($mutations)||count($mutations)!==8)throw new RuntimeException('Exactly eight explicit original PDF replacements required');$expectedFiles=[];$expectedHistory=[];$expectedDocumentAudits=[];$revision=$oldRevision;
  foreach($mutations as$mutation){if($mutation['document_id']!==$document||$mutation['old_revision']!==$revision||$mutation['new_revision']!==$revision+1||$mutation['before_document']!==$initialDocument||$mutation['all_other_whole_originals_exact']!==true)throw new RuntimeException('Exact successive original document versions required');$expectedDocument=$initialDocument;$expectedDocument['version']++;if($mutation['after_document']!==$expectedDocument)throw new RuntimeException('Only the explicit original metadata version may change');$initialDocument=$expectedDocument;$revision++;$file=$mutation['file'];$file['bytes']=base64_decode($file['bytes_base64'],true);unset($file['bytes_base64']);$expectedFiles[]=$file;$expectedHistory[]=$mutation['history'];$expectedDocumentAudits[]=$mutation['audit'];}
  $q=$db->prepare("SELECT ".$exactColumns('documents')." FROM documents WHERE club_id='own-ownintangible-source' AND id=?");$q->execute([$document]);if($q->fetch()!==$initialDocument)throw new RuntimeException('Exact last source document metadata required');$q=$db->prepare("SELECT ".$exactColumns('document_files')." FROM document_files WHERE club_id='own-ownintangible-source' AND document_id=? AND revision>? ORDER BY revision");$q->execute([$document,$oldRevision]);$files=$q->fetchAll();if(count($files)!==count($expectedFiles))throw new RuntimeException('Only exact explicit new source files required');foreach($files as$i=>$file){$expected=$expectedFiles[$i];ksort($file);ksort($expected);if($file!==$expected)throw new RuntimeException('Exact explicit original PDF bytes and every file column required');}
  $q=$db->prepare('SELECT '.$exactColumns('document_history').' FROM document_history WHERE sequence>? ORDER BY sequence');$q->execute([$maxHistory]);if($q->fetchAll()!==$expectedHistory)throw new RuntimeException('Exactly explicit original version history required');
  $q=$db->prepare('SELECT '.$exactColumns('audit_events').' FROM audit_events WHERE sequence>? ORDER BY sequence');$q->execute([$maxAudit]);$actualAudits=$q->fetchAll();$actualTax=[];$actualDocuments=[];foreach($actualAudits as$row){if($row['action']==='document.version')$actualDocuments[]=$row;else{unset($row['sequence']);$actualTax[]=$row;}}if($actualTax!==$fullAudits||$actualDocuments!==$expectedDocumentAudits||count($actualAudits)!==count($audits)+8)throw new RuntimeException('Every exact tax and independent original-file audit required');
  foreach($keys as $name=>$hash)if(hash_file('sha256',$fixture['input']['root'].'/'.$name.'.key')!==$hash)throw new RuntimeException('Own browser private key changed');
  require_once __DIR__.'/balance-filing-busy-browser.php';$busyKey=$actor==='env-board'?$out['accepted'][0]['key']:$db->query("SELECT request_key FROM balance_filing_commands WHERE club_id='own-balance-environment' ORDER BY request_key LIMIT 1")->fetchColumn();runOwnedBalanceFilingBusyBrowser($db,$fixture['input'],$busyKey);
  echo json_encode(['suite'=>'native-balance-own-browser-originals','protected_tables'=>count($tables),'all_other_whole_rows_columns_primary_keys_files_money_and_keys_exact'=>true,'exact_explicit_commands'=>count($new),'exact_new_local_filings'=>count($created),'exact_explicit_tax_audits'=>count($audits),'exact_explicit_document_versions'=>count($mutations),'exact_explicit_document_audits'=>count($expectedDocumentAudits),'all_first_pdf_revisions_preserved'=>true,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
 }finally{foreach($fixture['servers']as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}}
}
