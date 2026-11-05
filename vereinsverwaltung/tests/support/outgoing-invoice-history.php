<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingInvoiceDraft,OutgoingInvoiceRequests};
require_once __DIR__.'/outgoing-invoice-snapshots.php';

/** Real later draft versions and genuine canonical creations, without fabricated personal rows. */
function ownOutgoingInvoiceHistory(PDO $db,callable $check,array $fixture):array
{
    $requests=$fixture['requests'];$key=$fixture['key'];$tables=$fixture['tables'];$oldRows=ownOutgoingInvoiceEarlierRows($db,$tables);$before=ownOriginalDatabaseHash($db,$tables,$oldRows);
    $input=$fixture['input'];$input['data']['description']='Own real private invoice history 0';$first=$requests->execute($input,$key());$id=$first['result']['id'];$firsts=[$first];
    for($index=1;$index<26;$index++){
        $next=$input;$next['invoice']=$id;$next['version']=(string)$index;$next['data']['description']='Own real complete private invoice history '.$index;
        $firsts[]=$requests->execute($next,$key());
    }
    $page=$requests->history($id);$second=$requests->history($id,['page'=>'2']);
    $check($page['total']===26&&count($page['rows'])===25&&count($second['rows'])===1&&$second['rows'][0]['request_key']===$first['key'],'twenty-six genuine native saves paginate twenty-five plus the actual first invoice confirmation');
    foreach($firsts as$original)if($requests->receipt($original['key'])!==$original)throw new RuntimeException('An earlier whole invoice confirmation changed.');
    $check(true,'every whole first raw normalized source and result remains byte exact after all twenty-six later actual draft versions');
    $absent=$requests->history($id,['page'=>'999999']);$check($absent['page_input']==='999999'&&$absent['page']===null&&$absent['rows']===[],'whole unavailable history after twenty-six real saves cannot substitute an earlier page');
    $legacyKey=$key();$legacyInput=$fixture['input'];$legacyInput['data']['description']='Own actual earlier canonical-only draft';$legacyId=$fixture['service']->save('',0,$legacyInput['data'],$legacyKey);
    $current=ownOriginalDatabaseHash($db,$tables);$earlier=$requests->legacyCreation($legacyKey,$legacyInput);
    $check($earlier['invoice']['id']===$legacyId&&$earlier['original']['version']===1&&$earlier['original']['action']==='saved'&&$requests->history($legacyId)['total']===0&&ownOriginalDatabaseHash($db,$tables)===$current,'actual canonical-only creation resolves its real saved history without inventing a personal first or changing business data');
    $fixture['deny'](fn()=>$requests->execute($legacyInput,$legacyKey),409,'native new-creation key cannot backfill an earlier canonical draft as a new personal original');
    $session=[];$actor=$requests->assertAccess();$draft=OutgoingInvoiceDraft::open($session,$actor,$legacyInput,true);$draft['key']=$legacyKey;$draft['legacy_request_key']=$legacyKey;OutgoingInvoiceDraft::store($session,$draft);
    $recovered=OutgoingInvoiceDraft::recoverCreation($session,$actor,$draft,$earlier);
    $check($recovered['input']['invoice']===$legacyId&&$recovered['input']['version']==='1'&&$recovered['input']['data']===$legacyInput['data']&&$recovered['key']!==$legacyKey&&$recovered['legacy_creation']['input']===$legacyInput&&ownOriginalDatabaseHash($db,$tables)===$current,'conscious legacy recovery retains all original posted creation fields while preparing a distinct new edit of the actual existing draft');
    $edited=$requests->execute($recovered['input'],$recovered['key']);
    $check($edited['result']['id']===$legacyId&&$edited['result']['version']===2&&$edited['key']!==$legacyKey&&$requests->history($legacyId)['total']===1&&$edited['source']['invoice']['request_key']===$legacyKey,'conscious continuation edits the existing canonical draft and records only the new version-two personal confirmation');
    $current=ownOriginalDatabaseHash($db,$tables);$check($fixture['service']->save('',0,$legacyInput['data'],$legacyKey)===$legacyId&&ownOriginalDatabaseHash($db,$tables)===$current,'earlier canonical repeated creation keeps its existing idempotency contract after native continuation');
    $check((new OutgoingInvoiceRequests($db,'own-list-foreign'))->legacyCreation($legacyKey,$legacyInput)===null,'another association cannot resolve the known earlier canonical creation key');
    $check(ownOriginalDatabaseHash($db,$tables,$oldRows)===$before,'all deliberate new history and legacy continuation preserve every whole earlier business row private original and actual issued document');
    return compact('id','first','firsts','legacyId','legacyKey','edited');
}
