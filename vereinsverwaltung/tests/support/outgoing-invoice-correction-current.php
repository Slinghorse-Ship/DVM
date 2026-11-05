<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\OutgoingInvoices;
require_once __DIR__.'/outgoing-invoice-snapshots.php';

/** Composed canonical corrections must use current payments, earlier credits and replay keys. */
function ownOutgoingInvoiceCurrentCorrections(PDO $db,callable $check,array $fixture,array $config):void
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(($config['host']??null)!=='127.0.0.1'||($config['port']??null)!==13371||$config['database']!==$name||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$name))throw new RuntimeException('Owned current correction contract required.');
    $oldRows=ownOutgoingInvoiceEarlierRows($db,$fixture['tables']);$oldHash=ownOriginalDatabaseHash($db,$fixture['tables'],$oldRows);$finance=$fixture['finance'];$service=$fixture['service'];$key=$fixture['key'];
    // The genuine earlier expense path exists before the older caller snapshot.
    $finance->createEntry(['kind'=>'expense','description'=>'Own earlier correction expense area','amount'=>'1.00','category'=>'rooms','document_date'=>'2025-12-01','due_date'=>'2025-12-01'],$key());
    $make=static function(string$description)use($fixture,$service,$key):array{$id=$service->save('',0,array_replace($fixture['data'],['description'=>$description]),$key());$service->review($id,1);$service->issue($id,2);return $service->get($id);};
    $pay=static function(array$invoice)use($finance,$fixture,$key):array{$transaction=$finance->createTransaction(['account'=>$fixture['account'],'booked_on'=>'2025-12-02','direction'=>'income','amount'=>'100.00','description'=>'Own actual current correction payment','counterparty'=>'Own actual buyer'],$key());$tx=$finance->transactionDetail($transaction);$entry=$finance->entry($invoice['entry_id']);$allocation=$finance->allocate($transaction,(int)$tx['version'],$entry['id'],(int)$entry['version'],'100.00',$key());return compact('transaction','allocation');};
    $caller=new PDO('mysql:host=127.0.0.1;port=13371;dbname='.$name.';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$caller->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $unpaid=$make('Own genuinely unallocated current correction invoice');$payment=$pay($unpaid);$caller->beginTransaction();$caller->query('SELECT * FROM finance_allocations')->fetchAll();
    try{
        $tx=$finance->transactionDetail($payment['transaction']);$entry=$finance->entry($unpaid['entry_id']);$finance->releaseAllocation($payment['allocation'],(int)$tx['version'],(int)$entry['version'],'Own actual current payment correction');$before=ownOriginalDatabaseHash($db,$fixture['tables']);$rejected=false;
        try{(new OutgoingInvoices($caller,$fixture['actor']))->correct($unpaid['id'],3,'credit','50.00','Own no-longer-paid credit','2025-12-04',$key());}catch(DomainException$failure){$rejected=str_contains($failure->getMessage(),'Gutschrift darf den noch nicht gutgeschriebenen Zahlungseingang nicht übersteigen.');}
        $check($rejected&&$caller->inTransaction()&&$finance->entry($unpaid['entry_id'])['allocated_cents']===0,'actual released payment rejects a canonical credit behind an older caller snapshot without losing caller ownership');
    }finally{$caller->rollBack();}
    $check(ownOriginalDatabaseHash($db,$fixture['tables'])===$before,'rejected no-longer-paid canonical correction changes no whole current row number entry document or history');
    $paid=$make('Own genuine current earlier credit invoice');$payment=$pay($paid);$caller->beginTransaction();$caller->query('SELECT * FROM outgoing_invoices')->fetchAll();$earlierKey=$key();
    try{
        $earlier=$service->correct($paid['id'],3,'credit','80.00','Own actual earlier eighty-euro credit','2025-12-03',$earlierKey);$before=ownOriginalDatabaseHash($db,$fixture['tables']);$rejected=false;
        try{(new OutgoingInvoices($caller,$fixture['actor']))->correct($paid['id'],3,'credit','50.00','Own excess concurrent credit','2025-12-04',$key());}catch(DomainException$failure){$rejected=str_contains($failure->getMessage(),'Gutschrift darf den noch nicht gutgeschriebenen Zahlungseingang nicht übersteigen.');}
        $check($rejected&&$caller->inTransaction()&&$service->get($paid['id'])['version']===3,'actual earlier issued credit rejects an excessive second correction behind an older snapshot at the unchanged original invoice version');
        $check((new OutgoingInvoices($caller,$fixture['actor']))->correct($paid['id'],3,'credit','80.00','Own actual earlier eighty-euro credit','2025-12-03',$earlierKey)===$earlier,'actual canonical accepted correction key resolves its earlier complete result behind an older caller snapshot');
    }finally{$caller->rollBack();}
    $check(ownOriginalDatabaseHash($db,$fixture['tables'])===$before,'rejected excess credit and canonical first-result read preserve every entire earlier current business row');
    $before=ownOriginalDatabaseHash($db,$fixture['tables']);$caller->beginTransaction();
    try{$cancel=(new OutgoingInvoices($caller,$fixture['actor']))->correct($unpaid['id'],3,'cancellation','0','Own deliberate current caller cancellation','2025-12-04',$key());$check($caller->inTransaction()&&(new OutgoingInvoices($caller,$fixture['actor']))->get($cancel)['kind']==='cancellation','genuine current unpaid cancellation still allocates its original within the caller transaction');}
    finally{$caller->rollBack();}
    $check(ownOriginalDatabaseHash($db,$fixture['tables'])===$before&&ownOriginalDatabaseHash($db,$fixture['tables'],$oldRows)===$oldHash,'current correction caller rollback retains all whole earlier numbers original PDFs financial positions and personal originals');
}
