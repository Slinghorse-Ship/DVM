<?php declare(strict_types=1);
require_once __DIR__.'/InterfaceText.php';
$documentActionFormat??=static fn(string $source,array $values):string=>\Kulturhus\Verein\Documents\InterfaceText::format($source,$values);
$target=input($_POST,'doc');$version=(int)input($_POST,'version');
$documentData=scalarFields($_POST,['title','kind','document_date','folder','scope','notes','state','reason'])+['links'=>arrayInput($_POST,'links')];
switch($action){
 case 'document-receipt-purge':
  $documents->purgeReceipt($target,$version,input($_POST,'delete_not_required')==='1');$_SESSION['notice']='Rechnung und Dateiversionen endgültig gelöscht. Nicht im Papierkorb wiederherstellbar.';redirectTo(input($_POST,'return_context')==='invoice-inbox'?['view'=>'finance','tab'=>'inbox']:['view'=>'documents']);
 case 'document-folder-delete':
  $parent=$documents->deleteFolder(input($_POST,'folder_id'),$version);$_SESSION['notice']='Leerer Ordner gelöscht. Es wurden keine Dateien gelöscht.';redirectTo(['view'=>'documents','folder'=>$parent??'']);
 case 'document-receipt-delete':
  $documents->trashReceipt($target,$version);$_SESSION['notice']='Rechnung gelöscht. Im Papierkorb kann sie wiederhergestellt werden.';redirectTo(input($_POST,'return_context')==='invoice-inbox'?['view'=>'finance','tab'=>'inbox']:['view'=>'documents']);
 case 'document-receipt-restore':
  $documents->restoreReceipt($target,$version);$_SESSION['notice']='Rechnung wiederhergestellt.';redirectTo(['view'=>'documents','doc'=>$target]);
 case 'document-upload-many':
  if(\Kulturhus\Verein\Web\InventoryInvoiceForm::applies($_POST,$_GET)){\Kulturhus\Verein\Web\InventoryInvoiceForm::guard($db,(string)$_SESSION['actor'],$_POST,$_GET);\Kulturhus\Verein\Web\InventoryInvoiceForm::files($_FILES);}
  $inventoryInvoiceItem=input($_POST,'return_context')==='inventory-invoice'?input($_POST,'inventory_invoice_item'):null;if($inventoryInvoiceItem!==null){require_once dirname(__DIR__, 1).'/Inventory/InventoryInvoiceUpload.php';$inventoryInvoiceUpload=new \Kulturhus\Verein\InventoryInvoiceUpload($db,(string)$_SESSION['actor']);$inventoryInvoiceUpload->authorize($inventoryInvoiceItem);}
  $invoiceInboxUpload=input($_POST,'return_context')==='invoice-inbox';if($invoiceInboxUpload){if(!$finance->canWrite())throw new DomainException('Finanzschreibrecht erforderlich.');$documentData=array_replace($documentData,['kind'=>'receipt','scope'=>'finance','folder'=>'','links'=>[]]);}
  if(isset($_POST['live_uploads'])&&input($_POST,'document_confirm')!=='1')throw new DomainException('Gemeinsame Zuordnung vor dem Speichern bestätigen.');
  $incoming=$_FILES['document_files']??null;$files=[];$uploadBytes=0;
  if(!is_array($incoming)||!is_array($incoming['name']??null)||count($incoming['name'])<1||count($incoming['name'])>10)throw new DomainException('Eine bis zehn Dateien auswählen.');
  foreach($incoming['name'] as $i=>$name){$temporary=$incoming['tmp_name'][$i]??null;if(!is_string($name)||($incoming['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($temporary)||!\Kulturhus\Verein\LiveUploads::file($temporary))throw new DomainException('Upload unvollständig. Keine Datei abgelegt; bitte alle Dateien erneut auswählen (je 10 MB, zusammen 20 MB).');$prepared=\Kulturhus\Verein\LiveUploads::prepared($temporary,$name,min(10485760,20971520-$uploadBytes));$uploadBytes+=strlen($prepared->bytes);$file=['name'=>$prepared->name,'bytes'=>$prepared->bytes,'prepared'=>$prepared];$token=$_POST['live_uploads']['document_files'][$i]??null;if(is_string($token)&&isset($_POST['document_titles'][$token])){if(!is_string($_POST['document_titles'][$token]))throw new DomainException('Dokumenttitel prüfen.');$file['title']=$_POST['document_titles'][$token];}$date=is_string($token)?($_POST['document_dates'][$token]??null):null;if($date!==null){if(!is_string($date))throw new DomainException('Dokumentdatum prüfen.');$file['document_date']=$date;}if(is_string($token)&&isset($_POST['document_groups'][$token])){$group=$_POST['document_groups'][$token];$groupIds=arrayInput($_POST,'document_group_ids');if(!is_string($group)||!preg_match('/^g[0-9]$/D',$group)||count($groupIds)>10||!in_array($group,$groupIds,true))throw new DomainException('Ablageziel der Datei prüfen.');$groupLinks=$_POST['document_group_links'][$group]??[];if(!is_array($groupLinks)||!array_is_list($groupLinks)||array_filter($groupLinks,fn($v)=>!is_string($v)))throw new DomainException('Verknüpfungen des Ablageziels prüfen.');$file['links']=$groupLinks;$file['folder']=input(is_array($_POST['document_group_folders']??null)?$_POST['document_group_folders']:[],$group);$file['scope']=input(is_array($_POST['document_group_scopes']??null)?$_POST['document_group_scopes']:[],$group,input($_POST,'scope','orga'));}$files[]=$file;}
  if($invoiceInboxUpload)foreach($files as &$inboxFile){if(!in_array(strtolower(pathinfo($inboxFile['name'],PATHINFO_EXTENSION)),['pdf','xml','jpg','jpeg','png'],true))throw new DomainException('Für den Rechnungseingang PDF, XML, JPG oder PNG auswählen.');$inboxFile['scope']='finance';$inboxFile['links']=[];$inboxFile['folder']='';}unset($inboxFile);
  if($inventoryInvoiceItem!==null){try{$ids=\Kulturhus\Verein\Web\InventoryInvoiceForm::upload($db,(string)$_SESSION['actor'],$_POST,$_GET,$files);}catch(PDOException$failure){throw new DomainException('Rechnungsdatei-Auftrag konnte nicht bestätigt werden. Gespeicherten Stand prüfen.',503,$failure);}$_SESSION['notice']=$documentActionFormat('{count} Rechnungsdateien zum Gegenstand hinzugefügt. Im Rechnungseingang bitte prüfen; keine Ausgabe gebucht.',['count'=>count($ids)]);\Kulturhus\Verein\LiveUploads::finish();header('Location: '.url(['view'=>'inventory','tab'=>'items','item'=>$inventoryInvoiceItem,'invoice_request'=>$_POST['request_key']]).'#gegenstand-rechnungen',true,303);exit;}
  $ids=$documents->uploadMany($files,$documentData,input($_POST,'request_key'));if($invoiceInboxUpload){$_SESSION['notice']=$documentActionFormat('{count} Dateien im Rechnungseingang gespeichert. Bitte prüfen und einer Ausgabe zuordnen.',['count'=>count($ids)]);redirectTo(count($ids)===1?['view'=>'finance','tab'=>'inbox','inbox_doc'=>$ids[0]]:['view'=>'finance','tab'=>'inbox','status'=>'open']);}$_SESSION['notice']=$documentActionFormat('{count} Dokumente mit den gewählten Zuordnungen gespeichert.',['count'=>count($ids)]);redirectTo(count($ids)===1?['view'=>'documents','doc'=>$ids[0]]:['view'=>'documents','folder'=>input($_POST,'folder')]);
 case 'document-upload':case 'document-version':
  $file=$_FILES['document_file']??null;
  if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name']))throw new DomainException('Datei konnte nicht hochgeladen werden. Bitte eine Datei bis 10 MB auswählen.');
  $prepared=\Kulturhus\Verein\LiveUploads::prepared($file['tmp_name'],(string)$file['name']);$bytes=$prepared->bytes;
  if($action==='document-upload')$target=$documents->upload($prepared->name,$bytes,$documentData,input($_POST,'request_key'),null,$prepared);
  else $documents->newVersion($target,$version,$prepared->name,$bytes,input($_POST,'reason'),$prepared);
  break;
 case 'document-update':$documents->update($target,$version,$documentData);break;
 case 'document-folder':
  $folder=$documents->saveFolder(input($_POST,'folder_id'),$version,input($_POST,'name'),input($_POST,'parent'),input($_POST,'scope'));
  $_SESSION['notice']='Ordner gespeichert. Alle Dokumentverknüpfungen bleiben erhalten.';redirectTo(['view'=>'documents','folder'=>$folder]);
 case 'document-generate':
  $target=(new \Kulturhus\Verein\DocumentRecords($db,(string)$_SESSION['actor']))->generate(input($_POST,'source_type'),input($_POST,'source_id'),input($_POST,'request_key'));
  $_SESSION['notice']='PDF-Nachweis mit dem aktuellen Datenstand erstellt und im Vorgangsordner gespeichert.';break;
 default:throw new DomainException('Unbekannte Dokumentaktion.');
}
$_SESSION['notice']??='Dokument in der zentralen Ablage gespeichert.';redirectTo(['view'=>'documents','doc'=>$target]+scalarFields($_GET,['scope','q','folder','kind','state','unassigned','source_type','source_id','recursive','page','size','doc_mode']));
