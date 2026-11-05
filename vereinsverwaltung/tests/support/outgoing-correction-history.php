<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingCorrectionInput,OutgoingCorrectionRequests};

/** Twenty-six real small credits exercise both private history pages without fabricated ledger rows. */
function ownOutgoingCorrectionHistory(PDO $db,callable $check,array $fixture):array
{
    $service=$fixture['service'];$finance=$fixture['finance'];$requests=$fixture['requests'];$key=$fixture['key'];
    $id=$service->save('',0,array_replace($fixture['data'],['description'=>'Own actual twenty-six correction originals']),$key());$service->review($id,1);$service->issue($id,2);$invoice=$service->get($id);
    $transaction=$finance->createTransaction(['account'=>$fixture['account'],'booked_on'=>'2025-12-02','direction'=>'income','amount'=>'100.00','description'=>'Own actual history credit payment','counterparty'=>'Own history buyer'],$key());$tx=$finance->transactionDetail($transaction);$entry=$finance->entry($invoice['entry_id']);$finance->allocate($transaction,(int)$tx['version'],$entry['id'],(int)$entry['version'],'100.00',$key());
    $original=$service->get($id);$firsts=[];
    for($i=0;$i<26;$i++){
        $input=OutgoingCorrectionInput::initial($original);$input['data']=['kind'=>'credit','amount'=>'0.01','date'=>'2025-12-04','reason'=>"\nOwn whole correction history 0 ".$i."\n"];
        $review=$requests->prepare($input);$firsts[]=$requests->accept($input,$key(),$review['hash'],true);
    }
    $page=$requests->history($id);$second=$requests->history($id,['page'=>'2']);
    $check($page['total']===26&&count($page['rows'])===25&&count($second['rows'])===1&&$second['rows'][0]['request_key']===$firsts[0]['key'],'twenty-six real issued credits produce the actual first twenty-five and one earlier private history pages');
    $whole=$requests->history($id,['page'=>'999999']);$check($whole['page_input']==='999999'&&$whole['page']===null&&$whole['rows']===[]&&$whole['error']!=='','whole unavailable correction history page cannot select an earlier substitute');
    $fixture['deny'](fn()=>$requests->history($id,['query'=>"\n0\n"]),422,'whole multiline correction history search cannot silently normalize to a replacement search');
    $before=ownOriginalDatabaseHash($db,$fixture['tables']);foreach($firsts as$first)if($requests->receipt($first['key'])!==$first)throw new RuntimeException('An earlier whole native correction first changed.');
    $check($service->get($id)===$original&&$requests->receipt($fixture['first']['key'])===$fixture['first']&&$fixture['creditRequests']->receipt($fixture['creditFirst']['key'])===$fixture['creditFirst']&&ownOriginalDatabaseHash($db,$fixture['tables'])===$before,'all twenty-six actual credit firsts preserve the entire unchanged issued parent and both earlier cancellation and Finance-only originals');
    return ['id'=>$id,'first'=>$firsts[0],'firsts'=>$firsts];
}
