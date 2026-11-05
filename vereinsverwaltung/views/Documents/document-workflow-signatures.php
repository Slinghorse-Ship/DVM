<?php declare(strict_types=1);
/** Personally assigned original only; neither a module-wide list nor a translated declaration. */
$wfSignature=$wfPrepared['signature']??\Kulturhus\Verein\Documents\WorkflowSignatureState::prepare($workflowSignatures,$_GET,$_POST,$wfPrepared['post_action']??'');
if($wfSignature['http_status']>0&&http_response_code()<400)http_response_code($wfSignature['http_status']);
if($workflowError==='')$workflowError=$wfSignature['error'];
$wfSignId=$wfSignature['id'];$wfRequest=$wfSignature['request'];
$wfArchived=$wfRequest&&$wfRequest['signed_at']&&isset($workflowManager)&&$workflowManager->hasOwnFile($wfSignId);
$wfSignatureClientLabels=['Bitte mit Finger oder Maus unterschreiben.','Bitte die Unterschrift neu schreiben.','Alle auf dieser Seite auswählen','Auswahl auf dieser Seite aufheben','Unterschrift neu schreiben','Unterschrift gelöscht.','PNG-Datei auswählen.'];
$wfSignatureClient=array_combine($wfSignatureClientLabels,array_map($wfI,$wfSignatureClientLabels));
$wfOwnPager=static function()use($wfSignature,$workflowBase,$wfBrowseContext,$wfI):void{echo \Kulturhus\Verein\Documents\WorkflowPager::render($wfSignature['page'],'wf_signatures_page',array_replace($workflowBase,$wfBrowseContext,['wf_own'=>1,'wf_sign'=>'']),$wfI);};
?>
<link rel="stylesheet" href="document-workflows.css?v=2" data-dwf-asset>
<script src="document-workflows.js?v=2" defer data-dwf-asset></script>
<section class="dwf-workspace dwf-personal-signatures" data-dwf-workspace data-dwf-creation-text="<?= $wfEsc(json_encode($wfSignatureClient,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)) ?>" aria-label="<?= $wfEsc($wfI('Meine Unterschriften')) ?>">
<header class="dwf-heading"><h1><?= $wfEsc($wfI('Meine Unterschriften')) ?></h1><?php if($wfActor['access']!=='none'): ?><a href="<?= $wfEsc('?'.http_build_query(['view'=>'documents','tab'=>'workflows'])) ?>"><?= $wfEsc($wfI('Zur Dokumentübersicht')) ?></a><?php endif ?></header>
<?php if($workflowError!==''): ?><p class="dwf-alert dwf-error" role="alert"><?= $wfEsc($wfI($workflowError)) ?></p><?php endif ?>
<?php if($workflowNotice!==''): ?><p class="dwf-alert" role="status"><?= $wfEsc($wfI($workflowNotice)) ?></p><?php endif ?>
<?php if($wfRequest): ?>
<article class="dwf-card"><a href="<?= $wfEsc($wfUrl(['wf_own'=>1,'wf_sign'=>''])) ?>"><?= $wfEsc($wfI('← Meine Unterschriften')) ?></a><h2><?= $wfEsc($wfRequest['document']['title']) ?></h2><p><?= $wfEsc($wfF('Dokumentfassung {revision}',['revision'=>$wfRequest['revision']])) ?></p>
<a class="dwf-button dwf-secondary secondary" download href="<?= $wfEsc($wfUrl([$wfArchived?'wf_archived_pdf':'wf_signature_pdf'=>$wfSignId])) ?>"><?= $wfEsc($wfI($wfArchived?'Gespeichertes unterschriebenes PDF lesen':'Beurteilung mit Anlagen als PDF lesen')) ?></a>
<?php if($wfRequest['signed_at']): ?>
<p role="status"><?= $wfEsc($wfF('Unterschrieben von {name} am {date}.',['name'=>$wfRequest['signer_name'],'date'=>$wfDate($wfRequest['signed_at'])])) ?></p>
<?php if(isset($workflowManager)): ?><p><a href="?view=portal&amp;tab=documents#meine-beurteilungen"><?= $wfEsc($wfI('Meine Belege')) ?></a></p><form method="post" action="<?= $wfEsc($wfUrl(['wf_sign'=>$wfSignId])) ?>"><?= $wfHidden('csrf',$workflowCsrf).$wfHidden('action','document-workflow-signature-archive').$wfHidden('wf_sign',$wfSignId) ?><button class="btn btn-outline theme-secondary dwf-button dwf-secondary secondary"><?= $wfEsc($wfI('PDF ablegen')) ?></button></form><?php endif ?>
<?php elseif(!$wfRequest['can_sign']): ?>
<p class="dwf-alert"><?= $wfEsc($wfI('Diese Fassung wurde geändert oder zurückgezogen. Eine neue Unterschrift muss für die freigegebene Fassung angefordert werden.')) ?></p>
<?php else: ?>
<?php if(!$wfSignature['hash_matches']): ?><p class="dwf-alert"><?= $wfEsc($wfI('Die übertragene Prüfsumme gehört nicht zu diesem Original. Das Unterschriftenformular bewusst erneut öffnen.')) ?></p><a class="dwf-button dwf-secondary" href="<?= $wfEsc($wfUrl(['wf_sign'=>$wfSignId])) ?>"><?= $wfEsc($wfI('Unterschriftenformular erneut öffnen')) ?></a><?php endif ?>
<form method="post" novalidate enctype="multipart/form-data" action="<?= $wfEsc($wfUrl(['wf_sign'=>$wfSignId])) ?>" data-dwf-signature-form data-dwf-signature-new="<?= $wfSignature['attempt']?'0':'1' ?>">
<?= $wfHidden('csrf',$workflowCsrf).$wfHidden('action','document-workflow-signature-sign').$wfHidden('wf_sign',$wfSignId).$wfHidden('wf_document_hash',$wfSignature['hash']).$wfHidden('wf_signature_complete','1').$wfHidden('wf_signature_png',$wfSignature['image']) ?>
<h3><?= $wfEsc($wfI('Kenntnisnahme bestätigen')) ?></h3><h4><?= $wfEsc($wfI('Erklärung')) ?></h4><p class="dwf-signature-statement" data-dwf-original-statement><?= $wfEsc($wfRequest['statement']) ?></p>
<label class="dwf-check"><input class="check" type="checkbox" name="wf_accepted" value="1" required <?= $wfSignature['accepted']?'checked':'' ?>><span><?= $wfEsc($wfI('Ich habe die Beurteilung und ihre Anlagen gelesen und verstanden.')) ?></span></label>
<p><strong><?= $wfEsc($wfRequest['member_name']) ?></strong></p>
<fieldset class="dwf-signature-methods"><legend><?= $wfEsc($wfI('Handschriftliche Unterschrift')) ?></legend>
<label class="dwf-check" hidden data-dwf-signature-draw-choice><input class="check" type="radio" name="wf_signature_method" value="draw" disabled><span><?= $wfEsc($wfI('Mit Finger oder Maus unterschreiben')) ?></span></label>
<div hidden data-dwf-signature-draw><canvas class="dwf-signature-canvas" width="600" height="180" data-dwf-signature-canvas tabindex="0" aria-label="<?= $wfEsc($wfI('Unterschrift mit Finger oder Maus')) ?>"><?= $wfEsc($wfI('Der Browser unterstützt das Unterschriftenfeld nicht. Bitte eine PNG-Datei verwenden.')) ?></canvas><button type="button" class="btn btn-outline theme-secondary dwf-button dwf-secondary secondary" data-dwf-signature-clear><?= $wfEsc($wfI('Unterschrift neu schreiben')) ?></button></div>
<label class="dwf-check"><input class="check" type="radio" name="wf_signature_method" value="upload" <?= $wfSignature['image']===''?'checked':'' ?>><span><?= $wfEsc($wfI('Vorhandene handschriftliche PNG-Unterschrift verwenden')) ?></span></label>
<label><?= $wfEsc($wfI('PNG-Datei auswählen')) ?><input class="form-control" type="file" name="wf_signature_file" accept="image/png,.png" data-photo-original><small><?= $wfEsc($wfI('Bis16 KiB;80–1600 Pixel breit und30–500 Pixel hoch. Eine tatsächlich handschriftliche, nicht leere PNG-Datei verwenden.')) ?></small></label>
<?php if($wfSignature['image']!==''): ?><div class="dwf-signature-retained"><label class="dwf-check"><input class="check" type="radio" name="wf_signature_method" value="retained" checked><span><?= $wfEsc($wfI('Meine bereits übertragene Unterschrift erneut verwenden')) ?></span></label><img src="<?= $wfEsc($wfSignature['image']) ?>" alt="<?= $wfEsc($wfI('Deine übertragene handschriftliche Unterschrift')) ?>"><p><?= $wfEsc($wfI('Diese eigene Eingabe wurde noch nicht gespeichert. Sie bleibt an dieselbe Dokumentfassung gebunden.')) ?></p></div><?php endif ?>
</fieldset>
<noscript><p><?= $wfEsc($wfI('Ohne JavaScript kannst du eine vorhandene handschriftliche PNG-Unterschrift auswählen. Die Erklärung und das Original-PDF bleiben vollständig lesbar.')) ?></p></noscript>
<p class="dwf-signature-error" role="alert" data-dwf-signature-error></p><p role="status" data-dwf-signature-status></p>
<div class="dwf-actions"><button class="btn btn-solid theme-primary dwf-button" <?= !$wfSignature['hash_matches']?'disabled':'' ?>><?= $wfEsc($wfI('Unterschreiben')) ?></button></div>
</form><?php endif ?></article>
<?php else: ?>
<?php if($wfSignature['page']!==null):$wfOwnPager(); ?><div class="dwf-card"><?php if(!$wfSignature['page']['rows']): ?><p><?= $wfEsc($wfI('Keine Beurteilung zur Unterschrift vorhanden.')) ?></p><?php else: ?><ul class="dwf-own-signatures"><?php foreach($wfSignature['page']['rows']as$item): ?><li><a href="<?= $wfEsc($wfUrl(['wf_sign'=>$item['id']])) ?>"><?= $wfEsc($item['title']) ?></a><span><?= $wfEsc($wfF('Fassung {revision}',['revision'=>$item['revision']])) ?> · <?= $wfEsc($wfI($item['signed_at']?'Unterschrieben':(($item['status']==='approved'&&(int)$item['version']===(int)$item['revision'])?'Bitte unterschreiben':'Frühere Fassung'))) ?></span></li><?php endforeach ?></ul><?php endif ?></div><?php $wfOwnPager();endif ?>
<?php if($wfSignature['error']!==''): ?><p><a href="<?= $wfEsc($wfUrl(['wf_own'=>1,'wf_sign'=>'','wf_signatures_page'=>1])) ?>"><?= $wfEsc($wfI('Auswahl zurücksetzen')) ?></a></p><?php endif ?>
<?php endif ?></section>
