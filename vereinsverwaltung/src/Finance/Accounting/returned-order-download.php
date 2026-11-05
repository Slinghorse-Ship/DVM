<?php declare(strict_types=1);
if(isset($_GET['returned_order_original'])){
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    try{
        $returnedOrderOriginal=$finance->paymentReturnOrderOriginal(input($_GET,'transaction'),input($_GET,'returned_order_original'));unset($returnedOrderOriginal['request'],$returnedOrderOriginal['review'],$returnedOrderOriginal['result'],$returnedOrderOriginal['void']);
        $returnedOrderBytes=json_encode(['format'=>'dvm-returned-order-original/1','original'=>$returnedOrderOriginal],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="bankauftragszuordnung-'.$returnedOrderOriginal['id'].'.json"');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $returnedOrderBytes;
    }catch(Throwable$failure){http_response_code($failure instanceof DomainException?(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403):503);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo isset($nativeAccess)?\Kulturhus\Native\Language\FinanceBankMessages::text('Ursprünglicher Bankauftragsnachweis nicht verfügbar.',(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages()):'Ursprünglicher Bankauftragsnachweis nicht verfügbar.';}
    exit;
}
