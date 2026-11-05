<?php declare(strict_types=1);
$pollVotePosted=input($_POST,'action')==='chat-poll-vote'&&input($_POST,'chat')===$chatId&&input($_POST,'poll')===$chatPoll['id'];
$selectedPollOption=$pollVotePosted?(int)input($_POST,'poll_option'):$chatPoll['own_option'];
?>
<section class="chat-poll" data-chat-poll="<?= e($chatPoll['id']) ?>">
 <div class="chat-poll-heading"><span>Umfrage</span><strong><?= $chatPoll['open']?'Abstimmung läuft':'Beendet' ?></strong></div>
 <h3><?= e($chatPoll['question']) ?></h3>
 <?php if($chatPoll['closes_at']): ?><p class="chat-poll-deadline">Ende: <?= e((new DateTimeImmutable($chatPoll['closes_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y · H:i')) ?> Uhr</p><?php endif ?>
 <?php if($chatPoll['open']): ?><form method="post" action="<?= e($chatAction) ?>" data-chat-poll-vote>
  <?= csrf().hidden('action','chat-poll-vote').hidden('chat',$chatId).hidden('poll',$chatPoll['id']).hidden('vote_version',$pollVotePosted?input($_POST,'vote_version'):$chatPoll['vote_version']).hidden('request_key',$pollVotePosted?input($_POST,'request_key'):bin2hex(random_bytes(16))) ?>
  <fieldset><legend><?= $chatPoll['own_option']?'Deine Antwort ändern':'Eine Antwort auswählen' ?></legend>
  <?php foreach($chatPoll['options'] as $option): ?><label class="chat-poll-option"><input class="check" type="radio" name="poll_option" value="<?= (int)$option['option_number'] ?>" required <?= $selectedPollOption===(int)$option['option_number']?'checked':'' ?>><span><?= e($option['label']) ?><small><?= (int)$option['votes'] ?> <?= (int)$option['votes']===1?'Stimme':'Stimmen' ?></small></span></label><?php endforeach ?>
  </fieldset><button class="btn btn-solid theme-primary"><?= $chatPoll['own_option']?'Antwort ändern':'Antwort speichern' ?></button>
 </form><?php else: ?><ul class="chat-poll-results"><?php foreach($chatPoll['options'] as $option): ?><li><span><?= e($option['label']) ?><?php if($chatPoll['own_option']===(int)$option['option_number']): ?><small>Deine Antwort</small><?php endif ?></span><strong><?= (int)$option['votes'] ?> <?= (int)$option['votes']===1?'Stimme':'Stimmen' ?></strong></li><?php endforeach ?></ul><?php endif ?>
 <p class="chat-poll-summary"><?= (int)$chatPoll['total_votes'] ?> <?= (int)$chatPoll['total_votes']===1?'Person hat':'Personen haben' ?> abgestimmt. <?= $chatPoll['open']?'Eine Antwort pro Person.':'Das Ergebnis bleibt in der Unterhaltung erhalten.' ?></p>
 <?php if($chatPoll['open']&&$chatPoll['own_option']): ?><p class="chat-poll-own">Deine bisherige Antwort ist gespeichert.</p><?php endif ?>
 <?php if($chatPoll['can_close']): ?><details class="chat-poll-close"><summary>Umfrage jetzt beenden</summary><p>Danach können die Teilnehmer nicht mehr abstimmen oder ihre Antwort ändern.</p><form method="post" action="<?= e($chatAction) ?>"><?= csrf().hidden('action','chat-poll-close').hidden('chat',$chatId).hidden('poll',$chatPoll['id']).hidden('poll_version',$chatPoll['version']) ?><button class="btn btn-outline theme-secondary secondary">Abstimmung beenden</button></form></details><?php endif ?>
</section>
