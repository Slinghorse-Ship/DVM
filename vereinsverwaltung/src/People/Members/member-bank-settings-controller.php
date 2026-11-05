<?php declare(strict_types=1);
$memberBankSettingsHandled=false;
if(input($_GET,'view')!=='settings'||input($_GET,'tab')!=='sepa')return;
require_once __DIR__.'/MemberBankSettings.php';require_once dirname(__DIR__,2).'/Auth/BoardAccess.php';require_once dirname(__DIR__,2).'/Web/MemberBankSettingsPage.php';
header('Cache-Control: no-store, private, max-age=0');
$bankSettings=[];$bankSettingsState=null;$bankSettingsError='';$bankSettingsAllowed=false;
$bankSettingsMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberBankSettingsMessages::messages('de');
$bankSettingsText=static fn(string$text,array$values=[]):string=>\Kulturhus\Native\Language\MemberBankSettingsMessages::text($text,$bankSettingsMessages,$values);
try{
 $service=new \Kulturhus\Verein\MemberBankSettings($db,(string)$_SESSION['actor']);$bankSettingsState=\Kulturhus\Verein\Web\MemberBankSettingsPage::prepare($service,$_POST);$bankSettings=$bankSettingsState['fields'];$bankSettingsAllowed=true;
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  $memberBankSettingsHandled=true;
  if(empty($_SESSION['csrf'])||!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu öffnen.',403);
  \Kulturhus\Verein\Web\MemberBankSettingsPage::save($service,$_GET,$_POST);$_SESSION['notice']=$bankSettingsText('SEPA-Einstellungen gespeichert. Es wurde kein Einzug ausgelöst.');redirectTo(['view'=>'settings','tab'=>'sepa']);
 }
}catch(Throwable $failure){
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')$memberBankSettingsHandled=true;
 if(http_response_code()<400)http_response_code($failure instanceof DomainException?(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422):503);
 $bankSettingsError=$bankSettingsText($failure instanceof DomainException?$failure->getMessage():'SEPA-Einstellungen derzeit nicht verfügbar.');
 if($bankSettingsAllowed)try{$bankSettingsState=\Kulturhus\Verein\Web\MemberBankSettingsPage::prepare($service,$_POST);$bankSettings=$bankSettingsState['fields'];}catch(Throwable){$bankSettingsAllowed=false;}
}
