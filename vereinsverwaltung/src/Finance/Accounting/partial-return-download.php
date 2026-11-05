<?php declare(strict_types=1);
if(isset($_GET['partial_return_original'])){
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    try{
        $partialReturnDownload=$finance->partialReturnOriginal(input($_GET,'transaction'),input($_GET,'partial_return_original'));
        unset($partialReturnDownload['request'],$partialReturnDownload['review'],$partialReturnDownload['result'],$partialReturnDownload['void']);
        $partialReturnDownloadBytes=json_encode(['format'=>'dvm-partial-return-original/1','original'=>$partialReturnDownload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="teilrueckzahlung-'.$partialReturnDownload['id'].'.json"');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $partialReturnDownloadBytes;
    }catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $failure->getMessage();}
    catch(Throwable){http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo 'Ursprünglicher Teilrückzahlungsnachweis nicht verfügbar.';}
    exit;
}
