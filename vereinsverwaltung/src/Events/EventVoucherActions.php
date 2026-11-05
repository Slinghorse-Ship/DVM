<?php declare(strict_types=1);
// The front controller has already validated the session CSRF token.
$vouchers=new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar);
$voucherMail=new \Kulturhus\Verein\EventVoucherMail($db,(string)$_SESSION['actor'],$websiteCalendar);
$eventId=input($_POST,'voucher_event');$key=input($_POST,'request_key');
$route=['view'=>'portal','tab'=>'vouchers','voucher_event'=>$eventId];
switch($action){
case 'event-voucher-open':
    $route['voucher_event']=$vouchers->open(input($_POST,'source_id'),$key);break;
case 'event-voucher-grant':
    $benefits=[];foreach(['food'=>'Essen','drink'=>'Getränke','other'=>'Sonstiges'] as $kind=>$label){
        $amount=input($_POST,$kind.'_amount');if(trim($amount)===''||trim($amount)==='0')continue;
        $benefits[]=['kind'=>$kind,'label'=>input($_POST,$kind.'_label',$label),'amount'=>$amount,'unit'=>input($_POST,$kind.'_unit','piece')];
    }
    $vouchers->grant($eventId,arrayInput($_POST,'contacts'),$benefits,$key);$_SESSION['notice']='Gutscheine zugeteilt.';break;
case 'event-voucher-redeem':case 'event-voucher-revoke':
    $vouchers->change($eventId,input($_POST,'voucher_id'),(int)input($_POST,'version'),$action==='event-voucher-redeem'?'redeem':'revoke',input($_POST,'amount'),input($_POST,'reason'),$key,input($_POST,'ticket_id'));
    $route['q']=input($_POST,'q');$_SESSION['notice']=$action==='event-voucher-redeem'?'Eingelöst.':'Gutschein gesperrt.';break;
case 'event-voucher-delete':
    $vouchers->change($eventId,input($_POST,'voucher_id'),(int)input($_POST,'version'),'revoke','','Gutschein gelöscht',$key,input($_POST,'ticket_id'));
    $_SESSION['notice']='Gutschein gelöscht und ungültig gemacht.';break;
case 'event-voucher-reverse':
    $vouchers->reverse($eventId,input($_POST,'move_id'),input($_POST,'reason'),$key);$_SESSION['notice']='Einlösung zurückgenommen.';break;
case 'event-voucher-configure':
    $vouchers->configure($eventId,(int)input($_POST,'version'),input($_POST,'enabled')==='1',arrayInput($_POST,'helpers'),$key);$_SESSION['notice']='Einstellungen gespeichert.';break;
case 'event-voucher-mail':
    $voucherMail->queue($eventId,input($_POST,'contact_id'),input($_POST,'preview_hash'),$key);$_SESSION['notice']='Mail für den Versand vorgemerkt.';break;
case 'event-voucher-mail-template':
    $voucherMail->saveTemplate($eventId,(int)input($_POST,'template_version'),['subject'=>input($_POST,'mail_subject'),'message'=>input($_POST,'mail_message')],$key);
    if(input($_POST,'mail_to')!=='')$route['mail_to']=input($_POST,'mail_to');
    elseif(input($_POST,'mail_all')==='1')$route['mail_all']='1';
    $route['mail_template']='1';$_SESSION['notice']='Mailvorlage für diese Veranstaltung gespeichert. Es wurde keine Mail versendet.';break;
case 'event-voucher-mail-all':
    $batch=$voucherMail->queueBatch($eventId,input($_POST,'preview_hash'),$key);$_SESSION['notice']=$batch['queued'].' persönliche Mails mit PDF für den Versand vorgemerkt.';break;
case 'event-voucher-mail-cancel':
    $voucherMail->cancel($eventId,input($_POST,'mail_id'),$key);$_SESSION['notice']='Versandvorgang beendet.';break;
default:throw new DomainException('Gutscheinaktion nicht verfügbar.');
}
redirectTo($route);
