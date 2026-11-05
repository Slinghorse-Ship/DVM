<?php declare(strict_types=1);
// The caller supplies only current read facts; no invoice payload or original PDF is changed.
$oiPaymentState=$oiPaymentFacts['state'];$oiPaymentCredit=$oiPaymentKind==='credit';
$oiPaymentLabels=$oiPaymentCredit?['open'=>'Erstattung offen','partial'=>'Teilweise erstattet','paid'=>'Erstattet']:['open'=>'Zahlung offen','partial'=>'Teilbezahlt','paid'=>'Bezahlt'];
?>
<section class="outgoing-invoice-payment" data-invoice-payment="<?=e($oiPaymentId)?>" data-invoice-payment-state="<?=e($oiPaymentState)?>" aria-label="<?=e($oit('Zahlungsstand'))?>">
<?php if(isset($oiPaymentLabels[$oiPaymentState])): ?>
<dl><div><dt><?=e($oit('Zahlungsstand'))?></dt><dd data-invoice-current-state><?=e($oit($oiPaymentLabels[$oiPaymentState]))?></dd></div><div><dt><?=e($oit('Bereits zugeordnet'))?></dt><dd data-invoice-allocated><?=e(euro($oiPaymentFacts['allocated_cents']))?></dd></div><div><dt><?=e($oit($oiPaymentCredit?'Noch zu erstatten':'Noch offen'))?></dt><dd data-invoice-remaining><?=e(euro($oiPaymentFacts['remaining_cents']))?></dd></div></dl>
<?php else: ?><p data-invoice-payment-note><?=e($oit(match($oiPaymentState){'not_issued'=>'Noch nicht ausgegeben: Es besteht noch kein Zahlungsbeleg.','inactive'=>'Dieser Rechnungsbeleg ist nicht mehr für Zahlungen offen.',default=>'Zahlungsstand derzeit nicht verfügbar. Den ursprünglichen Beleg erneut prüfen.'}))?></p><?php endif ?>
</section>
