<?php declare(strict_types=1);
use Kulturhus\Verein\ActivityReportReview;
use Kulturhus\Verein\Web\{ActivityReportForm,ActivityReportPageState,ActivityReportPrepareTicket};
use Kulturhus\Native\Language\ActivityReportMessages;
$reportText=static fn(string $text,array $values=[]):string=>ActivityReportMessages::text($text,$managerLanguageMessages??[],$values);$reportFailure=null;$reportQuery=null;$reportAllowedKinds=[];$reportActor=(string)$_SESSION['actor'];
try{$reportQuery=ActivityReportForm::query($_GET);}catch(DomainException $reportFailure){}
$reportRawKind=$_GET['report_kind']??null;$reportAuthKinds=array_key_exists('report_kind',$_GET)?(in_array($reportRawKind,['tax','assembly'],true)?[$reportRawKind]:['tax','assembly']):['tax'];
foreach($reportAuthKinds as$reportAuthKind)if((new ActivityReportReview($db,$reportActor,null,$reportAuthKind))->canRead())$reportAllowedKinds[]=$reportAuthKind;
if($reportAllowedKinds===[])throw new DomainException('Keine Berechtigung für diesen Tätigkeitsbericht.',403);
$reportKind=$reportQuery['report_kind']??(in_array($reportRawKind,['tax','assembly'],true)?$reportRawKind:null);$reportBase=['view'=>'documents','tab'=>'activity-reports']+($reportKind!==null?['report_kind'=>$reportKind]:[]);$reportUrl=static fn(array $query=[]):string=>url($reportBase+$query);
if($reportFailure===null){$reports=new ActivityReportReview($db,$reportActor,null,$reportKind);try{$reportUi=ActivityReportPageState::read($reports,$_GET,$_POST,(int)http_response_code());if($reportUi['prepare_values']!==null&&!$reportUi['posted'])$reportUi['prepare_values']['prepare_token']=ActivityReportPrepareTicket::issue($db,$reportActor,$reportKind,$_SESSION);}catch(DomainException $reportFailure){}}
if($reportFailure!==null&&ActivityReportPageState::status($reportFailure)===403)throw $reportFailure;
if($reportFailure!==null&&http_response_code()<400)http_response_code(ActivityReportPageState::status($reportFailure));
?>
<link rel="stylesheet" href="activity-reports.css?v=1"><div class="content activity-reports" data-activity-reports>
<?php if($reportFailure!==null):require __DIR__.'/Reports/original.php';else:$reportTypePeriod=$reportUi['selected']!==null?['report_from'=>(string)$reportUi['selected']['from_year'],'report_to'=>(string)$reportUi['selected']['to_year']]:array_intersect_key($reportUi['source'],array_flip(['report_from','report_to']));?>
<header class="page-heading"><div><h1><?=e($reportText('Tätigkeitsberichte'))?></h1><p><?=e($reportText(ActivityReportReview::KINDS[$reportKind]))?></p></div><a class="btn btn-outline theme-secondary" href="<?=e(url(['view'=>'documents']))?>"><?=e($reportText('Zur Dokumentenübersicht'))?></a></header>
<nav class="module-tabs" aria-label="<?=e($reportText('Berichtsart'))?>"><?php foreach(ActivityReportReview::KINDS as$kind=>$label):if(!(new ActivityReportReview($db,$reportActor,null,$kind))->canRead())continue;?><a href="<?=e(url(['view'=>'documents','tab'=>'activity-reports','report_kind'=>$kind]+$reportTypePeriod))?>" <?=$kind===$reportKind?'aria-current="page"':''?>><?=e($reportText($kind==='tax'?'Finanzamt':'Mitgliederversammlung'))?></a><?php endforeach?></nav>
<p><?=e($reportText($reportKind==='tax'?'Tatsächliche Tätigkeiten und Verwirklichung der Satzungszwecke im angeforderten Zeitraum.':'Rückblick, Vereinsentwicklung, Dank und Ausblick. Der Kassenbericht bleibt getrennt.'))?></p>
<?php if(!$reportUi['chair']||$reportUi['chair']['name']===''):?><p role="alert"><?=e($reportText('Bitte unter „Rollen & Rechte“ genau einen aktiven 1. Vorstand mit persönlichem Namen zuordnen. Erst danach ist die Freigabe möglich.'))?></p><?php if((new \Kulturhus\Verein\WorkspaceAccess($db,$reportActor))->allows(['view'=>'settings','tab'=>'roles'])):?><p><a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=roles#role-accounts"><?=e($reportText('Vorstand zuordnen'))?></a></p><?php endif?><?php else:?><p><?=e($reportText('Prüfung und Freigabe'))?>: <?=e($reportUi['chair']['name'])?> · <?=e($reportText('1. Vorstand'))?></p><?php endif?>
<?php if($reportUi['posted']):?><?php endif?>
<?php if($reportUi['lookup']):?><section class="card" data-activity-report-period-result><h2><?=e($reportText('Gespeicherter Bericht für diesen Zeitraum'))?></h2><?php if($reportUi['selected']===null):?><p><?=e($reportText('Für diesen Zeitraum wurde bei dieser Prüfung kein gespeicherter Bericht gefunden.'))?></p><?php else:?><p><?=e($reportText('Öffne den gespeicherten Bericht und prüfe den aktuellen Stand.'))?></p><a class="btn btn-outline theme-secondary" href="<?=e($reportUrl(['report_id'=>$reportUi['selected']['id']]))?>"><?=e($reportText('Gespeicherten Bericht öffnen'))?></a><?php endif?></section>
<?php elseif($reportUi['operation']!==null):require __DIR__.'/Reports/operation.php';
elseif($reportUi['posted']&&$reportUi['edit_values']!==null):require __DIR__.'/Reports/edit.php';
elseif($reportUi['posted']&&$reportUi['prepare_values']!==null):require __DIR__.'/Reports/prepare.php';
elseif($reportUi['selected']!==null):if(isset($reportUi['source']['edit'])):require __DIR__.'/Reports/edit.php';else:require __DIR__.'/Reports/reader.php';endif;
else:if($reportUi['prepare_values']!==null):require __DIR__.'/Reports/prepare.php';elseif($reportUi['can_prepare']):?><a class="btn btn-outline theme-secondary" href="<?=e($reportUrl())?>"><?=e($reportText('Neuen Zeitraum vorbereiten'))?></a><?php endif;require __DIR__.'/Reports/list.php';endif?>
<?php endif?></div>
