<?php declare(strict_types=1);
$ivCatalogueText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\InventoryCatalogueMessages::text($source,$managerLanguageMessages??[],$values);
$ct=$ivCatalogueText;
?>
<div class="page-heading iv-heading"><div><h1><?= e($ct('Material')) ?></h1><p><?= e($ct('Vereinsmaterial und verfügbare Mengen')) ?></p></div><?php if($inventory->canReadLoans()): ?><a class="btn btn-outline theme-secondary button secondary" href="?view=inventory&amp;tab=loans"><?= e($ct('Ausleihen')) ?></a><?php endif ?></div>
<?php require __DIR__.'/inventory-navigation.php'; ?>
<div class="content">
<?php if($inventoryCatalogueState['blocked']??false){require __DIR__.'/inventory-catalogue-original.php';}else{
 $materialRows=$inventory->memberItems(input($_GET,'q'));require __DIR__.'/inventory-category-options.php'; ?>
<link rel="stylesheet" href="inventory-catalogue.css?v=3">
<section class="card iv-catalogue iv-material" id="inventory-material-catalogue"><form method="get" class="iv-filter"><?= hidden('view','material') ?><label><?= e($ct('Material suchen')) ?><input class="form-control" type="search" name="q" list="inventory-categories" maxlength="150" value="<?= e(input($_GET,'q')) ?>" placeholder="<?= e($ct('Bezeichnung oder Kategorie')) ?>"></label><button class="btn btn-outline theme-secondary secondary"><?= e($ct('Suchen')) ?></button></form>
<div class="iv-catalogue-cards"><?php foreach($materialRows as$row): ?><article class="iv-catalogue-card" data-material-original="<?= e($row['id']) ?>"><h2><?= e($row['name']) ?></h2><p><?= e($row['category']) ?></p><dl><?php foreach(['available'=>'Verfügbar','reserved'=>'Reserviert','lent'=>'Ausgeliehen']as$field=>$label):$summary=\Kulturhus\Verein\Inventory::contentSummary($row,(int)$row[$field],$ct); ?><div><dt><?= e($ct($label)) ?></dt><dd><?= e($summary!==''?$summary:$row[$field].' '.$row['unit']) ?></dd></div><?php endforeach ?></dl></article><?php endforeach;if(!$materialRows): ?><p><?= e($ct('Kein Material für diese Suche vorhanden.')) ?></p><?php endif ?></div></section>
<?php } ?></div>
