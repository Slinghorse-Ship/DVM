<?php declare(strict_types=1);
use Kulturhus\Verein\SettingsSchema;
$accountNative=\Kulturhus\Verein\ProductionRuntime::nativeManager();
$accountLanguageCatalog=$accountNative?($managerLanguageCatalog??new \Kulturhus\Native\Language\Catalog($db,$nativeAccess)):null;
$accountLanguageMessages=$accountLanguageCatalog?->messages()??\Kulturhus\Native\Language\AccountMessages::messages('de');
$accountText=static fn(string $source):string=>$accountLanguageMessages[\Kulturhus\Native\Language\AccountMessages::key($source)]??$source;


// Each existing partial owns its authorization and its separate form. Render once,
// then use its read-only state for the closed section's summary.
ob_start();require __DIR__.'/session-device-settings.php';$accountSecurityHtml=(string)ob_get_clean();
ob_start();require __DIR__.'/../Settings/notification-settings.php';$accountNotificationsHtml=(string)ob_get_clean();
ob_start();require __DIR__.'/../Governance/board-signature.php';$accountSignatureHtml=(string)ob_get_clean();
$accountAction=input($_POST,'action');
$accountSavedPanel=is_string($_SESSION['account_saved_panel']??null)&&in_array($_SESSION['account_saved_panel'],['profile','notifications','signature'],true)?$_SESSION['account_saved_panel']:'';unset($_SESSION['account_saved_panel']);
$accountOpen=match($accountAction){'settings-save'=>input($_POST,'section')==='account'?'profile':'','settings-notifications'=>'notifications','settings-signature'=>'signature',default=>$accountSavedPanel};
if(!empty($deviceSession['saved']))$accountOpen='security';
elseif(($notice??'')==='Einstellungen gespeichert.')$accountOpen='profile';
elseif(str_starts_with((string)($notice??''),'Unterschrift gespeichert.'))$accountOpen='signature';
elseif(str_starts_with((string)($notice??''),'Deine Benachrichtigungsthemen wurden gespeichert.'))$accountOpen='notifications';
$accountStartViews=$settings->startViews();
foreach($accountStartViews as &$accountStartLabel)$accountStartLabel=$accountText($accountStartLabel);unset($accountStartLabel);
$accountName=$accountNative?$nativeAccess->user()['name']:($roles[(string)$_SESSION['actor']]??'Testzugang');
$accountNotificationCount=count(array_intersect($notificationState['selected'],$notificationState['available']?array_keys($notificationState['available']):[]));
?>
<script defer src="account-settings.js?v=1"></script>
<div class="account-settings" data-account-settings data-account-messages="<?= e(json_encode(\Kulturhus\Native\Language\AccountMessages::browser($accountLanguageMessages,['Dieses Feld','Bitte dieses Feld bestätigen.','Bitte eine Datei auswählen.','Bitte einen Eintrag auswählen.','Bitte dieses Feld ausfüllen.','Bitte eine gültige E-Mail-Adresse eingeben.','Bitte eine gültige Angabe eingeben.','Bitte mindestens {value} eingeben.','Bitte höchstens {value} eingeben.','Bitte höchstens {value} Zeichen eingeben.','Bitte mindestens {value} Zeichen eingeben.','Bitte eine zulässige Zahl oder ein gültiges Datum eingeben.','Bitte das angegebene Format beachten.','Bitte diese Angabe prüfen.','Bitte die markierten Angaben prüfen:']),JSON_THROW_ON_ERROR)) ?>">
 <div class="account-overview">
  <span class="account-overview-icon" aria-hidden="true"><?= icon('settings') ?></span>
  <div><p class="account-overview-name"><?= e($accountText('Dein Zugang:')) ?> <strong><?= e($accountName) ?></strong></p><p><?= e($accountText('Öffne den Bereich, den du ändern möchtest. Jede Änderung wird dort einzeln gespeichert.')) ?></p></div>
 </div>



 <details class="account-panel" data-account-panel="profile" <?= $accountOpen==='profile'?'open':'' ?>>
  <summary><span><strong><?= e($accountText('Persönliche Anzeige')) ?></strong><small><?= e($accountText('Startseite:')) ?> <?= e($accountStartViews[$sd['start_view']??'']??$accountText('Übersicht')) ?> · <?= e($sd['page_size']??25) ?> <?= e($accountText('Zeilen pro Liste')) ?></small></span></summary>
  <div class="account-panel-body" id="account-profile">
   <p><?= e($accountText('Diese Einstellungen gelten nur für deinen Zugang. Du kannst sie bei Bedarf ändern.')) ?></p>
   <form method="post" class="settings-form" data-settings-form data-account-profile>
    <?= csrf().hidden('action','settings-save').hidden('section','account').hidden('version',$sv) ?>
    <div class="form-grid">
     <?php foreach(SettingsSchema::fields('account') as $key=>$field){
      if(in_array($key,['admin_view','admin_color','admin_density'],true))continue;
      $field[0]=$accountText($field[0]);
      if($key==='start_view'){$field[2]=$accountStartViews;if(!isset($field[2][$sd[$key]??'']))$sd[$key]='dashboard';}
      settingsField($key,$field,$sd);
     } ?>
    </div>
    <div class="settings-actions"><button class="btn btn-solid theme-primary" type="submit"><?= e($accountText('Anzeigeeinstellungen speichern')) ?></button><a class="btn btn-outline theme-secondary button secondary" href="<?= e(url(['view'=>'settings','tab'=>'account'])) ?>#account-profile"><?= e($accountText('Änderungen verwerfen')) ?></a></div>
   </form>
  </div>
 </details>

 <details class="account-panel" data-account-panel="security" <?= $accountOpen==='security'?'open':'' ?>>
  <summary><span><strong><?= e($accountText('Anmeldung & Sicherheit')) ?></strong><small><?= $deviceReady?e($accountText('Auf diesem Gerät:')).' '.e($accountText($deviceModes[$deviceMode])):e($accountText('Persönliche Anmeldung und Geräteeinstellungen')) ?> · <?= e($accountText('Zwei-Faktor-Anmeldung optional')) ?></small></span></summary>
  <div class="account-panel-body">
   <?php if($accountNative): ?><p><?= e($accountText('Du nutzt dasselbe persönliche Konto für die Website und den Vereinsmanager. Passwort und Zwei-Faktor-Anmeldung gelten für beide Bereiche.')) ?></p><?php endif ?>
   <?= $accountSecurityHtml ?>
   <?php if($accountNative): ?><div class="account-security-option"><h3><?= e($accountText('Zusätzlicher Schutz per E-Mail')) ?></h3><p><?= e($accountText('Die Zwei-Faktor-Anmeldung ist freiwillig. Du kannst sie für deinen eigenen Zugang verwalten.')) ?></p><a class="btn btn-outline theme-secondary button secondary" href="<?= $accountNative?'/account':'/login/?security=1' ?>"><?= e($accountText('Zwei-Faktor-Anmeldung verwalten')) ?></a></div><?php endif ?>
  </div>
 </details>

 <details class="account-panel" data-account-panel="notifications" <?= $accountOpen==='notifications'?'open':'' ?>>
  <summary><span><strong><?= e($accountText('Benachrichtigungen')) ?></strong><small><?= $accountNotificationCount===0?e($accountText('Keine Themen ausgewählt')):e($accountNotificationCount).' '.e($accountNotificationCount===1?$accountText('Thema ausgewählt'):$accountText('Themen ausgewählt')) ?> · <?= e($accountText('Themen und dieses Gerät verwalten')) ?></small></span></summary>
  <div class="account-panel-body"><?= $accountNotificationsHtml ?></div>
 </details>

 <?php if(trim($accountSignatureHtml)!==''): ?>
 <details class="account-panel" data-account-panel="signature" <?= $accountOpen==='signature'?'open':'' ?>>
  <summary><span><strong><?= e($accountText('Vorstands-Unterschrift')) ?></strong><small><?= e(!empty($signatureAsset)?$accountText('Unterschrift hinterlegt'):$accountText('Noch keine Unterschrift hinterlegt')) ?> · <?= e($accountText('Für berechtigte Bestätigungen')) ?></small></span></summary>
  <div class="account-panel-body"><?= $accountSignatureHtml ?></div>
 </details>
 <?php endif ?>
 <?php if($accountNative): ?>

 <details class="account-panel" data-account-panel="language">
  <summary><span><strong><?= e($accountText('Oberflächensprache')) ?></strong><small><?= e($accountLanguageCatalog->locale()) ?></small></span></summary>
  <div class="account-panel-body"><p><?= e($accountText('Die Auswahl gilt für deinen Zugang. Website-Inhalte behalten ihre eigene Sprache.')) ?></p><a class="btn btn-outline theme-secondary" href="/account?section=language#account-language"><?= e($accountText('Sprache ändern')) ?></a></div>
 </details>
 <details class="account-panel" data-account-panel="appearance">
  <summary><span><strong><?= e($accountText('Verwaltungsansicht')) ?></strong><small><?= e($accountText('Studio · Fokus · Kompakt')) ?></small></span></summary>
  <div class="account-panel-body"><a class="btn btn-outline theme-secondary" href="?view=appearance#account-appearance"><?= e($accountText('Ansicht auswählen')) ?></a></div>
 </details>
 <?php endif ?>
 <?php if($accountNative): ?>
 <details class="account-panel" data-account-panel="api">
  <summary><span><strong><?= e($accountText('Technische Einrichtung')) ?></strong><small><?= e($accountText('API-Zugänge')) ?> · <?= e($accountText('Deine persönlichen Zugriffsschlüssel')) ?></small></span></summary>
  <div class="account-panel-body"><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'settings','tab'=>'api'])) ?>"><?= e($accountText('API-Zugänge verwalten')) ?></a></div>
 </details>
 <?php endif ?>
</div>
