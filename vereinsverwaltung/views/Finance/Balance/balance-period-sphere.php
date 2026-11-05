<?php declare(strict_types=1);
$periodSphereTextMessages=$managerLanguageMessages??[];
$periodSphereText=static fn(string$text):string=>\Kulturhus\Native\Language\BalanceFilingMessages::text($text,$periodSphereTextMessages);
// Rare tax-relevant changes are separate from an ordinary room/location change.
$sphereChanges=$binding['proposal']['options']['sphere_changes']??[];
$sphereIndex=input($_GET,'sphere_index',$sphereChanges?'0':'new');
$sphereEntry=$sphereIndex==='new'?[]:($sphereChanges[(int)$sphereIndex]??[]);
$sphereField=static fn(string $name,string $default=''):string=>input($_GET,'sphere_preview')==='1'?input($_GET,$name):$default;
$sphereOpen=input($_GET,'sphere_preview')==='1'||isset($_GET['sphere_index']);
$sphereCases=['exempt_to_exempt'=>'Zwischen steuerfreien Vereinsbereichen',
    'taxable_value'=>'Erstmals im steuerpflichtigen Betrieb – oder nach einer bewerteten Entnahme',
    'exempt_book'=>'Vom steuerpflichtigen Betrieb in eine steuerbegünstigte Verwendung: Buchwert weiterführen',
    'exempt_value'=>'Vom steuerpflichtigen Betrieb in eine andere Verwendung: nachgewiesenen Wert ansetzen',
    'taxable_return'=>'Zurück in den steuerpflichtigen Betrieb nach einer Buchwertübertragung'];
