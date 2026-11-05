<?php declare(strict_types=1);
$memberProfileViewMessages=$managerLanguageMessages??[];
$memberProfileText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$memberProfileViewMessages,$values);

$profileIsContact=$profile?empty($memberships):input($_POST,'create_as',input($_GET,'create_as','member'))==='contact';
$profileIsOwn=$profile&&$profile['id']===(\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['contact_id']??'');
$profileBack=($canWrite||($workspaceAccess??new \Kulturhus\Verein\WorkspaceAccess($db,(string)$_SESSION['actor']))->allows(['view'=>'members']))?['view'=>'members','list'=>$profileIsContact?'contacts':'current']:['view'=>'portal','tab'=>'own'];
if($profileBack['view']==='members'&&$profile&&!$profileIsContact&&($memberships[array_key_last($memberships)]['status']??'')==='ehemalig')$profileBack=['view'=>'members','list'=>'members','status'=>'ehemalig'];
if($profileBack['view']==='members'&&(isset($_GET['list'])||($filters['as_of']??'')!==''))$profileBack=['view'=>'members']+$filters+['page'=>input($_GET,'page','1')];
if($profileBack['view']==='members'){$peopleNavRoute=$profileBack;require __DIR__.'/people-navigation.php';}
$profileAction=input($_POST,'id')===(string)($profile['id']??'')?input($_POST,'action'):'';
$profileDraft=$profileAction==='save';
$profileLastMembership=$memberships?($memberships[array_key_last($memberships)]):null;
$profileMembershipSummary=$profileLastMembership?$memberProfileText('{status} · Mitgliedsnummer {number}',['status'=>$memberProfileText(ucfirst($profileLastMembership['status'])),'number'=>$profileLastMembership['member_number']]):$memberProfileText('Bisher nur als Kontakt erfasst');
$profileMembershipOpen=in_array($profileAction,['join','membership-start','leave','save-family','assign-family','annual-contribution'],true);
$profileCreateKey=($_POST['action']??null)==='save'?($_POST['create_request']??null):($_GET['create_request']??null);if(!$profile&&(!is_string($profileCreateKey)||!preg_match('/\A[a-f0-9]{32}\z/D',$profileCreateKey)))$profileCreateKey=bin2hex(random_bytes(16));
?>
<div class="member-profile">
<?php if(($profileCreationSaved??null)!==null||($profileCreationStateError??'')!==''): ?><div class="content member-profile-content"><?php require __DIR__.'/profile-original.php';require __DIR__.'/profile-creation-status.php'; ?></div></div><?php return;endif ?>
<?php if($canWrite&&($_POST['action']??null)==='save'&&\Kulturhus\Verein\Web\MemberProfileForm::blocked($_POST,$_GET,$profile['id']??'',\Kulturhus\Verein\MemberCustomFields::definition($db,\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['club_id']))): ?><div class="content member-profile-content"><?php require __DIR__.'/profile-original.php'; ?></div></div><?php return;endif ?>
<?php if(($filters['as_of']??'')!==''): ?><p class="notice" data-member-current-profile><?= e($memberProfileText('Geöffnet aus der Auswertung vom')) ?> <?= e(dateDe($filters['as_of'])) ?><?= e($memberProfileText('. Dieses Profil zeigt den aktuellen Stand. Der Zurück-Pfeil führt zur unveränderten Stichtagsliste.')) ?></p><?php endif ?>
<div class="page-heading">
 <a class="icon-button" href="<?= e(url($profileBack)) ?>" aria-label="<?= e($profileBack['view']==='members'?($profileIsContact?$memberProfileText('Zur Kontaktliste'):$memberProfileText('Zur Mitgliederliste')):$memberProfileText('Zur Profilübersicht')) ?>"><?= icon('arrow') ?></a>
 <div class="member-profile-title"><h1><?= e($profile?($profile['name']):($profileIsContact?$memberProfileText('Kontakt hinzufügen'):$memberProfileText('Mitglied hinzufügen'))) ?></h1><p><?= e($profile?($profileIsContact?$memberProfileText('Kontakt ohne Mitgliedschaft'):$profileMembershipSummary):$memberProfileText('Angaben eintragen und anschließend unten speichern.')) ?></p></div>
 <?php if($profile&&!$profileIsContact&&$workspaceAccess->allows(['view'=>'portal','tab'=>'card'])): ?><a class="btn btn-outline theme-secondary" data-member-card-link href="<?=e(url(['view'=>'portal','tab'=>'card','person'=>$profile['id']]))?>"><?=e($memberProfileText('Mitgliederausweis'))?></a><?php endif ?>
 <?php if($profile&&$canWrite):$isFavourite=in_array($profile['id'],$registry->favouriteIds(),true); ?><form method="post"><?= csrf().hidden('action','set-favourite').hidden('id',$profile['id']).hidden('enabled',$isFavourite?'0':'1') ?><button class="btn btn-outline theme-secondary icon-button favourite-button <?= $isFavourite?'is-favourite':'' ?>" aria-label="<?= e($isFavourite?$memberProfileText('Aus Favoriten entfernen'):$memberProfileText('Als Favorit merken')) ?>" aria-pressed="<?= $isFavourite?'true':'false' ?>"><?= icon('star') ?></button></form><?php endif ?>
