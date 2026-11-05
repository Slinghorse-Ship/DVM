<?php declare(strict_types=1);
$assetSourceMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\ManagerMessages::messages('de');
$ast=static fn(string$source):string=>\Kulturhus\Native\Language\AssetBookSourceMessages::text($source,$assetSourceMessages);
$as=$assetBookSourceState;$asFields=$as['fields'];$asRoute=['view'=>'finance','tab'=>'book-values','item'=>$asFields['item'],'year'=>$asFields['year']];if($asFields['partial_review']!=='')$asRoute['partial_review']=$asFields['partial_review'];
$asUnsupported=$as['unsupported']!==[];$asRaw=$as['raw'];$asError=$as['command_error']??'';
?>
<link rel="stylesheet" href="finance-workflows.css?v=1"><link rel="stylesheet" href="tax-guided.css?v=1"><link rel="stylesheet" href="asset-book-source.css?v=1">
<div class="content asset-book-source" data-asset-book-unavailable="<?=e($as['state'])?>" data-original-status="<?=e((string)$as['original_status'])?>">
<section class="card fw-workflow">
<h1><?=e($ast('Anlagenauswahl prüfen'))?></h1>
<p role="alert"><?=e($ast($as['message']))?></p>
<?php if($asError!==''): ?><p data-asset-source-command-error><strong><?=e($ast('Vorheriger Vorgang'))?>:</strong> <?=e($ast($asError))?></p><?php endif ?>
<form method="get" data-asset-source-form><?=hidden('view','finance').hidden('tab','book-values')?><?php if($asFields['partial_review']!=='')echo hidden('partial_review',$asFields['partial_review']); ?>
<fieldset <?=$asUnsupported?'disabled':''?>>
<label for="asset-source-item"><?=e($ast('Vollständige Gegenstandskennung'))?><input id="asset-source-item" class="form-control" name="item" value="<?=e($asFields['item'])?>" required><small><?=e($ast('Die ganze Kennung aus dem ursprünglichen Link verwenden.'))?></small></label>
<label for="asset-source-year"><?=e($ast('Ursprünglich angefordertes Jahr'))?><input id="asset-source-year" class="form-control" type="text" inputmode="numeric" name="year" value="<?=e($asFields['year'])?>" required><small><?=e($ast('Ein vollständiges vierstelliges Jahr eingeben. Die Bewertungsmethode kann den zulässigen Zeitraum weiter einschränken.'))?></small></label>
<button class="btn btn-solid theme-primary"><?=e($ast('Gegenstand und Jahr prüfen'))?></button>
</fieldset></form>
<p><?=e($ast('Die eingetragenen Angaben werden hier nur gelesen. Eine Jahresbestätigung wird nicht ausgeführt.'))?></p>
<?php if($as['source']==='post'): ?><p><?=e($ast('Ungespeicherte Angaben des fehlgeschlagenen Vorgangs werden nicht übernommen. Lesen Sie sie bei Bedarf unter den ursprünglichen Angaben.'))?></p><?php endif ?>
<details data-asset-source-originals><summary><?=e($ast('Angaben lesen'))?></summary><pre><?=e(json_encode($asRaw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT))?></pre></details>
<div class="asset-source-actions">
<?php if(!$asUnsupported): ?><a class="btn btn-outline theme-secondary" data-asset-source-retry href="<?=e(url($asRoute))?>"><?=e($ast('Ursprüngliche Auswahl erneut prüfen'))?></a><?php endif ?>
<?php if($as['open_review']): ?><a class="btn btn-outline theme-secondary" data-asset-source-review href="<?=e(url($as['open_review']))?>"><?=e($ast('Ursprünglichen Prüfstand ausdrücklich öffnen'))?></a><?php endif ?>
<?php if($asFields['partial_review']!==''&&!$asUnsupported&&$asFields['item']!==''): ?><a class="btn btn-outline theme-secondary" data-asset-source-without-review href="<?=e(url(array_diff_key($asRoute,['partial_review'=>true])))?>"><?=e($ast('Anlagenplan ohne bisherigen Prüfstand prüfen'))?></a><?php endif ?>
<a class="btn btn-outline theme-secondary" data-asset-source-reset href="<?=e(url(['view'=>'finance','tab'=>'book-values']))?>"><?=e($ast('Gegenstandsauswahl ohne bisherige Angaben öffnen'))?></a>
</div><p><?=e($ast('Die ursprünglichen Anlagenpläne, Jahresbestätigungen und Nachweise bleiben erhalten.'))?></p>
</section></div>
