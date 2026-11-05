<?php declare(strict_types=1);
require __DIR__.'/finance-workflows.php';
require_once __DIR__.'/../src/Finance/Invoices/OutgoingEInvoice.php';
use Kulturhus\Verein\{Documents,EInvoiceValidator,FinanceWorkflowActions};
$files=new Documents($db,'admin');$original=$files->file($invoice['document_id'],1);$snapshot=json_decode($original['snapshot'],true,512,JSON_THROW_ON_ERROR);$proof=$snapshot['einvoice'];
ok($proof['kosit']['state']==='valid'&&$proof['validation']['pdfa']==='3b','issued canonical original has both native proofs');
ok(hash_equals($original['sha256'],$proof['validation']['sha256'])&&hash_equals(hash('sha256',$proof['xml']),$proof['validation']['xml_sha256']),'stored original and XML bind native proofs');
$queued=$db->query("SELECT document_id,document_sha256 FROM outgoing_invoice_deliveries WHERE invoice_id=".$db->quote($id))->fetch();
ok($queued['document_id']===$invoice['document_id']&&$queued['document_sha256']===$original['sha256'],'mail references identical hybrid original, no plain PDF fallback');
ok($files->file($invoice['document_id'],1)===$original,'later payment keeps original bytes and metadata unchanged');
$probe=$service->save('',0,$data,$key());$service->review($probe,1);
$before=[];foreach(['finance_entries','number_counters','documents','outgoing_invoices','outgoing_invoice_history'] as $table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
$runtime=getenv('VEREIN_KOSIT_RUNTIME');putenv('VEREIN_KOSIT_RUNTIME=/missing-validator');
try{denied(fn()=>$service->issue($probe,2),'missing validator blocks complete issuance');}finally{putenv('VEREIN_KOSIT_RUNTIME='.$runtime);}
foreach($before as $table=>$rows)ok($db->query('SELECT * FROM '.$table)->fetchAll()===$rows,'failed validation rolls back '.$table);
$bad=$data;$bad['buyer_reference']='';$draft=$service->save('',0,$bad,$key());denied(fn()=>$service->review($draft,1),'draft may be saved, incomplete reference cannot pass review');
$correction=$service->get($credit);$corrected=json_decode($files->file($correction['document_id'],1)['snapshot'],true,512,JSON_THROW_ON_ERROR);
$creditDom=new DOMDocument();$creditDom->loadXML($corrected['einvoice']['xml']);$creditXp=new DOMXPath($creditDom);
ok($corrected['einvoice']['kosit']['state']==='valid'&&$creditXp->evaluate('string(//*[local-name()="ExchangedDocument"]/*[local-name()="TypeCode"])')==='381','partial credit original contains validated credit XML');
ok(str_contains($corrected['einvoice']['xml'],$invoice['number']),'credit XML refers to original invoice');
ok($service->xml($id)===$proof['xml'],'XML download returns canonical embedded bytes');
denied(fn()=>(new \Kulturhus\Verein\OutgoingInvoices($db,'foreign'))->xml($id),'XML download denies other tenant');
denied(fn()=>(new \Kulturhus\Verein\OutgoingInvoices($db,'member'))->xml($id),'XML download denies missing finance permission');
$download=FinanceWorkflowActions::handle($db,'admin',['action'=>'outgoing-xml','invoice'=>$id,'version'=>'3']);
ok($download['mime']==='application/xml'&&$download['bytes']===$proof['xml'],'download action provides exact canonical XML');
require_once __DIR__.'/../src/Web/View.php';
$renderInvoice=static function(array $query,array $post=[])use($db):string{
 $finance=new \Kulturhus\Verein\Finance($db,'admin');$_SESSION=['actor'=>'admin','csrf'=>'synthetic'];$_GET=$query;$_POST=$post;ob_start();try{require __DIR__.'/../views/Finance/Accounting/finance-outgoing.php';return ob_get_contents();}finally{ob_end_clean();}
};
$form=$renderInvoice(['new_invoice'=>'1']);$htmlDom=new DOMDocument();@$htmlDom->loadHTML('<?xml encoding="utf-8" ?>'.$form);$htmlXp=new DOMXPath($htmlDom);
ok($htmlXp->query('//input[@name="seller_contact"]')->length===1&&$htmlXp->query('//input[@name="domestic_confirmed" and not(@checked)]')->length===1,'real invoice form renders contact and unchecked explicit country confirmation');
ok(!str_contains($form,'declare(strict_types')&&!str_contains($form,'new OutgoingInvoices')&&$htmlXp->query('//form[@method="post"]')->length===1,'view executes PHP without source disclosure or broken form tags');
$issuedView=$renderInvoice(['invoice'=>$id]);ok(str_contains($issuedView,'value="outgoing-xml"')&&!str_contains($issuedView,'data-outgoing-form'),'issued view offers XML but no editable original');
$finance=new \Kulturhus\Verein\Finance($db,'admin');$r=$finance->entry($entry);$documents=$files;$outgoingReady=true;ob_start();require __DIR__.'/../views/Finance/Invoices/outgoing-hybrid-status.php';$statusHtml=ob_get_clean();
ok(str_contains($statusHtml,'ZUGFeRD-PDF herunterladen')&&!str_contains($statusHtml,'outgoing-hybrid-save'),'canonical hybrid appears directly without second conversion step');
$qa=getenv('VEREIN_HYBRID_QA_DIR');if($qa&&is_dir($qa))foreach(['workflow-invoice'=>$id,'workflow-credit'=>$credit,'workflow-cancellation'=>$cancel] as $name=>$invoiceId){$stored=$files->file($service->get($invoiceId)['document_id'],1);file_put_contents($qa.'/'.$name.'.pdf',$stored['bytes']);file_put_contents($qa.'/'.$name.'.xml',$service->xml($invoiceId));}
echo "$n issued workflow checks passed\n";
