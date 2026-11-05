<?php declare(strict_types=1);
use Kulturhus\Verein\{Documents,Finance,InvoiceApprovals,InvoicePaymentReports,MemberBankVault,TransferOrders};

/** Independent canonical writers must supersede an older repeatable-read bank sender. */
function ownTransferNativeCurrent(PDO $db,callable $check,array $f,Closure $pdf):void
{
    $finance=new Finance($db,$f['actor']);$key=static fn():string=>bin2hex(random_bytes(16));$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $observe=static function(string $label,callable $change)use($db,$check,$f,$finance,$key,$tables):void{
        $source=($f['entry'])('Own current transfer source '.$label);$input=($f['inputFor'])($source['entry']);$preview=$f['service']->preview($source['entry'],$f['account'],$input['data']['execution_date'],$input['data']['purpose']);$order=$f['service']->prepare($source['entry'],$f['account'],$preview['execution_date'],$preview['purpose'],$preview['hash'],$key());$f['service']->approve($order,1);
        $reader=\Kulturhus\Native\TestSupport::db();$reader->beginTransaction();$reader->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn();
        try{
            $change($source);$fresh=false;try{$f['service']->fintsPayload($order,2);}catch(DomainException){$fresh=true;}$before=ownOriginalDatabaseHash($db,$tables);$old=false;try{(new TransferOrders($reader,$f['actor'],new MemberBankVault($reader,$f['actor'],$f['path'])))->fintsPayload($order,2);}catch(DomainException){$old=true;}$check($fresh&&$old,'genuine independent '.$label.' blocks both fresh and older bank payload readers');$check($reader->inTransaction(),'current transfer rejection preserves the older caller transaction '.$label);
        }finally{if($reader->inTransaction())$reader->rollBack();}
        $check(ownOriginalDatabaseHash($db,$tables)===$before,'current bank source checks change no complete earlier row '.$label);
    };
    $observe('partial payment',static function(array $source)use($finance,$f):void{$finance->recordPayment($source['entry'],(int)$finance->entry($source['entry'])['version'],$f['account'],$f['today'],'25.50');});
    $observe('reported payment',static function(array $source)use($db,$finance,$f,$key):void{(new InvoicePaymentReports($db,$f['actor']))->save($source['entry'],(int)$finance->entry($source['entry'])['version'],0,$f['today'],'Own genuine reported payment; no bank contacted',$key());});
    $observe('withdrawn invoice approval',static function(array $source)use($db,$f,$key):void{$approval=new InvoiceApprovals($db,$f['actor']);$state=$approval->state($source['entry']);$approval->withdraw($source['entry'],$state['hash'],$state['decision_id'],'Own actual withdrawal before bank sending',$key());});
    $observe('new original document revision',static function(array $source)use($db,$f,$pdf):void{(new Documents($db,$f['actor']))->newVersion($source['document'],1,'actual-corrected-invoice.pdf',$pdf('own corrected current invoice'),'Own actual document replacement');});
}
