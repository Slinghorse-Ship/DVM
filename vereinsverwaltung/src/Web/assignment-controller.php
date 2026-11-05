<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{AssignmentDraft,AssignmentInput,AssignmentRepository,AssignmentRequests,CatalogInput,CatalogRepository,GeneralDraft};

$assignmentHandled=false;$assignmentPostedDraft=null;$assignmentRequests=null;$assignmentAcceptStarted=false;
$assignmentTab=input($_GET,'tab');
$assignmentBase=($nativeManagerEnabled??false)&&isset($nativeAccess)&&$view==='settings'&&in_array($assignmentTab,['rates','services'],true);
$assignmentAction=$_POST['action']??null;$assignmentMethod=$_SERVER['REQUEST_METHOD']??'GET';
$assignmentLegacyPost=($nativeManagerEnabled??false)&&$assignmentMethod==='POST'&&in_array($assignmentAction,['settings-assign','settings-end-assignment'],true);
$assignmentNativePost=$assignmentMethod==='POST'&&($assignmentAction==='assignment-native'||$assignmentLegacyPost);
$assignmentDownload=isset($_GET['assignment_original'])||isset($_GET['assignment_revision_original']);
$assignmentGet=isset($_GET['catalog_assignments'])||isset($_GET['new_assignment'])||isset($_GET['assignment_id'])||isset($_GET['assignment_end'])||isset($_GET['assignment_draft'])||isset($_GET['assignment_receipt'])||isset($_GET['assignment_revision'])||isset($_GET['assignment_history_q'])||isset($_GET['assignment_history_page'])||isset($_GET['assignment_revisions_page'])||isset($_GET['pricing_assignment'])||isset($_GET['pricing_q'])||isset($_GET['pricing_page']);
$assignmentOther=($personalPriceNativeRoute??false)||isset($_GET['assignment_prices'])||isset($_GET['billing'])||isset($_GET['automation']);
$assignmentNativeRoute=$assignmentBase&&($assignmentNativePost||$assignmentDownload||(in_array($assignmentMethod,['GET','HEAD'],true)&&$assignmentGet&&!$assignmentOther));
if(!$assignmentNativeRoute&&!$assignmentNativePost)return;
$assignmentStore=static function(array $draft):array{$_SESSION['general_settings_drafts'][$draft['id']]=$draft;return$draft;};
try{
    if(!$assignmentNativeRoute||!$workspaceAllowed)throw new DomainException('Tarifzuweisungen im vorhandenen Einstellungsbereich öffnen.',403);
    $assignmentRepository=new AssignmentRepository($db,(string)$_SESSION['actor']);$assignmentActor=$assignmentRepository->actor();$assignmentRequests=new AssignmentRequests($db,(string)$_SESSION['actor']);
    if($assignmentDownload){
        if(!in_array($assignmentMethod,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
        if(isset($_GET['assignment_original'])){
            if(!is_string($_GET['assignment_original']))throw new DomainException('Ursprüngliche Zuweisungsbestätigung auswählen.',422);
            $read=$assignmentRequests->receipt($_GET['assignment_original']);
            if($read['input']['tab']!==$assignmentTab)throw new DomainException('Ursprüngliche Zuweisungsbestätigung nicht verfügbar.',404);
            $bytes=$read['original_json'];$filename='dvm-assignment-original.json';
        }else{
            $read=$assignmentRepository->revision(CatalogInput::id($_GET['assignment_id']??null),$assignmentTab,$_GET['assignment_revision_original']);$bytes=$read['payload_json'];$filename='dvm-assignment-version-'.$read['version'].'.json';
        }
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Length: '.strlen($bytes));if($assignmentMethod!=='HEAD')echo$bytes;exit;
    }
    if(!$assignmentNativePost)return;
    $assignmentHandled=true;
    if(!is_string($_POST['csrf']??null)||!hash_equals((string)$_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    $assignmentActor=$assignmentRepository->actor(true);
    if($assignmentLegacyPost){
        $input=$assignmentAction==='settings-assign'
            ?['operation'=>'create','tab'=>$assignmentTab,'id'=>'','version'=>'0','data'=>['catalog_id'=>$_POST['catalog_id']??'','contact_id'=>$_POST['contact_id']??'','start'=>$_POST['start']??'','end'=>$_POST['end']??'']]
            :['operation'=>'end','tab'=>$assignmentTab,'id'=>$_POST['assignment_id']??'','version'=>$_POST['version']??'','data'=>['end'=>$_POST['end']??'']];
        $assignmentPostedDraft=AssignmentDraft::open($_SESSION,$assignmentActor,$assignmentTab,$input,true);
        throw new DomainException('Dieses frühere Zuweisungsformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst prüfen und danach gesondert bestätigen.',422);
    }
    $assignmentPostedDraft=AssignmentDraft::get($_SESSION,$assignmentActor,$assignmentTab,$_POST['assignment_draft']??null);
    if($assignmentPostedDraft['accepted']!==null){
        $read=$assignmentRequests->receipt($assignmentPostedDraft['key']);redirectTo(['view'=>'settings','tab'=>$assignmentTab,'catalog_assignments'=>'1','assignment_receipt'=>$read['key']]);
    }
    $intent=$_POST['assignment_intent']??'';
    if(!is_string($intent)||(!in_array($intent,['preview','current','accept','discard'],true)&&!preg_match('/\A(?:catalog|contact)-(?:search|previous|next|page|clear|select:.{1,256})\z/sD',$intent)))throw new DomainException('Gültige ungespeicherte Zuweisungsaktion auswählen.',422);
    if($assignmentPostedDraft['pending']){
        try{
            $read=$assignmentRequests->receipt($assignmentPostedDraft['key']);$assignmentPostedDraft['pending']=false;$assignmentPostedDraft['accepted']=$read['key'];$assignmentStore($assignmentPostedDraft);
            redirectTo(['view'=>'settings','tab'=>$assignmentTab,'catalog_assignments'=>'1','assignment_draft'=>$assignmentPostedDraft['id'],'assignment_receipt'=>$read['key']]);
        }catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;}
        if($intent!=='current')throw new DomainException('Zuerst den ursprünglichen Zuweisungsausgang lesen.',409);
    }
    if($intent==='discard'){GeneralDraft::discard($_SESSION,$assignmentPostedDraft);redirectTo(['view'=>'settings','tab'=>$assignmentTab,'catalog_assignments'=>'1']);}
    if($intent==='accept'){
        if(!is_string($_POST['assignment_key']??null)||!is_string($_POST['assignment_review']??null)||!hash_equals($assignmentPostedDraft['key'],$_POST['assignment_key'])||!is_array($assignmentPostedDraft['review'])||!hash_equals($assignmentPostedDraft['review']['hash'],$_POST['assignment_review']))throw new DomainException('Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.',409);
        if(($_POST['assignment_approved']??null)!=='1')throw new DomainException('Die vollständig geprüfte Tarifzuweisung ausdrücklich bestätigen.',422);
        $assignmentPostedDraft['pending']=true;$assignmentStore($assignmentPostedDraft);$assignmentAcceptStarted=true;
        $read=$assignmentRequests->accept($assignmentPostedDraft['input'],$assignmentPostedDraft['key'],$assignmentPostedDraft['review']['hash'],true);
        $assignmentAcceptStarted=false;$assignmentPostedDraft['pending']=false;$assignmentPostedDraft['accepted']=$read['key'];$assignmentStore($assignmentPostedDraft);
        redirectTo(['view'=>'settings','tab'=>$assignmentTab,'catalog_assignments'=>'1','assignment_draft'=>$assignmentPostedDraft['id'],'assignment_receipt'=>$read['key']]);
    }
    $raw=$assignmentPostedDraft['input'];$fields=$raw['operation']==='create'?['end','start']:['end'];$posted=$_POST['data']??null;$keys=is_array($posted)?array_keys($posted):[];sort($keys);
    if(!is_array($posted)||$keys!==$fields)throw new DomainException('Vollständige ursprüngliche Zuweisungsfelder prüfen.',422);
    foreach($fields as$field)$raw['data'][$field]=$posted[$field];
    $sourceAction=null;
    if(str_contains($intent,'-')){
        if($raw['operation']!=='create')throw new DomainException('Quellen nur beim Anlegen einer Zuweisung auswählen.',422);
        [$source,$sourceAction]=explode('-',$intent,2);
        if($sourceAction==='clear')$raw['data'][$source==='catalog'?'catalog_id':'contact_id']='';
        elseif(str_starts_with($sourceAction,'select:'))$raw['data'][$source==='catalog'?'catalog_id':'contact_id']=substr($sourceAction,7);
    }
    if($raw['operation']==='create')foreach(['catalog','contact']as$choice)foreach(['query','page']as$field){$name='assignment_'.$choice.'_'.$field;if(array_key_exists($name,$_POST))$assignmentPostedDraft['choices'][$choice][$field]=$_POST[$name];}
    $assignmentPostedDraft=AssignmentDraft::replace($assignmentPostedDraft,$raw,$intent==='current');$assignmentStore($assignmentPostedDraft);
    if($sourceAction!==null){
        if(in_array($sourceAction,['search','previous','next','page'],true)){
            $query=$_POST['assignment_'.$source.'_query']??'';$page=$_POST['assignment_'.$source.'_page']??'1';
            $assignmentPostedDraft['choices'][$source]=['query'=>$query,'page'=>$sourceAction==='search'?'1':$page];$assignmentStore($assignmentPostedDraft);
            if(in_array($sourceAction,['previous','next'],true)){
                if(!is_string($page)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new DomainException('Vollständige Auswahlsuche und Seite prüfen.',422);
                $page=(string)((int)$page+($sourceAction==='previous'?-1:1));$assignmentPostedDraft['choices'][$source]['page']=$page;$assignmentStore($assignmentPostedDraft);
            }
            if($source==='catalog')$assignmentRepository->catalogs($assignmentTab,$assignmentPostedDraft['choices'][$source]);else$assignmentRepository->contacts($assignmentPostedDraft['choices'][$source]);
        }elseif(str_starts_with($sourceAction,'select:')){
            if($source==='catalog'){
                $selected=(new CatalogRepository($db,(string)$_SESSION['actor']))->find($raw['data']['catalog_id'],$assignmentTab);
                if(!$selected['data']['active'])throw new DomainException('Aktiven Tarif auswählen.',422);
            }else$assignmentRepository->contact($raw['data']['contact_id']);
        }
        return;
    }
    if($intent==='current'){
        $assignmentPostedDraft['input']['version']=$raw['operation']==='create'?'0':(string)$assignmentRepository->state($raw['id'],$assignmentTab)['version'];
        $assignmentPostedDraft['pending']=false;$assignmentStore($assignmentPostedDraft);
    }
    $assignmentPostedDraft['review']=$assignmentRequests->prepare($assignmentPostedDraft['input']);$assignmentStore($assignmentPostedDraft);
    if($issues=AssignmentRequests::issues($assignmentPostedDraft['review']))throw new DomainException($issues[0]['message'],$issues[0]['code']);
}catch(DomainException $failure){
    if($assignmentNativePost)$assignmentHandled=true;
    if($assignmentAcceptStarted&&$assignmentPostedDraft!==null){$assignmentPostedDraft['pending']=false;$assignmentPostedDraft['conflicted']=true;}
    if($assignmentPostedDraft!==null)$assignmentStore($assignmentPostedDraft);
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=$failure->getMessage();
}catch(Throwable){
    if($assignmentNativePost)$assignmentHandled=true;
    http_response_code(503);$error=$assignmentAcceptStarted?'Zuweisungsausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.':'Tarifzuweisungen konnten nicht geladen werden.';
}
if($assignmentDownload){
    try{$error=\Kulturhus\Native\Language\AssignmentMessages::text($error,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());}catch(Throwable){}
    header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');exit;
}
