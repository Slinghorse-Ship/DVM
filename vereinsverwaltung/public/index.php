<?php declare(strict_types=1);
require_once __DIR__.'/../src/Infrastructure/Runtime/LocalRuntime.php';
$production=\Kulturhus\Verein\ProductionRuntime::enabled();
if (!($production?\Kulturhus\Verein\ProductionRuntime::acceptsRequest($_SERVER):\Kulturhus\Verein\LocalRuntime::acceptsRequest(PHP_SAPI,$_SERVER))) {
    http_response_code(403); exit('Lokale Entwicklungsumgebung erforderlich.');
}
if(isset($_GET['mailbox_html'])&&!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Cache-Control: no-store');header('Content-Length: 0');exit;}
if(($_GET['view']??null)==='help'&&!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Cache-Control: no-store');header('Content-Length: 0');exit;}
// A draft read must never reach a remapped financial or other write action.
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&array_key_exists('invoice_read',$_POST)&&
    (($_POST['action']??null)!=='create-entry'||($_POST['inbox_guided']??null)!=='1'||($_GET['view']??null)!=='finance'||($_GET['tab']??null)!=='inbox'||array_diff(array_keys($_GET),['view','tab','inbox_doc','status','inbox_q','inbox_year','inbox_page'])!==[])){
    http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;
}
require_once __DIR__.'/../src/Web/ApplicationCataloguePage.php';
require_once __DIR__.'/../src/Web/ApplicationReviewForm.php';
require_once __DIR__.'/../src/Web/ManualApplicationPage.php';
require_once __DIR__.'/../src/Web/ApplicationSetupForm.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\ApplicationSetupForm::marked($_POST)){try{\Kulturhus\Verein\Web\ApplicationSetupForm::target($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\ManualApplicationPage::marked($_POST)){try{\Kulturhus\Verein\Web\ManualApplicationPage::target($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\ApplicationReviewForm::marked($_POST)){try{\Kulturhus\Verein\Web\ApplicationReviewForm::target($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}}
require_once __DIR__.'/../src/Web/MemberBankSettingsPage.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\MemberBankSettingsPage::marked($_POST)){try{\Kulturhus\Verein\Web\MemberBankSettingsPage::target($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}}
require_once __DIR__.'/../src/Web/MemberArchivePage.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&\Kulturhus\Verein\Web\MemberArchivePage::marked($_POST)){
 try{\Kulturhus\Verein\Web\MemberArchivePage::target($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}
}
require_once __DIR__.'/../src/Web/DocumentAssociationForm.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&array_key_exists('document_browse',$_POST)){
    try{\Kulturhus\Verein\Web\DocumentAssociationForm::route($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}
}
require_once __DIR__.'/../src/Web/DocumentSharingForm.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&array_key_exists('share_read',$_POST)){
    try{\Kulturhus\Verein\Web\DocumentSharingForm::route($_GET,$_POST);}catch(DomainException){http_response_code(422);header('Cache-Control: no-store');header('Content-Length: 0');exit;}
}
require_once __DIR__.'/../src/Communication/EditorialModules.php';
require_once __DIR__.'/../src/Communication/EditorialModulePreferences.php';
require_once __DIR__.'/../src/Communication/SocialMediaWorkspace.php';
require_once __DIR__.'/../src/Communication/NewsletterWorkspace.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodSourceForm.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceSourceDecisionForm.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePostingForm.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceYearClosingForm.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceSetupForm.php';
require_once __DIR__.'/../src/Finance/Balance/BalanceMappingForm.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['action']??null)&&in_array($_POST['action'],\Kulturhus\Verein\BalanceMappingForm::ACTIONS,true))$_GET=\Kulturhus\Verein\BalanceMappingForm::route($_POST);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['action']??null)&&in_array($_POST['action'],\Kulturhus\Verein\BalanceSetupForm::ACTIONS,true))$_GET=\Kulturhus\Verein\BalanceSetupForm::route($_POST);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['action']??null)&&in_array($_POST['action'],\Kulturhus\Verein\BalanceYearClosingForm::ACTIONS,true))$_GET=\Kulturhus\Verein\BalanceYearClosingForm::route($_POST);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['action']??null)&&in_array($_POST['action'],\Kulturhus\Verein\BalancePostingForm::ACTIONS,true))$_GET=\Kulturhus\Verein\BalancePostingForm::route($_POST);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['action']??null)&&in_array($_POST['action'],\Kulturhus\Verein\BalanceSourceDecisionForm::ACTIONS,true))$_GET=\Kulturhus\Verein\BalanceSourceDecisionForm::route($_POST);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['action']??null)&&in_array($_POST['action'],\Kulturhus\Verein\BalancePeriodSourceForm::ACTIONS,true))$_GET=\Kulturhus\Verein\BalancePeriodSourceForm::route($_POST);
$_GET=\Kulturhus\Verein\EditorialModules::route($_GET);
if(($_GET['view']??'')==='handbook'){http_response_code(410);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');exit('Das veraltete Handbuch ist vorübergehend deaktiviert.');}
// The document policy remains active across partial navigation. It must support
// every manager page regardless of the route used for the first full request.
$documentPreview=isset($_GET['document_file'],$_GET['preview'])||((($_SERVER['REQUEST_METHOD']??'GET')==='POST')&&($_GET['view']??null)==='documents'&&($_GET['tab']??null)==='activity-reports'&&($_POST['action']??null)==='activity-report-preview');
header("Content-Security-Policy: default-src 'none'; manifest-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; worker-src 'self'; connect-src 'self'; font-src 'self' blob:; img-src 'self' data: blob: ".(\Kulturhus\Verein\LocalRuntime::nativeServer()?'http://127.0.0.1:8092':'')."; media-src 'self' blob:; form-action 'self'; base-uri 'none'; frame-src 'self'; frame-ancestors ".($documentPreview?"'self'":"'none'"));
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
header('X-Kulturhus-Local-Project: '.hash('sha256',strtolower(str_replace('\\','/',dirname(__DIR__,2)))));
header('X-Kulturhus-Local-App: vereinsverwaltung');
// A same-origin continuation keeps Strict login cookies when returning from a bank.
if(isset($_GET['error'],$_GET['state'])&&!isset($_GET['enable_finish'])){echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Bankfreigabe</title><h1>Bankfreigabe nicht abgeschlossen</h1><p>Die Bankfreigabe wurde abgebrochen oder konnte nicht abgeschlossen werden.</p><a href="/vereinsverwaltung/?view=finance&amp;tab=bank">Zur Bankübersicht</a></html>';exit;}
if(isset($_GET['code'],$_GET['state'])&&!isset($_GET['enable_finish'])){
    $bankCode=is_string($_GET['code'])?$_GET['code']:'';$bankState=is_string($_GET['state'])?$_GET['state']:'';
    if(strlen($bankCode)>4096||!preg_match('/^(production|sandbox)-[a-f0-9]{64}$/D',$bankState)){http_response_code(400);exit('Bankfreigabe ungültig.');}
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bankfreigabe</title><h1>Zurück zum Vereinsmanager</h1><form method="get"><input type="hidden" name="enable_finish" value="1"><input type="hidden" name="code" value="'.htmlspecialchars($bankCode,ENT_QUOTES,'UTF-8').'"><input type="hidden" name="state" value="'.htmlspecialchars($bankState,ENT_QUOTES,'UTF-8').'"><button>Bankfreigabe abschließen</button></form></html>';exit;
}
$nativeManagerEnabled=getenv('KH_NATIVE_MANAGER')==='1';
if($nativeManagerEnabled){require __DIR__.'/../native/Integration/manager-session.php';}
else {
$managerSessionLifetime=8*3600;
// Explicit loopback-only synthetic session; personal accounts use native/Auth.
session_module_name('files');ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name($production?'KH_VEREIN':'KH_VEREIN_DEMO');
$managerSessionCookie=$_COOKIE[$production?'KH_VEREIN':'KH_VEREIN_DEMO']??'';
session_id(is_string($managerSessionCookie)&&preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$managerSessionCookie)?$managerSessionCookie:'');
try{$sessionDirectory=$production?\Kulturhus\Verein\ProductionRuntime::sessionDirectory():dirname(__DIR__,2).'/.local/vereinsverwaltung/sessions';}
catch(RuntimeException){http_response_code(503);exit('Privater Sitzungsspeicher ist nicht eingerichtet.');}
if(!is_dir($sessionDirectory)&&!mkdir($sessionDirectory,0700,true)&&!is_dir($sessionDirectory)){
    http_response_code(503);exit('Sitzungsspeicher ist nicht verfügbar.');
}
session_save_path($sessionDirectory);
ini_set('session.gc_maxlifetime',(string)$managerSessionLifetime);
session_set_cookie_params(['lifetime'=>$managerSessionLifetime,'httponly'=>true,'secure'=>$production,'samesite'=>'Strict','path'=>\Kulturhus\Verein\LocalRuntime::basePath()]);session_start();
setcookie(session_name(),session_id(),['expires'=>time()+$managerSessionLifetime,'httponly'=>true,'secure'=>$production,'samesite'=>'Strict','path'=>\Kulturhus\Verein\LocalRuntime::basePath()]);
$_SESSION['csrf']??=bin2hex(random_bytes(32));
// Automatic identity exists only in this loopback-only synthetic entry point.
$_SESSION['actor']??='demo-manager';
}
require __DIR__.'/../src/bootstrap.php';require_once __DIR__.'/../src/Auth/WorkspaceAccess.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\Database;
use Kulturhus\Verein\Registry;
use Kulturhus\Verein\Finance;
$path=$production?\Kulturhus\Verein\ProductionRuntime::database():\Kulturhus\Verein\LocalRuntime::database();
try{
    $db=Database::connect($path);
    if(!Database::tableExists($db,'schema_migrations')||(int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()!==Database::SCHEMA_VERSION){http_response_code(503);exit('Die Datenbank wird vorbereitet. Bitte den Betrieb prüfen.');}
}catch(PDOException){http_response_code(503);exit('Die Datenbank ist derzeit nicht erreichbar. Bitte den Betrieb prüfen.');}
if($nativeManagerEnabled){
    $memberCardBoundedRead=($_GET['view']??null)==='portal'&&($_GET['tab']??null)==='card'||($_GET['view']??null)==='settings'&&($_GET['tab']??null)==='member-cards';
    // Shared identity projection also reads contact rows before the feature controller.
    // Bound that wait and retain only the caller's posted input if it cannot complete.
    if($memberCardBoundedRead)$db->exec('SET SESSION innodb_lock_wait_timeout=1');
    try{require __DIR__.'/../native/Integration/manager-bind.php';}
    catch(PDOException $failure){if(!$memberCardBoundedRead||!in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw$failure;\Kulturhus\Verein\Web\MemberCardUnavailablePage::serve($_GET,$_POST,$_SESSION);exit;}
}
if($nativeManagerEnabled&&(isset($_GET['sepa_original'])||isset($_GET['sepa_xml'])||isset($_GET['sepa_order_xml'])||isset($_GET['transfer_original'])||isset($_GET['transfer_xml'])||isset($_GET['transfer_order_xml'])||isset($_GET['cover_original'])||isset($_GET['cover_pdf'])||isset($_GET['cover_preview_pdf'])||isset($_GET['reminder_original'])||isset($_GET['reminder_pdf'])||isset($_GET['reminder_eml'])||isset($_GET['reminder_preview_pdf'])||isset($_GET['reminder_preview_eml'])||isset($_GET['reminder_decision_original']))&&!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');exit;}
$admissionVotes=new \Kulturhus\Verein\AdmissionVotes($db,(string)$_SESSION['actor']);if(isset($_GET['admission_file'])){try{$admissionFile=$admissionVotes->documentFile(input($_GET,'application'),input($_GET,'admission_file'));header('Content-Type: '.$admissionFile['mime']);header('Content-Disposition: '.(isset($_GET['preview'])?'inline':'attachment').'; filename="antrag-datei.pdf"; filename*=UTF-8\'\''.rawurlencode($admissionFile['name']));echo $admissionFile['bytes'];exit;}catch(DomainException){http_response_code(403);exit('Antragsdatei nicht verfügbar.');}}
$websiteCalendar=null;
if(getenv('KH_NATIVE_MANAGER')==='1'){require_once dirname(__DIR__).'/native/bootstrap.php';$calendarActor=\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor']);$calendarClub=\Kulturhus\Native\Integration\ManagerBridge::clubKey($calendarActor['club_id']);$calendarIdentity=\Kulturhus\Native\Shared\Identity::forClub($db,$calendarClub);$websiteCalendar=\Kulturhus\Verein\WebsiteCalendar::fromNative($db,$calendarActor['club_id'],$calendarClub,$calendarIdentity['origin']);}
$registry=new Registry($db,(string)$_SESSION['actor'],$websiteCalendar);
require __DIR__.'/../src/Web/member-excel-download.php';
if(input($_GET,'view')==='pos'){
    require_once dirname(__DIR__).'/native/bootstrap.php';
    $posGuard=isset($nativeBridge)?static fn()=>$nativeBridge->managerActor():null;
    $posRegister=new \Kulturhus\Native\Pos\Register($db,(string)$_SESSION['actor'],$posGuard,$websiteCalendar);
    if(!$posRegister->canAccess()){http_response_code(403);exit('Keine Kassenberechtigung.');}
    $posWeb=new \Kulturhus\Native\Pos\Web($db,$posRegister,(string)$_SESSION['actor'],$_SESSION['csrf'],$websiteCalendar);$posError='';
    try {
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $posResult=$posWeb->action($_POST);
            if(isset($posResult['native'])){header('Content-Type: application/json');echo json_encode($posResult,JSON_THROW_ON_ERROR);exit;}
            header('Location: /vereinsverwaltung/?'.http_build_query(['view'=>'pos']+$posResult),true,303);exit;
        }
        echo $posWeb->render($_GET,$posError);
    }catch(DomainException|InvalidArgumentException $error){http_response_code(422);if(in_array($_POST['operation']??'',['native-info','native-start'],true)){header('Content-Type: application/json');echo '{"error":"Zahlungsversuch nicht startbar."}';}else echo $posWeb->render($_GET,$error->getMessage());}
    catch(Throwable){http_response_code(503);echo 'Kasse nicht verfügbar. Gespeicherten Zahlungsversuch prüfen; keine weitere Zahlung starten.';}
    exit;
}
$canAdmissions=$admissionVotes->canAccess();
$invitations=new \Kulturhus\Verein\CalendarInvitations($db,(string)$_SESSION['actor'],$registry);$invitationPreview=null;$printPreview=null;$canCalendar=$registry->canReadCalendar();
$website=new \Kulturhus\Verein\WebsiteWorkspace($db,(string)$_SESSION['actor']);$canWebsite=$website->canRead();
$finance=new Finance($db,(string)$_SESSION['actor']);$canFinance=$finance->canRead();
require __DIR__.'/../src/Finance/Accounting/payment-group-reversal-download.php';
require __DIR__.'/../src/Finance/Accounting/payment-return-download.php';
require __DIR__.'/../src/Finance/Accounting/partial-return-download.php';
require __DIR__.'/../src/Finance/Accounting/transfer-settlement-download.php';
require __DIR__.'/../src/Finance/Accounting/returned-order-download.php';
if(isset($_GET['catalog_file'])){
 require_once __DIR__.'/../src/Catalog/CatalogImports.php';
 try{$catalogFile=(new \Kulturhus\Verein\CatalogImports($db,(string)$_SESSION['actor']))->file(input($_GET,'catalog_id'),input($_GET,'catalog_file'));header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$catalogFile['name'].'"');header("Content-Security-Policy: sandbox; default-src 'none'");echo $catalogFile['bytes'];}catch(DomainException){http_response_code(403);echo 'Prüfdatei nicht verfügbar.';}exit;
}
require __DIR__.'/../src/Finance/Banking/enable-banking-controller.php';
require __DIR__.'/../src/Finance/Banking/bank-workflow-controller.php';
require __DIR__.'/../src/Finance/Invoices/invoice-recognition-controller.php';
require __DIR__.'/../src/Finance/Invoices/invoice-supplier-controller.php';
require __DIR__.'/../src/Finance/Invoices/invoice-accounting-controller.php';
if(isset($_GET['invoice_supplier_options'])){
 header('Content-Type: application/json; charset=utf-8');
 try{
  if(input($_GET,'view')!=='finance'||input($_GET,'tab')!=='inbox')throw new DomainException('Firmenauswahl hier nicht verfügbar.');
  require_once __DIR__.'/../src/Finance/Invoices/InvoiceSupplierContacts.php';
  $companyChoicePage=(new \Kulturhus\Verein\InvoiceSupplierContacts($db,(string)$_SESSION['actor']))->page(['contact_query'=>input($_GET,'q'),'contact_page'=>input($_GET,'page','1'),'contact_id'=>input($_GET,'selected'),'contact_exact'=>input($_GET,'exact')==='1']);
  echo json_encode(['suppliers'=>\Kulturhus\Verein\Web\InvoiceEntryChoices::companyOptions($companyChoicePage)]+array_intersect_key($companyChoicePage,array_flip(['page','pages','total','query'])),JSON_THROW_ON_ERROR);
 }catch(DomainException $e){http_response_code(403);echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
 exit;
}
if(input($_GET,'download')==='finance-report-xlsx'){
    if(!$canFinance){http_response_code(403);exit('Keine Berechtigung für Finanzauswertungen.');}
    try{
        require_once __DIR__.'/../src/Finance/Accounting/FinanceReports.php';require_once __DIR__.'/../src/Finance/Accounting/FinanceReportExport.php';
        $reportService=new \Kulturhus\Verein\FinanceReports($db,(string)$_SESSION['actor']);
        $reportExportState=\Kulturhus\Verein\Web\FinanceReportState::read($reportService,array_replace(['from'=>'','to'=>''],$_GET),date('Y'));
        if(!$reportExportState['available'])throw new DomainException($reportExportState['error'],$reportExportState['http_status']);
        $reportType=$reportExportState['values']['report_type'];$report=$reportExportState['report'];
        $bytes=\Kulturhus\Verein\FinanceReportExport::xlsx($report);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="kulturhus-'.($reportType==='journal'?'buchungsjournal':'einnahmen-ausgaben').'.xlsx"');
        echo $bytes;
    }catch(DomainException $ex){http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():400);echo e($ex->getMessage());}
    catch(Throwable){http_response_code(500);echo 'Die Auswertung konnte nicht exportiert werden. Bitte versuche es erneut.';}
    exit;
}
if(isset($_GET['donation_original'])){$kind=input($_GET,'donation_original');if(!$canFinance||!in_array($kind,['money','goods'],true)){http_response_code(403);exit('Formular nicht verfügbar.');}header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="amtliches-original.pdf"');readfile(__DIR__.'/../resources/donation-forms/'.$kind.'-original.pdf');exit;}
require __DIR__.'/../src/Documents/DocumentDownloads.php';
require __DIR__.'/../src/Finance/Tax/TaxFilingDownloads.php';
require __DIR__.'/../src/Finance/Balance/balance-pdf-download.php';
$documents=new \Kulturhus\Verein\Documents($db,(string)$_SESSION['actor']);
require __DIR__.'/../src/Documents/document-workflow-controller.php';
require __DIR__.'/../src/Finance/Balance/balance-period-download.php';
require __DIR__.'/../src/Documents/personal-document-downloads.php';
require __DIR__.'/../src/Events/event-voucher-downloads.php';
if(isset($_GET['calendar_print'])){try{$asset=(new \Kulturhus\Verein\CalendarPrints($db,(string)$_SESSION['actor'],$invitations))->file(input($_GET,'calendar_print'));header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename=einladungen.pdf');echo $asset['pdf'];}catch(DomainException){http_response_code(403);echo 'Druckdatei nicht verfügbar.';}exit;}
if(isset($_GET['minute_file'])){
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
 try{$minuteFileId=\Kulturhus\Verein\Web\MinutesForm::download($_GET);$asset=(new \Kulturhus\Verein\Minutes($db,(string)$_SESSION['actor']))->file($minuteFileId);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="protokoll.pdf"; filename*=UTF-8\'\''.rawurlencode($asset['name']));header('Content-Length: '.strlen($asset['bytes']));header("Content-Security-Policy: sandbox; default-src 'none'");if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$asset['bytes'];}
 catch(DomainException $minuteFileFailure){http_response_code(\Kulturhus\Verein\Web\MinutesPageState::status($minuteFileFailure));header('Content-Type: text/plain; charset=UTF-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$minuteFileMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];echo \Kulturhus\Native\Language\MinutesMessages::text('Protokolldatei nicht verfügbar.',$minuteFileMessages);}}
 exit;
}
if(isset($_GET['chat_updates'])){header('Content-Type: application/json');try{$rows=(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->listing();echo json_encode(['unread'=>array_sum(array_column($rows,'unread'))],JSON_THROW_ON_ERROR);}catch(DomainException){http_response_code(403);echo '{}';}exit;}
if(isset($_GET['chat_file'])){try{$asset=(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->file(input($_GET,'chat'),(int)input($_GET,'chat_file'));header('Cache-Control: private, no-store');header('Content-Type: '.$asset['mime']);header('Content-Disposition: attachment; filename="chat-anhang"; filename*=UTF-8\'\''.rawurlencode($asset['name']));echo $asset['bytes'];}catch(DomainException){http_response_code(403);echo 'Anhang nicht verfügbar.';}exit;}
require __DIR__.'/../src/People/Members/volunteer-downloads.php';
require __DIR__.'/../src/Governance/activity-report-download.php';
if(isset($_GET['invoice_mail_only_original'])){try{$mailBytes=(new \Kulturhus\Verein\InvoiceMailOnly($db,(string)$_SESSION['actor']))->original(input($_GET,'invoice_mail_only_original'));header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="nachricht-original.eml"');echo $mailBytes;}catch(DomainException){http_response_code(403);echo 'Originalmail nicht verfügbar.';}exit;}
if(isset($_GET['invoice_mail_original'])){try{$mailBytes=(new \Kulturhus\Verein\InvoiceMailView($db,(string)$_SESSION['actor']))->original(input($_GET,'invoice_mail_original'),input($_GET,'mail_key'),input($_GET,'mail_uid'));header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="rechnung-original.eml"');echo $mailBytes;}catch(DomainException){http_response_code(403);echo 'Originalmail nicht verfügbar.';}exit;}
if(isset($_GET['document_file'])){try{$asset=$documents->file(input($_GET,'document_file'),(int)input($_GET,'revision'));if(isset($_GET['preview'])&&in_array($asset['mime'],['image/heic','image/heif'],true)){require_once __DIR__.'/../src/Infrastructure/Media/HeicImage.php';$asset['bytes']=\Kulturhus\Verein\HeicImage::preview($asset['bytes'],$asset['mime']);$asset['mime']='image/jpeg';$asset['name']='vorschau.jpg';}$inline=isset($_GET['preview'])&&in_array($asset['mime'],['application/pdf','image/png','image/jpeg'],true);header('Cache-Control: private, no-store');header('Content-Type: '.$asset['mime']);header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="kulturhus-dokument"; filename*=UTF-8\'\''.rawurlencode($asset['name']));echo $asset['bytes'];}catch(DomainException){http_response_code(403);echo 'Dokument nicht verfügbar.';}exit;}
$inventory=new \Kulturhus\Verein\Inventory($db,(string)$_SESSION['actor']);
if(isset($_GET['inventory_asset'])){try{$asset=$inventory->file(input($_GET,'inventory_asset'));$download=isset($_GET['download'])||$asset['mime']==='application/pdf';header('Content-Type: '.$asset['mime']);header('Content-Disposition: '.($download?'attachment':'inline').'; filename="inventar-datei"; filename*=UTF-8\'\''.rawurlencode($asset['name']));echo $asset['bytes'];}catch(DomainException){http_response_code(403);echo 'Datei nicht verfügbar.';}exit;}
if(input($_GET,'tab')==='club-switch'||input($_GET,'view')==='webinars')redirectTo(['view'=>'dashboard']);
if(input($_GET,'view')==='members-extra'&&in_array(input($_GET,'tab'),['departments','association'],true))redirectTo(['view'=>'members','list'=>'current']);
if(input($_GET,'view')==='settings'&&input($_GET,'section')==='structure')redirectTo(['view'=>'settings']);
$settings=new \Kulturhus\Verein\Settings($db,(string)$_SESSION['actor']);$settingsPreview=null;
if(isset($_GET['settings_asset'])){
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
 header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
 try{$asset=$settings->asset(input($_GET,'settings_asset'));header('Content-Type: '.$asset['mime']);header('Content-Length: '.strlen($asset['bytes']));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$asset['bytes'];}
 catch(DomainException$failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);$message=$failure->getMessage();try{if(isset($nativeAccess))$message=\Kulturhus\Native\Language\PaperSettingsMessages::text($message,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Content-Type: text/plain; charset=utf-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 catch(Throwable){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');$message='Bild nicht verfügbar.';try{if(isset($nativeAccess))$message=\Kulturhus\Native\Language\PaperSettingsMessages::text($message,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo$message;}exit;
}
$intakeClub=$production?\Kulturhus\Verein\ProductionRuntime::club():\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['club_id'];
$intake=new \Kulturhus\Verein\MembershipApplications($db,$intakeClub,(string)$_SESSION['actor']);
if(input($_GET,'view')==='apply'){require __DIR__.'/../views/People/Members/application-public.php';exit;}
$accountPreferences=$settings->section('account')['data'];$view=input($_GET,'view',$accountPreferences['start_view']);if(isset($_GET['id'])||isset($_GET['new']))$view='members';
if($view==='portal'&&input($_GET,'tab')==='')redirectTo(['view'=>'portal','tab'=>'own']);
if($view==='portal'&&in_array(input($_GET,'tab'),['application','admissions'],true)&&!$canAdmissions){http_response_code(403);exit('Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.');}
if($view==='portal'&&input($_GET,'tab')==='admissions')redirectTo(['view'=>'portal','tab'=>'application','application'=>input($_GET,'application')]);
$views=['appearance'=>'Ansicht','help'=>'Hilfe','press'=>'Presse','social'=>'Social Media','newsletter'=>'Newsletter','handbook'=>'Handbuch','gema'=>'GEMA','website'=>'Website','inventory'=>'Inventar & Ausleihe','dashboard'=>'Dashboard','members'=>'Mitglieder & Kontakte','calendar'=>'Kalender','documents'=>'Dokumente','minutes'=>'Protokolle','material'=>'Material','messages'=>'Sendungen','chat'=>'Chat','free'=>'Freie Listen','contacts-extra'=>'Kontakte','members-extra'=>'Mitglieder','webinars'=>'Webinare','surveys'=>'Befragungen','finance'=>'Finanzen','donations'=>'Spenden','portal'=>'Vereinsportal','settings'=>'Einstellungen','audit'=>'Änderungsverlauf'];
if(!isset($views[$view])){$view='dashboard';http_response_code(404);}
if($view==='dashboard'&&$canWebsite&&!$registry->canRead()&&!$finance->canRead())foreach(['website','social','newsletter','press'] as $candidateModule){if((new \Kulturhus\Verein\EditorialModuleWorkspace($db,(string)$_SESSION['actor'],$candidateModule))->canRead()){$view=$candidateModule;break;}}
$workspaceAccess=new \Kulturhus\Verein\WorkspaceAccess($db,(string)$_SESSION['actor']);$workspaceAllowed=$workspaceAccess->allows(array_replace($_GET,['view'=>$view]));if(!$workspaceAllowed&&!isset($_GET['view'])&&!isset($_GET['id'])&&!isset($_GET['new'])){$view='dashboard';$workspaceAllowed=$workspaceAccess->allows(['view'=>'dashboard']);}$error='';$notice=(string)($_SESSION['notice']??'');unset($_SESSION['notice']);
$financialOriginalSource=\Kulturhus\Verein\Web\FinancialOriginalSource::request(($nativeManagerEnabled??false)?$view:'',$_GET,$_SERVER['REQUEST_METHOD']??'GET');
$freeInterfaceText=static fn(string $source):string=>$source;
if($view==='free'&&($nativeManagerEnabled??false)&&isset($nativeAccess)){
 $freeInterfaceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$freeInterfaceText=static fn(string $source):string=>\Kulturhus\Native\Language\ManagerMessages::text($source,$freeInterfaceMessages);
}
$documentActionText=static fn(string $source):string=>$source;
if(($view==='documents'||($view==='portal'&&in_array(input($_GET,'tab'),['signatures','documents','safety'],true))||str_starts_with(input($_POST,'action'),'document-')||str_starts_with(input($_POST,'action'),'live-upload-'))&&($nativeManagerEnabled??false)&&isset($nativeAccess)){
 $documentActionMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$documentActionText=static fn(string $source):string=>\Kulturhus\Native\Language\DocumentMessages::text($source,$documentActionMessages);$notice=$documentActionText($notice);
}
$documentActionFormat=static fn(string $source,array $values):string=>\Kulturhus\Verein\Documents\InterfaceText::format($source,$values,$documentActionMessages??[]);
$hseActionText=static fn(string $source):string=>\Kulturhus\Verein\Documents\HseInterfaceText::text($source,$documentActionMessages??[]);
if($view==='documents'&&input($_GET,'tab')==='inspections')$notice=$hseActionText($notice);
$calendarFieldError=false;
$calendarText=static fn(string $source):string=>$source;
if($view==='calendar'&&($nativeManagerEnabled??false)&&isset($nativeAccess)){
 $calendarMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$calendarText=static fn(string $source):string=>\Kulturhus\Native\Language\ManagerMessages::text($source,$calendarMessages);
}
$calendarFormat=static fn(string $source,array $values):string=>\Kulturhus\Native\Language\CalendarMessages::format($source,$values,$calendarText);
$gemaText=static fn(string $source):string=>$source;
if(in_array($view,['gema','calendar'],true)&&($nativeManagerEnabled??false)&&isset($nativeAccess)){
 $gemaMessages=$calendarMessages??(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$gemaText=static fn(string $source):string=>\Kulturhus\Native\Language\GemaMessages::text($source,$gemaMessages);
}
if($view==='calendar'&&input($_GET,'series',input($_POST,'series_id'))!==''&&!$registry->canWriteCalendar())$workspaceAllowed=false;
if(in_array($_POST['action']??null,['system-status-check','system-status-save'],true)||($view==='settings'&&input($_GET,'tab')==='status'&&($_GET['status_json']??null)==='1')){$statusCatalog=isset($nativeAccess)?new \Kulturhus\Native\Language\Catalog($db,$nativeAccess):null;\Kulturhus\Native\Status\Controller::serve($db,(string)$_SESSION['actor'],(string)$_SESSION['csrf'],$workspaceAllowed,$view,input($_GET,'tab'),$statusCatalog?->messages()??[],$statusCatalog?->locale()??'de');exit;}
if((is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'cache-'))||($view==='settings'&&input($_GET,'tab')==='cache'&&($_GET['cache_json']??null)==='1')){$cacheCatalog=new \Kulturhus\Native\Language\Catalog($db,$nativeAccess);$cacheLocale=$cacheCatalog->locale();\Kulturhus\Native\Cache\Controller::serve(\Kulturhus\Native\Cache\Factory::manager($nativeAccess,$nativeMediaRoot),(string)$_SESSION['csrf'],$workspaceAllowed,$view,input($_GET,'tab'),$cacheCatalog->messages($cacheLocale),$cacheLocale);exit;}
if((is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'extension-'))||($view==='settings'&&input($_GET,'tab')==='extensions'&&($_GET['extensions_json']??null)==='1')){$extensionCatalog=new \Kulturhus\Native\Language\Catalog($db,$nativeAccess);$extensionLocale=$extensionCatalog->locale();\Kulturhus\Native\Extensions\Controller::serve($db,(string)$_SESSION['actor'],(string)$_SESSION['csrf'],$workspaceAllowed,$view,input($_GET,'tab'),$extensionCatalog->messages($extensionLocale),$extensionLocale);exit;}
if((is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'api-token-'))||($view==='settings'&&input($_GET,'tab')==='api'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST')){\Kulturhus\Native\Api\TokensController::serve($db,(string)($_SESSION['auth_token']??''),(string)$_SESSION['csrf'],$workspaceAllowed,$view,input($_GET,'tab'));exit;}
if((is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'language-'))||($view==='settings'&&input($_GET,'tab')==='languages'&&($_GET['language_json']??null)==='1')){\Kulturhus\Native\Language\Http::serve($db,$nativeAccess,(string)$_SESSION['csrf'],$workspaceAllowed,$view,input($_GET,'tab'));exit;}
$systemState=($nativeManagerEnabled??false)?\Kulturhus\Native\System\ManagerEntry::prepare($db,$nativeAccess,$nativeMediaRoot,(string)$_SESSION['csrf'],$workspaceAllowed,$view,input($_GET,'tab'),$_POST):null;$systemHandled=$systemState!==null&&($_SERVER['REQUEST_METHOD']??'GET')==='POST';
if($systemState!==null&&$systemState['error']!=='')$nativeSystemErrorContent=\Kulturhus\Native\System\ManagerEntry::render($systemState,$_GET);
if(isset($_GET['mailbox_attachment'])){
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
 try{
  if(!$workspaceAllowed||$view!=='messages'||input($_GET,'tab')!=='inbox')throw new DomainException('Keine Postfachberechtigung.',403);
  $selection=\Kulturhus\Verein\Communication\MailboxForm::attachment($_GET);
  $part=(new \Kulturhus\Verein\Communication\Mailbox($db,(string)$_SESSION['actor'],mailbox:\Kulturhus\Verein\Communication\Mailbox::selection($_GET)))->attachment($selection['id'],$selection['part']);
  $inline=$selection['inline']&&$part['preview']!=='';header('Content-Type: '.($inline?$part['preview']:'application/octet-stream'));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: sandbox; default-src 'none'");
  header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($part['name']));header('Content-Length: '.$part['size']);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $part['bytes'];
 }catch(DomainException $error){http_response_code(in_array($error->getCode(),[403,404,409,422,503],true)?$error->getCode():403);header('Content-Type: text/plain; charset=UTF-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo 'Anhang nicht verfügbar.';}exit;
}
if(isset($_GET['mailbox_html'])){
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
 try{if(!$workspaceAllowed||$view!=='messages'||input($_GET,'tab')!=='inbox')throw new DomainException('Keine Postfachberechtigung.');$htmlQuery=\Kulturhus\Verein\Communication\MailboxForm::html($_GET);$mailboxHtml=(new \Kulturhus\Verein\Communication\Mailbox($db,(string)$_SESSION['actor'],mailbox:\Kulturhus\Verein\Communication\Mailbox::selection($_GET)))->formatted($htmlQuery['id'],$htmlQuery['images']);header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');header("Content-Security-Policy: sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:".($htmlQuery['images']?" https:":'')."; form-action 'none'; base-uri 'none'; frame-ancestors 'self'");if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $mailboxHtml;
 }catch(DomainException $error){http_response_code(in_array($error->getCode(),[403,404,409,422,503],true)?$error->getCode():403);header('Content-Type: text/plain; charset=UTF-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo 'E-Mail-Ansicht nicht verfügbar. Ursprüngliches Postfach und Nachricht prüfen.';}exit;
}
if(isset($_GET['mailbox_original'])){
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
 try{if(!$workspaceAllowed||$view!=='messages'||input($_GET,'tab')!=='inbox')throw new DomainException('Keine Postfachberechtigung.');$mailboxBytes=(new \Kulturhus\Verein\Communication\Mailbox($db,(string)$_SESSION['actor'],mailbox:\Kulturhus\Verein\Communication\Mailbox::selection($_GET)))->original(\Kulturhus\Verein\Communication\MailboxForm::download($_GET));header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="nachricht-original.eml"');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($mailboxBytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $mailboxBytes;}catch(DomainException $mailboxOriginalFailure){http_response_code(in_array($mailboxOriginalFailure->getCode(),[403,404,409,422,503],true)?$mailboxOriginalFailure->getCode():403);header('Content-Type: text/plain; charset=UTF-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$mailboxOriginalMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[];echo \Kulturhus\Native\Language\MailboxMessages::text('Originalmail nicht verfügbar.',$mailboxOriginalMessages);}}exit;
}
$calendarRecipientState=null;$calendarPeopleState=null;$contactChoiceState=null;$profile=null;$memberships=[];$history=[];$lists=[];$contacts=[];$events=[];$event=null;
$workflowWorkspace=$workspaceAllowed&&$workflowManager!==null&&(($view==='documents'&&in_array(input($_GET,'tab'),['workflows','inspections'],true))||($view==='portal'&&in_array(input($_GET,'tab'),['signatures','documents'],true)));
if($view==='handbook'&&isset($_GET['handbook_media'])){
 if(!$workspaceAllowed){http_response_code(403);exit('Keine Berechtigung.');}
 require_once __DIR__.'/../src/Infrastructure/Media/HandbookMedia.php';
 \Kulturhus\Verein\HandbookMedia::serve(input($_GET,'handbook_media'),__DIR__.'/../resources');
}
if($view==='handbook'&&isset($_GET['handbook_image'])){
 if(!$workspaceAllowed){http_response_code(403);exit('Keine Berechtigung.');}
 $handbookImage=input($_GET,'handbook_image');$handbookImages=json_decode((string)file_get_contents(__DIR__.'/../resources/handbook-images.json'),true,512,JSON_THROW_ON_ERROR);
 if(!in_array($handbookImage,$handbookImages,true)){http_response_code(404);exit('Bild nicht gefunden.');}
 header('Content-Type: image/png');header('Content-Disposition: inline');readfile(__DIR__.'/../resources/handbook/'.$handbookImage);exit;
}
if(isset($_GET['series_intent'])){
 if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
 try{if(!$workspaceAllowed||$view!=='calendar')throw new DomainException('Keine Serienberechtigung.');$seriesBytes=$registry->appointmentSeriesOriginal(input($_GET,'series'),input($_GET,'series_intent'));header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-serienoriginal.json"');header('X-Content-Type-Options: nosniff');if($_SERVER['REQUEST_METHOD']!=='HEAD')echo$seriesBytes;exit;}catch(DomainException){http_response_code(403);exit('Serienoriginal nicht verfügbar.');}
}
$pageData=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'size'=>25,'filters'=>Registry::filters([])];
$seriesRuleInput=['frequency'=>input($_POST,'series_frequency','once'),'every'=>input($_POST,'series_every','1'),'until'=>input($_POST,'series_until'),'skip'=>input($_POST,'series_skip')];
$filters=Registry::filters([]);$form=[];$eventForm=[];$canRead=$registry->canRead();$canWrite=$registry->canWrite();
require __DIR__.'/../src/Events/event-voucher-management-controller.php';
require __DIR__.'/../src/Events/event-voucher-mail-controller.php';
require __DIR__.'/../src/Web/mailing-controller.php';
require __DIR__.'/../src/Web/dashboard-controller.php';
$simpleSettingsSection=($nativeManagerEnabled??false)&&isset($nativeAccess)?\Kulturhus\Verein\Settings\SimpleEditor::section($_GET):null;
$simpleSettingsRoute=$simpleSettingsSection!==null&&in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','POST'],true)&&(!isset($_POST['action'])||in_array($_POST['action'],['settings-save','settings-registration'],true));
$generalNativeRoute=$preferenceNativeRoute=$invoicebankNativeRoute=$taxsettingsNativeRoute=$papersettingsNativeRoute=$textsettingsNativeRoute=$numberingNativeRoute=false;
$generalHandled=$preferenceHandled=$invoicebankHandled=$taxsettingsHandled=$papersettingsHandled=$textsettingsHandled=$numberingHandled=false;
require __DIR__.'/../src/Web/role-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/general-settings-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/finance-preferences-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/finance-bank-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/finance-tax-controller.php';
require __DIR__.'/../src/Web/mail-settings-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/paper-settings-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/text-settings-controller.php';
require __DIR__.'/../src/Web/outgoing-mail-controller.php';
require __DIR__.'/../src/Web/outgoing-invoice-controller.php';
require __DIR__.'/../src/Web/outgoing-correction-controller.php';
require __DIR__.'/../src/Web/contribution-delivery-controller.php';
require __DIR__.'/../src/Web/sepa-controller.php';
require __DIR__.'/../src/Web/transfer-controller.php';
require __DIR__.'/../src/Web/donation-cover-controller.php';
require __DIR__.'/../src/Web/debit-notice-controller.php';
require __DIR__.'/../src/Web/invoice-reminder-controller.php';
require __DIR__.'/../src/Web/invoice-reminder-decision-controller.php';
require __DIR__.'/../src/Web/contribution-run-controller.php';
if(!$simpleSettingsRoute)require __DIR__.'/../src/Web/numbering-controller.php';
require __DIR__.'/../src/Web/automation-controller.php';
require __DIR__.'/../src/Web/billing-controller.php';
require __DIR__.'/../src/Web/personal-price-controller.php';
require __DIR__.'/../src/Web/assignment-controller.php';
require __DIR__.'/../src/Web/tariff-controller.php';
require __DIR__.'/../src/People/Members/member-bank-controller.php';
require __DIR__.'/../src/People/Members/member-card-controller.php';
require __DIR__.'/../src/People/Members/member-bank-settings-controller.php';
require __DIR__.'/../src/People/Members/manual-application-controller.php';
require __DIR__.'/../src/People/Members/application-setup-controller.php';
require __DIR__.'/../src/Finance/Donations/donation-collections-controller.php';
require __DIR__.'/../src/Finance/Tax/annual-tax-controller.php';
require __DIR__.'/../src/Finance/Tax/tax-auto-package-controller.php';
require __DIR__.'/../src/Finance/Tax/tax-auto-draft-controller.php';
require __DIR__.'/../src/Finance/Balance/balance-filing-controller.php';
$taxLocalHandled=false;$taxLocalRead=false;$taxLocalState=null;$taxLocalError='';$taxLocalOutcome=null;
if(!$balanceLocalHandled)require __DIR__.'/../src/Finance/Tax/tax-filing-controller.php';
require __DIR__.'/../src/Finance/Accounting/reimbursement-controller.php';
try{
 if($_SERVER['REQUEST_METHOD']==='POST'&&!$mailingsHandled&&!$balanceLocalHandled&&!$taxLocalHandled&&!$taxDraftHandled&&!$taxAutoPackageHandled&&!$annualTaxHandled&&!$reimbursementHandled&&!$sepaHandled&&!$transferHandled&&!$coverHandled&&!$debitHandled&&!$reminderHandled&&!$reminderdecisionHandled&&!$automationHandled&&!$billingHandled&&!$personalPriceHandled&&!$assignmentHandled&&!$tariffHandled&&!$preferenceHandled&&!$invoicebankHandled&&!$taxsettingsHandled&&!$mailsettingsHandled&&!$papersettingsHandled&&!$textsettingsHandled&&!$outgoingmailHandled&&!$outgoinginvoiceHandled&&!$outgoingcorrectionHandled&&!$contributionrunHandled&&!$contributiondeliveryHandled&&!$numberingHandled&&!$generalHandled&&!$rolesHandled&&!$dashboardHandled&&!$voucherManagementHandled&&!$memberBankHandled&&!$memberCardHandled&&!$memberBankSettingsHandled&&!$manualApplicationHandled&&!$applicationSetupHandled&&!$donationCollectionsHandled&&!$isBankWorkflow&&!$systemHandled){
  if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf'])){http_response_code(403);throw new DomainException('Formular abgelaufen. Bitte neu laden.');}
  if(!isset($_POST['action'])&&is_string($_POST['operation']??null)&&$view==='settings'&&input($_GET,'tab')==='account'&&input($_GET,'account_native')==='1')$_POST['action']='account-native';
  $action=input($_POST,'action');$id=input($_POST,'id');
  if($action==='appearance-save'){
   if(!$workspaceAllowed||$view!=='appearance'||!($nativeManagerEnabled??false)||!isset($nativeAccess)||array_diff(array_keys($_POST),['action','csrf','appearance_version','appearance_scope','admin_view','admin_color','admin_density']))throw new DomainException('Ansicht im Vereinsmanager öffnen.',422);
   $appearanceVersion=input($_POST,'appearance_version');if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$appearanceVersion))throw new DomainException('Gespeicherte Anzeigeversion prüfen.',422);
   (new \Kulturhus\Native\Appearance\Preferences($db,$nativeAccess))->save(['admin_view'=>input($_POST,'admin_view'),'admin_color'=>input($_POST,'admin_color'),'admin_density'=>input($_POST,'admin_density')],(int)$appearanceVersion,input($_POST,'appearance_scope'));
   $_SESSION['notice']='Ansicht gespeichert.';redirectTo(['view'=>'appearance']);
  }
  if(in_array($action,\Kulturhus\Verein\Web\ApplicationReviewForm::ACTIONS,true))\Kulturhus\Verein\Web\ApplicationReviewForm::check($intake,$_GET,$_POST);
  if(array_key_exists('document_browse',$_POST)){
   \Kulturhus\Verein\Web\DocumentAssociationForm::read($db,(string)$_SESSION['actor'],$_GET,$_POST,$_FILES);$checkBrowseDraft=true;$notice=$documentActionText('Vorgangsauswahl angezeigt. Dokumentangaben und vorbereitete Dateien sind noch nicht gespeichert.');$action='association-source-browse';
  }
  if(array_key_exists('share_read',$_POST)){
   \Kulturhus\Verein\Web\DocumentSharingForm::read($db,(string)$_SESSION['actor'],$_GET,$_POST);$checkBrowseDraft=true;$notice=$documentActionText('Personenauswahl und gespeicherte Freigaben angezeigt. Es wurde keine Freigabe geändert.');$action='personal-sharing-browse';
  }
  require __DIR__.'/../src/Web/invoice-inbox-source-controller.php';
  require __DIR__.'/../src/Web/invoice-mail-source-controller.php';
  if(str_starts_with($action,'mailbox-')){
   if(!$workspaceAllowed||$view!=='messages'||input($_GET,'tab')!=='inbox')throw new DomainException('Postfach im Bereich Sendungen öffnen.');
   $mailboxRoute=(new \Kulturhus\Verein\Communication\MailboxPage($db,(string)$_SESSION['actor'],(string)$_SESSION['csrf']))->action($_POST,$_GET);$_SESSION['notice']=$mailboxRoute['notice'];unset($mailboxRoute['notice']);redirectTo(['view'=>'messages','tab'=>'inbox']+$mailboxRoute);
  }
  if(array_key_exists('recipient_selected',$_POST)){
   if(!$workspaceAllowed||$view!=='calendar'||!$invitations->canSend())throw new DomainException('Keine Berechtigung für diese Einladungsempfänger.');
   $calendarRecipientState=\Kulturhus\Verein\CalendarRecipientPicker::restore($_POST);
   if($calendarRecipientState['browse']==='all')$calendarRecipientState['selected']=$invitations->selectionGroup($id);
   $invitations->selectionPage($id,['contact_query'=>$calendarRecipientState['query'],'contact_page'=>$calendarRecipientState['page']]);
   $_POST['recipients']=$calendarRecipientState['selected'];
   if(array_key_exists('recipient_browse',$_POST)){$action='calendar-recipient-browse';$_POST['action']=$action;}
  }
  if(array_key_exists('calendar_selected',$_POST)){
   if(!$workspaceAllowed||$view!=='calendar'||!$registry->canWriteCalendar())throw new DomainException('Keine Berechtigung für die Teilnehmerauswahl.');
   $calendarPeopleState=\Kulturhus\Verein\CalendarPeoplePicker::restore($_POST);
   if($calendarPeopleState['kind_changed']&&$calendarPeopleState['browse']==='save')throw new DomainException('Terminart geändert. Teilnehmerauswahl prüfen und nochmals speichern.');
   if($calendarPeopleState['browse']==='group')$calendarPeopleState['selected']=array_values(array_intersect($registry->calendarSelectionGroup($calendarPeopleState['group_kind']??$calendarPeopleState['kind']),$registry->calendarSelectionGroup($calendarPeopleState['kind'])));
   $registry->contactChoicePage(['contact_query'=>$calendarPeopleState['query'],'contact_page'=>$calendarPeopleState['page']],true,$calendarPeopleState['kind']);
   if(array_key_exists('calendar_browse',$_POST)){$action='calendar-people-browse';$_POST['action']=$action;}
  }
  if(in_array($action,['review-application','manual-application'],true)&&array_key_exists('admission_browse',$_POST)){
   if(!$canAdmissions||$view!=='portal'||input($_GET,'tab')!=='application')throw new DomainException('Zuordnung im Aufnahmeformular öffnen.');
   if($action==='review-application')$intake->application($id);
   \Kulturhus\Verein\AdmissionChoicePicker::browse($intake,$_POST);$action='admission-choice-browse';
  }
  if(in_array($action,['settings-assign','donation-contact'],true)&&array_key_exists('contact_browse',$_POST)){
   $contactChoiceState=\Kulturhus\Verein\ContactChoicePicker::restore($_POST);$choiceAction=$contactChoiceState['action'];
   if(!$workspaceAllowed||($choiceAction==='settings-assign'&&(!$canWrite||$view!=='settings'||!$settings->canManage()))||($choiceAction==='donation-contact'&&(!$canRead||!in_array($view,['finance','donations'],true)||!$finance->canWrite())))throw new DomainException('Keine Berechtigung für diese Kontaktauswahl.');
   $registry->contactChoicePage($contactChoiceState);$action='contact-choice-browse';$_POST['action']=$action;
  }

  if(str_starts_with($action,'document-workflow-'))require __DIR__.'/../src/Documents/document-workflow-actions.php';
  if($nativeManagerEnabled&&$action==='native-module'){
   $nativeView=\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]));
   if(!$nativeView)throw new DomainException('Diese Modulaktion ist hier nicht verfügbar.');
   $nativeResult=$nativeEmbedded->action($nativeView,$_POST,$_FILES);
   if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){
    if(\Kulturhus\Native\Web\Communication\PlanningFormState::operation($_POST['operation']??null)){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['planning_saved'=>true,'url'=>'/vereinsverwaltung/?'.http_build_query(\Kulturhus\Native\Integration\Routes::result($nativeResult)),'notice'=>$nativeResult['notice']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['inbox_sync_response'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($nativeResult['inbox_sync_response'],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['library_response'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($nativeResult['library_response'],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['event_draft_response'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($nativeResult['event_draft_response'],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['check_plan_response'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($nativeResult['check_plan_response'],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_policy_result'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['video_policy_result'=>$nativeResult['video_policy_result'],'prepared'=>$nativeResult['video_policy_prepared'],'can_manage'=>$nativeResult['video_policy_can_manage'],'usage'=>$nativeResult['video_policy_usage']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_reuse_result'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['reuse_result'=>$nativeResult['video_reuse_result'],'prepared'=>$nativeResult['video_reuse_prepared'],'can_edit'=>$nativeResult['video_reuse_can_edit']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_recovery_result'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['recovery_result'=>$nativeResult['video_recovery_result'],'prepared'=>$nativeResult['video_recovery_prepared'],'can_manage'=>$nativeResult['video_recovery_can_manage'],'can_edit'=>$nativeResult['video_recovery_can_edit']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_file_result'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['video_file_result'=>$nativeResult['video_file_result'],'current'=>$nativeResult['video_file_current'],'prepared'=>$nativeResult['video_file_prepared'],'can_manage'=>$nativeResult['video_file_can_manage'],'usage'=>$nativeResult['video_file_usage']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_archive_result'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['video_archive_result'=>$nativeResult['video_archive_result'],'can_edit'=>$nativeResult['video_archive_can_edit'],'catalogue'=>$nativeResult['video_archive_catalogue']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_metadata_result'])){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['video_metadata_result'=>$nativeResult['video_metadata_result'],'prepared'=>$nativeResult['video_metadata_prepared'],'can_edit'=>$nativeResult['video_metadata_can_edit'],'can_proof'=>$nativeResult['video_metadata_can_proof']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['video_result'])){$videoResult=$nativeResult['video_result'];if(isset($videoResult['original_url']))$videoResult['original_url']=\Kulturhus\Native\Integration\Routes::translate($videoResult['original_url']);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['video_result'=>$videoResult,'usage'=>$nativeResult['video_usage'],'can_edit'=>$nativeResult['video_can_edit']],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['photo_result'])){$photo=$nativeResult['photo_result'];foreach(['url','thumb','original_url']as$key)if(isset($photo[$key]))$photo[$key]=\Kulturhus\Native\Integration\Routes::translate($photo[$key]);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['photo_result'=>$photo],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['canvas_result'])){$canvas=$nativeResult['canvas_result'];if(isset($canvas['url']))$canvas['url']=\Kulturhus\Native\Integration\Routes::translate($canvas['url']);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['canvas_result'=>$canvas],JSON_THROW_ON_ERROR);exit;}
    if($nativeView==='navigation'&&isset($nativeResult['compose_state']['navigation'])){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'unsaved'=>true,'html'=>$nativeEmbedded->main('navigation','',PHP_INT_MAX,$nativeResult['compose_state']),'url'=>\Kulturhus\Native\Integration\Routes::url('navigation')],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['save_lookup'])){$lookup=$nativeResult['save_lookup'];if(isset($lookup['url']))$lookup['url']=\Kulturhus\Native\Integration\Routes::translate($lookup['url']);header('Content-Type: application/json; charset=utf-8');echo json_encode(['save_lookup'=>$lookup],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['press_picker'])){header('Content-Type: application/json; charset=utf-8');echo json_encode(['picker'=>\Kulturhus\Native\Web\Communication\PressRecipientPicker::render($db,$nativeAccess,$nativeResult['press_picker'],true)],JSON_THROW_ON_ERROR);exit;}
    if(isset($nativeResult['image'])){$image=$nativeResult['image'];$image['url']=\Kulturhus\Native\Integration\Routes::translate($image['url']);if(isset($image['edit_url']))$image['edit_url']=\Kulturhus\Native\Integration\Routes::translate($image['edit_url']);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'image'=>$image],JSON_THROW_ON_ERROR);exit;}
    header('Content-Type: application/json; charset=utf-8');$nativeVersion=null;
    if($nativeView==='media'&&str_starts_with((string)($_POST['operation']??''),'album-')&&($nativeResult['item']??'')!=='')$nativeVersion=(new \Kulturhus\Native\Cms\Pages($db,$nativeAccess))->get($nativeResult['item'])['version'];
    echo json_encode(['ok'=>true,'version'=>$nativeVersion,'url'=>'/vereinsverwaltung/?'.http_build_query(\Kulturhus\Native\Integration\Routes::result($nativeResult))],JSON_THROW_ON_ERROR);exit;
   }
   if(isset($nativeResult['compose_state'])){$nativeComposeState=$nativeResult['compose_state'];$_GET['section']=$nativeResult['section']??'compose';}
   else{$_SESSION['notice']=$nativeResult['notice'];redirectTo(\Kulturhus\Native\Integration\Routes::result($nativeResult));}
  }
  if(str_starts_with($action,'catalog-import-')){
   require_once __DIR__.'/../src/Catalog/catalog-import-controller.php';set_time_limit(180);
   $catalogId=catalogImportAction($db,(string)$_SESSION['actor'],$action,$_POST,$_FILES);
   $_SESSION['notice']='Kontenplan-Prüfung gespeichert. Der aktive Jahresstand bleibt unverändert.';
   redirectTo(['view'=>'settings','tab'=>'tax-accounts','catalog'=>'1','catalog_id'=>$catalogId]);
  }
  if(str_starts_with($action,'bank-directory-')){
   $directoryService=new \Kulturhus\Verein\FintsDirectory($db,(string)$_SESSION['actor']);
   try{
    \Kulturhus\Verein\Web\BankDirectoryForm::assertInput($_POST,$action);
    if($action==='bank-directory-preview'){
     $file=$_FILES['directory_file']??null;
     if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name'])||($file['size']??0)>\Kulturhus\Verein\FintsDirectory::MAX_UPLOAD)throw new DomainException('ZIP oder CSV der Bankenliste bis 16 MiB auswählen.');
     $preview=$directoryService->preview((string)file_get_contents($file['tmp_name']),(string)($file['name']??'Bankenliste'),input($_POST,'directory_month'));
     redirectTo(['view'=>'finance','tab'=>'bank','directory'=>1,'directory_preview'=>$preview['id']]);
    }
    if($action!=='bank-directory-apply')throw new DomainException('Unbekannte Bankenlisten-Aktion.');
    $directoryService->apply(input($_POST,'directory_id'),input($_POST,'directory_confirm')==='1');$_SESSION['notice']='Bankenliste aktualisiert.';
    redirectTo(['view'=>'finance','tab'=>'bank','directory'=>1]);
   }catch(DomainException $failure){http_response_code(str_contains($failure->getMessage(),'inzwischen aktualisiert')?409:422);throw $failure;}
  }
  if(str_starts_with($action,'tax-filing-'))require __DIR__.'/../src/Finance/Tax/TaxFilingActions.php';
  if(str_starts_with($action,'event-voucher-'))require __DIR__.'/../src/Events/EventVoucherActions.php';
  if(str_starts_with($action,'tax-auto-'))require __DIR__.'/../src/Finance/Tax/TaxAutoActions.php';
  if(str_starts_with($action,'activity-report-'))require __DIR__.'/../src/Governance/ActivityReportActions.php';
  if(str_starts_with($action,'balance-'))require __DIR__.'/../src/Finance/Balance/BalanceActions.php';
  if(in_array($action,\Kulturhus\Verein\Web\AccountSettingsForm::ACTIONS,true)){$accountSubmittedOriginal=$_POST;\Kulturhus\Verein\Web\AccountSettingsForm::assertInput($finance,$_POST,$_GET);}
  if(in_array($action,\Kulturhus\Verein\FinanceWorkflowActions::ACTIONS,true)){
   $workflow=\Kulturhus\Verein\FinanceWorkflowActions::handle($db,(string)$_SESSION['actor'],$_POST);
   if(isset($workflow['bytes'])){header('Content-Type: '.$workflow['mime']);header('Content-Disposition: inline; filename="'.$workflow['filename'].'"');header('Cache-Control: no-store');echo $workflow['bytes'];exit;}
   $workflowRoute=$workflow['route'];$workflowAnchor=is_string($workflowRoute['_anchor']??null)?$workflowRoute['_anchor']:'';unset($workflowRoute['_anchor']);
   if($workflowAnchor!==''&&!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D',$workflowAnchor))$workflowAnchor='';
   if($workflowAnchor!==''){if(class_exists(\Kulturhus\Verein\LiveUploads::class,false))\Kulturhus\Verein\LiveUploads::finish();header('Location: '.url($workflowRoute).'#'.$workflowAnchor,true,303);exit;}redirectTo($workflowRoute);
  }
  if($action==='assignment-price-save'){
   if(!preg_match('/\A[0-9]{1,9}\z/D',input($_POST,'assignment_price_version')))throw new DomainException('Ursprüngliche Preisversion prüfen.');
   (new \Kulturhus\Verein\AssignmentPrices($db,(string)$_SESSION['actor']))->change(input($_POST,'price_assignment'),(int)input($_POST,'assignment_price_version'),input($_POST,'assignment_price_mode'),input($_POST,'assignment_price_value'),input($_POST,'assignment_price_key'));
   $priceTab=input($_GET,'tab','services');if(!in_array($priceTab,['services','rates'],true))$priceTab='services';$_SESSION['notice']='Persönlicher Tarifpreis gespeichert.';redirectTo(['view'=>'settings','tab'=>$priceTab,'assignment_prices'=>1,'price_assignment'=>input($_POST,'price_assignment')]);
  }
  if($action==='catalog-automation-change'){
   if(!preg_match('/\A[0-9]{1,9}\z/D',input($_POST,'auto_version'))||!preg_match('/\A[0-9]{1,2}\z/D',input($_POST,'auto_due'))||!in_array(input($_POST,'auto_enabled'),['0','1'],true))throw new DomainException('Version und Zahlungsfrist prüfen.');
   $state=(new \Kulturhus\Verein\CatalogBillingAutomation($db,(string)$_SESSION['actor']))->change(input($_POST,'auto_assignment'),(int)input($_POST,'auto_version'),input($_POST,'auto_first'),input($_POST,'auto_mode'),(int)input($_POST,'auto_due'),input($_POST,'auto_fingerprint'),input($_POST,'auto_key'),input($_POST,'auto_enabled')==='1');
   $autoTab=input($_GET,'tab','services');if(!in_array($autoTab,['services','rates'],true))$autoTab='services';
   $_SESSION['notice']=$state['status']==='active'?'Tarifautomatik aktiviert.':'Tarifautomatik pausiert.';redirectTo(['view'=>'settings','tab'=>$autoTab,'automation'=>1,'auto_assignment'=>input($_POST,'auto_assignment')]);
  }
  if($action==='catalog-billing-book'){
   if(!is_array($_POST['billing_items']??null))throw new DomainException('Geprüfte Tarifzuweisungen auswählen.');
   $billed=$finance->createCatalogCharges($_POST['billing_items'],input($_POST,'billing_date'),input($_POST,'billing_due'),input($_POST,'billing_mode'),input($_POST,'billing_day')?:null,input($_POST,'billing_key'));
   $billingTab=input($_GET,'tab','services');if(!in_array($billingTab,['services','rates'],true))$billingTab='services';
   $_SESSION['notice']=count($billed).' Tarifbelege gespeichert.';redirectTo(['view'=>'settings','tab'=>$billingTab,'billing'=>1,'billing_key'=>input($_POST,'billing_key')]);
  }
  if($action==='outgoing-hybrid-save'){
   \Kulturhus\Verein\Web\OutgoingHybridForm::target($_POST,$_GET);
   if(\Kulturhus\Verein\Web\OutgoingHybridForm::requested($_POST)){
    $hybridFormResult=\Kulturhus\Verein\Web\OutgoingHybridForm::handle($db,(string)$_SESSION['actor'],$_POST,$_GET);$checkBrowseDraft=true;$notice=$hybridFormResult['notice'];$action='outgoing-hybrid-browse';
   }else{
    require_once __DIR__.'/../src/Finance/Invoices/outgoing-hybrid-action.php';
    $outgoingDocument=saveOutgoingHybridInvoice($db,(string)$_SESSION['actor'],$_POST);
    $_SESSION['notice']='ZUGFeRD-PDF vollständig geprüft und zum Beleg gespeichert.';
    redirectTo(['view'=>'finance','tab'=>'receipts','entry'=>input($_POST,'entry_id')]);
   }
  }
  if($action==='outgoing-einvoice-save'){
   $xinvoiceId=\Kulturhus\Verein\Web\OutgoingXInvoiceForm::target($_POST,$_GET);
   if(\Kulturhus\Verein\Web\OutgoingXInvoiceForm::requested($_POST)){
    $xinvoiceFormResult=\Kulturhus\Verein\Web\OutgoingXInvoiceForm::handle($db,(string)$_SESSION['actor'],$_POST,$_GET);$checkBrowseDraft=true;$notice=$xinvoiceFormResult['notice'];$action='outgoing-xinvoice-browse';
   }else{
    $acceptedXml=\Kulturhus\Verein\Web\OutgoingXInvoiceForm::receipt($db,(string)$_SESSION['actor'],$xinvoiceId,$_POST);
    if($acceptedXml){$outgoingDocument=$acceptedXml['document']['id'];$_SESSION['notice']='Das ursprüngliche XML-Ergebnis war bereits gespeichert und wurde unverändert wiedergefunden.';}
    else{
     \Kulturhus\Verein\Web\OutgoingXInvoiceForm::assertCurrent($db,(string)$_SESSION['actor'],$_POST,$_GET);
     require_once __DIR__.'/../src/Finance/Invoices/outgoing-einvoice-action.php';$outgoingDocument=saveOutgoingEInvoice($db,(string)$_SESSION['actor'],$_POST);
     $_SESSION['notice']='XRechnung geprüft und zum Beleg gespeichert. Es wurde keine Nachricht versendet.';
    }
    $xinvoiceRoute=['view'=>'finance','tab'=>'receipts','entry'=>$xinvoiceId,'outgoing_einvoice'=>1,'xinvoice_receipt'=>$outgoingDocument];foreach(['q','page','status']as$xinvoiceContext)if(is_string($_GET[$xinvoiceContext]??null))$xinvoiceRoute[$xinvoiceContext]=$_GET[$xinvoiceContext];redirectTo($xinvoiceRoute);
   }
  }
  if($action==='donation-prepare')require __DIR__.'/../src/Finance/Donations/DonationPreparationActions.php';
  if(in_array($action,['invoice-payment-report','invoice-payment-withdraw','invoice-payment-allocate'],true))require __DIR__.'/../src/Finance/Invoices/InvoicePaymentActions.php';
  require_once __DIR__.'/../src/Infrastructure/Media/LiveUploads.php';
  if(in_array($action,['live-upload-stage','live-upload-remove'],true)){
   header('Content-Type: application/json; charset=UTF-8');try{
    if(array_key_exists('invoice_request',$_GET))\Kulturhus\Verein\Web\InventoryInvoiceForm::transport($db,(string)$_SESSION['actor'],$_POST,$_GET);
    if($action==='live-upload-remove'){if(array_key_exists('invoice_request',$_GET))\Kulturhus\Verein\Web\InventoryRecognitionForm::removeStage((string)$_SESSION['actor'],$_GET,$_POST['token']??null);\Kulturhus\Verein\LiveUploads::remove((string)$_SESSION['actor'],input($_POST,'token'));$result=['removed'=>true];}
    else{$purpose=input($_POST,'upload_action');\Kulturhus\Verein\LiveUploads::permit($db,(string)$_SESSION['actor'],$purpose,$_POST);$result=\Kulturhus\Verein\LiveUploads::stage((string)$_SESSION['actor'],$purpose,input($_POST,'upload_field'),$_FILES['live_file']??[],input($_POST,'upload_key'));if(array_key_exists('invoice_request',$_GET))\Kulturhus\Verein\Web\InventoryRecognitionForm::bindStage($result['token'],$_GET['item'],$_GET['invoice_request']);}
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR);
   }catch(DomainException $ex){$scopedInvoiceUpload=array_key_exists('invoice_request',$_GET);http_response_code($scopedInvoiceUpload&&in_array($ex->getCode(),[403,404,409,422],true)?$ex->getCode():400);$uploadError=$documentActionText($ex->getMessage());if($scopedInvoiceUpload)$uploadError=\Kulturhus\Native\Language\InventoryRecognitionMessages::text($uploadError,$documentActionMessages??[]);echo json_encode(['ok'=>false,'error'=>$uploadError],JSON_THROW_ON_ERROR);}catch(Throwable $ex){error_log('Live upload: '.$ex->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>$documentActionText('Upload konnte nicht verarbeitet werden. Bitte erneut versuchen.')]);}exit;
  }
  if($action==='document-check-save')require __DIR__.'/../src/Documents/document-check-upload.php';
  if($action==='create-entry'&&input($_POST,'inbox_guided')!=='1'){
   if(input($_POST,'manual_read')==='1'){
    $manualOriginal=\Kulturhus\Verein\Web\ManualFinanceForm::existing($db,(string)$_SESSION['actor'],input($_POST,'request_key'));
    if(!$manualOriginal){\Kulturhus\Verein\Web\ManualFinanceForm::retainUpload($db,(string)$_SESSION['actor'],$_POST,$_FILES);throw new DomainException('Unter dieser ursprünglichen Kennung ist noch kein Beleg gespeichert. Angaben prüfen und bewusst speichern.',404);}
    $manualOriginalRoute=receiptPaymentContext($manualOriginal['id'],$_GET,false);if(input($_POST,'target_transaction')!==''){try{$manualOriginalSource=$finance->transactionDetail(input($_POST,'target_transaction'));$manualOriginalRoute=array_replace($manualOriginalRoute,['candidate'=>$manualOriginalSource['id'],'match_account'=>$manualOriginalSource['account_id']]);}catch(DomainException){}}
    $_SESSION['notice']='Ursprünglich gespeicherten Beleg gelesen. Es wurde kein weiterer Beleg und keine Zahlung angelegt.';redirectTo($manualOriginalRoute);
   }
   if(input($_POST,'manual_move')==='remove-upload'){\Kulturhus\Verein\Web\ManualFinanceForm::removeUpload($db,(string)$_SESSION['actor'],$_POST);$_POST['manual_move']='review';}
   \Kulturhus\Verein\Web\ManualFinanceForm::retainUpload($db,(string)$_SESSION['actor'],$_POST,$_FILES);
  }
  if(\Kulturhus\Verein\Web\InventoryInvoiceForm::applies($_POST,$_GET)&&($action==='document-upload-many'||array_key_exists('inventory_invoice_complete',$_POST)||($_POST['return_context']??null)==='inventory-invoice')){
   $inventoryInvoiceIncomingFiles=$_FILES;\Kulturhus\Verein\Web\InventoryInvoiceForm::guard($db,(string)$_SESSION['actor'],$_POST,$_GET);if(!isset($_POST['live_uploads']))\Kulturhus\Verein\Web\InventoryInvoiceForm::files($_FILES);
  }
  if($action==='document-upload-many'&&array_key_exists('document_source_page',$_POST)){
   \Kulturhus\Verein\Web\DocumentAssociationForm::retainUploads($db,(string)$_SESSION['actor'],$_POST,$_FILES);
  }
  if($action!=='association-source-browse'&&isset($_POST['live_uploads'])){if(!is_array($_POST['live_uploads']))throw new DomainException('Uploadformular ungültig.');\Kulturhus\Verein\LiveUploads::attach((string)$_SESSION['actor'],$action,$_POST['live_uploads']);}
  \Kulturhus\Verein\LiveUploads::prepareRequest($db,(string)$_SESSION['actor'],$action,$_POST);
  if(str_starts_with($action,'document-')&&!str_starts_with($action,'document-workflow-')&&$action!=='document-check-save')require __DIR__.'/../src/Documents/DocumentActions.php';
  if(str_starts_with($action,'inventory-'))require __DIR__.'/../src/Inventory/InventoryActions.php';
  if(str_starts_with($action,'settings-'))require __DIR__.'/../src/Settings/SettingsActions.php';
  if($action==='update-positions'&&\Kulturhus\Verein\Web\PositionEditForm::requested($_POST)){$positionEditResult=\Kulturhus\Verein\Web\PositionEditForm::handle($db,(string)$_SESSION['actor'],$_POST,$_GET);$checkBrowseDraft=true;$notice=$positionEditResult['notice'];$action='position-edit-browse';}
  if(in_array($action,['invoice-approve','invoice-approval-clarify','invoice-approval-withdraw'],true)){
   if(\Kulturhus\Verein\Web\InvoiceReviewForm::requested($_POST)){$invoiceReviewResult=\Kulturhus\Verein\Web\InvoiceReviewForm::handle($db,(string)$_SESSION['actor'],$_POST,input($_GET,'entry'));$checkBrowseDraft=true;$notice=$invoiceReviewResult['notice'];$action='invoice-approval-browse';}
   else require __DIR__.'/../src/Finance/Invoices/InvoiceApprovalActions.php';
  }
  if(in_array($action,['invoice-inbox-assign','invoice-inbox-archive','invoice-inbox-fetch','invoice-mail-only-archive'],true))require __DIR__.'/../src/Finance/Invoices/InvoiceInboxActions.php';
  if($action==='einvoice-validate')require __DIR__.'/../src/Finance/Invoices/EInvoiceActions.php';
  if(str_starts_with($action,'bank-preview-')){
   unset($_SESSION['bank_preview']);
   throw new DomainException('Der simulierte Bankabruf wurde entfernt. Onlinebanking ist noch nicht eingerichtet.');
  }
  if($action==='persona'){
   if($nativeManagerEnabled)throw new DomainException('Persönliche Konten können nicht als Testrolle gewechselt werden.');
   $actor=input($_POST,'actor');if(!in_array($actor,['demo-manager','demo-finance','demo-member','demo-editor'],true))throw new DomainException('Ungültiger Testzugang.');
   session_regenerate_id(true);unset($_SESSION['bank_preview']);$_SESSION['actor']=$actor;$_SESSION['csrf']=bin2hex(random_bytes(32));redirectTo(['view'=>$actor==='demo-editor'?'website':'dashboard']);
  }
  require __DIR__.'/../src/Communication/Chat/chat-polls-controller.php';
  require __DIR__.'/../src/People/Members/member-changes-controller.php';
  switch($action){
   case 'account-native':
    if(!($nativeManagerEnabled??false)||!isset($nativeAccess)||$view!=='settings'||input($_GET,'tab')!=='account'||input($_GET,'account_native')!=='1')throw new DomainException('Kontoeinstellungen im Vereinsmanager öffnen.',422);
    $managerAccountPage=\Kulturhus\Native\Web\Account::forManager($db,\Kulturhus\Native\Auth\Runtime::service($db),$_SESSION['csrf'],hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.($_SERVER['HTTP_USER_AGENT']??'')))->withinManager();$managerAccountState=$managerAccountPage->action($_POST,$_SESSION);
    if(isset($managerAccountState['redirect'])){header('Location: '.$managerAccountState['redirect'],true,303);exit;}break;
   case 'finance-account-settings-refresh':$accountSettingsRefreshed=true;break;
   case 'personal-sharing-browse':case 'association-source-browse':case 'inventory-source-browse':case 'inventory-piece-draft':break;
   case 'inventory-individual-draft':break;
   case 'inventory-loan-draft':break;
   case 'inventory-loan-stock-browse':break;
   case 'inventory-window-check':break;
   case 'series-preview':$seriesPreview=$registry->previewAppointmentSeries(scalarFields($_POST,['title','start','end','location','description','category','activity_state','activity_kind','report_summary','source_reference']),$calendarPeopleState['selected']??arrayInput($_POST,'attendees'),$seriesRuleInput,input($_POST,'series_from')?:null);break;
   case 'series-save':$seriesResult=$registry->changeAppointmentSeries(input($_POST,'series_id'),(int)input($_POST,'version'),input($_POST,'series_from'),scalarFields($_POST,['title','start','end','location','description','category','activity_state','activity_kind','report_summary','source_reference']),$calendarPeopleState['selected']??arrayInput($_POST,'attendees'),$seriesRuleInput,input($_POST,'series_key'),false,'',input($_POST,'series_revive')==='1');$_SESSION['notice']=$calendarFormat('{updated} Standardtermine gespeichert; {preserved} Termine unverändert oder geschützt.',['updated'=>$seriesResult['updated'],'preserved'=>$seriesResult['preserved']]);redirectTo(['view'=>'calendar','series'=>$seriesResult['id']]);
   case 'series-cancel':$seriesResult=$registry->changeAppointmentSeries(input($_POST,'series_id'),(int)input($_POST,'version'),input($_POST,'series_from'),[],[],[],input($_POST,'series_key'),true,input($_POST,'reason'));$_SESSION['notice']=$calendarFormat('{updated} künftige Standardtermine abgesagt; Ausnahmen und Rückmeldungen bleiben erhalten.',['updated'=>$seriesResult['updated']]);redirectTo(['view'=>'calendar','series'=>$seriesResult['id']]);
   case 'series-exception':$seriesResult=$registry->protectAppointmentSeriesOccurrence(input($_POST,'series_id'),(int)input($_POST,'version'),(int)input($_POST,'ordinal','-1'),input($_POST,'protect')==='1',input($_POST,'series_key'));$_SESSION['notice']=$calendarText('Ausnahmeschutz gespeichert. Rückmeldungen und vergangene Termine bleiben geschützt.');redirectTo(['view'=>'calendar','series'=>$seriesResult['id'],'series_page'=>input($_GET,'series_page','1')]);
   case 'calendar-recipient-browse':case 'calendar-people-browse':case 'contact-choice-browse':case 'admission-choice-browse':break;
   case 'native-module':if(!isset($nativeComposeState))throw new DomainException('Unbekannte Aktion.');break;
   case 'document-workflow-document-compose':break; // Whole unsaved register intention remains in this response only.
   case 'document-workflow-template-compose':break; // Field operations keep the complete intention in this response only.
   case 'document-workflow-mail-preview':break; // Preview is handled above; it does not mutate or send.
   case 'document-check-save':require __DIR__.'/../src/Documents/document-check-actions.php';break;
   case 'contribution-mail-policy':
    if($view!=='finance'||input($_GET,'tab')!=='contribution-mails')throw new DomainException('Versandeinstellung im Beitragsbereich öffnen.',422);
    $policyVersion=input($_POST,'policy_version');if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$policyVersion)||!in_array($_POST['automatic']??'0',['0','1'],true))throw new DomainException('Versandeinstellung prüfen.',422);
    (new \Kulturhus\Verein\Finance\Contributions\ContributionMailPolicy($db,(string)$_SESSION['actor']))->save(($_POST['automatic']??'0')==='1',(int)$policyVersion);$_SESSION['notice']='Versandeinstellung gespeichert.';redirectTo(['view'=>'finance','tab'=>'contribution-mails']);
   case 'contribution-run':
    $run=(new \Kulturhus\Verein\ContributionReminders($db,(string)$_SESSION['actor']))->run((new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),true);$_SESSION['notice']=$run['created'].' Beiträge angelegt, '.$run['queued'].' Nachrichten vorgemerkt. Lokal wurde keine E-Mail versendet.'.($run['errors']?' '.count($run['errors']).' Vorgänge konnten nicht vorbereitet werden. Bitte zurückgestellte Beiträge prüfen.':'');redirectTo(['view'=>'finance','tab'=>'contribution-mails']);
   case 'contribution-invoice':
    if(!$finance->canWrite())throw new DomainException('Finanzbearbeitung für Beitragsrechnungen erforderlich.',403);
    $contributionInvoiceSource=\Kulturhus\Verein\Web\ContributionInvoiceForm::target($_POST,$_GET);
    if($finance->entry($contributionInvoiceSource)['id']!==$contributionInvoiceSource)throw new DomainException('Beitragsrechnung am ursprünglich geöffneten Beleg erneut prüfen.',409);
    $issued=(new \Kulturhus\Verein\ContributionInvoices($db,(string)$_SESSION['actor']))->issue($contributionInvoiceSource);$_SESSION['notice']=$issued['state']==='issued'?'Beitragsrechnung erstellt.':'Rechnungsentwurf gespeichert. Fehlende Angaben bitte ergänzen.';redirectTo(['view'=>'documents','doc'=>$issued['document_id']]);
   case 'minutes-save':
    $minuteCommand=\Kulturhus\Verein\Web\MinutesForm::command($_GET,$_POST);$minuteService=new \Kulturhus\Verein\Minutes($db,(string)$_SESSION['actor']);
    if(!$minuteService->canWrite())throw new DomainException('Keine ausreichende Berechtigung für Protokolle.',403);
    if($minuteCommand['retry_original']&&!$minuteCommand['retry_confirmed'])throw new DomainException('Die ursprüngliche Protokollaktion ausdrücklich bestätigen.',422);
    if(array_diff(array_keys($_FILES),['minute_pdf']))throw new DomainException('Die ursprüngliche PDF-Auswahl vollständig prüfen.',422);
    $upload=null;
    if(array_key_exists('minute_pdf',$_FILES)){
     $file=$_FILES['minute_pdf'];if(!is_array($file)||!is_int($file['error']??null))throw new DomainException('Die ursprüngliche PDF-Auswahl vollständig prüfen.',422);
     if($file['error']!==UPLOAD_ERR_NO_FILE){
      if($file['error']!==UPLOAD_ERR_OK||!is_string($file['name']??null)||!is_string($file['tmp_name']??null)||!is_int($file['size']??null)||$file['size']>10485760||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name']))throw new DomainException('Bitte eine PDF-Datei bis 10 MB auswählen.',422);
      $minuteBytes=file_get_contents($file['tmp_name']);if(!is_string($minuteBytes))throw new DomainException('Bitte die ursprünglich ausgewählte PDF erneut auswählen.',422);$upload=['name'=>$file['name'],'bytes'=>$minuteBytes];
     }
    }
    if($minuteCommand['data']['source_kind']==='pdf'&&$minuteCommand['upload_required']&&$upload===null)throw new DomainException('Bitte die ursprünglich ausgewählte PDF erneut auswählen.',422);
    $mid=$minuteService->save($minuteCommand['id'],$minuteCommand['version'],$minuteCommand['data'],$minuteCommand['request_key'],$upload);
    $_SESSION['notice']='Protokoll als Entwurf gespeichert. Du kannst weiterschreiben oder die Vorschau prüfen.';redirectTo(['view'=>'minutes','mid'=>$mid]+($minuteCommand['continue_edit']?['compose'=>'1']:[]));
   case 'minutes-transition':
    $minuteCommand=\Kulturhus\Verein\Web\MinutesForm::command($_GET,$_POST);$minuteService=new \Kulturhus\Verein\Minutes($db,(string)$_SESSION['actor']);
    if(!$minuteService->canWrite())throw new DomainException('Keine ausreichende Berechtigung für Protokolle.',403);
    if(!$minuteCommand['confirmed'])throw new DomainException('Die ursprüngliche Protokollaktion ausdrücklich bestätigen.',422);
    $minuteService->transition($minuteCommand['id'],$minuteCommand['version'],$minuteCommand['transition']);$_SESSION['notice']='Protokollstatus aktualisiert.';redirectTo(['view'=>'minutes','mid'=>$minuteCommand['id']]);
   case 'free-bulk':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$changed=$free->bulk(input($_POST,'sheet'),arrayInput($_POST,'selected'),input($_POST,'bulk_mode'),input($_POST,'request_key'));$_SESSION['notice']=$changed.' Zeilen verarbeitet.';redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
   case 'free-duplicate':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$copy=$free->duplicate(input($_POST,'sheet'),input($_POST,'title'),input($_POST,'request_key'));redirectTo(['view'=>'free','sheet'=>$copy]);
   case 'free-configure':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$free->configure(input($_POST,'sheet'),input($_POST,'title'),arrayInput($_POST,'column_names'),arrayInput($_POST,'column_types'),(int)input($_POST,'version'));redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
   case 'identity-superuser':if(!$nativeManagerEnabled)throw new DomainException('Persönliche native Anmeldung erforderlich.');{(new \Kulturhus\Native\Integration\AccountIdentity($db,$nativeBridge->access(),$nativeAuth,\Kulturhus\Native\Shared\SecretBox::fromFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'))))->setSuperuser(input($_POST,'account_id'),(int)input($_POST,'version'),input($_POST,'enabled')==='1',input($_POST,'confirmed')==='1');}$_SESSION['notice']='Zentrales Superuser-Recht für Website und Vereinsmanager gespeichert. Persönliche Vorstandsstimmen bleiben separat.';$identityTarget=(new \Kulturhus\Verein\RoleCatalogue($db,(string)$_SESSION['actor']))->accountForNativeAccount(input($_POST,'account_id'));redirectTo(['view'=>'settings','tab'=>'roles','role_user'=>$identityTarget['id']]);
   case 'free-import-preview':if(isset($_FILES['csv_file'])&&($_FILES['csv_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$uploadCsv=$_FILES['csv_file'];if(($uploadCsv['error']??-1)!==UPLOAD_ERR_OK||!\Kulturhus\Verein\LiveUploads::file($uploadCsv['tmp_name'])||($uploadCsv['size']??0)>1000000)throw new DomainException('CSV-Datei bis 1 MB auswählen.');$_POST['csv']=(string)file_get_contents($uploadCsv['tmp_name']);}$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$freeImport=$free->importPreview(input($_POST,'sheet'),input($_POST,'csv'));break;
   case 'free-import':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$added=$free->import(input($_POST,'sheet'),input($_POST,'csv'),input($_POST,'preview_hash'));$_SESSION['notice']=$added.' '.$freeInterfaceText('neue Zeilen importiert. Wiederholungen derselben Datei werden übersprungen.');redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
   case 'free-create':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$sheet=$free->create(input($_POST,'title'),arrayInput($_POST,'column_names'),arrayInput($_POST,'column_types'));redirectTo(['view'=>'free','sheet'=>$sheet]);
   case 'donation-search':\Kulturhus\Verein\Web\DonationFormState::target($_POST,$_GET);break;
   case 'donation-compare':case 'donation-adopt':
    $donationFormResult=\Kulturhus\Verein\Web\DonationFormState::handle($db,(string)$_SESSION['actor'],$_POST,$_GET);$notice=$donationFormResult['notice'];break;
   case 'donation-preview':
    \Kulturhus\Verein\Web\DonationFormState::target($_POST,$_GET);
    $draft=(new \Kulturhus\Verein\DonationForms($db,(string)$_SESSION['actor']))->build($_POST);$bytes=\Kulturhus\Verein\DocumentPreview::pdf($draft);header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="entwurf-zuwendungsbestaetigung.pdf"');echo $bytes;exit;
   case 'donation-issue':
    \Kulturhus\Verein\Web\DonationFormState::target($_POST,$_GET);
    $documentId=(new \Kulturhus\Verein\DonationReceipts($db,(string)$_SESSION['actor']))->issue($_POST,input($_POST,'request_key'));$_SESSION['notice']='Zuwendungsbescheinigung gespeichert. Bitte ausdrucken, eigenhändig unterschreiben und das unterschriebene Doppel aufbewahren.';redirectTo(['view'=>'documents','doc'=>$documentId]);
   case 'donation-cancel':
    (new \Kulturhus\Verein\DonationReceipts($db,(string)$_SESSION['actor']))->cancel(input($_POST,'receipt_id'),input($_POST,'reason'));$_SESSION['notice']='Bescheinigung storniert. Bereits ausgehändigte Exemplare müssen zurückgerufen werden.';redirectTo(['view'=>'settings','tab'=>'donation-forms','kind'=>input($_POST,'kind'),'source_id'=>input($_POST,'source_id')]);
   case 'donation-save':
    \Kulturhus\Verein\Web\DonationFormState::target($_POST,$_GET);
    $documentId=(new \Kulturhus\Verein\DonationForms($db,(string)$_SESSION['actor']))->save($_POST,input($_POST,'request_key'));$_SESSION['notice']='Entwurf des amtlichen Formulars in der Dokumentablage gespeichert.';redirectTo(['view'=>'documents','doc'=>$documentId]);
   case 'free-row':
    $free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);
    if(input($_POST,'row_operation')==='delete'){
     if(input($_POST,'confirmed')!=='1')throw new DomainException('Löschen der Zeile bitte bestätigen.');
     $free->bulk(input($_POST,'sheet'),[input($_POST,'row').':'.input($_POST,'version')],'archive',input($_POST,'request_key'));redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
    }
    try{$rowId=$free->saveRow(input($_POST,'sheet'),input($_POST,'row'),(int)input($_POST,'version'),arrayInput($_POST,'cells'),input($_POST,'request_key'));}
    catch(DomainException $ex){if(input($_POST,'autosave')!=='1')throw $ex;http_response_code(409);header('Content-Type: application/json');echo json_encode(['error'=>\Kulturhus\Native\Language\FreeListMessages::failure($ex,$freeInterfaceText)]);exit;}
    if(input($_POST,'autosave')==='1'){header('Content-Type: application/json');echo json_encode(['row'=>$rowId,'version'=>(int)input($_POST,'version')+1,'calculations'=>$free->displayedRows(input($_POST,'sheet'),array_values(array_unique([...arrayInput($_POST,'shown_rows'),$rowId])))]);exit;}
    redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
   case 'free-delete':
    if(input($_POST,'confirmed')!=='1')throw new DomainException('Löschen der Zeile bitte bestätigen.');
    $free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$free->bulk(input($_POST,'sheet'),[input($_POST,'row').':'.input($_POST,'version')],'archive',input($_POST,'request_key'));
    redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
   case 'free-share':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$free->share(input($_POST,'sheet'),input($_POST,'target_actor'),input($_POST,'access'),(int)input($_POST,'version'));redirectTo(['view'=>'free','sheet'=>input($_POST,'sheet')]);
   case 'free-archive':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$free->archive(input($_POST,'sheet'),(int)input($_POST,'version'),input($_POST,'archived')==='1');redirectTo(['view'=>'free']);
   case 'free-export':$free=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);$csv=$free->export(input($_POST,'sheet'));header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="kulturhus-freie-liste.csv"');echo $csv;exit;
   case 'identity-browse':
    $nativeAccess->require('accounts','manage');
    break;
   case 'identity-assign':
    if(!\Kulturhus\Verein\BoardAccess::superuser($db,(string)$_SESSION['actor']))throw new DomainException('Superuser-Berechtigung erforderlich.');
    if($nativeManagerEnabled){(new \Kulturhus\Native\Integration\AccountIdentity($db,$nativeBridge->access(),$nativeAuth,\Kulturhus\Native\Shared\SecretBox::fromFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'))))->assign(input($_POST,'account_id'),(int)input($_POST,'version'),(int)input($_POST,'role_version'),input($_POST,'expected_contact'),input($_POST,'role_id')==='__base__'?'':input($_POST,'role_id'),input($_POST,'contact_id'),input($_POST,'enabled')==='1',input($_POST,'confirmed')==='1',input($_POST,'role_id')==='__base__');$_SESSION['notice']='Persönliche Kontozuordnung gespeichert.';$identityTarget=(new \Kulturhus\Verein\RoleCatalogue($db,(string)$_SESSION['actor']))->accountForNativeAccount(input($_POST,'account_id'));redirectTo(['view'=>'settings','tab'=>'roles','role_user'=>$identityTarget['id']]);}
    throw new DomainException('Persönliche native Anmeldung erforderlich.');
   case 'editorial-connection-save':
    if(input($_POST,'module')!==$view)throw new DomainException('Aktion und Redaktionsmodul stimmen nicht überein.');
    require_once __DIR__.'/../src/Communication/EditorialConnectionSettings.php';
    $connectionFields=$_POST['connection_fields']??null;if(!is_array($connectionFields))throw new DomainException('Verbindungseinstellungen vollständig prüfen.');
    (new \Kulturhus\Verein\EditorialConnectionSettings($db,(string)$_SESSION['actor'],input($_POST,'module')))->save($connectionFields,input($_POST,'version'),input($_POST,'request_key'));
    unset($_SESSION['website_brand']);
    $_SESSION['notice']='Verbindungseinstellungen gespeichert.';
    header('Location: '.url(['view'=>input($_POST,'module'),'tab'=>'module-settings','saved'=>'1']),true,303);exit;
   case 'editorial-module-settings':
    $module=input($_POST,'module');if($module!==$view||!isset(\Kulturhus\Verein\EditorialModules::LABELS[$module]))throw new DomainException('Modul und Einstellungen stimmen nicht überein.');
    $data=$_POST['module_preferences']??null;if(!is_array($data))throw new DomainException('Moduleinstellungen vollständig eingeben.');
    $preferenceVersion=filter_var(input($_POST,'version'),FILTER_VALIDATE_INT);if($preferenceVersion===false||$preferenceVersion<0)throw new DomainException('Versionsstand der Moduleinstellungen prüfen.');
    (new \Kulturhus\Verein\EditorialModulePreferences($db,(string)$_SESSION['actor'],$module))->save($data,$preferenceVersion);
    $_SESSION['notice']='Moduleinstellungen gespeichert.';redirectTo(['view'=>$module,'tab'=>'module-settings']);
   case 'website-native':
    $nativeTab=input($_POST,'return_tab');if(!isset($website->areas()[$nativeTab]))throw new DomainException('Website-Bereich prüfen.');
    if(\Kulturhus\Verein\EditorialModules::owner($nativeTab)!==$view)throw new DomainException('Aktion und Redaktionsmodul stimmen nicht überein.');
    $nativeOperation=input($_POST,'operation');$nativeWorkspace=new \Kulturhus\Verein\EditorialModuleWorkspace($db,(string)$_SESSION['actor'],\Kulturhus\Verein\EditorialModules::owner($nativeTab));$nativeFields=$_POST['fields']??[];if(!is_array($nativeFields))throw new DomainException('Website-Formular prüfen.');
    if(in_array($nativeOperation,['image/upload','newsletter/image'],true)){
     $upload=$_FILES['image']??null;
     if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!\Kulturhus\Verein\LiveUploads::file($upload['tmp_name'])||($upload['size']??0)>8000000)throw new DomainException('Bitte ein Bild bis 8 MB auswählen.');
     $nativeFields['filename']=basename((string)$upload['name']);$nativeFields['content']=base64_encode((string)file_get_contents($upload['tmp_name']));
    }
      $nativeSaved=$nativeWorkspace->editorialWrite($nativeOperation,$nativeFields,input($_POST,'version'),input($_POST,'request_key'));
      if(str_starts_with($nativeOperation,'image/')&&input($_POST,'response_format')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+$nativeSaved,JSON_THROW_ON_ERROR);exit;}
      if($nativeOperation==='newsletter/image'&&input($_POST,'response_format')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'url'=>$nativeSaved['image_url'],'message'=>$nativeSaved['message']],JSON_THROW_ON_ERROR);exit;}
    $_SESSION['notice']=$nativeSaved['message']??'Website gespeichert.';
    if(isset($nativeSaved['draft']))$_SESSION['website_press_draft']=$nativeSaved['draft'];
    if(isset($nativeSaved['image_url']))$_SESSION['website_uploaded_image']=$nativeSaved['image_url'];
    $nativeItem=(int)input($_POST,'return_item');if(isset($nativeSaved['id'])&&in_array($nativeOperation,['page/save','news/save','gallery/save','newsletter/save','press/draft-save','press/campaign-send'],true))$nativeItem=(int)$nativeSaved['id'];
    if(in_array($nativeOperation,['news/trash','gallery/trash','newsletter/delete'],true))$nativeItem=0;
    if(in_array($nativeOperation,['press/save','press/remove'],true))redirectTo(['view'=>'website','tab'=>'press','press_view'=>'recipients']);
    if($nativeOperation==='press/prepare')redirectTo(['view'=>'website','tab'=>'press','press_new'=>'1']);
    if($nativeOperation==='press/draft-save')unset($_SESSION['website_press_draft']);
    redirectTo(array_filter(['view'=>'website','tab'=>$nativeTab,'item'=>$nativeItem? (string)$nativeItem:null,'period'=>$nativeOperation==='events/archive-import'?'archive':null]));
   case 'website-local-save':throw new DomainException('Bitte die native Website-Verwaltung verwenden.');
   case 'website-save':$draftId=$website->save(input($_POST,'draft_id')?:null,(int)input($_POST,'version'),scalarFields($_POST,['kind','page_key','title','summary','body','status']));$_SESSION['notice']='Textentwurf gespeichert. Er bleibt privat bis zur Veröffentlichung.';redirectTo(['view'=>'website','tab'=>input($_POST,'kind')==='page'?'pages':'news','draft'=>$draftId]);
   case 'settings-preview-rendered':break;
   case 'complete-intro':$intake->completeIntro();$_SESSION['notice']='Einführung als gelesen gespeichert.';redirectTo(['view'=>'dashboard','setup'=>'setup']);
   case 'save-application-settings':throw new DomainException('Einrichtung im ursprünglich geöffneten Bereich prüfen.',422);
   case 'review-application':\Kulturhus\Verein\Web\ApplicationReviewForm::save($intake,$_GET,$_POST);$_SESSION['notice']='Antragsentscheidung gespeichert.';redirectTo(['view'=>'portal','tab'=>'application','application'=>$id,'status'=>input($_GET,'status','pending'),'applications_q'=>input($_GET,'applications_q'),'applications_page'=>input($_GET,'applications_page','1')]);
   case 'set-favourite':$occasionCommand=\Kulturhus\Verein\Web\OccasionForm::favourite($_GET,$_POST);$registry->setFavourite($occasionCommand['id'],$occasionCommand['enabled']);$_SESSION['notice']=$occasionCommand['enabled']?'Als persönlicher Favorit gespeichert.':'Aus deinen Favoriten entfernt.';redirectTo($occasionCommand['route']);
   case 'save-anniversary-rules':$occasionRuleDraft=\Kulturhus\Verein\Web\OccasionForm::draft($_GET,$_POST);if($occasionRuleDraft['editing']){if(!$canWrite)throw new DomainException('Mitglieder-Schreibrecht erforderlich.',403);break;}$rules=\Kulturhus\Verein\Web\OccasionForm::rules($occasionRuleDraft);$registry->saveAnniversaryRules($rules,(int)$occasionRuleDraft['version']);$_SESSION['notice']='Jubiläumsregeln gespeichert.';redirectTo(['view'=>'members-extra','tab'=>'anniversaries']);
   case 'save-tax-account':
    $taxCatalogueCommand=\Kulturhus\Verein\Web\TaxCatalogueForm::command($_GET,$_POST);
    $finance->saveTaxAccount($taxCatalogueCommand['code'],$taxCatalogueCommand['name'],$taxCatalogueCommand['active'],$taxCatalogueCommand['version'],$taxCatalogueCommand['create_only']);
    $_SESSION['notice']='Steuerkonto gespeichert. Bestehende Belegpositionen bleiben erhalten.';redirectTo(['view'=>'settings','tab'=>'tax-accounts','q'=>trim($taxCatalogueCommand['code']),'active'=>'']);
   case 'update-positions':
    [$positionType,$positionId,$positionVersion]=\Kulturhus\Verein\Web\PositionEditForm::target($_POST,$_GET);
    $finance->updatePositions($positionType,$positionId,$positionVersion,Finance::positionsFromForm($_POST),input($_POST,'reason'));$_SESSION['notice']='Positionen und Kontierung gespeichert. Belegbetrag und Zahlungen bleiben unverändert.';
    redirectTo($positionType==='goods'?['view'=>'donations','tab'=>'goods','goods'=>$positionId]+scalarFields($_GET,['goods_page','goods_q','goods_origin','goods_evidence','goods_sort','goods_columns']):['view'=>'finance','tab'=>'receipts','entry'=>$positionId]+scalarFields($_GET,['kind','status','from','to','q','account','direction','sort','order','page']));
    case 'create-account':\Kulturhus\Verein\Web\AccountCreateForm::assertInput($finance,$_POST,$_GET);$requestedKind=\Kulturhus\Verein\Web\AccountCreateForm::kind($_POST['kind']);$createdAccount=$finance->createAccount(input($_POST,'name'),$requestedKind==='cash'?'cash':'offline',input($_POST,'opening'),input($_POST,'opened_on'));$_SESSION['notice']=$requestedKind==='offline-online'?'Bankkonto angelegt. Jetzt kann die Onlineverbindung eingerichtet werden.':'Konto in der Vereinsbuchführung angelegt.';redirectTo($requestedKind==='offline-online'?['view'=>'finance','tab'=>'bank','account'=>$createdAccount,'fints'=>1]:['view'=>'finance','tab'=>'bank','account'=>$createdAccount]);
   case 'delete-account':$finance->deleteAccount(input($_POST,'account'),input($_POST,'confirm_name'));$_SESSION['notice']='Konto und seine Testumsätze wurden endgültig gelöscht. Belege und Prüfverlauf bleiben erhalten.';redirectTo(['view'=>'finance','tab'=>'bank']);
   case 'invoice-approval-browse':case 'position-edit-browse':case 'outgoing-hybrid-browse':case 'outgoing-xinvoice-browse':break;
   case 'create-entry':
    if(\Kulturhus\Verein\Web\InvoiceDraftRead::requested($_POST)){
      $invoiceDraftReadState=\Kulturhus\Verein\Web\InvoiceDraftRead::handle($db,(string)$_SESSION['actor'],$_GET,$_POST);
      $checkBrowseDraft=true;http_response_code($invoiceDraftReadState['status']);
      if($invoiceDraftReadState['state']==='ready')$_POST=$invoiceDraftReadState['post'];
      break;
    }

    if(array_key_exists('manual_move',$_POST)&&input($_POST,'inbox_guided')!=='1'){\Kulturhus\Verein\Web\ManualFinanceForm::edit($db,(string)$_SESSION['actor'],$_POST);$checkBrowseDraft=true;$notice='Belegangaben und Positionen angezeigt. Noch kein Beleg und keine Zahlung gespeichert.';break;}
    if(input($_POST,'inbox_guided')==='1'){
     $previousInvoiceOutcome=\Kulturhus\Verein\Web\InvoiceDraftRead::previousOutcome($db,(string)$_SESSION['actor'],$_GET,$_POST);
     if($previousInvoiceOutcome!==null&&in_array($previousInvoiceOutcome['state'],['saved','conflict'],true)){$invoiceDraftReadState=$previousInvoiceOutcome;$checkBrowseDraft=true;http_response_code($previousInvoiceOutcome['status']);break;}
     $invoiceEntryCandidate=$previousInvoiceOutcome??\Kulturhus\Verein\Web\InvoiceDraftRead::inspect($db,(string)$_SESSION['actor'],$_GET,$_POST);
     if($invoiceEntryCandidate['state']!=='ready'){$invoiceDraftReadState=$invoiceEntryCandidate;$invoiceDraftReadState['legacy_failure']=true;$checkBrowseDraft=true;if($invoiceEntryCandidate['status']===403||$invoiceEntryCandidate['source']===null)http_response_code($invoiceEntryCandidate['status']);break;}
     $entryId=(new \Kulturhus\Verein\InvoiceInbox($db,(string)$_SESSION['actor']))->create(input($_POST,'inbox_doc'),(int)input($_POST,'inbox_version'),scalarFields($_POST,['description','counterparty','amount','document_date','due_date','category','notes','supplier_contact','supplier_version','confirm_other_invoice','payment_source','reimbursement_contact','invoice_recipient','classification_area','classification_event_kind','classification_asset_kind','classification_special','classification_input_tax','classification_vat_rate'])+['item_refs'=>$_POST['item_refs']??[],'item_positions'=>$_POST['item_positions']??[],'use_invoice_positions'=>input($_POST,'use_invoice_positions'),'positions_reviewed'=>input($_POST,'positions_reviewed'),'positions_rounding_reviewed'=>input($_POST,'positions_rounding_reviewed'),'positions_tax_total'=>input($_POST,'positions_tax_total'),'invoice_positions'=>$_POST['invoice_positions']??[]],input($_POST,'request_key'),input($_POST,'purpose'),input($_POST,'supplier_reference'),$registry);
     $_SESSION['notice']='Rechnung gespeichert. Original, Kontierung und Positionen sind im Beleg erhalten. Jetzt den Prüf- und Freigabeschritt öffnen.';
     redirectTo(['view'=>'finance','tab'=>'receipts','entry'=>$entryId]);
    }
    $recordedReceiptSource=input($_POST,'target_transaction')!==''?$finance->transactionDetail(input($_POST,'target_transaction')):null;if($recordedReceiptSource&&(($recordedReceiptSource['cents']<0)!==(input($_POST,'kind')==='expense')))throw new DomainException('Belegart und Zahlungsrichtung des vorhandenen Umsatzes müssen zusammenpassen.');
    require_once __DIR__.'/../src/Finance/Accounting/ManualFinanceAttachment.php';$manualReceipt=\Kulturhus\Verein\ManualFinanceAttachment::request($_FILES,input($_POST,'inbox_doc'));$paymentSource=input($_POST,'payment_source','none');$reimbursementContact=input($_POST,'reimbursement_contact');$invoiceRecipient=input($_POST,'invoice_recipient');$entryData=scalarFields($_POST,['kind','description','counterparty','amount','document_date','due_date','category','notes','payment_source','reimbursement_contact','invoice_recipient'])+['positions'=>Finance::positionsFromForm($_POST)];if($paymentSource==='private_advance'&&($reimbursementContact===''||!isset(\Kulturhus\Verein\ExpenseReimbursements::RECIPIENTS[$invoiceRecipient])))throw new DomainException('Für eine private Vorleistung Mitglied und Rechnungsempfänger auswählen.');$entryId=$finance->createEntry($entryData,input($_POST,'request_key'),function($created)use($db,$registry,$manualReceipt,$paymentSource,$reimbursementContact,$invoiceRecipient,$recordedReceiptSource){if($recordedReceiptSource){$sourceFinance=new Finance($db,(string)$_SESSION['actor']);if(!$sourceFinance->canWrite())throw new DomainException('Aktuelles Finanz-Bearbeitungsrecht für den Beleg erforderlich.');$sourceFinance->transactionDetail($recordedReceiptSource['id'],true);}if($paymentSource==='private_advance')(new \Kulturhus\Verein\ExpenseReimbursements($db,(string)$_SESSION['actor']))->createForEntry($created,$reimbursementContact,$invoiceRecipient,input($_POST,'request_key'));if($manualReceipt)\Kulturhus\Verein\ManualFinanceAttachment::store($db,(string)$_SESSION['actor'],$created,$manualReceipt,input($_POST,'request_key'));if(input($_POST,'gema_event')!=='')(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->linkInvoice(input($_POST,'gema_event'),(int)input($_POST,'gema_version'),$created);if(input($_POST,'inbox_doc')!=='')(new \Kulturhus\Verein\Documents($db,(string)$_SESSION['actor']))->linkExpense(input($_POST,'inbox_doc'),(int)input($_POST,'inbox_version'),$created);});$_SESSION['notice']=$recordedReceiptSource?'Beleg erfasst. Vorhandenen Kontoumsatz prüfen und zuordnen; es wurde keine weitere Zahlung erfasst.':'Beleg erfasst. Vorhandene Transaktion auswählen oder eine neue Zahlung erfassen.';redirectTo($recordedReceiptSource?array_replace(receiptPaymentContext($entryId,$_GET),['candidate'=>$recordedReceiptSource['id'],'match_account'=>$recordedReceiptSource['account_id']]):['view'=>'finance','tab'=>'receipts','entry'=>$entryId,'account'=>input($_POST,'target_account'),'candidate'=>'']);
   case 'payment-tags-assign':case 'payment-tags-save':
    $paymentTagResult=\Kulturhus\Verein\Web\PaymentTagForm::handle(new \Kulturhus\Verein\PaymentTags($db,(string)$_SESSION['actor']),$_GET,$_POST);
    if($paymentTagResult!==null)redirectTo(bankTransactionContext(input($_POST,'transaction_id'),$_GET)+['tag_receipt'=>$paymentTagResult['event']['id'],'_anchor'=>'payment-tags']);
    $paymentTagsCompared=true;break;
   case 'create-transaction':$tx=$finance->createTransaction(scalarFields($_POST,['account','booked_on','direction','amount','counterparty','description']),input($_POST,'request_key'));$_SESSION['notice']='Kontotransaktion erfasst und auf eindeutige Zuordnung geprüft. Offene Beträge bitte manuell zuordnen.';redirectTo(['view'=>'finance','tab'=>'bank','transaction'=>$tx]);
   case 'auto-match':if(!is_array($_POST['transactions']??null))throw new DomainException('Umsatzseite neu öffnen und ihre Auswahl prüfen.');$matched=$finance->matchRecordedTransactions(input($_POST,'account'),$_POST['transactions']);$_SESSION['notice']=$matched.' Umsätze dieser Seite eindeutig zugeordnet. Übrige Umsätze bitte manuell prüfen.';redirectTo(bankListContext($_GET));
   case 'donation-contact-review':case 'donation-contact-adopt':
    $donorContactState=\Kulturhus\Verein\Web\DonationContactState::review($db,(string)$_SESSION['actor'],$_POST,$_GET,$action==='donation-contact-adopt');break;
   case 'donation-contact':\Kulturhus\Verein\Web\DonationContactState::target($_POST,$_GET);\Kulturhus\Verein\Web\DonationContactState::fields($_POST);$source=input($_POST,'source_type');$finance->assignDonationContact($source,input($_POST,'source_id'),(int)input($_POST,'source_version'),input($_POST,'contact_id'));redirectTo($source==='goods'?['view'=>'donations','tab'=>'goods','goods'=>input($_POST,'source_id')]+scalarFields($_GET,['goods_page','goods_q','goods_origin','goods_evidence','goods_sort','goods_columns']):['view'=>'finance','tab'=>'receipts','entry'=>input($_POST,'source_id')]);
   case 'internal-link':if(array_key_exists('match_move',$_POST)){redirectTo(\Kulturhus\Verein\Web\FinanceMatchDraft::navigate($_SESSION,$db,(string)$_SESSION['actor'],\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET),$_POST,$_GET));}if(input($_POST,'review_match')==='1'){$financeMatchReviewState=\Kulturhus\Verein\Web\FinanceMatchReview::read($db,(string)$_SESSION['actor'],$_POST,\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET));break;}$finance->linkInternalMovement(input($_POST,'transaction_id'),(int)input($_POST,'transaction_version'),input($_POST,'other_id'),(int)input($_POST,'other_version'),input($_POST,'amount'),input($_POST,'reason'),input($_POST,'request_key'));\Kulturhus\Verein\Web\FinanceMatchDraft::finished($_SESSION,$db,(string)$_SESSION['actor'],$_POST);$_SESSION['notice']='Geldtransfer zugeordnet. Beide Kontoumsätze bleiben erhalten; keine Überweisung ausgelöst.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'internal-cash':if(array_key_exists('match_move',$_POST)){redirectTo(\Kulturhus\Verein\Web\FinanceMatchDraft::navigate($_SESSION,$db,(string)$_SESSION['actor'],\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET),$_POST,$_GET));}if(input($_POST,'review_match')==='1'){$financeMatchReviewState=\Kulturhus\Verein\Web\FinanceMatchReview::read($db,(string)$_SESSION['actor'],$_POST,\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET));break;}if(input($_POST,'cash_confirm')!=='1')throw new DomainException('Tatsächlichen Bargeldzugang oder die Bargeldabgabe bestätigen.');$finance->recordCashMovement(input($_POST,'transaction_id'),(int)input($_POST,'transaction_version'),input($_POST,'cash_account'),input($_POST,'cash_date'),input($_POST,'amount'),input($_POST,'reason'),input($_POST,'request_key'));\Kulturhus\Verein\Web\FinanceMatchDraft::finished($_SESSION,$db,(string)$_SESSION['actor'],$_POST);$_SESSION['notice']='Tatsächliche Bargeldbewegung erfasst und zugeordnet. Keine Einnahme oder Ausgabe entstanden.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'internal-release':$finance->releaseInternalMovement(input($_POST,'movement_id'),input($_POST,'reason'));$_SESSION['notice']='Transferzuordnung aufgehoben. Beide ursprünglichen Umsätze bleiben erhalten.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'excess-return':
    if(!$finance->canWrite())throw new DomainException('Aktuelle Finanzschreibberechtigung erforderlich.',403);
    $excessSourceId=input($_POST,'transaction_id');if(input($_GET,'transaction')!==$excessSourceId)throw new DomainException('Ursprünglichen Kontoumsatz mit seinen Angaben erneut öffnen.',409);
    if(input($_POST,'excess_commit')!=='1'){
     $excessMove=input($_POST,'excess_move');if(input($_POST,'choose_return')!=='')$excessMove='choose';elseif(input($_POST,'excess_target_page')!=='')$excessMove='page';
     if(!in_array($excessMove,['search','page','choose','clear','refresh'],true))throw new DomainException('Rückzahlung zuerst prüfen und anschließend gesondert bestätigen.',422);
     if($excessMove==='search')$_POST['excess_page']='1';
     if($excessMove==='page')$_POST['excess_page']=input($_POST,'excess_target_page');
     if($excessMove==='clear'){$_POST['returned_id']='';$_POST['returned_version']='0';$_POST['review_hash']='';unset($_POST['excess_return_confirm']);}
     if($excessMove==='choose'){
      $excessChoice=$finance->excessReturnReview($excessSourceId,input($_POST,'choose_return'));$_POST['returned_id']=input($_POST,'choose_return');$_POST['returned_version']=(string)$excessChoice['review']['returned']['version'];$_POST['transaction_version']=(string)$excessChoice['review']['source']['transaction']['version'];$_POST['review_hash']=$excessChoice['hash'];unset($_POST['excess_return_confirm']);
     }
     if($excessMove==='refresh'){$excessReviewed=true;unset($_POST['excess_return_confirm']);}
     break;
    }
    $excessResult=$finance->linkExcessReturn($excessSourceId,(int)input($_POST,'transaction_version'),input($_POST,'returned_id'),(int)input($_POST,'returned_version'),input($_POST,'amount'),input($_POST,'reason'),input($_POST,'request_key'),input($_POST,'review_hash'),input($_POST,'excess_return_confirm')==='1');
    $_SESSION['notice']=$excessResult['active']?'Vorhandene Überschussrückzahlung zugeordnet. Kontostand und ursprüngliche Forderung bleiben unverändert.':'Der ursprüngliche Rückzahlungsbezug wurde bereits aufgehoben. Gespeicherten Verlauf prüfen.';redirectTo(bankTransactionContext($excessSourceId,$_GET)+['_anchor'=>'excess-return-history']);
   case 'excess-return-release':
    if(input($_GET,'transaction')!==input($_POST,'transaction_id'))throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
    $finance->releaseExcessReturn(input($_POST,'excess_return_id'),(int)input($_POST,'version'),input($_POST,'reason'));$_SESSION['notice']='Rückzahlungsbezug aufgehoben. Beide ursprünglichen Kontoumsätze und der Beleg bleiben erhalten.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),$_GET)+['_anchor'=>'excess-return-history']);
   case 'finance-match-discard':$financeMatchDiscardRoute=\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET);\Kulturhus\Verein\Web\FinanceMatchDraft::discard($_SESSION,$db,(string)$_SESSION['actor'],$financeMatchDiscardRoute,$_POST['match_draft']??null);$financeMatchDiscardQuery=array_diff_key($_GET,['match_draft'=>true]);redirectTo($financeMatchDiscardRoute['kind']==='transaction'?bankTransactionContext($financeMatchDiscardRoute['id'],$financeMatchDiscardQuery):receiptPaymentContext($financeMatchDiscardRoute['id'],$financeMatchDiscardQuery));
   case 'allocate':if(array_key_exists('match_move',$_POST)){redirectTo(\Kulturhus\Verein\Web\FinanceMatchDraft::navigate($_SESSION,$db,(string)$_SESSION['actor'],\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET),$_POST,$_GET));}if(input($_POST,'review_match')==='1'){$financeMatchReviewState=\Kulturhus\Verein\Web\FinanceMatchReview::read($db,(string)$_SESSION['actor'],$_POST,\Kulturhus\Verein\Web\FinanceMatchDraft::route($_GET));break;}$finance->allocate(input($_POST,'transaction_id'),(int)input($_POST,'transaction_version'),input($_POST,'entry_id'),(int)input($_POST,'entry_version'),input($_POST,'amount'),input($_POST,'request_key'));\Kulturhus\Verein\Web\FinanceMatchDraft::finished($_SESSION,$db,(string)$_SESSION['actor'],$_POST);$_SESSION['notice']='Zuordnung gespeichert. Der Kontostand bleibt unverändert.';redirectTo(input($_POST,'return_to')==='transaction'?bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])):receiptPaymentContext(input($_POST,'entry_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'release-allocation':if(input($_POST,'review_allocation')==='1'){if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);$reviewRows=$finance->allocations(input($_POST,'entry_id'));if(!in_array(input($_POST,'id'),array_column($reviewRows,'id'),true))throw new DomainException('Zuordnung nicht verfügbar.',403);break;}$finance->releaseAllocation($id,(int)input($_POST,'transaction_version'),(int)input($_POST,'entry_version'),input($_POST,'reason'));$_SESSION['notice']='Zuordnung aufgehoben. Die Kontotransaktion bleibt erhalten.';redirectTo(input($_POST,'return_to')==='transaction'?bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])):receiptPaymentContext(input($_POST,'entry_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'reimbursement-approve':case 'reimbursement-create':case 'reimbursement-link':throw new DomainException('Ursprüngliche Ausgabe und Erstattungsformular vollständig öffnen.',422);
   case 'record-payment':$finance->recordPayment($id,(int)input($_POST,'version'),input($_POST,'account'),input($_POST,'paid_on'),array_key_exists('amount',$_POST)?input($_POST,'amount'):null);$_SESSION['notice']='Tatsächliche Zahlung manuell erfasst und der Rechnung zugeordnet. Der Kontostand wurde aktualisiert; es wurde kein Geld überwiesen.';redirectTo(['view'=>'finance','tab'=>'receipts','entry'=>$id]);
   case 'cancel-entry':$finance->cancel($id,(int)input($_POST,'version'));$_SESSION['notice']='Offener Beleg verworfen; der Verlauf bleibt erhalten.';redirectTo(['view'=>'finance','tab'=>'receipts']);
   case 'goods-correction-review':case 'goods-correction-compare':case 'goods-correction-adopt':case 'goods-correction-save':
    $goodsCorrectionReview=\Kulturhus\Verein\Web\GoodsCorrectionState::handle($db,(string)$_SESSION['actor'],$_POST,$_GET);
    if(isset($goodsCorrectionReview['accepted'])){
     $goodsAccepted=$goodsCorrectionReview['accepted'];$_SESSION['notice']=$goodsAccepted['replayed']?'Diese Korrektur wurde bereits gespeichert. Ihr ursprünglicher Stand ist im Verlauf geöffnet; der aktuelle Eintrag steht darüber.':'Sachspendenkorrektur gespeichert. Der vorherige Stand bleibt im Verlauf erhalten.';
     redirectTo(['view'=>'donations','tab'=>'goods','goods'=>input($_POST,'source_id'),'correction_first'=>$goodsAccepted['correction']['id']]+scalarFields($_GET,['goods_page','goods_q','goods_origin','goods_evidence','goods_sort','goods_columns']));
    }
    break;
   case 'create-goods':
    if(!$finance->canWrite())throw new DomainException('Finanzschreibberechtigung erforderlich.',403);
    if(array_key_exists('manual_move',$_POST)){\Kulturhus\Verein\Web\ManualFinanceForm::edit($db,(string)$_SESSION['actor'],$_POST);$goodsPositionNotice='Positionen angezeigt. Noch keine Sachspende gespeichert.';break;}
    \Kulturhus\Verein\Web\GoodsDonationState::validate($_POST);$goodsId=$finance->createGoods(scalarFields($_POST,['donor','description','received_on','amount','valuation','designation','origin','evidence_present'])+['positions'=>Finance::positionsFromForm($_POST)],input($_POST,'request_key'));$goodsAcceptedHistory=$finance->goodsCorrectionHistory($goodsId);$goodsAcceptedRoute=['view'=>'donations','tab'=>'goods','goods'=>$goodsId];if($goodsAcceptedHistory){$_SESSION['notice']='Diese Sachspende war bereits erfasst. Der aktuelle Eintrag und die früheren Stände sind im Verlauf sichtbar.';$goodsAcceptedRoute['correction_first']=$goodsAcceptedHistory[array_key_last($goodsAcceptedHistory)]['id'];}else $_SESSION['notice']='Sachspende erfasst.';redirectTo($goodsAcceptedRoute+scalarFields($_GET,['goods_page','goods_q','goods_origin','goods_evidence','goods_sort','goods_columns']));
   case 'reverse-payment-group':
    if(input($_GET,'transaction')!==input($_POST,'transaction_id'))throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
    if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
    if(input($_POST,'group_commit')!=='1'){
     $groupMove=input($_POST,'group_move');if(input($_POST,'choose_group_counter')!=='')$groupMove='choose';
     if(!in_array($groupMove,['search','page','choose','clear','preview'],true))throw new DomainException('Alle gemeinsamen Belege und die Gegenbewegung zuerst prüfen und anschließend gesondert bestätigen.',422);
     if($groupMove==='search')$_POST['group_counter_page']='1';
     if($groupMove==='clear'){$_POST['group_counter_id']='';$_POST['group_counter_version']='0';$_POST['group_review_hash']='';unset($_POST['group_confirm']);}
     if($groupMove==='choose'){$groupChoice=$finance->groupReversalReview(input($_POST,'transaction_id'),input($_POST,'choose_group_counter'),input($_POST,'group_reason'));$_POST['group_counter_id']=input($_POST,'choose_group_counter');$_POST['group_counter_version']=(string)$groupChoice['review']['returned']['version'];$_POST['group_review_hash']='';unset($_POST['group_confirm']);}
     if($groupMove==='preview'){$finance->groupReversalReview(input($_POST,'transaction_id'),input($_POST,'group_counter_id'),input($_POST,'group_reason'));$groupReversalReviewed=true;unset($_POST['group_confirm']);}
     break;
    }
    $finance->reversePaymentGroup(input($_POST,'transaction_id'),(int)input($_POST,'group_source_version'),input($_POST,'group_counter_id'),(int)input($_POST,'group_counter_version'),input($_POST,'group_reason'),input($_POST,'request_key'),input($_POST,'group_review_hash'),input($_POST,'group_confirm')==='1');$_SESSION['notice']='Gemeinsame vollständige Gegenbuchung gespeichert. Der Kontostand bleibt erhalten.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'link-return-order':
   case 'revoke-return-order':
    if(input($_GET,'transaction')!==input($_POST,'transaction_id'))throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
    $returnOrderUnlink=input($_POST,'action')==='revoke-return-order';
    if(input($_POST,'order_commit')!=='1'){
     if(input($_POST,'order_move')!=='preview')throw new DomainException('Bankauftrag und tatsächliche Banknachweise aktuell prüfen und ihre genaue Zuordnung anschließend gesondert bestätigen.',422);
     if($returnOrderUnlink)$finance->paymentReturnOrderRevocationReview(input($_POST,'transaction_id'),input($_POST,'link_id'),input($_POST,'order_reason'));
     else $finance->paymentReturnOrderReview(input($_POST,'transaction_id'),input($_POST,'return_id'),input($_POST,'order_id'),input($_POST,'order_reason'),input($_POST,'order_evidence'));
     $returnOrderReviewed=true;unset($_POST['order_confirm']);break;
    }
    if($returnOrderUnlink)$finance->revokeReturnedBankOrder(input($_POST,'transaction_id'),input($_POST,'link_id'),input($_POST,'order_reason'),input($_POST,'request_key'),input($_POST,'order_review_hash'),input($_POST,'order_confirm')==='1');
    else $finance->linkReturnedBankOrder(input($_POST,'transaction_id'),input($_POST,'return_id'),input($_POST,'order_id'),input($_POST,'order_reason'),input($_POST,'order_evidence'),input($_POST,'request_key'),input($_POST,'order_review_hash'),input($_POST,'order_confirm')==='1');
    $_SESSION['notice']=$returnOrderUnlink?'Auftragszuordnung zurückgenommen.':'Bankauftrag ausdrücklich zugeordnet. Es wurde keine Zahlung gesendet.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'confirm-transfer-execution':
   case 'revoke-transfer-execution':
    if(input($_GET,'transaction')!==input($_POST,'transaction_id'))throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
    $executionUnlink=input($_POST,'action')==='revoke-transfer-execution';
    if(input($_POST,'execution_commit')==='1'){
     if($executionUnlink)$finance->revokeTransferSettlement(input($_POST,'transaction_id'),input($_POST,'execution_id'),input($_POST,'execution_reason'),input($_POST,'request_key'),input($_POST,'execution_review_hash'),input($_POST,'execution_confirm')==='1');
     else $finance->confirmTransferSettlement(input($_POST,'transaction_id'),input($_POST,'execution_order_id'),Finance::transferSettlementDraftIds($_POST['execution_allocations']??[]),input($_POST,'execution_reason'),input($_POST,'execution_evidence'),input($_POST,'request_key'),input($_POST,'execution_review_hash'),input($_POST,'execution_confirm')==='1');
     $_SESSION['notice']=$executionUnlink?'Ausführungsnachweis zurückgenommen. Der ursprüngliche Bankauftrag bleibt geschützt.':'Ausführung des ursprünglichen Bankauftrags zugeordnet. Weitere Zahlungen benötigen einen eigenen beleggebundenen Auftrag.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
    }
    if(!$finance->canConfirmTransferSettlement())throw new DomainException('Aktuelle bestätigte Zahlungsberechtigung erforderlich.',403);
    $executionMove=input($_POST,'execution_move');if(input($_POST,'choose_execution_order')!=='')$executionMove='order';if(input($_POST,'choose_execution_allocation')!=='')$executionMove='choose';if(input($_POST,'remove_execution_allocation')!=='')$executionMove='remove';
    if(!in_array($executionMove,['orders-search','orders-page','payments-search','payments-page','order','choose','remove','preview'],true)||($executionUnlink&&$executionMove!=='preview'))throw new DomainException('Bankauftrag und ursprüngliche Zahlungen aktuell prüfen und anschließend gesondert bestätigen.',422);
    if($executionUnlink){$finance->transferSettlementRevocationReview(input($_POST,'transaction_id'),input($_POST,'execution_id'),input($_POST,'execution_reason'));$transferExecutionReviewed=true;unset($_POST['execution_confirm']);break;}
    $executionAllocations=Finance::transferSettlementDraftIds($_POST['execution_allocations']??[]);
    if($executionMove==='orders-search')$_POST['execution_order_page']='1';if($executionMove==='payments-search')$_POST['execution_payment_page']='1';
    if($executionMove==='order'){$executionOrder=$finance->transferSettlementOrder(input($_POST,'transaction_id'),input($_POST,'choose_execution_order'));$_POST['execution_order_id']=$executionOrder['order']['id'];$_POST['execution_payment_page']='1';}
    if($executionMove==='remove')$executionAllocations=array_values(array_filter($executionAllocations,static fn(string$id):bool=>$id!==input($_POST,'remove_execution_allocation')));
    if($executionMove==='choose'){$executionAllocation=$finance->transferSettlementAllocationChoice(input($_POST,'transaction_id'),input($_POST,'execution_order_id'),input($_POST,'choose_execution_allocation'));if(!in_array($executionAllocation['allocation']['id'],$executionAllocations,true)){if(count($executionAllocations)>=40)throw new DomainException('Höchstens vierzig ursprüngliche Zahlungszuordnungen auswählen.',422);$executionAllocations[]=$executionAllocation['allocation']['id'];}}
    $_POST['execution_allocations']=$executionAllocations;
    if($executionMove==='preview'){$finance->transferSettlementReview(input($_POST,'transaction_id'),input($_POST,'execution_order_id'),$executionAllocations,input($_POST,'execution_reason'),input($_POST,'execution_evidence'));$transferExecutionReviewed=true;unset($_POST['execution_confirm']);}
    break;
   case 'return-payment-parts':
   case 'revoke-payment-parts':
    if(input($_GET,'transaction')!==input($_POST,'transaction_id'))throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
    $partialUnlink=input($_POST,'action')==='revoke-payment-parts';
    if(input($_POST,'partial_commit')==='1'){
     if($partialUnlink)$finance->revokePaymentParts(input($_POST,'transaction_id'),input($_POST,'partial_return_id'),input($_POST,'partial_reason'),input($_POST,'request_key'),input($_POST,'partial_review_hash'),input($_POST,'partial_confirm')==='1');
     else $finance->returnPaymentParts(input($_POST,'transaction_id'),(int)input($_POST,'partial_counter_version'),Finance::partialReturnDraftAmounts($_POST['partial_amounts']??[]),input($_POST,'partial_reason'),input($_POST,'request_key'),input($_POST,'partial_review_hash'),input($_POST,'partial_confirm')==='1');
     $_SESSION['notice']=$partialUnlink?'Teilrückzahlungszuordnung zurückgenommen. Der ursprüngliche Nachweis bleibt erhalten.':'Vorhandene Rückzahlung zugeordnet. Die ursprünglichen Forderungen bleiben im zurückgezahlten Umfang offen.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
    }
    if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
    $partialMove=input($_POST,'partial_move');if(input($_POST,'choose_partial_allocation')!=='')$partialMove='choose';if(input($_POST,'remove_partial_allocation')!=='')$partialMove='remove';
    if(!in_array($partialMove,['search','page','choose','remove','preview'],true)||($partialUnlink&&$partialMove!=='preview'))throw new DomainException('Ursprüngliche Zahlungen und Teilbeträge aktuell prüfen und anschließend gesondert bestätigen.',422);
    if($partialUnlink){$finance->partialReturnRevocationReview(input($_POST,'transaction_id'),input($_POST,'partial_return_id'),input($_POST,'partial_reason'));$partialReturnReviewed=true;unset($_POST['partial_confirm']);break;}
    $partialAmounts=Finance::partialReturnDraftAmounts($_POST['partial_amounts']??[]);
    if($partialMove==='search')$_POST['partial_page']='1';
    if($partialMove==='remove')$partialAmounts=array_values(array_filter($partialAmounts,static fn(array$row):bool=>$row['allocation_id']!==input($_POST,'remove_partial_allocation')));
    if($partialMove==='choose'){
     $partialChoice=$finance->partialReturnChoice(input($_POST,'transaction_id'),input($_POST,'choose_partial_allocation'));
     if(!in_array($partialChoice['allocation']['id'],array_column($partialAmounts,'allocation_id'),true)){
      if(count($partialAmounts)>=40)throw new DomainException('Höchstens vierzig ursprüngliche Zahlungszuordnungen auswählen.',422);$partialCounter=$finance->transactionDetail(input($_POST,'transaction_id'));$partialDefault=min((int)$partialCounter['remaining_cents'],(int)$partialChoice['refundable_cents']);if($partialDefault<1)throw new DomainException('Diese vorhandene Rückzahlung hat keinen verfügbaren Restbetrag.',409);
      $partialAmounts[]=['allocation_id'=>$partialChoice['allocation']['id'],'amount'=>Finance::decimal($partialDefault)];
     }
    }
    $_POST['partial_amounts']=$partialAmounts;
    if($partialMove==='preview'){$finance->partialReturnReview(input($_POST,'transaction_id'),$partialAmounts,input($_POST,'partial_reason'));$partialReturnReviewed=true;unset($_POST['partial_confirm']);}
    break;
   case 'return-payment':
    if(input($_GET,'transaction')!==input($_POST,'transaction_id'))throw new DomainException('Ursprünglichen Kontoumsatz erneut öffnen.',409);
    if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
    if(input($_POST,'payment_return_commit')!=='1'){
     $paymentReturnMove=input($_POST,'payment_return_move');if(input($_POST,'choose_payment_return_counter')!=='')$paymentReturnMove='choose';
     if(!in_array($paymentReturnMove,['search','page','choose','clear','preview'],true))throw new DomainException('Alle ursprünglichen Forderungen und die vorhandene Rückzahlung zuerst prüfen und anschließend gesondert bestätigen.',422);
     if($paymentReturnMove==='search')$_POST['payment_return_counter_page']='1';
     if($paymentReturnMove==='clear'){$_POST['payment_return_counter_id']='';$_POST['payment_return_counter_version']='0';$_POST['payment_return_review_hash']='';unset($_POST['payment_return_confirm']);}
     if($paymentReturnMove==='choose'){$paymentReturnChoice=$finance->paymentReturnReview(input($_POST,'transaction_id'),input($_POST,'choose_payment_return_counter'),input($_POST,'payment_return_reason'));$_POST['payment_return_counter_id']=input($_POST,'choose_payment_return_counter');$_POST['payment_return_counter_version']=(string)$paymentReturnChoice['review']['returned']['version'];$_POST['payment_return_review_hash']='';unset($_POST['payment_return_confirm']);}
     if($paymentReturnMove==='preview'){$finance->paymentReturnReview(input($_POST,'transaction_id'),input($_POST,'payment_return_counter_id'),input($_POST,'payment_return_reason'));$paymentReturnReviewed=true;unset($_POST['payment_return_confirm']);}
     break;
    }
    $finance->returnPayment(input($_POST,'transaction_id'),(int)input($_POST,'payment_return_source_version'),input($_POST,'payment_return_counter_id'),(int)input($_POST,'payment_return_counter_version'),input($_POST,'payment_return_reason'),input($_POST,'request_key'),input($_POST,'payment_return_review_hash'),input($_POST,'payment_return_confirm')==='1');$_SESSION['notice']='Zurückgekommene Zahlung zugeordnet. Die ursprünglichen Forderungen sind wieder offen. Der Kontostand bleibt erhalten.';redirectTo(bankTransactionContext(input($_POST,'transaction_id'),array_diff_key($_GET,['match_draft'=>true])));
   case 'reverse-payment':
    if(!$finance->canWrite())throw new DomainException('Keine ausreichende Finanzberechtigung.',403);
    $reverseSubmittedOriginal=$_POST;\Kulturhus\Verein\Web\PaymentReversalForm::assertInput($finance,$_POST,$_GET);
    if(input($_POST,'reverse_commit')!=='1'){
     $move=input($_POST,'reverse_move');if(input($_POST,'choose_counter')!=='')$move='choose';
     if(!in_array($move,['refresh','search','page','choose','clear'],true))throw new DomainException('Korrektur zuerst prüfen und anschließend gesondert bestätigen.');
     if($move==='refresh')$reverseReviewed=true;
     if($move==='search')$_POST['counter_page']='1';
     if($move==='clear'){$_POST['counter_id']='';$_POST['counter_version']='0';unset($_POST['new_counter_confirm']);}
     if($move==='choose'){
      $reverseSelectionSource=$finance->reversalSource($id);$reverseSelectionCounter=$finance->transactionDetail(input($_POST,'choose_counter'));$reverseSelectionOriginal=$reverseSelectionSource['original'];
      if(!$reverseSelectionSource['eligible']||!$reverseSelectionOriginal||$reverseSelectionCounter['account_id']!==$reverseSelectionOriginal['account_id']||(int)$reverseSelectionCounter['cents']!==-(int)$reverseSelectionOriginal['cents']||$reverseSelectionCounter['reversal_of']||$reverseSelectionCounter['reversed_on']||(int)$reverseSelectionCounter['allocated_cents']!==0||$reverseSelectionCounter['booked_on']<$reverseSelectionOriginal['booked_on'])throw new DomainException('Passende noch nicht zugeordnete Gegenbewegung dieses Belegs auswählen.');
      $_POST['counter_id']=$reverseSelectionCounter['id'];$_POST['counter_version']=(string)$reverseSelectionCounter['version'];$_POST['reversed_on']=$reverseSelectionCounter['booked_on'];
     }
     if(in_array($move,['search','page'],true))$finance->reversalCandidatesPage($id,$_POST['counter_page']??'1',$_POST['counter_q']??'');
     break;
    }
    if(input($_POST,'counter_id')===''&&input($_POST,'new_counter_confirm')!=='1'&&!$finance->reversalSource($id)['entry']['reversed_on'])throw new DomainException('Weitere tatsächlich erfolgte, noch nicht erfasste Gegenbewegung ausdrücklich bestätigen.');
    $reverseCounterReview=input($_POST,'counter_review');if(input($_POST,'counter_id')===''&&$reverseCounterReview===''&&!$finance->reversalSource($id)['entry']['reversed_on'])throw new DomainException('Aktuellen Korrekturstand und passende Gegenbewegungen zuerst prüfen.',409);$finance->reverse($id,(int)input($_POST,'version'),input($_POST,'reversed_on'),input($_POST,'reason'),input($_POST,'counter_id'),(int)input($_POST,'counter_version'),$reverseCounterReview!==''?$reverseCounterReview:null);$_SESSION['notice']=\Kulturhus\Native\Language\FinanceBankMessages::text(input($_POST,'counter_id')!==''?'Vorhandene Gegenbewegung dem Originalbeleg zugeordnet. Der Kontostand bleibt erhalten.':'Tatsächliche Gegenbewegung mit Originalbezug gespeichert. Es wurde kein Geld überwiesen.',isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[]);redirectTo(receiptPaymentContext($id,$_GET,false));
   case 'close-cashbook':$finance->closeCashbook(input($_POST,'account'),input($_POST,'through'),input($_POST,'counted'));$_SESSION['notice']='Kassenzeitraum abgeschlossen. Rückdatierte Änderungen sind gesperrt.';redirectTo(['view'=>'finance','tab'=>'bank','account'=>input($_POST,'account')]);
   case 'cash-export':$csv=$finance->exportCashbook(input($_POST,'account'),input($_POST,'from'),input($_POST,'to'));documentDownload($documents,'kulturhus-kassenbuch.csv',$csv,['title'=>'Kassenbuch '.input($_POST,'from').' – '.input($_POST,'to'),'kind'=>'export','scope'=>'finance','links'=>['account:'.input($_POST,'account')]],scalarFields($_POST,['account','from','to']));
   case 'member-import-upload':case 'member-import-preview':case 'member-import-apply':case 'member-import-discard':case 'member-import-bank-begin':case 'member-import-bank-confirm':case 'member-excel-export':case 'member-excel-bank-begin':case 'member-excel-bank-confirm':case 'member-excel-bank-download':case 'member-excel-bank-cancel':require __DIR__.'/../src/People/Members/MemberImportActions.php';break;
   case 'datev-preview':require_once __DIR__.'/../src/Finance/Accounting/DatevExport.php';$datevInput=\Kulturhus\Verein\Web\FinanceExportForm::datev($_POST);$datevCheck=(new \Kulturhus\Verein\DatevExport($db,(string)$_SESSION['actor']))->preview($datevInput);$_SESSION['datev_export']=['actor'=>$_SESSION['actor'],'options'=>$datevCheck['options'],'hash'=>$datevCheck['hash']];redirectTo(['view'=>'finance','tab'=>'export','datev'=>1]);
   case 'datev-download':require_once __DIR__.'/../src/Finance/Accounting/DatevExport.php';$datevSaved=$_SESSION['datev_export']??[];if(($datevSaved['actor']??'')!==$_SESSION['actor'])throw new DomainException('Zuerst eine DATEV-Vorschau erstellen.');$bytes=(new \Kulturhus\Verein\DatevExport($db,(string)$_SESSION['actor']))->download($datevSaved['options'],input($_POST,'preview_hash'));header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="EXTF_Kulturhus_Zahlungen.csv"');echo $bytes;exit;
   case 'finance-bundle':$bundle=(new \Kulturhus\Verein\FinanceBundle($db,(string)$_SESSION['actor']))->build(\Kulturhus\Verein\Web\FinanceExportForm::documents($_POST,$view==='finance'&&($_GET['tab']??null)==='export'));header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="kulturhus-belegpaket.zip"');header('X-Kulturhus-Missing-Files: '.$bundle['missing']);echo $bundle['bytes'];exit;
   case 'finance-export':$csv=$finance->export(\Kulturhus\Verein\Web\FinanceExportForm::documents($_POST,$view==='finance'&&($_GET['tab']??null)==='export'));documentDownload($documents,'kulturhus-finanzliste.csv',$csv,['title'=>'Finanzliste · '.date('d.m.Y'),'kind'=>'export','scope'=>'finance'],scalarFields($_POST,['kind','status','from','to','q','account','direction']));
   case 'supplier-bank-save':
   case 'supplier-bank-remove':
    require_once __DIR__.'/../src/Finance/Invoices/InvoiceSupplierContacts.php';
    $supplierBankService=new \Kulturhus\Verein\InvoiceSupplierContacts($db,(string)$_SESSION['actor']);
    if($action==='supplier-bank-save')$supplierBankService->saveBank($id,(int)input($_POST,'version'),['iban'=>input($_POST,'supplier_iban'),'bic'=>input($_POST,'supplier_bic')]);
    else $supplierBankService->removeBank($id,(int)input($_POST,'version'));
    $_SESSION['notice']=$action==='supplier-bank-save'?'Bankverbindung des Lieferanten gespeichert.':'Bankverbindung des Lieferanten entfernt. Rechnungen und Zahlungen bleiben erhalten.';
    header('Location: '.url(['view'=>'members','id'=>$id]).'#supplier-bank');exit;
   case 'save':
    if(!$registry->canWrite())throw new DomainException('Mitgliederbearbeitung für Profilangaben erforderlich.',403);
    $id=\Kulturhus\Verein\Web\MemberProfileForm::target($_POST,$_GET);
    \Kulturhus\Verein\Web\MemberProfileForm::completeCustom($_POST,\Kulturhus\Verein\MemberCustomFields::definition($db,\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['club_id']));
    if($id!==''&&$registry->contact($id)['id']!==$id)throw new DomainException('Profilangaben am ursprünglich geöffneten Profil erneut prüfen.',409);
    try{
    $contactDetails=['legacy_member_number'=>input($_POST,'legacy_member_number'),'phone'=>input($_POST,'phone'),'birthday'=>input($_POST,'birthday'),'notes'=>input($_POST,'notes'),'honorary'=>input($_POST,'honorary')==='1'];
    $contactDetails['custom_fields']=$_POST['custom_fields']??[];$contactDetails['custom_fields_version']=(int)input($_POST,'custom_fields_version','-1');
    $saveProfile=static function()use($registry,$id,$contactDetails):string{
    if($id===''&&input($_POST,'create_as')==='member'){
      $membership=scalarFields($_POST,['number','status','family','family_name','start_precision']);$membership['start']=input($_POST,'start_precision')==='year'?input($_POST,'start_year').'-01-01':input($_POST,'start');
      return $registry->createMemberContact(input($_POST,'kind'),input($_POST,'name'),input($_POST,'email'),input($_POST,'address'),$contactDetails,$membership,input($_POST,'access_mail')==='1');

    }else return $registry->saveContact($id!==''?$id:null,(int)input($_POST,'version','0'),input($_POST,'kind'),input($_POST,'name'),input($_POST,'email'),input($_POST,'address'),$contactDetails);
    };
    $profileCreationResult=null;if($id===''&&$nativeManagerEnabled){$profileCreationResult=\Kulturhus\Verein\Web\MemberProfileCreation::session($db,(string)$_SESSION['auth_token'])->create($_POST,$saveProfile);$id=$profileCreationResult['contact'];}else $id=$saveProfile();
    }catch(PDOException $failure){throw new DomainException('Profilangaben konnten nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.',503,$failure);}
    $_SESSION['notice']=($profileCreationResult['replayed']??false)?'Profil dieses Anlagevorgangs bereits vorhanden.':'Profil gespeichert.';redirectTo(['view'=>'members','id'=>$id]);
   case 'assign-family':$registry->assignFamily($id,input($_POST,'expected_family'),input($_POST,'family'),input($_POST,'family_name'));$_SESSION['notice']='Familienzuordnung gespeichert.';redirectTo(['view'=>'members','id'=>$id]);
   case 'save-family':$registry->saveFamily($id,(int)input($_POST,'version'),input($_POST,'family_name'),input($_POST,'payer'));$_SESSION['notice']='Familie und Beitragszahler gespeichert.';redirectTo(['view'=>'members','id'=>$id]);
   case 'create-letter':$letterId=\Kulturhus\Verein\LetterDocuments::create($documents,$settings,scalarFields($_POST,['recipient','subject','body','date']),input($_POST,'request_key'));$_SESSION['notice']='Der Brief wurde als PDF im Ausgang gespeichert. Du kannst ihn hier ansehen und herunterladen.';redirectTo(['view'=>'documents','doc'=>$letterId]);
   case 'annual-contribution':$entry=$finance->createAnnualContribution($id,(int)input($_POST,'year'),input($_POST,'expected_family'));$_SESSION['notice']='Jahresbeitrag einmalig zugeordnet. Zahlungseingänge werden separat erfasst.';redirectTo(['view'=>'finance','tab'=>'contributions','entry'=>$entry]);
   case 'admission-grant':$admissionVotes->grant(input($_POST,'user_id'),(int)input($_POST,'version'),input($_POST,'office'),input($_POST,'enabled')==='1',input($_POST,'board_confirmed')==='1');$_SESSION['notice']='Vorstandsberechtigung gespeichert.';redirectTo(['view'=>'settings','tab'=>'roles','role_user'=>input($_POST,'user_id')]);
   case 'admission-open':$admissionVotes->open($id,input($_POST,'complete_board')==='1');redirectTo(['view'=>'portal','tab'=>'application','application'=>$id]);
   case 'admission-vote':$admissionVotes->vote($id,input($_POST,'choice'),input($_POST,'reason'),input($_POST,'confirmed')==='1');$_SESSION['notice']='Deine Stimme wurde protokolliert.';redirectTo(['view'=>'portal','tab'=>'application','application'=>$id]);
   case 'manual-application':throw new DomainException('Manuellen Antrag im ursprünglich geöffneten Bereich prüfen.',422);
   case 'portal-share':\Kulturhus\Verein\Web\DocumentSharingForm::target($_GET,$_POST);$shareNumbers=[];foreach(['version'=>1,'revision'=>1]as$key=>$minimum){$number=filter_var(input($_POST,$key),FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum]]);if($number===false)throw new DomainException('Geprüfte Dateifassung und Freigabestand fehlen. Dokument neu öffnen und prüfen.');$shareNumbers[$key]=$number;}if(!in_array(input($_POST,'enabled'),['0','1'],true))throw new DomainException('Freigabeaktion auswählen.');(new \Kulturhus\Verein\PortalDocuments($db,(string)$_SESSION['actor']))->share(input($_POST,'doc'),input($_POST,'contact'),input($_POST,'enabled')==='1',$shareNumbers['version'],$shareNumbers['revision']);$_SESSION['notice']='Persönliche Dokumentfreigabe gespeichert.';redirectTo(['view'=>'documents','doc'=>input($_POST,'doc')]+scalarFields($_GET,['scope','q','folder','kind','state','unassigned','source_type','source_id','recursive','page','size','doc_mode']));
   case 'volunteer-preview':
    $volunteerPreviousDraft=$_SESSION['volunteer_preview']??null;unset($_SESSION['volunteer_preview']);$volunteerService=new \Kulturhus\Verein\VolunteerForm($db,(string)$_SESSION['actor'],input($_POST,'volunteer_kind','hygiene'));$volunteerInput=scalarFields($_POST,$volunteerService->fields());$volunteerData=$volunteerService->build(input($_POST,'contact'),$volunteerInput);$volunteerBytes=\Kulturhus\Verein\DocumentPreview::pdf($volunteerData);
    $_SESSION['volunteer_preview']=['kind'=>$volunteerService->kind(),'actor'=>$_SESSION['actor'],'contact'=>$volunteerData['contact_id'],'input'=>$volunteerInput,'signed'=>$volunteerData['signature_used'],'hash'=>\Kulturhus\Verein\VolunteerForm::digest($volunteerData),'bytes'=>$volunteerBytes,'pdf_sha256'=>hash('sha256',$volunteerBytes),'token'=>bin2hex(random_bytes(32)),'request_key'=>bin2hex(random_bytes(16)),'expires'=>time()+1800];
    if(is_array($volunteerPreviousDraft)&&($volunteerPreviousDraft['actor']??'')===$_SESSION['actor']&&($volunteerPreviousDraft['kind']??'hygiene')===$volunteerService->kind()&&($volunteerPreviousDraft['contact']??'')===$volunteerData['contact_id']&&($volunteerPreviousDraft['input']??null)===$volunteerInput&&($volunteerPreviousDraft['signed']??null)===$volunteerData['signature_used']&&($volunteerPreviousDraft['hash']??'')===$_SESSION['volunteer_preview']['hash']&&($volunteerPreviousDraft['pdf_sha256']??'')===$_SESSION['volunteer_preview']['pdf_sha256']&&preg_match('/^[a-f0-9]{32}$/D',$volunteerPreviousDraft['request_key']??''))$_SESSION['volunteer_preview']['request_key']=$volunteerPreviousDraft['request_key'];break;
   case 'volunteer-save':
    if(input($_POST,'reviewed')!=='1')throw new DomainException('Formularangaben vor dem Ablegen bestätigen.');$draft=$_SESSION['volunteer_preview']??null;if(!is_array($draft)||$draft['actor']!==$_SESSION['actor']||$draft['expires']<time()||!hash_equals($draft['token'],input($_POST,'preview_token'))||!is_string($draft['bytes']??null)||!is_string($draft['pdf_sha256']??null)||!hash_equals($draft['pdf_sha256'],hash('sha256',$draft['bytes'])))throw new DomainException('Bitte zuerst die vollständige PDF-Vorschau erstellen.');
    if(input($_POST,'contact')!==$draft['contact'])throw new DomainException('Ausgewählte Person wurde geändert. Bitte erneut prüfen.');$volunteerService=new \Kulturhus\Verein\VolunteerForm($db,(string)$_SESSION['actor'],$draft['kind']??'hygiene');$volunteerDoc=$volunteerService->save($draft['contact'],$draft['input'],$draft['signed'],$draft['request_key'],$draft['hash']);$_SESSION['notice']=$hseActionText('Ehrenamtsbestätigung bei der Person abgelegt.');redirectTo(['view'=>'portal','tab'=>'safety','volunteer'=>1,'volunteer_kind'=>$draft['kind']??'hygiene','person'=>$draft['contact'],'volunteer_document'=>$volunteerDoc,'volunteer_receipt'=>$draft['request_key']]);
   case 'push-save':$subscription=json_decode(input($_POST,'subscription'),true);if(!is_array($subscription))throw new DomainException('Push-Anmeldung prüfen.');(new \Kulturhus\Verein\PushNotifications($db,(string)$_SESSION['actor']))->save($subscription);header('Content-Type: application/json');echo '{"ok":true}';exit;
   case 'push-remove':(new \Kulturhus\Verein\PushNotifications($db,(string)$_SESSION['actor']))->remove(input($_POST,'endpoint'));header('Content-Type: application/json');echo '{"ok":true}';exit;
   case 'push-remove-all':(new \Kulturhus\Verein\PushNotifications($db,(string)$_SESSION['actor']))->removeAll();$_SESSION['notice']='Alle Push-Geräte für deinen Zugang abgemeldet.';if(input($_POST,'return_to')==='account'){header('Location: '.url(['view'=>'settings','tab'=>'account']).'#notifications',true,303);exit;}redirectTo(['view'=>'chat']);
   case 'chat-create':$chatId=(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->create(input($_POST,'title'),arrayInput($_POST,'people'));redirectTo(['view'=>'chat','chat'=>$chatId]);
   case 'chat-send':$chatFile=null;if(isset($_FILES['chat_file'])&&$_FILES['chat_file']['error']!==UPLOAD_ERR_NO_FILE){$f=$_FILES['chat_file'];if($f['error']!==UPLOAD_ERR_OK||!\Kulturhus\Verein\LiveUploads::file($f['tmp_name']))throw new DomainException('Chat-Datei konnte nicht hochgeladen werden.');$chatFile=['name'=>(string)$f['name'],'bytes'=>\Kulturhus\Verein\LiveUploads::bytes($f['tmp_name'],2097152)];}(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->send(input($_POST,'chat'),input($_POST,'body'),input($_POST,'request_key'),$chatFile);\Kulturhus\Verein\WebPushDelivery::kick();redirectTo(['view'=>'chat','chat'=>input($_POST,'chat')]);
   case 'chat-add-people':(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->addPeople(input($_POST,'chat'),arrayInput($_POST,'people'));redirectTo(['view'=>'chat','chat'=>input($_POST,'chat')]);
   case 'chat-edit':(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->editMessage(input($_POST,'chat'),(int)input($_POST,'message'),input($_POST,'body'),input($_POST,'edit_token'));redirectTo(['view'=>'chat','chat'=>input($_POST,'chat')]);
   case 'chat-leave':(new \Kulturhus\Verein\ClubChat($db,(string)$_SESSION['actor']))->leave(input($_POST,'chat'));redirectTo(['view'=>'chat']);
   case 'tax-years':if(!preg_match('/^[0-9]{4}$/D',input($_POST,'from_year'))||!preg_match('/^[0-9]{4}$/D',input($_POST,'to_year'))||(int)input($_POST,'from_year')<2000||(int)input($_POST,'to_year')>(int)date('Y')||(int)input($_POST,'from_year')>(int)input($_POST,'to_year')||(int)input($_POST,'to_year')-(int)input($_POST,'from_year')>2)throw new DomainException('Ein bis drei gültige Kalenderjahre auswählen.');break;
   case 'tax-preview':$taxPreview=\Kulturhus\Verein\TaxPreparationFlow::preview(new \Kulturhus\Verein\TaxPreparation($db,(string)$_SESSION['actor']),(string)$_SESSION['actor'],$_POST,$_SESSION);break;
   case 'tax-bundle':$documentId=\Kulturhus\Verein\TaxPreparationFlow::save(new \Kulturhus\Verein\TaxPreparation($db,(string)$_SESSION['actor']),(string)$_SESSION['actor'],$_POST,$_SESSION);$_SESSION['notice']='Der geprüfte Vorbereitungsstand wurde gespeichert. Es wurde nichts an das Finanzamt übermittelt.';redirectTo(['view'=>'finance','tab'=>'tax','tax_saved'=>$documentId]);
   case 'manual-account':\Kulturhus\Verein\ManualMemberAccounts::request($db,(string)$_SESSION['actor'],$id,input($_POST,'access_mail')==='1',['expected'=>input($_POST,'expected_family'),'target'=>input($_POST,'family'),'name'=>input($_POST,'family_name')]);if($nativeManagerEnabled){$nativeBridge->managerActor();try{(new \Kulturhus\Native\Integration\MemberAccounts($db,$nativeAuth,\Kulturhus\Native\Shared\SecretBox::fromFile((string)getenv('KH_NATIVE_PRIVATE_KEY_FILE'))))->prepare($nativeBridge->managerClub(),$id);}catch(DomainException){$db->prepare("UPDATE manual_member_accounts SET state='needs_review' WHERE club_id=? AND contact_id=?")->execute([$nativeBridge->managerClub(),$id]);}}$_SESSION['notice']='Zugangsauftrag gespeichert. Den aktuellen Stand findest du im Profil.';redirectTo(['view'=>'members','id'=>$id]);
   case 'delete-contact':(new \Kulturhus\Verein\ContactArchive($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey()))->archive($id,(int)input($_POST,'version'),input($_POST,'confirm_name'),input($_POST,'delete_reason'),input($_POST,'archive_end'));$_SESSION['notice']=\Kulturhus\Native\Language\MemberCatalogueMessages::text('Profil verschlüsselt archiviert. Mitgliedschaftshistorie und Zahlungen bleiben erhalten.',isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberCatalogueMessages::messages('de'));redirectTo(['view'=>'members','tab'=>'archive']);
   case 'restore-contact':$archiveActionMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberCatalogueMessages::messages('de');\Kulturhus\Verein\Web\MemberArchivePage::restore(new \Kulturhus\Verein\ContactArchive($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey()),$_GET,$_POST);$_SESSION['notice']=\Kulturhus\Native\Language\MemberCatalogueMessages::text('Profil wiederhergestellt. Mitgliedschaft bleibt gegebenenfalls ehemalig; Zugänge werden nicht automatisch aktiviert.',$archiveActionMessages);redirectTo(['view'=>'members','id'=>$id]);
   case 'join':$registry->addMembership($id,input($_POST,'number'),input($_POST,'status'),input($_POST,'start_precision')==='year'?input($_POST,'start_year').'-01-01':input($_POST,'start'),input($_POST,'access_mail')==='1',['expected'=>input($_POST,'expected_family'),'target'=>input($_POST,'family'),'name'=>input($_POST,'family_name')],input($_POST,'start_precision','day'));$_SESSION['notice']='Mitgliedschaft angelegt.';redirectTo(['view'=>'members','id'=>$id]);
   case 'membership-start':$registry->changeMembershipStart($id,input($_POST,'membership'),(int)input($_POST,'version','0'),input($_POST,'start_precision')==='year'?input($_POST,'start_year').'-01-01':input($_POST,'start'),input($_POST,'start_precision','day'));$_SESSION['notice']='Eintritt gespeichert.';redirectTo(['view'=>'members','id'=>$id]);
   case 'leave':$registry->endMembership(input($_POST,'membership'),(int)input($_POST,'version','0'),input($_POST,'end'));$_SESSION['notice']='Austritt gespeichert. Die Mitgliedschaft bleibt im Verlauf erhalten.';redirectTo(['view'=>'members','id'=>$id]);
   case 'save-list':$listId=$registry->saveList(input($_POST,'list_name'),array_intersect_key($_POST,array_flip(['q','list','status','kind','sort','direction','as_of'])));$_SESSION['notice']='Dynamische Liste gespeichert.';redirectTo(['view'=>'members','saved'=>$listId]);
   case 'delete-list':$registry->deleteList($id);$_SESSION['notice']='Gespeicherte Liste entfernt. Profile bleiben erhalten.';redirectTo(['view'=>'members']);
   case 'export':$exportFormat=input($_POST,'export_format','csv');$exportDay=input($_POST,'as_of');$csv=$registry->exportContacts(arrayInput($_POST,'selected'),arrayInput($_POST,'columns'),$exportFormat,$exportDay);documentDownload($documents,'kulturhus-kontakte.'.$exportFormat,$csv,['title'=>'Mitglieder & Kontakte · '.($exportDay!==''?$exportDay:date('d.m.Y')),'kind'=>'export','scope'=>'members'],['selected'=>arrayInput($_POST,'selected'),'columns'=>arrayInput($_POST,'columns'),'as_of'=>$exportDay]);
   case 'gema-link-invoice':(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->linkInvoice($id,(int)input($_POST,'version'),input($_POST,'entry_id'));$_SESSION['notice']=$gemaText('Rechnung zugeordnet.');redirectTo(['view'=>'gema','event'=>$id]);
   case 'gema-upload-invoice':$f=$_FILES['gema_file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||!\Kulturhus\Verein\LiveUploads::file($f['tmp_name'])||$f['size']>10485760)throw new DomainException('Rechnungsdatei bis 10 MB auswählen.');(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->uploadInvoice($id,input($_POST,'entry_id'),$f['name'],(string)file_get_contents($f['tmp_name']),input($_POST,'request_key'),\Kulturhus\Verein\LiveUploads::prepared($f['tmp_name'],$f['name']));$_SESSION['notice']=$gemaText('Rechnung hochgeladen und in Finanzen zugeordnet.');redirectTo(['view'=>'gema','event'=>$id]);
   case 'gema-song':(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->changeSong($id,(int)input($_POST,'version'),input($_POST,'operation'),input($_POST,'song_index'),scalarFields($_POST,array_keys(\Kulturhus\Verein\GemaSetlist::FIELDS)));$_SESSION['notice']=$gemaText('Setlist gespeichert.');redirectTo(['view'=>'gema','event'=>$id]);
   case 'gema-setlist-export':$g=(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->get($id);$bytes=\Kulturhus\Verein\GemaSetlist::xlsx($g['songs']);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="GEMA-Setlist.xlsx"');echo $bytes;exit;
   case 'save-gema-check':(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->checklist($id,(int)input($_POST,'version'),input($_POST,'needed'),input($_POST,'done')==='1',input($_POST,'setlist'),input($_POST,'reason'));$_SESSION['notice']=$gemaText('Erledigungsstand gespeichert.');redirectTo(['view'=>'gema','event'=>$id]);
   case 'save-gema':(new \Kulturhus\Verein\EventGema($db,(string)$_SESSION['actor'],$registry))->save($id,(int)input($_POST,'version'),scalarFields($_POST,array_merge(['status','reference','notes','setlist','entry_id'],array_keys(\Kulturhus\Verein\EventGema::defaults()))));$_SESSION['notice']=$gemaText('GEMA-Vorgang gespeichert; keine Meldung an die GEMA übermittelt.');redirectTo(['view'=>'gema','event'=>$id]);
   case 'facebook-history-preview':$fbRows=\Kulturhus\Verein\FacebookHistory::preview($db,(string)$_SESSION['actor'],(int)input($_POST,'year'));$_SESSION['facebook_history']=['actor'=>$_SESSION['actor'],'expires'=>time()+1800,'rows'=>$fbRows,'hash'=>hash('sha256',json_encode($fbRows))];break;
   case 'facebook-history-import':$fb=$_SESSION['facebook_history']??null;if(!$fb||$fb['actor']!==$_SESSION['actor']||$fb['expires']<time()||!hash_equals($fb['hash'],input($_POST,'preview_hash')))throw new DomainException('Facebook-Vorschau erneut laden.');$count=\Kulturhus\Verein\FacebookHistory::import($db,(string)$_SESSION['actor'],$fb['rows'],arrayInput($_POST,'facebook_events'));$_SESSION['notice']=$count.' Termine übernommen. Durchführung und Kurzbericht bitte prüfen.';redirectTo(['view'=>'website','tab'=>'technical','history'=>1]);
   case 'preview-event-print':$printPreview=(new \Kulturhus\Verein\CalendarPrints($db,(string)$_SESSION['actor'],$invitations))->preview($id,arrayInput($_POST,'groups'),arrayInput($_POST,'recipients'),input($_POST,'subject'),input($_POST,'message'),input($_POST,'print_mode'));break;
   case 'create-event-print':$printId=(new \Kulturhus\Verein\CalendarPrints($db,(string)$_SESSION['actor'],$invitations))->create($id,arrayInput($_POST,'groups'),arrayInput($_POST,'recipients'),input($_POST,'subject'),input($_POST,'message'),input($_POST,'print_mode'),input($_POST,'preview_hash'),input($_POST,'request_key'));$_SESSION['notice']=$calendarText('Sammeldruck erstellt. Im Termin herunterladen; Postaufgabe anschließend gesondert vermerken.');redirectTo(['view'=>'calendar','event'=>$id]);
   case 'posted-event-print':(new \Kulturhus\Verein\CalendarPrints($db,(string)$_SESSION['actor'],$invitations))->posted($id);$_SESSION['notice']=$calendarText('Postaufgabe vermerkt.');redirectTo(['view'=>'calendar','event'=>input($_POST,'event_id')]);
   case 'preview-event-mail':$invitationPreview=$invitations->preview($id,arrayInput($_POST,'groups'),arrayInput($_POST,'recipients'),input($_POST,'subject'),input($_POST,'message'),isset($_POST['channels_present'])?arrayInput($_POST,'channels'):['mail']);break;
   case 'send-event-mail':$invitations->queue($id,arrayInput($_POST,'groups'),arrayInput($_POST,'recipients'),input($_POST,'subject'),input($_POST,'message'),input($_POST,'preview_hash'),input($_POST,'request_key'),isset($_POST['channels_present'])?arrayInput($_POST,'channels'):['mail']);$_SESSION['notice']=$calendarText('Einladung über die gewählten Versandwege vorgemerkt. Gleiche E-Mail-Adressen erhalten diese Nachricht nur einmal.');redirectTo(['view'=>'calendar','event'=>$id]);
   case 'save-event':
    if($id===''&&($_POST['series_frequency']??'once')!=='once'){$seriesId=$registry->createAppointmentSeries(scalarFields($_POST,['title','start','end','location','description','category','activity_state','activity_kind','report_summary','source_reference']),$calendarPeopleState['selected']??arrayInput($_POST,'attendees'),$seriesRuleInput,input($_POST,'series_key'));$_SESSION['notice']=$calendarText('Serie mit eigenen Terminen gespeichert. Keine Einladungen versendet.');redirectTo(['view'=>'calendar','series'=>$seriesId]);}
    $eventId=$registry->saveAppointment($id!==''?$id:null,(int)input($_POST,'version','0'),scalarFields($_POST,['title','start','end','location','description','category','activity_state','activity_kind','report_summary','source_reference']),$calendarPeopleState['selected']??arrayInput($_POST,'attendees'));$_SESSION['notice']=$calendarText('Termin gespeichert. Einladungen stehen im Vereinsportal bereit; keine E-Mail versendet.');redirectTo(['view'=>'calendar','event'=>$eventId]);
   case 'delete-event':$registry->deleteAppointment($id,(int)input($_POST,'version','0'));$_SESSION['notice']=$calendarText('Termin entfernt.');redirectTo(['view'=>'calendar']);
   case 'respond':$registry->respond($id,input($_POST,'response'),(int)input($_POST,'version'));$_SESSION['notice']=$calendarText('Deine Rückmeldung wurde gespeichert.');redirectTo(['view'=>'calendar','event'=>$id]);
   default:throw new DomainException('Unbekannte Aktion.');
  }
 }
}catch(DomainException $ex){
 if(($_POST['action']??null)==='account-native'&&http_response_code()<400)http_response_code($ex instanceof \Kulturhus\Native\Appearance\Conflict?409:(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422));
 if((($simpleSettingsRoute??false)||($_POST['action']??null)==='appearance-save')&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if(is_string($_POST['action']??null)&&str_starts_with(trim($_POST['action']),'activity-report-')&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\ActivityReportPageState::status($ex));
 if(is_string($_POST['action']??null)&&str_starts_with(trim($_POST['action']),'member-change-')&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\MemberChangePageState::status($ex));
 if(is_string($_POST['action']??null)&&str_starts_with(trim($_POST['action']),'minutes-')&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\MinutesPageState::status($ex));
 if(input($_POST,'action')==='save-tax-account'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():($ex->getMessage()==='Steuerkonto inzwischen geändert. Bitte neu laden.'?409:(in_array($ex->getMessage(),['Finanzberechtigung erforderlich.','Keine ausreichende Finanzberechtigung.'],true)?403:422)));
 if(in_array(input($_POST,'action'),\Kulturhus\Verein\Web\OccasionForm::ACTIONS,true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if((str_starts_with(input($_POST,'action'),'member-import-')||str_starts_with(input($_POST,'action'),'member-excel-'))&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if(in_array($_POST['action']??null,['delete-contact','restore-contact'],true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if(array_key_exists('document_browse',$_POST)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if(in_array(input($_POST,'action'),\Kulturhus\Verein\Web\FinanceExportForm::ACTIONS,true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if(input($_POST,'action')===\Kulturhus\Verein\BalanceMappingForm::ACTION&&http_response_code()<400)http_response_code(\Kulturhus\Verein\BalanceMappingForm::status($ex));
 if(in_array(input($_POST,'action'),\Kulturhus\Verein\BalanceSetupForm::ACTIONS,true)&&http_response_code()<400)http_response_code(\Kulturhus\Verein\BalanceSetupForm::status($ex,$_POST));
 if((str_starts_with(input($_POST,'action'),'balance-segment-')||str_starts_with(input($_POST,'action'),'balance-clearing-')||str_starts_with(input($_POST,'action'),'balance-tax-reconciliation-')||str_starts_with(input($_POST,'action'),'balance-period-area-')||str_starts_with(input($_POST,'action'),'balance-period-tax-')||in_array(input($_POST,'action'),['balance-period-create','balance-period-opening','balance-period-account','balance-period-post','balance-period-reverse','balance-period-close','balance-period-source-import','balance-period-source-correct','balance-source-import','balance-source-correct','balance-post','balance-reverse','balance-close'],true))&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():(in_array($ex->getMessage(),['Finanzberechtigung erforderlich.','Betriebsaufteilung ist nicht freigeschaltet.','Der Verein verwendet EÜR. Keine Bilanzaufteilung gespeichert.','Verrechnungsnachweise sind nicht freigeschaltet.','Der Verein verwendet EÜR. Keine Bilanz-Verrechnung bearbeiten.','Steuerliche Nebenrechnung ist nicht freigeschaltet.','Steuerprüfung für Abschlusszeiträume ist nicht freigeschaltet.','Buchungen für abweichende Abschlusszeiträume sind noch nicht freigegeben.','Für diesen Verein gilt die EÜR. Keine Bilanzbuchung vorgenommen.','Für diesen Verein ist die EÜR eingestellt. Keine Bilanzbuchung vorgenommen.','Das Bilanzjournal ist noch nicht für den Produktivbetrieb freigegeben.','Bilanzbuchungen sind für diesen Zugang nicht verfügbar.','Für diesen Verein ist keine Bereichsaufteilung der Bilanz freigeschaltet.','Der Verein verwendet EÜR. Keine Bilanz-Steuerwerte bearbeiten.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.'],true)?403:422));
 if(input($_POST,'action')==='save'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():($ex->getMessage()==='Das Profil wurde inzwischen geändert. Bitte neu laden und Änderungen vergleichen.'?409:422));
 if(in_array(input($_POST,'action'),['save-list','delete-list','export'],true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():($ex->getMessage()==='Keine Berechtigung für diese Mitgliederdaten.'?403:($ex->getMessage()==='Eine Liste mit diesem Namen existiert bereits.'?409:422)));
 if(input($_POST,'action')==='contribution-invoice'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);
 if(input($_POST,'action')==='create-account'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 if(in_array($_POST['action']??null,\Kulturhus\Verein\Web\AccountSettingsForm::ACTIONS,true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():($ex->getMessage()==='Kontoeinstellungen wurden inzwischen geändert. Bitte neu laden.'?409:422));
 if(input($_POST,'action')==='reverse-payment'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 if(in_array($_POST['action']??null,['payment-tags-assign','payment-tags-save'],true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 if((in_array($_POST['action']??null,['donation-contact','donation-contact-review','donation-contact-adopt','donation-prepare'],true)||(($contactChoiceState['action']??null)==='donation-contact'&&($_POST['action']??null)==='contact-choice-browse'))&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():($ex->getMessage()==='Spende inzwischen geändert. Bitte neu laden.'?409:422));
 if(in_array($_POST['action']??null,\Kulturhus\Verein\Web\GoodsCorrectionState::ACTIONS,true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 if(input($_POST,'action')==='create-goods'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 if(input($_POST,'action')==='outgoing-einvoice-save'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,409,503],true)?$ex->getCode():422);
 if(in_array($_POST['action']??null,['donation-preview','donation-save','donation-issue','donation-cancel','donation-compare','donation-adopt','donation-search'],true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 if(input($_POST,'action')==='outgoing-hybrid-save'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,409,503],true)?$ex->getCode():422);
 if(input($_POST,'action')==='update-positions'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,409,503],true)?$ex->getCode():422);
 if(input($_POST,'action')==='release-allocation'&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,409,503],true)?$ex->getCode():422);
 if(in_array(input($_POST,'action'),['volunteer-preview','volunteer-save'],true)&&http_response_code()<400)http_response_code($ex->getCode()===503?503:(in_array($ex->getMessage(),['Du kannst ausschließlich deine eigene Ehrenamtsbestätigung vorbereiten.','Für diesen Zugang ist keine Ehrenamtsbestätigung verfügbar.','Zugang nicht aktiv.'],true)?403:422));
 if(($nativeView??null)==='media'&&input($_POST,'action')==='native-module'&&(str_starts_with(input($_POST,'operation'),'media-')||str_starts_with(input($_POST,'operation'),'album-')||input($_POST,'operation')==='document-quota-save')){
  if(in_array($ex->getMessage(),['Für diese Aktion fehlt die Berechtigung.','Für diese Aktion fehlt die aktuelle Vereinsberechtigung.','Benutzer nicht freigeschaltet.','Vereinszugang nicht aktiv.','Bild nicht verfügbar.'],true))http_response_code(403);
  elseif(http_response_code()<400)http_response_code(422);
 }
 if(str_starts_with(input($_POST,'action'),'document-workflow-')&&!str_starts_with(input($_POST,'action'),'document-workflow-inspection-')&&http_response_code()<400)http_response_code($ex instanceof \Kulturhus\Verein\DocumentWorkflow\WorkflowException&&$ex->isUnconfirmedCreation()?503:($ex instanceof \Kulturhus\Verein\DocumentWorkflow\WorkflowException&&$ex->isConflict()?409:(in_array($ex->getMessage(),['Schreibrecht erforderlich.','Schreibrecht für die Gefährdungsbeurteilung erforderlich.','Mitgliedszugang nicht verfügbar.','Unterschrift nicht verfügbar.','Für diese Aktion fehlen Schreibrechte im Dokumentenmodul.','Eingereichte und freigegebene Dokumente sind für Bearbeitungen gesperrt.','Nur die zugewiesene bearbeitende Person darf den Entwurf ändern oder einreichen.','Für das Dokumentenmodul fehlt die Leseberechtigung.','Das Dokument wurde nicht gefunden.','Die Vorlage wurde nicht gefunden.','Der Ordner wurde nicht gefunden.','Der übergeordnete Ordner wurde nicht gefunden.','Zugang nicht aktiv.'],true)?403:422)));
 if(str_starts_with(input($_POST,'action'),'document-workflow-inspection-')&&http_response_code()<400)http_response_code($ex instanceof \Kulturhus\Verein\DocumentWorkflow\InspectionConflict?409:(in_array($ex->getMessage(),['Gefährdungsprüfung nicht verfügbar.','Prüfauftrag nicht verfügbar.','Schreibrecht für Gefährdungsprüfungen erforderlich.','Nur die aktuell zuständige Person kann diesen Auftrag umsetzen.','Zugang nicht aktiv.','Keine ausreichende Berechtigung.'],true)?403:422));
 if(input($_POST,'action')==='document-check-save'){if(http_response_code()<400)http_response_code(in_array($ex->getCode(),[409,503],true)?$ex->getCode():(in_array($ex->getMessage(),['Nachweisformular gehört zu einem anderen Zugang oder Verein.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.','Nachweis nicht verfügbar.','Dokument nicht verfügbar.','Keine ausreichende Berechtigung für dieses Dokument oder seine Zuordnungen.'],true)?403:422));$error=$hseActionText($ex->getMessage());}
 if(\Kulturhus\Verein\Web\InventoryInvoiceForm::applies($_POST,$_GET)&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryInvoiceForm::status($ex));
 if(in_array(input($_POST,'action'),['inventory-archive','inventory-restore','inventory-remove'],true)&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryLifecycleForm::status($ex));
 if(input($_POST,'action')==='inventory-file'&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryFileForm::status($ex));
 if(\Kulturhus\Verein\Web\InventoryLoanActionForm::applies($_POST,$_GET)&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryLoanActionForm::status($ex));
 if(\Kulturhus\Verein\Web\InventoryLoanCreateForm::applies($_POST,$_GET)&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryLoanCreateForm::status($ex));
 if(\Kulturhus\Verein\Web\InventoryIndividualForm::applies($_POST,$_GET)&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryIndividualForm::status($ex));
 if((input($_POST,'action')==='inventory-item'||(input($_POST,'action')==='inventory-source-browse'&&input($_POST,'source_form')!=='individual'))&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryItemForm::status($ex));
 if(input($_POST,'action')==='inventory-move'&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryMoveForm::status($ex));
 if(input($_POST,'action')==='inventory-location'&&http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\InventoryLocationForm::status($ex));
 if($view==='calendar')$calendarFieldError=\Kulturhus\Verein\FormValidation::fieldError($ex->getMessage());
 if(in_array(input($_POST,'action'),\Kulturhus\Verein\Web\ApplicationReviewForm::ACTIONS,true)&&http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,503],true)?$ex->getCode():422);
 $error=$view==='free'?\Kulturhus\Native\Language\FreeListMessages::failure($ex,$freeInterfaceText):($view==='calendar'?$calendarText($ex->getMessage()):$ex->getMessage());
 if(input($_POST,'action')==='cancel-entry'){if(http_response_code()<400)http_response_code(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422);$error=\Kulturhus\Native\Language\ReimbursementMessages::text($error,isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():[]);}
 if(input($_POST,'action')==='document-check-save')$error=$hseActionText($ex->getMessage());
 if($ex instanceof \Kulturhus\Verein\Documents\WorkflowFormError)$error=$ex->localized($documentActionMessages??[]);
 if(str_starts_with(input($_POST,'action'),'gema-')||in_array(input($_POST,'action'),['save-gema-check','save-gema'],true)){
  if(http_response_code()<400)http_response_code(in_array($ex->getMessage(),['GEMA-Vorgang inzwischen geändert.','Titel inzwischen geändert.','Rechnungszuordnung inzwischen geändert.'],true)?409:(in_array($ex->getMessage(),['Finanz-Schreibrecht erforderlich.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.'],true)?403:422));
  $error=$gemaText($ex->getMessage());
 }
 if(input($_POST,'action')==='portal-share'){
  if(http_response_code()<400)http_response_code($ex instanceof \Kulturhus\Verein\Documents\ShareConflict?409:(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():(in_array($ex->getMessage(),['Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Keine ausreichende Berechtigung für dieses Dokument oder seine Zuordnungen.','Zugang nicht aktiv.','Dokument nicht verfügbar.','Vertrauliche Aufnahmeunterlagen können hier nicht freigegeben werden.'],true)?403:422)));
  $error=$documentActionText($ex->getMessage());
 }
 if(str_starts_with(input($_POST,'action'),'document-')&&!str_starts_with(input($_POST,'action'),'document-workflow-')&&input($_POST,'action')!=='document-check-save'){
  if(http_response_code()<400)http_response_code(in_array($ex->getMessage(),['Dokument oder Ordner wurde inzwischen geändert. Bitte neu laden.','Ordner inzwischen geändert. Bitte neu laden.','Rechnung inzwischen geändert. Bitte neu laden.','Vorgangskennung mit anderen Eingaben bereits verwendet.'],true)?409:(in_array($ex->getMessage(),['Dokument nicht verfügbar.','Ordner nicht verfügbar.','Keine ausreichende Berechtigung für dieses Dokument oder seine Zuordnungen.','Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.','Zugang nicht aktiv.'],true)?403:422));
  $error=$documentActionText($ex->getMessage());
 }
 if(str_starts_with(input($_POST,'action'),'mailbox-')&&http_response_code()<400)http_response_code($ex instanceof \Kulturhus\Verein\Communication\MailboxConflict?409:(in_array($ex->getCode(),[403,404,409,422,503],true)?$ex->getCode():422));
 // Translate only declared exceptions from these own account actions, never saved/human content.
 if(($nativeManagerEnabled??false)&&isset($nativeAccess)&&(in_array(input($_POST,'action'),['settings-notifications','settings-signature'],true)||(input($_POST,'action')==='settings-save'&&input($_POST,'section')==='account'))){
  if(http_response_code()<400)http_response_code(in_array($error,['Diese Einstellungen wurden inzwischen geändert. Bitte neu laden.','Benachrichtigungseinstellungen inzwischen geändert. Bitte neu laden.','Das Briefpapier oder die Unterschrift wurde inzwischen geändert. Bitte neu laden und erneut prüfen.'],true)?409:422);
  try{$error=\Kulturhus\Native\Language\AccountMessages::text($error,new \Kulturhus\Native\Language\Catalog($db,$nativeAccess));}catch(Throwable){/* Preserve the original rejection when current identity or language storage is unavailable. */}
 }
 if(($nativeView??null)==='navigation'&&input($_POST,'action')==='native-module'&&\Kulturhus\Native\Web\NavigationFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$conflict=$ex instanceof \Kulturhus\Native\Cms\Conflict||$ex->getPrevious() instanceof \Kulturhus\Native\Cms\Conflict;$state=(new \Kulturhus\Native\Web\Navigation($db,$nativeAccess,$_SESSION['csrf']))->correction($_POST,$error,$conflict);$nativeComposeState=['navigation'=>$state];http_response_code($conflict?409:422);$error='';}catch(Throwable){/* A changed actor, club or current permission cannot recover saved originals. */}
 }
 if(($nativeView??null)==='website'&&in_array(input($_POST,'action'),['website-native','native-module'],true)&&in_array($_POST['operation']??null,['website-comment-configure','website-comment-moderate','website-comment-edit'],true)&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  $_GET['section']='comments';$nativeComposeState=['comment_correction'=>['post'=>$_POST,'error'=>$error]];
  if($ex instanceof \Kulturhus\Native\Cms\Conflict||$ex->getPrevious() instanceof \Kulturhus\Native\Cms\Conflict)http_response_code(409);
  $error='';
 }
 if(($nativeView??null)==='settings'&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='meta-webhook-save'&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$nativeAccess->require('website','manage');$nativeAccess->require('social','manage');$nativeComposeState=['webhook'=>array_intersect_key($_POST,array_flip(['version','enabled']))];}catch(Throwable){/* Current permission failure remains the original rejection. */}
 }
 if(($nativeView??null)==='chat'&&input($_POST,'action')==='native-module'&&\Kulturhus\Native\Web\ChatFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$nativeAccess->require('chat','edit');$chatState=\Kulturhus\Native\Web\ChatFormState::restore($_POST);(new \Kulturhus\Native\Chat\Workspace($db,$nativeAccess))->get($chatState['id']);$nativeComposeState=['chat'=>$chatState+['error'=>$error]];$_GET['native_item']=$chatState['id'];}catch(Throwable){/* Current access failure keeps the original rejection. */}
 }
  if(($nativeView??null)==='press'&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='press-event-draft'&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
   try{$nativeAccess->require('press','read');$_GET['section']='events';}catch(Throwable){/* Current permission failure remains visible. */}
  }
  if(in_array($nativeView??null,['newsletter','press'],true)&&input($_POST,'action')==='native-module'&&in_array($_POST['operation']??null,['communication-template-manage','communication-from-template'],true)&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
   try{$nativeAccess->require($nativeView,'read');$nativeComposeState=['templates'=>\Kulturhus\Native\Web\Communication\TemplateFormState::restore($_POST)];$_GET['section']='templates';}catch(Throwable){/* Retain the current rejection without writing a template. */}
  }
  if(($nativeView??null)==='press'&&input($_POST,'action')==='native-module'&&in_array($_POST['operation']??null,['press-contacts','press-contact-profile'],true)&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
   try{$nativeAccess->require('press','read');$nativeComposeState=['contacts'=>\Kulturhus\Native\Web\Communication\PressContactManager::restore($_POST)];$_GET['section']='recipients';}catch(Throwable){/* Preserve the current rejection without changing any contact. */}
  }
  if(($nativeView??null)==='newsletter'&&input($_POST,'action')==='native-module'&&in_array($_POST['operation']??null,['newsletter-contacts','newsletter-profile'],true)&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
   try{$nativeAccess->require('newsletter','manage');$nativeComposeState=['contacts'=>\Kulturhus\Native\Web\Communication\NewsletterContacts::restore($_POST)];$_GET['section']='recipients';}catch(Throwable){/* Keep the original rejection when current access or input is invalid. */}
  }
 if(($nativeView??null)==='press'&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='communication-save'&&!isset($_POST['press_browse'])&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$nativeAccess->require('press','edit');$nativeComposeState=\Kulturhus\Native\Web\Communication\PressComposerState::restore($_POST);$_GET['section']='compose';}catch(Throwable){/* Keep the original rejection when input or current access cannot be restored. */}
 }
 if(($nativeView??null)==='social'&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$nativeAccess->require('social','edit');$nativeComposeState=\Kulturhus\Native\Web\Communication\SocialComposerState::restore($_POST);$_GET['section']='compose';}catch(Throwable){/* Keep the original rejection if input or current access cannot be restored. */}
 }
}
catch(Throwable){$activityReportUnconfirmed=in_array(input($_POST,'action'),\Kulturhus\Verein\Web\ActivityReportForm::ACTIONS,true);$memberChangeUnconfirmed=in_array(input($_POST,'action'),\Kulturhus\Verein\Web\MemberChangeForm::ACTIONS,true);$minutesUnconfirmed=in_array(input($_POST,'action'),\Kulturhus\Verein\Web\MinutesForm::ACTIONS,true);$taxCatalogueUnconfirmed=input($_POST,'action')==='save-tax-account';$occasionUnconfirmed=in_array(input($_POST,'action'),\Kulturhus\Verein\Web\OccasionForm::ACTIONS,true);$mailboxUnconfirmed=str_starts_with(input($_POST,'action'),'mailbox-');$memberSpreadsheetFailure=$activityReportUnconfirmed||$memberChangeUnconfirmed||$minutesUnconfirmed||$taxCatalogueUnconfirmed||$mailboxUnconfirmed||$occasionUnconfirmed||str_starts_with(input($_POST,'action'),'member-import-')||str_starts_with(input($_POST,'action'),'member-excel-');http_response_code($memberSpreadsheetFailure?503:500);$error=$memberChangeUnconfirmed?'Ergebnis der Mitgliederänderung konnte nicht bestätigt werden. Ursprüngliche Angaben behalten und gespeicherten Stand prüfen.':($minutesUnconfirmed?'Ergebnis der Protokollaktion konnte nicht bestätigt werden. Ursprünglichen Entwurf behalten und gespeicherten Stand prüfen.':($taxCatalogueUnconfirmed?'Ergebnis der Kontoänderung konnte nicht bestätigt werden. Ursprüngliche Angaben behalten und gespeicherten Stand prüfen.':($mailboxUnconfirmed?'Ergebnis der Postfachaktion konnte nicht bestätigt werden. Ursprüngliche Angaben behalten und gespeicherten Stand prüfen.':($occasionUnconfirmed?'Ergebnis konnte nicht bestätigt werden. Ursprüngliche Angaben behalten und gespeicherten Stand prüfen.':($memberSpreadsheetFailure?'Ergebnis von Import oder Export konnte nicht bestätigt werden. Ursprüngliche Auswahl und gespeicherten Bestand prüfen.':'Vorgang nicht gespeichert. Bitte Eingaben prüfen und bei anhaltendem Fehler den Betrieb prüfen.')))));if($activityReportUnconfirmed)$error='Ergebnis der Berichtsaktion konnte nicht bestätigt werden. Ursprüngliche Angaben behalten und gespeicherten Stand prüfen.';if($view==='free')$error=$freeInterfaceText($error);if($view==='calendar')$error=$calendarText($error);if($view==='gema')$error=$gemaText($error);if($view==='documents')$error=$documentActionText($error);}
 if($error!==''&&($nativeView??null)==='newsletter'&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$nativeAccess->require('newsletter','edit');$nativeComposeState=\Kulturhus\Native\Web\Communication\NewsletterComposerState::restore($_POST);$_GET['section']='compose';}catch(Throwable){/* Keep the original rejection when input or current access cannot be restored. */}
 }
 if($error!==''&&in_array($nativeView??null,['press','social'],true)&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='communication-save'&&is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])){
  try{$nativeAccess->require($nativeView,'edit');$nativeComposeState=$nativeView==='social'?\Kulturhus\Native\Web\Communication\SocialComposerState::restore($_POST):\Kulturhus\Native\Web\Communication\PressComposerState::restore($_POST);$_GET['section']='compose';if($nativeView==='social'&&http_response_code()>=500)$error='Speicherung nicht bestätigt. Deine Angaben bleiben erhalten. Gespeicherten Stand prüfen oder unverändert erneut speichern.';}catch(Throwable){/* Keep the original rejection when input or current access cannot be restored. */}
 }

if($error!==''&&input($_POST,'action')==='native-module'&&\Kulturhus\Native\Web\Communication\PlanningFormState::operation($_POST['operation']??null)&&isset($nativeAccess)&&in_array($planningErrorView=\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view])),['social','newsletter','press'],true)){
 $nativeView=$planningErrorView;
 try{$error=\Kulturhus\Native\Language\EditorialHubMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$failure=\Kulturhus\Native\Web\Communication\PlanningFormState::failure($db,$nativeAccess,$nativeView,$_POST,$error,http_response_code()>=500);
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$failure['error']],JSON_THROW_ON_ERROR);exit;}
  $_GET=$failure['route'];$nativeComposeState=['planning_failure'=>$failure];$error='';
 }catch(Throwable){/* Current read rights are checked again when the planning page renders. */}
}
if($error!==''&&input($_POST,'action')==='native-module'&&\Kulturhus\Native\Web\Communication\VideoPolicyForm::operation($_POST['operation']??null)&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$policyError=http_response_code()>=500?'Speicherung der Regel nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Regel prüfen.':$error;
 try{$policyError=\Kulturhus\Native\Language\VideoPolicyMessages::text($policyError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$canManage=true;try{$nativeAccess->require('social','manage');}catch(DomainException){$canManage=false;}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$policyError,'csrf'=>$_SESSION['csrf'],'can_manage'=>$canManage],JSON_THROW_ON_ERROR);exit;}
  $policyState=\Kulturhus\Native\Web\Communication\VideoPolicyForm::restore($_POST);$policyState['error']=$policyError;$nativeComposeState=['video_policy_only'=>$policyState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$policyError;}
}
if($error!==''&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='social-video-reuse'&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$reuseError=http_response_code()>=500?'Videoübernahme nicht bestätigt. Ursprüngliche Auswahl und Beitragsfassung behalten.':$error;
 try{$reuseError=\Kulturhus\Native\Language\VideoReuseMessages::text($reuseError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$edit=\Kulturhus\Native\Web\Communication\VideoReuseForm::canEdit($db,$nativeAccess,$_POST,$nativeMediaRoot);
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$reuseError,'csrf'=>$_SESSION['csrf'],'can_edit'=>$edit],JSON_THROW_ON_ERROR);exit;}
  $reuseState=\Kulturhus\Native\Web\Communication\VideoReuseForm::correction($db,$nativeAccess,$nativeMediaRoot,$_POST,$reuseError);$nativeComposeState=['video_reuse_only'=>$reuseState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$reuseError;}
}
if($error!==''&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='social-video-recover'&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$recoveryError=http_response_code()>=500?'Wiederherstellung nicht bestätigt. Ursprüngliche Dateiauswahl und gespeicherten Vorgang prüfen.':$error;
 try{$recoveryError=\Kulturhus\Native\Language\VideoRecoveryMessages::text($recoveryError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$manage=true;$edit=true;try{$nativeAccess->require('social','manage');}catch(DomainException){$manage=false;}try{$nativeAccess->require('social','edit');}catch(DomainException){$edit=false;}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$recoveryError,'csrf'=>$_SESSION['csrf'],'can_manage'=>$manage,'can_edit'=>$edit],JSON_THROW_ON_ERROR);exit;}
  $recoveryState=\Kulturhus\Native\Web\Communication\VideoRecoveryForm::correction($db,$nativeAccess,$nativeMediaRoot,$_POST,$recoveryError);$nativeComposeState=['video_recovery_only'=>$recoveryState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$recoveryError;}
}
if($error!==''&&input($_POST,'action')==='native-module'&&\Kulturhus\Native\Web\Communication\VideoRemovalForm::operation($_POST['operation']??null)&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$fileError=http_response_code()>=500?'Dateivorgang nicht bestätigt. Ursprünglichen Vorgang und Dateizustand prüfen.':$error;
 try{$fileError=\Kulturhus\Native\Language\VideoFileMessages::text($fileError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$canManage=true;try{$nativeAccess->require('social','manage');}catch(DomainException){$canManage=false;}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$fileError,'csrf'=>$_SESSION['csrf'],'can_manage'=>$canManage],JSON_THROW_ON_ERROR);exit;}
  $fileState=\Kulturhus\Native\Web\Communication\VideoRemovalForm::correction($db,$nativeAccess,$nativeMediaRoot,$_POST,$fileError);$nativeComposeState=['video_file_only'=>$fileState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$fileError;}
}
if($error!==''&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='social-video-archive'&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$archiveError=http_response_code()>=500?'Speicherung der Archivwahl nicht bestätigt. Ursprünglichen Vorgang behalten und gespeicherte Archivwahl prüfen.':$error;
 try{$archiveError=\Kulturhus\Native\Language\VideoCatalogueMessages::text($archiveError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$canEdit=true;try{$nativeAccess->require('social','edit');}catch(DomainException){$canEdit=false;}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$archiveError,'csrf'=>$_SESSION['csrf'],'can_edit'=>$canEdit],JSON_THROW_ON_ERROR);exit;}
  $archiveState=\Kulturhus\Native\Web\Communication\VideoArchiveForm::restore($_POST);$archiveState['error']=$archiveError;$nativeComposeState=['video_archive_only'=>$archiveState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$archiveError;}
}
if($error!==''&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='social-video-metadata-save'&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$metadataError=http_response_code()>=500?'Speicherung der Videoangaben nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Angaben prüfen.':$error;
 try{$metadataError=\Kulturhus\Native\Language\VideoMetadataMessages::text($metadataError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$canEdit=true;$canProof=true;try{$nativeAccess->require('social','edit');}catch(DomainException){$canEdit=false;}try{$nativeAccess->require('social','manage');}catch(DomainException){$canProof=false;}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$metadataError,'csrf'=>$_SESSION['csrf'],'can_edit'=>$canEdit,'can_proof'=>$canProof],JSON_THROW_ON_ERROR);exit;}
  $metadataState=\Kulturhus\Native\Web\Communication\VideoMetadataForm::restore($_POST);$metadataState['error']=$metadataError;$nativeComposeState=['video_metadata_only'=>$metadataState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$metadataError;}
}
if($error!==''&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='social-video-save'&&isset($nativeAccess)&&\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]))==='social'){
 $nativeView='social';$videoError=http_response_code()>=500?'Speicherung nicht bestätigt. Originaldatei und ursprünglichen Vorgang behalten. Gespeichertes Video prüfen.':$error;
 try{$videoError=\Kulturhus\Native\Language\VideoStudioMessages::text($videoError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());$nativeAccess->require('social','read');$videoCanEdit=true;try{$nativeAccess->require('social','edit');}catch(DomainException){$videoCanEdit=false;}
  if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$videoError,'csrf'=>$_SESSION['csrf'],'can_edit'=>$videoCanEdit],JSON_THROW_ON_ERROR);exit;}
  $videoState=\Kulturhus\Native\Web\Communication\VideoUploadForm::correction($_POST,$_FILES,$videoError);$nativeComposeState=['video_only'=>$videoState];$_GET['section']='compose';$error='';
 }catch(Throwable){$error=$videoError;}
}
if($error!==''&&($nativeView??null)==='social'&&input($_POST,'action')==='native-module'&&($_POST['operation']??null)==='social-photo-upload'){
 $photoError=http_response_code()>=500?'Fotoupload nicht bestätigt. Datei, Beschreibung und ursprünglichen Vorgang behalten. Gespeichertes Foto prüfen.':$error;
 try{$photoError=\Kulturhus\Native\Language\ImageStudioMessages::text($photoError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}
 if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$photoError],JSON_THROW_ON_ERROR);exit;}
 if(is_string($_POST['csrf']??null)&&isset($nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf']))try{$state=\Kulturhus\Native\Web\Communication\PhotoUploadForm::restore($_POST);$nativeComposeState=['photo_only'=>$state];$_GET['section']='compose';$error=$photoError;}catch(Throwable){}
}
if($error!==''&&($nativeView??null)==='social'&&input($_POST,'action')==='native-module'&&in_array($_POST['operation']??null,['social-canvas-save','social-canvas-review'],true)&&str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){
 $canvasError=http_response_code()>=500?'Bildspeicherung nicht bestätigt. Gestaltung und ursprünglichen Vorgang behalten. Gespeicherte Bildfassung prüfen.':$error;
 try{$canvasError=\Kulturhus\Native\Language\ImageStudioMessages::text($canvasError,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}
 header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$canvasError],JSON_THROW_ON_ERROR);exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'&&input($_POST,'action')==='native-module'&&\Kulturhus\Native\Web\Communication\HumanFormState::submitted($_POST)&&is_string($_POST['csrf']??null)&&isset($db,$nativeAccess,$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$_POST['csrf'])&&$error!==''){
 try{$nativeAccess->require('social','manage');$nativeAccess->require('social','edit');$humanState=\Kulturhus\Native\Web\Communication\HumanFormState::restore($_POST);$humanSource=(new \Kulturhus\Native\Communication\Replies($db,\Kulturhus\Native\Shared\SecretBox::fromFile(getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,2).'/.local/native-cms-integration/private.key')))->source($nativeAccess,$humanState['attention_id']);$_GET=array_replace($_GET,\Kulturhus\Native\Web\Communication\HumanFormState::route($nativeAccess,$humanSource));unset($_GET['reply']);$nativeComposeState=['human'=>$humanState+['error'=>$error]];}
 catch(Throwable){/* Original rejection remains visible without changing a send intention. */}
}
if($_SERVER['REQUEST_METHOD']==='POST'&&input($_POST,'action')==='website-native'&&input($_POST,'response_format')==='json'){http_response_code(http_response_code()>=400?http_response_code():400);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$error?:'Upload fehlgeschlagen.'],JSON_THROW_ON_ERROR);exit;}
if(!$workspaceAllowed){http_response_code(403);$error='Dieser Bereich ist für deinen Zugang nicht freigegeben.';}
if($view==='free'){try{$freeAccess=new \Kulturhus\Verein\FreeTables($db,(string)$_SESSION['actor']);if(input($_GET,'sheet')!=='')$freeAccess->table(input($_GET,'sheet'));if(isset($_GET['new_table'])&&\Kulturhus\Verein\Permissions::actor($db,(string)$_SESSION['actor'])['members_access']!=='write')throw new DomainException('Nur die Vereinsverwaltung kann neue Listen anlegen.');}catch(DomainException $ex){http_response_code(403);$error=\Kulturhus\Native\Language\FreeListMessages::failure($ex,$freeInterfaceText);}}
if($view==='minutes'){try{$minutesAccess=new \Kulturhus\Verein\Minutes($db,(string)$_SESSION['actor']);if(!$minutesAccess->canRead())throw new DomainException('Keine Berechtigung für Protokolle.',403);$minutesSource=\Kulturhus\Verein\Web\MinutesForm::query($_GET);if($_POST===[]&&$minutesSource['mid']!=='')$minutesAccess->entry($minutesSource['mid']);}catch(DomainException $ex){if(http_response_code()<400)http_response_code(\Kulturhus\Verein\Web\MinutesPageState::status($ex));$error=$error?:$ex->getMessage();}}
try{
 if(!$workflowWorkspace&&!($workspaceAllowed&&(($view==='settings'&&input($_GET,'tab')==='api')||($view==='documents'&&input($_GET,'tab')==='activity-reports')||($view==='portal'&&input($_GET,'tab')==='vouchers')||($view==='messages'&&input($_GET,'tab')==='inbox')))&&!in_array($view,['free','minutes','handbook'],true)&&!$canRead&&!$canFinance&&!$settings->canManage()&&!$inventory->canReadLoans()&&!$documents->canRead()&&!($canCalendar&&$view==='calendar')&&!($workspaceAllowed&&in_array($view,['website','social','newsletter','press'],true))&&!($canAdmissions&&$view==='portal'&&in_array(input($_GET,'tab'),['application','admissions'],true)))throw new DomainException('Keine Berechtigung für diese Vereinsdaten. Die Redaktionszentrale hat einen eigenen Zugang.');
 if($canWrite&&$view==='audit')$history=$registry->history();
 if($view==='members'&&($_GET['list']??null)==='birthdays')$filters['list']='birthdays';
 if($canRead&&$view==='members'&&($_GET['list']??null)!=='birthdays'){
 if($canWrite){$lists=$registry->savedLists();$history=$registry->history();}
 $requestedFilters=array_intersect_key($_GET,array_flip(['q','list','status','kind','sort','direction','as_of']));
 $filters=Registry::filters([]);
 if($view==='members'&&input($_GET,'saved')!==''){
  $found=false;foreach($lists as $list)if($list['id']===input($_GET,'saved')){$requestedFilters=json_decode($list['filters'],true,512,JSON_THROW_ON_ERROR);$found=true;$savedList=$list;break;}
  if(!$found)throw new DomainException('Liste nicht verfügbar.');
 }
 $filters=Registry::filters($requestedFilters);
 if(isset($savedList)&&$filters['list']==='birthdays')$occasionSavedQuery=\Kulturhus\Verein\Web\OccasionForm::saved($_GET,$savedList,$filters);
 $pageData=$registry->contactPage($filters,(int)input($_GET,'page','1'),(int)input($_GET,'size',$accountPreferences['page_size']));$filters=$pageData['filters'];
 $profileId=input($_GET,'id');
 if($_SERVER['REQUEST_METHOD']==='POST'&&in_array(input($_POST,'action'),['join','leave','assign-family','save-family'],true))$profileId=input($_POST,'id');
 if($profileId!==''){$profile=$registry->contact($profileId);$memberships=$registry->memberships($profileId);}
 }
 if($canCalendar&&$view==='calendar'){
 $calendarDate=input($_GET,'date',\Kulturhus\Verein\Events\Calendar\Presentation::today());$day=DateTimeImmutable::createFromFormat('!Y-m-d',$calendarDate,new DateTimeZone('Europe/Berlin'));
 if(!$day||$day->format('Y-m-d')!==$calendarDate)throw new DomainException('Ungültiges Kalenderdatum.');
 $calendarMode=input($_GET,'mode','month');if(!in_array($calendarMode,['month','week','day','agenda'],true))throw new DomainException('Ungültige Kalenderansicht.');
 $first=$day->modify('first day of this month');$gridStart=$first->modify('monday this week');
 $calendarFeed=new \Kulturhus\Verein\CalendarFeed($db,(string)$_SESSION['actor'],$registry,$nativeAccess??null);$calendarFilter=input($_GET,'calendar_filter','all');$calendarFilters=$calendarFeed->clubFilters();$calendarRange=\Kulturhus\Verein\CalendarFeed::range($calendarMode,$day);
 $events=$calendarFeed->between($calendarRange['from'],$calendarRange['to'],$calendarFilter,clubOnly:true);
 $calendarUpcoming=null;$calendarUpcomingFailure='';
 if(input($_GET,'tab')===''&&!array_intersect(['event','new_event','series'],array_keys($_GET))&&!in_array(input($_POST,'action'),['save-event','series-preview','series-save','calendar-people-browse','calendar-recipient-browse'],true)){try{$calendarUpcoming=\Kulturhus\Verein\Web\CalendarUpcoming::read($calendarFeed,$day,$calendarMode,$calendarFilter,$_GET);}catch(DomainException $upcomingFailure){$calendarUpcomingFailure=$upcomingFailure->getMessage();if(http_response_code()<400)http_response_code($upcomingFailure->getCode()?:422);}}
 $eventId=input($_GET,'event');if($_SERVER['REQUEST_METHOD']==='POST'&&in_array(input($_POST,'action'),['save-event','calendar-people-browse','calendar-recipient-browse'],true))$eventId=input($_POST,'id');
 if($eventId!=='')$event=$registry->appointment($eventId);
 }
}catch(DomainException $ex){if($view==='members'&&http_response_code()<400)http_response_code(422);$error=$error?:$ex->getMessage();}
catch(Throwable){http_response_code(500);$error=$error?:'Daten konnten nicht geladen werden. Bitte den Betrieb prüfen.';}
$form=$profile??['id'=>'','version'=>0,'kind'=>'person','name'=>'','email'=>'','address'=>'','phone'=>'','birthday'=>'','notes'=>'','honorary'=>0,'legacy_member_number'=>''];
if(!$profile&&input($_GET,'create_as')==='contact'&&input($_GET,'kind')==='organisation')$form['kind']='organisation';
if($error!==''&&input($_POST,'action')==='save'){foreach($form as $key=>$value)$form[$key]=input($_POST,$key,(string)($value??''));$form['honorary']=input($_POST,'honorary')==='1'?1:0;}
$eventForm=['id'=>$event['id']??'','version'=>$event['version']??0,'title'=>$event['title']??(input($_GET,'meeting')==='1'?'Mitgliederversammlung':''),'start'=>localDateTime($event['starts_at']??null),'end'=>localDateTime($event['ends_at']??null),'location'=>$event['location']??'','description'=>$event['description']??'','category'=>$event['category']??'verein','activity_state'=>$event['activity_state']??'planned','activity_kind'=>$event['activity_kind']??(input($_GET,'meeting')==='1'?'assembly':'event'),'report_summary'=>$event['report_summary']??'','source_reference'=>$event['source_reference']??'','attendees'=>array_column($event['attendees']??[],'contact_id')];
if($view==='calendar'&&isset($_GET['facebook_draft'])&&$invitations->canSend()){$fb=$_SESSION['facebook_history']??null;$draft=$fb&&$fb['actor']===$_SESSION['actor']&&$fb['expires']>=time()?($fb['rows'][(int)$_GET['facebook_draft']]??null):null;if($draft)$eventForm=array_replace($eventForm,$draft);}
if(($error!==''&&in_array(input($_POST,'action'),['save-event','series-preview','series-save'],true))||$calendarPeopleState!==null){foreach(['id','version','title','start','end','location','description','category','activity_state','activity_kind','report_summary','source_reference'] as $key)$eventForm[$key]=input($_POST,$key);$eventForm['attendees']=$calendarPeopleState['selected']??arrayInput($_POST,'attendees');}
$editingProfile=isset($_GET['id'])||isset($_GET['new'])||($_SERVER['REQUEST_METHOD']==='POST'&&in_array(input($_POST,'action'),['save','join','leave','assign-family','save-family'],true));
$profileCreationSaved=null;$profileCreationStateError='';
if($nativeManagerEnabled&&$view==='members'&&($_GET['new']??null)==='1'&&!isset($_GET['id'])&&!$canWrite){$profileCreationStateError='Aktuelle Mitgliederbearbeitung für diesen Anlagevorgang erforderlich.';if(http_response_code()<400)http_response_code(403);$error=$error?:$profileCreationStateError;}
if($nativeManagerEnabled&&$view==='members'&&($_GET['new']??null)==='1'&&!isset($_GET['id'])&&$canWrite){
 if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&!array_key_exists('create_request',$_GET)&&$error===''&&input($_GET,'tab')===''&&!isset($_GET['import'])&&in_array($_GET['create_as']??'member',['member','contact'],true))redirectTo(array_replace(scalarFields($_GET,['view','new','create_as','list','q','status','kind','sort','direction','as_of','page']),['view'=>'members','new'=>'1','create_request'=>bin2hex(random_bytes(16))]));
 $creationKey=array_key_exists('create_request',$_GET)?$_GET['create_request']:((($_POST['action']??null)==='save')?($_POST['create_request']??null):null);
 if($creationKey!==null){
  try{$profileCreationSaved=\Kulturhus\Verein\Web\MemberProfileCreation::session($db,(string)$_SESSION['auth_token'])->saved($creationKey);if($profileCreationSaved&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'){$_SESSION['notice']='Vorhandenes Profil dieses Anlagevorgangs geöffnet.';redirectTo(['view'=>'members','id'=>$profileCreationSaved]);}}
  catch(DomainException $failure){$profileCreationStateError=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$error?:$profileCreationStateError;}
  catch(Throwable){$profileCreationStateError='Anlagevorgang konnte nicht bestätigt werden. Aktuellen Speicherstand prüfen.';if(http_response_code()<400)http_response_code(503);$error=$error?:$profileCreationStateError;}
 }
}

if($view==='calendar'&&$error!=='')$error=$calendarText($error);
$editingEvent=isset($_GET['event'])||isset($_GET['new_event'])||in_array(input($_POST,'action'),['save-event','series-preview','calendar-people-browse','calendar-recipient-browse'],true);
if((($view==='documents'&&input($_GET,'tab')==='workflows')||($view==='portal'&&input($_GET,'tab')==='signatures'))&&$workspaceAllowed&&isset($workflowStore)){
 try{$workflowViewState=\Kulturhus\Verein\Documents\WorkflowBrowseState::prepare($workflowStore,$view==='portal'?array_replace($_GET,['wf_own'=>'1']):$_GET,$_POST,$error,(string)$_SESSION['csrf'],$workflowTemplateDraft??null,$workflowDocumentDraft??null,$workflowSignatures??null,$workflowReminder??null);}
 catch(Throwable){$workflowViewState=\Kulturhus\Verein\Documents\WorkflowBrowseState::failed('Workflowansicht konnte nicht geladen werden.',500);}
 if(($workflowViewState['http_status']??0)>0&&http_response_code()<400)http_response_code($workflowViewState['http_status']);
 if(($workflowViewState['error']??'')!=='')$error=$hseActionText($workflowViewState['error']);
}
if($view==='portal'&&input($_GET,'tab')==='documents'&&$workspaceAllowed&&isset($workflowManager)&&$workflowManager&&$workflowManager->allows(true)){
 $workflowOwnFilePage=null;$workflowOwnFileError='';
 try{$workflowOwnFilePage=$workflowManager->ownFilesPage($_GET['wf_signed_files_page']??1);}
 catch(DomainException $failure){$workflowOwnFileError=$failure->getMessage();if(http_response_code()<400)http_response_code(422);}
}
if($view==='portal'&&input($_GET,'tab')==='vouchers'){
 require_once __DIR__.'/../src/Events/EventVoucherViewState.php';
 $voucherViewState=\Kulturhus\Verein\EventVoucherViewState::prepare(new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null),$_GET);
 $voucherCatalog=isset($nativeAccess)?new \Kulturhus\Native\Language\Catalog($db,$nativeAccess):null;
 $voucherInterfaceMessages=$voucherCatalog?->messages()??[];
 if(!$voucherViewState['personal']){
  require_once __DIR__.'/../src/Events/EventVoucherManageState.php';
  $voucherActionLocalized=$voucherActionFailure instanceof \Throwable?\Kulturhus\Native\Language\EventVoucherMessages::failure($voucherActionFailure,$voucherInterfaceMessages,$voucherCatalog?->locale()??'de'):$voucherActionError;
  $voucherManageState=\Kulturhus\Verein\EventVoucherManageState::prepare(new \Kulturhus\Verein\EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null),(string)$_SESSION['actor'],$_GET,$_SESSION,$voucherActionDraft,$voucherActionLocalized,$voucherActionStatus);
  if($voucherManageState['task']==='mail'&&$voucherManageState['error']===''&&$voucherManageState['event']!==null){
   $voucherMailState=(new \Kulturhus\Verein\EventVoucherMail($db,(string)$_SESSION['actor'],$websiteCalendar??null))->workspace($voucherManageState,$_GET,$_SESSION,$_POST);
   if($voucherMailState['http_status']>0&&http_response_code()<400)http_response_code($voucherMailState['http_status']);
  }
  if($voucherManageState['http_status']>0&&http_response_code()<400)http_response_code($voucherManageState['http_status']);
 }
 if($voucherViewState['http_status']>0&&http_response_code()<400)http_response_code($voucherViewState['http_status']);
 if($voucherViewState['http_status']===503){http_response_code(503);$error=\Kulturhus\Native\Language\EventVoucherMessages::text($voucherViewState['error']?:'Gutscheine werden gerade bearbeitet. Bitte erneut öffnen.',$voucherInterfaceMessages);}
 if($error!=='')$error=\Kulturhus\Native\Language\EventVoucherMessages::text($error,$voucherInterfaceMessages);
}
if($view==='portal'&&input($_GET,'tab')==='safety'&&$workspaceAllowed&&(input($_GET,'volunteer')==='1'||input($_GET,'person')!==''||isset($_GET['volunteer_receipt']))){
 require_once __DIR__.'/../src/People/Members/VolunteerFormState.php';
 $volunteerFormState=\Kulturhus\Verein\VolunteerFormState::prepare($db,(string)$_SESSION['actor'],$_GET,$_POST,$_SESSION);
 if($volunteerFormState['http_status']>0&&http_response_code()<400)http_response_code($volunteerFormState['http_status']);
 if($error!=='')$error=$hseActionText($error);
}
if(in_array($_POST['action']??null,['delete-contact','restore-contact'],true)&&$error!=='')$error=\Kulturhus\Native\Language\MemberCatalogueMessages::text($error,isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\MemberCatalogueMessages::messages('de'));
if($view==='portal'&&input($_GET,'tab')==='application'&&isset($nativeAccess)){$applicationDecisionMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\ApplicationReviewMessages::text($error,$applicationDecisionMessages);$notice=\Kulturhus\Native\Language\ApplicationReviewMessages::text($notice,$applicationDecisionMessages);}
if($workspaceAllowed&&\Kulturhus\Verein\Web\ApplicationCataloguePage::applies(array_replace($_GET,['view'=>$view]),$_POST)){$applicationCatalogueState=\Kulturhus\Verein\Web\ApplicationCataloguePage::prepare($db,(string)$_SESSION['actor'],$_GET);if($applicationCatalogueState['status']>=400&&http_response_code()<400)http_response_code($applicationCatalogueState['status']);}
if($workspaceAllowed&&\Kulturhus\Verein\Web\MemberArchivePage::applies(array_replace($_GET,['view'=>$view]))){
 $memberArchiveState=\Kulturhus\Verein\Web\MemberArchivePage::prepare(new \Kulturhus\Verein\ContactArchive($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey()),$_GET,$_POST);if($memberArchiveState['status']>=400&&http_response_code()<400)http_response_code($memberArchiveState['status']);
}
if($workspaceAllowed&&\Kulturhus\Verein\Web\DocumentSourcePage::applies(array_replace($_GET,['view'=>$view]),$_POST)){
 $documentSourceState=\Kulturhus\Verein\Web\DocumentSourcePage::prepare($documents,$_GET);if($documentSourceState['status']>=400&&http_response_code()<400)http_response_code($documentSourceState['status']);
}
if($view==='portal'&&input($_GET,'tab')==='documents'&&$workspaceAllowed){
 require_once __DIR__.'/../src/Documents/PersonalDocumentState.php';
 $personalDocumentState=\Kulturhus\Verein\Documents\PersonalDocumentState::prepare(new \Kulturhus\Verein\PortalDocuments($db,(string)$_SESSION['actor']),$_GET,$websiteCalendar??null);
 if($personalDocumentState['http_status']>0&&http_response_code()<400)http_response_code($personalDocumentState['http_status']);
}
if($view==='documents'&&input($_GET,'tab')==='checks'&&$workspaceAllowed){
 require_once __DIR__.'/../src/Documents/DocumentCheckState.php';
 $checkService=new \Kulturhus\Verein\DocumentChecks($db,(string)$_SESSION['actor'],$registry);
 $checkViewState=\Kulturhus\Verein\Documents\DocumentCheckState::prepare($db,$checkService,$documents,$registry,$_GET,$_POST,(string)$_SESSION['csrf'],$error,$checkDraft??null);
 if(($checkViewState['http_status']??0)>0&&http_response_code()<400)http_response_code($checkViewState['http_status']);
 if(($checkViewState['error']??'')!=='')$error=$hseActionText($checkViewState['error']);
}
if($view==='documents'&&input($_GET,'tab')==='inspections'){
 if($workspaceAllowed&&$error===''){
  try{
   \Kulturhus\Verein\DocumentWorkflow\InspectionStore::state(['query'=>input($_GET,'inspection_query'),'status'=>input($_GET,'inspection_status','active'),'scope'=>input($_GET,'inspection_scope','all'),'page'=>input($_GET,'inspection_page','1')]);
   if(isset($_GET['inspection_history_page'])&&(!is_string($_GET['inspection_history_page'])||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$_GET['inspection_history_page'])))throw new DomainException('Verlaufsseite prüfen.');
  }catch(DomainException $failure){if(http_response_code()<400)http_response_code(422);$error=$failure->getMessage();}
 }
 if($error!=='')$error=$hseActionText($error);
}
if(in_array(input($_POST,'action'),['payment-tags-assign','payment-tags-save'],true)&&$error!==''){
 if(http_response_code()<400)http_response_code(422);
 if(isset($nativeAccess))$error=\Kulturhus\Native\Language\PaymentTagMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
}
if(input($_POST,'action')==='create-account'&&$error!==''&&isset($nativeAccess))$error=\Kulturhus\Native\Language\BankLedgerMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
if(in_array($_POST['action']??null,\Kulturhus\Verein\Web\AccountSettingsForm::ACTIONS,true)&&$error!==''&&isset($nativeAccess))$error=\Kulturhus\Native\Language\BankLedgerMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
if(input($_POST,'action')==='reverse-payment'&&$error!==''&&isset($nativeAccess))$error=\Kulturhus\Native\Language\FinanceBankMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
$tab=input($_GET,'tab');$financeEntry=null;$accountRows=[];$financeRows=[];$goodsRows=[];
$period=input($_GET,'period','6');if(!in_array($period,['3','6','12'],true))$period='6';
$reportEnd=\Kulturhus\Verein\Finance::today();$reportStart=(new DateTimeImmutable($reportEnd,new DateTimeZone('Europe/Berlin')))->modify('first day of this month')->modify('-'.((int)$period-1).' months')->format('Y-m-d');
$overview=['months'=>[],'income'=>0,'expense'=>0,'donations'=>0,'receivables'=>0,'payables'=>0];
if($canFinance&&$view!=='dashboard'&&!$taxLocalRead){try{
 $accountRows=$finance->accounts();$overview=$finance->overview($reportStart,$reportEnd);
 if(in_array($view,['finance','donations'],true)&&!($view==='finance'&&$tab==='inbox')){
  $ff=scalarFields($_GET,['kind','status','from','to','q','account','direction']);
  if($view==='donations')$ff['kind']='donation';if($tab==='contributions')$ff['kind']='contribution';
  if($financialOriginalSource['state']!=='none'){$financialOriginalSource=(new \Kulturhus\Verein\Web\FinancialOriginalSource($db,(string)$_SESSION['actor']))->read($financialOriginalSource);if($financialOriginalSource['state']==='ready'&&$financialOriginalSource['kind']==='receipt')$financeEntry=$financialOriginalSource['row'];}
  elseif(input($_GET,'entry')!=='')$financeEntry=$finance->entry(input($_GET,'entry'));
  elseif($view==='donations'&&$tab==='goods'){$goodsCatalogue=$finance->goodsPage($_GET['goods_page']??1,$_GET['goods_q']??'',$_GET['goods_origin']??'',$_GET['goods_evidence']??'',$_GET['goods_sort']??'recent',$_GET['goods_columns']??'summary');$goodsRows=$goodsCatalogue['rows'];}
  elseif(($view==='finance'&&in_array($tab,['receipts','contributions'],true))||($view==='donations'&&in_array($tab,['','money'],true))){$ff=\Kulturhus\Verein\Web\FinanceCatalogueFilters::read($_GET,$view,$tab);$financeCatalogue=$finance->entriesPage($ff,$_GET['page']??1,$_GET['sort']??'document_date',$_GET['order']??'desc',\Kulturhus\Verein\Web\FinanceCatalogueFilters::pageSize($_GET));$financeRows=$financeCatalogue['rows'];}
 }
}catch(DomainException $ex){$error=$error?:$ex->getMessage();}catch(Throwable){http_response_code(500);$error=$error?:'Finanzdaten konnten nicht geladen werden.';}}
// Recoverable source pages use the existing manager-fragment validation contract.
if(in_array($financialOriginalSource['state'],['invalid','unavailable'],true)){$error='';http_response_code(422);}
// Catalogue GET failures use fixed interface labels; original filter values stay outside translation.
if(isset($nativeAccess)&&$error!==''&&(input($_POST,'action')==='finance-export'||(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&input($_GET,'entry')===''&&!isset($_GET['new_entry'])&&(($view==='finance'&&in_array($tab,['receipts','contributions'],true))||($view==='donations'&&in_array($tab,['','money'],true))))))$error=\Kulturhus\Native\Language\FinanceReceiptMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
if($view==='dashboard'&&$workspaceAllowed){
 $dashboardState=(new \Kulturhus\Verein\Web\DashboardState($db,(string)$_SESSION['actor'],$websiteCalendar??null))->prepare($_GET);
 if($dashboardState['http_status']>0&&http_response_code()<400)http_response_code($dashboardState['http_status']);
 $dashboardMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\ManagerMessages::messages('de');
 if($dashboardHandled)$error=\Kulturhus\Native\Language\DashboardMessages::text($error,$dashboardMessages);
 if($notice==='Einführung als gelesen gespeichert.')$notice=\Kulturhus\Native\Language\DashboardMessages::text($notice,$dashboardMessages);
}
require __DIR__.'/../src/Web/role-page.php';
require __DIR__.'/../src/Web/general-settings-page.php';
require __DIR__.'/../src/Web/donation-form-page.php';
require __DIR__.'/../src/Web/donation-contact-page.php';
require __DIR__.'/../src/Web/goods-donation-page.php';
require __DIR__.'/../src/Web/finance-report-page.php';
require __DIR__.'/../src/Web/finance-export-page.php';
require __DIR__.'/../src/Web/asset-book-source-page.php';
require __DIR__.'/../src/Web/invoice-inbox-source-page.php';
require __DIR__.'/../src/Web/invoice-mail-source-page.php';
require __DIR__.'/../src/Web/member-excel-page.php';
require __DIR__.'/../src/Web/finance-preferences-page.php';
require __DIR__.'/../src/Web/finance-bank-page.php';
require __DIR__.'/../src/Web/finance-tax-page.php';
require __DIR__.'/../src/Web/mail-settings-page.php';
require __DIR__.'/../src/Web/paper-settings-page.php';
require __DIR__.'/../src/Web/text-settings-page.php';
require __DIR__.'/../src/Web/outgoing-mail-page.php';
require __DIR__.'/../src/Web/outgoing-invoice-page.php';
require __DIR__.'/../src/Web/outgoing-correction-page.php';
if(in_array($outgoingInvoiceSource['state'],['invalid','unavailable'],true)){$error='';http_response_code(422);}
require __DIR__.'/../src/Web/contribution-run-page.php';
require __DIR__.'/../src/Web/account-native-page.php';
require __DIR__.'/../src/Web/contribution-delivery-page.php';
require __DIR__.'/../src/Web/sepa-page.php';
require __DIR__.'/../src/Web/transfer-page.php';
require __DIR__.'/../src/Web/donation-cover-page.php';
require __DIR__.'/../src/Web/debit-notice-page.php';
require __DIR__.'/../src/Web/invoice-reminder-page.php';
require __DIR__.'/../src/Web/invoice-reminder-decision-page.php';
require __DIR__.'/../src/Web/numbering-page.php';
require __DIR__.'/../src/Web/automation-page.php';
require __DIR__.'/../src/Web/billing-page.php';
require __DIR__.'/../src/Web/personal-price-page.php';
require __DIR__.'/../src/Web/assignment-page.php';
require __DIR__.'/../src/Web/tariff-page.php';
if($error===''&&!($checkBrowseDraft??false)&&class_exists(\Kulturhus\Verein\LiveUploads::class,false))\Kulturhus\Verein\LiveUploads::finish();
if($nativeManagerEnabled){
 $nativeView=\Kulturhus\Native\Integration\Routes::view(array_replace($_GET,['view'=>$view]));
 if($nativeView){try{$nativeManagerContent=$nativeEmbedded->main($nativeView,input($_GET,'native_item'),isset($_GET['chat_before'])?(int)input($_GET,'chat_before'):PHP_INT_MAX,$nativeComposeState??null);$workspaceAllowed=true;if($canCalendar&&$nativeView==='press'&&input($_GET,'section')==='planning')$nativeManagerContent='<div class="content"><a class="button secondary" href="'.e(url(['view'=>'calendar','mode'=>'agenda','calendar_filter'=>$nativeView])).'">Planung im gemeinsamen Kalender</a></div>'.$nativeManagerContent;}catch(InvalidArgumentException $ex){http_response_code(422);$error=$ex->getMessage();$workspaceAllowed=false;}catch(DomainException|RuntimeException $ex){http_response_code(403);$error=$ex->getMessage();$workspaceAllowed=false;}}
 if($error!==''&&$nativeView==='media'&&(in_array(input($_GET,'section'),['library','removed','storage'],true)||str_starts_with(input($_POST,'operation'),'media-')||input($_POST,'operation')==='document-quota-save')){
  try{$error=\Kulturhus\Native\Language\MediaMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){/* Retain the original rejection if the current identity cannot read its language. */}
 }
 if($error!==''&&(($nativeView==='media'&&(str_starts_with(input($_POST,'operation'),'album-')||!in_array(input($_GET,'section'),['library','removed','storage','files'],true)))||(in_array($nativeView,['website','news','events'],true)&&(($nativeView==='website'&&input($_GET,'section')==='trash')||in_array(input($_POST,'operation'),['page-copy','page-trash','page-trash-restore','page-purge','page-restore','page-place','page-fieldtypes-review','page-plan','page-cancel-plan','news-plan','news-cancel-plan'],true))))){
  try{$error=\Kulturhus\Native\Language\ContentMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){/* Retain the original rejection if the current identity cannot read its language. */}
 }
 if($error!==''&&(in_array($nativeView,['press','newsletter','chat','navigation','model'],true)||($nativeView==='social'&&input($_POST,'operation')==='communication-save'))){try{$error=\Kulturhus\Native\Language\ContentMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){/* Keep the original rejection when the current identity cannot read its language. */}}
 if($error!==''&&\Kulturhus\Native\Web\Communication\SocialLibrary::applies($nativeView??'',$_POST,$_GET)){try{$error=\Kulturhus\Native\Language\ConversationMessages::text(\Kulturhus\Native\Language\ContentMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->contentMessages()),(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}}
 if($error!==''&&\Kulturhus\Native\Web\Communication\EventSources::applies($nativeView??'',$_POST,$_GET)){try{$error=\Kulturhus\Native\Language\ContentMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->contentMessages());}catch(Throwable){}}
 if($error!==''&&\Kulturhus\Native\Web\Communication\ConversationRequest::applies($nativeView??'',$_POST,$_GET)){try{$error=\Kulturhus\Native\Language\ConversationMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){/* Current access remains authoritative. */}}
 if($_SERVER['REQUEST_METHOD']==='POST'&&input($_POST,'action')==='native-module'&&str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){if(http_response_code()<400)http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$error?:($nativeComposeState['comment_correction']['error']??'Vorgang nicht gespeichert.')],JSON_THROW_ON_ERROR);exit;}
}
if($view==='inventory'&&$tab==='loans'&&$workspaceAllowed&&\Kulturhus\Verein\Web\InventoryLoanActionForm::applies($_POST,$_GET)){
 $inventoryLoanActionState=\Kulturhus\Verein\Web\InventoryLoanActionForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);
 if($inventoryLoanActionState['status']>=400){if(http_response_code()<400)http_response_code($inventoryLoanActionState['status']);$error=$error?:$inventoryLoanActionState['error'];}
 if(isset($nativeAccess)){$inventoryItemFieldError=\Kulturhus\Verein\FormValidation::fieldError($error);$inventoryLoanActionMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryLoanActionMessages::text($error,$inventoryLoanActionMessages);$notice=\Kulturhus\Native\Language\InventoryLoanActionMessages::text($notice,$inventoryLoanActionMessages);}
}
if($view==='inventory'&&$tab==='loans'&&$workspaceAllowed&&\Kulturhus\Verein\Web\InventoryLoanCreateForm::applies($_POST,$_GET)){
 $inventoryLoanCreateState=\Kulturhus\Verein\Web\InventoryLoanCreateForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);
 if($inventoryLoanCreateState['redirect']!==null&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&$error===''){if($inventoryLoanCreateState['saved']!==null)$_SESSION['notice']='Vorhandene Ausleihe dieses Vorgangs geöffnet. Es wurde nichts erneut reserviert.';redirectTo($inventoryLoanCreateState['redirect']);}
 if($inventoryLoanCreateState['status']>=400){if(http_response_code()<400)http_response_code($inventoryLoanCreateState['status']);$error=$error?:$inventoryLoanCreateState['error'];}
 if(isset($nativeAccess)){$inventoryItemFieldError=\Kulturhus\Verein\FormValidation::fieldError($error);$inventoryLoanMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryLoanMessages::text($error,$inventoryLoanMessages);$notice=\Kulturhus\Native\Language\InventoryLoanMessages::text($notice,$inventoryLoanMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&\Kulturhus\Verein\Web\InventoryIndividualForm::applies($_POST,$_GET)){
 $inventoryIndividualState=\Kulturhus\Verein\Web\InventoryIndividualForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);
 if($inventoryIndividualState['redirect']!==null&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&$error===''){if($inventoryIndividualState['saved']!==null)$_SESSION['notice']='Vorhandene Einzelstückgruppe dieses Vorgangs geöffnet. Es wurde nichts erneut angelegt.';redirectTo($inventoryIndividualState['redirect']);}
 if($inventoryIndividualState['status']>=400){if(http_response_code()<400)http_response_code($inventoryIndividualState['status']);$error=$error?:$inventoryIndividualState['error'];}
 if(isset($nativeAccess)){$inventoryItemFieldError=\Kulturhus\Verein\FormValidation::fieldError($error);$inventoryIndividualMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryIndividualMessages::text($error,$inventoryIndividualMessages);$notice=\Kulturhus\Native\Language\InventoryIndividualMessages::text($notice,$inventoryIndividualMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&\Kulturhus\Verein\Web\InventoryItemForm::applies($_POST,$_GET)){
 $inventoryItemState=\Kulturhus\Verein\Web\InventoryItemForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);
 if($inventoryItemState['redirect']!==null&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&$error===''){if($inventoryItemState['saved']!==null)$_SESSION['notice']='Vorhandener Gegenstand dieses Vorgangs geöffnet. Es wurde kein weiterer Gegenstand angelegt.';redirectTo($inventoryItemState['redirect']);}
 if($inventoryItemState['status']>=400){if(http_response_code()<400)http_response_code($inventoryItemState['status']);$error=$error?:$inventoryItemState['error'];}
 if(isset($nativeAccess)){$inventoryItemFieldError=\Kulturhus\Verein\FormValidation::fieldError($error);$inventoryItemMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryItemMessages::text($error,$inventoryItemMessages);$notice=\Kulturhus\Native\Language\InventoryItemMessages::text($notice,$inventoryItemMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&(\Kulturhus\Verein\Web\InventoryInvoiceForm::applies($_POST,$_GET)||($canFinance&&is_string($_GET['item']??null)&&$_GET['item']!==''))){
 $inventoryInvoiceState=\Kulturhus\Verein\Web\InventoryInvoiceForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET,$inventoryInvoiceIncomingFiles??$_FILES);
 if($inventoryInvoiceState['status']>=400){if(http_response_code()<400)http_response_code($inventoryInvoiceState['status']);$error=$error?:$inventoryInvoiceState['error'];}
 if(isset($nativeAccess)){$inventoryInvoiceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryInvoiceMessages::text($error,$inventoryInvoiceMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&(\Kulturhus\Verein\Web\InventoryLifecycleForm::applies($_POST,$_GET)||(is_string($_GET['item']??null)&&$_GET['item']!==''))){
 $inventoryLifecycleState=\Kulturhus\Verein\Web\InventoryLifecycleForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);
 if($inventoryLifecycleState['status']>=400){if(http_response_code()<400)http_response_code($inventoryLifecycleState['status']);$error=$error?:$inventoryLifecycleState['error'];}
 if(isset($nativeAccess)){$inventoryLifecycleMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryLifecycleMessages::text($error,$inventoryLifecycleMessages);$notice=\Kulturhus\Native\Language\InventoryLifecycleMessages::text($notice,$inventoryLifecycleMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&(is_string($_GET['item']??null)&&$_GET['item']!=='')){
 $inventoryHistoryState=\Kulturhus\Verein\Web\InventoryHistoryForm::state($inventory,$_GET);
 if($inventoryHistoryState['status']>=400){if(http_response_code()<400)http_response_code($inventoryHistoryState['status']);$error=$error?:$inventoryHistoryState['error'];}
 if(isset($nativeAccess)){$inventoryHistoryMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryHistoryMessages::text($error,$inventoryHistoryMessages);$notice=\Kulturhus\Native\Language\InventoryHistoryMessages::text($notice,$inventoryHistoryMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&(input($_POST,'action')==='inventory-file'||array_key_exists('file_request',$_GET)||(is_string($_GET['item']??null)&&$_GET['item']!==''))){
 $inventoryFileState=\Kulturhus\Verein\Web\InventoryFileForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET,$_FILES);
 if($inventoryFileState['status']>=400){if(http_response_code()<400)http_response_code($inventoryFileState['status']);$error=$error?:$inventoryFileState['error'];}
 if(isset($nativeAccess)){$inventoryFileMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryFileMessages::text($error,$inventoryFileMessages);$notice=\Kulturhus\Native\Language\InventoryFileMessages::text($notice,$inventoryFileMessages);}
}
if($view==='inventory'&&$tab==='items'&&$workspaceAllowed&&(input($_POST,'action')==='inventory-move'||(is_string($_GET['item']??null)&&$_GET['item']!==''))){$inventoryMoveState=\Kulturhus\Verein\Web\InventoryMoveForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);if($inventoryMoveState['status']>=400){if(http_response_code()<400)http_response_code($inventoryMoveState['status']);$error=$error?:$inventoryMoveState['error'];}if(isset($nativeAccess)){$inventoryMoveFieldError=($inventoryItemFieldError??false)||\Kulturhus\Verein\FormValidation::fieldError($error);$inventoryMoveMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryMoveMessages::text($error,$inventoryMoveMessages);$notice=\Kulturhus\Native\Language\InventoryMoveMessages::text($notice,$inventoryMoveMessages);}}
if($view==='material'&&$workspaceAllowed){
 $inventoryCatalogueState=\Kulturhus\Verein\Web\InventoryCatalogueForm::state($_GET,'material');
 if($inventoryCatalogueState['blocked']){if(http_response_code()<400)http_response_code(422);$error=$error?:$inventoryCatalogueState['error'];}
 if(isset($nativeAccess)){$materialMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryCatalogueMessages::text($error,$materialMessages);}
}
if($view==='inventory'&&$workspaceAllowed&&\Kulturhus\Verein\Web\InventoryCatalogueForm::applies($_GET,$tab?:'where')){
 $inventoryCatalogueState=\Kulturhus\Verein\Web\InventoryCatalogueForm::state($_GET,$tab?:'where');
 if($inventoryCatalogueState['blocked']){if(http_response_code()<400)http_response_code(422);$error=$error?:$inventoryCatalogueState['error'];}
 if(isset($nativeAccess)){$inventoryCatalogueMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryCatalogueMessages::text($error,$inventoryCatalogueMessages);}
}
if($view==='inventory'&&$tab==='locations'&&$workspaceAllowed){
 $inventoryLocationState=\Kulturhus\Verein\Web\InventoryLocationForm::state($db,(string)$_SESSION['actor'],$_POST,$_GET);
 if(($inventoryLocationState['redirect']??null)!==null&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&$error===''){if($inventoryLocationState['saved']!==null)$_SESSION['notice']='Vorhandener Lagerort dieses Vorgangs geöffnet. Es wurde kein weiterer Lagerort angelegt.';redirectTo($inventoryLocationState['redirect']);}
 if($inventoryLocationState['status']>=400){if(http_response_code()<400)http_response_code($inventoryLocationState['status']);$error=$error?:$inventoryLocationState['error'];}
}
if($view==='inventory'&&$tab==='locations'&&isset($nativeAccess)){$inventoryLocationFieldError=\Kulturhus\Verein\FormValidation::fieldError($error);$inventoryLocationMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InventoryLocationMessages::text($error,$inventoryLocationMessages);$notice=\Kulturhus\Native\Language\InventoryLocationMessages::text($notice,$inventoryLocationMessages);}
if($view==='members'&&isset($nativeAccess)&&(isset($_GET['id'])||isset($_GET['new'])||input($_POST,'action')==='save')){$memberProfileFieldError=\Kulturhus\Verein\FormValidation::fieldError($error);$memberProfileMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\MemberProfileMessages::failure($error,$memberProfileMessages);$notice=\Kulturhus\Native\Language\MemberProfileMessages::text($notice,$memberProfileMessages);}
if($view==='members'&&!$editingProfile&&$tab===''&&!isset($_GET['import'])&&isset($nativeAccess)){$memberCatalogueMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\MemberCatalogueMessages::text($error,$memberCatalogueMessages);$notice=\Kulturhus\Native\Language\MemberCatalogueMessages::text($notice,$memberCatalogueMessages);}
if(isset($nativeAccess)&&(input($_POST,'action')==='contribution-invoice'||in_array($notice,['Beitragsrechnung erstellt.','Rechnungsentwurf gespeichert. Fehlende Angaben bitte ergänzen.'],true))){$contributionInvoiceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\FinanceReceiptMessages::text($error,$contributionInvoiceMessages);$notice=\Kulturhus\Native\Language\FinanceReceiptMessages::text($notice,$contributionInvoiceMessages);}
// Translate only declared account/journal interface messages; raw bank originals
// and the separate receipt/transfer workflows keep their own contexts.
if($view==='finance'&&(in_array($tab,['','bank'],true)||(in_array($tab,['receipts','contributions'],true)&&input($_GET,'entry')!==''))&&isset($nativeAccess)){
 $bankLedgerMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
 $error=\Kulturhus\Native\Language\BankDialogMessages::text(\Kulturhus\Native\Language\FinanceMatchingMessages::text($error,$bankLedgerMessages),$bankLedgerMessages);
 $notice=\Kulturhus\Native\Language\BankDialogMessages::text(\Kulturhus\Native\Language\FinanceMatchingMessages::text($notice,$bankLedgerMessages),$bankLedgerMessages);
}
if($view==='finance'&&isset($nativeAccess)&&(isset($_GET['new_entry'])||input($_POST,'action')==='create-entry'||input($_GET,'entry')!=='')){
 $manualFinanceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\ManualFinanceMessages::text($error,$manualFinanceMessages);$notice=\Kulturhus\Native\Language\ManualFinanceMessages::text($notice,$manualFinanceMessages);
}
if($view==='finance'&&isset($nativeAccess)&&input($_GET,'entry')!==''){$invoiceReviewMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\InvoiceReviewMessages::text($error,$invoiceReviewMessages);$notice=\Kulturhus\Native\Language\InvoiceReviewMessages::text($notice,$invoiceReviewMessages);}
if(isset($nativeAccess)&&(isset($_GET['edit_positions'])||input($_POST,'action')==='update-positions')){$positionMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\PositionMessages::text($error,$positionMessages);$notice=\Kulturhus\Native\Language\PositionMessages::text($notice,$positionMessages);}
if(isset($nativeAccess)&&input($_POST,'action')==='outgoing-hybrid-save'){$outgoingElectronicMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\OutgoingElectronicMessages::text($error,$outgoingElectronicMessages);$notice=\Kulturhus\Native\Language\OutgoingElectronicMessages::text($notice,$outgoingElectronicMessages);}
if(isset($nativeAccess)&&(isset($_GET['outgoing_einvoice'])||input($_POST,'action')==='outgoing-einvoice-save')){$xinvoiceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\XInvoiceMessages::text($error,$xinvoiceMessages);$notice=\Kulturhus\Native\Language\XInvoiceMessages::text($notice,$xinvoiceMessages);}
if($view==='finance'&&$tab==='tax'&&input($_GET,'tax_filings')==='1'&&isset($nativeAccess)){$filingActionMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\TaxFilingMessages::text($error,$filingActionMessages);$notice=\Kulturhus\Native\Language\TaxFilingMessages::text($notice,$filingActionMessages);}
if(isset($nativeAccess)&&in_array(input($_POST,'action'),[...\Kulturhus\Verein\BalanceSourceDecisionForm::ACTIONS,...\Kulturhus\Verein\BalancePostingForm::ACTIONS,...\Kulturhus\Verein\BalanceYearClosingForm::ACTIONS,...\Kulturhus\Verein\BalanceSetupForm::ACTIONS,...\Kulturhus\Verein\BalanceMappingForm::ACTIONS],true)){$annualSourceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\BalanceFilingMessages::text($error,$annualSourceMessages);$notice=\Kulturhus\Native\Language\BalanceFilingMessages::text($notice,$annualSourceMessages);}
if(isset($nativeAccess)&&input($_POST,'action')==='balance-close'&&$error!=='')try{$yearClosingInput=\Kulturhus\Verein\BalanceYearClosingForm::metadata($_POST);$error=(new \Kulturhus\Verein\BalanceYearClosingRead($db,(string)$_SESSION['actor']))->failure($error,$yearClosingInput['year'],$annualSourceMessages??[]);}catch(DomainException){}
if(isset($nativeAccess)&&input($_POST,'action')===\Kulturhus\Verein\BalanceMappingForm::ACTION&&$error!=='')try{$mappingErrorInput=\Kulturhus\Verein\BalanceMappingForm::metadata($_POST);$error=(new \Kulturhus\Verein\BalanceMappingCurrentRead($db,(string)$_SESSION['actor']))->failure($error,$mappingErrorInput['year'],$annualSourceMessages??[]);}catch(DomainException){}
require \Kulturhus\Verein\Web\Templates::path('layout');
