<?php declare(strict_types=1);
$gcClose=$base+['goods'=>$goodsDetail['id']];$gcRoute=$gcClose+['correct_goods'=>'1'];
$gcState=\Kulturhus\Verein\Web\GoodsCorrectionState::form($goodsDetail,$finance->positions('goods',$goodsDetail['id']),$_POST,date('Y-m-d'));$gc=$gcState['values'];
$gcReview=$goodsCorrectionReview??null;$gcPreview=$gcReview['preview']??null;
$gcStale=$gc['source_version']!==(string)$goodsDetail['version'];$gcReady=preg_match('/\A[a-f0-9]{64}\z/D',$gc['review_hash'])===1;
$gcKey=preg_match('/\A[a-f0-9]{32}\z/D',$gc['request_key'])===1;
?>
<link rel="stylesheet" href="goods-catalogue.css">
<div data-goods-correction-workspace><noscript><link rel="stylesheet" href="finance-intake-nojs.css"></noscript>
<dialog open class="side-sheet goods-donation-sheet goods-correction-sheet" aria-labelledby="goods-correction-title" data-modal data-close-url="<?= e(url($gcClose)) ?>">
<header><div><small><?= $goodsE('Sachspende · gespeicherter Eintrag') ?></small><h2 id="goods-correction-title"><?= $goodsE('Sachspende begründet korrigieren') ?></h2></div><a class="btn btn-outline theme-secondary" href="<?= e(url($gcClose)) ?>" aria-label="<?= $goodsE('Sachspende schließen') ?>">×</a></header>
<?php if(!$goodsCanWrite): ?><div class="sheet-content"><p role="alert"><?= $goodsE('Aktuelle Finanzschreibberechtigung erforderlich.') ?></p><a class="btn btn-outline theme-secondary" href="<?= e(url($gcClose)) ?>"><?= $goodsE('Gespeicherte Sachspende öffnen') ?></a></div>
<?php else: ?>
<form method="post" data-goods-correction-form><?= csrf().hidden('action','goods-correction-review').hidden('source_id',$gc['source_id']).hidden('source_version',$gc['source_version']).hidden('request_key',$gc['request_key']).hidden('review_hash',$gc['review_hash']) ?>
<div class="sheet-content">
<p><?= $goodsE('Prüfe die tatsächlichen Sachangaben, Positionen und den Änderungsgrund. Der vorherige Stand bleibt mit seinem vollständigen Verlauf erhalten.') ?></p>
<p><?= $goodsE('Gespeicherter Wert') ?>: <strong data-goods-current-value><?= e(euro((int)$goodsDetail['value_cents'])) ?></strong> · <?= $goodsE('Aktuelle Fassung') ?> <?= e((string)$goodsDetail['version']) ?></p>
<?php if($gcState['unsupported']): ?><label><?= $goodsE('Nicht unterstützte ursprüngliche Eingaben') ?><textarea class="form-control" rows="4" readonly data-goods-correction-malformed><?= e(json_encode($gcState['unsupported'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)) ?></textarea></label><?php endif ?>
<label><?= $goodsE('Zuwender') ?><input class="form-control" name="donor" maxlength="200" required value="<?= e($gc['donor']) ?>"></label>
<label><?= $goodsE('Bezeichnung') ?><input class="form-control" name="designation" maxlength="200" value="<?= e($gc['designation']) ?>"></label>
<label><?= $goodsE('Spendendatum') ?><input class="form-control" type="<?= $gcState['date_valid']?'date':'text' ?>" name="received_on" required value="<?= e($gc['received_on']) ?>"></label>
<label><?= $goodsE('Beschreibung') ?><textarea class="form-control" name="description" maxlength="2000" required><?= e($gc['description']) ?></textarea></label>
<?php if($gcState['position_error']!==''||!$gcState['date_valid']): ?>
<p role="alert"><?= $goodsE($gcState['position_error']!==''?$gcState['position_error']:'Spendendatum zuerst bewusst korrigieren. Die ursprünglichen Positionen bleiben erhalten.') ?></p>
<label><?= $goodsE('Ursprüngliche Positionen') ?><textarea class="form-control" rows="4" readonly data-goods-correction-original-positions><?= e(json_encode($gcState['raw_positions'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)) ?></textarea></label>
<?php if($gcState['position_error']==='')foreach($gcState['raw_positions']as$gcField=>$gcRows)foreach($gcRows as$gcValue)echo hidden($gcField.'[]',$gcValue);else echo hidden('position_description','unsupported'); ?>
<?php else:
$positionMessages=$goodsUiMessages;$positionText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\PositionMessages::text($source,$positionMessages,$values);$manualFinanceClient=\Kulturhus\Native\Language\ManualFinanceMessages::client($positionMessages);
$positionKind='goods';$positionDefault=$gc['designation'];$positionForm=$gcState['positions'];$positionSimpleLayout=true;$positionServerControls=true;$positionFixedDate=$gc['received_on'];$positionPreviewProvided=true;$positionPreview=null;
try{$positionPreview=$finance->previewEntryPositions($positionForm,'goods',$positionFixedDate);}catch(DomainException $gcPositionFailure){ ?><p role="status"><?= e($positionText($gcPositionFailure->getMessage())) ?></p><?php }
require __DIR__.'/../Settings/positions-editor.php';endif ?>
<label><?= $goodsE('Ursprung der Zuwendung') ?><select class="form-control" name="origin" required><?php if(!$gcState['origin_valid']): ?><option selected value="<?= e($gc['origin']) ?>"><?= $goodsE('Ursprüngliche Eingabe prüfen') ?>: <?= e($gc['origin']) ?></option><?php endif;foreach($goodsOrigins as$gcOrigin=>$gcLabel): ?><option value="<?= e($gcOrigin) ?>" <?= $gc['origin']===$gcOrigin?'selected':'' ?>><?= $goodsE($gcLabel) ?></option><?php endforeach ?></select></label>
<?php if(!$gcState['evidence_valid']): ?><label><?= $goodsE('Ursprünglicher Unterlagenvermerk') ?><select class="form-control" name="evidence_present"><option selected value="<?= e($gc['evidence_present']) ?>"><?= $goodsE('Ursprüngliche Eingabe prüfen') ?>: <?= e($gc['evidence_present']) ?></option><option value="0"><?= $goodsE('Nicht bestätigt') ?></option><option value="1"><?= $goodsE('Als vorhanden vermerkt') ?></option></select></label>
<?php else: ?><?= hidden('evidence_present','0') ?><label class="check evidence-switch"><input class="check" type="checkbox" name="evidence_present" value="1" <?= $gc['evidence_present']==='1'?'checked':'' ?>><?= $goodsE('Geeignete Unterlagen zur Wertermittlung, z. B. Rechnung/Gutachten, liegen vor') ?></label><?php endif ?>
<label><?= $goodsE('Grundlage der Bewertung') ?><textarea class="form-control" name="valuation" maxlength="2000" required><?= e($gc['valuation']) ?></textarea></label>
<label><?= $goodsE('Änderungsgrund') ?><textarea class="form-control" name="reason" maxlength="500" required><?= e($gc['reason']) ?></textarea></label>
<?php if($gcStale): ?><p role="status" data-goods-correction-stale><?= $goodsE('Die Sachspende hat inzwischen einen anderen Stand. Deine Eingaben und die ursprüngliche Fassung bleiben erhalten. Zuerst bewusst vergleichen.') ?></p><?php endif ?>
<?php if($gcPreview): ?>
<section class="goods-correction-comparison" data-goods-correction-preview><h3><?= $goodsE('Gespeicherte und beabsichtigte Angaben vergleichen') ?></h3>
<p><?= $goodsE('Ursprüngliche Fassung') ?>: <?= e((string)$gcReview['original_version']) ?> · <?= $goodsE('Geprüfte aktuelle Fassung') ?>: <?= e((string)$gcPreview['source']['version']) ?></p>
<div class="goods-correction-pair"><?php foreach(['source'=>'Aktuell gespeichert','next_source'=>'Beabsichtigte Korrektur']as$gcPart=>$gcTitle):$gcSnapshot=$gcPreview[$gcPart]; ?>
<section><h4><?= $goodsE($gcTitle) ?></h4><dl><?php foreach(['donor'=>'Zuwender','designation'=>'Bezeichnung','description'=>'Beschreibung','received_on'=>'Spendendatum','value_cents'=>'Wert','origin'=>'Ursprung','evidence_present'=>'Unterlagen','valuation'=>'Wertermittlung']as$gcField=>$gcLabel):$gcValue=$gcSnapshot[$gcField];if($gcField==='value_cents')$gcValue=euro((int)$gcValue);elseif($gcField==='origin')$gcValue=$goodsT($goodsOrigins[$gcValue]??$gcValue);elseif($gcField==='evidence_present')$gcValue=$goodsT($gcValue?'Als vorhanden vermerkt':'Nicht bestätigt'); ?>
<dt><?= $goodsE($gcLabel) ?></dt><dd><?= nl2br(e((string)$gcValue)) ?></dd><?php endforeach ?></dl></section><?php endforeach ?></div>
<?php if($gcReview['comparison']&&!$gcReview['adopted']): ?><?= hidden('correction_compare_hash',$gcReview['hash']) ?><p><?= $goodsE('Der Vergleich ändert keine gespeicherten Angaben. Übernimm den geprüften Stand zuerst ins Formular.') ?></p><button class="btn btn-outline theme-secondary" name="action" value="goods-correction-adopt" formnovalidate><?= $goodsE('Geprüften Stand für diese Korrektur übernehmen') ?></button>
<?php else: ?><p role="status" data-goods-correction-reviewed><?= $goodsE('Die Korrektur ist geprüft. Erst das gesonderte Speichern ändert die Sachspende.') ?></p><?php endif ?></section><?php endif ?>
<p><a class="btn btn-outline theme-secondary" href="<?= e(url($gcClose)) ?>"><?= $goodsE('Spenderkontakt, Bescheinigungen und Nachweise öffnen') ?></a></p>
<?php if(!$gcKey||$gcState['unsupported']): ?><p><a class="btn btn-outline theme-secondary" data-goods-correction-reopen href="<?= e(url($gcRoute)) ?>"><?= $goodsE('Korrektur neu öffnen') ?></a> · <?= $goodsE('Beim Neuöffnen werden ungespeicherte Formulareingaben verworfen.') ?></p><?php endif ?>
</div><footer><a class="btn btn-outline theme-secondary" href="<?= e(url($gcClose)) ?>"><?= $goodsE('Abbrechen') ?></a><button class="btn btn-outline theme-secondary" name="action" value="goods-correction-review" formnovalidate><?= $goodsE('Korrektur prüfen') ?></button><button class="btn btn-outline theme-secondary" name="action" value="goods-correction-compare" formnovalidate><?= $goodsE('Aktuellen Stand vergleichen') ?></button><button class="btn btn-solid theme-primary" name="action" value="goods-correction-save" <?= !$gcKey||!$gcReady||$gcStale||$gcState['unsupported']||$gcState['position_error']!==''?'disabled':'' ?>><?= $goodsE('Begründete Korrektur speichern') ?></button></footer></form>
<?php endif ?></dialog></div>