</div>
<div class="content member-profile-content">
<?php require __DIR__.'/profile-original.php'; ?>
<?php if($canWrite): ?>
<form method="post" id="profile-form" class="member-profile-main-form"<?php if(!$profile&&($_GET['new']??null)==='1'&&!isset($_GET['id'])): ?> action="<?=e(url(array_replace(scalarFields($_GET,['view','new','create_as','list','q','status','kind','sort','direction','as_of','page']),['view'=>'members','new'=>'1','create_request'=>$profileCreateKey])))?>"<?php endif ?>>
 <?= csrf().hidden('action','save').hidden('id',$form['id']).hidden('version',$form['version']) ?>
 <?php if(!$profile)echo hidden('create_request',$profileCreateKey); ?>
 <?php if(!$profile): ?><section class="card member-profile-create"><label><?= e($memberProfileText('Anlegen als')) ?><select class="form-control" name="create_as"><option value="member"><?= e($memberProfileText('Mitglied mit Kontaktprofil')) ?></option><option value="contact" <?= $profileIsContact?'selected':'' ?>><?= e($memberProfileText('Nur Kontakt ohne Mitgliedschaft')) ?></option></select></label><p class="muted"><?= e($memberProfileText('Ein Mitglied erhält zusätzlich eine Mitgliedschaft. Bei einem Kontakt speichern Sie nur die Kontaktdaten.')) ?></p></section><?php endif ?>
 <section class="card member-profile-basics" data-member-basics>
  <h2><?= e($profile?$memberProfileText('Persönliche Angaben ändern'):$memberProfileText('Persönliche Angaben')) ?></h2>
  <div class="form-grid">
   <label><?= e($memberProfileText('Name')) ?> <span class="required">*</span><input class="form-control" name="name" required maxlength="200" value="<?= e($form['name']) ?>" autocomplete="off"></label>
   <label><?= e($memberProfileText('Profilart')) ?><select class="form-control" name="kind"><option value="person"><?= e($memberProfileText('Person')) ?></option><option value="organisation" <?= $form['kind']==='organisation'?'selected':'' ?>><?= e($memberProfileText('Firma')) ?></option></select></label>
   <label><?= e($memberProfileText('E-Mail')) ?><input class="form-control" name="email" type="email" maxlength="254" value="<?= e($form['email']) ?>" autocomplete="off"></label>
   <label><?= e($memberProfileText('Telefon')) ?><input class="form-control" name="phone" type="tel" maxlength="80" value="<?= e($form['phone']) ?>"></label>
   <label class="wide"><?= e($memberProfileText('Anschrift')) ?><textarea class="form-control" name="address" maxlength="1000" rows="3"><?= e($form['address']) ?></textarea></label>
  </div>
  <p class="muted"><?= e($memberProfileText('Die E-Mail-Adresse darf auch von einer Familie gemeinsam verwendet werden. Eine Newsletter-Anmeldung erfolgt dadurch nicht.')) ?></p>
 </section>
 <?php
 ob_start();require __DIR__.'/member-custom-fields.php';$profileCustomFields=ob_get_clean();
 $profileCustomMissing=false;
 foreach($customDefinition['rows']??[] as $profileCustomRow){$profileCustomId=$profileCustomRow['id'];$profileCustomValue=$customPosted?($customDraft[$profileCustomId]??''):($customValues[$profileCustomId]['value']??'');if(!empty($profileCustomRow['required'])&&$profileCustomValue==='')$profileCustomMissing=true;}
 ?>
 <details class="member-profile-panel" data-member-panel="extra" <?= $profileDraft||$profileCustomMissing?'open':'' ?>>
  <summary><span><strong><?= e($memberProfileText('Weitere persönliche Angaben')) ?></strong><small><?= e($memberProfileText('Geburtstag, alte Mitgliedsnummer, Ehrenmitglied und Notizen')) ?></small></span></summary>
  <div class="member-profile-panel-body">
   <div class="form-grid">
    <label><?= e($memberProfileText('Geburtstag')) ?><input class="form-control" type="date" name="birthday" max="<?= date('Y-m-d') ?>" value="<?= e($form['birthday']) ?>"></label>
    <label><?= e($memberProfileText('Alte Mitgliedsnummer (optional)')) ?><input class="form-control" name="legacy_member_number" maxlength="80" value="<?= e($form['legacy_member_number']??'') ?>"></label>
   </div>
   <p class="muted"><?= e($memberProfileText('Die alte Nummer hilft, frühere Zahlungen zuzuordnen. Die aktuelle Mitgliedsnummer bleibt erhalten.')) ?></p>
   <label class="check"><input class="check" type="checkbox" name="honorary" value="1" <?= $form['honorary']?'checked':'' ?>><?= e($memberProfileText('Ehrenmitglied')) ?></label>
   <label for="profile-notes"><?= e($memberProfileText('Notizen')) ?><textarea class="form-control" id="profile-notes" name="notes" rows="4" maxlength="10000"><?= e($form['notes']) ?></textarea></label>
   <?= $profileCustomFields ?>
  </div>
 </details>
 <?php if(!$profile): ?><section class="card" data-new-membership><h2><?= e($memberProfileText('Mitgliedschaft')) ?> & <?= e($memberProfileText('Familie')) ?></h2><p class="muted"><?= e($memberProfileText('Diese Angaben werden gemeinsam mit dem neuen Mitglied gespeichert.')) ?></p><?php require __DIR__.'/membership-fields.php'; ?></section><?php endif ?>
 <div class="member-profile-save"><button class="btn btn-solid theme-primary" form="profile-form"><?= e($profile?$memberProfileText('Persönliche Angaben speichern'):($profileIsContact?$memberProfileText('Kontakt anlegen'):$memberProfileText('Mitglied anlegen'))) ?></button><p><?= e($memberProfileText('Speichert')) ?> <?= e($profile?$memberProfileText('die persönlichen Angaben oben.'):$memberProfileText('das neue Profil mit den eingetragenen Angaben.')) ?></p></div>
