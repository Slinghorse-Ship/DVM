<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Communication/EditorialConnectionSettings.php';
if(!$moduleWorkspace->canManage()): ?>
<section class="card"><h2>Verbindungseinstellungen</h2><p>Die technische Administration verwaltet Kanalverbindungen und Website-Design. Inhalte und Vorschauen bearbeitest du im jeweiligen Modul.</p></section>
<?php return;endif;
$connection=(new \Kulturhus\Verein\EditorialConnectionSettings($db,(string)$_SESSION['actor'],$editorialModule))->read();$connectedSettings=$connection['settings'];
?>
<section class="card"><h2><?= $editorialModule==='website'?'Website-Designvorlagen':'Social Media · Verbindungen & Automatisierung' ?></h2>
<form method="post"><?= csrf().hidden('action','editorial-connection-save').hidden('module',$editorialModule).hidden('version',$connection['version']).hidden('request_key',bin2hex(random_bytes(16))) ?>
<?php if($editorialModule==='website'): ?>
<p>Diese Vorlagen ändern das tatsächliche Website-Design einschließlich Favicon und App-Farbe.</p>
<label>Website-Design<select class="form-control" name="connection_fields[public_theme]" required><?php foreach($connection['theme_options'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $connectedSettings['public_theme']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<a href="<?= e(\Kulturhus\Verein\WebsiteWorkspace::publicUrl()) ?>" target="_blank" rel="noopener">Website-Vorschau öffnen</a>
<?php else: ?>
<p>Diese Einstellungen steuern die vorhandenen Veröffentlichungs-Worker. Lokale Vorschauen versenden und veröffentlichen nichts.</p>
<?php foreach(['facebook_url'=>'Facebook-Adresse','facebook_page_id'=>'Facebook-Seiten-ID','instagram_username'=>'Instagram-Name','facebook_post_template'=>'Facebook-Textvorlage','instagram_post_template'=>'Instagram-Textvorlage'] as $key=>$label): ?><label><?= e($label) ?><?php if(str_ends_with($key,'template')): ?><textarea class="form-control" name="connection_fields[<?= e($key) ?>]" rows="4"><?= e($connectedSettings[$key]) ?></textarea><?php else: ?><input class="form-control" name="connection_fields[<?= e($key) ?>]" value="<?= e($connectedSettings[$key]) ?>"><?php endif ?></label><?php endforeach ?>
<label>Facebook-Schlüssel<?= !empty($connection['stored']['facebook_page_access_token'])?' · vorhanden':'' ?><input class="form-control" type="password" name="connection_fields[facebook_page_access_token]" autocomplete="new-password"></label><p>Ein leeres Schlüsselfeld behält den vorhandenen Wert.</p>
<?php foreach(['facebook_auto_publish'=>'Facebook automatisch veröffentlichen','instagram_auto_publish'=>'Instagram automatisch veröffentlichen'] as $key=>$label): ?><label class="check"><input class="check" type="checkbox" name="connection_fields[<?= e($key) ?>]" value="1" <?= !empty($connectedSettings[$key])?'checked':'' ?>><?= e($label) ?></label><?php endforeach ?>
<p>Veranstaltungsbeiträge, Story-Motive und geplante Story-Erinnerungen verwenden die vorhandenen Kulturhus-Abläufe. Ihre Vorschauen und Veröffentlichungsstände findest du unter Inhalte.</p>
<?php endif ?><button class="btn btn-solid theme-primary">Verbindungseinstellungen speichern</button></form></section>
<?php if($editorialModule==='social'): ?><section class="card"><h2>Instagram-Verbindung</h2><form method="post"><?= csrf().hidden('action','website-native').hidden('operation','settings/refresh-instagram').hidden('return_tab','social').hidden('return_item','0').hidden('version',$connection['version']).hidden('request_key',bin2hex(random_bytes(16))) ?><button class="btn btn-solid theme-primary" <?= empty($connection['external_allowed'])?'disabled':'' ?>>Instagram-Verbindung aktualisieren</button></form></section><?php endif ?>