$sphereUrl=['view'=>'finance','tab'=>'tax','balance'=>'1','periods'=>'1','period_id'=>$periodId,'period_source'=>$selected]+($sourceCataloguePage['query']??[]);
?>
<details <?= $sphereOpen?'open':'' ?>><summary><?= e($periodSphereText('Gegenstand künftig anders nutzen')) ?></summary>
<p><?= e($periodSphereText('Nur bei einem Wechsel des steuerlichen Vereinsbereichs. Die Bewertung vorher mit der Steuerberatung klären.')) ?></p>
<?php if($sphereChanges): ?><nav aria-label="Bereichswechsel auswählen"><?php foreach($sphereChanges as $i=>$change): ?><a href="<?= e('?'.http_build_query($sphereUrl+['sphere_index'=>(string)$i]).'#period-sources') ?>"><?= e($dateLabel($change['on']).' · '.\Kulturhus\Verein\TaxCatalog::SPHERES[$change['to_sphere']]) ?></a> · <?php endforeach ?><a href="<?= e('?'.http_build_query($sphereUrl+['sphere_index'=>'new']).'#period-sources') ?>"><?= e($periodSphereText('Weiteren Wechsel erfassen')) ?></a></nav><?php endif ?>
<form method="get" data-period-sphere>
<?php foreach($sphereUrl as $key=>$value)echo hidden($key,$value); ?><?= hidden('sphere_preview','1').hidden('sphere_index',$sphereIndex) ?>
<label><?= e($periodSphereText('Ab welchem Monatsbeginn?')) ?><input class="form-control" type="date" name="sphere_on" min="<?= e($r['period']['start']) ?>" max="<?= e($r['period']['end']) ?>" value="<?= e($sphereField('sphere_on',$sphereEntry['on']??'')) ?>" required></label>
<label><?= e($periodSphereText('Künftiger Vereinsbereich')) ?><select class="form-control" name="sphere_to" required><option value=""><?= e($periodSphereText('Geprüften Bereich auswählen')) ?></option><?php foreach(\Kulturhus\Verein\TaxCatalog::SPHERES as $key=>$label): if((string)$key==='9')continue; ?><option value="<?= e((string)$key) ?>" <?= $sphereField('sphere_to',$sphereEntry['to_sphere']??'')===(string)$key?'selected':'' ?>><?= e($periodSphereText($label)) ?></option><?php endforeach ?></select></label>
<label><?= e($periodSphereText('Grund der geänderten Nutzung')) ?><textarea class="form-control" name="sphere_reason" maxlength="1000" required><?= e($sphereField('sphere_reason',$sphereEntry['reason']??'')) ?></textarea></label>
<label><?= e($periodSphereText('Nachweis der Steuerberatung')) ?><select class="form-control" name="sphere_document" data-select-search required><option value=""><?= e($periodSphereText('Nachweis auswählen')) ?></option><?php foreach($documents['disposal_document'] as $document): ?><option value="<?= e($document['id']) ?>" <?= $sphereField('sphere_document',$sphereEntry['document_id']??'')===$document['id']?'selected':'' ?>><?= e($document['title']) ?></option><?php endforeach ?></select></label>
<?php if(!$documents['disposal_document']): ?><p><?= e($periodSphereText('Den Nachweis zuerst in der Dokumentenablage mit diesem Gegenstand verknüpfen.')) ?></p><?php endif ?>
<fieldset><legend><?= e($periodSphereText('Angaben aus dem geprüften Nachweis')) ?></legend>
<label><?= e($periodSphereText('Steuerliche Behandlung')) ?><select class="form-control" name="sphere_case" required><option value=""><?= e($periodSphereText('Behandlung auswählen')) ?></option><?php foreach($sphereCases as $key=>$label): ?><option value="<?= e($key) ?>" <?= $sphereField('sphere_case',$sphereEntry['case']??'')===$key?'selected':'' ?>><?= e($periodSphereText($label)) ?></option><?php endforeach ?></select></label>
<label data-sphere-value><?= e($periodSphereText('Bestätigter Steuerwert beim Wechsel (Euro)')) ?><input class="form-control" name="sphere_value" inputmode="decimal" value="<?= e($sphereField('sphere_value',isset($sphereEntry['value_cents'])?number_format($sphereEntry['value_cents']/100,2,',',''):'')) ?>"></label>
<div data-sphere-life><label><?= e($periodSphereText('Neue Restnutzungsdauer (Monate)')) ?><input class="form-control" type="number" name="sphere_months" min="1" max="1200" value="<?= e($sphereField('sphere_months',!empty($sphereEntry['remaining_months'])?(string)$sphereEntry['remaining_months']:'')) ?>"></label>
<label><?= e($periodSphereText('Neuer Restwert (Euro)')) ?><input class="form-control" name="sphere_residual" inputmode="decimal" value="<?= e($sphereField('sphere_residual',number_format(($sphereEntry['residual_cents']??0)/100,2,',',''))) ?>"></label></div>
<noscript><p><?= e($periodSphereText('Steuerwert nur bei einem Wechsel in den oder aus dem steuerpflichtigen Betrieb ausfüllen. Neue Restnutzungsdauer und Restwert nur bei einem neuen Wertansatz.')) ?></p></noscript>
<label><input class="check" type="checkbox" name="sphere_charitable" value="1" <?= $sphereField('sphere_charitable',!empty($sphereEntry['charitable_use'])?'1':'')==='1'?'checked':'' ?>><?= e($periodSphereText('Der Nachweis bestätigt die steuerbegünstigte Verwendung nach dem Wechsel.')) ?></label>
<?php foreach(['funds'=>'Mittelbindung und Finanzierung sind geprüft.','vat'=>'Umsatzsteuer und eine mögliche Vorsteuerberichtigung sind geprüft.','tax'=>'Wertansatz und Abschreibung sind steuerlich geprüft.','history'=>'Alle früheren Bereichswechsel sind erfasst. Vor dem ersten Wechsel waren Buch- und Steuerplan identisch.'] as $flag=>$label): ?>
<label><input class="check" type="checkbox" name="sphere_<?= e($flag) ?>" value="1" <?= $sphereField('sphere_'.$flag,!empty($sphereEntry[$flag.'_reviewed'])?'1':'')==='1'?'checked':'' ?> required><?= e($periodSphereText($label)) ?></label>
<?php endforeach ?></fieldset><button class="btn btn-outline theme-secondary secondary"><?= e($periodSphereText('Wechsel prüfen')) ?></button>
<?php if($sphereEntry): ?><button class="btn btn-outline theme-secondary secondary" name="sphere_action" value="remove" formnovalidate><?= e($periodSphereText('Diesen Wechsel aus dem Entwurf entfernen')) ?></button><?php endif ?>
</form></details>
