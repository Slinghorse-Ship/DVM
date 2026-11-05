<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{GeneralDraft,PersonalPriceDraft,PersonalPriceRepository,PersonalPriceRequests,CatalogInput};
$personalPriceHandled=false;$personalPricePostedDraft=null;$personalPriceRequests=null;$personalPriceAcceptStarted=false;$personalPriceTab=input($_GET,'tab');
$personalPriceBase=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&in_array($personalPriceTab,['rates','services'],true);$personalPriceMethod=$_SERVER['REQUEST_METHOD']??'GET';$personalPriceAction=$_POST['action']??null;
$personalPriceLegacy=($nativeManagerEnabled??false)&&$personalPriceMethod==='POST'&&$personalPriceAction==='assignment-price-save';$personalPricePost=$personalPriceMethod==='POST'&&($personalPriceAction==='personal-price-native'||$personalPriceLegacy);
$personalPriceDownload=isset($_GET['personal_price_original'])||isset($_GET['personal_price_revision_original']);
$personalPriceGet=isset($_GET['assignment_prices'])||isset($_GET['price_assignment'])||isset($_GET['personal_price_draft'])||isset($_GET['personal_price_receipt'])||isset($_GET['personal_price_history_q'])||isset($_GET['personal_price_history_page'])||isset($_GET['personal_price_revisions_page'])||isset($_GET['personal_price_revision']);
$personalPriceOther=isset($_GET['billing'])||isset($_GET['automation']);
$personalPriceNativeRoute=$personalPriceBase&&($personalPricePost||$personalPriceDownload||(in_array($personalPriceMethod,['GET','HEAD'],true)&&$personalPriceGet&&!$personalPriceOther));
if(!$personalPriceNativeRoute&&!$personalPricePost)return;
$personalPriceStore=static function(array $draft):array{$_SESSION['general_settings_drafts'][$draft['id']]=$draft;return$draft;};
try{
    if(!$personalPriceNativeRoute||!$workspaceAllowed)throw new DomainException('Persönliche Preise im vorhandenen Einstellungsbereich öffnen.',403);
    $personalPriceRepository=new PersonalPriceRepository($db,(string)$_SESSION['actor']);$personalPriceActor=$personalPriceRepository->actor();$personalPriceRequests=new PersonalPriceRequests($db,(string)$_SESSION['actor']);
    if($personalPriceDownload){
        if(!in_array($personalPriceMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(isset($_GET['personal_price_original'])){
            if(!is_string($_GET['personal_price_original']))throw new DomainException('Ursprüngliche persönliche Preisbestätigung auswählen.',422);
            $read=$personalPriceRequests->receipt($_GET['personal_price_original']);if($read['input']['tab']!==$personalPriceTab)throw new DomainException('Ursprüngliche persönliche Preisbestätigung nicht verfügbar.',404);$bytes=$read['original_json'];$filename='dvm-personal-price-original.json';
        }else{$read=$personalPriceRepository->revision(CatalogInput::id($_GET['price_assignment']??null),$personalPriceTab,$_GET['personal_price_revision_original']);$bytes=$read['payload_json'];$filename='dvm-personal-price-version-'.$read['version'].'.json';}
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($personalPriceMethod!=='HEAD')echo$bytes;exit;
    }
    if(!$personalPricePost)return;$personalPriceHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $personalPriceActor=$personalPriceRepository->actor(true);
    if($personalPriceLegacy){
        $raw=['tab'=>$personalPriceTab,'id'=>$_POST['price_assignment']??'','version'=>$_POST['assignment_price_version']??'','data'=>['mode'=>$_POST['assignment_price_mode']??'','value'=>$_POST['assignment_price_value']??'']];
        $personalPricePostedDraft=PersonalPriceDraft::open($_SESSION,$personalPriceActor,$personalPriceTab,$raw,true);$personalPricePostedDraft['earlier_form']=['key'=>$_POST['assignment_price_key']??''];$personalPriceStore($personalPricePostedDraft);
        throw new DomainException('Dieses frühere persönliche Preisformular wurde nicht gespeichert. Erhaltene Angaben zuerst prüfen und danach gesondert bestätigen.',422);
    }
    $personalPricePostedDraft=PersonalPriceDraft::get($_SESSION,$personalPriceActor,$personalPriceTab,$_POST['personal_price_draft']??null);
    if($personalPricePostedDraft['accepted']!==null){$read=$personalPriceRequests->receipt($personalPricePostedDraft['key']);redirectTo(['view'=>'settings','tab'=>$personalPriceTab,'assignment_prices'=>'1','personal_price_receipt'=>$read['key']]);}
    $intent=$_POST['personal_price_intent']??null;if(!in_array($intent,['preview','current','accept','discard'],true))throw new DomainException('Gültige ungespeicherte persönliche Preisaktion auswählen.',422);
    if($personalPricePostedDraft['pending']){
        try{$read=$personalPriceRequests->receipt($personalPricePostedDraft['key']);$personalPricePostedDraft['pending']=false;$personalPricePostedDraft['accepted']=$read['key'];$personalPriceStore($personalPricePostedDraft);redirectTo(['view'=>'settings','tab'=>$personalPriceTab,'assignment_prices'=>'1','personal_price_draft'=>$personalPricePostedDraft['id'],'personal_price_receipt'=>$read['key']]);}catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen persönlichen Preisausgang lesen.',409);
    }
    if($intent==='discard'){GeneralDraft::discard($_SESSION,$personalPricePostedDraft);redirectTo(['view'=>'settings','tab'=>$personalPriceTab,'assignment_prices'=>'1']);}
    if($intent==='accept'){
        if(!is_string($_POST['personal_price_key']??null)||!is_string($_POST['personal_price_review']??null)||!hash_equals($personalPricePostedDraft['key'],$_POST['personal_price_key'])||!is_array($personalPricePostedDraft['review'])||!hash_equals($personalPricePostedDraft['review']['hash'],$_POST['personal_price_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['personal_price_approved']??null)!=='1')throw new DomainException('Die vollständig geprüfte persönliche Preisänderung ausdrücklich bestätigen.',422);
        $personalPricePostedDraft['pending']=true;$personalPriceStore($personalPricePostedDraft);$personalPriceAcceptStarted=true;
        $read=$personalPriceRequests->accept($personalPricePostedDraft['input'],$personalPricePostedDraft['key'],$personalPricePostedDraft['review']['hash'],true);$personalPriceAcceptStarted=false;$personalPricePostedDraft['pending']=false;$personalPricePostedDraft['accepted']=$read['key'];$personalPriceStore($personalPricePostedDraft);
        redirectTo(['view'=>'settings','tab'=>$personalPriceTab,'assignment_prices'=>'1','personal_price_draft'=>$personalPricePostedDraft['id'],'personal_price_receipt'=>$read['key']]);
    }
    $data=$_POST['data']??null;$keys=is_array($data)?array_keys($data):[];sort($keys);if(!is_array($data)||$keys!==['mode','value'])throw new DomainException('Vollständige persönliche Preisfelder prüfen.',422);
    $raw=$personalPricePostedDraft['input'];$raw['data']=$data;$personalPricePostedDraft=GeneralDraft::replace($personalPricePostedDraft,$raw,$intent==='current'||$personalPricePostedDraft['review']!==null);$personalPriceStore($personalPricePostedDraft);
    if($intent==='current'){$personalPricePostedDraft['input']['version']=(string)$personalPriceRepository->state($raw['id'],$personalPriceTab)['version'];$personalPricePostedDraft['pending']=false;$personalPriceStore($personalPricePostedDraft);}
    $personalPricePostedDraft['review']=$personalPriceRequests->prepare($personalPricePostedDraft['input']);$personalPriceStore($personalPricePostedDraft);if($issues=PersonalPriceRequests::issues($personalPricePostedDraft['review']))throw new DomainException($issues[0]['message'],$issues[0]['code']);
}catch(DomainException $failure){
    if($personalPricePost)$personalPriceHandled=true;if($personalPriceAcceptStarted&&$personalPricePostedDraft!==null){$personalPricePostedDraft['pending']=false;$personalPricePostedDraft['conflicted']=true;}if($personalPricePostedDraft!==null)$personalPriceStore($personalPricePostedDraft);
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
}catch(Throwable){if($personalPricePost)$personalPriceHandled=true;http_response_code(503);$error=$personalPriceAcceptStarted?'Persönlicher Preisausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.':'Persönliche Preise konnten nicht geladen werden.';}
if($personalPriceDownload){try{$error=\Kulturhus\Native\Language\PersonalPriceMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;}
