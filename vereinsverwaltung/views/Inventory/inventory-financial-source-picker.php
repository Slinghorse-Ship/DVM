<?php declare(strict_types=1);
$sourceText=$ivItemText??static fn(string $source,array $values=[]):string=>$source;
if($invoicePositionDraft||($inventoryItemSourceBound??false)){ivField($sourcePickerName,$sourceText('Vorhandene Rechnung oder Sachspende zuordnen'),$sourcePickerValue,'select',$sourceOptions);return;}
$sourcePickerRoute=['view'=>'inventory','tab'=>'items']+scalarFields($_GET,['item','item_request','individual_request','item_page','asset_page','return_assets','return_where','where_page','state','member','location','new_item','create_mode','q','category','status','cash_source','source','invoice_position']);
$browseBase=url($sourcePickerRoute+['source_move'=>'']);
$pickerOptions=array_intersect_key($sourceOptions,array_fill_keys(['keep','none',...array_map(fn($r)=>$sourcePickerList['kind'].':'.$r['id'],$sourcePickerList['rows'])],true));if(isset($sourceOptions[$sourcePickerValue]))$pickerOptions[$sourcePickerValue]=$sourceOptions[$sourcePickerValue];
?>
<?php if(!($sourcePickerHasFormMarker??false))echo hidden('source_form',$sourcePickerForm); ?>
<fieldset class="iv-financial-source-picker" data-financial-source-picker>
<legend><?= e($sourceText('Rechnung oder Sachspende finden')) ?></legend>
<div class="iv-form-grid">
<label><?= e($sourceText('Belegart')) ?><select class="form-control" name="source_picker_kind" data-select-native><option value="expense" <?= $sourcePickerList['kind']==='expense'?'selected':'' ?>><?= e($sourceText('Anschaffungsrechnungen')) ?></option><option value="goods" <?= $sourcePickerList['kind']==='goods'?'selected':'' ?>><?= e($sourceText('Sachspenden')) ?></option></select></label>
<label><?= e($sourceText('Beleg durchsuchen')) ?><input class="form-control" type="search" name="source_picker_q" maxlength="150" value="<?= e($sourcePickerList['search']) ?>"></label>
<button class="btn btn-outline theme-secondary" name="action" value="inventory-source-browse" formaction="<?= e(url($sourcePickerRoute+['source_move'=>'search'])) ?>#inventory-purchase" formnovalidate><?= e($sourceText('Belege suchen')) ?></button>
</div>
<?php if($sourcePickerError!==''): ?><p role="alert"><?= e($sourcePickerError) ?></p><?php else: ?><p data-financial-source-summary><?= e($sourceText('{count} Belege · Seite {page} von {pages}',['count'=>$sourcePickerList['total'],'page'=>$sourcePickerList['page'],'pages'=>$sourcePickerList['pages']])) ?></p><?php endif ?>
<?php ivField($sourcePickerName,$sourceText('Vorhandene Rechnung oder Sachspende zuordnen'),$sourcePickerValue,'select',$sourcePickerForm==='individual'?array_diff_key($pickerOptions,['keep'=>true]):$pickerOptions,false,true); ?>
<div class="iv-financial-source-pages">
<?php if($sourcePickerList['page']>1): ?><button class="btn btn-outline theme-secondary" name="action" value="inventory-source-browse" formaction="<?= e(url($sourcePickerRoute+['source_move'=>'previous'])) ?>#inventory-purchase" formnovalidate><?= e($sourceText('Vorherige Belege')) ?></button><?php endif ?>
<?php if($sourcePickerList['page']<$sourcePickerList['pages']): ?><button class="btn btn-outline theme-secondary" name="action" value="inventory-source-browse" formaction="<?= e(url($sourcePickerRoute+['source_move'=>'next'])) ?>#inventory-purchase" formnovalidate><?= e($sourceText('Nächste Belege')) ?></button><?php endif ?>
<label><?= e($sourceText('Belegseite')) ?><input class="form-control" type="number" name="source_picker_page" min="1" max="<?= (int)$sourcePickerList['pages'] ?>" value="<?= (int)$sourcePickerList['page'] ?>"></label>
<button class="btn btn-outline theme-secondary" name="action" value="inventory-source-browse" formaction="<?= e($browseBase) ?>#inventory-purchase" formnovalidate><?= e($sourceText('Belegseite anzeigen')) ?></button>
</div>
</fieldset>
