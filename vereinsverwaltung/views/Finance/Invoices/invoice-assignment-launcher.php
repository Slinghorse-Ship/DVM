<?php declare(strict_types=1);
$iaText=static fn(string$s,array$v=[]):string=>\Kulturhus\Native\Language\InvoiceAssignmentMessages::text($s,$managerLanguageMessages??\Kulturhus\Native\Language\InvoiceAssignmentMessages::messages('de'),$v);
$iaLaunch=\Kulturhus\Verein\Web\InvoiceAssignment::originalRoute($_GET)+['assign_mode'=>'existing','assign_read'=>bin2hex(random_bytes(8))];
?>
<link rel="stylesheet" href="invoice-assignment.css?v=1">
<details class="invoice-assignment-launcher" data-invoice-assignment-launcher><summary><?=e($iaText('Die Ausgabe ist bereits erfasst – vorhandener Ausgabe zuordnen'))?></summary><p><?=e($iaText('Nur verwenden, wenn diese Rechnung schon in Finanzen vorhanden ist.'))?></p><p><?=e($iaText('Die bisherigen Rechnungsangaben bleiben im ursprünglichen Tab erhalten. Die Auswahl einer Ausgabe speichert noch keine Zuordnung.'))?></p><a class="btn btn-outline theme-secondary" data-invoice-assignment-open target="_blank" rel="noopener" href="<?=e(url($iaLaunch))?>"><?=e($iaText('Vorhandene Ausgaben in neuem Tab prüfen'))?></a></details>
