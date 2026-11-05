<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Banking\NativeSepa\{SepaInput,SepaRequests};

/** Run last, so the legal maximum source does not change the preceding UI fixture. */
function ownSepaNativeLarge(PDO $db,callable $check,array $f):array
{
    require_once __DIR__.'/outgoing-invoice-snapshots.php';$notes=json_encode(['payer'=>$f['payer'],'own_full_original_annotation'=>str_repeat('🙂',9900)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$ids=[];
    for($i=0;$i<500;$i++)$ids[]=$f['finance']->createEntry(['kind'=>'contribution','category'=>'membership','description'=>'Own actual legal maximum bank source '.$i,'counterparty'=>'Own SEPA payer','amount'=>'20','document_date'=>$f['notice'],'due_date'=>$f['notice'],'notes'=>$notes],($f['key'])());
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$oldRows=ownOutgoingInvoiceEarlierRows($db,$tables);$before=ownOriginalDatabaseHash($db,$tables,$oldRows);$raw=($f['inputFor'])($ids[0]);$raw['data']['entries']=$ids;$raw['data']['reason']='Own actual legal five hundred demand review';$review=$f['native']->prepare($raw);$bytes=strlen(SepaRequests::json($review));
    $check($bytes<2000000&&count($review['source']['entries'])===500&&ownOriginalDatabaseHash($db,$tables,$oldRows)===$before,'actual legal five hundred-demand review remains compact and changes no complete original row or long note');
    $first=$f['native']->accept($raw,($f['key'])(),$review['hash'],true);$exact=count($first['result']['order']['items'])===500;
    foreach($ids as$id){$source=$f['finance']->currentEntry($id);$saved=$first['review']['source']['entries'][$id];if($source['notes']!==$notes||isset($saved['entry']['notes'])||$saved['notes_sha256']!==hash('sha256',$notes)||$saved['entry_sha256']!==hash('sha256',SepaRequests::json($source)))$exact=false;}
    $check($exact&&ownOriginalDatabaseHash($db,$tables,$oldRows)===$before,'actual legal maximum bank decision creates one canonical five-hundred-item order while all complete notes financial sources and source digests stay exact');
    return ['count'=>count($ids),'review_bytes'=>$bytes,'peak_memory_bytes'=>memory_get_peak_usage(true),'order'=>$first['result']['order']['id']];
}
