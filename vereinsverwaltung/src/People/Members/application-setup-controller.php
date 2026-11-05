<?php declare(strict_types=1);
$applicationSetupHandled=false;$applicationSetupState=null;$applicationSetupAllowed=false;$applicationSetupRecovery=false;$applicationSetupError='';$applicationSetupHistory=null;$applicationSetupHistoryMode=false;$applicationSetupStep=0;
if($view!=='portal'||input($_GET,'tab')!=='application'||(!array_key_exists('configure',$_GET)&&input($_POST,'action')!=='save-application-settings'))return;
header('Cache-Control: no-store, private, max-age=0');
$applicationSetupMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\ApplicationSetupMessages::messages('de');$setupText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\ApplicationSetupMessages::text($source,$applicationSetupMessages,$values);
try{
 if(($_GET['configure']??null)!=='1'||array_diff(array_keys($_GET),['view','tab','configure','setup_history','setup_page','setup_revision'])!==[])throw new DomainException('Einrichtung im ursprünglich geöffneten Bereich prüfen.',422);
 $applicationSetupHistoryMode=array_key_exists('setup_history',$_GET);
 if($applicationSetupHistoryMode){if($_GET['setup_history']!=='1'||($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new DomainException('Verlauf im ursprünglich geöffneten Bereich prüfen.',422);$applicationSetupHistory=$intake->setupHistory(\Kulturhus\Verein\Web\ApplicationSetupForm::number($_GET['setup_page']??'1',1),isset($_GET['setup_revision'])?\Kulturhus\Verein\Web\ApplicationSetupForm::number($_GET['setup_revision'],1):null);$applicationSetupAllowed=true;}
 else{
  if(array_key_exists('setup_page',$_GET)||array_key_exists('setup_revision',$_GET))throw new DomainException('Verlauf im ursprünglich geöffneten Bereich prüfen.',422);
  $applicationSetupState=\Kulturhus\Verein\Web\ApplicationSetupForm::prepare($intake,$_POST);$applicationSetupAllowed=true;
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
   $applicationSetupHandled=true;if(empty($_SESSION['csrf'])||!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);\Kulturhus\Verein\Web\ApplicationSetupForm::target($_GET,$_POST);
   if(array_key_exists('application_setup_recheck',$_POST)&&$_POST['application_setup_recheck']!=='1')throw new DomainException('Einrichtung erneut prüfen.',422);
   if(($_POST['application_setup_recheck']??null)!=='1'){$outcome=\Kulturhus\Verein\Web\ApplicationSetupForm::save($intake,$_GET,$_POST);if($outcome['reused']){$applicationSetupState['saved_version']=$outcome['version'];http_response_code(409);}else{$_SESSION['notice']=$setupText('Einrichtung gespeichert. Frühere Anträge und Einwilligungen bleiben unverändert.');redirectTo(['view'=>'portal','tab'=>'application','configure'=>'1']);}}
  }
  if($applicationSetupState['source_error']!=='')throw new DomainException($applicationSetupState['source_error'],422);$applicationSetupHistory=$intake->setupHistory();
 }
}catch(Throwable $failure){
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')$applicationSetupHandled=true;if(http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\ApplicationSetupForm::status($failure));$message=$failure instanceof DomainException?$failure->getMessage():'Antragseinrichtung nicht verfügbar.';$applicationSetupError=\Kulturhus\Native\Language\ApplicationSetupMessages::failure($message,$applicationSetupMessages);$applicationSetupStep=in_array($message,['Titel und Inhalt der Hinweise prüfen.','Bestätigungen zur Antragseinrichtung prüfen.'],true)?2:($message==='Für diesen Mitgliedsantrag Rechnung auswählen.'?1:0);
 if($applicationSetupAllowed&&!$applicationSetupHistoryMode)try{$applicationSetupState=\Kulturhus\Verein\Web\ApplicationSetupForm::prepare($intake,$_POST);}catch(Throwable){$applicationSetupAllowed=false;}
 $applicationSetupRecovery=!$applicationSetupAllowed&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)==='save-application-settings'&&in_array(http_response_code(),[409,503],true);
}
