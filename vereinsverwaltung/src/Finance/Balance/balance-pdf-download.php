<?php declare(strict_types=1);
if(isset($_GET['balance_pdf'])){
    try{
        require_once __DIR__.'/BalancePdf.php';
        $pdfYear=input($_GET,'balance_pdf');
        if(!preg_match('/^[0-9]{4}$/D',$pdfYear))throw new DomainException('Bilanzjahr prüfen.');
        $file=(new \Kulturhus\Verein\BalancePdf($db,(string)$_SESSION['actor']))->file((int)$pdfYear,input($_GET,'balance_pdf_hash'));
        header('Cache-Control: private, no-store');header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$file['name'].'"');
        header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");
        echo $file['bytes'];
    }catch(DomainException){http_response_code(409);echo 'Bilanz-PDF nicht verfügbar. Bitte den aktuellen Bilanzstand und Ihre Berechtigung prüfen.';}
    exit;
}
