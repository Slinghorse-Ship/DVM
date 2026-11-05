<?php declare(strict_types=1);
if($view!=='members'||!array_key_exists('import',$_GET))return;
$memberExcelState=\Kulturhus\Verein\Web\MemberExcelPage::prepare($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SERVER['REQUEST_METHOD']??'GET',$_SESSION);
if($memberExcelState['status']>=400)http_response_code($memberExcelState['status']);

if($error!==''&&isset($nativeAccess))$error=\Kulturhus\Native\Language\MemberExcelMessages::row($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
if($error!==''&&http_response_code()<400)http_response_code(422);
