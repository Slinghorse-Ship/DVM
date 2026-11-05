<?php declare(strict_types=1);
$tp=static fn(string $text):string=>\Kulturhus\Native\Language\TransferMessages::text($text,$transferMessages);
$tpRoute=receiptPaymentContext($transferEntry,$_GET,false);$tpCurrent=$transferPaymentState['current']??null;$tpForm=$transferPaymentState['form']??null;
$tpEntry=$transferState['invoice']['entry']??$r;$tpOpen=in_array($tpEntry['payment_state'],['open','partial'],true)&&$tpEntry['remaining_cents']>0;
$tpReview=($transferPaymentState['reviews']??[])?reset($transferPaymentState['reviews']):null;
$tpSource=$tpCurrent['source']??($tpReview['source']['preview']['source']??null);
$tpBank=static fn(array $order):array=>['view'=>'finance','tab'=>'bank','account'=>$order['account_id'],'fints'=>1,'payment'=>'transfer','payment_order'=>$order['id']];
?>
<link rel="stylesheet" href="transfer-native.css?v=2">
<section class="card invoice-transfer-payment" id="invoice-transfer" data-transfer-native data-transfer-payment>
<h2><?=e($tp('Zahlung'))?></h2>
<?php if($tpSource):?>
<dl class="data-list"><dt><?=e($tp($tpSource['recipient_kind']==='member-reimbursement'?'Erstattung an':'Empfänger'))?></dt><dd><?=e($tpSource['recipient_contact_name']??$tpSource['recipient']['name'])?></dd><?php if(($tpSource['recipient_contact_name']??$tpSource['recipient']['name'])!==$tpSource['recipient']['name']):?><dt><?=e($tp('Kontoinhaber'))?></dt><dd><?=e($tpSource['recipient']['name'])?></dd><?php endif?><dt>IBAN</dt><dd><?=e($tpSource['recipient']['iban_masked'])?></dd><dt><?=e($tp('Betrag'))?></dt><dd><strong><?=e(euro((int)$tpSource['total_cents']))?></strong></dd><dt><?=e($tp('Vereinskonto'))?></dt><dd><?=e($tpSource['source']['name'].' · '.$tpSource['source']['iban_masked'])?></dd><dt><?=e($tp('Verwendungszweck'))?></dt><dd><?=e($tpSource['purpose'])?></dd></dl>
<?php endif?>
<?php if(!$tpOpen):?>
<p role="status"><?=e($tp('Diese Rechnung ist bezahlt oder abgeschlossen.'))?></p>
<?php elseif($tpCurrent&&($tpCurrent['transport']['pending']??false)):?>
<p role="status" data-transfer-transport-pending><?=e($tp('Der Bankauftrag ist noch offen. Den vorhandenen FinTS-Dialog fortsetzen.'))?></p>
<?php if($transferPaymentState['can_pay']):?><a data-fints-payment-link class="btn btn-solid theme-primary" href="<?=e(url($tpBank($tpCurrent['order'])))?>#fints-payment"><?=e($tp('Bankdialog fortsetzen'))?></a><details><summary><?=e($tp('Bankstatus klären'))?></summary><a class="btn btn-outline theme-secondary" href="<?=e(url($tpRoute+['transfer_order'=>$tpCurrent['order']['id'],'transfer_manual'=>'1']))?>#invoice-transfer"><?=e($tp('Banknachweis zuordnen'))?></a></details><?php endif?>
<?php elseif($tpCurrent&&$tpCurrent['order']['state']==='approved'):?>
<p><?=e($tp(($tpSource['transfer_mode']??'standard')==='instant'?'Echtzeitüberweisung':'Normale Überweisung'))?></p>
<?php if($transferPaymentState['can_pay']):?><a data-fints-payment-link class="btn btn-solid theme-primary" href="<?=e(url($tpBank($tpCurrent['order'])))?>#fints-payment"><?=e($tp('Mit FinTS überweisen'))?></a><?php endif?>
<?php elseif($tpCurrent&&in_array($tpCurrent['order']['state'],['submitted','exported'],true)):?>
<p role="status"><?=e($tp($tpCurrent['order']['state']==='submitted'?'An die Bank übermittelt. Der zugehörige Kontoumsatz kann nach dem Bankabruf zugeordnet werden.':'Für diese Rechnung wurde bereits eine Bankdatei ausgegeben. Vor einer weiteren Zahlung den Bankstatus klären.'))?></p>
<?php if($tpCurrent['order']['bank_reference']!==''):?><p><?=e($tp('Bankreferenz'))?>: <?=e($tpCurrent['order']['bank_reference'])?></p><?php endif?>
<?php elseif($tpForm):?>
<form method="post" action="<?=e(url($tpRoute))?>#invoice-transfer" data-transfer-payment-form>
<?=csrf().hidden('action','transfer-payment').hidden('payment_form',$tpForm['id'])?>
<fieldset class="invoice-transfer-modes"><legend><?=e($tp('Überweisungsart'))?></legend>
<?php foreach($tpForm['input']['choices']as$mode=>$choice):?><label><input class="check" type="radio" name="payment_mode" value="<?=e($mode)?>" <?=$mode===array_key_first($tpForm['input']['choices'])?'checked':''?> required><span><?=e($tp($mode==='instant'?'Echtzeitüberweisung':'Normale Überweisung'))?></span></label><?php endforeach?>
</fieldset><button class="btn btn-solid theme-primary"><?=e($tp('Weiter zur Bank'))?></button>
</form>
<?php elseif(!($transferPaymentState['can_pay']??false)):?>
<p><?=e($tp('Zahlungen können vom berechtigten Vorstand oder Kassenwart ausgelöst werden.'))?></p>
<?php elseif(($transferPaymentState['reason']??'')!==''):?>
<p role="status"><?=e($tp($transferPaymentState['reason']))?></p>
<?php elseif($transferPaymentPost??false):?>
<a class="btn btn-outline theme-secondary" href="<?=e(url($tpRoute))?>#invoice-transfer"><?=e($tp('Zahlungsstand aktualisieren'))?></a>
<?php endif?>
<?php if($tpOpen):?><p class="muted"><?=e($tp('Der Zahlungsstand ergibt sich aus dem zugeordneten Kontoumsatz.'))?></p><div class="actions"><?php if($transferState['can_write']):?><a class="btn btn-outline theme-secondary" data-payment-already-paid href="<?=e(url(array_replace($tpRoute,['payment_match'=>'1','manual_payment'=>'1'])))?>#manual-payment"><?=e($tp('Bereits bezahlt'))?></a><?php endif?><a class="btn btn-outline theme-secondary" href="<?=e(url(array_replace($tpRoute,['payment_match'=>'1'])))?>#invoice-payment"><?=e($tp('Vorhandenen Umsatz zuordnen'))?></a></div><?php endif?>
<?php if($tpCurrent&&$tpCurrent['order']['state']==='prepared'):?><details><summary><?=e($tp('Auftrag ändern'))?></summary><a class="btn btn-outline theme-secondary" href="<?=e(url($tpRoute+['transfer_order'=>$tpCurrent['order']['id'],'transfer_manual'=>'1']))?>#invoice-transfer"><?=e($tp('Vorbereiteten Auftrag öffnen'))?></a></details><?php endif?>
<?php if($transferState['can_write']):?><details><summary><?=e($tp('Zahlungsverlauf'))?></summary><a class="btn btn-outline theme-secondary" href="<?=e(url($tpRoute+['transfer_manual'=>'1','transfer_state'=>'']))?>#invoice-transfer"><?=e($tp('Bisherige Bankaufträge ansehen'))?></a></details><?php endif?>
</section>
