<?php declare(strict_types=1);
$annualTaxMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$taxText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\AnnualTaxMessages::text($source,$annualTaxMessages,$values);
if(input($_GET,'tax_auto')==='1'){require __DIR__.'/tax-auto.php';return;}
if(($balanceLocalRead??false)||($balanceLocalError??'')!=='')require __DIR__.'/../Balance/balance-filing-outcome.php';
if($balanceLocalRead??false)return;
if(($balanceLocalHandled??false)&&(!is_string($_POST['balance_local_token']??null)||!isset($_SESSION['balance_local_contexts'][$_POST['balance_local_token']])))return;
if(input($_GET,'balance')==='1'){
 ob_start();try{require __DIR__.'/../Balance/balance-ledger.php';$balanceHtml=ob_get_clean();}catch(Throwable $failure){ob_end_clean();throw $failure;}
 echo '<link rel="stylesheet" href="balance-filing-local.css?v=1"><div data-balance-local>';
 echo \Kulturhus\Verein\Web\BalanceFilingMarkup::protect($db,(string)$_SESSION['actor'],$_GET,$_POST,$balanceHtml,$_SESSION,$balanceLocalState??null,$annualTaxMessages??[]);require __DIR__.'/../Balance/balance-filing-history.php';echo '</div>';return;
}
if(input($_GET,'tax_filings')==='1'){require __DIR__.'/tax-filings.php';return;}
if(input($_GET,'tax_review')==='1'){require __DIR__.'/tax-review-queue.php';return;}
if($_SERVER['REQUEST_METHOD']!=='POST'&&input($_GET,'tax_workpapers')!=='1'&&input($_GET,'tax_saved')===''&&input($_GET,'tax_resume')===''){require __DIR__.'/tax-start.php';return;}
$taxPreparation=new \Kulturhus\Verein\TaxPreparation($db,(string)$_SESSION['actor']);
$taxBasis=(new \Kulturhus\Verein\Settings($db,(string)$_SESSION['actor']))->section('tax')['data']['accounting_basis']??'euer';
$taxBasisLabel=$taxBasis==='balance'?'Bilanzierung':'Einnahmenüberschussrechnung (EÜR)';
$annualTaxHandled??=false;$annualTaxError??='';$annualTaxOutcome??=null;
$annualTaxRaw=$annualTaxHandled?\Kulturhus\Verein\Web\AnnualTaxForm::original($_POST):(array_key_exists('annual_tax_outcome',$_GET)?['annual_tax_outcome'=>$_GET['annual_tax_outcome']]:[]);
$annualTaxMalformed=$annualTaxReadOnlyError??false;if($annualTaxHandled)try{\Kulturhus\Verein\Web\AnnualTaxForm::source($_GET);\Kulturhus\Verein\Web\AnnualTaxForm::fields($_POST);}catch(DomainException){$annualTaxMalformed=true;}
$taxFormInput=$annualTaxState['fields']??$_POST;
$annualTaxRoute=['view'=>'finance','tab'=>'tax','tax_workpapers'=>'1'];
if($_SERVER['REQUEST_METHOD']!=='POST'&&input($_GET,'tax_resume')!=='')try{$taxFormInput=$taxPreparation->editValues(input($_GET,'tax_resume'));}catch(DomainException$e){http_response_code(404);$annualTaxError=$e->getMessage();$annualTaxRaw=['tax_resume'=>input($_GET,'tax_resume')];$annualTaxMalformed=true;}
$taxSavedPreview=null;if($_SERVER['REQUEST_METHOD']!=='POST'&&input($_GET,'tax_saved')!=='')try{$taxSavedPreview=$taxPreparation->saved(input($_GET,'tax_saved'));}catch(DomainException$e){http_response_code(404);$annualTaxError=$e->getMessage();$annualTaxRaw=['tax_saved'=>input($_GET,'tax_saved')];$annualTaxMalformed=true;}
$taxDefaultYear=input($_GET,'tax_year',(string)((int)date('Y')-1));
if(!$taxFormInput)$taxFormInput=['from_year'=>$taxDefaultYear,'to_year'=>$taxDefaultYear];
$taxNotesFrom=(int)input($taxFormInput,'from_year',$taxDefaultYear);$taxNotesTo=(int)input($taxFormInput,'to_year',$taxDefaultYear);
$taxNotesInput=is_array($taxFormInput['tax_year_notes']??null)?$taxFormInput['tax_year_notes']:[];$taxGuideInput=is_array($taxFormInput['tax_guide']??null)?$taxFormInput['tax_guide']:[];
?>
<link rel="stylesheet" href="annual-tax-native.css?v=1"><link rel="stylesheet" href="tax-preparation.css?v=2"><link rel="stylesheet" href="tax-guided.css?v=1">
<div class="page-heading annual-tax-heading"><h1><?= e($taxText('Jahresunterlagen zusammenstellen')) ?></h1><a class="btn btn-outline theme-secondary button secondary" href="?view=finance&amp;tab=tax"><?= e($taxText('Jahresabschluss & Steuern')) ?></a></div>
<div class="content tax-preparation annual-tax-native" data-annual-tax data-tax-guided>
<?php require __DIR__.'/annual-tax-outcome.php';if($annualTaxOutcome!==null||$annualTaxMalformed){echo '</div>';return;}?>

