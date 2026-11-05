<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{AssignmentDraft,AssignmentInput,AssignmentRepository,AssignmentRequests,CatalogInput,CatalogRepository};
$assignmentState=null;$assignmentDraft=null;$assignmentDraftIndex=[];$assignmentMessages=[];
if(!$assignmentNativeRoute||!$workspaceAllowed)return;
$assignmentMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
$error=\Kulturhus\Native\Language\AssignmentMessages::text($error,$assignmentMessages);
$assignmentReadOwn=!$db->inTransaction();
try{
    if($assignmentReadOwn)$db->beginTransaction();
    $assignmentRepository??=new AssignmentRepository($db,(string)$_SESSION['actor']);$assignmentActor=$assignmentRepository->actor();$assignmentRequests??=new AssignmentRequests($db,(string)$_SESSION['actor']);
    $assignmentState=['actor'=>$assignmentActor,'can_write'=>$assignmentActor['finance_access']==='write'&&$assignmentActor['members_access']==='write','can_finance_write'=>$assignmentActor['finance_access']==='write','receipt'=>null,'current'=>null,'list'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'quote'=>null,'quote_input'=>['id'=>$_GET['pricing_assignment']??'','date'=>$_GET['pricing_date']??date('Y-m-d'),'day'=>$_GET['pricing_day']??'','mode'=>$_GET['pricing_mode']??'full'],'selected_catalog'=>null,'selected_contact'=>null,'catalog_choices'=>null,'contact_choices'=>null,'review_readable'=>false,'previous_readable'=>[],'errors'=>[]];
    $assignmentProblem=static function(string $field,DomainException $failure)use(&$assignmentState):void{
        $assignmentState['errors'][$field]=$failure->getMessage();
        if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);
    };
    if($assignmentPostedDraft!==null)$assignmentDraft=AssignmentDraft::get($_SESSION,$assignmentActor,$assignmentTab,$assignmentPostedDraft['id']);
    elseif(isset($_GET['assignment_draft']))$assignmentDraft=AssignmentDraft::get($_SESSION,$assignmentActor,$assignmentTab,$_GET['assignment_draft']);
    if(isset($_GET['assignment_receipt'])){
        if(!is_string($_GET['assignment_receipt']))throw new DomainException('Ursprüngliche Zuweisungsbestätigung auswählen.',422);
        try{
            $read=$assignmentRequests->receipt($_GET['assignment_receipt']);
            if($read['input']['tab']!==$assignmentTab)throw new DomainException('Ursprüngliche Zuweisungsbestätigung nicht verfügbar.',404);
            $assignmentState['receipt']=$read;
        }catch(DomainException $failure){$assignmentProblem('receipt',$failure);}
        if($assignmentDraft&&$assignmentState['receipt']&&$assignmentDraft['key']===$assignmentState['receipt']['key']){
            if(AssignmentInput::capture($assignmentDraft['input'])!==$assignmentState['receipt']['input'])throw new DomainException('Diese Zuweisungskennung gehört zu anderen ursprünglichen Angaben.',409);
            $assignmentDraft['pending']=false;$assignmentDraft['accepted']=$assignmentState['receipt']['key'];$_SESSION['general_settings_drafts'][$assignmentDraft['id']]=$assignmentDraft;
        }
    }
    if(!$assignmentDraft&&(isset($_GET['new_assignment'])||isset($_GET['assignment_end']))){
        if(!$assignmentState['can_write'])throw new DomainException('Aktuelle Finanz- und Mitgliederberechtigung für Tarifzuweisungen erforderlich.',403);
        if(isset($_GET['assignment_end'])){
            $state=$assignmentRepository->state(CatalogInput::id($_GET['assignment_end']),$assignmentTab);
            if($state['data']['end']!=='')throw new DomainException('Diese Tarifzuweisung ist bereits befristet oder beendet.',409);
            $input=['operation'=>'end','tab'=>$assignmentTab,'id'=>$state['id'],'version'=>(string)$state['version'],'data'=>['end'=>date('Y-m-d')]];
        }else $input=['operation'=>'create','tab'=>$assignmentTab,'id'=>'','version'=>'0','data'=>['catalog_id'=>$_GET['assignment_catalog']??'','contact_id'=>$_GET['assignment_contact']??'','start'=>date('Y-m-d'),'end'=>'']];
        $assignmentDraft=AssignmentDraft::open($_SESSION,$assignmentActor,$assignmentTab,$input,true);
        if($assignmentReadOwn)$db->commit();
        redirectTo(['view'=>'settings','tab'=>$assignmentTab,'catalog_assignments'=>'1','assignment_draft'=>$assignmentDraft['id']]);
    }
    $selected=null;
    if($assignmentDraft&&$assignmentDraft['accepted']!==null){
        $read=$assignmentRequests->receipt($assignmentDraft['accepted']);
        if(!isset($_GET['assignment_receipt']))$assignmentState['receipt']=$read;
        $selected=$read['result']['id'];
    }elseif($assignmentDraft&&$assignmentDraft['input']['operation']==='end')$selected=$assignmentDraft['input']['id'];
    elseif(isset($_GET['assignment_id']))$selected=CatalogInput::id($_GET['assignment_id']);
    elseif($assignmentState['receipt'])$selected=$assignmentState['receipt']['result']['id'];
    if($selected!==null){
        try{$assignmentState['current']=$assignmentRepository->state($selected,$assignmentTab);}
        catch(DomainException $failure){$assignmentProblem('current',$failure);}
    }
    foreach(['list','history','revisions']as$piece){
        try{
            if($piece==='list')$assignmentState['list']=(new \Kulturhus\Verein\CatalogAssignments($db,(string)$_SESSION['actor']))->page($assignmentTab,$_GET);
            elseif($piece==='history')$assignmentState['history']=$assignmentRequests->history($assignmentTab,['query'=>$_GET['assignment_history_q']??'','page'=>$_GET['assignment_history_page']??'1']);
            elseif($assignmentState['current'])$assignmentState['revisions']=$assignmentRepository->revisions($assignmentState['current']['id'],$assignmentTab,$_GET['assignment_revisions_page']??'1');
        }catch(DomainException $failure){$assignmentProblem($piece,$failure);}
    }
    if(isset($_GET['assignment_revision'])){
        try{
            if(!$assignmentState['current'])throw new DomainException('Eigene ursprüngliche Tarifzuweisung auswählen.',422);
            $assignmentState['revision']=$assignmentRepository->revision($assignmentState['current']['id'],$assignmentTab,$_GET['assignment_revision']);
        }catch(DomainException $failure){$assignmentProblem('revision',$failure);}
    }
    if(isset($_GET['pricing_assignment'])){
        try{
            $quoteInput=$assignmentState['quote_input'];
            foreach($quoteInput as$value)if(!is_string($value))throw new DomainException('Vollständige Preisangaben und Daten prüfen.',422);
            $quote=(new \Kulturhus\Verein\CatalogPricing($db,(string)$_SESSION['actor']))->quote($quoteInput['id'],$quoteInput['date'],$quoteInput['mode'],$quoteInput['day']===''?null:$quoteInput['day']);
            if(AssignmentRepository::tab($quote['kind'])!==$assignmentTab)throw new DomainException('Tarifzuweisung im passenden Bereich auswählen.',404);
            $assignmentState['quote']=$quote;
        }catch(DomainException $failure){$assignmentProblem('quote',$failure);}
    }
    $sourceReadable=static function(?array $review)use($assignmentRepository):bool{
        if(!$review)return false;
        try{$assignmentRepository->contact($review['source']['contact']['id']);return true;}
        catch(DomainException $failure){if(!in_array($failure->getCode(),[403,404],true))throw$failure;return false;}
    };
    if($assignmentDraft&&$assignmentDraft['accepted']===null){
        $raw=$assignmentDraft['input']['data'];
        if($assignmentDraft['input']['operation']==='create'){
            foreach(['catalog','contact']as$source){
                try{
                    $value=$raw[$source.'_id']??'';
                    if($value!==''){
                        if(!is_string($value))throw new DomainException('Vollständige ursprüngliche Zuweisungsfelder prüfen.',422);
                        $assignmentState['selected_'.$source]=$source==='catalog'?(new CatalogRepository($db,(string)$_SESSION['actor']))->find($value,$assignmentTab):$assignmentRepository->contact($value);
                    }
                }catch(DomainException $failure){$assignmentProblem('selected_'.$source,$failure);}
                try{
                    $assignmentState[$source.'_choices']=$source==='catalog'?$assignmentRepository->catalogs($assignmentTab,$assignmentDraft['choices'][$source]):$assignmentRepository->contacts($assignmentDraft['choices'][$source]);
                }catch(DomainException $failure){$assignmentProblem($source.'_choices',$failure);}
            }
        }
        $assignmentState['review_readable']=$sourceReadable($assignmentDraft['review']);
        foreach($assignmentDraft['previous']as$index=>$earlier)$assignmentState['previous_readable'][$index]=$sourceReadable($earlier['review']);
    }
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$assignmentActor['id']&&$stored['club']===$assignmentActor['club_id']&&str_starts_with($stored['section'],'assignment:'.$assignmentTab.':')&&$stored['accepted']===null)$assignmentDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'pending'=>$stored['pending']];
    if($assignmentReadOwn)$db->commit();
}catch(DomainException $failure){
    if($assignmentReadOwn&&$db->inTransaction())$db->rollBack();
    http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);
    $error=\Kulturhus\Native\Language\AssignmentMessages::text($failure->getMessage(),$assignmentMessages);$assignmentDraft=null;
    if(in_array($failure->getCode(),[403,503],true))$assignmentState=null;
}catch(Throwable){
    if($assignmentReadOwn&&$db->inTransaction())$db->rollBack();
    http_response_code(503);$error=\Kulturhus\Native\Language\AssignmentMessages::text('Tarifzuweisungen konnten nicht geladen werden.',$assignmentMessages);$assignmentDraft=null;$assignmentState=null;
}
