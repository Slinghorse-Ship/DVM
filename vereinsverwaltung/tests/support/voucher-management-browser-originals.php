<?php declare(strict_types=1);
/** Precise browser write allowance; every unrelated complete row and each immutable original stays exact. */
function ownManagementBrowserRows(PDO $db,string $table,string $key):array
{
 $rows=$db->query('SELECT * FROM '.chr(96).$table.chr(96))->fetchAll(PDO::FETCH_ASSOC);$out=[];
 foreach($rows as$row){if(isset($out[$row[$key]]))throw new RuntimeException('Owned browser fixture needs unique scoped source identities.');$out[$row[$key]]=$row;}return$out;
}
function ownManagementBrowserOriginals(PDO $db,array $tables,array $cases):array
{
 $keys=['event_voucher_events'=>'id','event_vouchers'=>'id','event_voucher_tickets'=>'id','event_voucher_moves'=>'id','event_voucher_requests'=>'request_key','event_voucher_receipts'=>'request_key'];$rows=[];
 foreach($keys as$table=>$key)$rows[$table]=ownManagementBrowserRows($db,$table,$key);
 $allow=['event_voucher_events'=>array_column($cases,'event'),'event_vouchers'=>[],'event_voucher_tickets'=>[]];
 foreach($cases as$case){$allow['event_vouchers'][]=$case['piece']['id'];$allow['event_vouchers'][]=$case['euro']['id'];foreach($case['piece']['tickets']as$ticket)$allow['event_voucher_tickets'][]=$ticket['id'];}
 $filter=static function(string $table)use($rows,$keys,$allow):array{
  if(!isset($keys[$table]))return['',[]];
  $ids=array_values(array_diff(array_keys($rows[$table]),$allow[$table]??[]));
  return$ids?[' WHERE '.$keys[$table].' IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids]:[' WHERE 1=0',[]];
 };
 return ['keys'=>$keys,'rows'=>$rows,'filter'=>$filter,'hash'=>ownOriginalDatabaseHash($db,$tables,$filter)];
}
function ownManagementBrowserVerify(PDO $db,array $tables,array $cases,array $before,array $results,callable $check):void
{
 $check(ownOriginalDatabaseHash($db,$tables,$before['filter'])===$before['hash'],'every native browser action preserves all complete unrelated originals and every earlier movement request input receipt and business row');
 $after=[];foreach($before['keys']as$table=>$key)$after[$table]=ownManagementBrowserRows($db,$table,$key);
 $executed=array_column($results,null,'event');$newKeys=[];$aux=[];$newParents=[];
 foreach($results as$result){foreach($result['accepted']as$accepted)$newKeys[]=$accepted['key'];$aux[]=$result['aux_event'];}
 sort($newKeys);$check(count($newKeys)===count(array_unique($newKeys))&&count($newKeys)===9*count($results),'actual browser accepted exactly the nine deliberate operations for each completed native write case');
 foreach(['event_voucher_requests','event_voucher_receipts']as$table){$added=array_values(array_diff(array_keys($after[$table]),array_keys($before['rows'][$table])));sort($added);$check($added===$newKeys,'native browser inserts only its explicitly accepted original keys in '.$table);}
 $addedEvents=array_values(array_diff(array_keys($after['event_voucher_events']),array_keys($before['rows']['event_voucher_events'])));sort($addedEvents);sort($aux);$check($addedEvents===$aux,'native original source selection creates exactly one requested additional event per case');
 foreach($cases as$case){
  $event=$case['event'];$changed=isset($executed[$event]);
  $original=$before['rows']['event_voucher_events'][$event];$current=$after['event_voucher_events'][$event];$expected=$original;if($changed)$expected['version']+=2;
  $check($current===$expected,'native configuration changes only the two explicitly confirmed event revisions and retains its original metadata: '.$event);
  foreach(['piece'=>3,'euro'=>2]as$kind=>$increments){$id=$case[$kind]['id'];$expected=$before['rows']['event_vouchers'][$id];if($changed){$expected['version']+=$increments;if($kind==='piece')$expected['remaining']--;}
   $check($after['event_vouchers'][$id]===$expected,'native redemption correction and revocation retain the exact parent identity code label units grant and deliberate balance: '.$kind.' '.$event);}
  foreach($case['piece']['tickets']as$index=>$ticket){$expected=$before['rows']['event_voucher_tickets'][$ticket['id']];if($changed&&$index===1)$expected['state']='revoked';$check($after['event_voucher_tickets'][$ticket['id']]===$expected,'native original piece keeps exact code identity and only its deliberate final state: '.$event.' '.$index);}
 }
 foreach($results as$result){
  $grants=array_values(array_filter($result['accepted'],static fn(array $row):bool=>$row['kind']==='grant'));$grant=$grants[0];$receipt=(new \Kulturhus\Verein\EventVouchers($db,'own-list-admin'))->confirmation($grant['key']);
  $check(hash('sha256',$receipt['original_json'])===$grant['json_sha256']&&$receipt['payload']===['grant',$result['event'],$result['selected'],[['label'=>$result['full_label'],'kind'=>'other','unit'=>'piece','amount'=>1]]],'actual lost-response native grant retains its exact downloaded original full Unicode input and both recipient pages');
  foreach($receipt['result']as$id){
   $newParents[]=$id;$v=$after['event_vouchers'][$id];
   $check($v['event_id']===$result['event']&&in_array($v['contact_id'],$result['selected'],true)&&$v['label']===$result['full_label']&&$v['unit']==='piece'&&$v['granted']===1&&$v['remaining']===1&&$v['version']===1&&$v['state']==='active','accepted native allocation creates only its requested single original entitlement');
  }
 }
 $addedParents=array_values(array_diff(array_keys($after['event_vouchers']),array_keys($before['rows']['event_vouchers'])));sort($addedParents);sort($newParents);$check($addedParents===$newParents&&count($newParents)===2*count($results),'native allocation creates exactly two selected new parent originals per case and no duplicate after lost response');
 $addedTickets=array_values(array_diff(array_keys($after['event_voucher_tickets']),array_keys($before['rows']['event_voucher_tickets'])));$check(count($addedTickets)===2*count($results),'native allocation creates exactly the two requested new physical pieces per case');
 foreach($addedTickets as$id){$ticket=$after['event_voucher_tickets'][$id];$check(in_array($ticket['voucher_id'],$newParents,true)&&$ticket['unit_no']===1&&$ticket['state']==='active'&&$ticket['redeemed_move_id']===null&&preg_match('/^[A-F0-9]{16}$/D',$ticket['code'])===1,'each new native physical piece belongs only to its accepted parent and retains one original unspent code');}
 $addedMoves=array_values(array_diff(array_keys($after['event_voucher_moves']),array_keys($before['rows']['event_voucher_moves'])));$check(count($addedMoves)===7*count($results),'native write cases append only five deliberate balance movements and two requested grant originals per case');
 foreach($addedMoves as$id){$move=$after['event_voucher_moves'][$id];$voucher=$after['event_vouchers'][$move['voucher_id']];$check(isset($executed[$voucher['event_id']])&&$move['actor_id']==='own-list-admin','each actual native movement stays in its explicitly handled event and creating account');}
}
