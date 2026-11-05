<?php declare(strict_types=1);
// Included once for each immutable returned-payment original.
$rlText=$prText;$rlReturn=$prOriginal['id'];$rlPosted=in_array(input($_POST,'action'),['link-return-order','revoke-return-order'],true)&&input($_POST,'return_id')===$rlReturn&&input($_POST,'transaction_id')===$t['id'];
$rlDraft=static fn(string$name,string$fallback=''):string=>$rlPosted?input($_POST,$name,$fallback):$fallback;
try{$rlChoices=$finance->paymentReturnOrderChoices($t['id'],$rlReturn);$rlHistory=$finance->paymentReturnOrderHistory($t['id'],$rlReturn);}catch(DomainException$failure){?><p class="error" role="alert"><?=e($rlText($failure->getMessage()))?></p><?php return;}
if(!$rlChoices&&!$rlHistory)return;
$rlCan=$finance->canLinkReturnedBankOrder();
?>
<div class="returned-order-links" data-return-order-workspace="<?=e($rlReturn)?>">
<h3><?=e($rlText('Zurückgekommenen Bankauftrag ausdrücklich zuordnen'))?></h3>
<p><?=e($rlText('Eine zurückgekommene Zahlung allein klärt keinen eingereichten Bankauftrag. Verbinde zuerst Originalzahlung und Rückzahlung vollständig mit ihren Banknachweisen. Ordne dann den tatsächlich betroffenen alten Auftrag gesondert zu.'))?></p>
<?php foreach($rlHistory as$rlOriginal):?>
<article class="notice" data-return-order-history="<?=e($rlOriginal['id'])?>" data-return-order-active="<?=$rlOriginal['active']?'1':'0'?>">
<h4><?=e($rlText($rlOriginal['active']?'Bankauftrag ausdrücklich zugeordnet':'Auftragszuordnung zurückgenommen'))?></h4>
<?php $rlStillResolved=true;if($rlOriginal['active'])try{$rlStillResolved=$finance->paymentReturnResolvesTransfer($rlOriginal['order_id']);}catch(DomainException){$rlStillResolved=false;}if(!$rlStillResolved):?><p class="error" role="alert"><?=e($rlText('Banknachweis oder ursprünglicher Auftrag inzwischen geändert. Vor einer Folgezahlung die Zuordnung und den tatsächlichen Bankstand klären.'))?></p><?php endif?>
<p><?=e($rlOriginal['review']['original_entry']['display_number'].' · '.euro((int)$rlOriginal['review']['order']['order']['total_cents']).' · '.$rlOriginal['review']['order']['order']['bank_reference'])?></p>
<p style="white-space:pre-wrap"><?=e($rlOriginal['reason'])?></p><p style="white-space:pre-wrap"><?=e($rlOriginal['evidence'])?></p>
<a class="btn btn-outline theme-secondary" data-return-order-download href="<?=e(url(array_replace(bankTransactionContext($t['id'],$_GET),['returned_order_original'=>$rlOriginal['id']])))?>"><?=e($rlText('Ursprünglichen Bankauftragsnachweis herunterladen'))?></a>
<?php if(!$rlOriginal['active']):?><p style="white-space:pre-wrap"><?=e($rlOriginal['void_reason'])?></p><?php elseif($rlCan):
$rlRevPosted=$rlPosted&&input($_POST,'action')==='revoke-return-order'&&input($_POST,'link_id')===$rlOriginal['id'];$rlRevReason=$rlRevPosted?input($_POST,'order_reason'):'';$rlRevHash=$rlRevPosted?input($_POST,'order_review_hash'):'';$rlRevReview=null;$rlRevError='';
if($rlRevReason!=='')try{$rlRevReview=$finance->paymentReturnOrderRevocationReview($t['id'],$rlOriginal['id'],$rlRevReason);}catch(DomainException$failure){$rlRevError=$failure->getMessage();}
if($rlRevPosted&&($returnOrderReviewed??false)&&$rlRevReview)$rlRevHash=$rlRevReview['hash'];
?>
<details <?=$rlRevPosted?'open':''?>><summary><?=e($rlText('Falsche Auftragszuordnung zurücknehmen'))?></summary>
<p><?=e($rlText('Laufende Folgeaufträge zuerst klären. Nach der Rücknahme sperrt der alte eingereichte Auftrag erneut die Vorbereitung einer weiteren Zahlung. Originale und Geldbewegungen bleiben erhalten.'))?></p>
<?php if($rlRevError!==''):?><p class="error" role="alert"><?=e($rlText($rlRevError))?></p><?php endif?>
<form method="post" data-return-order-revoke>
<?=csrf().hidden('action','revoke-return-order').hidden('transaction_id',$t['id']).hidden('return_id',$rlReturn).hidden('link_id',$rlOriginal['id']).hidden('order_review_hash',$rlRevHash).hidden('request_key',$rlRevPosted?input($_POST,'request_key'):bin2hex(random_bytes(16)))?>
<label><?=e($rlText('Grund der Rücknahme'))?><textarea class="form-control" name="order_reason" rows="3" maxlength="1000" required><?="\n".e($rlRevReason)?></textarea></label>
<button class="btn btn-outline theme-secondary" name="order_move" value="preview" formnovalidate><?=e($rlText('Rücknahme aktuell prüfen'))?></button>
<?php if($rlRevReview&&hash_equals($rlRevHash,$rlRevReview['hash'])):?><p class="notice" data-return-order-revoke-reviewed><?=e($rlText('Die Rücknahme ist aktuell geprüft. Es besteht kein ungeklärter Folgeauftrag.'))?></p><?php endif?>
<label class="check"><input type="checkbox" name="order_confirm" value="1" <?=$rlRevPosted&&!($returnOrderReviewed??false)&&input($_POST,'order_confirm')==='1'?'checked':''?>><span><?=e($rlText('Ich bestätige die Rücknahme dieser Auftragszuordnung. Den tatsächlichen Bankausgang kläre ich vor einer weiteren Zahlung.'))?></span></label>
<button class="btn btn-solid theme-primary" name="order_commit" value="1"><?=e($rlText('Auftragszuordnung zurücknehmen'))?></button>
</form></details>
<?php endif?></article><?php endforeach?>
<?php if($rlCan):
$rlLinkPosted=$rlPosted&&input($_POST,'action')==='link-return-order';$rlOrder=$rlLinkPosted?input($_POST,'order_id'):'';$rlReason=$rlLinkPosted?input($_POST,'order_reason'):'';$rlEvidence=$rlLinkPosted?input($_POST,'order_evidence'):'';$rlHash=$rlLinkPosted?input($_POST,'order_review_hash'):'';$rlReview=null;$rlError='';
if($rlOrder!==''&&$rlReason!==''&&$rlEvidence!=='')try{$rlReview=$finance->paymentReturnOrderReview($t['id'],$rlReturn,$rlOrder,$rlReason,$rlEvidence);}catch(DomainException$failure){$rlError=$failure->getMessage();}
if($rlLinkPosted&&($returnOrderReviewed??false)&&$rlReview)$rlHash=$rlReview['hash'];
?>
<details <?=$rlLinkPosted?'open':''?>><summary><?=e($rlText('Betroffenen ursprünglichen Bankauftrag prüfen'))?></summary>
<p><?=e($rlText('Nur mit bestätigter Zahlungsberechtigung. Grund: höchstens 500 Zeichen. Banknachweis: höchstens 2000 Zeichen. Dieser Schritt erfasst nur die Zuordnung und sendet keine Zahlung.'))?></p>
<?php if($rlError!==''):?><p class="error" role="alert"><?=e($rlText($rlError))?></p><?php endif?>
<form method="post" data-return-order-link>
<?=csrf().hidden('action','link-return-order').hidden('transaction_id',$t['id']).hidden('return_id',$rlReturn).hidden('order_review_hash',$rlHash).hidden('request_key',$rlLinkPosted?input($_POST,'request_key'):bin2hex(random_bytes(16)))?>
<label><?=e($rlText('Ursprünglicher eingereichter Bankauftrag'))?><select class="form-control" name="order_id" required><option value=""><?=e($rlText('Bankauftrag bewusst auswählen'))?></option>
<?php $rlKnown=false;foreach($rlChoices as$rlChoice):$rlKnown=$rlKnown||$rlOrder===$rlChoice['id'];?><option value="<?=e($rlChoice['id'])?>" <?=$rlOrder===$rlChoice['id']?'selected':''?>><?=e($rlChoice['entry']['display_number'].' · '.dateDe($rlChoice['execution_date']).' · '.euro((int)$rlChoice['total_cents']).' · '.$rlChoice['bank_reference'])?></option><?php endforeach?>
<?php if($rlOrder!==''&&!$rlKnown):?><option selected value="<?=e($rlOrder)?>"><?=e($rlOrder)?></option><?php endif?></select></label>
<label><?=e($rlText('Grund der Bankauftragszuordnung'))?><textarea class="form-control" name="order_reason" rows="3" maxlength="1000" required><?="\n".e($rlReason)?></textarea></label>
<label><?=e($rlText('Tatsächlicher Banknachweis für genau diesen Auftrag'))?><textarea class="form-control" name="order_evidence" rows="4" maxlength="4000" required><?="\n".e($rlEvidence)?></textarea></label>
<button class="btn btn-outline theme-secondary" name="order_move" value="preview" formnovalidate><?=e($rlText('Bankauftrag und beide Banknachweise aktuell prüfen'))?></button>
<?php if($rlReview&&$rlHash!==''&&hash_equals($rlHash,$rlReview['hash'])):$rlFacts=$rlReview['review'];?><div class="notice" data-return-order-reviewed>
<h4><?=e($rlText('Geprüfter Bankauftrag und vollständige Bankbezüge'))?></h4>
<dl class="data-list"><dt><?=e($rlText('Ursprünglicher eingereichter Bankauftrag'))?></dt><dd><?=e($rlFacts['order']['order']['bank_reference'].' · '.dateDe($rlFacts['order']['order']['execution_date']).' · '.euro((int)$rlFacts['order']['order']['total_cents']))?></dd><dt><?=e($rlText('Empfänger'))?></dt><dd><?=e($rlFacts['order']['source']['recipient']['name'].' · '.$rlFacts['order']['source']['recipient']['iban_masked'])?></dd></dl>
<?php foreach($rlFacts['payment']as$rlMoney):?><p><?=e(dateDe($rlMoney['booked_on']).' · '.$rlMoney['account_name'].' · '.euro((int)$rlMoney['cents']).' · '.euro((int)$rlMoney['bank_proved_cents']))?> <a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($rlMoney['id'],$_GET)))?>" target="_blank" rel="noopener"><?=e($rlText('Zahlung mit Banknachweisen öffnen'))?></a></p><ul><?php foreach($rlFacts['bank_proofs'][$rlMoney['id']]??[]as$rlProof):?><li><a class="btn btn-outline theme-secondary" href="<?=e(url(array_replace(bankImportContext($_GET),['import_record'=>$rlProof['record_id']])))?>" target="_blank" rel="noopener"><?=e($rlText('Bankzeile prüfen').' · '.dateDe($rlProof['booked_on']).' · '.euro((int)$rlProof['evidence_cents']))?></a></li><?php endforeach?></ul><?php endforeach?>
<a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$rlFacts['original_entry']['id'],'transfer_order'=>$rlOrder]))?>" target="_blank" rel="noopener"><?=e($rlText('Ursprünglichen Bankauftrag öffnen'))?></a>
<p><?=e($rlText('Der ausgewählte alte Auftrag gilt danach als vollständig zurückgekommen. Eine erneute Zahlung erfordert einen eigenen neu geprüften beleggebundenen Auftrag.'))?></p>
</div><?php elseif($rlHash!==''):?><p class="notice" role="status"><?=e($rlText('Der gespeicherte Prüfstand ist nicht mehr aktuell. Auswahl und Angaben bleiben erhalten. Erneut prüfen und danach gesondert bestätigen.'))?></p><?php endif?>
<label class="check"><input type="checkbox" name="order_confirm" value="1" <?=$rlLinkPosted&&!($returnOrderReviewed??false)&&input($_POST,'order_confirm')==='1'?'checked':''?>><span><?=e($rlText('Ich bestätige anhand der Banknachweise: Genau dieser eingereichte Bankauftrag ist vollständig zurückgekommen.'))?></span></label>
<button class="btn btn-solid theme-primary" name="order_commit" value="1"><?=e($rlText('Zurückgekommenen Bankauftrag zuordnen'))?></button>
</form></details><?php endif?>
</div>
