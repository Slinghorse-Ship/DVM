<?php declare(strict_types=1);
if(!($nativeManagerEnabled??false)||!isset($nativeAccess)||$view!=='settings'||$tab!=='account'||input($_GET,'account_native')!=='1')return;
try{
 if(!$workspaceAllowed)throw new DomainException('Kontozugang nicht verfügbar.',403);
 $managerAccountPage??=\Kulturhus\Native\Web\Account::forManager($db,\Kulturhus\Native\Auth\Runtime::service($db),$_SESSION['csrf'],hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.($_SERVER['HTTP_USER_AGENT']??'')))->withinManager();
 $nativeManagerContent=$managerAccountPage->render('/account',$_GET,$_SESSION,$managerAccountState??[],$error??'');$error='';
}catch(DomainException){http_response_code(403);$error='Kontozugang nicht verfügbar.';}
