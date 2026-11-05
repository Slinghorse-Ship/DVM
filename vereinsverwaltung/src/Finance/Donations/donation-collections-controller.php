<?php declare(strict_types=1);
// Include before the main POST dispatcher; never accepts actor IDs from the request.
$donationCollectionsHandled=false;
if(input($_GET,'view')!=='finance'||input($_GET,'tab')!=='donation-collections')return;
require_once __DIR__.'/DonationCollections.php';
$donationCollectionsHandled=true;$collectionService=new \Kulturhus\Verein\DonationCollections($db,(string)$_SESSION['actor']);$waiverService=new \Kulturhus\Verein\DonationWaivers($db,(string)$_SESSION['actor']);$collectionPreview=null;$collectionError='';$collectionCanWrite=false;
try{
 $a=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);if(!in_array($a['finance_access'],['read','write'],true)){http_response_code(403);throw new DomainException('Finanzberechtigung erforderlich.');}$collectionCanWrite=$a['finance_access']==='write';
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){http_response_code(403);throw new DomainException('Formular abgelaufen. Bitte neu laden.');}
  if(!$collectionCanWrite){http_response_code(403);throw new DomainException('Finanz-Schreibrecht erforderlich.');}
  $data=$_POST['data']??[];if(!is_array($data))throw new DomainException('Formularangaben prüfen.');
  switch(input($_POST,'action')){
   case 'donation-collection-preview':$collectionPreview=$collectionService->build($data);break;
   case 'donation-collection-cancel':$collectionService->cancel(input($_POST,'receipt_id'),input($_POST,'reason'));$_SESSION['notice']='Sammelbestätigung begründet storniert. Bereits ausgegebene Exemplare gesondert zurückrufen.';redirectTo(['view'=>'finance','tab'=>'donation-collections']);break;
   case 'donation-collection-issue':$doc=$collectionService->issue($data,input($_POST,'request_key'),input($_POST,'preview_hash'));redirectTo(['view'=>'documents','doc'=>$doc]);break;
   case 'donation-waiver-save':$waiverService->save($data,input($_POST,'request_key'));$_SESSION['notice']='Aufwandsverzicht mit Nachweisen erfasst. Keine Zahlung und keine Bescheinigung erzeugt.';redirectTo(['view'=>'finance','tab'=>'donation-collections','contact'=>$data['contact_id']??'']);break;
   case 'donation-waiver-cancel':$waiverService->cancel(input($_POST,'id'),(int)input($_POST,'version'),input($_POST,'reason'));$_SESSION['notice']='Aufwandsverzicht begründet storniert.';redirectTo(['view'=>'finance','tab'=>'donation-collections']);break;
   default:throw new DomainException('Unbekannte Formularaktion.');
  }
 }
}catch(DomainException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[403,404,409,503],true)?$e->getCode():422);$collectionError=$e->getMessage();}
