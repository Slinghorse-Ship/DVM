<?php declare(strict_types=1);
if(isset($_GET['transfer_execution_original'])){
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    try{
        $executionDownload=$finance->transferSettlementOriginal(input($_GET,'transaction'),input($_GET,'transfer_execution_original'));unset($executionDownload['request'],$executionDownload['review'],$executionDownload['result'],$executionDownload['void']);$executionDownloadBytes=json_encode(['format'=>'dvm-transfer-settlement-original/1','original'=>$executionDownload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="bankausfuehrung-'.$executionDownload['id'].'.json"');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $executionDownloadBytes;
    }catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $failure->getMessage();}
    catch(Throwable){http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo 'Ursprünglicher Ausführungsnachweis nicht verfügbar.';}
    exit;
}
