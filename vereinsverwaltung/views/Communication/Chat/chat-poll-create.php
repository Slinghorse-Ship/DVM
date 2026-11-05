<?php declare(strict_types=1);
$creatingPoll=input($_POST,'action')==='chat-poll-create'&&input($_POST,'chat')===$chatId;
$pollOptions=$creatingPoll?arrayInput($_POST,'poll_options'):['',''];while(count($pollOptions)<2)$pollOptions[]='';
$pollDefaultEnd=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->modify('+7 days')->format('Y-m-d\TH:i');
?>
<details class="chat-tools chat-poll-create" id="chat-poll-create" <?= $creatingPoll?'open':'' ?>>
 <summary>+ Umfrage erstellen</summary>
 <p>Eine Frage stellen und die Teilnehmer dieser Unterhaltung eine Antwort auswählen lassen.</p>
 <form method="post" action="<?= e($chatAction) ?>" data-chat-poll-create>
  <?= csrf().hidden('action','chat-poll-create').hidden('chat',$chatId).hidden('request_key',$creatingPoll?input($_POST,'request_key'):bin2hex(random_bytes(16))) ?>
  <label>Deine Frage<input class="form-control" name="question" maxlength="500" required value="<?= e($creatingPoll?input($_POST,'question'):'') ?>" placeholder="Zum Beispiel: Wann treffen wir uns zum Aufbau?"></label>
  <fieldset data-chat-poll-options><legend>Welche Antworten stehen zur Wahl?</legend>
   <?php foreach($pollOptions as $number=>$option): ?><label>Antwort <?= $number+1 ?><input class="form-control" name="poll_options[]" maxlength="160" <?= $number<2?'required':'' ?> value="<?= e($option) ?>" placeholder="Zum Beispiel: <?= $number===0?'Freitag um 16 Uhr':'Samstag um 10 Uhr' ?>"></label><?php endforeach ?>
  </fieldset>
  <div class="chat-actions" data-chat-poll-option-tools hidden><button class="btn btn-outline theme-secondary secondary" type="button" data-chat-poll-option-add>+ Weitere Antwort</button><button class="btn btn-outline theme-secondary secondary" type="button" data-chat-poll-option-remove>Letzte Antwort entfernen</button><span data-chat-poll-option-count role="status"></span></div>
  <noscript><label>Antwort 3 (optional)<input class="form-control" name="poll_options[]" maxlength="160"></label><label>Antwort 4 (optional)<input class="form-control" name="poll_options[]" maxlength="160"></label></noscript>
  <label>Abstimmen bis (optional)<input class="form-control" type="datetime-local" name="closes_on" value="<?= e($creatingPoll?input($_POST,'closes_on'):$pollDefaultEnd) ?>"></label>
  <p>Jede Person hat eine Stimme und kann ihre Antwort bis zum Ende ändern. Ohne Enddatum beendest du die Umfrage selbst. Frage und Antworten bleiben nach dem Erstellen unverändert.</p>
  <button class="btn btn-solid theme-primary">Umfrage in der Unterhaltung starten</button>
 </form>
</details>
