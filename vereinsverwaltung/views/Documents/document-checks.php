<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Documents/HseInterfaceText.php';
require_once __DIR__.'/../../src/Documents/WorkflowPager.php';
$ccState=$checkViewState??['fatal'=>true,'error'=>'Nachweisansicht konnte nicht geladen werden.','editing'=>false,'http_status'=>500];
$ccMessages=$documentActionMessages??[];$ccI=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$ccMessages);$ccF=static fn(string $source,array $values):string=>\Kulturhus\Verein\Documents\HseInterfaceText::format($source,$values,$ccMessages);
$ccLocale=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->locale():'de';
$ccDate=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::date($source,$ccLocale,$ccMessages);
$ccTime=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::timestamp($source,$ccLocale);
$ccUrl=static fn(array $values=[]):string=>'?'.http_build_query(array_replace(['view'=>'documents','tab'=>'checks'],$values),'','&',PHP_QUERY_RFC3986);
$ccPager=static function(?array $page,string $parameter,array $values=[])use($ccI):void{echo \Kulturhus\Verein\Documents\WorkflowPager::render($page,$parameter,array_replace(['view'=>'documents','tab'=>'checks'],$values),$ccI);};
$ccRecord=static function(array $record)use($ccI,$ccDate):void{?><dl class="check-original-data"><?php foreach(['title'=>'Titel','category'=>'Kategorie','responsible'=>'Verantwortlich / Kontaktstelle','valid_until'=>'Gültig bis','review_on'=>'Nächste Prüfung','reminder_days'=>'Erinnerungstage','state'=>'Status','notes'=>'Hinweise und Versicherungsnummer']as$key=>$label):$value=$record[$key]??'';if($key==='category')$value=$ccI(\Kulturhus\Verein\DocumentChecks::CATEGORIES[$value]??$value);elseif($key==='state')$value=$ccI($value==='archived'?'Archiviert':'Aktiv überwachen');elseif(in_array($key,['valid_until','review_on'],true))$value=$ccDate((string)$value);?><dt><?= e($ccI($label)) ?></dt><dd><?= e((string)$value) ?></dd><?php endforeach ?></dl><?php };
?>
<script src="document-checks.js?v=1" defer></script><link rel="stylesheet" href="document-workflows.css?v=2"><link rel="stylesheet" href="calendar-checks.css?v=2">
<section class="content document-check-workspace dwf-workspace" data-check-workspace>
<header class="dwf-heading"><div><h1><?= e($ccI('Fristen & Nachweise')) ?></h1><p><?= e($ccI('Genehmigungen, Hygiene, Erste Hilfe und Versicherungen')) ?></p></div><a class="btn btn-solid theme-primary dwf-button" href="<?= e($ccUrl($ccState['editing']?[]:['new_check'=>1])) ?>"><?= e($ccI($ccState['editing']?'Zur Übersicht':'Nachweis erfassen')) ?></a></header>
<?php if($ccState['error']!==''): ?><p class="dwf-alert dwf-error" role="alert"><?= e($ccI($ccState['error'])) ?></p><?php endif ?>
<?php if(($notice??'')!==''): ?><p class="dwf-alert" role="status"><?= e($ccI($notice)) ?></p><?php endif ?>
<?php if($ccState['fatal']): ?><p><a href="<?= e($ccUrl()) ?>"><?= e($ccI('Zur Übersicht')) ?></a></p></section><?php return;endif ?>
<nav class="dwf-tabs" aria-label="<?= e($ccI('Nachweisbereiche')) ?>"><a href="<?= e($ccUrl()) ?>"><?= e($ccI('Aktive Nachweise')) ?></a><a href="<?= e($ccUrl(['state'=>'archived'])) ?>"><?= e($ccI('Archiv')) ?></a><a href="<?= e($ccUrl(['check_receipts'=>1])) ?>"><?= e($ccI('Meine Nachweis-Anlegevorgänge')) ?></a><a href="?view=documents"><?= e($ccI('Dateiablage')) ?></a><a href="?view=calendar&amp;mode=agenda&amp;calendar_filter=deadlines"><?= e($ccI('Fristen im gemeinsamen Kalender')) ?></a><a href="?view=portal&amp;tab=safety"><?= e($ccI('Anleitung für Mitglieder')) ?></a></nav>
<?php if($ccState['editing']):require __DIR__.'/document-check-form.php';
elseif($ccState['mode']==='receipt'||$ccState['mode']==='receipts'):require __DIR__.'/document-check-creations.php';
else:require __DIR__.'/document-checks-list.php';endif ?>
</section>
