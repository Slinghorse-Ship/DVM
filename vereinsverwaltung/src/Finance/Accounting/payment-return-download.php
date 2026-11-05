<?php declare(strict_types=1);
if(isset($_GET['payment_return_original'])){
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    $paymentReturnDownloadAccess=$nativeAccess??null;$paymentReturnDownloadText=static function(string $value)use($db,$paymentReturnDownloadAccess):string{try{return $paymentReturnDownloadAccess!==null?\Kulturhus\Native\Language\FinanceBankMessages::text($value,(new \Kulturhus\Native\Language\Catalog($db,$paymentReturnDownloadAccess))->messages()):$value;}catch(Throwable){return $value;}};
    try{
        $paymentReturnDownload=$finance->paymentReturnOriginal(input($_GET,'transaction'),input($_GET,'payment_return_original'));
        unset($paymentReturnDownload['request'],$paymentReturnDownload['review'],$paymentReturnDownload['result']);
        $paymentReturnDownloadBytes=json_encode(['format'=>'dvm-payment-return-original/1','original'=>$paymentReturnDownload],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="zahlungsrueckfuehrung-'.$paymentReturnDownload['id'].'.json"');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $paymentReturnDownloadBytes;
    }catch(DomainException $failure){
        http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $paymentReturnDownloadText($failure->getMessage());
    }catch(Throwable){
        http_response_code(503);header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: private, no-store');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $paymentReturnDownloadText('Ursprünglicher Rückzahlungsnachweis nicht verfügbar.');
    }
    exit;
}
