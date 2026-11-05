<?php $inventoryNavigationMessages=$managerLanguageMessages??[];$inventoryNavigationText=static fn(string $source):string=>\Kulturhus\Native\Language\InventoryLocationMessages::text($source,$inventoryNavigationMessages);$inventoryNavItems=[
 ['Wo ist was?',['view'=>'inventory','tab'=>'where']],
 ['Gegenstände',['view'=>'inventory','tab'=>'items']],
 ['Lagerorte',['view'=>'inventory','tab'=>'locations']],
 ['Ausleihen',['view'=>'inventory','tab'=>'loans']],
 ['Materialübersicht',['view'=>'material']],
]; ?>
<nav class="module-tabs inventory-navigation iv-no-print" aria-label="<?= e($inventoryNavigationText('Inventarbereiche')) ?>"><?php foreach($inventoryNavItems as [$inventoryNavLabel,$inventoryNavRoute]):if(!$workspaceAccess->allows($inventoryNavRoute))continue;$inventoryNavActive=$view===$inventoryNavRoute['view']&&($view==='material'||($tab?:'where')===$inventoryNavRoute['tab']); ?><a href="<?= e(url($inventoryNavRoute)) ?>" <?= $inventoryNavActive?'aria-current="page"':'' ?>><?= e($inventoryNavigationText($inventoryNavLabel)) ?></a><?php endforeach ?></nav>