</form>
<?php elseif($profile): ?>
 <section class="card"><h2><?= e($profileIsOwn?$memberProfileText('Mein Profil'):$memberProfileText('Profilangaben')) ?></h2><dl class="data-list"><dt><?= e($memberProfileText('Name')) ?></dt><dd><?= e($profile['name']) ?></dd><dt><?= e($memberProfileText('Alte Mitgliedsnummer')) ?></dt><dd><?= e($profile['legacy_member_number']??'') ?></dd><dt><?= e($memberProfileText('E-Mail')) ?></dt><dd><?= e($profile['email']) ?></dd><dt><?= e($memberProfileText('Telefon')) ?></dt><dd><?= e($profile['phone']) ?></dd><dt><?= e($memberProfileText('Geburtstag')) ?></dt><dd><?= e(dateDe($profile['birthday'])) ?></dd><dt><?= e($memberProfileText('Anschrift')) ?></dt><dd><?= e($profile['address']) ?></dd></dl><p class="muted"><?= e($profileIsOwn?$memberProfileText('Name, Kontakt-E-Mail, Telefon und Anschrift kannst du dem Vorstand als Änderungsantrag senden.'):$memberProfileText('Du hast hier Leserechte. Änderungen übernimmt die Mitgliederverwaltung.')) ?></p></section>
 <?php if($profileIsOwn&&(new \Kulturhus\Verein\MemberChangeRequests($db,(string)$_SESSION['actor']))->canSubmit()): ?><p><a class="btn btn-solid theme-primary button" href="?view=portal&amp;tab=changes"><?= e($memberProfileText('Meine Angaben ändern')) ?></a></p><?php endif ?>
 <?php require __DIR__.'/member-custom-fields.php'; ?>
