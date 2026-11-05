<?php declare(strict_types=1);
if(!isset($_GET['invoice_recognition']))return;
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$inventoryRecognition=\Kulturhus\Verein\Web\InventoryRecognitionForm::applies($_POST,$_GET);
$recognitionMessages=$inventoryRecognition?(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\ManagerMessages::messages('de')):[];
$recognitionText=static fn(string$s):string=>$inventoryRecognition?\Kulturhus\Native\Language\InventoryRecognitionMessages::text($s,$recognitionMessages):$s;
try{
 if(!$inventoryRecognition&&(input($_GET,'view')!=='finance'||input($_GET,'tab')!=='inbox'))throw new DomainException('Rechnungserkennung hier nicht verfügbar.');
 require_once __DIR__.'/InvoiceRecognition.php';$service=new \Kulturhus\Verein\InvoiceRecognition($db,(string)$_SESSION['actor']);
 if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  if(!isset($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],input($_POST,'csrf'))){http_response_code(403);throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.');}
  $result=$inventoryRecognition?\Kulturhus\Verein\Web\InventoryRecognitionForm::run($db,(string)$_SESSION['actor'],$_POST,$_GET):$service->run(input($_POST,'doc'),(int)input($_POST,'revision'));
 }elseif(($_SERVER['REQUEST_METHOD']??'')==='GET')$result=$inventoryRecognition?\Kulturhus\Verein\Web\InventoryRecognitionForm::result($db,(string)$_SESSION['actor'],$_GET):$service->result(input($_GET,'doc'));
 else{http_response_code(405);header('Allow: GET, POST');throw new DomainException('Nicht unterstützte Anfrage.');}
 echo json_encode(['result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(DomainException$e){if(http_response_code()<400)http_response_code($inventoryRecognition&&in_array($e->getCode(),[403,404,409,422],true)?$e->getCode():422);$out=['error'=>$recognitionText($e->getMessage())];if($inventoryRecognition)$out['original']=\Kulturhus\Verein\Web\InventoryRecognitionForm::original($_POST,$_GET);echo json_encode($out,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
catch(Throwable){http_response_code($inventoryRecognition?503:500);$out=['error'=>$recognitionText('Texterkennung konnte nicht abgeschlossen werden. Bitte Original manuell prüfen.')];if($inventoryRecognition)$out['original']=\Kulturhus\Verein\Web\InventoryRecognitionForm::original($_POST,$_GET);echo json_encode($out,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
exit;
