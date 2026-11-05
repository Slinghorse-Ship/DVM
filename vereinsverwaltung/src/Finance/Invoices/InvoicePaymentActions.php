<?php declare(strict_types=1);
$paymentActionMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];$paymentActionText=static fn(string$s):string=>\Kulturhus\Native\Language\InvoicePaymentMessages::text($s,$paymentActionMessages);
$paymentReports=new \Kulturhus\Verein\InvoicePaymentReports($db,(string)$_SESSION['actor']);
$paymentReports->assertDocument(input($_POST,'doc'),input($_POST,'entry_id'));
if($action==='invoice-payment-report'){
 $savedPaymentReport=$paymentReports->save(input($_POST,'entry_id'),(int)input($_POST,'entry_version'),(int)input($_POST,'report_version'),input($_POST,'paid_on'),input($_POST,'note'),input($_POST,'request_key'));
 $currentPaymentReport=$paymentReports->summary(input($_POST,'entry_id'));$_SESSION['notice']=$paymentActionText($currentPaymentReport['version']===$savedPaymentReport&&$currentPaymentReport['state']==='reported'?'Als bezahlt gemeldet. Die Zuordnung zur tatsächlichen Kontobuchung ist noch offen.':'Früher gespeicherten Meldungsausgang gelesen. Maßgeblich ist der aktuelle Zahlungsstand bei der Rechnung.');
}elseif($action==='invoice-payment-withdraw'){
 $savedPaymentReport=$paymentReports->withdraw(input($_POST,'entry_id'),(int)input($_POST,'entry_version'),(int)input($_POST,'report_version'),input($_POST,'reason'),input($_POST,'request_key'));
 $currentPaymentReport=$paymentReports->summary(input($_POST,'entry_id'));$_SESSION['notice']=$paymentActionText($currentPaymentReport['version']===$savedPaymentReport&&$currentPaymentReport['state']==='withdrawn'?'Zahlungsmeldung zurückgenommen. Der Verlauf bleibt erhalten.':'Früher gespeicherten Meldungsausgang gelesen. Maßgeblich ist der aktuelle Zahlungsstand bei der Rechnung.');
}elseif($action==='invoice-payment-allocate'){
 $finance->allocate(input($_POST,'transaction_id'),(int)input($_POST,'transaction_version'),input($_POST,'entry_id'),(int)input($_POST,'entry_version'),input($_POST,'amount'),input($_POST,'request_key'));
 $_SESSION['notice']=$paymentActionText('Kontobuchung zugeordnet. Es wurde keine neue Zahlung angelegt.');
}else throw new DomainException('Unbekannte Zahlungsaktion.');
redirectTo(['view'=>'finance','tab'=>'inbox','inbox_doc'=>input($_POST,'doc'),'status'=>'assigned']);
