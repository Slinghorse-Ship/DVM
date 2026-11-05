<?php declare(strict_types=1);
use Kulturhus\Verein\MemberChangeRequests;
use Kulturhus\Verein\Web\{MemberChangePageState,OriginalInput};
use Kulturhus\Native\Language\MemberChangeMessages;
$changeService=new MemberChangeRequests($db,(string)$_SESSION['actor']);$reviewChanges=$view==='members';$changeBase=['view'=>$reviewChanges?'members':'portal','tab'=>'changes'];
if(!($reviewChanges?$changeService->canReview():$changeService->canSubmit()))throw new DomainException($reviewChanges?'Keine Berechtigung zur Prüfung der Mitgliederänderungen.':'Keine Berechtigung für eigene Mitgliederänderungen.',403);
$changeText=static fn(string $source,array $values=[]):string=>MemberChangeMessages::text($source,$managerLanguageMessages??[],$values);
$changeUrl=static fn(array $query=[]):string=>url($changeBase+$query);$changeUiFailure=null;
try{$changeUi=MemberChangePageState::read($changeService,$_GET,$_POST,(int)http_response_code());}catch(DomainException $changeUiFailure){if(http_response_code()<400)http_response_code(MemberChangePageState::status($changeUiFailure));}
?>
<link rel="stylesheet" href="member-changes.css?v=2">
<div class="content member-changes" data-member-changes>
<?php if($changeUiFailure!==null):require __DIR__.'/Changes/original.php';else:?>
<header class="page-heading"><div><h1><?=e($changeText($reviewChanges?'Mitgliederänderungen prüfen':'Meine Angaben ändern'))?></h1><p><?=e($changeText($reviewChanges?'Vorgeschlagene Änderungen vergleichen und bewusst übernehmen.':'Name, Kontakt-E-Mail, Telefon und Anschrift auf dem aktuellen Stand halten.'))?></p></div><a class="btn btn-outline theme-secondary" href="<?=e(url($reviewChanges?['view'=>'members','list'=>'current']:['view'=>'portal','tab'=>'own']))?>"><?=e($changeText($reviewChanges?'Zur Mitgliederliste':'Zu meinem Profil'))?></a></header>
<?php if($reviewChanges):require __DIR__.'/people-navigation.php';endif?>
<?php if($changeUi['posted']):?><?php endif?>
<?php if($changeUi['operation']!==null):require __DIR__.'/Changes/operation.php';elseif($changeUi['lookup']&&$changeUi['selected']===null):?>
<section class="card" data-member-change-submission-empty><h2><?=e($changeText('Speicherstand dieses Einreichungsvorgangs'))?></h2><p><?=e($changeText('Bei dieser Prüfung wurde kein gespeicherter Antrag für den ursprünglichen Vorgang gefunden.'))?></p><p><?=e($changeText('Die ursprünglichen Angaben bleiben im anderen Fenster erhalten.'))?></p></section>
<?php elseif($changeUi['selected']!==null):require __DIR__.'/Changes/reader.php';elseif($changeUi['editing']):require __DIR__.'/Changes/compose.php';elseif(!$reviewChanges&&$changeUi['pending']!==[]):?>
<section class="card"><h2><?=e($changeText('Dein Antrag wird geprüft'))?></h2><p><?=e($changeText('Es gibt bereits eine offene Änderung. Öffne zuerst die ursprünglichen Anträge und prüfe ihren Stand.'))?></p><a class="btn btn-outline theme-secondary" href="<?=e($changeUrl(['state'=>'pending']))?>"><?=e($changeText('Offene Anträge ansehen'))?></a></section>
<?php elseif(!$reviewChanges):?><a class="btn btn-outline theme-secondary" href="<?=e($changeUrl(['compose'=>'1']))?>"><?=e($changeText('Neue Änderung vorschlagen'))?></a><?php endif?>
<?php if(!$changeUi['posted']&&!$changeUi['lookup']&&$changeUi['selected']===null&&!isset($_GET['compose'])):require __DIR__.'/Changes/list.php';endif?>
<?php endif?></div>
