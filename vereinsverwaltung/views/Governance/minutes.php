<?php declare(strict_types=1);
use Kulturhus\Verein\Minutes;
use Kulturhus\Verein\Web\{MinutesForm,MinutesPageState};
use Kulturhus\Native\Language\MinutesMessages;
$minutes=new Minutes($db,(string)$_SESSION['actor']);
if(!$minutes->canRead())throw new DomainException('Keine Berechtigung für Protokolle.',403);
$minutesText=static fn(string $source,array $values=[]):string=>MinutesMessages::text($source,$managerLanguageMessages??[],$values);
$minutesUrl=static fn(array $query=[]):string=>url(['view'=>'minutes']+$query);
$minutesStates=['published'=>'Für Mitglieder freigegeben','draft'=>'Entwurf','archived'=>'Archiviert'];
$minutesTransitionLabels=['publish'=>'Für Mitglieder freigeben','withdraw'=>'Freigabe zurückziehen','archive'=>'Archivieren','restore'=>'Als Entwurf wiederherstellen'];
$minutesConfirmLabels=['publish'=>'Ich habe das vollständige Protokoll geprüft und gebe es für die Mitglieder frei.','withdraw'=>'Ich ziehe die Freigabe zurück; Mitglieder können das Protokoll dann nicht mehr lesen.','archive'=>'Ich archiviere dieses Protokoll; es bleibt intern erhalten und wird für Mitglieder ausgeblendet.','restore'=>'Ich stelle dieses Protokoll als internen Entwurf wieder her.'];
$minutesUiFailure=null;
try{$minutesUi=MinutesPageState::read($minutes,$_GET,$_POST,$_FILES??[],(int)http_response_code());}catch(DomainException $minutesUiFailure){if(http_response_code()<400)http_response_code(MinutesPageState::status($minutesUiFailure));}
?>
<link rel="stylesheet" href="minutes.css?v=4"><script defer src="minutes.js?v=4"></script>
<div class="minutes-workspace" data-minutes-workspace>
<?php if($minutesUiFailure!==null):require __DIR__.'/Minutes/original.php';else:?>
<header class="minutes-heading"><div><p class="minutes-eyebrow"><?=e($minutesText('Vereinsleben'))?></p><h1><?=e($minutesText('Protokolle'))?></h1><p><?=e($minutesText('Beschlüsse und Besprechungen zum Nachlesen.'))?></p></div><?php if($minutesUi['write']&&!$minutesUi['editing']&&!$minutesUi['posted']&&!$minutesUi['lookup']):?><a class="btn btn-solid theme-primary button" href="<?=e($minutesUrl(['compose'=>'1']))?>"><?=e($minutesText('+ Protokoll anlegen'))?></a><?php endif?></header>
<?php if($minutesUi['posted']):?><details class="card" data-minutes-original-input><summary><?=e($minutesText('Protokollangaben'))?></summary><pre><?=e(MinutesPageState::originals($_GET,$_POST,$_FILES??[]))?></pre></details><?php endif?>
<?php if($minutesUi['editing']):require __DIR__.'/Minutes/compose.php';elseif(isset($minutesUi['own_transition'])):require __DIR__.'/Minutes/transition.php';elseif($minutesUi['lookup']&&$minutesUi['minute']===null):?>
<section class="card" data-minutes-creation-empty><h2><?=e($minutesText('Speicherstand dieses Anlagevorgangs'))?></h2><p><?=e($minutesText('Bei dieser Prüfung wurde kein gespeichertes Protokoll für den ursprünglichen Vorgang gefunden.'))?></p><p><?=e($minutesText('Der ursprüngliche Entwurf bleibt im anderen Fenster erhalten.'))?></p></section>
<?php elseif($minutesUi['minute']!==null):require __DIR__.'/Minutes/reader.php';else:require __DIR__.'/Minutes/list.php';endif?>
<?php endif?></div>