<?php endif ?>

<?php if($profile): ?>
 <details class="member-profile-panel" data-member-panel="membership" <?= $profileMembershipOpen?'open':'' ?>>
  <summary><span><strong><?= e($memberProfileText('Mitgliedschaft')) ?> & <?= e($memberProfileText('Beitrag')) ?></strong><small><?= e($profileMembershipSummary) ?></small></span></summary>
  <div class="member-profile-panel-body">
   <?php if(!$memberships): ?><p><?= e($memberProfileText('Externer Kontakt · bisher keine Mitgliedschaft.')) ?></p><?php endif ?>
   <?php foreach($memberships as $m): ?>
    <article class="membership"><div><strong><?= e($memberProfileText('Mitgliedsnummer')) ?> <?= e($m['member_number']) ?></strong> <span class="status <?= e($m['status']) ?>"><?= e($memberProfileText(ucfirst($m['status']))) ?></span><p><?= e($memberProfileText('Eintritt:')) ?> <?= e(($m['starts_precision']??'day')==='year'?($memberProfileText('{year} (genauer Tag unbekannt)',['year'=>substr($m['starts_on'],0,4)])):(dateDe($m['starts_on']))) ?><?= e($m['ends_on']?($memberProfileText(' · Austritt: {date}',['date'=>dateDe($m['ends_on'])])):$memberProfileText(' · fortlaufende Mitgliedschaft')) ?></p></div><?php if($canWrite)require __DIR__.'/membership-start.php'; ?><?php if($canWrite&&!$m['ends_on'])require __DIR__.'/membership-end.php'; ?></article>
   <?php endforeach ?>
   <?php if($canWrite): ?><details class="member-profile-inline" <?= $profileAction==='join'?'open':'' ?>><summary><?= e($memberships?$memberProfileText('Weitere Mitgliedschaft hinzufügen'):$memberProfileText('Als Mitglied übernehmen')) ?></summary><form method="post"><?= csrf().hidden('action','join').hidden('id',$profile['id']) ?><?php require __DIR__.'/membership-fields.php'; ?><button class="btn btn-solid theme-primary"><?= e($memberProfileText('Mitgliedschaft anlegen')) ?></button></form></details><?php endif ?>
   <?php if($canWrite&&$profile['kind']==='person')require __DIR__.'/member-family.php'; ?>
  </div>
 </details>
 <?php
 ob_start();require __DIR__.'/profile-supplier-bank.php';require __DIR__.'/profile-member-bank.php';$profileBankPanel=trim(ob_get_clean());
 if($profileBankPanel!==''):
 ?>
 <details class="member-profile-panel" data-member-panel="bank" <?= in_array($profileAction,['supplier-bank-save','supplier-bank-remove'],true)?'open':'' ?>>
  <summary><span><strong><?= e($profile['kind']==='organisation'?$memberProfileText('Bankverbindung des Lieferanten'):$memberProfileText('Bankverbindung & SEPA')) ?></strong><small><?= e($profile['kind']==='organisation'?$memberProfileText('Bankdaten ansehen oder ändern'):$memberProfileText('Geschützte Bankdaten und Lastschriftmandat')) ?></small></span></summary>
  <div class="member-profile-panel-body"><?= $profileBankPanel ?></div>
 </details>
 <?php endif ?>
 <?php ob_start();require __DIR__.'/../../Inventory/inventory-profile.php';$profileInventoryPanel=trim(ob_get_clean());if($profileInventoryPanel!==''): ?>
 <details class="member-profile-panel" data-member-panel="inventory">
  <summary><span><strong><?= e($memberProfileText('Inventar')) ?> & <?= e($memberProfileText('Ausleihen')) ?></strong><small><?= e($memberProfileText('Aufbewahrte Gegenstände und offene Ausleihen')) ?></small></span></summary>
  <div class="member-profile-panel-body"><?= $profileInventoryPanel ?></div>
 </details>
 <?php endif ?>
 <details class="member-profile-panel" data-member-panel="access" <?= $profileAction==='manual-account'?'open':'' ?>>
  <summary><span><strong><?= e($memberProfileText('Website-Zugang')) ?> & <?= e($memberProfileText('Newsletter')) ?></strong><small><?= e($memberProfileText('Konto, Zugangsmail und Verteilerstatus')) ?></small></span></summary>
  <div class="member-profile-panel-body"><?php require __DIR__.'/../../Help/manual-account-status.php';require __DIR__.'/../../Website/website-contact-status.php'; ?></div>
 </details>
 <?php ob_start();documentPanel($documents,'contact',$profile['id']);$profileDocumentPanel=trim(ob_get_clean());if($profileDocumentPanel!==''): ?>
 <details class="member-profile-panel" data-member-panel="documents">
  <summary><span><strong><?= e($memberProfileText('Dokumente')) ?> & <?= e($memberProfileText('Nachweise')) ?></strong><small><?= e($memberProfileText('Dateien dieses Profils ansehen oder hinzufügen')) ?></small></span></summary>
  <div class="member-profile-panel-body"><?= $profileDocumentPanel ?></div>
 </details>
 <?php endif ?>
