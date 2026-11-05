<?php declare(strict_types=1);
$menu=$native['menu'];$posted=input($_POST,'action')==='website-native'&&input($_POST,'operation')==='navigation/save';
if($posted){
    $menu['program_auto']=input($nativePostedFields,'program_auto','0');
    foreach($menu['items'] as $key=>&$row){$submitted=$nativePostedFields['items'][$key]??null;if(is_array($submitted))foreach(['label','visible','position'] as $fieldName)if(is_string($submitted[$fieldName]??null))$row[$fieldName]=$submitted[$fieldName];}unset($row);
}
uasort($menu['items'],static fn($a,$b)=>(int)$a['position']<=>(int)$b['position']);
?>
<link rel="stylesheet" href="website-navigation.css?v=1">
<section class="card website-menu-editor"><h2>Hauptmenü auf kulturhus.de</h2>
<?php $start('navigation/save',[],$native['version']); ?>
<fieldset <?= !$nativeCanWrite?'disabled':'' ?> data-website-menu>
<legend class="sr-only">Menü bearbeiten</legend>
<label class="check"><input type="hidden" name="fields[program_auto]" value="0"><input class="check" type="checkbox" name="fields[program_auto]" value="1" data-menu-auto <?= $menu['program_auto']==='1'?'checked':'' ?>>Programmjahr automatisch anzeigen</label>
<p class="muted">Automatisch: Jahr des nächsten Termins. Für „Programm 2027“ ausschalten und den Namen ändern.</p>
<ol class="website-menu-rows" data-menu-rows>
<?php foreach($menu['items'] as $key=>$row):$prefix='fields[items]['.$key.']';$label=$key==='program'&&$menu['program_auto']==='1'?($native['program_label']??'Programm'):$row['label']; ?>
<li class="website-menu-row" data-menu-row>
<div class="website-menu-name"><label for="menu-label-<?= e($key) ?>">Name im Menü<input class="form-control" id="menu-label-<?= e($key) ?>" type="text" name="<?= e($prefix) ?>[label]" value="<?= e($label) ?>" maxlength="60" required <?= $key==='program'?'data-menu-program data-auto-label="'.e($native['program_label']??'Programm').'"':'' ?>></label><small>/<?= e($native['targets'][$key]) ?></small></div>
<label class="website-menu-position">Position<input class="form-control" type="number" name="<?= e($prefix) ?>[position]" min="1" max="6" value="<?= e($row['position']) ?>" required data-menu-position></label>
<div class="website-menu-move"><button type="button" class="btn btn-outline theme-secondary secondary" data-menu-move="up" aria-label="<?= e($label) ?> nach oben">↑ Nach oben</button><button type="button" class="btn btn-outline theme-secondary secondary" data-menu-move="down" aria-label="<?= e($label) ?> nach unten">↓ Nach unten</button></div>
<label class="check"><input type="hidden" name="<?= e($prefix) ?>[visible]" value="0"><input class="check" type="checkbox" name="<?= e($prefix) ?>[visible]" value="1" <?= $row['visible']==='1'?'checked':'' ?>>Im Menü anzeigen</label>
</li>
<?php endforeach ?></ol>
<p role="status" aria-live="polite" data-menu-status></p>
</fieldset>
<?php $button('Menü speichern'); ?>
<p class="muted">Ändert nur das Hauptmenü. Seiten, Termine und Internetadressen bleiben erhalten.</p>
</section>
