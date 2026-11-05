<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
use Kulturhus\Verein\Web\FinanceExportForm;
$exportMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\FinanceExportMessages::messages('de');
$exportT=static fn(string$text,array$values=[]):string=>\Kulturhus\Native\Language\FinanceExportMessages::text($text,$exportMessages,$values);
$exportState=$financeExportState??FinanceExportForm::inspect($finance,$_GET,date('Y-m-d'));
$exportFilter=$exportState['values'];$exportChecked=$exportState['checked'];$exportRows=$exportState['rows'];$exportChecks=$exportState['checks'];
$exportDraft=in_array(input($_POST,'action'),['finance-export','finance-bundle'],true)&&input($_POST,'export_form')==='finance'?$_POST:[];
$exportDownloadInput=$exportDraft;
if(!$exportDraft)foreach(['export_kind'=>'kind','export_status'=>'status']as$key=>$field)if(array_key_exists($key,$_GET))$exportDownloadInput[$field]=$_GET[$key];
$exportFields=FinanceExportForm::fields($exportDownloadInput,($exportDraft?['from'=>'','to'=>'']:$exportFilter)+['kind'=>'','status'=>'']);
$exportUnsupported=[];if($exportState['unsupported']!==[])$exportUnsupported['period']=$exportState['unsupported'];if($exportFields['unsupported']!==[])$exportUnsupported['download']=$exportFields['unsupported'];
$exportBlocked=$exportState['error']!==''||$exportUnsupported!==[];
$exportSelection=['from'=>$exportFields['values']['from'],'to'=>$exportFields['values']['to'],'export_kind'=>$exportFields['values']['kind'],'export_status'=>$exportFields['values']['status']];
$exportSelectionReady=!$exportBlocked&&$exportChecked&&$exportState['preview_error']==='';
$exportKindOptions=[''=>'Alle Belegtypen']+Finance::KINDS;$exportStatusOptions=[''=>'Alle','open'=>'Offen (inkl. Teilzahlung)','partial'=>'Teilbezahlt','reversed'=>'Gegengebucht','paid'=>'Bezahlt','cancelled'=>'Verworfen'];

