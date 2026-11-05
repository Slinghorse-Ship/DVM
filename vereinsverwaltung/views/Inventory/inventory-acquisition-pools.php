<?php declare(strict_types=1);
if(!$canFinance)return;
require_once __DIR__.'/../../src/Inventory/InventoryAcquisitionValues.php';
$ipText=static fn(string$s,array$v=[]):string=>\Kulturhus\Native\Language\InventoryAccountingMessages::text($s,$managerLanguageMessages??[],$v);
$itemAcquisitionPools=\Kulturhus\Verein\InventoryAcquisitionValues::pools($db,(string)$_SESSION['actor'],$itemId);
foreach($itemAcquisitionPools as $itemPool):
if($itemPool['all_priced'])continue;
try{$documents->document($itemPool['document_id']);$poolMayRead=true;}catch(\DomainException){$poolMayRead=false;}
?>
<section class="card iv-financial-source" data-inventory-acquisition-pool><h2><?= e($ipText('Anschaffung über Rechnung')) ?></h2><h3><?= e($itemPool['description']) ?></h3><p><?= e($ipText('{amount} insgesamt laut Original',['amount'=>euro((int)$itemPool['gross_cents'])])) ?></p>
<p><?= e($ipText($itemPool['complete_pending']?'Der Rechnungsbetrag wird in der Vermögensübersicht centgenau auf die vollständig erfassten Teile verteilt. Die Originalrechnung und die gespeicherten Einzelwerte bleiben unverändert.':'Bestand oder Einzelwerte haben sich geändert. Die ursprünglichen Zuordnungen stehen unten; eine aktuelle Verteilung wird hier nicht zugesichert. Bitte die Zuordnung prüfen.')) ?></p>
<ul><?php foreach($itemPool['items'] as $poolMember): ?><li data-inventory-acquisition-member><?php if($poolMember['deleted_at']===null): ?><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'inventory','tab'=>'items','item'=>$poolMember['id']])) ?>"><?= e($poolMember['name']) ?></a><?php else: ?><strong><?= e($poolMember['name']) ?></strong><p><?= e($ipText('Entfernter Gegenstand')) ?></p><?php endif ?><p><?= e($ipText('Ursprüngliche Menge: {quantity} {unit}',['quantity'=>(string)$poolMember['pool_quantity'],'unit'=>$poolMember['unit']])) ?></p><?php if(!$poolMember['active']): ?><p><?= e($ipText('Archiviert')) ?></p><?php endif ?></li><?php endforeach ?></ul>
<?php if($poolMayRead): ?><a class="btn btn-outline theme-secondary" href="<?= e(url(['document_file'=>$itemPool['document_id'],'revision'=>$itemPool['revision']])) ?>"><?= e($ipText('Originalrechnung ansehen')) ?></a><?php endif ?>
</section>
<?php endforeach ?>
