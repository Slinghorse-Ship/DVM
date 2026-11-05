<?php declare(strict_types=1);
$approvalService=new \Kulturhus\Verein\InvoiceApprovals($db,(string)$_SESSION['actor']);$approvalEntry=input($_POST,'entry');
if($action==='invoice-approve')$approvalService->approve($approvalEntry,input($_POST,'snapshot_hash'),input($_POST,'decision_id'),input($_POST,'request_key'),input($_POST,'reviewed')==='1');
elseif($action==='invoice-approval-clarify')$approvalService->clarify($approvalEntry,input($_POST,'snapshot_hash'),input($_POST,'decision_id'),input($_POST,'reason'),input($_POST,'request_key'));
elseif($action==='invoice-approval-withdraw')$approvalService->withdraw($approvalEntry,input($_POST,'snapshot_hash'),input($_POST,'decision_id'),input($_POST,'reason'),input($_POST,'request_key'));
else throw new DomainException('Unbekannte Freigabeaktion.');
$currentApproval=$approvalService->state($approvalEntry)['status'];
$_SESSION['notice']=match($currentApproval){'approved'=>'Rechnung geprüft und zur Zahlung freigegeben. Jetzt den Zahlungsweg auswählen; es wurde noch kein Geld überwiesen.','paid'=>'Die Ausgabe ist bereits bezahlt. Es wurde keine neue Zahlung ausgelöst.','withdrawn'=>'Freigabe zurückgenommen. Die bisherigen Entscheidungen bleiben dokumentiert.','clarified'=>'Rechnung zur Klärung zurückgestellt. Sie bleibt offen, bis die Angaben geprüft und erneut freigegeben wurden.',default=>'Die frühere Prüfentscheidung bleibt dokumentiert. Der aktuelle Stand ist nicht freigegeben; bitte erneut prüfen.'};
if(class_exists(\Kulturhus\Verein\LiveUploads::class,false))\Kulturhus\Verein\LiveUploads::finish();header('Location: '.url(receiptPaymentContext($approvalEntry,$_GET,false)).'#invoice-approval',true,303);exit;
