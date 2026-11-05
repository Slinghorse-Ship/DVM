<?php declare(strict_types=1);
use Kulturhus\Verein\Finance;
use Kulturhus\Verein\FinancePreferences;
use Kulturhus\Verein\TaxCatalog;
$preferenceWrite=$finance->canWrite();$preferenceData=array_replace(FinancePreferences::defaults(),$sd);
?>
<link rel="stylesheet" href="finance-preferences.css?v=1">
<form method="post" class="finance-preferences" data-finance-preferences>
<?= csrf().hidden('action','settings-save').hidden('section',FinancePreferences::SECTION).hidden('version',$sv) ?>
<?php if(!$preferenceWrite): ?><p class="notice">Sie können diese Einstellungen ansehen. Änderungen benötigen Finanz-Schreibrecht.</p><?php endif ?>
<fieldset <?= !$preferenceWrite?'disabled':'' ?>>
<section class="card"><h2>1. Sphären-Voreinstellung</h2>
<p>Die passenden Startwerte sind bereits vorgeschlagen. Sie können jeden Wert ändern. Bei gemischter Nutzung bleibt die Sphäre offen und wird je Position geprüft.</p>
<p class="notice"><strong>Kulturhus-Standard:</strong> Mitgliedsbeiträge, Spenden, Verwaltung und Porto werden dem ideellen Bereich vorgeschlagen; Inventar dem Zweckbetrieb. Veranstaltung, Räume, GEMA, Honorare und Versicherungen bleiben zur Prüfung offen.</p>
<div class="finance-preference-categories">
<?php foreach(Finance::CATEGORIES as $category=>$label): ?><label><?= e($label) ?><select class="form-control" name="data[category_spheres][<?= e($category) ?>]" data-no-select-search><option value="">Offen lassen – einzeln prüfen</option><?php foreach(TaxCatalog::costCenters() as $sphere=>$name): ?><option value="<?= e($sphere) ?>" <?= (string)($preferenceData['category_spheres'][$category]??'')===(string)$sphere?'selected':'' ?>><?= e($sphere.' · '.$name) ?></option><?php endforeach ?></select></label><?php endforeach ?>
</div>
<p><a href="?view=finance&amp;tab=help#hilfe-kontierung">Sphären und Kontierung verständlich erklärt</a></p>
</section>
<section class="card"><h2>2. Steuerkonten in Formularen</h2>
<p>Die häufig benötigten Vereinskonten sind voreingestellt. Sie können die Auswahl je Jahr ändern oder alle aktiven Konten anzeigen. Ausgeblendete Konten bleiben im Kontenplan, in vorhandenen Buchungen und im Export erhalten.</p>
<?php foreach(array_reverse(TaxCatalog::YEARS) as $preferenceYear):
 $accountPreference=$preferenceData['accounts'][$preferenceYear]??['mode'=>'all','codes'=>[]];$preferenceAccounts=$finance->taxAccounts($preferenceYear);$accountOptions=[];
 foreach($preferenceAccounts as $a)if($a['active']||in_array((string)$a['code'],$accountPreference['codes'],true))$accountOptions[]=['code'=>(string)$a['code'],'name'=>$a['name'],'group'=>(string)$a['category'],'active'=>(bool)$a['active']];
?><details class="finance-preference-year" data-account-preference <?= $preferenceYear===TaxCatalog::YEAR?'open':'' ?>><summary>Konten für <?= (int)$preferenceYear ?></summary>
<div class="finance-preference-year-body">
<label>Umfang der Kontenauswahl<select class="form-control" name="data[accounts][<?= (int)$preferenceYear ?>][mode]" data-account-mode data-no-select-search><option value="all" <?= $accountPreference['mode']==='all'?'selected':'' ?>>Alle aktiven Steuerkonten anzeigen</option><option value="selection" <?= $accountPreference['mode']==='selection'?'selected':'' ?>>Nur unsere ausgewählten Steuerkonten anzeigen</option></select></label>
<input type="hidden" name="data[accounts][<?= (int)$preferenceYear ?>][codes]" value="<?= e(implode(',',$accountPreference['codes'])) ?>" data-account-codes>
<div data-account-selection <?= $accountPreference['mode']==='all'?'hidden':'' ?>>
<label>Steuerkonto suchen und hinzufügen<select class="form-control" data-account-picker data-select-search><option value="">Kontonummer oder Bezeichnung eingeben</option><?php foreach($accountOptions as $a):if(!$a['active'])continue; ?><option value="<?= e($a['code']) ?>"><?= e($a['code'].' · '.$a['name']) ?></option><?php endforeach ?></select></label>
<details class="finance-preference-groups"><summary>Mehrere Konten nach Bereich auswählen</summary><div class="finance-preference-bulk"><label>Kontenbereich<select class="form-control" data-account-group data-no-select-search><option value="">Alle Bereiche</option><?php foreach(TaxCatalog::GROUPS as $group=>$label): ?><option value="<?= e($group) ?>"><?= e($label) ?></option><?php endforeach ?></select></label><button type="button" class="btn btn-outline theme-secondary secondary" data-account-add-group>Bereich hinzufügen</button><button type="button" class="btn btn-outline theme-secondary secondary" data-account-remove-group>Bereich entfernen</button></div></details>
<p data-account-count role="status" aria-live="polite"><?= count($accountPreference['codes']) ?> Steuerkonten ausgewählt.</p>
<ul class="finance-preference-selected" data-account-selected></ul><button type="button" class="btn btn-outline theme-secondary secondary" data-account-more hidden>Weitere ausgewählte Konten anzeigen</button>
<noscript><p>Zum Suchen und Ändern einzelner Steuerkonten bitte JavaScript einschalten. Die gespeicherte Kontenauswahl bleibt beim Speichern erhalten.</p></noscript>
</div><template data-account-options><?= e(json_encode($accountOptions,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)) ?></template>
</div></details><?php endforeach ?>
<p class="muted">„Nur unsere ausgewählten Steuerkonten“ ohne ausgewähltes Konto bietet zunächst keine neuen Steuerkonten an. Die Kontierung kann weiterhin als offen gespeichert werden. Konten aktivieren, umbenennen und eigene Konten ergänzen: <a href="?view=settings&amp;tab=tax-accounts">vollständige Steuerkontenliste</a>.</p>
</section>
<div class="card finance-preference-actions"><button class="btn btn-solid theme-primary">Finanz-Vorauswahl speichern</button><p>Gilt für diesen Verein. Es werden keine Buchungen oder Zahlungen ausgelöst.</p></div>
</fieldset></form>
<script defer src="finance-preferences.js?v=1"></script>
