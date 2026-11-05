<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
$ppText=$bankProofText;$ppPosted=input($_POST,'action')==='return-payment-parts'&&input($_POST,'transaction_id')===$t['id'];$ppVoidPosted=input($_POST,'action')==='revoke-payment-parts'&&input($_POST,'transaction_id')===$t['id'];$ppHistory=[];$ppError='';
try{$ppHistory=$finance->partialReturnHistory($t['id']);}catch(DomainException$failure){$ppError=$failure->getMessage();}
if(!$ppHistory&&!$assignable&&!$ppPosted&&!$ppVoidPosted)return;
?>
<link rel="stylesheet" href="payment-reversals.css?v=1">
<?php if($ppError!==''):?><p class="error" role="alert"><?=e($ppText($ppError))?></p><?php endif?>
<?php foreach($ppHistory as$ppFirst):?>
<section class="card payment-reversal-workspace" data-partial-return-history="<?=e($ppFirst['id'])?>" data-partial-return-active="<?=$ppFirst['active']?'1':'0'?>">
<h2><?=e($ppText($ppFirst['active']?'Rückzahlung auf ursprüngliche Zahlungen verteilt':'Teilrückzahlungszuordnung zurückgenommen'))?></h2>
<p><?=e(euro((int)$ppFirst['cents']).' · '.$ppFirst['created_at'])?></p><p style="white-space:pre-wrap"><?=e($ppFirst['reason'])?></p>
<ul><?php foreach($ppFirst['review']['selected']as$ppSelected):?><li><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$ppSelected['entry']['id']]))?>"><?=e($ppSelected['entry']['display_number'].' · '.$ppSelected['entry']['description'].' · '.euro((int)$ppSelected['amount_cents']))?></a><a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($ppSelected['source']['id'],$_GET)))?>"><?=e($ppText('Ursprüngliche Zahlung öffnen'))?></a></li><?php endforeach?></ul>
<div class="actions"><a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($ppFirst['counter_id'],$_GET)))?>"><?=e($ppText('Vorhandene Rückzahlung öffnen'))?></a><a class="btn btn-outline theme-secondary" data-partial-return-download href="<?=e(url(array_replace(bankTransactionContext($t['id'],$_GET),['partial_return_original'=>$ppFirst['id']])))?>"><?=e($ppText('Ursprünglichen Teilrückzahlungsnachweis herunterladen'))?></a></div>
<?php $ppNative=isset($ppFirst['request']['native_debit'])||\Kulturhus\Verein\NativeDebitReturnHistory::financialBound($db,$t['club_id'],$ppFirst['id']);if($ppNative):?><p class="notice" data-partial-return-native-debit><?=e($ppText('Diese Finanzzuordnung gehört zur dokumentierten Rücklastschrift. Beide ursprünglichen Nachweise bleiben gemeinsam erhalten; die Finanzzuordnung kann hier nicht getrennt zurückgenommen werden.'))?></p><?php endif?>
<?php $ppPrivate=isset($ppFirst['request']['reimbursement']);if($ppPrivate):?><p class="notice"><?=e($ppText('Diese Rückzahlung gehört zur privaten Erstattung. Die Zuordnung und eine mögliche Rücknahme gemeinsam am ursprünglichen Erstattungsfall prüfen.'))?></p><a class="btn btn-outline" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$ppFirst['request']['reimbursement']['entry']]).'#reimbursement-returns')?>"><?=e($ppText('Private Erstattung und Rückzahlungen öffnen'))?></a><?php endif?>
<?php if(!$ppFirst['active']):?><p style="white-space:pre-wrap"><?=e($ppFirst['void_reason'])?></p><?php elseif($finance->canWrite()&&!$ppNative&&!$ppPrivate):
$ppThisVoid=$ppVoidPosted&&input($_POST,'partial_return_id')===$ppFirst['id'];$ppVoidReason=$ppThisVoid?input($_POST,'partial_reason'):'';$ppVoidHash=$ppThisVoid?input($_POST,'partial_review_hash'):'';$ppVoidReview=null;$ppVoidError='';
if($ppVoidReason!=='')try{$ppVoidReview=$finance->partialReturnRevocationReview($t['id'],$ppFirst['id'],$ppVoidReason);}catch(DomainException$failure){$ppVoidError=$failure->getMessage();}
if($ppThisVoid&&($partialReturnReviewed??false)&&$ppVoidReview)$ppVoidHash=$ppVoidReview['hash'];
?>
<details <?=$ppThisVoid?'open':''?>><summary><?=e($ppText('Falsche Teilrückzahlungszuordnung zurücknehmen'))?></summary>
<p><?=e($ppText('Die ursprünglichen Belegcents müssen noch frei sein. Spätere Zahlungen, laufende Bankaufträge und abgeschlossene Kassenzeiträume zuerst klären. Der vorhandene Geldumsatz und der ursprüngliche Nachweis bleiben erhalten.'))?></p>
<?php if($ppVoidError!==''):?><p class="error" role="alert"><?=e($ppText($ppVoidError))?></p><?php endif?>
<form method="post" data-partial-return-revoke>
<?=csrf().hidden('action','revoke-payment-parts').hidden('transaction_id',$t['id']).hidden('partial_return_id',$ppFirst['id']).hidden('partial_review_hash',$ppVoidHash).hidden('request_key',$ppThisVoid?input($_POST,'request_key'):bin2hex(random_bytes(16)))?>
<label><?=e($ppText('Grund der Rücknahme'))?><textarea class="form-control" name="partial_reason" maxlength="1000" rows="3" required><?="\n".e($ppVoidReason)?></textarea></label>
<button class="btn btn-outline theme-secondary" name="partial_move" value="preview" formnovalidate><?=e($ppText('Rücknahme aktuell prüfen'))?></button>
<?php if($ppVoidReview&&hash_equals($ppVoidHash,$ppVoidReview['hash'])):?><p class="notice" data-partial-return-revoke-reviewed><?=e($ppText('Die ursprünglichen Belegcents können wiederhergestellt werden. Die Rücknahme ist aktuell geprüft.'))?></p><?php endif?>
<label class="check"><input type="checkbox" name="partial_confirm" value="1" <?=$ppThisVoid&&!($partialReturnReviewed??false)&&input($_POST,'partial_confirm')==='1'?'checked':''?>><span><?=e($ppText('Ich bestätige die Rücknahme dieser Teilrückzahlungszuordnung anhand der ursprünglichen Zahlungen.'))?></span></label>
<button class="btn btn-solid theme-primary" name="partial_commit" value="1"><?=e($ppText('Teilrückzahlungszuordnung zurücknehmen'))?></button>
</form></details><?php endif?>
</section><?php endforeach;
if(!$finance->canWrite()||(!$assignable&&!$ppPosted))return;
$ppDraft=static fn(string$name,string$fallback=''):string=>$ppPosted?input($_POST,$name,$fallback):$fallback;$ppAmounts=[];$ppChoices=[];$ppReview=null;$ppCatalogue=null;$ppError='';
try{$ppAmounts=Finance::partialReturnDraftAmounts($ppPosted?($_POST['partial_amounts']??[]):[]);}catch(DomainException$failure){$ppError=$failure->getMessage();}
foreach($ppAmounts as$ppAmount)try{$ppChoices[$ppAmount['allocation_id']]=$finance->partialReturnChoice($t['id'],$ppAmount['allocation_id']);}catch(DomainException$failure){$ppError=$failure->getMessage();}
if($ppAmounts&&$ppDraft('partial_reason')!=='')try{$ppReview=$finance->partialReturnReview($t['id'],$ppAmounts,$ppDraft('partial_reason'));}catch(DomainException$failure){$ppError=$failure->getMessage();}
$ppHash=$ppDraft('partial_review_hash');$ppVersion=$ppDraft('partial_counter_version',(string)$t['version']);$ppRefresh=$ppPosted&&($partialReturnReviewed??false);
if($ppRefresh&&$ppReview){$ppHash=$ppReview['hash'];$ppVersion=(string)$ppReview['review']['counter']['version'];}
try{$ppCatalogue=$finance->partialReturnCandidatesPage($t['id'],$ppDraft('partial_page','1'),$ppDraft('partial_q'));}catch(DomainException$failure){$ppError=$failure->getMessage();}
?>
<details class="card payment-reversal-workspace" data-partial-return-workspace <?=$ppPosted?'open':''?>>
<summary><?=e($ppText('Teilbetrag oder mehrere ursprüngliche Zahlungen zurückgekommen'))?></summary>
<p><?=e($ppText('Dieser vorhandene Umsatz ist eine tatsächliche Rückzahlung. Wähle die ursprünglichen Zahlungszuordnungen und die jeweils zurückgekommenen Beträge. Die Belege bleiben gültig; nur diese Beträge werden wieder offen.'))?></p>
<p class="muted"><?=e($ppText('Eine bis vierzig ursprüngliche Zahlungen sind möglich, auch von verschiedenen eigenen Konten. Für laufende Lastschriften, bescheinigte Spenden, private Auslagen und Rechnungskorrekturen den zugehörigen Fachablauf verwenden.'))?></p>
<?php if($ppError!==''):?><p class="error" role="alert"><?=e($ppText($ppError))?></p><?php endif?>
<form method="post" data-partial-return>
<?=csrf().hidden('action','return-payment-parts').hidden('transaction_id',$t['id']).hidden('partial_counter_version',$ppVersion).hidden('partial_review_hash',$ppHash).hidden('request_key',$ppDraft('request_key',bin2hex(random_bytes(16))))?>
<h3><?=e($ppText('Ausgewählte ursprüngliche Zahlungen'))?></h3>
<?php if(!$ppAmounts):?><p><?=e($ppText('Zuerst mindestens eine ursprüngliche Zahlungszuordnung auswählen.'))?></p><?php endif?>
<?php foreach($ppAmounts as$ppIndex=>$ppAmount):$ppChoice=$ppChoices[$ppAmount['allocation_id']]??null;?>
<article class="matching-candidate" data-partial-return-selected="<?=e($ppAmount['allocation_id'])?>"><div>
<?=hidden('partial_amounts['.$ppIndex.'][allocation_id]',$ppAmount['allocation_id'])?>
<?php if($ppChoice):$ppEntry=$ppChoice['entry'];?><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$ppEntry['id']]))?>" target="_blank" rel="noopener"><?=e($ppEntry['display_number'].' · '.$ppEntry['description'])?></a>
<p><?=e(dateDe($ppChoice['source']['booked_on']).' · '.$ppChoice['source']['account_name'].' · '.$ppChoice['source']['description'])?></p>
<dl class="data-list"><dt><?=e($ppText('Ursprünglich zugeordnet'))?></dt><dd><?=e(euro((int)$ppChoice['allocation']['cents']))?></dd><dt><?=e($ppText('Davon noch nicht zurückgezahlt'))?></dt><dd><?=e(euro((int)$ppChoice['refundable_cents']))?></dd><dt><?=e($ppText('Aktuell am Beleg bezahlt'))?></dt><dd><?=e(euro((int)$ppEntry['allocated_cents']))?></dd><dt><?=e($ppText('Aktuell am Beleg offen'))?></dt><dd><?=e(euro((int)$ppEntry['remaining_cents']))?></dd></dl>
<?php foreach($ppChoice['originals']as$ppDocument):?><a class="btn btn-outline theme-secondary" href="<?=e(url(['document_file'=>$ppDocument['document']['id'],'revision'=>$ppDocument['file']['revision']]))?>" target="_blank" rel="noopener"><?=e($ppText('Ursprüngliche Belegdatei öffnen'))?></a><?php endforeach?>
<?php else:?><p><?=e($ppAmount['allocation_id'])?></p><?php endif?>
<label><?=e($ppText('Tatsächlich zurückgekommener Teilbetrag (EUR)'))?><input class="form-control" name="partial_amounts[<?=(int)$ppIndex?>][amount]" value="<?=e($ppAmount['amount'])?>" inputmode="decimal" maxlength="60" required></label>
</div><button class="btn btn-outline theme-secondary" name="remove_partial_allocation" value="<?=e($ppAmount['allocation_id'])?>" formnovalidate><?=e($ppText('Auswahl entfernen'))?></button></article>
<?php endforeach?>
<label><?=e($ppText('Grund der tatsächlichen Rückzahlung'))?><textarea class="form-control" name="partial_reason" rows="4" maxlength="1000" required><?="\n".e($ppDraft('partial_reason'))?></textarea></label><p class="muted"><?=e($ppText('Höchstens 500 Zeichen. Die Rückzahlung und das Fortbestehen der ursprünglichen Forderungen beschreiben.'))?></p>
<h3><?=e($ppText('Zahlungszuordnung auswählen'))?></h3>
<div class="filter-form"><label><?=e($ppText('Ursprüngliche Zahlung suchen'))?><input class="form-control" name="partial_q" maxlength="300" value="<?=e($ppDraft('partial_q'))?>"></label><button class="btn btn-outline theme-secondary" name="partial_move" value="search" formnovalidate><?=e($ppText('Zahlungen suchen'))?></button></div>
<?php foreach($ppCatalogue['rows']??[]as$ppCandidate):?>
<article class="matching-candidate" data-partial-return-candidate="<?=e($ppCandidate['allocation_id'])?>"><div><strong><?=e($ppCandidate['display_number'].' · '.$ppCandidate['entry_description'])?></strong><p><?=e(dateDe($ppCandidate['booked_on']).' · '.$ppCandidate['account_name'].' · '.$ppCandidate['transaction_description'])?></p><p><?=e($ppText('Davon noch nicht zurückgezahlt').': '.euro((int)$ppCandidate['refundable_cents']))?></p></div>
<?php if($ppCandidate['private_reimbursement']??false):?><a class="btn btn-outline" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$ppCandidate['entry_id'],'reimbursement_return_counter'=>$t['id']]).'#reimbursement-returns')?>"><?=e($ppText('Private Erstattung und Rückzahlungen öffnen'))?></a><?php elseif(!in_array($ppCandidate['allocation_id'],array_column($ppAmounts,'allocation_id'),true)):?><button class="btn btn-outline theme-secondary" name="choose_partial_allocation" value="<?=e($ppCandidate['allocation_id'])?>" formnovalidate><?=e($ppText('Diese ursprüngliche Zahlung auswählen'))?></button><?php else:?><span class="status aktiv"><?=e($ppText('Ausgewählt'))?></span><?php endif?>
</article><?php endforeach?>
<?php if($ppCatalogue&&!$ppCatalogue['rows']):?><p><?=e($ppText('Keine passende ursprüngliche Zahlungszuordnung gefunden.'))?></p><?php endif?>
<div class="filter-form"><p><?=e($ppText('Zahlungsseiten'))?>: <?=(int)($ppCatalogue['page']??1)?> / <?=(int)($ppCatalogue['pages']??1)?> · <?=(int)($ppCatalogue['total']??0)?></p><label><?=e($ppText('Zahlungsseite'))?><input class="form-control" name="partial_page" inputmode="numeric" value="<?=e($ppDraft('partial_page',(string)($ppCatalogue['page']??1)))?>"></label><button class="btn btn-outline theme-secondary" name="partial_move" value="page" formnovalidate><?=e($ppText('Zahlungsseite anzeigen'))?></button></div>
<button class="btn btn-outline theme-secondary" name="partial_move" value="preview" formnovalidate><?=e($ppText('Alle Teilbeträge und Originale aktuell prüfen'))?></button>
<?php if($ppReview&&$ppHash!==''&&hash_equals($ppHash,$ppReview['hash'])):?>
<div class="notice" data-partial-return-reviewed><h3><?=e($ppText('Geprüfte Verteilung der vorhandenen Rückzahlung'))?></h3>
<p><?=e($ppText('Verfügbarer Rückzahlungsbetrag').': '.euro((int)$ppReview['review']['counter']['remaining_cents']))?></p>
<ul><?php $ppProjected=[];foreach($ppReview['review']['selected']as$ppSelection){$ppEntry=$ppSelection['entry'];$ppProjected[$ppEntry['id']]??=['entry'=>$ppEntry,'returned'=>0];$ppProjected[$ppEntry['id']]['returned']+=(int)$ppSelection['amount_cents'];}foreach($ppProjected as$ppProjection):$ppEntry=$ppProjection['entry'];?><li><?=e($ppEntry['display_number'].' · '.$ppEntry['description'])?><br><?=e($ppText('Danach bezahlt').': '.euro((int)$ppEntry['allocated_cents']-$ppProjection['returned']).' · '.$ppText('Danach offen').': '.euro((int)$ppEntry['remaining_cents']+$ppProjection['returned']))?></li><?php endforeach?></ul>
</div><?php elseif($ppHash!==''):?><p class="notice" role="status"><?=e($ppText('Der gespeicherte Prüfstand ist nicht mehr aktuell. Auswahl und Angaben bleiben erhalten. Erneut prüfen und danach gesondert bestätigen.'))?></p><?php endif?>
<label class="check"><input type="checkbox" name="partial_confirm" value="1" <?=$ppPosted&&!$ppRefresh&&$ppDraft('partial_confirm')==='1'?'checked':''?>><span><?=e($ppText('Ich bestätige die vorhandene tatsächliche Rückzahlung und die geprüften Teilbeträge. Die ursprünglichen Forderungen bleiben in diesem Umfang gültig und werden wieder offen.'))?></span></label>
<button class="btn btn-solid theme-primary" name="partial_commit" value="1"><?=e($ppText('Rückzahlung auf ursprüngliche Zahlungen verteilen'))?></button>
</form></details>
