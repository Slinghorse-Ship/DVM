<?php declare(strict_types=1);
use Kulturhus\Verein\{CatalogImports,TaxCatalog,TaxonomySources};
require_once __DIR__.'/../../src/Catalog/CatalogImports.php';
require_once __DIR__.'/../../src/Finance/Tax/TaxonomySources.php';
$catalogImports=new CatalogImports($db,(string)$_SESSION['actor']);
$catalogBase=['view'=>'settings','tab'=>'tax-accounts','catalog'=>'1'];
$catalogId=input($_GET,'catalog_id',input($_POST,'catalog_id'));
$catalogLast=max(TaxCatalog::YEARS);
?>
<link rel="stylesheet" href="catalog-import.css?v=1">
<div class="catalog-import">
<div class="page-heading"><h1>Kontenplan aktualisieren</h1><a class="btn btn-outline theme-secondary button secondary" href="?view=settings&amp;tab=tax-accounts">Zur Steuerkontenliste</a></div>
<div class="content">
<section class="card"><h2>Neue Jahresausgabe</h2><p>Unveränderte Zuordnungen werden übernommen. Nur Änderungen müssen geprüft werden. Alte Buchungen und die Abschlussart des Vereins bleiben unverändert.</p></section>
<?php if(!$catalogImports->canManage()): ?>
<section class="card"><h2>Zentrale Aktualisierung</h2><p>Die Administration stellt den geprüften Jahresstand bereit. Als Kassenwart müssen Sie keine PDF-Datei bearbeiten und keine Konten einzeln übertragen.</p></section>
<?php else:
$catalogRuns=$catalogImports->listing();$catalogRun=$catalogId!==''?$catalogImports->get($catalogId):null;
$catalogDraft=input($_POST,'action')==='catalog-import-start';
?>
<details class="card"><summary>Taxonomien und Wirtschaftsjahre</summary>
<p>Maßgeblich ist der Beginn des Wirtschaftsjahrs. Für einen Kalenderabschluss 2025 sind 6.8 und die ausdrücklich optionale 6.9 vorgesehen. Die vorhandene lokale Prüfung verwendet 6.9.</p>
<div class="table-scroll"><table class="table"><thead><tr><th>Taxonomie</th><th>Reguläres Beginnjahr</th><th>Optionales Beginnjahr</th><th>Prüfstand im Programm</th></tr></thead><tbody>
<?php foreach(TaxonomySources::listing() as $taxonomyVersion=>$taxonomySource): ?><tr><td><a href="<?= e($taxonomySource['source']) ?>" target="_blank" rel="noreferrer"><?= e($taxonomyVersion) ?></a></td><td><?= $taxonomySource['regular_start_year'] ?></td><td><?= $taxonomySource['optional_start_year'] ?></td><td><?= $taxonomySource['local_validation_years']?'Lokale Prüfung: '.e(implode(', ',$taxonomySource['local_validation_years'])):'Quelle vorhanden; Ausgabe und native Prüfung noch nicht abgenommen' ?></td></tr><?php endforeach ?>
</tbody></table></div><p>Eine vorhandene Taxonomiequelle erteilt keine Freigabe für eine Steuerabgabe.</p></details>
<?php if($catalogRun): ?><details class="card"><summary>Weitere Jahresprüfung beginnen</summary><?php else: ?><section class="card"><?php endif ?><h2>1. Jahresstand von DATEV holen</h2>
<form method="post" data-catalog-import><?= csrf().hidden('action','catalog-import-start').hidden('request_id',$catalogDraft?input($_POST,'request_id'):bin2hex(random_bytes(16))) ?>
<div class="form-grid"><label>Vorhandener Jahresstand<select class="form-control" name="from_year" data-catalog-base><?php foreach(TaxCatalog::YEARS as $catalogYear): ?><option value="<?= $catalogYear ?>" <?= (int)($catalogDraft?input($_POST,'from_year'):$catalogLast)===$catalogYear?'selected':'' ?>><?= $catalogYear ?></option><?php endforeach ?></select></label>
<label>Neuer Jahresstand<input class="form-control" type="number" name="year" min="2001" max="2100" required data-catalog-year value="<?= e($catalogDraft?input($_POST,'year'):(string)($catalogLast+1)) ?>"></label></div>
<button class="btn btn-solid theme-primary">Bei DATEV suchen</button><p data-catalog-progress hidden role="status">Die Originaldatei wird geprüft. Bitte dieses Fenster geöffnet lassen.</p></form>
<details><summary>Was passiert beim Jahreswechsel?</summary><p>Die Jahre werden nacheinander bereitgestellt: <?= $catalogLast+1 ?>, <?= $catalogLast+2 ?>, <?= $catalogLast+3 ?> und so weiter. Der Download allein aktiviert keine neuen Steuerregeln oder ELSTER-Formulare.</p></details><?php if($catalogRun): ?></details><?php else: ?></section><?php endif ?>
<?php if($catalogRun):$catalogData=$catalogRun['data'];$catalogLink=static fn(string $name):string=>url($catalogBase+['catalog_id'=>$catalogId,'catalog_file'=>$name]); ?>
<section class="card"><h2>Prüfung <?= $catalogRun['from_year'] ?> → <?= $catalogRun['target_year'] ?></h2><p><strong><?= e(CatalogImports::STATES[$catalogRun['state']]) ?></strong></p>
<?php if($catalogRun['state']==='unavailable'): ?><p>Die angebotene DATEV-Ausgabe ist für <?= (int)($catalogData['available_year']??0) ?>. Es wurde kein anderer Jahresstand unter <?= $catalogRun['target_year'] ?> gespeichert.</p>
<form method="post" data-catalog-import><?= csrf().hidden('action','catalog-import-fetch').hidden('catalog_id',$catalogId).hidden('version',$catalogRun['version']) ?><button class="btn btn-solid theme-primary">Erneut bei DATEV prüfen</button><p data-catalog-progress hidden role="status">Quelle wird geprüft …</p></form>
<?php else: ?><p><a class="btn btn-outline theme-secondary button secondary" href="<?= e($catalogLink('original.pdf')) ?>">Original-PDF herunterladen</a></p><?php endif ?>
<?php if($catalogRun['state']==='downloaded'): ?>
<h3>2. PDF einlesen und Änderungen erkennen</h3><form method="post" data-catalog-import><?= csrf().hidden('action','catalog-import-compare').hidden('catalog_id',$catalogId).hidden('version',$catalogRun['version']) ?>
<?php if(($catalogData['appendix_pages']??null)!==null): ?><?= hidden('appendix_pages',(int)$catalogData['appendix_pages']) ?>
<?php else: ?><label>Wie viele Erläuterungsseiten stehen am Ende hinter der letzten Kontentabelle?<input class="form-control" type="number" name="appendix_pages" min="0" max="20" required></label><p>Einmal an der neuen Originalausgabe prüfen. Die Kontentabellen selbst zählen nicht mit.</p><?php endif ?>
<button class="btn btn-solid theme-primary">Kontenplan einlesen</button><p data-catalog-progress hidden role="status">Die Konten und Zuordnungen werden mit dem Vorjahr verglichen …</p></form>
<?php endif ?>
<?php if(in_array($catalogRun['state'],['review_required','reviewed_candidate','incomplete_candidate'],true)): ?>
<h3>2. Jahresvergleich</h3><dl><?php foreach(['unchanged'=>'Unverändert','changed'=>'Geändert','newly_bookable'=>'Neue Konten','no_longer_bookable'=>'Entfallen oder gesperrt'] as $key=>$label): ?><dt><?= $label ?></dt><dd><?= (int)($catalogData['counts'][$key]??0) ?></dd><?php endforeach ?></dl>
<details><summary>Vergleich nach einer Programmaktualisierung erneuern</summary><p>Das gespeicherte Original wird mit dem aktuellen Programmstand verglichen. Bisherige Prüfdateien werden dadurch ersetzt; frühere Prüfstände bleiben im Verlauf erhalten. Anschließend die neuen Entscheidungen prüfen und bestätigen.</p>
<form method="post" data-catalog-import><?= csrf().hidden('action','catalog-import-compare').hidden('catalog_id',$catalogId).hidden('version',$catalogRun['version']) ?>
<?php if(isset($catalogData['appendix_pages'])&&is_int($catalogData['appendix_pages'])): ?><?= hidden('appendix_pages',$catalogData['appendix_pages']) ?>
<?php else: ?><label>Anzahl der Erläuterungsseiten am Ende des Originals<input class="form-control" type="number" name="appendix_pages" min="0" max="20" required></label><?php endif ?>
<button class="btn btn-solid theme-primary">Vergleich aktualisieren</button><p data-catalog-progress hidden role="status">Das gespeicherte Original wird erneut verglichen …</p></form></details>
<p><strong><?= (int)($catalogData['automatic']??0) ?></strong> Zuordnungen automatisch übernommen. <strong><?= (int)($catalogData['review_groups']??0) ?></strong> Änderungsgruppen prüfen.</p>
<?php if(!empty($catalogData['pending_year_review'])): ?><p><?= (int)$catalogData['pending_year_review'] ?> unveränderte Kontenzwecke sind für die noch ausstehende Jahresprüfung vorbereitet.</p><?php endif ?>
<?php if(!empty($catalogData['existing_gaps'])): ?><p><?= (int)$catalogData['existing_gaps'] ?> bisherige Zuordnungslücken bleiben gesondert zu klären.</p><?php endif ?>
<?php if(!empty($catalogData['filing_package']['ready'])): ?><p>Geprüfte Grundlage für den Kontenvergleich: Taxonomie <?= e($catalogData['filing_package']['taxonomy']) ?>, Jahr <?= (int)$catalogData['filing_package']['year'] ?>. Die Freigabe einer Steuerabgabe wird gesondert geprüft.</p>
<?php if(($catalogData['filing_package']['native_validation_ready']??null)===false): ?><p class="notice">Der Kontenplan kann vorbereitet werden. Ausgabeadapter und amtlicher Prüfer für diese Taxonomie sind noch nicht abgenommen. Eine Steuerabgabe bleibt gesperrt.</p><?php endif ?>
<?php elseif(isset($catalogData['filing_package'])): ?><p class="notice"><?= e($catalogData['filing_package']['reason']??'Das geprüfte Jahrespaket fehlt.') ?> Die Administration muss zuerst die passende Programmversion prüfen.</p><?php endif ?>
<p><a class="btn btn-outline theme-secondary button secondary" href="<?= e($catalogLink('review.html')) ?>">Prüfseite herunterladen</a></p><p>Die heruntergeladene Prüfseite im Browser öffnen. Sie zeigt Originaltexte und Änderungen nebeneinander. Nach der fachlichen Prüfung dort „Prüfdatei speichern“ wählen.</p>
<?php if(empty($catalogData['taxonomy_ready'])): ?><p class="notice">Die passende Taxonomie für den Kontenvergleich ist noch nicht geprüft. Das Original und der Vergleich bleiben gespeichert; ein Zuordnungspaket ist noch nicht möglich.</p>
<?php elseif($catalogRun['state']==='review_required'): ?>
<h3>3. Geprüftes Paket erstellen</h3><form method="post" enctype="multipart/form-data" data-catalog-import><?= csrf().hidden('action','catalog-import-approve').hidden('catalog_id',$catalogId).hidden('version',$catalogRun['version']) ?>
<label>Ausgefüllte Prüfdatei<input class="form-control" type="file" name="catalog_review" accept=".json,application/json" required></label>
<label class="check"><input class="check" type="checkbox" name="review_confirmed" value="1" required>Ich habe Original, Änderungen und Entscheidungen fachlich geprüft.</label><button class="btn btn-solid theme-primary">Prüfung übernehmen</button><p data-catalog-progress hidden role="status">Prüfdatei und Original werden erneut abgeglichen …</p></form>
<?php else: ?><p><a class="btn btn-solid theme-primary button" href="<?= e($catalogLink('package.zip')) ?>">Prüfpaket herunterladen</a></p><p>Das Paket ist für die zentrale Programmaktualisierung vorgesehen. Der aktive Kontenplan und bestehende Buchungen wurden nicht geändert.</p><?php endif ?>
<?php endif ?></section><?php endif ?>
<?php if($catalogRuns): ?><section class="card"><h2>Bisherige Prüfungen</h2><ul><?php foreach($catalogRuns as $run): ?><li><a href="<?= e(url($catalogBase+['catalog_id'=>$run['id']])) ?>"><?= (int)$run['from_year'] ?> → <?= (int)$run['target_year'] ?> · <?= e(CatalogImports::STATES[$run['state']]??'Prüfen') ?></a> · <?= e($run['updated_at']) ?></li><?php endforeach ?></ul></section><?php endif ?>
<script defer src="catalog-import.js?v=1"></script>
<?php endif ?></div></div>
