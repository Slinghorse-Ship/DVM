<?php declare(strict_types=1); $reminders=$native['reminders']??[]; ?>
<details class="card setup-accordion" id="newsletter-confirmations"><summary>Optionen: Anmeldung nochmal bestätigen</summary>
<p><?= (int)($reminders['pending']??0) ?> Anmeldungen warten auf Bestätigung.</p>
<?php if(($reminders['queued']??0)>0): ?><p role="status"><?= (int)$reminders['queued'] ?> Bestätigungsmails eingeplant. Versand beim nächsten Hintergrundlauf.</p><?php endif ?>
<?php if(($reminders['sent']??0)>0): ?><p><?= (int)$reminders['sent'] ?> Bestätigungsmails versendet; die Anmeldung wurde noch nicht bestätigt.</p><?php endif ?>
<?php if(($reminders['review']??0)>0): ?><p role="alert"><?= (int)$reminders['review'] ?> Versandergebnisse bitte prüfen. Unklare Versendungen werden nicht automatisch wiederholt.</p><?php endif ?>
<?php if(($reminders['invalid']??0)>0): ?><p><?= (int)$reminders['invalid'] ?> Anmeldungen können wegen unvollständiger Angaben keine Erinnerung erhalten.</p><?php endif ?>
<?php if($nativeCanWrite&&($reminders['available']??0)>0): ?>
<details><summary>Bestätigungsmail an alle Unbestätigten senden (<?= (int)$reminders['available'] ?>)</summary>
<p>Alle noch unbestätigten Anmeldungen erhalten eine persönliche Mail mit neuem Link. Erst den Link öffnen, dann auf der Website „Newsletter bestätigen“ anklicken. Der neue Link gilt 48 Stunden. Bereits bestätigte oder abgemeldete Adressen erhalten keine Mail.</p>
<?php $start('newsletter/remind'); ?>
<label class="check"><input class="check" type="checkbox" name="fields[send_confirmed]" value="1" required>Bestätigungsmail an alle <?= (int)$reminders['available'] ?> unbestätigten Anmeldungen senden.</label>
<?php $button('Bestätigungsmails senden',(bool)$native['mail_allowed']); ?>
</details>
<?php endif ?>
<?php if(($reminders['cooldown']??0)>0): ?><p>Für <?= (int)$reminders['cooldown'] ?> Anmeldungen ist eine erneute Erinnerung frühestens 48 Stunden nach dem letzten Versand möglich.</p><?php endif ?>
</details>
