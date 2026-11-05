<?php declare(strict_types=1);
if($view==='documents'&&$tab==='inspections'){
    if(!$workflowManager||!\Kulturhus\Verein\DocumentWorkflow\InspectionStore::installed($db)){http_response_code(403);echo '<p>Gefährdungsprüfungen nicht verfügbar.</p>';return;}
    ob_start();try{require __DIR__.'/../Documents/inspections.php';}catch(DomainException $ex){ob_clean();http_response_code(403);echo '<div class="content"><p class="error">'.e($ex->getMessage()).'</p></div>';}echo ob_get_clean();return;
}
if(($view==='documents'&&$tab==='workflows')||($view==='portal'&&$tab==='signatures')){
    if(!$workflowManager){http_response_code(403);echo '<p>Gefährdungsbeurteilungen nicht verfügbar.</p>';return;}
    $workflowError=$error;$workflowNotice='';
    ob_start();try{require __DIR__.'/../Documents/document-workflows.php';}catch(DomainException $ex){ob_clean();http_response_code(403);echo '<div class="content"><p class="error">'.e($ex->getMessage()).'</p></div>';}echo ob_get_clean();return;
}
if($view==='portal'&&$tab==='changes'){require __DIR__.'/../People/Members/member-change-requests.php';return;}
if($view==='portal'&&$tab==='vouchers'){ob_start();try{require __DIR__.'/../Events/event-vouchers.php';}catch(DomainException $ex){ob_clean();echo '<div class="content"><p class="error">'.e($ex->getMessage()).'</p></div>';}echo ob_get_clean();return;}
if($view==='portal'&&$tab==='card'){require __DIR__.'/../People/Members/member-card.php';return;}
if($view==='settings'&&$tab==='member-cards'){require __DIR__.'/../Settings/settings-member-cards.php';return;}
if($view==='portal'&&$tab==='bank'){echo '<div class="page-heading"><h1>'.e($bankText('Bankverbindung & SEPA')).'</h1></div><div class="content">';require __DIR__.'/../People/Members/member-bank.php';echo '</div>';return;}
if($view==='settings'&&$tab==='status'){require __DIR__.'/../Settings/settings-status.php';return;}
if($view==='settings'&&$tab==='cache'){require __DIR__.'/../Settings/settings-cache.php';return;}
if($view==='settings'&&$tab==='extensions'){require __DIR__.'/../Settings/settings-extensions.php';return;}
if($view==='settings'&&$tab==='api'){require __DIR__.'/../Settings/settings-api.php';return;}
if($view==='settings'&&$tab==='system'){require __DIR__.'/../Settings/settings-system.php';return;}
if($view==='settings'&&$tab==='languages'){require __DIR__.'/../Settings/settings-languages.php';return;}
if($view==='settings'&&$tab==='sepa'){require __DIR__.'/../People/Members/member-bank-settings.php';return;}
if($view==='portal'&&in_array($tab,['application','admissions'],true)&&!$canAdmissions){http_response_code(403);echo '<div class="content"><p>Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.</p></div>';return;}
if($view==='portal'&&$tab==='documents'){require __DIR__.'/../Documents/portal-documents.php';return;}
if($view==='portal'&&$tab==='safety'){require __DIR__.'/../Documents/safety-guide.php';return;}
if($view==='portal'&&$tab==='admissions'){ob_start();try{require __DIR__.'/../People/Members/admission-votes.php';}catch(DomainException $ex){ob_clean();echo '<div class="content"><p>'.e($ex->getMessage()).'</p></div>';}echo ob_get_clean();return;}
if($view==='gema'){ob_start();try{require __DIR__.'/../Events/gema-overview.php';}catch(DomainException $ex){ob_clean();echo '<section class="error" role="alert" tabindex="-1"><p>'.e($gemaText($ex->getMessage())).'</p><a class="btn btn-outline theme-secondary button secondary" href="?view=gema">'.e($gemaText('Zur GEMA-Übersicht')).'</a></section>';}echo ob_get_clean();return;}
if($view==='chat'){require __DIR__.'/../Communication/Chat/chat.php';return;}
if($view==='messages'&&$tab==='inbox'){ob_start();try{echo(new \Kulturhus\Verein\Communication\MailboxPage($db,(string)$_SESSION['actor'],(string)$_SESSION['csrf'],messages:$managerLanguageMessages??[]))->render($_GET,$_POST);}catch(DomainException $ex){ob_clean();if(http_response_code()<400)http_response_code(403);echo '<section class="content"><div class="error" role="alert">'.e(\Kulturhus\Native\Language\MailboxMessages::notice($ex->getMessage(),$managerLanguageMessages??[])).'</div></section>';}echo ob_get_clean();return;}
if($view==='messages'){require __DIR__.(\Kulturhus\Verein\BoardAccess::allowed($db,(string)$_SESSION['actor'])&&$tab!=='letter'?'/../Communication/mailings.php':'/../Communication/letter.php');return;}
if($view==='free'){ob_start();try{require __DIR__.'/../Finance/Contributions/free-board.php';}catch(DomainException $ex){ob_clean();http_response_code(403);echo '<section class="content"><div class="error" role="alert">'.e(\Kulturhus\Native\Language\FreeListMessages::failure($ex,$managerText??static fn(string $v):string=>$v)).'</div></section>';}echo ob_get_clean();return;}
if($view==='material'){require __DIR__.'/../Inventory/material.php';return;}
if($view==='minutes'){ob_start();try{require __DIR__.'/../Governance/minutes.php';}catch(DomainException $ex){ob_clean();http_response_code(403);echo '<section class="content"><div class="error" role="alert">'.e($ex->getMessage()).'</div></section>';}echo ob_get_clean();return;}

