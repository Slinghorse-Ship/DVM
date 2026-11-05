<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,PaperDraft,PaperInput,PaperRequests};
$papersettingsHandled=false;$papersettingsPostedDraft=null;$papersettingsRequests=null;$papersettingsAcceptStarted=false;$papersettingsMethod=$_SERVER['REQUEST_METHOD']??'GET';
$papersettingsNativeRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&($_GET['tab']??null)==='paper';
$papersettingsLegacyPost=($nativeManagerEnabled??false)&&$papersettingsMethod==='POST'&&($_POST['action']??null)==='settings-save'&&($_POST['section']??null)==='paper';
$papersettingsPost=($nativeManagerEnabled??false)&&$papersettingsMethod==='POST'&&($papersettingsLegacyPost||($_POST['action']??null)==='paper-settings-native');
if(!$papersettingsNativeRoute&&!$papersettingsPost)return;
$papersettingsStore=static function(array $draft):void{$_SESSION['general_settings_drafts'][$draft['id']]=$draft;};
$papersettingsPrivate=static function():PaperDraft{try{return new PaperDraft(\Kulturhus\Verein\ContactArchive::serverKey());}catch(DomainException$failure){throw new DomainException($failure->getMessage(),503,$failure);}};
$papersettingsUpload=static function(array $draft)use($db,$papersettingsPrivate):array{
    $file=$_FILES['logo']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return$draft;
    \Kulturhus\Verein\LiveUploads::prepareRequest($db,(string)$_SESSION['actor'],(string)$_POST['action'],$_POST);$file=$_FILES['logo'];
    if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_string($file['name']??null)||!\Kulturhus\Verein\LiveUploads::file($file['tmp_name']))throw new DomainException('Die Logodatei wurde nicht vollständig übertragen. Erhaltene Angaben bleiben korrigierbar.',422);
    $prepared=\Kulturhus\Verein\LiveUploads::prepared($file['tmp_name'],$file['name'],2097152);
    return$papersettingsPrivate()->upload($draft,PaperInput::upload($prepared->name,$prepared->bytes,$prepared->source));
};
try{
    if(!$papersettingsNativeRoute)throw new DomainException('Briefpapier im vorhandenen Einstellungsbereich öffnen.',403);
    if(!$workspaceAllowed)throw new DomainException('Aktuelle Vereinseinstellungen für Briefpapier erforderlich.',403);
    $papersettingsRequests=new PaperRequests($db,(string)$_SESSION['actor']);$papersettingsActor=$papersettingsRequests->assertAccess('paper');
    if(isset($_GET['papersettings_pdf'])){
        if(!in_array($papersettingsMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $pdfDraft=GeneralDraft::get($_SESSION,$papersettingsActor,'paper',$_GET['papersettings_pdf']);
        if($pdfDraft['pending']||$pdfDraft['accepted']!==null||!is_array($pdfDraft['review'])||!is_string($_GET['papersettings_review']??null)||!hash_equals($pdfDraft['review']['hash'],$_GET['papersettings_review']))throw new DomainException('Den eigenen vollständig geprüften Briefpapierentwurf öffnen.',409);
        $logo=$pdfDraft['input']['logo']!==null?$papersettingsPrivate()->logo($pdfDraft):null;$preview=$papersettingsRequests->preview($pdfDraft['input'],$logo);
        if(!hash_equals($pdfDraft['review']['hash'],$preview['review']['hash'])||$pdfDraft['input']['version']!==(string)$preview['review']['current']['version'])throw new DomainException('Das Briefpapier wurde inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
        $bytes=\Kulturhus\Verein\DocumentPreview::pdf($preview['document']);header('Content-Type: application/pdf');header('Content-Disposition: '.(($_GET['papersettings_pdf_download']??null)==='1'?'attachment':'inline').'; filename="dvm-briefpapier-entwurf.pdf"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($papersettingsMethod!=='HEAD')echo$bytes;exit;
    }
    if(isset($_GET['papersettings_original'])||isset($_GET['papersettings_revision_original'])){
        if(!in_array($papersettingsMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(isset($_GET['papersettings_original'])){if(!is_string($_GET['papersettings_original']))throw new DomainException('Ursprüngliche Briefpapierbestätigung auswählen.',422);$read=$papersettingsRequests->receipt('paper',$_GET['papersettings_original']);$bytes=$read['original_json'];$filename='dvm-paper-settings-original.json';}
        else{$read=$papersettingsRequests->revision('paper',$_GET['papersettings_revision_original']);$bytes=$read['payload_json'];$filename='dvm-paper-version-'.$read['version'].'.json';}
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($papersettingsMethod!=='HEAD')echo$bytes;exit;
    }
    if(!$papersettingsPost)return;$papersettingsHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if(isset($_POST['live_uploads'])){if(!is_array($_POST['live_uploads']))throw new DomainException('Uploadformular ungültig.',422);\Kulturhus\Verein\LiveUploads::attach((string)$_SESSION['actor'],(string)$_POST['action'],$_POST['live_uploads']);}
    if($papersettingsLegacyPost){
        $initial=PaperInput::initial($papersettingsRequests->current());$initial['data']=array_replace($initial['data'],is_array($_POST['data']??null)?$_POST['data']:[]);$initial['version']=$_POST['version']??'';
        $papersettingsPostedDraft=GeneralDraft::open($_SESSION,$papersettingsActor,'paper',$initial,true);$papersettingsStore($papersettingsPostedDraft);$papersettingsPostedDraft=$papersettingsUpload($papersettingsPostedDraft);$papersettingsStore($papersettingsPostedDraft);
        throw new DomainException('Dieses frühere Briefpapierformular wurde nicht gespeichert. Ganze Angaben und vorgemerktes Logo zuerst prüfen und danach gesondert bestätigen.',422);
    }
    $papersettingsPostedDraft=GeneralDraft::get($_SESSION,$papersettingsActor,'paper',$_POST['papersettings_draft']??null);
    if($papersettingsPostedDraft['accepted']!==null){$read=$papersettingsRequests->receipt('paper',$papersettingsPostedDraft['key']);redirectTo(['view'=>'settings','tab'=>'paper','papersettings_receipt'=>$read['key']]);}
    $intent=$_POST['papersettings_intent']??null;
    if(!in_array($intent,['preview','pdf','current','accept','discard','copy-club','remove-logo','forget-logo'],true))throw new DomainException('Gültige Briefpapieraktion auswählen.',422);
    if($papersettingsPostedDraft['pending']){
        try{$read=$papersettingsRequests->receipt('paper',$papersettingsPostedDraft['key']);$papersettingsPostedDraft=PaperDraft::accepted($papersettingsPostedDraft,$read['key']);$papersettingsStore($papersettingsPostedDraft);redirectTo(['view'=>'settings','tab'=>'paper','papersettings_receipt'=>$read['key']]);}
        catch(DomainException$failure){if($failure->getCode()!==404)throw$failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen Briefpapierausgang lesen.',409);$papersettingsPostedDraft['pending']=false;
    }
    if($intent==='discard'){GeneralDraft::discard($_SESSION,$papersettingsPostedDraft);redirectTo(['view'=>'settings','tab'=>'paper','papersettings_closed'=>'1']);}
    if($intent==='accept'){
        if(!is_string($_POST['papersettings_key']??null)||!is_string($_POST['papersettings_review']??null)||!hash_equals($papersettingsPostedDraft['key'],$_POST['papersettings_key'])||!is_array($papersettingsPostedDraft['review'])||!hash_equals($papersettingsPostedDraft['review']['hash'],$_POST['papersettings_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['papersettings_approved']??null)!=='1')throw new DomainException('Das vollständig geprüfte Briefpapier ausdrücklich bestätigen.',422);
        $logo=$papersettingsPostedDraft['input']['logo']!==null?$papersettingsPrivate()->logo($papersettingsPostedDraft):null;
        $papersettingsPostedDraft['pending']=true;$papersettingsStore($papersettingsPostedDraft);$papersettingsAcceptStarted=true;$read=$papersettingsRequests->accept('paper',$papersettingsPostedDraft['input'],$papersettingsPostedDraft['key'],$papersettingsPostedDraft['review']['hash'],true,$logo);$papersettingsAcceptStarted=false;
        $papersettingsPostedDraft=PaperDraft::accepted($papersettingsPostedDraft,$read['key']);$papersettingsStore($papersettingsPostedDraft);redirectTo(['view'=>'settings','tab'=>'paper','papersettings_draft'=>$papersettingsPostedDraft['id'],'papersettings_receipt'=>$read['key']]);
    }
    if(!is_array($_POST['data']??null)&&$intent!=='current')throw new DomainException('Vollständiges ursprüngliches Briefpapier prüfen.',422);
    $raw=$papersettingsPostedDraft['input'];if(is_array($_POST['data']??null))$raw['data']=$_POST['data'];$papersettingsPostedDraft=GeneralDraft::replace($papersettingsPostedDraft,$raw,$intent==='current');$papersettingsStore($papersettingsPostedDraft);
    $papersettingsPostedDraft=$papersettingsUpload($papersettingsPostedDraft);$papersettingsStore($papersettingsPostedDraft);
    if(in_array($intent,['remove-logo','forget-logo'],true)){
        $papersettingsPostedDraft=PaperDraft::forget($papersettingsPostedDraft);if($intent==='remove-logo')$papersettingsPostedDraft['input']['data']['logo']='';$papersettingsStore($papersettingsPostedDraft);return;
    }
    if($intent==='copy-club'){
        $club=$settings->section('club')['data'];$papersettingsPostedDraft['input']['data']=array_replace($papersettingsPostedDraft['input']['data'],['sender_source'=>'custom','sender_name'=>$club['name'],'sender_street'=>$club['street'],'sender_address2'=>$club['address2'],'sender_city'=>trim($club['postcode'].' '.$club['city'])]);$papersettingsStore($papersettingsPostedDraft);return;
    }
    if($intent==='current'){$papersettingsPostedDraft['input']=PaperInput::adoptCurrent($papersettingsPostedDraft['input'],$papersettingsRequests->current());$papersettingsPostedDraft['pending']=false;$papersettingsStore($papersettingsPostedDraft);}
    $logo=$papersettingsPostedDraft['input']['logo']!==null?$papersettingsPrivate()->logo($papersettingsPostedDraft):null;
    $preview=$papersettingsRequests->preview($papersettingsPostedDraft['input'],$logo);$papersettingsPostedDraft['review']=$preview['review'];$papersettingsStore($papersettingsPostedDraft);
    if($papersettingsPostedDraft['input']['version']!==(string)$preview['review']['current']['version'])throw new DomainException('Das Briefpapier wurde inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
    if($intent==='pdf'){
        $bytes=\Kulturhus\Verein\DocumentPreview::pdf($preview['document']);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="dvm-briefpapier-entwurf.pdf"');header('Cache-Control: private, no-store');header('Content-Length: '.strlen($bytes));echo$bytes;exit;
    }
}catch(DomainException$failure){if($papersettingsPost)$papersettingsHandled=true;if($papersettingsAcceptStarted&&$papersettingsPostedDraft!==null){$papersettingsPostedDraft['pending']=false;$papersettingsPostedDraft['conflicted']=true;}if($papersettingsPostedDraft!==null)$papersettingsStore($papersettingsPostedDraft);http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();}
catch(Throwable){if($papersettingsPost)$papersettingsHandled=true;http_response_code(503);$error=$papersettingsAcceptStarted?'Briefpapierausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.':'Briefpapier konnte nicht geladen werden.';}
if(isset($_GET['papersettings_original'])||isset($_GET['papersettings_revision_original'])||isset($_GET['papersettings_pdf'])){try{$error=\Kulturhus\Native\Language\PaperSettingsMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
