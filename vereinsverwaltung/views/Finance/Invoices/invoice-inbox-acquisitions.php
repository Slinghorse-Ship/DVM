<?php declare(strict_types=1);
require_once __DIR__.'/../../../src/Finance/Invoices/InvoiceAcquisitionLinks.php';
$acquisitionRows=\Kulturhus\Verein\InvoiceAcquisitionLinks::rows($db,(string)$_SESSION['actor'],$inboxId);
if(!$acquisitionRows)return;
$acquisitionInventory=new \Kulturhus\Verein\Inventory($db,(string)$_SESSION['actor']);
?>
<section class="card"><h3>Gegenstände bereits im Inventar</h3><p>Die Gegenstände aus dieser Rechnung wurden bereits übernommen. Beim Speichern der geprüften Rechnungspositionen wird die vorhandene Zuordnung verwendet; es entsteht kein weiterer Bestand.</p>
<?php if($acquisitionInventory->canRead()): ?><ul><?php foreach($acquisitionRows as $acquisitionRow):
try{$acquisitionItem=$acquisitionInventory->item($acquisitionRow['item_id']);}catch(\DomainException){continue;}
?><li>Rechnungsposition <?= (int)$acquisitionRow['source_ordinal'] ?>: <a href="<?= e(url(['view'=>'inventory','tab'=>'items','item'=>$acquisitionItem['id']])) ?>"><?= e($acquisitionItem['name']) ?></a><?php if($acquisitionRow['pool_id']): ?> · gemeinsamer Paketpreis<?php endif ?></li><?php endforeach ?></ul><?php endif ?>
<?php if(!$inboxSelected['entry_links']): ?><p><strong>Buchhaltung noch zu prüfen:</strong> Die Inventarübernahme legt keine neue Ausgabe oder Zahlung an. Bei älteren Anschaffungen zuerst mit den bisherigen Vereinsunterlagen abgleichen, ob die Rechnung bereits gebucht und bezahlt wurde.</p><?php endif ?></section>
