<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,GeneralRequests,NumberingContext};
$numberingHandled=false;$numberingPostedDraft=null;$numberingRequests=null;$numberingAcceptStarted=false;
$numberingNativeRoute=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&input($_GET,'tab')==='numbers';
$numberingLegacyPost=($nativeManagerEnabled??false)&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)==='settings-save'&&($_POST['section']??null)==='numbers';
$numberingNativePost=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&(($_POST['action']??null)==='numbering-native'||$numberingLegacyPost);
if(!$numberingNativeRoute&&!$numberingNativePost)return;
try{
    if(!$numberingNativeRoute||!$workspaceAllowed)throw new DomainException('Nummernkreise im vorhandenen Einstellungsbereich öffnen.',403);
    $numberingRequests=new GeneralRequests($db,(string)$_SESSION['actor']);$numberingActor=$numberingRequests->assertAccess('numbers');
    if(isset($_GET['number_original'])||isset($_GET['number_revision_original'])){
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(isset($_GET['number_original'])){if(!is_string($_GET['number_original']))throw new DomainException('Ursprüngliche Nummernbestätigung auswählen.',422);$read=$numberingRequests->receipt('numbers',$_GET['number_original']);$bytes=$read['original_json'];$filename='dvm-numbering-original.json';}
        else{$read=$numberingRequests->revision('numbers',$_GET['number_revision_original']);$bytes=$read['payload_json'];$filename='dvm-numbering-version-'.$read['version'].'.json';}
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox; default-src \'none\'');header('Content-Length: '.strlen($bytes));if($_SERVER['REQUEST_METHOD']!=='HEAD')echo$bytes;exit;
    }
    if(!$numberingNativePost)return;$numberingHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $numberingActor=$numberingRequests->assertAccess('numbers',true);
    if($numberingLegacyPost){$numberingPostedDraft=GeneralDraft::open($_SESSION,$numberingActor,'numbers',['version'=>$_POST['version']??'','data'=>is_array($_POST['data']??null)?$_POST['data']:[],'date'=>$_POST['number_date']??gmdate('Y-m-d')],true);throw new DomainException('Dieses frühere Nummernformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst prüfen und danach gesondert bestätigen.',422);}
    $numberingPostedDraft=GeneralDraft::get($_SESSION,$numberingActor,'numbers',$_POST['number_draft']??null);
    if($numberingPostedDraft['accepted']!==null){$read=$numberingRequests->receipt('numbers',$numberingPostedDraft['key']);redirectTo(['view'=>'settings','tab'=>'numbers','number_receipt'=>$read['key']]);}
    $intent=$_POST['number_intent']??'';if(!is_string($intent)||!in_array($intent,['preview','current','accept','discard'],true))throw new DomainException('Gültige Nummernaktion auswählen.',422);
    if($numberingPostedDraft['pending']){
        try{$read=$numberingRequests->receipt('numbers',$numberingPostedDraft['key']);$numberingPostedDraft['pending']=false;$numberingPostedDraft['accepted']=$read['key'];$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;redirectTo(['view'=>'settings','tab'=>'numbers','number_draft'=>$numberingPostedDraft['id'],'number_receipt'=>$read['key']]);}
        catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen Nummernausgang prüfen.',409);
    }
    if($intent==='discard'){GeneralDraft::discard($_SESSION,$numberingPostedDraft);redirectTo(['view'=>'settings','tab'=>'numbers']);}
    if($intent==='accept'){
        if(!is_string($_POST['number_key']??null)||!is_string($_POST['number_review']??null)||!hash_equals($numberingPostedDraft['key'],$_POST['number_key'])||!is_array($numberingPostedDraft['review'])||!hash_equals($numberingPostedDraft['review']['hash'],$_POST['number_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['number_approved']??null)!=='1')throw new DomainException('Die vollständig geprüften Nummernregeln ausdrücklich bestätigen.',422);
        $numberingPostedDraft['pending']=true;$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;$numberingAcceptStarted=true;
        $read=$numberingRequests->accept('numbers',$numberingPostedDraft['input'],$numberingPostedDraft['key'],$numberingPostedDraft['review']['hash'],true);$numberingAcceptStarted=false;
        $numberingPostedDraft['pending']=false;$numberingPostedDraft['accepted']=$read['key'];$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;redirectTo(['view'=>'settings','tab'=>'numbers','number_draft'=>$numberingPostedDraft['id'],'number_receipt'=>$read['key']]);
    }
    if(!is_array($_POST['data']??null))throw new DomainException('Vollständige ursprüngliche Nummernfelder und Beispieldatum prüfen.',422);
    $raw=['version'=>$numberingPostedDraft['input']['version'],'data'=>$_POST['data'],'date'=>$_POST['number_date']??''];$numberingPostedDraft=GeneralDraft::replace($numberingPostedDraft,$raw,$intent==='current');$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;
    if($intent==='current'){$state=$settings->section('numbers');$numberingPostedDraft['input']['version']=(string)$state['version'];$numberingPostedDraft['pending']=false;$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;}
    $numberingPostedDraft['review']=$numberingRequests->prepare('numbers',$numberingPostedDraft['input']);$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;
    $issues=NumberingContext::issues($numberingPostedDraft['review']);if($issues)throw new DomainException($issues[0]['message'],$issues[0]['code']);
}catch(DomainException $failure){if($numberingNativePost)$numberingHandled=true;if($numberingAcceptStarted&&$numberingPostedDraft!==null){$numberingPostedDraft['pending']=false;$numberingPostedDraft['conflicted']=true;}if($numberingPostedDraft!==null)$_SESSION['general_settings_drafts'][$numberingPostedDraft['id']]=$numberingPostedDraft;http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();}
catch(Throwable){if($numberingNativePost)$numberingHandled=true;http_response_code(503);$error=$numberingAcceptStarted?'Nummernausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung prüfen.':'Nummernkreise konnten nicht geladen werden.';}
if(isset($_GET['number_original'])||isset($_GET['number_revision_original'])){try{$error=\Kulturhus\Native\Language\NumberingMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Cache-Control: private, no-store');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
