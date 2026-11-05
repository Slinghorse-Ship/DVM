<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{MailInput,MailDraft,MailSettingsRequests};
$mailsettingsHandled=false;$mailsettingsPostedDraft=null;$mailsettingsRequests=null;$mailsettingsAcceptStarted=false;$mailsettingsMethod=$_SERVER['REQUEST_METHOD']??'GET';
$mailsettingsNativeRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&input($_GET,'tab')===''&&($_GET['section']??null)==='mail';
$mailsettingsLegacyAction=$_POST['action']??null;$mailsettingsLegacyPost=$mailsettingsMethod==='POST'&&in_array($mailsettingsLegacyAction,['settings-mail-channels','settings-invoice-mailbox'],true);
$mailsettingsOldBasis=$mailsettingsMethod==='POST'&&$mailsettingsLegacyAction==='settings-save'&&($_POST['section']??null)==='mail';
$mailsettingsPost=($nativeManagerEnabled??false)&&$mailsettingsMethod==='POST'&&($mailsettingsLegacyPost||$mailsettingsOldBasis||$mailsettingsLegacyAction==='mail-settings-native');
if(!$mailsettingsNativeRoute&&!$mailsettingsPost)return;
$mailsettingsScope=$_GET['mailsettings_scope']??'mail_channels';$mailsettingsReadKey=static function():string{try{return \Kulturhus\Verein\ContactArchive::serverKey();}catch(DomainException $failure){throw new DomainException($failure->getMessage(),503,$failure);}};$mailsettingsStore=static function(array $draft):void{$_SESSION['mail_settings_drafts'][$draft['id']]=$draft;};
try{
    if(!$mailsettingsNativeRoute)throw new DomainException('Postfächer im vorhandenen Einstellungsbereich öffnen.',403);if(!$workspaceAllowed)throw new DomainException('Nur der Superuser darf Postfächer und Versandzuordnungen ändern.',403);
    if(!is_string($mailsettingsScope))throw new DomainException('Eigenen Postfachbereich auswählen.',422);
    $mailsettingsRequests=new MailSettingsRequests($db,(string)$_SESSION['actor']);$mailsettingsActor=$mailsettingsRequests->assertAccess($mailsettingsScope);
    if(isset($_GET['mailsettings_original'])){
        if(!in_array($mailsettingsMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(!is_string($_GET['mailsettings_original']))throw new DomainException('Ursprüngliche Postfachbestätigung auswählen.',422);$read=$mailsettingsRequests->receipt($mailsettingsScope,$_GET['mailsettings_original']);$bytes=$read['original_json'];
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dvm-mail-settings-original.json"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($mailsettingsMethod!=='HEAD')echo $bytes;exit;
    }
    if(!$mailsettingsPost)return;$mailsettingsHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if($mailsettingsOldBasis){
        $old=[];foreach(['sender_name','sender_email','reply_to']as $field){$value=$_POST['data'][$field]??'';if(!is_string($value)||strlen($value)>40000||preg_match('//u',$value)!==1)throw new DomainException('Alle früheren Absenderangaben vollständig übermitteln.',422);$old[$field]=$value;}
        $_SESSION['mail_settings_inactive_basis'][$mailsettingsActor['club_id'].':'.$mailsettingsActor['id']]=['data'=>$old,'version'=>is_string($_POST['version']??null)?$_POST['version']:''];
        throw new DomainException('Die früheren Newsletter-Basisangaben sind ungespeichert erhalten. Den wirksamen Absender im zugeordneten Postfach prüfen.',422);
    }
    if($mailsettingsLegacyPost){
        $mailsettingsScope=$mailsettingsLegacyAction==='settings-mail-channels'?'mail_channels':'invoice_mailbox';$mailsettingsActor=$mailsettingsRequests->assertAccess($mailsettingsScope);
        $mailsettingsKey=$mailsettingsReadKey();$mailsettingsRequests=new MailSettingsRequests($db,(string)$_SESSION['actor'],$mailsettingsKey);$mailsettingsDraftService=new MailDraft($mailsettingsKey);$initial=MailInput::initial($mailsettingsScope,$mailsettingsRequests->current($mailsettingsScope));
        $legacy=is_array($_POST['data']??null)?$_POST['data']:[];$initial['data']=array_replace_recursive($initial['data'],$legacy);$initial['version']=$_POST['version']??'';
        $mailsettingsPostedDraft=$mailsettingsDraftService->open($_SESSION,$mailsettingsActor,$mailsettingsScope,$initial,true);$mailsettingsStore($mailsettingsPostedDraft);throw new DomainException('Dieses frühere Postfachformular wurde nicht gespeichert. Erhaltene Angaben zuerst prüfen und danach gesondert bestätigen.',422);
    }
    $mailsettingsPostedDraft=MailDraft::get($_SESSION,$mailsettingsActor,$mailsettingsScope,$_POST['mailsettings_draft']??null);
    if($mailsettingsPostedDraft['accepted']!==null){$read=$mailsettingsRequests->receipt($mailsettingsScope,$mailsettingsPostedDraft['key']);redirectTo(['view'=>'settings','section'=>'mail','mailsettings_scope'=>$mailsettingsScope,'mailsettings_receipt'=>$read['key']]);}
    $intent=$_POST['mailsettings_intent']??null;$forget=is_string($intent)&&str_starts_with($intent,'forget:')?substr($intent,7):null;$switch=is_string($intent)&&str_starts_with($intent,'switch:')?substr($intent,7):null;
    if($forget===null&&$switch===null&&!in_array($intent,['preview','current','accept','discard'],true))throw new DomainException('Gültige Postfachaktion auswählen.',422);
    if($switch!==null)MailInput::scope($switch);
    if($mailsettingsPostedDraft['pending']){
        try{$read=$mailsettingsRequests->receipt($mailsettingsScope,$mailsettingsPostedDraft['key']);$mailsettingsPostedDraft=MailDraft::accepted($mailsettingsPostedDraft,$read['key']);$mailsettingsStore($mailsettingsPostedDraft);redirectTo(['view'=>'settings','section'=>'mail','mailsettings_scope'=>$mailsettingsScope,'mailsettings_draft'=>$mailsettingsPostedDraft['id'],'mailsettings_receipt'=>$read['key']]);}
        catch(DomainException $failure){if($failure->getCode()!==404)throw $failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen Postfachausgang lesen.',409);
        $mailsettingsPostedDraft['pending']=false;
    }
    if($intent==='discard'){MailDraft::discard($_SESSION,$mailsettingsPostedDraft);redirectTo(['view'=>'settings','section'=>'mail','mailsettings_scope'=>$mailsettingsScope,'mailsettings_closed'=>'1']);}
    $mailsettingsKey=$mailsettingsReadKey();$mailsettingsDraftService=new MailDraft($mailsettingsKey);$mailsettingsRequests=new MailSettingsRequests($db,(string)$_SESSION['actor'],$mailsettingsKey);
    if($intent==='accept'){
        if(!is_string($_POST['mailsettings_key']??null)||!is_string($_POST['mailsettings_review']??null)||!hash_equals($mailsettingsPostedDraft['key'],$_POST['mailsettings_key'])||!is_array($mailsettingsPostedDraft['review'])||!hash_equals($mailsettingsPostedDraft['review']['hash'],$_POST['mailsettings_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['mailsettings_approved']??null)!=='1')throw new DomainException('Die vollständig geprüften Postfacheinstellungen ausdrücklich bestätigen.',422);
        $mailsettingsPostedDraft['pending']=true;$mailsettingsStore($mailsettingsPostedDraft);$mailsettingsAcceptStarted=true;$read=$mailsettingsRequests->accept($mailsettingsScope,$mailsettingsDraftService->input($mailsettingsPostedDraft),$mailsettingsPostedDraft['key'],$mailsettingsPostedDraft['review']['hash'],true);$mailsettingsAcceptStarted=false;$mailsettingsPostedDraft=MailDraft::accepted($mailsettingsPostedDraft,$read['key']);$mailsettingsStore($mailsettingsPostedDraft);
        redirectTo(['view'=>'settings','section'=>'mail','mailsettings_scope'=>$mailsettingsScope,'mailsettings_draft'=>$mailsettingsPostedDraft['id'],'mailsettings_receipt'=>$read['key']]);
    }
    if(!is_array($_POST['data']??null)&&$intent!=='current')throw new DomainException('Vollständige ursprüngliche Postfacheinstellungen prüfen.',422);
    $mailsettingsPostedDraft=$mailsettingsDraftService->posted($mailsettingsPostedDraft,is_array($_POST['data']??null)?$_POST['data']:$mailsettingsDraftService->input($mailsettingsPostedDraft)['data'],$forget);$mailsettingsStore($mailsettingsPostedDraft);
    if($switch!==null)redirectTo(['view'=>'settings','section'=>'mail','mailsettings_scope'=>$switch]);
    if($forget!==null)return;
    if($intent==='current'){$raw=$mailsettingsDraftService->input($mailsettingsPostedDraft);$raw['version']=(string)$mailsettingsRequests->current($mailsettingsScope)['version'];$mailsettingsPostedDraft=$mailsettingsDraftService->replace($mailsettingsPostedDraft,$raw,true);$mailsettingsStore($mailsettingsPostedDraft);}
    $mailsettingsPostedDraft['review']=$mailsettingsRequests->prepare($mailsettingsScope,$mailsettingsDraftService->input($mailsettingsPostedDraft));$mailsettingsStore($mailsettingsPostedDraft);
}catch(DomainException $failure){if($mailsettingsPost)$mailsettingsHandled=true;if($mailsettingsAcceptStarted&&$mailsettingsPostedDraft!==null){$mailsettingsPostedDraft['pending']=false;$mailsettingsPostedDraft['conflicted']=true;}if($mailsettingsPostedDraft!==null)$mailsettingsStore($mailsettingsPostedDraft);http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();}
catch(Throwable){if($mailsettingsPost)$mailsettingsHandled=true;http_response_code(503);$error=$mailsettingsAcceptStarted?'Postfachausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.':'Postfacheinstellungen konnten nicht geladen werden.';}
if(isset($_GET['mailsettings_original'])){try{$error=\Kulturhus\Native\Language\MailSettingsMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
