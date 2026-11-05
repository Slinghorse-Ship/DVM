<?php declare(strict_types=1);
foreach($documents->listing(['source_type'=>'entry','source_id'=>$r['id']]) as $xmlDocument){
    $mailDocumentId=$xmlDocument['id'];require __DIR__.'/../Invoices/invoice-mail-messages.php';
    if($xmlDocument['mime']!=='application/xml')continue;
    $xmlDocumentId=$xmlDocument['id'];require __DIR__.'/../Invoices/einvoice-inspection.php';
}
