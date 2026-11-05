<?php declare(strict_types=1);
require __DIR__.'/inventory-invoice-integration.php';
require __DIR__.'/../src/Web/View.php';
$_SESSION=['actor'=>'operator'];$canFinance=true;$ivRead=true;$documents=$docs;
$itemId=$integrated['items']['lights'];ob_start();require __DIR__.'/../views/Inventory/inventory-acquisition-pools.php';$html=ob_get_clean();check(!str_contains($html,'Gemeinsamer Anschaffungspreis')&&str_contains($html,'Anschaffung über Rechnung'),'invoice allocation is shown without the old package-price label');
$assets=$inventory->assetsOverview();$lightRow=array_values(array_filter($assets['rows'],fn($row)=>$row['id']===$integrated['items']['lights']))[0];$cableRow=array_values(array_filter($assets['rows'],fn($row)=>$row['id']===$integrated['items']['longcables']))[0];check($assets['total']===192300&&$lightRow['documented_total']===7146&&$cableRow['documented_total']===7144&&$lightRow['allocated_unit_value_cents']===3573&&$cableRow['allocated_unit_value_cents']===3572,'unpriced invoice parts receive an exact cent allocation and preserve the invoice total');
foreach(['lights'=>3573,'longcables'=>3572] as $group=>$value){$db->prepare('UPDATE inventory_purchase_units SET unit_value_cents=? WHERE item_id=?')->execute([$value,$integrated['items'][$group]]);}
$assets=$inventory->assetsOverview();check($assets['total']===192300,'allocated unit values preserve original overall total');
$itemId=$integrated['items']['lights'];ob_start();require __DIR__.'/../views/Inventory/inventory-acquisition-pools.php';$html=ob_get_clean();check(trim($html)==='','fully allocated pool item section hidden');
echo "Inventory acquisition views passed.\n";
