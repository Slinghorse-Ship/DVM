<?php declare(strict_types=1);
$ct=$ivCatalogueText;
$filters=scalarFields($_GET,['q','status','category','group']);$catalogue=$inventory->itemsPage($filters,$_GET['item_page']??1);$itemListRoute=['view'=>'inventory','tab'=>'items']+$filters;
?>
<link rel="stylesheet" href="inventory-catalogue.css?v=2">
<section class="card iv-catalogue" id="inventory-item-catalogue">
<?php if(input($_GET,'group')!==''): ?><h2><?= e($ct('Gemeinsam angelegte Einzelstücke')) ?></h2><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'inventory','tab'=>'items'])) ?>"><?= e($ct('Alle Gegenstände anzeigen')) ?></a><?php endif ?>
<form method="get" action="#inventory-item-catalogue" class="iv-filter" data-inventory-live-filter><?= hidden('view','inventory').hidden('tab','items').hidden('group',input($_GET,'group')) ?>
<?php ivField('q',$ct('Gegenstand suchen'),input($_GET,'q'));ivField('category',$ct('Kategorie'),input($_GET,'category'));ivField('status',$ct('Bestand'),input($_GET,'status','active'),'select',array_map($ct,['active'=>'Aktiv','low'=>'Unter Mindestbestand','blocked'=>'Gesperrte Bestände','archived'=>'Archiviert','all'=>'Alle'])); ?>
<button class="btn btn-outline theme-secondary"><?= e($ct('Anzeigen')) ?></button></form><p role="status" aria-live="polite" data-inventory-filter-status></p>
<div data-inventory-filter-results>
<div class="section-heading"><h2 data-inventory-item-summary><?= e($ct('{count} Gegenstände · Seite {page} von {pages}',['count'=>$catalogue['total'],'page'=>$catalogue['page'],'pages'=>$catalogue['pages']])) ?></h2><?php ivExport('items',$filters,$ct); ?></div>
<div class="iv-catalogue-cards"><?php foreach($catalogue['rows']as$i): ?><article class="iv-catalogue-card" data-inventory-original="<?= e($i['id']) ?>">
<a class="btn btn-outline theme-secondary" href="<?= e(url($itemListRoute+['item_page'=>$catalogue['page'],'item'=>$i['id']])) ?>"><strong><?= e($i['name']) ?></strong></a><p><?= e($i['code'].' · '.$i['category']) ?></p>
<?php if(($i['tracking_mode']??'quantity')==='individual'): ?><p><?= e($ct('Einzelstück · Seriennummer: {number}',['number'=>$i['serial_number']!==null&&$i['serial_number']!==''?$i['serial_number']:$ct('noch nicht bekannt')])) ?></p><?php endif ?>
<p><?= e($ct($i['kind']==='reusable'?'Ausleihbar':'Verbrauchsmaterial')) ?> · <?= e($ct($i['ownership']==='club'?'Vereinseigentum':'Fremde Leihgabe')) ?></p>
<dl><div><dt><?= e($ct('Gesamt')) ?></dt><dd><?= e(\Kulturhus\Verein\Inventory::contentSummary($i,(int)$i['total'],$ct)?:$i['total'].' '.$i['unit']) ?></dd></div><div><dt><?= e($ct('Verfügbar')) ?></dt><dd class="<?= $i['available']<$i['minimum']?'iv-low':'' ?>"><?= (int)$i['available'] ?></dd></div><div><dt><?= e($ct('Reserviert')) ?></dt><dd><?= (int)$i['reserved'] ?></dd></div><div><dt><?= e($ct('Ausgeliehen')) ?></dt><dd><?= (int)$i['lent'] ?></dd></div><div><dt><?= e($ct('Gesperrt')) ?></dt><dd><?= (int)$i['blocked'] ?></dd></div><?php if((int)$i['minimum']>0): ?><div><dt><?= e($ct('Mindestbestand')) ?></dt><dd><?= (int)$i['minimum'] ?></dd></div><?php endif ?></dl>
</article><?php endforeach;if(!$catalogue['rows']): ?><p><?= e($ct('Keine Gegenstände gefunden.')) ?></p><?php endif ?></div>
<nav class="iv-catalogue-pages" aria-label="<?= e($ct('Gegenstandsseiten')) ?>">
<?php if($catalogue['page']>1): ?><a class="btn btn-outline theme-secondary" href="<?= e(url($itemListRoute+['item_page'=>$catalogue['page']-1])) ?>#inventory-item-catalogue"><?= e($ct('Vorherige')) ?></a><?php endif ?>
<?php if($catalogue['page']<$catalogue['pages']): ?><a class="btn btn-outline theme-secondary" href="<?= e(url($itemListRoute+['item_page'=>$catalogue['page']+1])) ?>#inventory-item-catalogue"><?= e($ct('Nächste')) ?></a><?php endif ?></nav>
<form method="get" action="#inventory-item-catalogue" class="iv-catalogue-pages"><?php foreach($itemListRoute as$key=>$value)echo hidden($key,$value); ?><label><?= e($ct('Seite')) ?><input class="form-control" type="number" name="item_page" min="1" max="<?= (int)$catalogue['pages'] ?>" value="<?= (int)$catalogue['page'] ?>" required></label><button class="btn btn-outline theme-secondary"><?= e($ct('Seite anzeigen')) ?></button></form>
</div></section><script src="inventory-where-filter.js?v=3" defer></script>
