<?php declare(strict_types=1);
// Render the actual views against an isolated synthetic invoice; no HTTP/bank calls.
require_once __DIR__.'/../src/Web/View.php';
$savedEnvironment=getenv('VEREIN_MEMBER_BANK_KEY_PATH');
$savedSession=$_SESSION??[];$savedGet=$_GET??[];$savedPost=$_POST??[];
putenv('VEREIN_MEMBER_BANK_KEY_PATH='.$path);
$_SESSION=['actor'=>'treasurer','csrf'=>'synthetic-only'];$_POST=[];
try {
 $renderBank=static function(array $state,string $id)use($db,$finance):string {
  $_GET=['view'=>'finance','tab'=>'bank','payment'=>'transfer','payment_order'=>$id];
  $canFinance=true;$fintsAvailable=true;$fintsStatus=$state;
  $fintsCredentialStatus=['saved'=>true,'expires'=>0,'bank_id'=>''];
  $fintsDefaultBank=['name'=>'Synthetic bank'];
  ob_start();try{require __DIR__.'/../views/Finance/Banking/fints-bank.php';return ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}
 };
 $base=['version'=>3,'task'=>'transfer','payment_order'=>$instantOrder];
 $html=$renderBank($base+['phase'=>'accounts'],$instantOrder);
 check(substr_count($html,'value="fints-transfer-start"')===1,'one explicit send action on payment summary');
 check(str_contains($html,'125,50 € überweisen')&&str_contains($html,'DE12 5001 0517 0648 4898 90'),'amount button and readable full recipient IBAN');
 check(!str_contains($html,'Schritt 1 von')&&!str_contains($html,'direkt über FinTS')&&!str_contains($html,'So wird dieser Auftrag'),'technical instructions and duplicate step lists removed');
 foreach(['tan','decoupled','poll'] as $phase){
  $html=$renderBank($base+['phase'=>$phase,'challenge'=>'Synthetic challenge'],$instantOrder);
  check(substr_count($html,'value="fints-continue"')===1&&!str_contains($html,'value="fints-transfer-start"'),'only current bank confirmation shown '.$phase);
  check(str_contains($html,'name="tan"')===($phase==='tan'),'TAN input only for actual TAN request '.$phase);
  if($phase==='poll')check(!str_contains($html,'Öffnen Sie Ihre Banking-App'),'poll does not wrongly ask for app approval');
 }
 $html=$renderBank($base+['phase'=>'vop','vop_result'=>'CompletedNoMatch','vop_info'=>'<script>unsafe</script>'],$instantOrder);
 check(str_contains($html,'name="acknowledge_vop_warning" value="1" required')&&str_contains($html,'Trotz Warnung weiter'),'explicit payee risk acknowledgement preserved');
 check(!str_contains($html,'<script>unsafe</script>')&&str_contains($html,'&lt;script&gt;'),'bank text remains escaped');
 $html=$renderBank($base+['phase'=>'vop','vop_result'=>'CompletedFullMatch','vop_expires'=>date(DATE_ATOM,time()-10)],$instantOrder);
 check(!str_contains($html,'name="confirm_vop"')&&str_contains($html,'Prüfung ist abgelaufen'),'expired payee screen has no confirmation');
 $html=$renderBank($base+['phase'=>'payment_done','payment_success'=>'Synthetic bank accepted'],$instantOrder);
 check(str_contains($html,'Bei der Bank eingereicht.')&&!str_contains($html,'value="fints-transfer-start"')&&!str_contains($html,'value="fints-start-saved"'),'finished payment cannot accidentally restart from success screen');
 $html=$renderBank(['phase'=>'accounts'],str_repeat('f',32));
 check(!str_contains($html,'value="fints-start-saved"')&&!str_contains($html,'value="fints-statement"'),'missing payment does not fall through to another banking flow');
 $html=$renderBank($base+['phase'=>'interrupted'],$instantOrder);
 check(str_contains($html,'Nicht einfach erneut senden.'),'interrupted payment retains duplicate-payment warning');
 $_GET=['view'=>'finance','tab'=>'receipts','entry'=>$invoice];
 $renderReceipt=static function()use($db,$finance,$invoice):string{
  $r=$finance->entry($invoice);$approval=['status'=>'approved'];ob_start();
  try{require __DIR__.'/../views/Finance/Banking/transfer-order.php';return ob_get_clean();}catch(Throwable $e){ob_end_clean();throw $e;}
 };
 $html=$renderReceipt();
 check(str_contains($html,'Weiter zur Bank')&&!str_contains($html,'value="transfer-order-preview"'),'existing active order opens directly instead of a duplicate preparation form');
 check(str_contains($html,'Fiktives Onlinekonto')&&!str_contains($html,'<dd>'.$bank.'</dd>'),'account displayed by name not internal id');
 // Idempotent approval continuation only redirects; it does not send to a bank.
 $stored=$orders->get($instantOrder);
 $route=\Kulturhus\Verein\FinanceWorkflowActions::handle($db,'treasurer',[
  'action'=>'transfer-order-confirm','entry'=>$invoice,'account'=>$bank,'execution_date'=>date('Y-m-d'),
  'purpose'=>'Synthetic instant','transfer_mode'=>'instant','preview_hash'=>$stored['snapshot_hash'],
  'request_key'=>$stored['request_key'],'continue_to_bank'=>'1'
 ])['route'];
 check($route['tab']==='bank'&&$route['payment_order']===$instantOrder,'confirmation goes straight to bank page without extra order page');
 check($orders->get($instantOrder)['state']==='approved'&&$finance->transactions()===[],'direct continuation preserves approval and sends no money');
}finally{
 putenv($savedEnvironment===false?'VEREIN_MEMBER_BANK_KEY_PATH':'VEREIN_MEMBER_BANK_KEY_PATH='.$savedEnvironment);
 $_SESSION=$savedSession;$_GET=$savedGet;$_POST=$savedPost;
}
