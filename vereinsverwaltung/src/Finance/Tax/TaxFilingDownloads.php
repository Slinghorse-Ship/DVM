<?php declare(strict_types=1);
if(input($_GET,'tax_filing_download')!==''){
    try{
        $file=(new \Kulturhus\Verein\TaxFilings($db,(string)$_SESSION['actor']))->download(input($_GET,'filing'),input($_GET,'tax_filing_download'));
        header('Content-Type: '.$file['mime']);header('Content-Disposition: '.($file['mime']==='application/pdf'?'inline':'attachment').'; filename="'.$file['name'].'"');
        header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");echo $file['bytes'];exit;
    }catch(DomainException){http_response_code(404);exit('Dokument nicht verfügbar.');}
}
