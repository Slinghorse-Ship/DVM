<?php declare(strict_types=1);
if(!isset($_GET['invoice_supplier_create']))return;
header('Content-Type: application/json; charset=utf-8');
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');throw new DomainException('Firma bitte über das Formular anlegen.');}
 if(input($_GET,'view')!=='finance'||input($_GET,'tab')!=='inbox')throw new DomainException('Firmenanlage hier nicht verfügbar.');
 if(!isset($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],input($_POST,'csrf'))){http_response_code(403);throw new DomainException('Sitzung abgelaufen. Die Rechnungsangaben bleiben geöffnet; bitte Anmeldung prüfen.');}
 if(!$finance->canWrite()||!$registry->canWrite()){http_response_code(403);throw new DomainException('Zum Anlegen sind Finanz- und Kontakt-Bearbeitungsrechte erforderlich.');}
 require_once __DIR__.'/InvoiceSupplierContacts.php';
 $service=new \Kulturhus\Verein\InvoiceSupplierContacts($db,(string)$_SESSION['actor']);
 $supplier=$service->create(scalarFields($_POST,['name','email','phone','address','iban','bic']),input($_POST,'request_key'));
 echo json_encode(['supplier'=>$supplier],JSON_THROW_ON_ERROR);
}catch(DomainException $e){if(http_response_code()<400)http_response_code(422);echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
catch(\Throwable $e){http_response_code(500);error_log('Inline supplier creation failed: '.get_class($e));echo json_encode(['error'=>'Die Firma konnte nicht gespeichert werden. Ihre Rechnungsangaben bleiben erhalten. Bitte erneut versuchen.'],JSON_THROW_ON_ERROR);}
exit;
