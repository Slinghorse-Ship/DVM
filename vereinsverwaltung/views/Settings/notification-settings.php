<?php declare(strict_types=1);
require __DIR__.'/../Auth/account-language-context.php';
$notificationState=(new \Kulturhus\Verein\ManagerNotifications($db,(string)$_SESSION['actor']))->state();
$notificationSelected=$notificationState['selected'];$notificationVersion=(int)$notificationState['version'];
if(input($_POST,'action')==='settings-notifications'){
    $notificationSelected=is_array($_POST['topics']??null)?array_values(array_filter($_POST['topics'],'is_string')):[];
    if(input($_POST,'chat_choice_present')!=='1'&&in_array('chat',$notificationState['selected'],true))$notificationSelected[]='chat';
    $notificationVersion=(int)input($_POST,'version');
}
$notificationDescriptions=[
 'chat'=>'Wenn dir jemand in einer deiner Unterhaltungen schreibt. Eigene und bereits gelesene Nachrichten werden nicht gemeldet.',
 'invoices'=>'Wenn eine neue Rechnung im Rechnungseingang bereitliegt.',
 'payments'=>'Wenn ein neuer Zahlungseingang erfasst wurde.',
 'events'=>'Wenn eine neue Veranstaltung veröffentlicht wurde.',
 'meetings'=>'Wenn du zu einer Mitglieder- oder Vorstandsversammlung eingeladen wirst.',
 'documents'=>'Wenn ein neues Dokument für dich persönlich bereitliegt.',
 'contributions'=>'Wenn dein eigener Vereinsbeitrag durch zugeordnete Zahlungen vollständig bezahlt ist.',
 'minutes'=>'Wenn ein neues Protokoll erstmals für die berechtigten Leser veröffentlicht wurde. Entwürfe und spätere Textänderungen lösen keinen neuen Hinweis aus.',
];
?>
<section class="card" id="notifications" aria-labelledby="notifications-title">
 <h2 id="notifications-title"><?= e($accountText('Worüber möchtest du Bescheid bekommen?')) ?></h2>
 <p><?= e($accountText('Wähle, worüber deine Geräte dich informieren sollen. Hier kannst du auch Chat-Mitteilungen einzeln ein- oder ausschalten. Die Auswahl gilt für deinen Zugang; andere Mitglieder und E-Mails werden dadurch nicht verändert.')) ?></p>
 <form method="post" class="settings-form" data-notification-settings>
  <?= csrf().hidden('action','settings-notifications').hidden('version',$notificationVersion).hidden('chat_choice_present','1') ?>
  <fieldset><legend><?= e($accountText('1. Gewünschte Themen auswählen')) ?></legend>
   <?php foreach($notificationState['available'] as $topic=>$label): ?>
    <label class="setting-check"><input class="check" type="checkbox" name="topics[]" value="<?= e($topic) ?>" <?= in_array($topic,$notificationSelected,true)?'checked':'' ?> aria-describedby="notification-help-<?= e($topic) ?>"> <?= e($accountText($label)) ?></label>
    <p class="muted" id="notification-help-<?= e($topic) ?>"><?= e($accountText($notificationDescriptions[$topic]??'Benachrichtigung zu diesem Thema erhalten.')) ?></p>
   <?php endforeach ?>
   <?php if(!$notificationState['available']): ?><p><?= e($accountText('Für deinen Zugang stehen derzeit keine zusätzlichen Themen zur Auswahl.')) ?></p><?php endif ?>
  </fieldset>
  <?php if($notificationState['available']): ?><button class="btn btn-solid theme-primary" type="submit"><?= e($accountText('Benachrichtigungen speichern')) ?></button><?php endif ?>
 </form>
 <p><?= e($accountText('Die Themenauswahl gilt auf allen Geräten, die du für deinen Zugang aktivierst. Auf dem Sperrbildschirm erscheint nur ein allgemeiner Hinweis. Öffne den Vereinsmanager, um die Angaben zu sehen.')) ?></p>
 <h3><?= e($accountText('2. Dieses Gerät aktivieren')) ?></h3>
 <p><?= e($accountText('Damit Mitteilungen ankommen, muss auch dieses Gerät angemeldet sein und Mitteilungen erlauben. Die Themenauswahl allein öffnet keine Browserabfrage.')) ?></p>
 <?php try{(new \Kulturhus\Verein\PushNotifications($db,(string)$_SESSION['actor']))->count();$notificationPushAvailable=true;}catch(DomainException){$notificationPushAvailable=false;} ?>
 <?php if($notificationPushAvailable): ?>
  <?php $pushContext='account';require __DIR__.'/../Communication/Chat/chat-push.php';unset($pushContext); ?>
  <link rel="stylesheet" href="push.css?v=1"><script src="push.js?v=5" defer></script>
 <?php else: ?><p><?= e($accountText('Die Geräteanmeldung ist für deinen Zugang derzeit nicht verfügbar.')) ?></p><?php endif ?>
</section>
