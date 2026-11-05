<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
$prText=$bankProofText;$prPosted=input($_POST,'action')==='return-payment'&&input($_POST,'transaction_id')===$t['id'];
$prDraft=static fn(string $name,string $fallback=''):string=>$prPosted?input($_POST,$name,$fallback):$fallback;
$prEntryIds=[];foreach($allocationRows??[]as$prLink)if($prLink['voided_at']===null)$prEntryIds[$prLink['entry_id']]=true;
if(!$prPosted&&count($prEntryIds)<1&&!$t['reversal_of']&&!$t['reversed_on'])return;
try{$prHistory=$transactionPaymentReturns??$finance->paymentReturnHistory($t['id']);}
catch(DomainException $failure){if($prPosted):?><p class="error" role="alert"><?=e($prText($failure->getMessage()))?></p><?php endif;return;}
if(!$prHistory&&!$prPosted&&count($prEntryIds)<1)return;
if(!$prPosted&&!$prHistory&&($t['reversal_of']||$t['reversed_on']))return;
$prSourceError='';$prSource=['entries'=>[],'originals'=>[],'eligible'=>false,'available'=>false];
if(!$prHistory||$prPosted)try{$prSource=$finance->paymentReturnSource($t['id']);}catch(DomainException $failure){$prSourceError=$failure->getMessage();if(!$prPosted&&!$prHistory)return;}
if(!$prHistory&&!$prPosted&&(!$finance->canWrite()||count($prSource['entries'])<1))return;
?><link rel="stylesheet" href="payment-reversals.css?v=1">
<?php foreach($prHistory as$prOriginal):?>
<section class="card payment-reversal-workspace" data-payment-return-accepted="<?=e($prOriginal['id'])?>">
<h2><?=e($prText('Zurückgekommene Zahlung zugeordnet'))?></h2>
<p><?=e($prText('Die vorhandene Rückzahlung ist mit der ursprünglichen Zahlung verbunden. Die aufgeführten Forderungen bestehen weiter. Es wurde kein weiterer Kontoumsatz erfasst.'))?></p>
<p><?=e(dateDe($prOriginal['result']['date']).' · '.euro((int)$prOriginal['cents']))?></p>
<p style="white-space:pre-wrap"><?=e($prOriginal['reason'])?></p>
<ul><?php foreach($prOriginal['review']['source']['entries']as$prEntry):?><li><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$prEntry['id']]))?>"><?=e(($prEntry['display_number']??'').' · '.$prEntry['description'].' · '.euro((int)$prEntry['amount_cents']))?></a></li><?php endforeach?></ul>
<div class="actions"><a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($prOriginal['source_id'],$_GET)))?>"><?=e($prText('Ursprüngliche Zahlung öffnen'))?></a><a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($prOriginal['returned_id'],$_GET)))?>"><?=e($prText('Vorhandene vollständige Rückzahlung öffnen'))?></a></div>
<a class="btn btn-outline theme-secondary" data-payment-return-download href="<?=e(url(array_replace(bankTransactionContext($t['id'],$_GET),['payment_return_original'=>$prOriginal['id']])))?>"><?=e($prText('Ursprünglichen Rückzahlungsnachweis herunterladen'))?></a>
<?php require __DIR__.'/transaction-return-order.php';?>
</section>
<?php endforeach;
if($prHistory&&!$prPosted)return;
if(!$finance->canWrite())return;
?><p class="notice"><?=e($prText('Für einen Teilbetrag oder mehrere ursprüngliche Zahlungsquellen die vorhandene Rückzahlung öffnen und dort „Teilbetrag oder mehrere ursprüngliche Zahlungen zurückgekommen“ verwenden. Auch ein anderes eigenes Konto oder eine Kasse ist möglich.'))?> <a class="btn btn-outline theme-secondary" data-partial-return-find href="<?=e(url(['view'=>'finance','tab'=>'bank','account'=>$t['account_id'],'bank_direction'=>(int)$t['cents']>0?'expense':'income']))?>"><?=e($prText('Vorhandene Rückzahlung in Bank & Kasse suchen'))?></a></p><?php
if(!$prSource['eligible']&&!$prPosted):?><section class="card payment-reversal-workspace"><h2><?=e($prText('Zahlung und fortbestehende Forderungen zuerst klären'))?></h2><p><?=e($prText('Hier lässt sich eine vollständige zurückgekommene Zahlung mit allen ursprünglichen Forderungen verbinden. Für Teilbeträge und mehrere Zahlungsquellen den Teilrückzahlungsweg an der vorhandenen Rückzahlung verwenden. Bescheinigte Spenden, private Vorleistungen, laufende Bankaufträge und Rechnungskorrekturen zuerst klären.'))?></p></section><?php return;endif;
$prAccepted=(bool)$prHistory;$prSelected=$prDraft('payment_return_counter_id');$prCounter=null;$prError=$prSourceError;$prReview=null;
if($prSelected!=='')try{$prCounter=$finance->transactionDetail($prSelected);if($prSource['eligible'])$prReview=$finance->paymentReturnReview($t['id'],$prSelected,$prDraft('payment_return_reason'));}catch(DomainException $failure){$prError=$failure->getMessage();}
$prRefresh=($paymentReturnReviewed??false)&&!$prAccepted;
$prHash=$prDraft('payment_return_review_hash');$prVersion=$prDraft('payment_return_source_version',(string)$t['version']);$prCounterVersion=$prDraft('payment_return_counter_version',(string)($prCounter['version']??0));
if($prRefresh&&$prReview){$prHash=$prReview['hash'];$prVersion=(string)$prReview['review']['source']['transaction']['version'];$prCounterVersion=(string)$prReview['review']['returned']['version'];}
$prCurrent=$prReview&&$prHash!==''&&hash_equals($prHash,$prReview['hash']);$prCandidates=null;
if($prSource['eligible'])try{$prCandidates=$finance->paymentReturnCandidatesPage($t['id'],$prDraft('payment_return_counter_page','1'),$prDraft('payment_return_counter_q'));}catch(DomainException $failure){$prError=$failure->getMessage();}
?>
<details class="card payment-reversal-workspace" data-payment-return-workspace <?= $prPosted?'open':'' ?>>
<summary><?=e($prText('Zahlung zurückgekommen – Forderungen bleiben offen'))?></summary>
<p><?=e($prText('Die vollständige ursprüngliche Zahlung ist tatsächlich zurückgekommen. Alle unten aufgeführten Forderungen bleiben bestehen und werden wieder offen. Wähle die bereits erfasste vollständige Rückzahlung auf demselben Konto.'))?></p>
<p class="notice"><?=e($prText('Die Originalbelege werden nicht storniert. Dieser Schritt löst keine Überweisung aus. Für Teilrückzahlungen und mehrere ursprüngliche Quellen den Teilrückzahlungsweg an der vorhandenen Rückzahlung verwenden. Für laufende Lastschriften den Rücklastschriftweg verwenden.'))?></p>
<?php if($prPosted&&!$prRefresh):?><p class="notice" role="status"><?=e($prText('Ursprüngliche Auswahl, Grund, Prüfstand und Bestätigung bleiben erhalten. Zuerst den gespeicherten Ausgang prüfen; bei Änderungen erneut prüfen und danach gesondert bestätigen.'))?></p><?php endif?>
<?php if(!$prSource['eligible']&&!$prAccepted):?><p class="error" role="alert"><?=e($prText('Diese Zahlung lässt sich inzwischen nicht mehr vollständig zurückführen. Ursprüngliche Forderungen und vorhandene Rückzahlung zuerst klären.'))?></p><?php endif?>
<?php if($prError!==''):?><p class="error" role="alert"><?=e($prText($prError))?></p><?php endif?>
<h3><?=e($prText('Alle ursprünglichen fortbestehenden Forderungen'))?></h3>
<ul><?php foreach($prSource['entries']as$prEntry):?><li><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$prEntry['id']]))?>" target="_blank" rel="noopener"><?=e(($prEntry['display_number']??'').' · '.$prEntry['description'].' · '.euro((int)$prEntry['amount_cents']))?></a>
<?php foreach($prSource['originals'][$prEntry['id']]??[]as$prDocument):?><a class="btn btn-outline theme-secondary" href="<?=e(url(['document_file'=>$prDocument['document']['id'],'revision'=>$prDocument['file']['revision']]))?>" target="_blank" rel="noopener"><?=e($prText('Ursprüngliche Belegdatei öffnen'))?></a><?php endforeach?></li><?php endforeach?></ul>
<form method="post" data-payment-return>
<?=csrf().hidden('action','return-payment').hidden('transaction_id',$t['id']).hidden('payment_return_source_version',$prVersion).hidden('payment_return_counter_id',$prSelected).hidden('payment_return_counter_version',$prCounterVersion).hidden('payment_return_review_hash',$prHash).hidden('request_key',$prDraft('request_key',bin2hex(random_bytes(16))))?>
<label><?=e($prText('Grund der zurückgekommenen Zahlung'))?><textarea class="form-control" name="payment_return_reason" rows="4" maxlength="1000" required <?= $prAccepted?'readonly':'' ?>><?="\n".e($prDraft('payment_return_reason'))?></textarea></label><p class="muted"><?=e($prText('Höchstens 500 Zeichen. Die tatsächliche vollständige Rückzahlung und das Fortbestehen aller ursprünglichen Forderungen beschreiben.'))?></p>
<?php if($prCounter):?><div class="notice" data-payment-return-selected="<?=e($prCounter['id'])?>"><strong><?=e($prText('Vorgewählte vorhandene Rückzahlung'))?></strong><p><?=e(dateDe($prCounter['booked_on']).' · '.$prCounter['account_name'].' · '.euro((int)$prCounter['cents']))?></p><p><?=e($prCounter['description'])?></p><?php if(!$prAccepted):?><button class="btn btn-outline theme-secondary" name="payment_return_move" value="clear" formnovalidate><?=e($prText('Rückzahlungsauswahl leeren'))?></button><?php endif?></div><?php endif?>
<?php if(!$prAccepted&&$prSource['eligible']):?>
<h3 data-payment-return-page="<?= (int)($prCandidates['page']??1)?>"><?=e($prText('Vorhandene vollständige Rückzahlung auswählen'))?></h3>
<div class="filter-form"><label><?=e($prText('Vollständige Rückzahlung suchen'))?><input class="form-control" name="payment_return_counter_q" maxlength="300" value="<?=e($prDraft('payment_return_counter_q'))?>"></label><button class="btn btn-outline theme-secondary" name="payment_return_move" value="search" formnovalidate><?=e($prText('Rückzahlungen suchen'))?></button></div>
<?php foreach($prCandidates['rows']??[]as$prCandidate):?><article class="matching-candidate" data-payment-return-candidate="<?=e($prCandidate['id'])?>"><div><strong><?=e(dateDe($prCandidate['booked_on']).' · '.euro((int)$prCandidate['cents']))?></strong><p><?=e($prCandidate['description'].' · '.$prCandidate['counterparty'])?></p></div><button class="btn btn-outline theme-secondary" name="choose_payment_return_counter" value="<?=e($prCandidate['id'])?>" formnovalidate><?=e($prText('Diese vollständige Rückzahlung verwenden'))?></button></article><?php endforeach?>
<?php if($prCandidates&&!$prCandidates['rows']):?><p><?=e($prText('Keine passende noch nicht zugeordnete vollständige Rückzahlung gefunden.'))?></p><?php endif?>
<div class="filter-form"><p><?=e($prText('Rückzahlungsseiten'))?>: <?= (int)($prCandidates['page']??1)?> / <?= (int)($prCandidates['pages']??1)?> · <?= (int)($prCandidates['total']??0)?></p><label><?=e($prText('Rückzahlungsseite'))?><input class="form-control" name="payment_return_counter_page" inputmode="numeric" value="<?=e($prDraft('payment_return_counter_page',(string)($prCandidates['page']??1)))?>"></label><button class="btn btn-outline theme-secondary" name="payment_return_move" value="page" formnovalidate><?=e($prText('Rückzahlungsseite anzeigen'))?></button></div>
<button class="btn btn-outline theme-secondary" name="payment_return_move" value="preview" formnovalidate><?=e($prText('Alle Forderungen und Rückzahlung aktuell prüfen'))?></button>
<?php endif?>
<?php if($prCurrent):?><div class="notice" data-payment-return-reviewed><h3><?=e($prText('Geprüfte vollständige Zahlungsrückführung'))?></h3><dl class="data-list"><dt><?=e($prText('Ursprüngliche vollständige Zahlung'))?></dt><dd><?=e(euro((int)$prReview['review']['source']['transaction']['cents']))?></dd><dt><?=e($prText('Bereits erfasste vollständige Rückzahlung'))?></dt><dd><?=e(euro((int)$prReview['review']['returned']['cents']))?></dd><dt><?=e($prText('Tatsächliches Datum der Rückzahlung'))?></dt><dd><?=e(dateDe($prReview['review']['returned']['booked_on']))?></dd></dl><p><?=e($prText('Alle aufgeführten Forderungen werden wieder offen. Originalbelege, ursprüngliche Zahlungszuordnungen und Kontostand bleiben erhalten.'))?></p></div><?php elseif($prHash!==''&&!$prAccepted):?><p class="notice" role="status"><?=e($prText('Der ursprünglich geprüfte Rückzahlungsstand ist nicht mehr aktuell. Auswahl und Angaben bleiben erhalten. Alle Forderungen erneut prüfen und anschließend gesondert bestätigen.'))?></p><?php endif?>
<label class="check"><input type="checkbox" name="payment_return_confirm" value="1" <?= $prDraft('payment_return_confirm')==='1'&&!$prRefresh?'checked':'' ?>><span><?=e($prText('Ich bestätige diese bereits erfasste vollständige Rückzahlung. Alle aufgeführten ursprünglichen Forderungen bestehen weiter und werden wieder offen.'))?></span></label>
<?php if($prSource['eligible']||$prAccepted):?><button class="btn btn-solid theme-primary" name="payment_return_commit" value="1"><?=e($prText($prAccepted?'Ursprüngliche Zahlungsrückführung erneut prüfen':'Rückzahlung zuordnen und Forderungen öffnen'))?></button><?php endif?>
</form></details>
