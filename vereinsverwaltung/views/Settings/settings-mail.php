<?php declare(strict_types=1);
// The parent checks superuser access before loading any protected mailbox state.
?>
<div class="settings-intro"><h2>Postfächer &amp; Versand</h2><p>Hier legst du fest, über welche Adresse der Verein Nachrichten sendet und Rechnungen empfängt. Öffne den Bereich, den du ändern möchtest.</p><p><a class="btn btn-outline theme-secondary button secondary" href="?view=finance&amp;tab=inbox">Eingegangene Rechnungen ansehen</a></p></div>
<?php require __DIR__.'/settings-mail-channels.php';require __DIR__.'/settings-invoice-mailbox.php'; ?>
<details class="settings-panel" id="mail-newsletter-basis" <?= input($_POST,'action')==='settings-save'&&input($_POST,'section')==='mail'?'open':'' ?>>
 <summary><span><strong>Bisherige Newsletter-Basisangaben</strong><small>Nur benötigt, wenn der Newsletter den bisherigen Serverzugang verwendet.</small></span></summary>
 <div class="settings-panel-body"><p>Diese Absenderangaben ergänzen den bisherigen Newsletter-Serverzugang. Wenn für „Newsletter“ ein eigener Postfachzugang ausgewählt ist, gelten dessen Angaben.</p>
 <form method="post" class="settings-form" data-settings-form><?= csrf().hidden('action','settings-save').hidden('section','mail').hidden('version',$sv) ?>
 <div class="form-grid"><?php foreach(\Kulturhus\Verein\SettingsSchema::fields('mail') as $key=>$field)settingsField($key,$field,$sd); ?></div>
 <div class="settings-actions"><button class="btn btn-solid theme-primary">Newsletter-Basisangaben speichern</button></div></form></div>
</details>
