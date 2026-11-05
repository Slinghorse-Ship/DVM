<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
$grText=$bankProofText;$grPosted=input($_POST,'action')==='reverse-payment-group'&&input($_POST,'transaction_id')===$t['id'];
$grDraft=static fn(string $name,string $fallback=''):string=>$grPosted?input($_POST,$name,$fallback):$fallback;
$grEntryIds=[];foreach($allocationRows??[]as$grLink)if($grLink['voided_at']===null)$grEntryIds[$grLink['entry_id']]=true;
if(!$grPosted&&count($grEntryIds)<2&&!$t['reversal_of']&&!$t['reversed_on'])return;
try{$grHistory=$finance->groupReversalHistory($t['id']);}
catch(DomainException $failure){if($grPosted):?><p class="error" role="alert"><?=e($grText($failure->getMessage()))?></p><?php endif;return;}
if(!$grHistory&&!$grPosted&&count($grEntryIds)<2)return;
if(!$grPosted&&!$grHistory&&($t['reversal_of']||$t['reversed_on']))return;
$grSourceError='';$grSource=['entries'=>[],'originals'=>[],'eligible'=>false,'available'=>false];
if(!$grHistory||$grPosted)try{$grSource=$finance->groupReversalSource($t['id']);}catch(DomainException $failure){$grSourceError=$failure->getMessage();if(!$grPosted&&!$grHistory)return;}
if(!$grHistory&&!$grPosted&&(!$finance->canWrite()||count($grSource['entries'])<2))return;
?><link rel="stylesheet" href="payment-reversals.css?v=1">
<?php foreach($grHistory as$grOriginal):?>
<section class="card payment-reversal-workspace" data-group-reversal-accepted="<?=e($grOriginal['id'])?>">
<h2><?=e($grText('Gemeinsame Gegenbuchung gespeichert'))?></h2>
<p><?=e($grText('Die vorhandene Gegenbewegung ist mit allen aufgeführten ursprünglichen Belegen verbunden. Es wurde kein weiterer Kontoumsatz erfasst.'))?></p>
<p><?=e(dateDe($grOriginal['result']['date']).' · '.euro((int)$grOriginal['cents']))?></p>
<p style="white-space:pre-wrap"><?=e($grOriginal['reason'])?></p>
<ul><?php foreach($grOriginal['review']['source']['entries']as$grEntry):?><li><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$grEntry['id']]))?>"><?=e(($grEntry['display_number']??'').' · '.$grEntry['description'].' · '.euro((int)$grEntry['amount_cents']))?></a></li><?php endforeach?></ul>
<div class="actions"><a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($grOriginal['source_id'],$_GET)))?>"><?=e($grText('Gemeinsame ursprüngliche Zahlung öffnen'))?></a><a class="btn btn-outline theme-secondary" href="<?=e(url(bankTransactionContext($grOriginal['returned_id'],$_GET)))?>"><?=e($grText('Vorhandene vollständige Gegenbewegung öffnen'))?></a></div>
<a class="btn btn-outline theme-secondary" data-group-reversal-download href="<?=e(url(array_replace(bankTransactionContext($t['id'],$_GET),['group_reversal_original'=>$grOriginal['id']])))?>"><?=e($grText('Ursprünglichen gemeinsamen Korrekturbeleg herunterladen'))?></a>
</section>
<?php endforeach;
if($grHistory&&!$grPosted)return;
if(!$finance->canWrite())return;
if(!$grSource['eligible']&&!$grPosted):?><section class="card payment-reversal-workspace"><h2><?=e($grText('Gemeinsame Zahlung zuerst klären'))?></h2><p><?=e($grText('Die gemeinsame Zahlung kann hier nur mit allen vollständig bezahlten Originalbelegen vollständig korrigiert werden. Teilzahlungen, bescheinigte Spenden, private Vorleistungen und laufende Überweisungsaufträge zuerst gesondert klären. Für ausgestellte Rechnungen den Storno- oder Gutschriftweg verwenden.'))?></p></section><?php return;endif;
$grAccepted=(bool)$grHistory;$grSelected=$grDraft('group_counter_id');$grCounter=null;$grError=$grSourceError;$grReview=null;
if($grSelected!=='')try{$grCounter=$finance->transactionDetail($grSelected);if($grSource['eligible'])$grReview=$finance->groupReversalReview($t['id'],$grSelected,$grDraft('group_reason'));}catch(DomainException $failure){$grError=$failure->getMessage();}
$grRefresh=($groupReversalReviewed??false)&&!$grAccepted;
$grHash=$grDraft('group_review_hash');$grVersion=$grDraft('group_source_version',(string)$t['version']);$grCounterVersion=$grDraft('group_counter_version',(string)($grCounter['version']??0));
if($grRefresh&&$grReview){$grHash=$grReview['hash'];$grVersion=(string)$grReview['review']['source']['transaction']['version'];$grCounterVersion=(string)$grReview['review']['returned']['version'];}
$grCurrent=$grReview&&$grHash!==''&&hash_equals($grHash,$grReview['hash']);$grCandidates=null;
if($grSource['eligible'])try{$grCandidates=$finance->groupReversalCandidatesPage($t['id'],$grDraft('group_counter_page','1'),$grDraft('group_counter_q'));}catch(DomainException $failure){$grError=$failure->getMessage();}
?>
<details class="card payment-reversal-workspace" data-group-reversal-workspace <?= $grPosted?'open':'' ?>>
<summary><?=e($grText('Gemeinsam bezahlte Belege vollständig gegenbuchen'))?></summary>
<p><?=e($grText('Dieser Schritt korrigiert alle unten aufgeführten Belege vollständig. Ihre bisherigen Zahlungen bleiben als Originale erhalten. Wähle ausschließlich eine tatsächlich bereits erfasste vollständige Gegenbewegung.'))?></p>
<p class="notice"><?=e($grText('Soll eine Forderung weiterbestehen oder nur ein Teil zurückgezahlt werden, diesen gemeinsamen vollständigen Korrekturweg nicht verwenden. Ausgestellte Rechnungen werden über ihren Storno- oder Gutschriftweg korrigiert.'))?></p>
<?php if($grPosted&&!$grRefresh):?><p class="notice" role="status"><?=e($grText('Ursprüngliche Auswahl, Grund, Prüfstand und Bestätigung bleiben erhalten. Zuerst den gespeicherten Ausgang prüfen; bei Änderungen erneut prüfen und danach gesondert bestätigen.'))?></p><?php endif?>
<?php if(!$grSource['eligible']&&!$grAccepted):?><p class="error" role="alert"><?=e($grText('Die gemeinsame Zahlung ist inzwischen nicht mehr vollständig korrigierbar. Ursprüngliche Belege und vorhandene Gegenbewegung zuerst klären.'))?></p><?php endif?>
<?php if($grError!==''):?><p class="error" role="alert"><?=e($grText($grError))?></p><?php endif?>
<h3><?=e($grText('Alle gemeinsam bezahlten Originalbelege'))?></h3>
<ul><?php foreach($grSource['entries']as$grEntry):?><li><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'finance','tab'=>'receipts','entry'=>$grEntry['id']]))?>" target="_blank" rel="noopener"><?=e(($grEntry['display_number']??'').' · '.$grEntry['description'].' · '.euro((int)$grEntry['amount_cents']))?></a>
<?php foreach($grSource['originals'][$grEntry['id']]??[]as$grDocument):?><a class="btn btn-outline theme-secondary" href="<?=e(url(['document_file'=>$grDocument['document']['id'],'revision'=>$grDocument['file']['revision']]))?>" target="_blank" rel="noopener"><?=e($grText('Ursprüngliche Belegdatei öffnen'))?></a><?php endforeach?></li><?php endforeach?></ul>
<form method="post" data-payment-group-reversal>
<?=csrf().hidden('action','reverse-payment-group').hidden('transaction_id',$t['id']).hidden('group_source_version',$grVersion).hidden('group_counter_id',$grSelected).hidden('group_counter_version',$grCounterVersion).hidden('group_review_hash',$grHash).hidden('request_key',$grDraft('request_key',bin2hex(random_bytes(16))))?>
<label><?=e($grText('Grund der gemeinsamen vollständigen Gegenbuchung'))?><textarea class="form-control" name="group_reason" rows="4" maxlength="1000" required <?= $grAccepted?'readonly':'' ?>><?="\n".e($grDraft('group_reason'))?></textarea></label><p class="muted"><?=e($grText('Höchstens 500 Zeichen. Alle betroffenen Belege und den tatsächlich geprüften Rückzahlungsbezug beschreiben.'))?></p>
<?php if($grCounter):?><div class="notice" data-group-reversal-selected="<?=e($grCounter['id'])?>"><strong><?=e($grText('Vorgewählte vorhandene Gegenbewegung'))?></strong><p><?=e(dateDe($grCounter['booked_on']).' · '.$grCounter['account_name'].' · '.euro((int)$grCounter['cents']))?></p><p><?=e($grCounter['description'])?></p><?php if(!$grAccepted):?><button class="btn btn-outline theme-secondary" name="group_move" value="clear" formnovalidate><?=e($grText('Gegenbewegungsauswahl leeren'))?></button><?php endif?></div><?php endif?>
<?php if(!$grAccepted&&$grSource['eligible']):?>
<h3 data-group-reversal-page="<?= (int)($grCandidates['page']??1)?>"><?=e($grText('Vorhandene vollständige Gegenbewegung auswählen'))?></h3>
<div class="filter-form"><label><?=e($grText('Vollständige Gegenbewegung suchen'))?><input class="form-control" name="group_counter_q" maxlength="300" value="<?=e($grDraft('group_counter_q'))?>"></label><button class="btn btn-outline theme-secondary" name="group_move" value="search" formnovalidate><?=e($grText('Gegenbewegungen suchen'))?></button></div>
<?php foreach($grCandidates['rows']??[]as$grCandidate):?><article class="matching-candidate" data-group-reversal-candidate="<?=e($grCandidate['id'])?>"><div><strong><?=e(dateDe($grCandidate['booked_on']).' · '.euro((int)$grCandidate['cents']))?></strong><p><?=e($grCandidate['description'].' · '.$grCandidate['counterparty'])?></p></div><button class="btn btn-outline theme-secondary" name="choose_group_counter" value="<?=e($grCandidate['id'])?>" formnovalidate><?=e($grText('Diese vollständige Gegenbewegung verwenden'))?></button></article><?php endforeach?>
<?php if($grCandidates&&!$grCandidates['rows']):?><p><?=e($grText('Keine passende noch nicht zugeordnete Gegenbewegung gefunden.'))?></p><?php endif?>
<div class="filter-form"><p><?=e($grText('Gegenbewegungsseiten'))?>: <?= (int)($grCandidates['page']??1)?> / <?= (int)($grCandidates['pages']??1)?> · <?= (int)($grCandidates['total']??0)?></p><label><?=e($grText('Gegenbewegungsseite'))?><input class="form-control" name="group_counter_page" inputmode="numeric" value="<?=e($grDraft('group_counter_page',(string)($grCandidates['page']??1)))?>"></label><button class="btn btn-outline theme-secondary" name="group_move" value="page" formnovalidate><?=e($grText('Gegenbewegungsseite anzeigen'))?></button></div>
<button class="btn btn-outline theme-secondary" name="group_move" value="preview" formnovalidate><?=e($grText('Alle Belege und Gegenbewegung aktuell prüfen'))?></button>
<?php endif?>
<?php if($grCurrent):?><div class="notice" data-group-reversal-reviewed><h3><?=e($grText('Geprüfte gemeinsame vollständige Gegenbuchung'))?></h3><dl class="data-list"><dt><?=e($grText('Gemeinsame ursprüngliche Zahlung'))?></dt><dd><?=e(euro((int)$grReview['review']['source']['transaction']['cents']))?></dd><dt><?=e($grText('Bereits erfasste Gegenbewegung'))?></dt><dd><?=e(euro((int)$grReview['review']['returned']['cents']))?></dd><dt><?=e($grText('Tatsächliches Datum der Gegenbewegung'))?></dt><dd><?=e(dateDe($grReview['review']['returned']['booked_on']))?></dd></dl><p><?=e($grText('Alle aufgeführten Belege werden vollständig gegengebucht. Der Kontostand bleibt erhalten.'))?></p></div><?php elseif($grHash!==''&&!$grAccepted):?><p class="notice" role="status"><?=e($grText('Der ursprünglich geprüfte Stand ist nicht mehr aktuell. Auswahl und Angaben bleiben erhalten. Alle Belege erneut prüfen und anschließend gesondert bestätigen.'))?></p><?php endif?>
<label class="check"><input type="checkbox" name="group_confirm" value="1" <?= $grDraft('group_confirm')==='1'&&!$grRefresh?'checked':'' ?>><span><?=e($grText('Ich bestätige die vollständige Gegenbuchung aller aufgeführten Belege durch genau diese bereits erfasste tatsächliche Gegenbewegung.'))?></span></label>
<?php if($grSource['eligible']||$grAccepted):?><button class="btn btn-solid theme-primary" name="group_commit" value="1"><?=e($grText($grAccepted?'Ursprüngliche gemeinsame Gegenbuchung erneut prüfen':'Gemeinsame vollständige Gegenbuchung speichern'))?></button><?php endif?>
</form></details>
