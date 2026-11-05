<?php declare(strict_types=1);
// Authentication, request method and CSRF are checked by the front controller.
$changeAction=input($_POST,'action');
if(in_array($changeAction,\Kulturhus\Verein\Web\MemberChangeForm::ACTIONS,true)){
 $changeService=new \Kulturhus\Verein\MemberChangeRequests($db,(string)$_SESSION['actor']);$changeReview=$changeAction==='member-change-review';
 if(!($changeReview?$changeService->canReview():$changeService->canSubmit()))throw new DomainException($changeReview?'Keine Berechtigung zur Prüfung der Mitgliederänderungen.':'Keine Berechtigung für eigene Mitgliederänderungen.',403);
 $changeCommand=\Kulturhus\Verein\Web\MemberChangeForm::command($_GET,$_POST);
 if($changeCommand['retry_original']&&!$changeCommand['retry_confirmed'])throw new DomainException('Ursprüngliche Änderungsaktion ausdrücklich bestätigen.',422);
 if($_FILES!==[])throw new DomainException('Hier können nur Name, Kontakt-E-Mail, Telefon und Anschrift geändert werden.',422);
 if($changeAction==='member-change-submit'){
  $changeId=$changeService->submit($changeCommand['data'],$changeCommand['contact_version'],$changeCommand['request_key'],$changeCommand['contact_id']);$_SESSION['notice']='Dein Änderungsantrag ist gespeichert. Der Vorstand prüft die Angaben.';
 }else{
  if(!$changeCommand['confirmed'])throw new DomainException('Ursprüngliche Änderungsaktion ausdrücklich bestätigen.',422);
  $changeId=$changeCommand['id'];
  if($changeAction==='member-change-withdraw'){$changeService->withdraw($changeId,$changeCommand['version']);$_SESSION['notice']='Dein Änderungsantrag wurde zurückgezogen.';}
  else{$changeService->review($changeId,$changeCommand['version'],$changeCommand['decision'],$changeCommand['note']);$_SESSION['notice']=$changeCommand['decision']==='accepted'?'Die geprüften Angaben wurden in das Mitgliederprofil übernommen.':'Der Antrag wurde mit der Begründung abgeschlossen.';}
 }
 redirectTo(['view'=>$changeReview?'members':'portal','tab'=>'changes','change'=>$changeId]);
}