?>
<link rel="stylesheet" href="finance-export.css?v=1">
<div class="content" data-finance-export>
<section class="card"><h2><?=e($exportT('1. Zeitraum auswählen'))?></h2>
<p><?=e($exportT('Beginnen Sie mit dem Belegzeitraum. Für einen DATEV-Zahlungsstapel zählt anschließend das Zahlungsdatum; deshalb können sich die enthaltenen Vorgänge unterscheiden.'))?></p>
<?php if($exportState['error']!==''): ?><p role="alert" data-export-period-error><?=e($exportT($exportState['error']))?></p><?php endif ?>
<?php if($exportUnsupported!==[]): ?><section data-export-unsupported><p role="alert"><?=e($exportT('Diese Eingabeform wird nicht als einzelne Angabe übernommen. Öffnen Sie das Exportformular bewusst neu.'))?></p><details><summary><?=e($exportT('Angaben lesen'))?></summary><pre><?=e(json_encode($exportUnsupported,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT))?></pre></details><a data-navigation-native class="btn btn-outline theme-secondary" data-export-reset href="<?=e(url(['view'=>'finance','tab'=>'export']))?>"><?=e($exportT('Exportformular ohne bisherige Angaben neu öffnen'))?></a></section><?php endif ?>
<?php if($exportSelectionReady): ?><div data-export-selection-summary><p><strong><?=e($exportT('Geprüfte Auswahl'))?></strong>: <?=e(dateDe($exportSelection['from']).' – '.dateDe($exportSelection['to']))?></p><p><?=e($exportT($exportKindOptions[$exportFields['values']['kind']]??$exportFields['values']['kind']))?> · <?=e($exportT($exportStatusOptions[$exportFields['values']['status']]??$exportFields['values']['status']))?></p><a data-navigation-native class="btn btn-outline theme-secondary" data-export-change href="<?=e(url(['view'=>'finance','tab'=>'export']+$exportSelection))?>"><?=e($exportT('Auswahl ändern'))?></a></div>
<?php else: ?><form method="get" data-export-period><?=hidden('view','finance').hidden('tab','export').hidden('check_export','1')?>
<div class="form-grid"><?php foreach(['from'=>'Belege von','to'=>'Belege bis']as$key=>$label): ?><label><?=e($exportT($label))?><input class="form-control" type="<?=FinanceExportForm::dateInputType($exportFields['values'][$key])?>" name="<?=$key?>" required value="<?=e($exportFields['values'][$key])?>" <?php if($exportUnsupported!==[]):?>disabled<?php endif ?>></label><?php endforeach ?>
<?php foreach(['kind'=>['label'=>'Belegtyp','options'=>$exportKindOptions],'status'=>['label'=>'Status','options'=>$exportStatusOptions]]as$key=>$field):$selected=$exportFields['values'][$key]; ?><label><?=e($exportT($field['label']))?><select class="form-control" name="export_<?=$key?>"><?php if(!array_key_exists($selected,$field['options'])): ?><option value="<?=e($selected)?>" selected><?=e($exportT('Auswahl nicht verfügbar – bitte ändern'))?></option><?php endif ?><?php foreach($field['options']as$value=>$label): ?><option value="<?=e($value)?>" <?=$selected===$value?'selected':''?>><?=e($exportT($label))?></option><?php endforeach ?></select></label><?php endforeach ?></div>
<button class="btn btn-solid theme-primary" <?php if($exportUnsupported!==[]):?>disabled<?php endif ?>><?=e($exportT('Auswahl prüfen'))?></button></form><?php endif ?></section>
<section class="card" id="export-pruefung"><h2><?=e($exportT('2. Hinweise prüfen und berichtigen'))?></h2>
<?php if($exportBlocked): ?><p><?=e($exportT('Korrigieren Sie zuerst die Auswahl. Es wurde keine andere Belegauswahl geöffnet.'))?></p>
<?php elseif(!$exportChecked): ?><p><?=e($exportT('Wählen Sie den Zeitraum und prüfen Sie ihn. Danach erscheinen offene Kontierungen und Zahlungszuordnungen mit dem passenden Bearbeitungslink.'))?></p>
<?php elseif($exportState['preview_error']!==''): ?><p role="alert"><?=e($exportT($exportState['preview_error']))?></p><p><?=e($exportT('Bitte die Datumsfelder berichtigen oder den Zeitraum eingrenzen und erneut prüfen.'))?></p>
<?php else: ?><p data-export-source-count><?=e($exportT('{receipts} Belege im Zeitraum. {checks} Hinweise zur Kontierung oder Zahlung.',['receipts'=>(string)count($exportRows),'checks'=>(string)count($exportChecks)]))?></p>
<?php if(!$exportRows): ?><p><?=e($exportT('Keine Belege gefunden. Prüfen Sie die Datumsangaben. Es wurde kein fertiger Export bestätigt.'))?></p><?php elseif(!$exportChecks): ?><p><?=e($exportT('Bei diesen Kontierungen und Zahlungszuordnungen wurde kein offener Punkt gefunden. Prüfen Sie zusätzlich die Originaldateien und Freigaben. Dies ist noch keine DATEV-Prüfung oder steuerliche Abnahme.'))?></p><?php endif ?>
<?php foreach($exportChecks as$check): ?><article data-export-check><h3><?=e($check['label'])?></h3><p><?=e($exportT($check['message']))?></p><a data-navigation-native class="btn btn-outline theme-secondary" href="<?=e(url($check['route']))?>"><?=e($exportT($check['button']))?></a></article><?php endforeach ?><?php endif ?>
<p><?=e($exportT('Noch unbezahlte Rechnungen dürfen im Paket bleiben. Ordnen Sie eine Zahlung erst zu, wenn sie tatsächlich auf dem Konto vorhanden ist. Hinweise zu fehlenden Originaldateien stehen im Belegpaket in LIESMICH.txt und im Zuordnungsverzeichnis.'))?></p>
<a data-navigation-native class="btn btn-outline theme-secondary" href="?view=finance&amp;tab=help#hilfe-export"><?=e($exportT('Finanzexport Schritt für Schritt erklärt'))?></a></section>
<?php if($exportSelectionReady): ?><form method="post" data-finance-readonly-navigation class="card" data-export-downloads><?=csrf().hidden('export_form','finance')?><h2><?=e($exportT('3. Passendes Paket herunterladen'))?></h2>
<p><?=e($exportT('Die Liste und das Belegpaket sind Arbeitsunterlagen. Offene Prüfpunkte bleiben darin erkennbar; sie sind keine steuerliche Freigabe.'))?></p>
<p data-export-download-selection><?=e($exportT('CSV und Belegpaket verwenden genau die oben geprüfte Auswahl.'))?></p>
<?php foreach(['from','to','kind','status']as$key)echo hidden($key,$exportFields['values'][$key]); ?>
<button class="btn btn-solid theme-primary" name="action" value="finance-export"><?=icon('download')?> <?=e($exportT('CSV herunterladen'))?></button>
<button class="btn btn-outline theme-secondary" name="action" value="finance-bundle"><?=e($exportT('Belegpaket als ZIP herunterladen'))?></button>
<p class="muted"><?=e($exportT('Die CSV enthält die gefilterte Belegliste. Das ZIP enthält außerdem zugeordnete Originaldateien und ein Zuordnungsverzeichnis. Fehlende oder nicht freigegebene Dateien sind im Paket vermerkt. Excel-Auswertungen und Buchungsjournal stehen unter Auswertungen bereit; DATEV wird gesondert geprüft.'))?></p>
<a data-navigation-native class="btn btn-outline theme-secondary" href="?view=finance&amp;tab=reports"><?=e($exportT('Excel-Auswertungen und Buchungsjournal öffnen'))?></a></form><?php endif ?>
</div>
<?php if(!$exportBlocked)require __DIR__.'/datev-export.php'; ?>
