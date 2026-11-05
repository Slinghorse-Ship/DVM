<?php declare(strict_types=1);
$mnTemplate=$mnState['template'];$mnTemplateDraft=$mnState['template_draft'];$mnTemplateValues=$mnTemplateDraft['values'];$mnTemplateChanged=$mnTemplateDraft['version']!==(string)$mnTemplate['version'];
?>
<details class="dwf-card" id="voucher-mail-template" data-voucher-mail-template <?= $mnState['template_open']?'open':'' ?>><summary><?= e($vmI('Mailvorlage für diese Veranstaltung')) ?></summary>
<section data-voucher-mail-current-template><h3><?= e($vmI($mnTemplate['version']===0?'Standardvorlage noch nicht gespeichert':'Aktuell gespeicherter Stand')) ?></h3><p><?= e($vmF('Fassung {version}',['version'=>$mnTemplate['version']])) ?></p><p class="voucher-manager-original"><?= e($mnTemplate['data']['subject']) ?></p><details><summary><?= e($vmI('Gespeichertes Anschreiben lesen')) ?></summary><div class="voucher-manager-original"><?= e($mnTemplate['data']['message']) ?></div></details></section>
<?php if($mnState['template_open']):?>
<form method="post" data-confirm-other-drafts <?= ($vmState['proposal_restored']??false)&&$vmState['draft']['action']==='event-voucher-mail-template'?'data-unsaved-changes':'' ?>>
<?= $mnForm($mnTemplateDraft) ?>
<p><?= e($vmF('Ursprüngliche Fassung {version}',['version'=>$mnTemplateDraft['version']])) ?></p>
<label><?= e($vmI('Betreff')) ?><input class="form-control" name="mail_subject" value="<?= e($mnTemplateValues['mail_subject']??'') ?>" required></label>
<label><?= e($vmI('Anschreiben')) ?><textarea class="form-control" name="mail_message" rows="9" required><?= e($mnTemplateValues['mail_message']??'') ?></textarea></label>
<p><?= e($vmI('Betreff höchstens 200 Zeichen, Anschreiben höchstens 6.000 Zeichen. Vollständige ungültige Eingaben bleiben korrigierbar.')) ?></p>
<p><?= e($vmI('Für eine persönliche Anrede schreibe')) ?> <strong><?= e($vmI('Hallo {{VORNAME}},')) ?></strong> <?= e($vmI('in das Anschreiben. Der Baustein wird durch den Vornamen des jeweiligen Mitglieds ersetzt – auch beim Versand an alle.')) ?> <code>{{NAME}}</code> <?= e($vmI('fügt den vollständigen Namen ein.')) ?></p>
<p><?= e($vmI('Der Vorname wird aus dem gespeicherten Namen übernommen. Bitte die persönliche Vorschau prüfen.')) ?></p>
<details><summary><?= e($vmI('Weitere Textbausteine')) ?></summary><ul><?php foreach(['{{VERANSTALTUNG}}'=>'Name der Veranstaltung','{{DATUM}}'=>'Veranstaltungsdatum','{{UHRZEIT}}'=>'Beginn','{{ORT}}'=>'Veranstaltungsort']as$mnToken=>$mnLabel):?><li><code><?= e($mnToken) ?></code> · <?= e($vmI($mnLabel)) ?></li><?php endforeach ?></ul><p><?= e($vmI('Bausteine mit beiden geschweiften Klammern in Betreff oder Anschreiben eintragen. Speichern und danach die persönliche Mailvorschau prüfen.')) ?></p></details>
<p><?= e($vmI('Persönliche Gutscheine, QR-Codes und PDF werden automatisch ergänzt.')) ?></p><p><?= e($vmI('Vorlage und Vorschautext behalten ihre ursprüngliche Sprache. Ungespeicherte Vorlagenänderungen werden nicht versendet.')) ?></p>
<?php if($mnTemplateChanged):?><p class="dwf-alert" role="alert"><?= e($vmI('Die Mailvorlage wurde inzwischen geändert. Bitte neu laden.')) ?></p><?php endif ?>
<button class="btn btn-solid theme-primary dwf-button" <?= $mnTemplateChanged||!preg_match('/^[a-f0-9]{32}$/D',$mnTemplateDraft['key'])?'disabled':'' ?>><?= e($vmI('Vorlage speichern')) ?></button>
<?php $mnReview($mnTemplateDraft); ?>
</form>
<?php else:?><a class="btn btn-outline theme-secondary dwf-button" href="<?= e($mnUrl(['voucher_mail_section'=>'template'])) ?>"><?= e($vmI('Mailvorlage bearbeiten')) ?></a><?php endif ?>
</details>
