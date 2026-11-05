<?php declare(strict_types=1);
// The front controller already checked authentication, POST and current CSRF.
use Kulturhus\Verein\{ActivityReportReview,Permissions};
use Kulturhus\Verein\Web\{ActivityReportForm,ActivityReportPrepareTicket};
$reportActor=(string)$_SESSION['actor'];$reportKind=ActivityReportForm::kind($_POST['report_kind']??null);$reports=new ActivityReportReview($db,$reportActor,null,$reportKind);$reportRawAction=$_POST['action']??null;
if(!$reports->canRead()||($reportRawAction==='activity-report-prepare'&&!$reports->canPrepare())||(in_array($reportRawAction,['activity-report-edit','activity-report-regenerate','activity-report-approve'],true)&&!$reports->isChair())||($reportRawAction==='activity-report-preview'&&($_POST['with_signature']??null)==='1'&&!$reports->isChair()))throw new DomainException('Für diese Berichtsaktion fehlt die aktuelle Berechtigung.',403);
$reportCommand=ActivityReportForm::command($_GET,$_POST);if(($_FILES??[])!==[])throw new DomainException('Berichtsangaben ohne Dateianhang prüfen.',422);if($reportCommand['retry_original']&&!$reportCommand['retry_confirmed'])throw new DomainException('Ursprüngliche Berichtsaktion ausdrücklich bestätigen.',422);
$reportId=$reportCommand['id']??'';
if($reportCommand['action']==='activity-report-prepare'){
 $reportOriginal=ActivityReportPrepareTicket::expected($db,$reportActor,$reportKind,$reportCommand['prepare_token'],$reportCommand['from'],$reportCommand['to'],$_SESSION);$reportId=$reports->prepare($reportCommand['from'],$reportCommand['to'],$reportCommand['supplement'],$reportOriginal);$_SESSION['notice']='Bericht liegt dem 1. Vorstand zur Prüfung vor.';
}else{
 $reportCurrent=$reports->get($reportId);if((int)$reportCurrent['version']!==$reportCommand['version'])throw new DomainException('Der Bericht wurde inzwischen geändert. Bitte neu öffnen.',409);
 $reportFacts=$reports->source((int)$reportCurrent['from_year'],(int)$reportCurrent['to_year'],$reportCurrent['supplement']);if(!hash_equals($reportFacts['hash'],$reportCommand['source_hash']))throw new DomainException('Die Tätigkeiten wurden inzwischen geändert. Bitte neu öffnen und prüfen.',409);
 switch($reportCommand['action']){
 case 'activity-report-edit':case 'activity-report-regenerate':
  $reportBody=$reportCommand['body']??'';if($reportCommand['action']==='activity-report-regenerate'){if(!$reportCommand['confirmed'])throw new DomainException('Ursprüngliche Berichtsaktion ausdrücklich bestätigen.',422);if(!$reportFacts['complete'])throw new DomainException('Veranstaltungen konnten nicht vollständig geladen werden. Eine Freigabe ist derzeit nicht möglich.',409);$reportBody=$reportFacts['text'];}
  $reports->edit($reportId,$reportCommand['version'],$reportBody,$reportCommand['source_hash']);$_SESSION['notice']='Bericht gespeichert. Die Freigabe gilt erst nach erneuter Prüfung.';break;
 case 'activity-report-preview':
  $reportPreview=$reports->preview($reportId,$reportCommand['version'],$reportCommand['with_signature']);$reportReceipts=is_array($_SESSION['activity_report_previews']??null)?$_SESSION['activity_report_previews']:[];$reportReceipts=array_filter($reportReceipts,static fn($row):bool=>is_array($row)&&is_int($row['expires']??null)&&$row['expires']>=time());$reportScope=Permissions::actor($db,$reportActor);
  $reportReceipts[$reportCommand['preview_token']]=['actor'=>$reportActor,'club_id'=>$reportScope['club_id'],'kind'=>$reportKind,'id'=>$reportId,'version'=>$reportCommand['version'],'signed'=>$reportCommand['with_signature'],'hash'=>$reportPreview['hash'],'expires'=>time()+1800];$_SESSION['activity_report_previews']=array_slice($reportReceipts,-8,null,true);
  header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");header('X-Frame-Options: SAMEORIGIN');
  header('Cache-Control: private, no-store');header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="taetigkeitsbericht-vorschau.pdf"');header('Content-Length: '.strlen($reportPreview['pdf']));echo $reportPreview['pdf'];exit;
 case 'activity-report-approve':
  $reportReceipts=is_array($_SESSION['activity_report_previews']??null)?$_SESSION['activity_report_previews']:[];$reportReceipt=$reportReceipts[$reportCommand['preview_token']]??null;$reportScope=Permissions::actor($db,$reportActor);
  if(!is_array($reportReceipt)||($reportReceipt['actor']??null)!==$reportActor||($reportReceipt['club_id']??null)!==$reportScope['club_id']||($reportReceipt['kind']??null)!==$reportKind||($reportReceipt['id']??null)!==$reportId||($reportReceipt['version']??null)!==$reportCommand['version']||($reportReceipt['signed']??null)!==$reportCommand['with_signature']||!is_int($reportReceipt['expires']??null)||$reportReceipt['expires']<time())throw new DomainException('Bitte zuerst die aktuelle PDF-Vorschau mit der gewählten Unterschrift öffnen.',422);
  $reports->approve($reportId,$reportCommand['version'],$reportReceipt['hash'],$reportCommand['with_signature'],$reportCommand['reviewed']);unset($_SESSION['activity_report_previews'][$reportCommand['preview_token']]);$_SESSION['notice']='Dieser Tätigkeitsbericht ist freigegeben.';break;
 default:throw new DomainException('Unbekannter Berichtsvorgang.',422);
 }
}
redirectTo(['view'=>'documents','tab'=>'activity-reports','report_kind'=>$reportKind,'report_id'=>$reportId]);
