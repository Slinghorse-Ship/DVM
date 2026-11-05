<?php declare(strict_types=1);
if(isset($_GET['group_reversal_original'])){
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    $groupDownloadAccess=$nativeAccess??null;$groupDownloadText=static function(string $value)use($db,$groupDownloadAccess):string{try{return $groupDownloadAccess!==null?\Kulturhus\Native\Language\FinanceBankMessages::text($value,(new \Kulturhus\Native\Language\Catalog($db,$groupDownloadAccess))->messages()):$value;}catch(Throwable){return $value;}};
    try{
        $groupDownload=$finance->groupReversalOriginal(input($_GET,'transaction'),input($_GET,'group_reversal_original'));
        unset($groupDownload['request'],$groupDownload['review'],$groupDownload['result']);
        $groupDownloadBytes=json_encode(['format'=>'dvm-payment-group-reversal-original/1','original'=>$groupDownload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="gemeinsame-gegenbuchung-'.$groupDownload['id'].'.json"');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $groupDownloadBytes;
    }catch(DomainException $failure){
        http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $groupDownloadText($failure->getMessage());
    }catch(Throwable){
        http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $groupDownloadText('Ursprünglicher gemeinsamer Korrekturbeleg nicht verfügbar.');
    }
    exit;
}
