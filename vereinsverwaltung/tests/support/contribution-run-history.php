<?php declare(strict_types=1);
use Kulturhus\Verein\ContributionInvoices;
use Kulturhus\Verein\Finance\Contributions\ContributionRunInput;
use Kulturhus\Native\Shared\Db;

/** Twenty-six genuine scheduling approvals each add one newly eligible actual invoice. */
function ownContributionRunHistory(PDO $db,callable $check,array $f):array
{
    require_once __DIR__.'/outgoing-invoice-snapshots.php';$input=['data'=>['day'=>ContributionRunInput::today()]];$firsts=[];
    for($i=1;$i<=26;++$i){
        $payer='own-contribution-history-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);($f['payer'])($payer);$entry=$f['finance']->createAnnualContribution($payer,2026,'','2026-01-01');$invoice=(new ContributionInvoices($db,$f['actor']))->issue($entry);
        $oldRows=ownOutgoingInvoiceEarlierRows($db,$f['tables']);$before=ownOriginalDatabaseHash($db,$f['tables'],$oldRows);$review=$f['requests']->prepare($input);$first=$f['requests']->accept($input,($f['key'])(),$review['hash'],true);$firsts[]=$first;
        $check($invoice['state']==='issued'&&$first['result']['created']===0&&$first['result']['queued']===1&&$first['result']['deliveries'][0]['entry_id']===$entry&&$first['result']['errors']===[]&&ownOriginalDatabaseHash($db,$f['tables'],$oldRows)===$before,'genuine contribution history approval '.$i.' adds exactly its new actual order and retains every entire earlier row');
    }
    $query='"day":"'.$input['data']['day'].'"';$page1=$f['requests']->history(['query'=>$query,'page'=>'1']);$page2=$f['requests']->history(['query'=>$query,'page'=>'2']);$check($page1['total']===26&&count($page1['rows'])===25&&count($page2['rows'])===1&&$page2['rows'][0]['request_key']===$firsts[0]['key'],'twenty-six real contribution orders produce the actual first twenty-five and one earlier private history pages');
    $before=ownOriginalDatabaseHash($db,$f['tables']);$unavailable=$f['requests']->history(['query'=>$query,'page'=>'999999']);$check($unavailable['page']===null&&$unavailable['page_input']==='999999'&&$unavailable['rows']===[]&&$unavailable['error']!=='','whole unavailable contribution history page does not select an earlier substitute');($f['deny'])(fn()=>$f['requests']->history(['query'=>"\n0\n",'page'=>'2']),422,'whole multiline contribution search cannot silently normalize to a replacement query');
    $allExact=true;foreach($firsts as$first)if($f['requests']->receipt($first['key'])!==$first)$allExact=false;$check($allExact&&$f['requests']->receipt($f['first']['key'])===$f['first']&&ownOriginalDatabaseHash($db,$f['tables'])===$before,'all twenty-six real contribution firsts and the earlier annual original remain whole and byte exact during every history read');
    return ['firsts'=>$firsts,'query'=>$query,'first_key'=>$firsts[0]['key'],'last_key'=>$firsts[25]['key']];
}
