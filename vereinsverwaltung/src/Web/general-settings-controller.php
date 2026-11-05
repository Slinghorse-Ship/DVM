<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,GeneralInput,GeneralRequests,GeneralValues};

$generalHandled=false;$generalPostedDraft=null;$generalRequests=null;$generalAcceptStarted=false;
$generalSection=$_GET['section']??'club';
$generalNativeRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&input($_GET,'tab')===''&&is_string($generalSection)&&GeneralValues::supports($generalSection);
$generalLegacyPost=($nativeManagerEnabled??false)&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)==='settings-save'&&is_string($_POST['section']??null)&&GeneralValues::supports($_POST['section']);
$generalNativePost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&(($_POST['action']??null)==='general-settings-native'||$generalLegacyPost);
if(!$generalNativeRoute&&!$generalNativePost)return;
try{
    if(!$generalNativeRoute||!$workspaceAllowed)throw new DomainException('Vereinseinstellungen im vorhandenen Bereich öffnen.',403);
    $generalRequests=new GeneralRequests($db,(string)$_SESSION['actor']);$generalActor=$generalRequests->assertAccess($generalSection);
    if(isset($_GET['settings_revision_original'])){
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        $generalVersionOriginal=$generalRequests->revision($generalSection,$_GET['settings_revision_original']);
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-settings-version-'.$generalVersionOriginal['version'].'.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox; default-src \'none\'');header('Content-Length: '.strlen($generalVersionOriginal['payload_json']));if($_SERVER['REQUEST_METHOD']!=='HEAD')echo$generalVersionOriginal['payload_json'];exit;
    }
    if(isset($_GET['settings_original'])){
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!is_string($_GET['settings_original']))throw new DomainException('Ursprüngliche Einstellungsbestätigung auswählen.',422);
        $generalOriginal=$generalRequests->receipt($generalSection,$_GET['settings_original']);
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-settings-original.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox; default-src \'none\'');header('Content-Length: '.strlen($generalOriginal['original_json']));if($_SERVER['REQUEST_METHOD']!=='HEAD')echo$generalOriginal['original_json'];exit;
    }
    if(!$generalNativePost)return;$generalHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if($generalLegacyPost){
        if($_POST['section']!==$generalSection)throw new DomainException('Vereinseinstellungen im vorhandenen Bereich öffnen.',403);
        $raw=is_array($_POST['data']??null)?$_POST['data']:[];
        if($generalSection==='fields'&&is_array($raw['rows']??null))foreach($raw['rows']as&$row)if(is_array($row)&&($row['id']??'')==='')$row['id']=bin2hex(random_bytes(16));unset($row);
        $generalPostedDraft=GeneralDraft::open($_SESSION,$generalActor,$generalSection,['version'=>$_POST['version']??'','data'=>$raw],true);
        throw new DomainException('Dieses frühere Einstellungsformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst prüfen und danach gesondert bestätigen.',422);
    }
    $generalPostedDraft=GeneralDraft::get($_SESSION,$generalActor,$generalSection,$_POST['settings_draft']??null);$generalBeforeDraft=$generalPostedDraft;
    if($generalPostedDraft['accepted']!==null){$receipt=$generalRequests->receipt($generalSection,$generalPostedDraft['key']);redirectTo(['view'=>'settings','section'=>$generalSection,'settings_receipt'=>$receipt['key']]);}
    $generalIntent=$_POST['settings_intent']??'';
    if(!is_string($generalIntent)||(!in_array($generalIntent,['preview','current','accept','discard','add'],true)&&!preg_match('/\A(?:remove|up|down):[0-9]{1,2}\z/D',$generalIntent)))throw new DomainException('Gültige Einstellungsaktion auswählen.',422);
    if($generalPostedDraft['pending']){
        try{$receipt=$generalRequests->receipt($generalSection,$generalPostedDraft['key']);$generalPostedDraft['pending']=false;$generalPostedDraft['accepted']=$receipt['key'];$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;redirectTo(['view'=>'settings','section'=>$generalSection,'settings_receipt'=>$receipt['key'],'settings_draft'=>$generalPostedDraft['id']]);}
        catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;}
        if($generalIntent!=='current')throw new DomainException('Zuerst den ursprünglichen Einstellungsausgang prüfen.',409);
    }
    if($generalIntent==='discard'){GeneralDraft::discard($_SESSION,$generalPostedDraft);redirectTo(['view'=>'settings','section'=>$generalSection]);}
    if($generalIntent==='accept'){
        if(!is_string($_POST['settings_key']??null)||!is_string($_POST['settings_review']??null)||!hash_equals($generalPostedDraft['key'],$_POST['settings_key'])||!is_array($generalPostedDraft['review'])||!hash_equals($generalPostedDraft['review']['hash'],$_POST['settings_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['settings_approved']??null)!=='1')throw new DomainException('Die vollständig geprüften Einstellungen ausdrücklich bestätigen.',422);
        $generalPostedDraft['pending']=true;$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;$generalAcceptStarted=true;
        $receipt=$generalRequests->accept($generalSection,$generalPostedDraft['input'],$generalPostedDraft['key'],$generalPostedDraft['review']['hash'],true);$generalAcceptStarted=false;
        $generalPostedDraft['pending']=false;$generalPostedDraft['accepted']=$receipt['key'];$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;
        redirectTo(['view'=>'settings','section'=>$generalSection,'settings_receipt'=>$receipt['key'],'settings_draft'=>$generalPostedDraft['id']]);
    }
    if($generalSection==='fields'&&($_POST['settings_rows_present']??null)==='1'&&!array_key_exists('data',$_POST))$_POST['data']=['rows'=>[]];
    if(!is_array($_POST['data']??null))throw new DomainException('Vollständige ursprüngliche Einstellungsfelder prüfen.',422);
    if(in_array($generalSection,['salutations','fields'],true)&&($_POST['settings_rows_present']??null)==='1'&&!array_key_exists('rows',$_POST['data']))$_POST['data']['rows']=[];
    $raw=['version'=>$generalPostedDraft['input']['version'],'data'=>$_POST['data']];
    $generalPostedDraft=GeneralDraft::replace($generalPostedDraft,$raw,$generalIntent==='current'||$generalIntent==='add'||str_contains($generalIntent,':'));$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;
    if($generalIntent==='add'||str_contains($generalIntent,':')){$generalPostedDraft['input']=GeneralDraft::row($generalSection,$raw,$generalIntent);$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;return;}
    if($generalIntent==='current'){$current=$settings->section($generalSection);$generalPostedDraft['input']['version']=(string)$current['version'];$generalPostedDraft['pending']=false;$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;}
    $generalPostedDraft['review']=$generalRequests->prepare($generalSection,$generalPostedDraft['input']);$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;
    if($generalPostedDraft['input']['version']!==(string)$generalPostedDraft['review']['current']['version'])throw new DomainException('Diese Einstellungen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);
}catch(DomainException $failure){
    if($generalNativePost)$generalHandled=true;
    if($generalAcceptStarted&&$generalPostedDraft!==null){$generalPostedDraft['pending']=false;$generalPostedDraft['conflicted']=true;}
    if($generalPostedDraft!==null)$_SESSION['general_settings_drafts'][$generalPostedDraft['id']]=$generalPostedDraft;
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
}catch(Throwable){if($generalNativePost)$generalHandled=true;http_response_code(503);$error=$generalAcceptStarted?'Einstellungsausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung prüfen.':'Einstellungsbereich konnte nicht geladen werden.';}
if(isset($_GET['settings_original'])||isset($_GET['settings_revision_original'])){try{$error=\Kulturhus\Native\Language\GeneralSettingsMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
