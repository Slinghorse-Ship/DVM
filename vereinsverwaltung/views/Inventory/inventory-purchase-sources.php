<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Inventory/InventoryAccounting.php';
$purchaseSources=\Kulturhus\Verein\InventoryAccounting::forItem($db,(string)$_SESSION['actor'],$itemId);
$purchaseDocuments=$documents->listing(['source_type'=>'item','source_id'=>$itemId]);
?>
<?php if($purchaseSources): ?><p><strong>Bereits zugeordnete Rechnungen / Sachspenden</strong></p><ul>
<?php foreach($purchaseSources as $purchaseSource): ?><li><a href="<?= e(url($purchaseSource['type']==='entry'?['view'=>'finance','tab'=>'receipts','entry'=>$purchaseSource['id']]:['view'=>'donations','tab'=>'goods','goods'=>$purchaseSource['id']])) ?>"><?= e($purchaseSource['title']) ?></a><?php if($purchaseSource['ordinals']): ?> · Position <?= e(implode(', ',$purchaseSource['ordinals'])) ?><?php endif ?></li><?php endforeach ?></ul><p>Alle diese Zuordnungen bleiben erhalten. Es muss keine einzelne Rechnung als Hauptquelle ausgewählt werden.</p><?php endif ?>
<?php foreach($purchaseDocuments as $purchaseDocument):
if(!in_array($purchaseDocument['kind'],['receipt','invoice'],true)||!in_array('finance',$purchaseDocument['scopes'],true))continue;
if(array_filter($purchaseDocument['links'],static fn($link)=>$link['source_type']==='entry'))continue;
?><p><a href="<?= e(url(['view'=>'finance','tab'=>'inbox','inbox_doc'=>$purchaseDocument['id']])) ?>"><?= e($purchaseDocument['title']) ?></a> · <?= e(dateDe($purchaseDocument['document_date'])) ?> · noch nicht in Finanzen erfasst</p><?php endforeach ?>
