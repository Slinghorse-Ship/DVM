<?php declare(strict_types=1);
$manualApplicationRecovery=false;$manualApplicationHandled=false;$manualApplicationState=null;$manualApplicationAllowed=false;$manualApplicationError='';$manualInitialFamily='';
if($view!=='portal'||input($_GET,'tab')!=='application'||(!array_key_exists('manual',$_GET)&&input($_POST,'action')!=='manual-application'))return;
header('Cache-Control: no-store, private, max-age=0');
$manualApplicationMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\ManualApplicationMessages::messages('de');
$manualText=static fn(string $text,array $values=[]):string=>\Kulturhus\Native\Language\ManualApplicationMessages::text($text,$manualApplicationMessages,$values);
try{
 if(($_GET['manual']??null)!=='1'||array_diff(array_keys($_GET),['view','tab','manual','family'])!==[])throw new DomainException('Manuellen Antrag im ursprünglich geöffneten Bereich prüfen.',422);
 $manualService=new \Kulturhus\Verein\ManualApplications($db,(string)$_SESSION['actor']);$manualApplicationState=\Kulturhus\Verein\Web\ManualApplicationPage::prepare($manualService,$_POST);$manualIntake=new \Kulturhus\Verein\MembershipApplications($db,$manualApplicationState['current']['club_id'],(string)$_SESSION['actor']);
 if(array_key_exists('family',$_GET)){if(!is_string($_GET['family'])||!preg_match('/\A[a-f0-9]{32}\z/D',$_GET['family']))throw new DomainException('Familienzuordnung prüfen.',422);$manualIntake->admissionChoicePage('family',['selected'=>$_GET['family'].':1']);$manualInitialFamily=$_GET['family'];}
 $manualApplicationAllowed=true;
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  $manualApplicationHandled=true;if(empty($_SESSION['csrf'])||!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
  \Kulturhus\Verein\Web\ManualApplicationPage::target($_GET,$_POST);
  if(array_key_exists('manual_application_recheck',$_POST)&&($_POST['manual_application_recheck']!=='1'||array_key_exists('admission_browse',$_POST)))throw new DomainException('Erfassung erneut prüfen.',422);
  if(($_POST['manual_application_recheck']??null)==='1'){/* Review the same submitted original without recording an application. */}
  elseif(array_key_exists('admission_browse',$_POST)){\Kulturhus\Verein\AdmissionChoicePicker::browse($manualIntake,$_POST);$manualApplicationState=\Kulturhus\Verein\Web\ManualApplicationPage::prepare($manualService,$_POST);}
  else{$outcome=\Kulturhus\Verein\Web\ManualApplicationPage::record($manualService,$_GET,$_POST);if($outcome['reused']){$manualApplicationState['saved_id']=$outcome['id'];http_response_code(409);}else{$_SESSION['notice']=$manualText('Manueller Antrag gespeichert. Das Original kann jetzt als PDF oder Scan angehängt werden.');redirectTo(['view'=>'portal','tab'=>'application','application'=>$outcome['id']]);}}
 }
 if($manualApplicationState['source_error']!=='')throw new DomainException($manualApplicationState['source_error'],422);
}catch(Throwable $failure){
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')$manualApplicationHandled=true;if(http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\ManualApplicationPage::status($failure));$manualApplicationError=\Kulturhus\Native\Language\ManualApplicationMessages::failure($failure instanceof DomainException?$failure->getMessage():'Antragserfassung nicht verfügbar.',$manualApplicationMessages);
 if($manualApplicationAllowed)try{$manualApplicationState=\Kulturhus\Verein\Web\ManualApplicationPage::prepare($manualService,$_POST);}catch(Throwable){$manualApplicationAllowed=false;}
 $manualApplicationRecovery=!$manualApplicationAllowed&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)==='manual-application'&&in_array(http_response_code(),[409,503],true);
}
