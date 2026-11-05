<?php declare(strict_types=1);
if($action==='invoice-mail-only-archive'){if(!in_array(input($_POST,'archive'),['0','1'],true))throw new DomainException('Archivaktion prüfen.');(new \Kulturhus\Verein\InvoiceMailOnly($db,(string)$_SESSION['actor']))->archive(input($_POST,'mail_only'),(int)input($_POST,'version'),input($_POST,'archive')==='1',input($_POST,'reason'));$_SESSION['notice']=input($_POST,'archive')==='1'?'E-Mail abgelegt. Das Original bleibt erhalten.':'E-Mail wiederhergestellt.';redirectTo(['view'=>'finance','tab'=>'inbox','mail_state'=>input($_POST,'archive')==='1'?'archived':'active']);}
if($action==='invoice-inbox-fetch'){
 $fetch=(new \Kulturhus\Verein\InvoiceMailbox($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey()))->fetch();
 $_SESSION['notice']=$fetch['status']==='ok'?$fetch['new'].' neue Dateien, '.$fetch['existing'].' bereits vorhanden, '.($fetch['mail_only']??0).' E-Mails ohne Beleg, '.$fetch['failed'].' nicht übernommen.':'Kein Abruf: Rechnungsfach oder Netzwerkzugriff ist ausgeschaltet oder derzeit nicht verfügbar.';
 redirectTo(['view'=>'finance','tab'=>'inbox']);
}
if($action==='invoice-inbox-archive'){
 if(!in_array(input($_POST,'archive'),['0','1'],true))throw new DomainException('Ungültige Archivaktion.');
 (new \Kulturhus\Verein\InvoiceInbox($db,(string)$_SESSION['actor']))->archive(input($_POST,'doc'),(int)input($_POST,'document_version'),input($_POST,'archive')==='1',input($_POST,'reason'));
 $_SESSION['notice']=input($_POST,'archive')==='1'?'Eingangsdatei ausgeblendet. Sie bleibt im Archiv wiederherstellbar; die Originalmail im Postfach bleibt unverändert.':'Eingangsdatei wiederhergestellt.';
 redirectTo(['view'=>'finance','tab'=>'inbox','status'=>input($_POST,'archive')==='1'?'archived':'open']);
}
$inboxSelection=explode(':',input($_POST,'expense'));
if(count($inboxSelection)!==2||!ctype_digit($inboxSelection[1])||(int)$inboxSelection[1]<1)throw new DomainException('Bitte eine Ausgabe auswählen.');
(new \Kulturhus\Verein\Documents($db,(string)$_SESSION['actor']))->linkExpense(input($_POST,'doc'),(int)input($_POST,'document_version'),$inboxSelection[0],(int)$inboxSelection[1]);
$_SESSION['notice']='Originalrechnung der Ausgabe zugeordnet. Es wurde keine Freigabe oder Zahlung ausgelöst.';
redirectTo(['view'=>'finance','tab'=>'receipts','entry'=>$inboxSelection[0]]);