if(in_array($view,['social','newsletter','press'],true)){ob_start();try{require __DIR__.'/../Communication/editorial-module.php';}catch(DomainException $ex){ob_clean();echo '<section class="content"><p class="error">'.e($ex->getMessage()).'</p></section>';}echo ob_get_clean();return;}
if($view==='website'){ob_start();try{require __DIR__.'/../Website/website.php';}catch(DomainException $ex){ob_clean();echo '<section class="content"><div class="error" role="alert">'.e($ex->getMessage()).'</div></section>';}echo ob_get_clean();return;}
if($view==='documents'){ob_start();try{require __DIR__.'/../Documents/documents.php';}catch(DomainException $ex){ob_clean();if(http_response_code()<400)http_response_code(in_array($ex->getMessage(),['Dokument nicht verfügbar.','Ordner nicht verfügbar.','Keine ausreichende Berechtigung für dieses Dokument oder seine Zuordnungen.','Keine Berechtigung für Vereinsdokumente.','Diese Dokumentansicht ist nicht freigegeben.','Diese Dokumentansicht ist nicht verfügbar.','Diese HSE-Dokumentansicht ist nicht freigegeben.'],true)?403:422);echo '<section class="content"><div class="error" role="alert">'.e(\Kulturhus\Verein\Documents\InterfaceText::text($ex->getMessage())).'</div><a class="btn btn-outline theme-secondary" href="?view=documents">'.e(\Kulturhus\Verein\Documents\InterfaceText::text('← Zurück zur Dokumentliste')).'</a></section>';}echo ob_get_clean();return;}
if($view==='inventory'){ob_start();try{require __DIR__.'/../Inventory/inventory.php';}catch(DomainException $ex){ob_clean();echo '<section class="content"><div class="error" role="alert">'.e($ex->getMessage()).'</div></section>';}echo ob_get_clean();return;}
if($view==='settings'&&$tab==='donation-forms'){ob_start();try{require __DIR__.'/../Finance/Donations/donation-forms.php';}catch(DomainException $ex){ob_clean();echo '<section class="content"><div class="error" role="alert">'.e($ex->getMessage()).'</div></section>';}echo ob_get_clean();return;}
if($view==='portal'&&$tab==='application'){require __DIR__.'/../People/Members/application-admin.php';return;}
if($view==='settings'&&$tab==='booking-keys'){require __DIR__.'/../Finance/Accounting/booking-keys.php';return;}
if($view==='settings'&&$tab==='tax-accounts'){require __DIR__.'/../Finance/Tax/tax-accounts.php';return;}
if($view==='settings'&&in_array($tab,['','account','roles','numbers','rates','services','texts','paper'],true)){ob_start();try{require __DIR__.'/../Settings/settings.php';}catch(DomainException $ex){ob_clean();echo '<section class="content"><div class="error" role="alert">'.e($ex->getMessage()).'</div></section>';}echo ob_get_clean();return;}
if($canRead&&(($view==='contacts-extra'&&$tab==='favourites')||($view==='members-extra'&&$tab==='anniversaries'))){require __DIR__.'/../Governance/occasions.php';return;}
if($view==='settings'&&$tab==='progress'){require __DIR__.'/progress.php';return;}
if($view==='portal'&&$tab==='own'):$ownProfileEntryText=static fn(string $source):string=>\Kulturhus\Native\Language\MemberProfileMessages::text($source,$managerLanguageMessages??[]);$ownActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);$ownContact=$ownActor['contact_id']?$registry->contact($ownActor['contact_id']):null; ?><div class="page-heading"><h1><?= e($ownProfileEntryText('Mein Profil')) ?></h1></div><div class="content"><section class="card"><?php if($ownContact): ?><h2><?= e($ownContact['name']) ?></h2><p><?= e($ownProfileEntryText('Deine persönlichen Stammdaten und Mitgliedschaft.')) ?></p><a class="btn btn-solid theme-primary button" href="<?= e(url(['view'=>'members','id'=>$ownContact['id']])) ?>"><?= e($ownProfileEntryText('Mein Profil öffnen')) ?></a><?php if((new \Kulturhus\Verein\MemberChangeRequests($db,(string)$_SESSION['actor']))->canSubmit()): ?> <a class="btn btn-outline theme-secondary button secondary" href="?view=portal&amp;tab=changes"><?= e($ownProfileEntryText('Meine Angaben ändern')) ?></a><?php endif ?><?php else: ?><p><?= e($ownProfileEntryText('Diesem Zugang ist noch kein eigenes Mitgliederprofil ausdrücklich zugeordnet.')) ?></p><?php endif ?></section></div><?php return;endif;
$title=$currentItem[0]??$views[$view];
?>
<div class="page-heading"><h1><?= e($title) ?></h1></div>
<?php if($currentGroup): ?><nav class="module-tabs" aria-label="<?= e($currentGroup[0]) ?> Untermenüs"><?php foreach($currentGroup[2] as [$label,$params]):if(!$workspaceAccess->allows($params))continue; ?><a <?= $matches($params)?'aria-current="page"':'' ?> href="<?= e(url($params)) ?>"><?= e($label) ?></a><?php endforeach ?></nav><?php endif ?>
<div class="content">
<section class="card module-empty"><span class="module-icon"><?= icon($currentGroup[1]??'settings') ?></span><h2><?= e($title) ?></h2><p>Diese Ansicht ist nicht verfügbar.</p><a href="?view=dashboard">Zur Startseite</a></section></div>
