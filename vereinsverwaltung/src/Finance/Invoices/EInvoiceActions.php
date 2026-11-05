<?php declare(strict_types=1);
$eInvoiceService=new \Kulturhus\Verein\EInvoiceValidator($db,(string)$_SESSION['actor']);
$eInvoiceService->validate(input($_POST,'doc'),(int)input($_POST,'revision'),input($_POST,'sha256'));
$_SESSION['notice']='E-Rechnungsprüfung gespeichert. Das Ergebnis steht am XML-Original; eine Rechnungsfreigabe oder Zahlung wurde nicht ausgelöst.';
redirectTo(['view'=>'documents','doc'=>input($_POST,'doc')]);
