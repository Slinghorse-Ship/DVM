<?php declare(strict_types=1);
if(!$canFinance)return;
require_once __DIR__.'/../../src/Inventory/InventoryAccounting.php';
$iaText=static fn(string$s,array$v=[]):string=>\Kulturhus\Native\Language\InventoryAccountingMessages::text($s,$managerLanguageMessages??[],$v);
$iaTaxText=static fn(string$s):string=>\Kulturhus\Native\Language\ManualFinanceMessages::text($s,$managerLanguageMessages??[]);
$inventoryAccounting=\Kulturhus\Verein\InventoryAccounting::forItem($db,(string)$_SESSION['actor'],$itemId);
?>
<link rel="stylesheet" href="inventory-accounting.css?v=1">
<details class="card iv-financial-source" data-inventory-accounting><summary><?= e($iaText('Steuerliche Zuordnung aus Rechnung oder Sachspende')) ?></summary>
<p><?= e($iaText('Die Kontierung wird am Finanzbeleg gepflegt und hier angezeigt. Bestand und Gegenstandswerte erzeugen keine zweite Buchung.')) ?></p>
<?php if(!$inventoryAccounting): ?><p><?= e($iaText('Noch kein Finanzbeleg mit diesem Gegenstand verknüpft. Eine hochgeladene Datei allein ist noch keine gebuchte Rechnung.')) ?></p><?php endif ?>
<?php foreach($inventoryAccounting as $source): ?><section data-inventory-accounting-source><h3><?= e($source['title']) ?></h3>
<p><?= e($iaText($source['specific']?'Die folgenden Rechnungspositionen sind diesem Gegenstand zugeordnet.':'Zuordnung zur gesamten Rechnung oder Sachspende. Ihre Positionen gelten nicht automatisch vollständig für diesen Gegenstand.')) ?></p>
<?php if(!$source['positions']): ?><p><?= e($iaText('Für diese Quelle sind noch keine Kontierungspositionen erfasst. Die Zuordnung bleibt offen.')) ?></p><?php endif ?>
<?php foreach($source['positions'] as $position): ?><section data-inventory-accounting-position><h4><?= e($position['description']) ?></h4><dl>
<dt><?= e($iaText('Sphäre / Kostenstelle (KOST1)')) ?></dt><dd><?= e(($position['sphere']??'')!==''?(string)$position['sphere'].' · '.$iaTaxText(\Kulturhus\Verein\TaxCatalog::costCenters()[$position['sphere']]??'Noch offen'):$iaText('Noch offen')) ?></dd>
<dt><?= e($iaText('Steuerkonto')) ?></dt><dd><?= e(($position['account_code']??'')!==''?$position['account_code'].' · '.$position['account_name']:$iaText('Noch offen')) ?></dd>
<dt><?= e($iaText('BU-Schlüssel')) ?></dt><dd><?= e(($position['booking_key']??'')!==''?(string)$position['booking_key']:$iaText('Keiner')) ?></dd>
</dl></section><?php endforeach ?>
<a class="btn btn-outline theme-secondary" href="<?= e(url($source['type']==='entry'?['view'=>'finance','tab'=>'receipts','entry'=>$source['id']]:['view'=>'donations','tab'=>'goods','goods'=>$source['id']])) ?>"><?= e($iaText($finance->canWrite()?'Kontierung im Finanzbeleg ansehen / bearbeiten':'Kontierung im Finanzbeleg ansehen')) ?></a></section>
<?php endforeach ?></details>
