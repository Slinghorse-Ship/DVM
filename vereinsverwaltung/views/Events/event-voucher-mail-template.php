<?php declare(strict_types=1);
$mailTemplate=$voucherMail->template($eventId);$templateFailed=input($_POST,'action')==='event-voucher-mail-template';
$templateData=$mailTemplate['data'];
if($templateFailed)$templateData=['subject'=>input($_POST,'mail_subject'),'message'=>input($_POST,'mail_message')];
?>
<details class="card voucher-screen" id="voucher-mail-template" <?= input($_GET,'mail_template')==='1'||$templateFailed?'open':'' ?>><summary>Mailvorlage für diese Veranstaltung</summary>
<form method="post">
<?= $form('mail-template',['template_version'=>$templateFailed?input($_POST,'template_version'):$mailTemplate['version'],'mail_to'=>input($_GET,'mail_to'),'mail_all'=>input($_GET,'mail_all')]) ?>
<label for="voucher-mail-subject">Betreff</label><input class="form-control" id="voucher-mail-subject" name="mail_subject" maxlength="200" required value="<?= e($templateData['subject']) ?>">
<label for="voucher-mail-message">Anschreiben</label><textarea class="form-control" id="voucher-mail-message" name="mail_message" rows="9" maxlength="6000" required><?= e($templateData['message']) ?></textarea>
<p>Für eine persönliche Anrede schreibe <strong>Hallo {{VORNAME}},</strong> in das Anschreiben. Der Baustein wird durch den Vornamen des jeweiligen Mitglieds ersetzt – auch beim Versand an alle. <code>{{NAME}}</code> fügt den vollständigen Namen ein.</p>
<p>Der Vorname wird aus dem gespeicherten Namen übernommen. Bitte die persönliche Vorschau prüfen.</p>
<details><summary>Weitere Textbausteine</summary><ul>
<li><code>{{VERANSTALTUNG}}</code> – Name der Veranstaltung</li><li><code>{{DATUM}}</code> – Veranstaltungsdatum</li>
<li><code>{{UHRZEIT}}</code> – Beginn</li><li><code>{{ORT}}</code> – Veranstaltungsort</li>
</ul><p>Bausteine mit beiden geschweiften Klammern in Betreff oder Anschreiben eintragen. Speichern und danach die persönliche Mailvorschau prüfen.</p></details>
<p>Persönliche Gutscheine, QR-Codes und PDF werden automatisch ergänzt.</p>
<button class="btn btn-solid theme-primary">Vorlage speichern</button>
</form></details>
