<?php declare(strict_types=1);
if(!$canFinance)return;
$ilMessages=$managerLanguageMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[]);
$ilText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\InventoryLinkMessages::text($source,$ilMessages,$values);
$ilState=\Kulturhus\Verein\Web\InventoryLinkForm::state($_GET);$ilList=null;$ilError=$ilState['error'];
$ilRoute=array_replace($inventorySource==='cash'?bankListContext($_GET):($base??[]),match($inventorySource){'expense'=>['view'=>'finance','tab'=>'receipts','entry'=>$inventorySourceId],'goods'=>['view'=>'donations','tab'=>'goods','goods'=>$inventorySourceId],'cash'=>['view'=>'finance','tab'=>'bank','account'=>$inventorySourceId]});
if(!$ilState['blocked'])try{$ilList=$inventory->linkedItemsPage($inventorySource,$inventorySourceId,$ilState['page']);}catch(DomainException $failure){$ilError=$failure->getMessage();}
$ilFresh=$ilRoute;$ilFresh[\Kulturhus\Verein\Web\InventoryLinkForm::PAGE]='1';
?>
<link rel="stylesheet" href="inventory-links.css?v=1">
<details class="card iv-source-links" id="inventory-source-links" data-inventory-links data-source="<?= e($inventorySource) ?>" data-source-id="<?= e($inventorySourceId) ?>" <?= array_key_exists(\Kulturhus\Verein\Web\InventoryLinkForm::PAGE,$_GET)?'open':'' ?>>
<summary><strong><?= e($ilText('Inventarzuordnung')) ?></strong><?php if($ilList): ?><span><?= e($ilText($ilList['count']===1?'1 verknüpfter Gegenstand':'{count} verknüpfte Gegenstände',['count'=>$ilList['count']])) ?></span><?php endif ?></summary>
<div class="iv-source-link-content"><h3><?= e($ilText('Verknüpftes Inventar')) ?></h3>
<?php if($ilError!==''): ?><p role="alert"><?= e($ilText($ilError)) ?></p><?php if($ilState['original']!==null): ?><p><?= e($ilText('Bekannte ursprüngliche Seitenangabe')) ?></p><pre data-inventory-links-original><?= e(json_encode($ilState['original'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)) ?></pre><?php endif ?><a class="btn btn-outline theme-secondary" href="<?= e(url($ilFresh).'#inventory-source-links') ?>"><?= e($ilText('Inventarzuordnung neu anzeigen')) ?></a>
<?php elseif($ilList): ?>
<p data-inventory-links-summary><?= e($ilText($ilList['count']===1?'1 Gegenstand · Seite {page} von {pages}':'{count} Gegenstände · Seite {page} von {pages}',['count'=>$ilList['count'],'page'=>$ilList['page'],'pages'=>$ilList['pages']])) ?></p>
<div class="iv-source-link-cards"><?php foreach($ilList['rows']as$ir): ?><article data-inventory-linked-item="<?= e($ir['id']) ?>">
<?php $ilName=($ir['code']!==''?$ir['code'].' · ':'').$ir['name'];if($inventory->canRead()): ?><a class="btn btn-outline theme-secondary" data-inventory-linked-open href="<?= e(url(['view'=>'inventory','tab'=>'items','item'=>$ir['id']])) ?>"><?= e($ilName) ?></a><?php else: ?><strong><?= e($ilName) ?></strong><?php endif ?>
<p data-inventory-linked-state><?= e($ilText((int)$ir['active']===1?'Aktiv':'Archiviert')) ?></p></article><?php endforeach ?></div>
<?php if(!$ilList['rows']): ?><p><?= e($ilText('Bisher kein Gegenstand zugeordnet.')) ?></p><?php endif ?>
<?php if(array_filter($ilList['rows'],static fn(array $row):bool=>(int)$row['active']===0)): ?><p><?= e($ilText('Archivierte Gegenstände bleiben mit ihrer ursprünglichen Belegzuordnung erhalten.')) ?></p><?php endif ?>
<?php if($ilList['pages']>1): ?><nav aria-label="<?= e($ilText('Verknüpfte Inventarseiten')) ?>"><?php foreach([-1=>'Vorherige',1=>'Nächste']as$delta=>$label)if($ilList['page']+$delta>=1&&$ilList['page']+$delta<=$ilList['pages']): ?><a class="btn btn-outline theme-secondary" data-inventory-links-page href="<?= e(url($ilRoute+[\Kulturhus\Verein\Web\InventoryLinkForm::PAGE=>$ilList['page']+$delta]).'#inventory-source-links') ?>"><?= e($ilText($label)) ?></a><?php endif ?></nav>
<form method="get" data-inventory-links-page-form><?php foreach($ilRoute as$name=>$value)echo hidden($name,$value); ?><label><?= e($ilText('Seite')) ?><input class="form-control" type="number" name="inventory_link_page" min="1" max="<?= (int)$ilList['pages'] ?>" value="<?= (int)$ilList['page'] ?>" required></label><button class="btn btn-outline theme-secondary"><?= e($ilText('Seite anzeigen')) ?></button></form><?php endif ?>
<?php if($inventory->canWrite()&&($inventorySource!=='cash'||$ilList['count']===0)): ?><a class="btn btn-outline theme-secondary" data-inventory-linked-create href="<?= e(url(['view'=>'inventory','tab'=>'items','new_item'=>1]+($inventorySource==='cash'?['cash_source'=>$inventorySourceId]:['source'=>$inventorySource.':'.$inventorySourceId]))) ?>"><?= e($ilText($inventorySource==='cash'?'Handkasse mit Lagerort anlegen':'Gegenstand aus diesem Beleg anlegen')) ?></a><?php endif ?>
<p><?= e($ilText('Die Verknüpfung verändert keinen Geldbestand. Bestehende Gegenstände können über ihre Rechnung oder Sachspende zugeordnet werden.')) ?></p>
<?php endif ?></div></details>
