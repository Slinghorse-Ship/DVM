<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
$financeFrameMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$financeFrameText=static fn(string$source,array$values=[]):string=>\Kulturhus\Native\Language\FinanceReceiptMessages::text($source,$financeFrameMessages,$values);
if($view==='finance'&&in_array($tab,['','bank'],true)){$bankLedgerMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$bankLedgerText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\BankLedgerMessages::text($source,$bankLedgerMessages,$values);}
if(isset($invoiceDraftReadState)&&$invoiceDraftReadState['state']!=='ready'){require __DIR__.'/../Invoices/invoice-draft-recovery.php';return;}
if(!$canFinance): ?><div class="page-heading"><h1><?= e($views[$view]) ?></h1></div><div class="content"><section class="card"><h2><?=e($financeFrameText('Finanzberechtigung erforderlich'))?></h2><p><?=e($financeFrameText('Für diesen Bereich brauchen Sie eine Finanzberechtigung. Bitte wenden Sie sich an den ersten Vorstand, wenn Sie die Kasse betreuen sollen.'))?></p></section></div><?php return;endif;
if(isset($invoiceInboxSourceState)&&!in_array($invoiceInboxSourceState['state'],['list','selected'],true)){require __DIR__.'/../Invoices/invoice-inbox-unavailable.php';return;}
$financialOriginalSource??=\Kulturhus\Verein\Web\FinancialOriginalSource::request(isset($nativeAccess)?$view:'',$_GET,$_SERVER['REQUEST_METHOD']??'GET');if($financialOriginalSource['state']==='requested')$financialOriginalSource=(new \Kulturhus\Verein\Web\FinancialOriginalSource($db,(string)$_SESSION['actor']))->read($financialOriginalSource);if(in_array($financialOriginalSource['state'],['invalid','unavailable'],true)){http_response_code(422);require __DIR__.'/financial-original-unavailable.php';return;}if($financialOriginalSource['state']==='ready'&&$financialOriginalSource['kind']==='receipt')$financeEntry=$financialOriginalSource['row'];
$invoiceReceiptReturn=['state'=>'none','fields'=>[],'back'=>null,'reset'=>null];if($financeEntry&&($nativeManagerEnabled??false)){$invoiceReceiptReturn=(new \Kulturhus\Verein\Web\InvoiceReceiptReturn($db,(string)$_SESSION['actor']))->resolve($financeEntry['id'],$_GET);}
if(!$finance->canWrite()){
 echo '<p class="notice" data-finance-readonly>'.e(isset($bankLedgerText)?$bankLedgerText('Lesezugriff: Änderungen sind für diese Rolle gesperrt.'):$financeFrameText('Lesezugriff: Änderungen sind für diese Rolle gesperrt.')).'</p>';
  if(array_intersect(['new_entry','new_account','edit_account','new_transaction','new_goods','edit_positions','transfer_new'],array_keys($_GET))||in_array(input($_POST,'action'),['create-entry','create-account','finance-account-settings','finance-account-settings-refresh','create-transaction','create-goods','update-positions','delete-account','reimbursement-create','reimbursement-approve','reimbursement-link','fints-schedule-save','transfer-order-preview','transfer-order-prepare','transfer-order-confirm','transfer-order-approve','transfer-order-export','transfer-order-submitted','transfer-order-cancel'],true)){http_response_code(403);return;}
}
require __DIR__.'/finance-navigation.php';
require __DIR__.'/../Contributions/contribution-invoice-original.php';
if($tab==='bank'&&(input($_GET,'bank_import')==='1'||input($_POST,'action')==='bank-import-review')){require __DIR__.'/../Banking/bank-import-review.php';return;}
if($tab==='debit'&&($nativeManagerEnabled??false)){require __DIR__.'/../Banking/sepa-native.php';return;}
if($tab==='donation-collections'){require __DIR__.'/../Donations/donation-collections.php';return;}
if($tab==='outgoing'){require __DIR__.'/finance-outgoing.php';return;}
if($tab==='book-values'){require __DIR__.'/finance-book-values.php';return;}
if(isset($_GET['outgoing_einvoice'])||input($_POST,'action')==='outgoing-einvoice-save'){require __DIR__.'/../Invoices/outgoing-einvoice.php';return;}
if($tab==='help'){require __DIR__.'/finance-help.php';return;}
if($tab==='tax'){require __DIR__.'/../Tax/tax-preparation.php';return;}
if($tab==='contribution-mails'){require __DIR__.'/../Contributions/contribution-schedule.php';return;}
$tab=$tab?:($view==='donations'?'money':'bank');
$names=['bank'=>'Bank & Kasse','contributions'=>'Beitragsabrechnung','receipts'=>'Belege','inbox'=>'Rechnungseingang','debit'=>'Lastschriften','export'=>'Finanzexport','reports'=>'Auswertungen','money'=>'Geldspenden','goods'=>'Sachspenden'];
$title=$names[$tab]??$views[$view];if($tab==='bank'&&isset($bankLedgerText))$title=$bankLedgerText($title);elseif(isset($names[$tab]))$title=$financeFrameText($names[$tab]);$base=['view'=>$view,'tab'=>$tab];
if($view==='donations'&&$tab==='goods')$base+=scalarFields($_GET,['goods_page','goods_q','goods_origin','goods_evidence','goods_sort','goods_columns']);
else $base+=scalarFields($_GET,['kind','status','from','to','q','account','direction','sort','order','per_page','page']);
$base+=$invoiceReceiptReturn['fields'];
if($financeEntry&&(isset($_GET['edit_positions'])||input($_POST,'action')==='update-positions')){$positionType='entry';$positionId=$financeEntry['id'];$positionVersion=(int)$financeEntry['version'];$positionKind=$financeEntry['kind'];$positionTotal=(int)$financeEntry['amount_cents'];$positionRoute=$base+['entry'=>$positionId];require __DIR__.'/../../Settings/positions-edit.php';return;}
$new=isset($_GET['new_entry'])||(input($_POST,'action')==='create-entry'&&input($_POST,'inbox_guided')!=='1');
if($new):
 $inboxDocId=input($_GET,'inbox_doc',input($_POST,'inbox_doc'));$inboxDoc=$inboxDocId?(new \Kulturhus\Verein\InvoiceInbox($db,(string)$_SESSION['actor']))->document($inboxDocId):null;$inboxVersion=input($_POST,'action')==='create-entry'?input($_POST,'inbox_version'):($inboxDoc['version']??0);if($inboxDoc&&$inboxDoc['inbox_status']!=='open')throw new DomainException('Die Eingangsrechnung ist nicht mehr offen. Bitte Rechnungseingang neu öffnen.');
 $gemaEventId=input($_GET,'gema_event',input($_POST,'gema_event'));$gemaPreset=null;$gemaVersion=0;if($gemaEventId!==''){$gemaPreset=$registry->appointment($gemaEventId);$gemaVersion=(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->get($gemaEventId)['version'];if(input($_POST,'action')==='create-entry'&&input($_POST,'gema_event')===$gemaEventId)$gemaVersion=input($_POST,'gema_version');}

 $sourceTransaction=null;
 if(input($_GET,'source_transaction',input($_POST,'target_transaction'))!==''){
  try{$sourceTransaction=$finance->transactionDetail(input($_GET,'source_transaction',input($_POST,'target_transaction')));}
  catch(DomainException $ex){ ?><div class="error" role="alert"><?= e($ex->getMessage()) ?></div><?php return;}
 }
 $catalogPreset=null;$catalogQuote=null;
 if(!$_POST&&(input($_GET,'catalog_assignment')!==''||input($_GET,'catalog')!=='')){
  try{
   if(input($_GET,'catalog_assignment')!==''){
    $catalogQuote=(new \Kulturhus\Verein\CatalogPricing($db,(string)$_SESSION['actor']))->template(input($_GET,'catalog_assignment'),input($_GET,'catalog_date'),input($_GET,'catalog_mode','full'),input($_GET,'catalog_fingerprint'),input($_GET,'catalog_day')?:null);
    $catalogPreset=['id'=>$catalogQuote['catalog_id'],'kind'=>$catalogQuote['kind'],'data'=>array_replace($catalogQuote['data'],['amount'=>Finance::decimal($catalogQuote['amount_cents'])])];
   }else{
    foreach($settings->catalog()as$row)if($row['id']===input($_GET,'catalog')&&$row['active'])$catalogPreset=$row;
    if(!$catalogPreset)throw new DomainException('Aktive Belegvorlage nicht verfügbar.');
    if($catalogPreset['data']['pricing']!=='fixed')throw new DomainException('Gestaffelten Tarifpreis zuerst bei der eigenen Zuweisung ermitteln.');
   }
   $preset=$catalogPreset['data'];$positionForm=[['description'=>$preset['name'],'amount'=>$preset['amount'],'sphere'=>$preset['sphere'],'account'=>$preset['tax_account'],'tax_rate'=>$preset['tax_rate'],'booking_key'=>'']];
  }catch(DomainException $failure){ ?><div class="content"><section class="card"><p role="alert"><?= e($failure->getMessage()) ?></p><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'settings','tab'=>($catalogPreset['kind']??'')==='service'?'services':'rates']).'#catalog-pricing') ?>">Tarifpreis erneut ermitteln</a></section></div><?php return;}
 }

 $kind=input($_POST,'kind',input($_GET,'kind',$inboxDoc?'expense':'income'));if(!isset(Finance::KINDS[$kind]))$kind='income'; ?>
