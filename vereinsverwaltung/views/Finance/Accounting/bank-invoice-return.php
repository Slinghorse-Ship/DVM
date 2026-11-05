<?php declare(strict_types=1);
if(($bankInvoiceReturn['state']??'none')==='none')return;
$bankInvoiceReturnText=static fn(string $text):string=>\Kulturhus\Native\Language\FinanceReceiptMessages::text($text,$bankProofMessages??[]);
?>
<div class="content"><section class="card" data-bank-invoice-return="<?=e($bankInvoiceReturn['state'])?>">
<?php if($bankInvoiceReturn['state']==='valid'): ?>
<p><?=e($bankInvoiceReturnText('Der Rückweg öffnet die zuvor gewählte Rechnung. Ob dieser Umsatz zugeordnet ist, zeigt der Verlauf.'))?></p>
<div class="actions"><a class="btn btn-outline theme-secondary" data-bank-invoice-receipt href="<?=e(url($bankInvoiceReturn['back']))?>"><?=e($bankInvoiceReturnText('Zur Rechnungszahlung zurück'))?></a><a class="btn btn-outline theme-secondary" data-bank-invoice-original href="<?=e(url($bankInvoiceReturn['invoice']))?>"><?=e($bankInvoiceReturnText('Zur Ausgangsrechnung'))?></a></div>
<?php else: ?>
<p class="notice" role="alert"><?=e($bankInvoiceReturnText('Der Rückweg zur Ausgangsrechnung ist unvollständig oder nicht verfügbar. Bitte den Rechnungsbezug erneut prüfen.'))?></p>
<?php if($bankInvoiceReturn['reset']!==null): ?><p><a class="btn btn-outline theme-secondary" data-bank-invoice-reset href="<?=e(url($bankInvoiceReturn['reset']))?>"><?=e($bankInvoiceReturnText('Ausgangsrechnung ohne bisherige Listenauswahl öffnen'))?></a></p><?php endif ?>
<?php endif ?>
</section></div>
