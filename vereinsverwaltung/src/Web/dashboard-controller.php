<?php declare(strict_types=1);
$dashboardHandled=false;
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||($_POST['dashboard_native']??null)!=='1')return;
$dashboardHandled=true;
try{
    if(!$workspaceAllowed||$view!=='dashboard')throw new DomainException('Dashboard im Vereinsmanager öffnen.',403);
    if(($_POST['action']??null)!=='complete-intro')throw new DomainException('Gültigen Dashboard-Vorgang auswählen.',422);
    if(!is_string($_POST['csrf']??null)||!is_string($_SESSION['csrf']??null)||$_SESSION['csrf']===''||!hash_equals($_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if(!$registry->canWrite())throw new DomainException('Keine Berechtigung für die Einführungsbestätigung.',403);
    $intake->completeIntro();$_SESSION['notice']='Einführung als gelesen gespeichert.';redirectTo(['view'=>'dashboard','setup'=>'setup','intro_result'=>'1']);
}catch(DomainException $failure){
    http_response_code(in_array($failure->getCode(),[403,422,503],true)?$failure->getCode():403);$error=$failure->getMessage();
}catch(Throwable){
    http_response_code(503);$error='Bestätigungsausgang konnte nicht bestätigt werden. Bitte den Einführungsstand dieses Vereins prüfen.';
}
