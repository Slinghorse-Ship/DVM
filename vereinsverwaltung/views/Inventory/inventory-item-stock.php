<?php declare(strict_types=1);if($item&&$ivWrite&&$item['active']):
$st=$ivMoveText;$stockCash=$inventoryMoveState['cash']??!empty($item['cash_account']);$movePosted=input($_POST,'action')==='inventory-move'&&input($_POST,'target')===$itemId;$mv=$movePosted&&is_array($_POST['data']??null)?$_POST['data']:[];
if($movePosted&&isset($inventoryPieceDraft))$mv=$inventoryPieceDraft['data'];
if(!$mv&&input($_GET,'stock_edit')==='1'){$stockDefaults=$stockCash?array_values(array_filter($item['stock'],static fn(array $r):bool=>(int)$r['shelf']+(int)$r['lent']+(int)$r['blocked']>0)):$item['stock'];$stockDefault=count($stockDefaults)===1?$stockDefaults[0]:null;$mv=['kind'=>$stockCash?($stockDefault?'transfer':'receive'):'count','location'=>$stockDefault['location_id']??'','quantity'=>$stockCash?'1':($stockDefault['shelf']??0)];}
$moveStocks=array_column($item['stock'],null,'location_id');$moveLocations=$locationOptions;
if(($mv['kind']??'receive')==='transfer')$moveLocations=array_filter($locationOptions,static fn($id)=>$id===''||((int)($moveStocks[$id]['shelf']??0)-(int)($moveStocks[$id]['reserved']??0)>0),ARRAY_FILTER_USE_KEY);
$moveLocations=\Kulturhus\Verein\Web\InventoryCatalogueForm::options($moveLocations,(string)($mv['location']??''),$st('Ausgewählter Lagerort nicht mehr verfügbar – bitte neu auswählen'));$moveDestinations=\Kulturhus\Verein\Web\InventoryCatalogueForm::options($locationOptions,(string)($mv['destination']??''),$st('Ausgewählter Zielort nicht mehr verfügbar – bitte neu auswählen'));
?>
<details class="iv-editor-panel iv-stock-panel" id="inventory-stock-panel" data-inventory-panel="stock" <?= $movePosted||input($_GET,'stock_edit')==='1'||!$item['stock']?'open':'' ?>>
 <summary><span><strong><?= e($st('Menge und Lagerort ändern')) ?></strong><small><?= e($st('Zugang, Umlagerung oder vorhandenen Bestand berichtigen')) ?></small></span></summary>
 <div class="iv-editor-body"><section class="iv-no-print" id="inventory-stock-change" data-unit="<?= e($item['unit']) ?>" data-stock-rows="<?= e(json_encode($moveStocks,JSON_THROW_ON_ERROR)) ?>" data-location-options="<?= e(json_encode($locationOptions,JSON_THROW_ON_ERROR)) ?>" data-move-posted="<?= $movePosted?'1':'0' ?>" data-original-source="<?= e($mv['location']??'') ?>">
 <?php if($stockCash&&!($inventoryMoveState['can_cash_move']??$canFinance)): ?><p role="status"><?= e($st('Die Übergabe einer Handkasse benötigt zusätzlich Finanz-Leserechte für den Zählabgleich.')) ?></p>
 <?php else: ?>
 <p><?= e($st('Wählen Sie zuerst, was sich geändert hat. Für einen Umzug verwenden Sie „Umlagerung“; für eine berichtigte Anzahl „Inventurkorrektur“.')) ?></p>
 <?php if($stockCash):$stockCashAccount=$inventoryMoveState['cash_account']??$item['cash_account'];$stockCashRow=array_column($finance->accounts(),null,'id')[$stockCashAccount]; ?><p class="iv-stock-cash" data-stock-cash-balance><a href="<?= e(url(['view'=>'finance','tab'=>'bank','account'=>$stockCashAccount])) ?>"><?= e($st('Verknüpfte Handkasse: {name}',['name'=>$stockCashRow['name']])) ?></a><strong><?= euro((int)$stockCashRow['balance_cents']) ?></strong><span><?= e($st('Geldbestand aus dem Kassenbuch. Die Übergabe ändert den physischen Lagerort.')) ?></span></p><?php endif ?>
 <form method="post" id="inventory-stock-form" action="<?= e(url($inventoryMoveState['route']??['view'=>'inventory','tab'=>'items','item'=>$itemId])) ?>#inventory-stock-change">
 <?= isset($inventoryMoveState['key'])?csrf().hidden('action','inventory-move').hidden('target',$itemId).hidden('version',$movePosted?(string)$_POST['version']:(string)$item['stock_version']).hidden('request_key',$inventoryMoveState['key']):ivPost('move',$itemId,(int)$item['stock_version']) ?>
 <div class="iv-form-grid">
 <?php $stockKinds=array_intersect_key(\Kulturhus\Verein\Inventory::ACTIONS,array_flip($stockCash?['receive','transfer']:\Kulturhus\Verein\Web\InventoryMoveForm::KINDS));foreach($stockKinds as&$label)$label=$st($label);unset($label);ivField('data[kind]',$st('Vorgang'),$mv['kind']??'receive','select',$stockKinds);ivField('data[location]',$st('Lagerort / Ausgangsort'),$mv['location']??'','select',$moveLocations,true);ivField('data[destination]',$st('Zielort (nur Umlagerung)'),$mv['destination']??'','select',$moveDestinations);
 if($stockCash): ?><label><?= e($st('Anzahl physischer Handkassen')) ?><input class="form-control" type="number" name="data[quantity]" min="1" max="1" step="1" required value="<?= e($mv['quantity']??'1') ?>"></label><?php else:ivField('data[quantity]',$st('Menge / gezählter nutzbarer Lagerbestand'),$mv['quantity']??1,'number',[],true);endif;
 ivField('data[reason]',$st('Grund / Zustands- und Übergabevermerk'),$mv['reason']??'','textarea',[],true);if($stockCash)ivField('data[counted]',$st('Bestätigter Zählbestand der Handkasse (€)'),$mv['counted']??'','text',[],true); ?>
 </div>
 <details class="iv-editor-hint"><summary><?= e($st('Welcher Vorgang passt?')) ?></summary>
 <p><?= e($st('Zugang: Es kommen weitere Stücke hinzu. Umlagerung: Vorhandene Stücke wechseln den Lagerort.')) ?></p>
 <?php if(!$stockCash): ?><p><?= e($st('Inventurkorrektur: Die gezählte nutzbare Menge am Lagerort eintragen, einschließlich reservierter Mengen. Ausgeliehene und gesperrte Mengen bleiben separat.')) ?></p><p><?= e($st('Gesperrt / defekt: Vorläufig nicht nutzbare Stücke. Freigegeben / repariert: Gesperrte Stücke werden wieder verfügbar. Ausgesondert: Zuvor gesperrte Stücke dauerhaft entfernen.')) ?></p><?php endif ?>
 </details>
 <?php if(($item['tracking_mode']??'quantity')==='quantity'&&!$stockCash){$pieceMode='move';$pieceData=$mv;require __DIR__.'/inventory-piece-editor.php';$pieceOptions=$item['purchase_units'];$pieceSelected=is_array($mv['purchase_unit_ids']??null)?$mv['purchase_unit_ids']:[];$pieceSelectionName='data[purchase_unit_ids]';require __DIR__.'/inventory-piece-selection.php';} ?>
 <?php if(($item['tracking_mode']??'quantity')==='quantity'&&!$stockCash)echo hidden('piece_form_complete','1'); ?>
 <?= hidden('stock_form_complete','1') ?>
 <button class="btn btn-solid theme-primary"><?= e($st('Lagerbewegung bestätigen')) ?></button>
 </form><?php endif ?>
 </section></div>
</details><script defer src="inventory-stock-change.js?v=4"></script>
<?php endif ?>
