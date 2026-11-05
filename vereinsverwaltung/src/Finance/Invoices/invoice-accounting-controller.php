<?php declare(strict_types=1);
if(!isset($_GET['invoice_accounting_suggestions']))return;
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new DomainException('Bitte das Rechnungsformular verwenden.');}
 if(input($_GET,'view')!=='finance'||input($_GET,'tab')!=='inbox'||!isset($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],input($_POST,'csrf'))){http_response_code(403);throw new DomainException('Sitzung oder Rechnungsansicht prüfen.');}
 $positions=json_decode(input($_POST,'positions'),true,16,JSON_THROW_ON_ERROR);if(!is_array($positions))throw new DomainException('Positionen prüfen.');
 require_once __DIR__.'/InvoiceAccountingSuggestions.php';
 echo json_encode(['suggestions'=>(new \Kulturhus\Verein\InvoiceAccountingSuggestions($db,(string)$_SESSION['actor']))->suggest(input($_POST,'doc'),input($_POST,'supplier'),input($_POST,'date'),$positions,input($_POST,'category'),input($_POST,'purpose','general'))],JSON_THROW_ON_ERROR);
}catch(DomainException $e){if(http_response_code()<400)http_response_code(422);echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>'Kontierungsvorschläge sind derzeit nicht verfügbar. Bitte manuell prüfen.']);}exit;
