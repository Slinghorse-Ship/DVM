<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,TextInput,TextRequests};
$textsettingsHandled=false;$textsettingsPostedDraft=null;$textsettingsRequests=null;$textsettingsAcceptStarted=false;$textsettingsMethod=$_SERVER['REQUEST_METHOD']??'GET';
$textsettingsNativeRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&($_GET['tab']??null)==='texts';
$textsettingsGroup=$_GET['group']??'contributions';$textsettingsScope=is_string($textsettingsGroup)?'texts_'.$textsettingsGroup:'';
$textsettingsLegacyPost=($nativeManagerEnabled??false)&&$textsettingsMethod==='POST'&&($_POST['action']??null)==='settings-save'&&is_string($_POST['section']??null)&&TextInput::supports($_POST['section']);
$textsettingsPost=($nativeManagerEnabled??false)&&$textsettingsMethod==='POST'&&($textsettingsLegacyPost||($_POST['action']??null)==='text-settings-native');
if(!$textsettingsNativeRoute&&!$textsettingsPost)return;
$textsettingsStore=static function(array $draft):void{$_SESSION['general_settings_drafts'][$draft['id']]=$draft;};
$textsettingsComplete=static function(array $draft,string $key):array{$draft['pending']=false;$draft['accepted']=$key;$draft['review']=null;$draft['previous']=[];return$draft;};
$textsettingsUrl=static fn(string $scope,array $q=[]):array=>['view'=>'settings','tab'=>'texts','group'=>substr($scope,6)]+$q;
try{
    if(!$textsettingsNativeRoute||!$workspaceAllowed)throw new DomainException('Aktuelle Vereinseinstellungen für Textbausteine erforderlich.',403);
    TextInput::section($textsettingsScope);$textsettingsRequests=new TextRequests($db,(string)$_SESSION['actor']);$textsettingsActor=$textsettingsRequests->assertAccess($textsettingsScope);
    if(isset($_GET['textsettings_pdf'])){
        if(!in_array($textsettingsMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $pdfDraft=GeneralDraft::get($_SESSION,$textsettingsActor,$textsettingsScope,$_GET['textsettings_pdf']);
        if($pdfDraft['pending']||$pdfDraft['accepted']!==null||!is_array($pdfDraft['review'])||!is_string($_GET['textsettings_review']??null)||!hash_equals($pdfDraft['review']['hash'],$_GET['textsettings_review']))throw new DomainException('Den eigenen vollständig geprüften Textbausteinentwurf öffnen.',409);
        $preview=$textsettingsRequests->reviewedPreview($textsettingsScope,$pdfDraft['input'],$pdfDraft['review']);
        if(!hash_equals($pdfDraft['review']['hash'],$preview['review']['hash'])||$pdfDraft['input']['version']!==(string)$preview['review']['current']['version'])throw new DomainException('Die Textbausteine oder ihre Quellen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
        $bytes=\Kulturhus\Verein\DocumentPreview::pdf($preview['document']);header('Content-Type: application/pdf');header('Content-Disposition: '.(($_GET['textsettings_pdf_download']??null)==='1'?'attachment':'inline').'; filename="dvm-textbaustein-entwurf.pdf"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($textsettingsMethod!=='HEAD')echo$bytes;exit;
    }
    if(isset($_GET['textsettings_original'])||isset($_GET['textsettings_revision_original'])){
        if(!in_array($textsettingsMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(isset($_GET['textsettings_original'])){if(!is_string($_GET['textsettings_original']))throw new DomainException('Ursprüngliche Textbausteinbestätigung auswählen.',422);$read=$textsettingsRequests->receipt($textsettingsScope,$_GET['textsettings_original']);$bytes=$read['original_json'];$filename='dvm-text-settings-original.json';}
        else{$read=$textsettingsRequests->revision($textsettingsScope,$_GET['textsettings_revision_original']);$bytes=$read['payload_json'];$filename='dvm-text-settings-version-'.$read['version'].'.json';}
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($textsettingsMethod!=='HEAD')echo$bytes;exit;
    }
    if(!$textsettingsPost)return;$textsettingsHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if($textsettingsLegacyPost){
        if($_POST['section']!==$textsettingsScope)throw new DomainException('Eigene Textbausteingruppe auswählen.',422);
        $input=TextInput::initial($textsettingsScope,$textsettingsRequests->current($textsettingsScope));$input['data']=$_POST['data']??null;$input['version']=$_POST['version']??'';$input['template']=$_POST['preview_template']??$input['template'];
        if(is_array($input['data']))foreach($input['data']as&$row)if(is_array($row)&&!array_key_exists('attach_pdf',$row))$row['attach_pdf']='0';unset($row);
        $textsettingsPostedDraft=GeneralDraft::open($_SESSION,$textsettingsActor,$textsettingsScope,TextInput::capture($textsettingsScope,$input),true);$textsettingsStore($textsettingsPostedDraft);
        throw new DomainException('Dieses frühere Textbausteinformular wurde nicht gespeichert. Ganze Angaben zuerst prüfen und danach gesondert bestätigen.',422);
    }
    if(($_POST['textsettings_scope']??null)!==$textsettingsScope)throw new DomainException('Eigene Textbausteingruppe auswählen.',422);
    $textsettingsPostedDraft=GeneralDraft::get($_SESSION,$textsettingsActor,$textsettingsScope,$_POST['textsettings_draft']??null);
    if($textsettingsPostedDraft['accepted']!==null){$read=$textsettingsRequests->receipt($textsettingsScope,$textsettingsPostedDraft['key']);redirectTo($textsettingsUrl($textsettingsScope,['textsettings_receipt'=>$read['key']]));}
    $intent=$_POST['textsettings_intent']??null;
    if(!is_string($intent)||!in_array($intent,['preview','pdf','current','accept','discard'],true)&&!preg_match('/\A(?:switch:texts_(?:contributions|invoices|donations)|reset:[a-z]+)\z/D',$intent))throw new DomainException('Gültige Textbausteinaktion auswählen.',422);
    if($textsettingsPostedDraft['pending']){
        try{$read=$textsettingsRequests->receipt($textsettingsScope,$textsettingsPostedDraft['key']);$textsettingsPostedDraft=$textsettingsComplete($textsettingsPostedDraft,$read['key']);$textsettingsStore($textsettingsPostedDraft);redirectTo($textsettingsUrl($textsettingsScope,['textsettings_receipt'=>$read['key']]));}
        catch(DomainException$failure){if($failure->getCode()!==404)throw$failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen Textbausteinausgang lesen.',409);$textsettingsPostedDraft['pending']=false;
    }
    if($intent==='discard'){GeneralDraft::discard($_SESSION,$textsettingsPostedDraft);redirectTo($textsettingsUrl($textsettingsScope,['textsettings_closed'=>'1']));}
    if($intent==='accept'){
        if(!is_string($_POST['textsettings_key']??null)||!is_string($_POST['textsettings_review']??null)||!hash_equals($textsettingsPostedDraft['key'],$_POST['textsettings_key'])||!is_array($textsettingsPostedDraft['review'])||!hash_equals($textsettingsPostedDraft['review']['hash'],$_POST['textsettings_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['textsettings_approved']??null)!=='1')throw new DomainException('Die vollständig geprüfte Textbausteingruppe ausdrücklich bestätigen.',422);
        $textsettingsPostedDraft['pending']=true;$textsettingsStore($textsettingsPostedDraft);$textsettingsAcceptStarted=true;$read=$textsettingsRequests->accept($textsettingsScope,$textsettingsPostedDraft['input'],$textsettingsPostedDraft['key'],$textsettingsPostedDraft['review']['hash'],true);$textsettingsAcceptStarted=false;
        $textsettingsPostedDraft=$textsettingsComplete($textsettingsPostedDraft,$read['key']);$textsettingsStore($textsettingsPostedDraft);redirectTo($textsettingsUrl($textsettingsScope,['textsettings_draft'=>$textsettingsPostedDraft['id'],'textsettings_receipt'=>$read['key']]));
    }
    $raw=$textsettingsPostedDraft['input'];if(is_array($_POST['data']??null)){$raw['data']=$_POST['data'];$raw['template']=$_POST['textsettings_template']??null;}elseif($intent!=='current')throw new DomainException('Vollständige ursprüngliche Textbausteine prüfen.',422);$raw=TextInput::capture($textsettingsScope,$raw);
    $textsettingsPostedDraft=GeneralDraft::replace($textsettingsPostedDraft,$raw,$intent==='current');$textsettingsStore($textsettingsPostedDraft);
    if(str_starts_with($intent,'switch:')){redirectTo($textsettingsUrl(substr($intent,7)));}
    if(str_starts_with($intent,'reset:')){$textsettingsPostedDraft['input']=TextInput::reset($textsettingsScope,$raw,substr($intent,6));$textsettingsStore($textsettingsPostedDraft);return;}
    if($intent==='current'){$textsettingsPostedDraft['input']['version']=(string)$textsettingsRequests->current($textsettingsScope)['version'];$textsettingsPostedDraft['pending']=false;$textsettingsStore($textsettingsPostedDraft);}
    $preview=$textsettingsRequests->preview($textsettingsScope,$textsettingsPostedDraft['input']);$textsettingsPostedDraft['review']=$preview['review'];$textsettingsStore($textsettingsPostedDraft);
    if($textsettingsPostedDraft['input']['version']!==(string)$preview['review']['current']['version'])throw new DomainException('Die Textbausteine oder ihre Quellen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
    if($intent==='pdf'){$bytes=\Kulturhus\Verein\DocumentPreview::pdf($preview['document']);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="dvm-textbaustein-entwurf.pdf"');header('Cache-Control: private, no-store');header('Content-Length: '.strlen($bytes));echo$bytes;exit;}
}catch(DomainException$failure){if($textsettingsPost)$textsettingsHandled=true;if($textsettingsAcceptStarted&&$textsettingsPostedDraft!==null){$textsettingsPostedDraft['pending']=false;$textsettingsPostedDraft['conflicted']=true;}if($textsettingsPostedDraft!==null)$textsettingsStore($textsettingsPostedDraft);http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();}
catch(Throwable){if($textsettingsPost)$textsettingsHandled=true;http_response_code(503);$error=$textsettingsAcceptStarted?'Textbausteinausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.':'Textbausteine konnten nicht geladen werden.';}
if(isset($_GET['textsettings_original'])||isset($_GET['textsettings_revision_original'])||isset($_GET['textsettings_pdf'])){try{$error=\Kulturhus\Native\Language\TextSettingsMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
