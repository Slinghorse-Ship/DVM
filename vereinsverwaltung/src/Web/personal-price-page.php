<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{PersonalPriceDraft,PersonalPriceRepository,PersonalPriceRequests,CatalogInput};
$personalPriceState=null;$personalPriceDraft=null;$personalPriceDraftIndex=[];$personalPriceMessages=[];
if(!$personalPriceNativeRoute)return;
$personalPriceMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
if(!$workspaceAllowed){$error=\Kulturhus\Native\Language\PersonalPriceMessages::text('Aktuelle Finanz- und Mitgliederrechte für persönliche Preise erforderlich.',$personalPriceMessages);return;}
$error=\Kulturhus\Native\Language\PersonalPriceMessages::text($error,$personalPriceMessages);$personalPriceReadOwn=!$db->inTransaction();
try{
    if($personalPriceReadOwn)$db->beginTransaction();$personalPriceRepository??=new PersonalPriceRepository($db,(string)$_SESSION['actor']);$personalPriceActor=$personalPriceRepository->actor();$personalPriceRequests??=new PersonalPriceRequests($db,(string)$_SESSION['actor']);
    $personalPriceState=['actor'=>$personalPriceActor,'can_write'=>$personalPriceActor['finance_access']==='write','current'=>null,'receipt'=>null,'list'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'quote'=>null,'quote_error'=>null,'review_readable'=>false,'previous_readable'=>[],'errors'=>[]];
    $personalPriceProblem=static function(string $field,DomainException $failure)use(&$personalPriceState):void{$personalPriceState['errors'][$field]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    if($personalPricePostedDraft!==null)$personalPriceDraft=PersonalPriceDraft::get($_SESSION,$personalPriceActor,$personalPriceTab,$personalPricePostedDraft['id']);elseif(isset($_GET['personal_price_draft']))$personalPriceDraft=PersonalPriceDraft::get($_SESSION,$personalPriceActor,$personalPriceTab,$_GET['personal_price_draft']);
    if(isset($_GET['personal_price_receipt'])){
        if(!is_string($_GET['personal_price_receipt']))throw new DomainException('Ursprüngliche persönliche Preisbestätigung auswählen.',422);
        try{$read=$personalPriceRequests->receipt($_GET['personal_price_receipt']);if($read['input']['tab']!==$personalPriceTab)throw new DomainException('Ursprüngliche persönliche Preisbestätigung nicht verfügbar.',404);$personalPriceState['receipt']=$read;}catch(DomainException $failure){$personalPriceProblem('receipt',$failure);}
        if($personalPriceDraft&&$personalPriceState['receipt']&&$personalPriceDraft['key']===$read['key']){
            if(\Kulturhus\Verein\Settings\PersonalPriceInput::capture($personalPriceDraft['input'])!==$read['input'])throw new DomainException('Diese persönliche Preiskennung gehört zu anderen ursprünglichen Angaben.',409);
            $personalPriceDraft['pending']=false;$personalPriceDraft['accepted']=$read['key'];$_SESSION['general_settings_drafts'][$personalPriceDraft['id']]=$personalPriceDraft;
        }
    }
    $selected=null;
    if($personalPriceDraft)$selected=$personalPriceDraft['input']['id'];elseif(isset($_GET['price_assignment']))$selected=CatalogInput::id($_GET['price_assignment']);elseif($personalPriceState['receipt'])$selected=$personalPriceState['receipt']['result']['id'];
    if($selected!==null){try{$personalPriceState['current']=$personalPriceRepository->state($selected,$personalPriceTab);}catch(DomainException $failure){$personalPriceProblem('current',$failure);}}
    if($personalPriceState['current']){try{$personalPriceState['quote']=(new \Kulturhus\Verein\CatalogPricing($db,(string)$_SESSION['actor']))->quote($personalPriceState['current']['id'],date('Y-m-d'));}catch(DomainException $failure){$personalPriceState['quote_error']=$failure->getMessage();}}
    if($personalPriceDraft&&$personalPriceDraft['accepted']!==null&&!isset($_GET['personal_price_receipt']))$personalPriceState['receipt']=$personalPriceRequests->receipt($personalPriceDraft['accepted']);
    if(!$personalPriceDraft&&$personalPriceState['current']&&$personalPriceState['can_write']&&!isset($_GET['personal_price_receipt'])&&!$personalPriceState['current']['source']['linked_membership_tariff']){
        $current=$personalPriceState['current'];$raw=['tab'=>$personalPriceTab,'id'=>$current['id'],'version'=>(string)$current['version'],'data'=>['mode'=>$current['data']['mode'],'value'=>$current['data']['mode']==='none'?'':\Kulturhus\Verein\Finance::decimal($current['data']['value_units'])]];
        $personalPriceDraft=PersonalPriceDraft::open($_SESSION,$personalPriceActor,$personalPriceTab,$raw,true);
        if($personalPriceReadOwn)$db->commit();redirectTo(['view'=>'settings','tab'=>$personalPriceTab,'assignment_prices'=>'1','personal_price_draft'=>$personalPriceDraft['id']]);
    }
    foreach(['list','history','revisions']as$piece){try{
        if($piece==='list')$personalPriceState['list']=(new \Kulturhus\Verein\CatalogAssignments($db,(string)$_SESSION['actor']))->page($personalPriceTab,$_GET);
        elseif($piece==='history')$personalPriceState['history']=$personalPriceRequests->history($personalPriceTab,['query'=>$_GET['personal_price_history_q']??'','page'=>$_GET['personal_price_history_page']??'1']);
        elseif($personalPriceState['current'])$personalPriceState['revisions']=$personalPriceRepository->revisions($personalPriceState['current']['id'],$personalPriceTab,$_GET['personal_price_revisions_page']??'1');
    }catch(DomainException $failure){$personalPriceProblem($piece,$failure);}}
    if(isset($_GET['personal_price_revision'])){try{if(!$personalPriceState['current'])throw new DomainException('Eigene Tarifzuweisung für den persönlichen Preis auswählen.',422);$personalPriceState['revision']=$personalPriceRepository->revision($personalPriceState['current']['id'],$personalPriceTab,$_GET['personal_price_revision']);}catch(DomainException $failure){$personalPriceProblem('revision',$failure);}}
    $readable=static function(?array $review)use($personalPriceRepository):bool{if(!$review)return false;try{$personalPriceRepository->contact($review['source']['contact']['id']);return true;}catch(DomainException $failure){if(!in_array($failure->getCode(),[403,404],true))throw$failure;return false;}};
    if($personalPriceDraft&&$personalPriceDraft['accepted']===null){$personalPriceState['review_readable']=$readable($personalPriceDraft['review']);foreach($personalPriceDraft['previous']as$index=>$previous)$personalPriceState['previous_readable'][$index]=$readable($previous['review']);}
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$personalPriceActor['id']&&$stored['club']===$personalPriceActor['club_id']&&str_starts_with($stored['section'],'personal-price:'.$personalPriceTab.':')&&$stored['accepted']===null)$personalPriceDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'pending'=>$stored['pending']];
    if($personalPriceReadOwn)$db->commit();
}catch(DomainException $failure){if($personalPriceReadOwn&&$db->inTransaction())$db->rollBack();http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\PersonalPriceMessages::text($failure->getMessage(),$personalPriceMessages);$personalPriceDraft=null;if(in_array($failure->getCode(),[403,503],true))$personalPriceState=null;}
catch(Throwable){if($personalPriceReadOwn&&$db->inTransaction())$db->rollBack();http_response_code(503);$error=\Kulturhus\Native\Language\PersonalPriceMessages::text('Persönliche Preise konnten nicht geladen werden.',$personalPriceMessages);$personalPriceDraft=null;$personalPriceState=null;}
