<?php declare(strict_types=1);
use Kulturhus\Verein\DocumentPreview;
$data=$_POST['data']??[];if(!is_array($data))throw new DomainException('Ungültige Einstellungen.');
$settingsTab=input($_GET,'tab');$back=['view'=>'settings','tab'=>$settingsTab];
$personalAccountCatalog=($nativeManagerEnabled??false)&&isset($nativeAccess)?new \Kulturhus\Native\Language\Catalog($db,$nativeAccess):null;
$personalAccountText=static fn(string $source):string=>\Kulturhus\Native\Language\AccountMessages::text($source,$personalAccountCatalog);
if($action==='settings-registration'){
 $provider=input($_POST,'provider');
 try{(new \Kulturhus\Verein\IntegrationRegistrations($db,(string)$_SESSION['actor']))->save($provider,input($_POST,'registration_value'),input($_POST,'registration_revision'),input($_POST,'registration_confirm')==='1');}
 catch(DomainException $failure){http_response_code(str_contains($failure->getMessage(),'inzwischen geändert')?409:422);throw $failure;}
 $_SESSION['notice']='Schnittstellenkennung gespeichert.';header('Location: '.url(['view'=>'settings','section'=>$provider==='elster'?'tax':'bank']).'#'.$provider.'-registration',true,303);exit;
}elseif($action==='settings-notifications'){
 $topics=$_POST['topics']??[];if(!is_array($topics))throw new DomainException('Benachrichtigungsthemen prüfen.');
 $notificationService=new \Kulturhus\Verein\ManagerNotifications($db,(string)$_SESSION['actor']);
 // Forms opened before the chat checkbox existed must preserve its current setting.
 if(input($_POST,'chat_choice_present')!=='1'&&in_array('chat',$notificationService->state()['selected'],true))$topics[]='chat';
 $notificationService->save($topics,(int)input($_POST,'version'));
 $_SESSION['notice']=$personalAccountText('Deine Benachrichtigungsthemen wurden gespeichert. Die Geräteanmeldung kannst du darunter prüfen.');$_SESSION['account_saved_panel']='notifications';
 header('Location: '.url(['view'=>'settings','tab'=>'account']).'#notifications',true,303);exit;
}elseif($action==='settings-mail-channels'){
 (new \Kulturhus\Verein\MailChannelSettings($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey()))->save($data,(int)input($_POST,'version'));$_SESSION['notice']='Postfächer und Versandzuordnungen gespeichert. Keine Testmail versendet.';redirectTo(['view'=>'settings','section'=>'mail']);
}elseif(in_array($action,['settings-invoice-mailbox','settings-invoice-fetch'],true)){
 $invoiceMailbox=new \Kulturhus\Verein\InvoiceMailbox($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey());
 if($action==='settings-invoice-mailbox'){$invoiceMailbox->save($data,(int)input($_POST,'version'));$_SESSION['notice']='Rechnungsfach gespeichert. Keine Testmail versendet.';}
 else{$invoiceFetch=$invoiceMailbox->fetch();$_SESSION['notice']=$invoiceFetch['status']==='ok'?$invoiceFetch['new'].' neue Rechnungsanhänge, '.$invoiceFetch['existing'].' bereits vorhanden, '.($invoiceFetch['mail_only']??0).' E-Mails ohne Beleg, '.$invoiceFetch['failed'].' nicht übernommen.':'Kein Abruf: Postfach oder Netzwerkzugriff in dieser Umgebung ausgeschaltet.';}
 redirectTo(['view'=>'settings','section'=>'mail']);
}elseif($action==='settings-signature'){
 $signatureService=new \Kulturhus\Verein\BoardSignature($db,(string)$_SESSION['actor']);
 if(!$signatureService->canUse())throw new DomainException('Nur der erste Vorstand oder Superuser darf die Unterschrift verwalten.');
 $file=$_FILES['signature']??null;
 if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name'])||($file['size']??0)>2097152)throw new DomainException('Bitte ein Unterschriftenbild als PNG oder JPG bis 2 MB auswählen oder eine Zeichnung übernehmen.');
 $signatureService->save((string)file_get_contents($file['tmp_name']),(int)input($_POST,'version'),input($_POST,'reviewed')==='1');
 $_SESSION['notice']=$personalAccountText('Unterschrift gespeichert. Sie steht jetzt für die berechtigte Erstellung der Bestätigung bereit.');$_SESSION['account_saved_panel']='signature';
 redirectTo(['view'=>'settings','tab'=>'account']);
}elseif($action==='settings-save'){
 if($simpleSettingsRoute??false)\Kulturhus\Verein\Settings\SimpleEditor::validate($_GET,$_POST);
 $section=input($_POST,'section');$intent=input($_POST,'intent','save');$uploads=[];
 foreach(['logo','signature'] as $name){$file=$_FILES[$name]??null;if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;if(($file['error']??-1)!==UPLOAD_ERR_OK||!is_string($file['tmp_name'])||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name'])||$file['size']>2097152)throw new DomainException('Bild-Upload fehlgeschlagen oder größer als 2 MB.');$uploads[$name]=(string)file_get_contents($file['tmp_name']);}
 if(in_array($intent,['preview','pdf'],true)){
  if($uploads)throw new DomainException('Bitte neue Bilder zuerst mit „Speichern“ übernehmen. Danach steht die PDF-Vorschau bereit.');
  $group=str_starts_with($section,'texts_')?$section:input($_POST,'preview_group','texts_invoices');$template=input($_POST,'preview_template',$group==='texts_donations'?'donation':'invoice');
  if($section!=='paper'&&!str_starts_with($section,'texts_'))throw new DomainException('Keine Dokumentvorschau für diesen Bereich.');
  $settingsPreview=DocumentPreview::build($settings,$section==='paper'?$data:null,$group,$template,str_starts_with($section,'texts_')?$data:null);
  if($intent==='pdf'){$pdf=DocumentPreview::pdf($settingsPreview);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="dokumentvorschau.pdf"');header('Cache-Control: private, no-store');echo $pdf;exit;}
  $notice='Vorschau mit den aktuellen Eingaben. Noch nicht gespeichert.';$action='settings-preview-rendered';
 }else{
  if($intent!=='save')throw new DomainException('Ungültige Formularaktion.');$settings->save($section,$data,(int)input($_POST,'version'),$uploads);$_SESSION['notice']=$section==='account'?$personalAccountText('Einstellungen gespeichert.'):'Einstellungen gespeichert.';if($section==='account')$_SESSION['account_saved_panel']='profile';
  if($settingsTab==='')$back['section']=$section;if($settingsTab==='texts')$back['group']=substr($section,6);redirectTo($back);
 }
}elseif($action==='settings-catalog'){
 $saved=$settings->saveCatalog(input($_POST,'catalog_id'),(int)input($_POST,'version'),input($_POST,'kind'),$data);$_SESSION['notice']='Tarif gespeichert. Vorhandene Belege bleiben unverändert.';redirectTo($back+['edit'=>$saved]);
}elseif($action==='settings-assign'){
 $settings->assign(input($_POST,'catalog_id'),input($_POST,'contact_id'),input($_POST,'start'),input($_POST,'end'));$_SESSION['notice']='Tarif zugewiesen.';redirectTo($back);
}elseif($action==='settings-end-assignment'){
 $settings->endAssignment(input($_POST,'assignment_id'),(int)input($_POST,'version'),input($_POST,'end'));$_SESSION['notice']='Zuweisung beendet.';redirectTo($back);
}elseif($action==='settings-role'){
 $role=$settings->saveRole(input($_POST,'role_id'),(int)input($_POST,'version'),$data);$_SESSION['notice']='Rolle gespeichert.';redirectTo($back+['edit'=>$role]);
}elseif($action==='settings-role-assign'){
 $roleSelection=input($_POST,'role_id');$settings->assignRole(input($_POST,'user_id'),$roleSelection==='__base__'?'':$roleSelection,(int)input($_POST,'version'),$roleSelection==='__base__');$_SESSION['notice']=$roleSelection==='__base__'?'Zusätzliche Rolle zurückgesetzt.':'Rolle zugewiesen.';redirectTo($back+['role_user'=>input($_POST,'user_id')]);
}else throw new DomainException('Unbekannte Einstellungsaktion.');