<?php require __DIR__.'/../../Help/manual-finance.php';return;endif;
if($financeEntry):$r=$financeEntry;$receiptIsCredit=$r['kind']==='expense'&&(new \Kulturhus\Verein\ExpenseReimbursements($db,(string)$_SESSION['actor']))->isCredit($r['id']);$dueDateMissing=$r['kind']==='expense'&&str_contains((string)($r['notes']??''),'Zahlbar bis nicht auf Rechnung angegeben.'); ?>
<link rel="stylesheet" href="finance-receipt.css?v=4">
<?php require __DIR__.'/receipt-summary.php'; ?>
<?php require __DIR__.'/../Invoices/outgoing-einvoice-status.php'; ?>
<?php require __DIR__.'/../Contributions/contribution-invoice-status.php'; ?>
<?php if($r['kind']==='contribution')require __DIR__.'/../Contributions/contribution-receipt-status.php';if($r['kind']==='donation'&&!in_array($r['payment_state'],['cancelled','reversed'],true)): ?><p data-receipt-donation><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'settings','tab'=>'donation-forms','kind'=>'money','source_id'=>$r['id']])) ?>"><?= e($financeFrameText($finance->canWrite()?'Zuwendungsbescheinigung erstellen / verwalten':'Zuwendungsformular und Bestätigungen ansehen')) ?></a></p><?php endif; if($r['kind']==='donation'){$donorType='entry';require __DIR__.'/../Donations/donation-contact.php';}if($r['kind']==='expense'){require __DIR__.'/reimbursement.php';}documentPanel($documents,'entry',$r['id']);if($r['kind']==='expense'){require __DIR__.'/finance-einvoice.php';$positionType='entry';$positionId=$r['id'];$positionEditable=!in_array($r['payment_state'],['cancelled','reversed'],true);$positionRoute=$base+['entry'=>$r['id']];$positionCompact=true;require __DIR__.'/../../Settings/positions-detail.php';$inventorySource='expense';$inventorySourceId=$r['id'];require __DIR__.'/../../Inventory/inventory-links.php';require __DIR__.'/../Invoices/invoice-approval.php';require __DIR__.'/../Banking/transfer-order.php';}if($r['kind']!=='expense'){$positionType='entry';$positionId=$r['id'];$positionKind=$r['kind'];$positionEditable=!in_array($r['payment_state'],['cancelled','reversed'],true);$positionRoute=$base+['entry'=>$r['id']];$positionCompact=true;require __DIR__.'/../../Settings/positions-detail.php';}require __DIR__.'/entry-payments.php'; ?></div><?php return;endif;
if(input($_GET,'transaction')!==''){require __DIR__.'/transaction.php';return;}
?>
<?php require __DIR__.'/finance-sections.php'; ?>
