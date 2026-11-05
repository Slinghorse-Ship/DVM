<?php declare(strict_types=1);
// Included by transfer-orders.php while the synthetic order is approved.
require_once __DIR__.'/../src/Finance/Banking/FinTsDialog.php';
$savedKey=getenv('VEREIN_MEMBER_BANK_KEY_PATH');putenv('VEREIN_MEMBER_BANK_KEY_PATH='.$path);
$fintsDir=$dir.'/fints';mkdir($fintsDir,0700);file_put_contents($fintsDir.'/fints-state.key',random_bytes(32));chmod($fintsDir.'/fints-state.key',0600);
file_put_contents($fintsDir.'/fints.json',json_encode(['url'=>'https://bank.example.invalid/fints','bank_code'=>'12345678','product_id'=>'SYNTHETIC0000000000000000','product_version'=>'1','club_id'=>'a']));
$engine=new class {
 public int $calls=0;public string $phase='tan';public string $expectedXml='';public string $expectedMode='standard';
 public function advance(array $s,string $command,array $input):array{
  ++$this->calls;
  if($command==='discover')return array_replace($s,['phase'=>'accounts','accounts'=>[['uid'=>'test','iban'=>'DE89370400440532013000']]]);
  if($command==='transfer'){
   if($input['xml']!==$this->expectedXml||$input['transfer_mode']!==$this->expectedMode)throw new RuntimeException('Untrusted XML or mode reached engine');
   return array_replace($s,['task'=>'transfer','phase'=>$this->phase,'payment_order'=>$input['order_id'],'payment_order_version'=>$input['order_version']]);
  }
  if($command==='continue')return array_replace($s,['phase'=>'payment_done']);
  throw new RuntimeException('Unexpected command');
 }
};
$savedPaymentInvoice=$invoice;$savedPaymentOrder=$order;$paymentSource=$orders->get($order)['snapshot'];
try{
 foreach(['tan','decoupled','poll','vop'] as $phase){
  // Every successful bank confirmation consumes its own genuine invoice and order.
  $invoice=$finance->createEntry(['kind'=>'expense','description'=>'Own '.$phase.' bank confirmation','counterparty'=>'Fiktive Lieferfirma GmbH','amount'=>'125.50','document_date'=>'2026-09-09','due_date'=>'2026-09-20','category'=>'inventory'],$key());
  $db->prepare('INSERT INTO finance_supplier_contacts SELECT club_id,?,contact_id,contact_version,supplier_name,actor_id,created_at FROM finance_supplier_contacts WHERE club_id=? AND entry_id=?')->execute([$invoice,'a',$savedPaymentInvoice]);
  (new \Kulturhus\Verein\Documents($db,'treasurer'))->upload('own-'.$phase.'.png',$png,['title'=>'Own '.$phase.' invoice original','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$invoice]],$key());
  $state=$approval->state($invoice);$approval->approve($invoice,$state['hash'],$state['decision_id'],$key(),true);
  $mode=$paymentSource['transfer_mode']??'standard';$preview=$orders->preview($invoice,$bank,$paymentSource['execution_date'],'Own '.$phase.' transfer',$mode);$order=$orders->prepare($invoice,$bank,$preview['execution_date'],$preview['purpose'],$preview['hash'],$key(),$mode);$orders->approve($order,1);
  $engine->phase=$phase;$engine->expectedXml=$orders->fintsPayload($order,2)['xml'];$engine->expectedMode=$orders->fintsPayload($order,2)['transfer_mode'];
  $dialog=new \Kulturhus\Verein\FinTsDialog($db,'treasurer','test-'.$phase,$fintsDir,$engine);
  $dialog->act('start',['user'=>'synthetic','pin'=>'synthetic'],0);
  $before=$engine->calls;denied(fn()=>$dialog->act('transfer',['order_id'=>str_repeat('a',32),'order_version'=>2,'xml'=>'<forged/>'],1),'missing payment order rejected before engine '.$phase);check($engine->calls===$before,'missing order makes zero bank calls '.$phase);
  $dialog->act('transfer',['uid'=>'test','order_id'=>$order,'order_version'=>2,'xml'=>'<forged/>','source_iban'=>'WRONG','transfer_mode'=>$engine->expectedMode==='instant'?'standard':'instant'],1);
  check($dialog->status()['phase']===$phase,'authoritative server payload replaces browser XML '.$phase);
  if($phase==='vop'){$before=$engine->calls;denied(fn()=>$dialog->act('continue',['confirm_vop'=>'1'],2),'unverified payee requires explicit warning acknowledgement');check($engine->calls===$before&&$dialog->status()['version']===2,'warning failure makes no network call and preserves dialog');}
  $state=$approval->state($invoice);$approval->withdraw($invoice,$state['hash'],$state['decision_id'],'Synthetic withdrawal',$key());
  $before=$engine->calls;denied(fn()=>$dialog->act('continue',['tan'=>'fake','confirm_vop'=>'1','acknowledge_vop_warning'=>'1','order_id'=>'different'],2),'withdrawn invoice blocks continuation '.$phase);check($engine->calls===$before,'revoked continuation makes zero bank calls '.$phase);
  denied(fn()=>$orders->export($order,2),'withdrawn invoice blocks initial XML export '.$phase);
  $state=$approval->state($invoice);$approval->approve($invoice,$state['hash'],$state['decision_id'],$key(),true);
  $db->beginTransaction();try{$db->exec("UPDATE actors SET active=0 WHERE id='treasurer'");$before=$engine->calls;denied(fn()=>$dialog->act('continue',[],2),'revoked sender rejected '.$phase);check($engine->calls===$before,'revoked sender makes zero bank calls '.$phase);}finally{$db->rollBack();}
  check($dialog->act('continue',['tan'=>'fake','confirm_vop'=>'1','acknowledge_vop_warning'=>'1'],2)['phase']==='payment_done','current approval allows legitimate continuation '.$phase);
  check($orders->get($order)['state']==='submitted'&&(int)$orders->get($order)['version']===3,'actual bank acceptance consumes exactly its own order '.$phase);
  check($orders->get($order)['snapshot']['xml']===$engine->expectedXml&&$finance->entry($invoice)['payment_state']==='open'&&$finance->transactions()===[],'bank acceptance preserves original XML and books no money '.$phase);
  denied(fn()=>$orders->fintsPayload($order,2),'accepted transfer cannot be sent again '.$phase);
  $dialog->act('cancel',[],3);
 }
 $invoice=$savedPaymentInvoice;$order=$savedPaymentOrder;
 $writerDialog=new \Kulturhus\Verein\FinTsDialog($db,'writer','raw-fallback',$fintsDir,$engine);$writerDialog->act('start',['user'=>'synthetic','pin'=>'synthetic'],0);$before=$engine->calls;
 denied(fn()=>$writerDialog->act('transfer',['uid'=>'test','order_id'=>$order,'order_version'=>2,'xml'=>$engine->expectedXml],1),'finance writer cannot bypass payment office through raw action');
 denied(fn()=>$writerDialog->act('direct-debit',['uid'=>'test','order_id'=>$order,'order_version'=>2,'xml'=>'<forged/>'],1),'direct debit sibling enforces order authority');
 denied(fn()=>$writerDialog->act('transfer-start',[],1),'unsupported command rejected');check($engine->calls===$before,'raw sibling failures make zero bank calls');
}finally{
 $invoice=$savedPaymentInvoice;$order=$savedPaymentOrder;
 putenv($savedKey===false?'VEREIN_MEMBER_BANK_KEY_PATH':'VEREIN_MEMBER_BANK_KEY_PATH='.$savedKey);
 foreach(glob($fintsDir.'/*') as $temporary)unlink($temporary);rmdir($fintsDir);
}
