<?php declare(strict_types=1);
$chatService=new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']);$chatDirectory=$chatService->directory();
$chatCreating=input($_POST,'action')==='chat-create';$chatSelected=$chatCreating?arrayInput($_POST,'people'):[];$chatId=input($_GET,'chat');$chatNames=[];
$chatAction=url(['view'=>'chat']+($chatId!==''?['chat'=>$chatId]:[]));
$chatName=static fn(array $person):string=>preg_match('/^pw:([0-9]+)$/D',$person['name'],$match)?($chatNames[(int)$match[1]]??'Website-Konto '.$match[1]):$person['name'];
$chatSending=input($_POST,'action')==='chat-send'&&input($_POST,'chat')===$chatId;$chatAdding=input($_POST,'action')==='chat-add-people'&&input($_POST,'chat')===$chatId;
$currentChat=$chatId?$chatService->thread($chatId):null;$currentPeople=$currentChat?$chatService->people($chatId):[];
$messages=$currentChat?$chatService->messages($chatId,max(1,(int)input($_GET,'before',(string)PHP_INT_MAX))):[];if($messages&&!isset($_GET['before']))$chatService->read($chatId,(int)end($messages)['seq']);$chatThreads=$chatService->listing();
$chatPolls=$currentChat?(new \Kulturhus\Verein\ChatPolls($db,(string)$_SESSION['actor']))->forMessages($chatId,$messages):[];
?>
<link rel="stylesheet" href="chat.css?v=2"><script src="chat.js?v=5" defer></script>
<div class="page-heading chat-page-heading"><div><h1>Chat</h1><p>Absprechen, nachfragen und gemeinsam planen.</p></div><a class="btn btn-solid theme-primary button" href="#chat-create" data-chat-create-open>+ Neue Unterhaltung</a></div>
<div class="content chat-workspace" data-chat-workspace>
 <details class="chat-create card" id="chat-create" <?= $chatCreating||!$chatThreads?'open':'' ?>>
  <summary>Neue Unterhaltung beginnen</summary>
  <form method="post" action="<?= e($chatAction) ?>" class="chat-create-form"><?= csrf().hidden('action','chat-create') ?>
   <label for="chat-title">Worum geht es?<input class="form-control" id="chat-title" name="title" maxlength="150" value="<?= e($chatCreating?input($_POST,'title'):'') ?>" placeholder="Zum Beispiel: Aufbau beim Sommerfest" required></label>
   <fieldset data-choice-list="Teilnehmer" data-choice-limit="49"><legend>Wer soll dabei sein?</legend><p>Du bist dabei. Wähle mindestens eine weitere Person, höchstens 49.</p>
    <?php foreach($chatDirectory as $person):if($person['id']===$_SESSION['actor'])continue; ?><label class="check"><input class="check" type="checkbox" name="people[]" value="<?= e($person['id']) ?>" <?= in_array($person['id'],$chatSelected,true)?'checked':'' ?>><?= e($chatName($person)) ?></label><?php endforeach ?>
   </fieldset><div class="chat-actions"><button class="btn btn-solid theme-primary">Unterhaltung erstellen</button><button type="button" class="btn btn-outline theme-secondary secondary" data-chat-create-close>Abbrechen</button></div>
  </form>
 </details>
 <div class="chat-layout">
  <aside class="chat-rooms card" aria-label="Unterhaltungen">
   <div class="chat-rooms-heading"><h2>Unterhaltungen</h2><span data-chat-room-total><?= count($chatThreads) ?></span></div>
   <?php if($chatThreads): ?><label class="chat-room-search" for="chat-room-query">Unterhaltung suchen<input class="form-control" id="chat-room-query" type="search" autocomplete="off" placeholder="Name der Unterhaltung" data-chat-room-search></label><?php endif ?>
   <nav class="chat-room-list" aria-label="Unterhaltung auswählen" data-chat-room-list>
    <?php foreach($chatThreads as $thread): ?><a class="chat-room <?= $thread['id']===$chatId?'is-current':'' ?>" href="<?= e(url(['view'=>'chat','chat'=>$thread['id']])) ?>" data-chat-room data-chat-title="<?= e($thread['title']) ?>" <?= $thread['id']===$chatId?'aria-current="page"':'' ?>><span class="chat-room-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($thread['title']),0,1))) ?></span><span class="chat-room-title"><?= e($thread['title']) ?><small><?= $thread['id']===$chatId?'Geöffnet':'Unterhaltung öffnen' ?></small></span><?php if($thread['unread']): ?><span class="chat-unread" aria-label="<?= (int)$thread['unread'] ?> ungelesene Nachrichten"><?= (int)$thread['unread'] ?></span><?php endif ?></a><?php endforeach ?>
   </nav>
   <p class="chat-room-empty" data-chat-room-empty <?= $chatThreads?'hidden':'' ?>><?= $chatThreads?'Keine passende Unterhaltung. Suchtext ändern.':'Noch keine Unterhaltung vorhanden. Beginne oben eine neue.' ?></p>
  </aside>
  <?php if($currentChat): ?>
  <section class="chat-conversation card" aria-label="<?= e($currentChat['title']) ?>">
   <header class="chat-conversation-heading"><div><p class="chat-eyebrow">Unterhaltung</p><h2><?= e($currentChat['title']) ?></h2><a href="#chat-people" data-chat-people-open><?= count($currentPeople) ?> Personen · Teilnehmer ansehen</a></div><a class="btn btn-outline theme-secondary button secondary chat-refresh" data-chat-refresh href="<?= e(url(['view'=>'chat','chat'=>$chatId])) ?>">Aktualisieren</a></header>
   <div class="chat-update-bar"><p id="chat-notification" role="status" aria-live="polite"><?= isset($_GET['before'])?'Du siehst ältere Nachrichten.':'Neue Nachrichten erscheinen automatisch.' ?></p><button class="btn btn-outline theme-secondary secondary" type="button" data-chat-latest hidden>Zu den neuesten Nachrichten ↓</button></div>
   <?php if(isset($_GET['before'])): ?><p class="chat-history-link"><a href="<?= e(url(['view'=>'chat','chat'=>$chatId])) ?>">Zur aktuellen Unterhaltung →</a></p><?php endif ?>
   <div class="chat-scroll" data-chat-scroll tabindex="0" aria-label="Nachrichtenverlauf">
    <?php if(count($messages)===200): ?><p class="chat-history-link"><a href="<?= e(url(['view'=>'chat','chat'=>$chatId,'before'=>$messages[0]['seq']])) ?>">Ältere Nachrichten anzeigen</a></p><?php endif ?>
    <?php require __DIR__.'/chat-messages.php'; ?>
   </div>
   <form method="post" action="<?= e($chatAction) ?>" enctype="multipart/form-data" class="chat-compose" data-chat-compose><?= csrf().hidden('action','chat-send').hidden('chat',$chatId).hidden('request_key',$chatSending?input($_POST,'request_key'):bin2hex(random_bytes(16))) ?>
    <label for="chat-body">Deine Nachricht<textarea class="form-control" id="chat-body" name="body" maxlength="5000" rows="3" placeholder="Hier deine Nachricht schreiben …"><?= e($chatSending?input($_POST,'body'):'') ?></textarea></label>
    <div class="chat-compose-bottom"><details class="chat-attachment" <?= $chatSending?'open':'' ?>><summary>Datei anhängen</summary><label>Anhang bis 2 MB · PDF, PNG, JPG · HEIC/RAW bis 256 MiB als JPEG<input class="form-control" type="file" data-photo-server name="chat_file" accept=".pdf,.png,.jpg,.jpeg,.heic,.heif,.hif,.dng,.cr2,.cr3,.crw,.nef,.nrw,.arw,.sr2,.srf,.rw2,.rwl,.orf,.raf,.pef,.ptx,.raw,.iiq,.3fr,.fff,.kdc,.dcr,.mos,.mef,.mrw,.erf"></label></details><button class="btn btn-solid theme-primary chat-send">Nachricht senden</button></div>
    <p class="chat-compose-help">Enter beginnt eine neue Zeile. Gesendet wird erst mit „Nachricht senden“.</p>
   </form>
   <?php require __DIR__.'/chat-poll-create.php'; ?>
   <details class="chat-tools" id="chat-people" <?= $chatAdding?'open':'' ?>><summary>Teilnehmer ansehen<?= $currentChat['owner']===$_SESSION['actor']?' oder hinzufügen':'' ?></summary>
    <ul class="chat-people"><?php foreach($currentPeople as $person): ?><li><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($chatName($person)),0,1))) ?></span><?= e($chatName($person)) ?><?= $person['id']===$_SESSION['actor']?' · Du':'' ?></li><?php endforeach ?></ul>
    <?php if($currentChat['owner']===$_SESSION['actor']):$chatAddable=array_filter($chatDirectory,static fn($person)=>!in_array($person['id'],array_column($currentPeople,'id'),true));if($chatAddable): ?>
     <form method="post"><?= csrf().hidden('action','chat-add-people').hidden('chat',$chatId) ?><fieldset data-choice-list="Weitere Teilnehmer" data-choice-limit="<?= max(0,50-count($currentPeople)) ?>"><legend>Weitere Personen einladen</legend><p>Neu hinzugefügte Personen können auch alle bisherigen Nachrichten und Anhänge dieser Unterhaltung lesen.</p><?php foreach($chatAddable as $person): ?><label class="check"><input class="check" type="checkbox" name="people[]" value="<?= e($person['id']) ?>" <?= $chatAdding&&in_array($person['id'],arrayInput($_POST,'people'),true)?'checked':'' ?>><?= e($chatName($person)) ?></label><?php endforeach ?></fieldset><button class="btn btn-solid theme-primary">Ausgewählte Teilnehmer hinzufügen</button></form>
    <?php else: ?><p>Alle verfügbaren Personen sind bereits dabei.</p><?php endif;endif ?>
   </details>
   <details class="chat-tools chat-leave" <?= input($_POST,'action')==='chat-leave'?'open':'' ?>><summary>Unterhaltung verlassen</summary><form method="post"><?= csrf().hidden('action','chat-leave').hidden('chat',$chatId) ?><p>Danach kannst du diese Unterhaltung und ihre Anhänge nicht mehr öffnen.</p><button class="btn btn-outline theme-secondary secondary">Unterhaltung verlassen</button></form></details>
  </section>
  <?php else: ?>
  <section class="chat-welcome card"><span class="chat-welcome-symbol" aria-hidden="true">…</span><h2>Hier ist Platz für eure Absprachen.</h2><p>Wähle eine Unterhaltung aus der Liste oder beginne eine neue mit den gewünschten Personen.</p><a class="btn btn-solid theme-primary button" href="#chat-create" data-chat-create-open>+ Neue Unterhaltung</a><p id="chat-notification" role="status" aria-live="polite"></p></section>
  <?php endif ?>
 </div>
 <p><a href="<?= e(url(['view'=>'settings','tab'=>'account'])) ?>#notifications">Benachrichtigungen in Mein Account einstellen →</a></p>
</div>
