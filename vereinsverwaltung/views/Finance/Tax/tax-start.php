<?php declare(strict_types=1);
$annualTaxMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];
$taxText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\AnnualTaxMessages::text($source,$annualTaxMessages,$values);
use Kulturhus\Verein\{Settings,TaxFilings,TaxPreparation,WorkspaceAccess};
$startYearRaw=$_GET['tax_year']??(string)((int)date('Y')-1);
$startYearValid=is_string($startYearRaw)&&preg_match('/\A[0-9]{4}\z/D',$startYearRaw)&&(int)$startYearRaw>=2000&&(int)$startYearRaw<=(int)date('Y');
$startYear=$startYearValid?(int)$startYearRaw:null;$startError='';
if($startYearValid){try{
 $startFilings=new TaxFilings($db,(string)$_SESSION['actor']);$startProfile=$startFilings->profile($startYear);
 $startTax=(new Settings($db,(string)$_SESSION['actor']))->section('tax')['data'];$startBasis=$startProfile['scope']['answers']['accounting'];
 $startRoute=['view'=>'finance','tab'=>'tax','tax_year'=>(string)$startYear];
 $startNext=$startRoute+($startBasis==='balance'?['balance'=>'1','balance_year'=>(string)$startYear]:($startYear===2025?['tax_auto'=>'1']:['tax_workpapers'=>'1']));
 $startRecent=$startFilings->recent($startYear);
}catch(DomainException $ex){$startError=$ex->getMessage();}}
else{$startError='Ein gültiges Kalenderjahr zwischen 2000 und dem laufenden Jahr eingeben.';http_response_code(422);}
?>
<link rel="stylesheet" href="annual-tax-native.css?v=1"><link rel="stylesheet" href="tax-preparation.css?v=2"><link rel="stylesheet" href="tax-guided.css?v=1">
<div class="page-heading annual-tax-heading"><h1><?= e($taxText('Jahresabschluss & Steuern')) ?></h1></div>
<div class="content tax-preparation annual-tax-native" data-annual-tax data-tax-guided>
<form method="get" class="card"><?= hidden('view','finance').hidden('tab','tax') ?><label><?= e($taxText('Für welches Jahr?')) ?><input class="form-control" type="text" inputmode="numeric" name="tax_year" maxlength="20" value="<?= e(is_string($startYearRaw)?$startYearRaw:'') ?>" required></label><button class="btn btn-outline theme-secondary secondary"><?= e($taxText('Jahr anzeigen')) ?></button></form>
<?php if($startError!==''):?><section class="notice" role="alert" data-annual-year-error><strong><?=e($taxText($startError))?></strong><details><summary><?=e($taxText('Bekannte ursprüngliche Jahresangaben'))?></summary><pre><?=e(json_encode(['tax_year'=>$startYearRaw],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE))?></pre></details></section></div><?php return;endif?>
<section class="card"><h2><?=e($taxText('Jahresabschluss {year}',['year'=>$startYear]))?></h2>
<p><?= e($taxText('Abschlussart:')) ?> <strong><?= e($taxText(match($startBasis){'balance'=>'Bilanz und Gewinn- und Verlustrechnung','euer'=>'Einnahmenüberschussrechnung (EÜR)',default=>'Noch zu klären'})) ?></strong></p>
<p><?=e($taxText((int)$startProfile['version']>0?'Aus den gespeicherten Jahresangaben.':'Aus den Vereinseinstellungen; für dieses Jahr noch zu bestätigen.'))?></p>
<?php if((int)$startProfile['version']>0&&($startTax['accounting_basis']??'unknown')!==$startBasis): ?><p class="tax-status"><?=e($taxText('Die aktuelle Vereinseinstellung weicht ab. Die Angaben für {year} wurden nicht geändert.',['year'=>$startYear]))?></p><?php endif ?>
<ol class="tax-steps"><li><?= e($taxText('Jahr wählen')) ?></li><li><?= e($taxText('Angaben ergänzen')) ?></li><li><?= e($taxText('Ergebnis prüfen')) ?></li><li><?= e($taxText('Freigeben & übermitteln')) ?></li></ol>
<p><a class="btn btn-solid theme-primary button" href="<?= e(url($startNext)) ?>"><?= e($taxText('Jahresabschluss vorbereiten')) ?></a></p>
<p><a href="<?= e(url($startRoute+['tax_filings'=>'1'])) ?>#tax-scope-accounting"><?=e($taxText('Abschlussart für {year} prüfen',['year'=>$startYear]))?></a> · <a href="?view=settings&amp;section=tax#settings-tax-closing"><?= e($taxText('Vereinseinstellung ansehen')) ?></a></p>
</section>
<?php if($startRecent): ?><section class="card"><h2><?= e($taxText('Vorhandene Steuererklärungen')) ?></h2><ul><?php foreach($startRecent as $r): ?><li><a href="<?= e(url($startRoute+['tax_filings'=>'1','filing'=>$r['id']])) ?>"><?= e(($r['environment']==='test'?'TEST · ':'').(\Kulturhus\Verein\TaxFilingScope::families()[$r['family']]['label']??$r['data_version']).' · '.$taxText(match($r['state']){'sent_production'=>'Abgabe bestätigt','sent_test'=>'Testabgabe bestätigt','validated'=>'Geprüft – noch nicht abgegeben','sending','unknown'=>'Übermittlung prüfen – nicht erneut senden',default=>'In Bearbeitung'})) ?></a></li><?php endforeach ?></ul></section><?php endif ?>
<details class="card"><summary><?= e($taxText('Berichte, Nachweise und weitere Aufgaben')) ?></summary>
<ul><li><a href="<?=e(url(['view'=>'documents','tab'=>'activity-reports','report_kind'=>'tax','report_from'=>(string)$startYear,'report_to'=>(string)$startYear]))?>"><?= e($taxText('Tätigkeitsbericht für das Finanzamt')) ?></a></li>
<?php if((new WorkspaceAccess($db,(string)$_SESSION['actor']))->depreciationEnabled()): ?><li><a href="<?= e(url(['view'=>'finance','tab'=>'book-values','year'=>(string)$startYear])) ?>"><?= e($taxText('Anschaffungen & Abschreibungen (AfA)')) ?></a></li><?php endif ?>
<li><a href="<?= e(url($startRoute+['tax_workpapers'=>'1'])) ?>"><?= e($taxText('Jahresunterlagen oder mehrere Jahre zusammenstellen')) ?></a></li>
<li><a href="<?= e(url($startRoute+['tax_filings'=>'1'])) ?>"><?= e($taxText('Weitere Steuerformulare und Nachrichten')) ?></a></li>
<li><a href="?view=finance&amp;tab=tax&amp;tax_review=1"><?= e($taxText('Nacharbeiten aus der Kassenprüfung')) ?></a></li>
<li><a href="?view=settings&amp;section=tax#elster-certificate"><?= e($taxText('Vereins-Steuerdaten und ELSTER-Zertifikat')) ?></a></li></ul>
<?php $startPackages=(new TaxPreparation($db,(string)$_SESSION['actor']))->recent();if($startPackages): ?><h3><?= e($taxText('Gespeicherte Jahresunterlagen')) ?></h3><ul><?php foreach($startPackages as $saved): ?><li><a href="<?= e(url(['view'=>'finance','tab'=>'tax','tax_saved'=>$saved['id']])) ?>"><?= e($saved['title'].' · '.$saved['created_at']) ?></a></li><?php endforeach ?></ul><?php endif ?>
</details></div>