<?php endif ?>

<?php if($profile&&$canWrite):$profileArchiveDraft=$profileAction==='delete-contact'; ?>
 <details class="member-profile-panel member-profile-archive" data-member-panel="archive" <?= $profileArchiveDraft?'open':'' ?>>
  <summary><span><strong><?= e($memberProfileText($memberships?'Mitglied archivieren':'Kontakt archivieren')) ?></strong><small><?= e($memberProfileText('Profil aus den laufenden Listen entfernen')) ?></small></span></summary>
  <div class="member-profile-panel-body"><p><?= e($memberProfileText('Das Profil verschwindet aus den normalen Listen. Seine persönlichen Stammdaten werden verschlüsselt im Archiv gespeichert. Mitgliedsnummern, Mitgliedschaftshistorie, Zahlungszuordnungen und bereits ausgestellte Belege bleiben erhalten. Verknüpfte Zugänge werden gesperrt. Historische Rechnungen und bereits versandte Nachrichten werden dabei nicht verändert.')) ?></p><form method="post" data-confirm="<?= e($memberProfileText('Dieses Profil verschlüsselt archivieren?')) ?>"><?= csrf().hidden('action','delete-contact').hidden('id',$profile['id']).hidden('version',$profileArchiveDraft?input($_POST,'version'):$profile['version']).hidden('return_list',$memberships?'current':'contacts') ?><label><?= e($memberProfileText('Vollständigen Namen zur Bestätigung eingeben')) ?><input class="form-control" name="confirm_name" required maxlength="200" autocomplete="off" value="<?= e($profileArchiveDraft?input($_POST,'confirm_name'):'') ?>"></label><label><?= e($memberProfileText('Archivierungsgrund')) ?><input class="form-control" name="delete_reason" required maxlength="500" value="<?= e($profileArchiveDraft?input($_POST,'delete_reason'):'') ?>"></label><label><?= e($memberProfileText('Bei laufender Mitgliedschaft: tatsächlicher Austritt am')) ?><input class="form-control" name="archive_end" type="date" max="<?= date('Y') ?>-12-31" value="<?= e($profileArchiveDraft?input($_POST,'archive_end'):'') ?>"></label><button class="btn btn-outline theme-danger danger"><?= e($memberProfileText('Verschlüsselt archivieren')) ?></button></form></div>
 </details>
<?php endif ?>
</div>
</div>
