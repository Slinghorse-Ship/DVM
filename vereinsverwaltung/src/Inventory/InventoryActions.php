<?php declare(strict_types=1);
use Kulturhus\Verein\Inventory;
if(in_array($action,['inventory-archive','inventory-restore','inventory-remove'],true))$inventoryLifecycleDecision=\Kulturhus\Verein\Web\InventoryLifecycleForm::guard($db,(string)$_SESSION['actor'],$_POST,$_GET,$inventory);
if($action==='inventory-file')\Kulturhus\Verein\Web\InventoryFileForm::guard($db,(string)$_SESSION['actor'],$_POST,$_GET,$inventory);
if(\Kulturhus\Verein\Web\InventoryLoanActionForm::applies($_POST,$_GET))\Kulturhus\Verein\Web\InventoryLoanActionForm::guard($db,(string)$_SESSION['actor'],$_POST,$_GET,$inventory);
if(\Kulturhus\Verein\Web\InventoryLoanCreateForm::applies($_POST,$_GET))\Kulturhus\Verein\Web\InventoryLoanCreateForm::guard($_POST,$_GET,$inventory);
if(\Kulturhus\Verein\Web\InventoryIndividualForm::applies($_POST,$_GET)){
 \Kulturhus\Verein\Web\InventoryIndividualForm::guard($_POST,$_GET,$inventory,$finance,$db,(string)$_SESSION['actor']);
 if($action!=='inventory-source-browse'){
  $individualStep=$_POST['individual_step']??($action==='inventory-individual-draft'?'prepare':'');
  if($individualStep!==''){$individualBody=$_POST;$individualBody['individual_step']=$individualStep;$inventoryIndividualDraft=$individualStep==='review'?\Kulturhus\Verein\Web\InventoryIndividualForm::review($db,$inventory,$finance,new \Kulturhus\Verein\InventoryFinancialSources($db,(string)$_SESSION['actor']),$individualBody,(string)$_SESSION['actor'],(string)$_SESSION['csrf']):\Kulturhus\Verein\Web\InventoryIndividualForm::draft($individualBody,$finance->canRead());$notice=$inventoryIndividualDraft['review']?'Einzelstücke zur Prüfung vorbereitet. Erst die ausdrückliche Bestätigung legt sie an.':'Einzelstückentwurf aktualisiert. Es wurde nichts angelegt.';$action='inventory-individual-draft';return;}
  [$individualCommon,$individualRows]=\Kulturhus\Verein\Web\InventoryIndividualForm::confirm($_POST,(string)$_SESSION['actor'],(string)$_SESSION['csrf']);
 }
}
if($action==='inventory-item'||($action==='inventory-source-browse'&&($_POST['source_form']??null)!=='individual'))\Kulturhus\Verein\Web\InventoryItemForm::target($_POST,$_GET,$inventory,$finance,$db,(string)$_SESSION['actor']);
if($action==='inventory-move')\Kulturhus\Verein\Web\InventoryMoveForm::target($_POST,$_GET,$inventory,$finance,$db,(string)$_SESSION['actor']);
if($action==='inventory-item'){
 if(array_key_exists('piece_correction_step',$_POST)){$inventoryPieceCorrectionDraft=\Kulturhus\Verein\Web\InventoryPieceCorrectionForm::prepare($inventory,$finance,$_POST,$_GET);$notice=$inventoryPieceCorrectionDraft['enabled']?'Stückbearbeitung geöffnet. Korrekturen prüfen und anschließend speichern.':(($_POST['piece_correction_step']??'')==='discard'?'Vorbereitete Stückkorrekturen verworfen. Übrige Angaben bleiben erhalten.':'Stückseite gewechselt. Ungespeicherte Gegenstandsangaben bleiben erhalten.');$action='inventory-piece-draft';return;}
 \Kulturhus\Verein\Web\InventoryPieceCorrectionForm::save($_POST);
}
if(in_array($action,['inventory-item','inventory-move'],true)){
 $pieceMode=$action==='inventory-item'?'create':'move';
 if(array_key_exists('piece_step',$_POST)){$inventoryPieceDraft=\Kulturhus\Verein\Web\InventoryPieceForm::prepare($inventory,$finance,$db,(string)$_SESSION['actor'],$_POST,$_GET,$pieceMode);$notice=$inventoryPieceDraft['enabled']?'Stückzeilen vorbereitet. Angaben prüfen und anschließend speichern.':'Alle vorbereiteten Stückangaben wurden verworfen. Es wurde nichts gespeichert.';$action='inventory-piece-draft';return;}
 \Kulturhus\Verein\Web\InventoryPieceForm::save($inventory,$_POST,$pieceMode);
}
$key=input($_POST,'request_key');$target=input($_POST,'target');$version=(int)input($_POST,'version');
$data=is_array($_POST['data']??null)?$_POST['data']:[];
$inventoryAfterSaveInvoice=$action==='inventory-item'&&$target===''&&input($_POST,'after_save')==='invoice-upload'&&$finance->canWrite()&&$inventory->canWrite();
$invoiceEntryReturn='';
if($action==='inventory-item'&&$target===''&&isset($data['invoice_position'])&&preg_match('/^expense:([a-f0-9]{32})$/D',(string)($data['source']??''),$invoiceSource))$invoiceEntryReturn=$invoiceSource[1];
switch($action){
 case 'inventory-loan-stock-browse':
  if($target!=='')throw new DomainException('Nur neue Ausleihbestände auswählen.');
  $inventory->loanStockPage($_POST['stock_q']??'',$_POST['stock_page']??1,$_GET['stock_move']??'',($data['reservation_mode']??'')==='window');return;
 case 'inventory-window-check':
  if($target!=='')throw new DomainException('Nur neue Ausleihplanung prüfen.');
  $windowPreview=$inventory->reviewLoanWindow($data);return;
 case 'inventory-loan-draft':
  if($target!=='')throw new DomainException('Nur neue Ausleihpositionen vorbereiten.');
  $_POST['data']=$inventory->loanDraft($data,$_GET['loan_draft_remove']??null);return;
 case 'inventory-source-browse':
  if(!in_array(input($_POST,'source_form','item'),['item','individual'],true))throw new DomainException('Quellenformular prüfen.');
  if($target!=='')$inventory->item($target);
  (new \Kulturhus\Verein\InventoryFinancialSources($db,(string)$_SESSION['actor']))->page($_POST['source_picker_kind']??'expense',$_POST['source_picker_page']??1,$_POST['source_picker_q']??'',$_GET['source_move']??'');
  return;
 case 'inventory-accessories':$rows=is_array($data['accessories']??null)?$data['accessories']:[];$rows=array_values(array_filter($rows,static fn($row)=>!is_array($row)||trim((string)($row['child']??''))!==''));$inventory->saveAccessories($target,$version,$rows,$key);$route=['tab'=>'items','item'=>$target];break;
 case 'inventory-archive':
  if(input($_POST,'confirmed')!=='1')throw new DomainException('Bitte den Gegenstand und den Bestand prüfen und das Entfernen bestätigen.');
  try{$inventory->archiveItem($_POST['target'],(int)$_POST['version'],(int)$_POST['stock_version'],$_POST['data'],$_POST['request_key']);}catch(PDOException$failure){throw new DomainException('Inventarentscheidung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'items','item'=>$_POST['target'],'item_action'=>'archive','lifecycle_request'=>$_POST['request_key']];break;
 case 'inventory-restore':
  if(input($_POST,'confirmed')!=='1')throw new DomainException('Bitte die Wiederherstellung mit Bestand 0 bestätigen.');
  try{$inventory->restoreItem($_POST['target'],(int)$_POST['version'],$_POST['request_key']);}catch(PDOException$failure){throw new DomainException('Inventarentscheidung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'items','item'=>$_POST['target'],'item_action'=>'restore','lifecycle_request'=>$_POST['request_key']];break;
 case 'inventory-location':
  if(!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Lagerortvorgang erforderlich.',403);
  $target=\Kulturhus\Verein\Web\InventoryLocationForm::target($_POST,$_GET);$version=(int)$_POST['version'];$data=$_POST['data'];$key=$_POST['request_key'];
  if($target!==''&&\Kulturhus\Verein\Web\InventoryLocationForm::opened($inventory,$target)['id']!==$target)throw new DomainException('Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.',409);
  try{$target=$inventory->saveLocation($target,$version,$data,$key);}catch(PDOException $failure){throw new DomainException('Lagerort konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}
  $route=['tab'=>'locations','location'=>$target];break;
 case 'inventory-individuals':
  try{$target=$inventory->createIndividualItems($individualCommon,$individualRows,$_POST['request_key']);}catch(PDOException $failure){throw new DomainException('Einzelstückanlage konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'items','group'=>$target];break;
 case 'inventory-item':
  if(!$inventory->canWrite())throw new DomainException('Aktuelle Inventarbearbeitung für diesen Gegenstandsvorgang erforderlich.',403);
  $target=$_POST['target'];$version=(int)$_POST['version'];$data=$_POST['data'];$key=$_POST['request_key'];
  if(isset($data['source'])&&is_string($data['source'])){[$type,$source]=array_pad(explode(':',$data['source'],2),2,'');$data['source_kind']=$type;$data['source_id']=$source;unset($data['source']);}
  try{$target=$inventory->saveItem($target,$version,$data,$key);}catch(PDOException $failure){throw new DomainException('Gegenstand konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'items','item'=>$target];if(isset($_POST['piece_correction_page']))$route['piece_page']=$_POST['piece_correction_page'];break;
 case 'inventory-remove':
  $removeItem=$inventory->item($target);if($removeItem['tracking_mode']!=='quantity'||$removeItem['cash_account']||!in_array($data['kind']??'',['remove','discard'],true))throw new DomainException('Bitte einen Mengenartikel und den zu entfernenden Bestand auswählen.');
  try{$inventory->move($_POST['target'],(int)$_POST['version'],$_POST['data'],$_POST['request_key']);}catch(PDOException$failure){throw new DomainException('Inventarentscheidung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'items','item'=>$_POST['target'],'item_action'=>'remove','lifecycle_request'=>$_POST['request_key']];break;
 case 'inventory-move':try{$inventory->move($target,$version,$data,$key);}catch(PDOException $failure){throw new DomainException('Lagerbewegung konnte nicht bestätigt werden. Aktuellen Bestand neu prüfen.',503,$failure);}$route=['tab'=>'items','item'=>$target,'stock_edit'=>'1'];break;
 case 'inventory-reserve':
  $data=\Kulturhus\Verein\Web\InventoryLoanCreateForm::normalise($_POST);
  try{$target=$inventory->reserve($data,$_POST['request_key']);}catch(PDOException$failure){throw new DomainException('Ausleihanlage konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'loans','loan'=>$target];break;
 case 'inventory-reschedule':case 'inventory-relocate':case 'inventory-issue':case 'inventory-cancel':case 'inventory-extend':try{$inventory->transition($_POST['target'],(int)$_POST['version'],substr($_POST['action'],10),$_POST['data'],$_POST['request_key']);}catch(PDOException$failure){throw new DomainException('Ausleihänderung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'loans','loan'=>$_POST['target']];break;
 case 'inventory-return':try{$inventory->returnLoan($_POST['target'],(int)$_POST['version'],$_POST['data'],$_POST['request_key']);}catch(PDOException$failure){throw new DomainException('Ausleihänderung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'loans','loan'=>$_POST['target']];break;
 case 'inventory-file':
  $file=\Kulturhus\Verein\Web\InventoryFileForm::file($_FILES);
  $prepared=\Kulturhus\Verein\LiveUploads::prepared($file['tmp_name'],basename((string)$file['name']),2097152);try{$inventory->upload($_POST['target'],$prepared->name,$prepared->bytes,$_POST['request_key'],$prepared);}catch(PDOException$failure){throw new DomainException('Dateizuordnung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}$route=['tab'=>'items','item'=>$_POST['target'],'file_request'=>$_POST['request_key']];break;
 case 'inventory-export':$csv=$inventory->export(input($_POST,'export_kind'),scalarFields($_POST,['q','category','status','item','location','contact','state']));documentDownload($documents,'kulturhus-inventar.csv',$csv,['title'=>'Inventar · '.input($_POST,'export_kind').' · '.date('d.m.Y'),'kind'=>'export','scope'=>'inventory','links'=>input($_POST,'item')!==''?['item:'.input($_POST,'item')]:[]],scalarFields($_POST,['export_kind','q','category','status','item','location','contact','state']));
 default:throw new DomainException('Unbekannte Inventaraktion.');
}
$_SESSION['notice']=match($action){'inventory-archive'=>'Der Gegenstand wurde aus dem aktiven Inventar entfernt. Belege und Verlauf bleiben im Archiv erhalten.','inventory-restore'=>'Der Gegenstand ist wieder aktiv. Der Bestand bleibt 0; vorhandene Stücke bitte als Zugang erfassen.','inventory-individuals'=>'Die Einzelstücke wurden angelegt. Jedes hat Bestand 1 und kann einzeln bearbeitet oder ausgeliehen werden.','inventory-reserve'=>'Reservierung gespeichert. Der aktuelle Lagerort bleibt unverändert.','inventory-return'=>'Rückgabe erfasst und Bestand aktualisiert.','inventory-remove'=>'Die gewählte Anzahl wurde entfernt. Der Gegenstand bleibt aktiv; Rechnungen und Verlauf bleiben erhalten.','inventory-move'=>'Lagerbewegung bestätigt und protokolliert.','inventory-issue'=>'Ausgabe bestätigt. Der Aufenthaltsort ist aktualisiert.',default=>'Inventarvorgang gespeichert.'};if(isset($inventoryLifecycleDecision)&&$inventoryLifecycleDecision['recorded']!==null)$_SESSION['notice']='Vorhandene Inventarentscheidung gelesen. Es wurde nichts erneut entfernt oder wiederhergestellt.';if($invoiceEntryReturn!==''){if(class_exists(\Kulturhus\Verein\LiveUploads::class,false))\Kulturhus\Verein\LiveUploads::finish();header('Location: '.url(['view'=>'finance','tab'=>'receipts','entry'=>$invoiceEntryReturn]).'#invoice-approval',true,303);exit;}if($inventoryAfterSaveInvoice){if(class_exists(\Kulturhus\Verein\LiveUploads::class,false))\Kulturhus\Verein\LiveUploads::finish();header('Location: '.url(['view'=>'inventory']+$route).'#gegenstand-rechnungen',true,303);exit;}redirectTo(['view'=>'inventory']+$route+(($route['tab']??'')==='items'?scalarFields($_GET,['item_page','q','status','category','group','asset_page','return_assets','return_where','where_page','state','member','location']):(($route['tab']??'')==='loans'?scalarFields($_GET,['loan_page','q','state','borrower','return_where','where_page','member','location']):[])));
