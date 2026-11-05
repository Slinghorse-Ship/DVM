<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

$customActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);
$customDefinition=\Kulturhus\Verein\MemberCustomFields::definition($db,$customActor['club_id']);
$customValues=\Kulturhus\Verein\MemberCustomFields::values($profile['custom_fields']??'{}');
$customPosted=$canWrite&&input($_POST,'action')==='save'&&input($_POST,'id')===(string)($profile['id']??'');
$customDraft=$customPosted&&is_array($_POST['custom_fields']??null)?$_POST['custom_fields']:[];
$customIds=array_column($customDefinition['rows'],'id');
?>
<?php if($canWrite): ?><?= hidden('custom_fields_version',$customPosted?input($_POST,'custom_fields_version','-1'):(string)$customDefinition['version']) ?><?php endif ?>
<?php if($customDefinition['rows']||$customValues): ?><section class="card"><h2><?= e($memberProfileText('Weitere Angaben')) ?></h2>
<?php if($canWrite): ?><p><?= e($memberProfileText('Diese Angaben gehören zu diesem Mitglied beziehungsweise Kontakt.')) ?> <?= e($profile?$memberProfileText('Mit „Persönliche Angaben speichern“ übernehmen.'):$memberProfileText('Sie werden beim Anlegen mitgespeichert.')) ?> <?= e($memberProfileText('Bei Ja/Nein ist auch „Nein“ eine gültige Antwort.')) ?></p><?php endif ?>
<div class="form-grid">
<?php foreach($customDefinition['rows'] as $customRow):
$customId=$customRow['id'];$customOld=$customValues[$customId]??[];
$customValue=$customPosted?($customDraft[$customId]??''):($customOld['value']??'');if(!is_string($customValue))$customValue='';
$customType=$customRow['type'];$customChoices=$customType==='checkbox'?['1'=>$memberProfileText('Ja'),'0'=>$memberProfileText('Nein')]:array_combine(\Kulturhus\Verein\MemberCustomFields::options($customRow),\Kulturhus\Verein\MemberCustomFields::options($customRow));
$customChanged=isset($customOld['type'])&&$customOld['type']!==$customType;
?>
<div><label><?= e($customRow['name']) ?><?= !empty($customRow['required'])?' *':'' ?>
<?php if(!$canWrite): ?><span><?= e($customType==='checkbox'?(['1'=>$memberProfileText('Ja'),'0'=>$memberProfileText('Nein')][$customValue]??$memberProfileText('Keine Angabe')):($customValue!==''?$customValue:$memberProfileText('Keine Angabe'))) ?></span>
<?php elseif(in_array($customType,['checkbox','select'],true)): ?><select class="form-control" name="custom_fields[<?= e($customId) ?>]" <?= !empty($customRow['required'])?'required':'' ?>><option value=""><?= e($memberProfileText('Bitte auswählen')) ?></option><?php if($customValue!==''&&!array_key_exists($customValue,$customChoices)): ?><option selected value="<?= e($customValue) ?>"><?= e($memberProfileText('Bisheriger Wert:')) ?> <?= e($customValue) ?> <?= e($memberProfileText('– bitte neu auswählen')) ?></option><?php endif ?><?php foreach($customChoices as $v=>$label): ?><option value="<?= e((string)$v) ?>" <?= (string)$v===$customValue?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select>
<?php else: ?><input class="form-control" name="custom_fields[<?= e($customId) ?>]" type="<?= $customType==='date'&&($customValue===''||preg_match('/^\d{4}-\d{2}-\d{2}$/D',$customValue))?'date':'text' ?>" maxlength="2000" value="<?= e($customValue) ?>" <?= !empty($customRow['required'])?'required':'' ?> <?= $customType==='number'?'inputmode="decimal"':'' ?>><?php endif ?></label>
<?php if($canWrite&&$customType==='date'): ?><p class="muted"><?= e($memberProfileText('Datum auswählen. Bei einem unpassenden bisherigen Wert das Datum als JJJJ-MM-TT korrigieren, zum Beispiel 2026-09-07.')) ?></p><?php endif ?>
<?php if($customChanged): ?><p class="notice"><?= e($memberProfileText('Der Feldtyp wurde geändert. Bisher gespeichert:')) ?> <?= e((string)($customOld['value']??'')) ?><?= e($memberProfileText('. Bitte den Wert vor dem Speichern prüfen.')) ?></p><?php endif ?></div>
<?php endforeach ?></div>
<?php $customRemoved=array_diff_key($customValues,array_flip($customIds));if($customRemoved): ?><details><summary><?= e($memberProfileText('Frühere Angaben zu entfernten Feldern')) ?></summary><p><?= e($memberProfileText('Diese gespeicherten Werte bleiben erhalten. Sie werden hier nicht mehr bearbeitet.')) ?></p><dl><?php foreach($customRemoved as $old): ?><dt><?= e((string)($old['name']??'')) ?></dt><dd><?= e((string)($old['value']??'')) ?></dd><?php endforeach ?></dl></details><?php endif ?>
</section><?php endif ?>
