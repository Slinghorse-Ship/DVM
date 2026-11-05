<?php declare(strict_types=1);
/** Browser allowances name exact accepted requests; every earlier complete row is protected. */
function ownMailBrowserRows(PDO $db,string $table,array $keys):array
{
 $out=[];foreach($db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row){$key=serialize(array_map(static fn(string $field):mixed=>$row[$field],$keys));if(isset($out[$key]))throw new RuntimeException('Unique owned mail fixture primary key required.');$out[$key]=$row;}return$out;
}
function ownMailBrowserOriginals(PDO $db,array $tables,array $cases):array
{
 $keys=['club_settings'=>['club_id','section'],'settings_revisions'=>['club_id','section','version'],'audit_events'=>['sequence'],'event_voucher_requests'=>['club_id','request_key'],'event_voucher_receipts'=>['club_id','request_key'],'event_voucher_outbox'=>['club_id','id']];$rows=[];
 foreach($keys as$table=>$fields)$rows[$table]=ownMailBrowserRows($db,$table,$fields);
 $sections=array_map(static fn(array $case):string=>'event_voucher_mail:'.$case['event'],$cases);
 $filter=static function(string $table)use($keys,$rows,$sections):array{
  if(!isset($keys[$table]))return['',[]];$values=[];$groups=[];
  foreach($rows[$table]as$row){if($table==='club_settings'&&$row['club_id']==='own-list-club'&&in_array($row['section'],$sections,true))continue;$groups[]='('.implode(' AND ',array_map(static fn(string $field):string=>'`'.$field.'`=?',$keys[$table])).')';foreach($keys[$table]as$field)$values[]=$row[$field];}
  return[$groups?' WHERE '.implode(' OR ',$groups):' WHERE 1=0',$values];
 };
 return ['keys'=>$keys,'rows'=>$rows,'filter'=>$filter,'hash'=>ownOriginalDatabaseHash($db,$tables,$filter)];
}
function ownMailBrowserVerify(PDO $db,array $tables,array $cases,array $before,array $results,callable $check):void
{
 $check(ownOriginalDatabaseHash($db,$tables,$before['filter'])===$before['hash'],'actual native mail actions preserve every earlier complete unrelated business row original piece source membership balance revision request receipt and delivery');
 $after=[];$added=[];foreach($before['keys']as$table=>$keys){$after[$table]=ownMailBrowserRows($db,$table,$keys);$added[$table]=array_diff_key($after[$table],$before['rows'][$table]);}
 $check($added['club_settings']===[],'native browser mail changes only explicitly existing event template rows');
 $executed=array_column($results,null,'event');
 foreach($cases as$case){$key=serialize(['own-list-club','event_voucher_mail:'.$case['event']]);$expected=$before['rows']['club_settings'][$key];$current=$after['club_settings'][$key];if(isset($executed[$case['event']])){$expected['version']=5;$expected['payload']=json_encode($executed[$case['event']]['final_template'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$expected['updated_at']=$current['updated_at'];}$check($current===$expected,'native mail template retains its exact original scope and only four separately accepted versions: '.$case['event']);}
 $accepted=[];$jobs=[];$templates=[];
 foreach($results as$result){
  $check(count($result['accepted'])===8&&count($result['batch_jobs'])===26,'native mail browser accepts exactly eight deliberate operations and twenty-six batch jobs per completed case');
  foreach($result['accepted']as$entry){$accepted[$entry['key']]=$entry;$receipt=(new \Kulturhus\Verein\EventVouchers($db,'own-list-admin'))->confirmation($entry['key']);$check($receipt['payload']===$entry['input']&&hash('sha256',$receipt['original_json'])===$entry['json_sha256'],'actual native accepted '.$entry['kind'].' retains its complete downloaded immutable original');
   if($entry['kind']==='mail-template')$templates[$result['event']][(int)$entry['input'][2]+1]=$entry['input'][3];
   if($entry['kind']==='mail'){$check($receipt['result']===$entry['job'],'native individual queue links to its one actually accepted original job');$jobs[$entry['job']]=['event'=>$result['event'],'hash'=>$entry['input'][3],'state'=>$entry['job']===$result['single_job']?'cancelled':'pending'];}
   if($entry['kind']==='mail-all'){$check($receipt['result']===['queued'=>26,'skipped'=>31]&&$receipt['context']['mail']['deliveries']===$result['batch_jobs'],'native batch count and every original recipient mapping are bound atomically to the exact approved result');foreach($result['batch_jobs']as$job)$jobs[$job['id']]=['event'=>$result['event'],'hash'=>$job['snapshot_sha256'],'state'=>'pending'];}
  }
 }
 foreach(['event_voucher_requests','event_voucher_receipts']as$table){$actual=array_column($added[$table],'request_key');$expected=array_keys($accepted);sort($actual);sort($expected);$check($actual===$expected,'native mail adds only its deliberate accepted keys and no duplicate after a lost answer in '.$table);}
 $check(count($added['event_voucher_outbox'])===28*count($results)&&count($jobs)===28*count($results),'native mail queues exactly two personal originals plus twenty-six batch originals per case');
 foreach($added['event_voucher_outbox']as$row){$expected=$jobs[$row['id']]??null;$snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$check($expected!==null&&$row['club_id']==='own-list-club'&&$row['event_id']===$expected['event']&&$row['state']===$expected['state']&&$row['fingerprint']===$expected['hash']&&hash('sha256',$row['snapshot_json'])===$expected['hash']&&$snapshot['person']['id']===$row['contact_id']&&$snapshot['person']['email']===$row['email']&&$snapshot['mail_template']===$executed[$row['event_id']]['final_template']&&count($snapshot['vouchers'])===1,'each actual native delivery retains its one exact approved personal snapshot original email template and deliberately final local state');}
 foreach(['settings_revisions','audit_events']as$table){$check(count($added[$table])===4*count($results),'native template appends exactly four accepted originals per case in '.$table);foreach($added[$table]as$row){$event=$table==='settings_revisions'?substr($row['section'],strlen('event_voucher_mail:')):$row['object_id'];$version=$table==='settings_revisions'?$row['version']:$row['object_version'];$valid=isset($templates[$event][$version])&&$row['club_id']==='own-list-club'&&$row['actor_id']==='own-list-admin';if($table==='settings_revisions')$valid=$valid&&json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR)===$templates[$event][$version];else$valid=$valid&&$row['action']==='event-voucher.mail-template';$check($valid,'every native appended template original belongs only to its exact accepted event version actor and full input');}}
}