<p><a class="btn btn-outline theme-secondary button secondary" href="?view=documents&amp;tab=activity-reports&amp;report_kind=tax"><?= e($taxText('Tätigkeitsbericht für das Finanzamt')) ?></a></p>
<section class="card"><h2><?= e($taxText('Abschlussart:')) ?> <?= e($taxText($taxBasisLabel)) ?></h2><?php if($taxBasis==='balance'): ?><p class="tax-warning"><?= e($taxText('Die Anwendung sammelt für die Bilanz zusätzlich Vermögenswerte, Verbindlichkeiten, Rückstellungen und Abschreibungen. Offene Werte bleiben sichtbar und müssen vor dem Abschluss geprüft werden.')) ?></p><?php else: ?><p><?= e($taxText('Die Jahresauswertung berechnet den Zahlungsüberschuss aus den erfassten Einnahmen und Ausgaben. Steuerliche Korrekturen und offene Angaben bleiben sichtbar.')) ?></p><?php endif ?><p><a href="?view=settings&amp;section=tax#settings-tax-closing"><?= e($taxText('Abschlussart ändern')) ?></a></p></section>
<?php if($taxBasis==='balance'): ?><p><a class="btn btn-outline theme-secondary button secondary" href="?view=finance&amp;tab=tax&amp;balance=1"><?= e($taxText('Bilanz und Gewinn- und Verlustrechnung')) ?></a></p><?php endif ?>
<?php if(input($_GET,'tax_saved')!==''): $taxPreview=$taxSavedPreview;require __DIR__.'/tax-preview.php'; ?>
<p><a class="btn btn-solid theme-primary button" href="<?= e(url(['view'=>'finance','tab'=>'tax','tax_resume'=>input($_GET,'tax_saved')])) ?>"><?= e($taxText('Mit diesen Angaben einen neuen Stand vorbereiten')) ?></a> <a class="btn btn-outline theme-secondary button secondary" href="?view=finance&amp;tab=tax"><?= e($taxText('Zur Steuer-Vorbereitung')) ?></a></p>
</div><?php return;endif ?>
<section class="card"><h2><?= e($taxText('Jahresunterlagen zusammenstellen')) ?></h2><ol class="tax-steps"><li><?= e($taxText('1. Zeitraum wählen')) ?></li><li><?= e($taxText('2. Fehlende Angaben ergänzen')) ?></li><li><?= e($taxText('3. Vorschau prüfen und speichern')) ?></li></ol><p><a href="?view=settings&amp;section=tax"><?= e($taxText('Vereins-Steuerdaten prüfen')) ?></a> · <a href="?view=documents&amp;doc_mode=upload"><?= e($taxText('Erklärung, Bescheid oder Nachweise hochladen')) ?></a></p></section>
<?php if(input($_GET,'tax_resume')!==''): ?><p class="notice"><?= e($taxText('Die ergänzten Angaben wurden aus dem gespeicherten Stand übernommen. Die nächste Vorschau verwendet die aktuellen Buchungen und ausgewählten Dokumentversionen. Das bisherige Paket bleibt unverändert.')) ?></p><?php endif ?>
<form method="post" action="<?=e(url($annualTaxRoute))?>" id="tax-preparation-form" class="card" data-tax-preparation-form><?= csrf() ?>
<?php if(isset($taxPreview['token'])): ?><?= hidden('tax_preview_token',$taxPreview['token']) ?><?php endif ?>
<h2><?= e($taxText('1. Für welches Jahr?')) ?></h2><div class="form-grid"><label><?= e($taxText('Kalenderjahr')) ?><input class="form-control" name="from_year" type="number" min="2000" max="<?= date('Y') ?>" value="<?= e(input($taxFormInput,'from_year',(string)$taxNotesFrom)) ?>" required></label><details data-tax-multiple-years <?= $taxNotesFrom!==$taxNotesTo?'open':'' ?>><summary><?= e($taxText('Mehrere Jahre zusammenstellen')) ?></summary><label><?= e($taxText('Bis einschließlich')) ?><input class="form-control" name="to_year" type="number" min="2000" max="<?= date('Y') ?>" value="<?= e(input($taxFormInput,'to_year',(string)$taxNotesTo)) ?>" required></label><p><?= e($taxText('Höchstens drei Kalenderjahre. Für ein einzelnes Jahr beide Angaben gleich lassen.')) ?></p></details></div><button type="submit" class="btn btn-outline theme-secondary secondary" name="action" value="tax-years" formnovalidate><?= e($taxText('Zeitraum übernehmen')) ?></button>
<h2><?= e($taxText('2. Angaben und Nachweise ergänzen')) ?></h2>
<?php require __DIR__.'/tax-year-notes.php'; ?>
<details class="card"><summary><?= e($taxText('Fragen zur tatsächlichen Vereinsarbeit beantworten')) ?></summary><?php require __DIR__.'/tax-declaration-guide.php'; ?></details>
<details class="card"><summary><?= e($taxText('Finanzamtsbericht ergänzen und Nachweise auswählen')) ?></summary><p><?= e($taxText('Durchgeführte Termine und vergangene, nicht abgesagte Veranstaltungen werden ohne Dopplungen übernommen. Die Verwirklichung der Satzungszwecke bitte anhand der tatsächlichen Arbeit prüfen und ergänzen.')) ?></p><p><a href="?view=calendar&amp;new_event=1"><?= e($taxText('Fehlende Veranstaltung oder Sitzung nachtragen')) ?></a></p><label><?= e($taxText('Ergänzungen zum Finanzamtsbericht')) ?><textarea class="form-control" name="activity_report" rows="6" maxlength="40000" placeholder="<?= e($taxText('Tätigkeiten, verwirklichte Vereinszwecke und Nachweise ergänzen.')) ?>"><?= e(input($taxFormInput,'activity_report')) ?></textarea></label>
<?php $taxSelected=is_array($taxFormInput['tax_documents']??null)?$taxFormInput['tax_documents']:[];$taxSearch=is_string($taxFormInput['annual_tax_attachment_search']??null)?$taxFormInput['annual_tax_attachment_search']:'';$taxPage=is_string($taxFormInput['annual_tax_attachment_page']??null)?(int)$taxFormInput['annual_tax_attachment_page']:1;$taxCatalogue=$taxPreparation->attachmentsPage($taxSearch,$taxPage);$taxFiles=$taxCatalogue['rows'];$knownTaxFiles=array_column($taxFiles,'id'); ?>
<fieldset data-annual-tax-attachments><legend><?=e($taxText('PDF-Nachweise beifügen · höchstens zehn'))?></legend>
<label><?=e($taxText('Nachweis suchen'))?><input class="form-control" type="search" name="annual_tax_attachment_search" data-annual-tax-attachment-search maxlength="200" value="<?=e($taxSearch)?>" placeholder="<?=e($taxText('Titel oder Datum eingeben'))?>"></label>
<?=hidden('annual_tax_attachment_page',(string)$taxCatalogue['page'])?><button class="btn btn-outline" name="annual_tax_browse" value="1" formnovalidate><?=e($taxText('Nachweise suchen'))?></button>
<p><?=e($taxText('{count} Nachweise · Seite {page} von {pages}',$taxCatalogue))?></p>
<div class="tax-attachment-list" data-tax-attachment-list>
<?php foreach($taxFiles as$taxFile):?><label class="check" data-tax-attachment-row><input type="checkbox" name="tax_documents[]" value="<?=e($taxFile['id'])?>" <?=in_array($taxFile['id'],$taxSelected,true)?'checked':''?>><span><?=e($taxFile['title'].' · '.$taxFile['document_date'])?></span></label><?php endforeach?>
<?php foreach($taxSelected as$taxChosen):if(!is_string($taxChosen)||in_array($taxChosen,$knownTaxFiles,true))continue;$taxOffPage=null;try{$taxOffPage=$taxPreparation->attachmentChoice($taxChosen);}catch(DomainException){}?>
<label class="check" data-annual-tax-selected><input type="checkbox" name="tax_documents[]" value="<?=e($taxChosen)?>" checked><span><?php if($taxOffPage):?><?=e($taxOffPage['title'].' · '.$taxOffPage['document_date'])?> · <?=e($taxText('Bereits ausgewählter Nachweis auf einer anderen Seite'))?><?php else:?><?=e($taxText('Eine zuvor gewählte Anlage ist nicht mehr als aktives Finanz-PDF verfügbar. Auswahl entfernen und den passenden Nachweis erneut wählen.'))?> · <?=e($taxChosen)?><?php endif?></span></label><?php endforeach?>
</div><?php if(!$taxFiles):?><p><?=e($taxText('Kein passender Nachweis gefunden. Bereits gewählte Anlagen bleiben angehängt.'))?></p><?php endif?>
<div class="annual-tax-pages"><?php foreach([-1=>'Vorherige',1=>'Nächste']as$offset=>$label):$page=$taxCatalogue['page']+$offset;if($page>=1&&$page<=$taxCatalogue['pages']):?><button class="btn btn-outline" name="annual_tax_browse" value="<?=$page?>" formnovalidate><?=e($taxText($label))?></button><?php endif;endforeach?></div>
<p><?=e($taxText('Suche und Seitenwechsel erhalten Jahresangaben und ausgewählte Nachweise. Danach die vollständige Vorschau erneut prüfen.'))?></p></fieldset></details>
<h2><?= e($taxText('3. Den gesamten Stand ansehen')) ?></h2><p><?= e($taxText('Die Vorschau zeigt Sphären, Steuerkonten, Ihre Jahresangaben, offene Punkte und den Tätigkeitsbericht. Hier wird noch nichts gespeichert.')) ?></p><button class="btn btn-solid theme-primary" name="action" value="tax-preview"><?= e($taxText('Vollständige Vorschau erstellen')) ?></button>
<?=hidden(\Kulturhus\Verein\Web\AnnualTaxForm::COMPLETE,'1')?></form>
<?php if(isset($taxPreview))require __DIR__.'/tax-preview.php'; ?>
<?php $taxRecent=$taxPreparation->recent();if($taxRecent): ?><section class="card"><h2><?= e($taxText('Gespeicherte Vorbereitungsstände')) ?></h2><p><?= e($taxText('Ein gespeicherter Stand wird durch spätere Buchungen nicht verändert.')) ?></p><ul><?php foreach($taxRecent as $savedTax): ?><li><a href="<?= e(url(['view'=>'finance','tab'=>'tax','tax_saved'=>$savedTax['id']])) ?>"><?= e($savedTax['title'].' · '.$savedTax['created_at']) ?></a></li><?php endforeach ?></ul></section><?php endif ?>
</div><script defer src="tax-preparation.js?v=2"></script><script defer src="tax-guided.js?v=1"></script>
