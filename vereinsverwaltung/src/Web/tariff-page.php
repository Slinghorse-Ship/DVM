<?php declare(strict_types=1);
use Kulturhus\Verein\Settings\{CatalogInput,CatalogRepository,CatalogRequests,GeneralDraft,TariffDraft};
$tariffSaved=false;$tariffState=null;$tariffDraft=null;$tariffDraftIndex=[];$tariffMessages=[];
if(!$tariffNativeRoute||!$workspaceAllowed)return;
$tariffMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();$error=\Kulturhus\Native\Language\TariffMessages::text($error,$tariffMessages);
try{
    $tariffRepository??=new CatalogRepository($db,(string)$_SESSION['actor']);$tariffActor=$tariffRepository->actor();$tariffRequests??=new CatalogRequests($db,(string)$_SESSION['actor']);
    $tariffState=['actor'=>$tariffActor,'can_write'=>$tariffActor['finance_access']==='write','current'=>null,'catalogue'=>null,'receipt'=>null,'history'=>null,'revisions'=>null,'revision'=>null,'linked_rates'=>[],'errors'=>[]];
    if($tariffTab==='rates')$tariffState['linked_rates']=$tariffRepository->linkedMembershipRates();
    if($tariffPostedDraft!==null)$tariffDraft=TariffDraft::get($_SESSION,$tariffActor,$tariffTab,$tariffPostedDraft['id']);elseif(isset($_GET['tariff_draft']))$tariffDraft=TariffDraft::get($_SESSION,$tariffActor,$tariffTab,$_GET['tariff_draft']);
    if(isset($_GET['tariff_saved'])&&is_string($_GET['tariff_saved'])){$saved=$tariffRequests->receipt($_GET['tariff_saved']);$tariffSaved=($saved['result']['id']===($_GET['edit']??null)&&in_array($saved['result']['kind'],CatalogRepository::kinds($tariffTab),true));}
    if(isset($_GET['tariff_receipt'])){
        if(!is_string($_GET['tariff_receipt']))throw new DomainException('Ursprüngliche Tarifbestätigung auswählen.',422);
        try{$receipt=$tariffRequests->receipt($_GET['tariff_receipt']);if(!in_array($receipt['input']['kind'],CatalogRepository::kinds($tariffTab),true))throw new DomainException('Ursprüngliche Tarifbestätigung nicht verfügbar.',404);$tariffState['receipt']=$receipt;}
        catch(DomainException $failure){if($failure->getCode()!==404)throw$failure;$tariffState['errors']['receipt']=$failure->getMessage();http_response_code(404);}
        if($tariffDraft&&$tariffState['receipt']&&$tariffDraft['key']===$tariffState['receipt']['key']){if(CatalogInput::capture($tariffDraft['input'])!==$tariffState['receipt']['input'])throw new DomainException('Diese Tarifkennung gehört zu anderen ursprünglichen Angaben.',409);$tariffDraft['pending']=false;$tariffDraft['accepted']=$tariffState['receipt']['key'];$_SESSION['general_settings_drafts'][$tariffDraft['id']]=$tariffDraft;}
    }
    if($tariffDraft&&$tariffDraft['accepted']!==null){$accepted=$tariffRequests->receipt($tariffDraft['key']);if(!isset($_GET['tariff_receipt']))$tariffState['receipt']=$accepted;$tariffState['current']=$tariffRepository->state($accepted['result']['id'],$accepted['result']['kind']);}
    elseif($tariffDraft)$tariffState['current']=$tariffRepository->state($tariffDraft['input']['id'],$tariffDraft['input']['kind']);
    elseif(isset($_GET['edit']))$tariffState['current']=$tariffRepository->find(CatalogInput::id($_GET['edit']),$tariffTab);
    elseif($tariffState['receipt'])$tariffState['current']=$tariffRepository->state($tariffState['receipt']['result']['id'],$tariffState['receipt']['result']['kind']);
    elseif(isset($_GET['new_catalog'])){$kind=input($_GET,'kind',$tariffTab==='services'?'service':'contribution');if(!in_array($kind,CatalogRepository::kinds($tariffTab),true))throw new DomainException('Eigene Tarifart auswählen.',422);if(!$tariffState['can_write'])throw new DomainException('Aktuelle Finanzberechtigung für Tarife erforderlich.',403);$tariffState['current']=$tariffRepository->state('',$kind);}
    if(!$tariffDraft&&$tariffState['current']&&$tariffState['can_write']&&!isset($_GET['tariff_receipt'])){$current=$tariffState['current'];$tariffDraft=TariffDraft::open($_SESSION,$tariffActor,$tariffTab,['id'=>$current['id'],'kind'=>$current['kind'],'version'=>(string)$current['version'],'data'=>$current['data']],isset($_GET['new_catalog']));}
    foreach(['catalogue','history','revisions']as$kind){try{
        if($kind==='catalogue')$list=$tariffRepository->page($tariffTab,['query'=>$_GET['q']??'','page'=>$_GET['tariff_page']??'1']);
        elseif($kind==='history')$list=$tariffRequests->history($tariffTab,['query'=>$_GET['tariff_history_q']??'','page'=>$_GET['tariff_history_page']??'1']);
        elseif(!$tariffState['current']||$tariffState['current']['id']==='')continue;
        else $list=$tariffRepository->revisions($tariffState['current']['id'],$tariffState['current']['kind'],$_GET['tariff_revisions_page']??'1');
        $tariffState[$kind]=$list;if($list['error']!==''){$tariffState['errors'][$kind]=$list['error'];if(http_response_code()<400)http_response_code(422);}
    }catch(DomainException $failure){$tariffState['errors'][$kind]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,503],true)?$failure->getCode():422);}}
    if(isset($_GET['tariff_revision'])){if(!$tariffState['current']||$tariffState['current']['id']==='')throw new DomainException('Eigenen ursprünglichen Tarif auswählen.',422);$tariffState['revision']=$tariffRepository->revision($tariffState['current']['id'],$tariffState['current']['kind'],$_GET['tariff_revision']);}
    foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$tariffActor['id']&&$stored['club']===$tariffActor['club_id']&&str_starts_with($stored['section'],'catalog:')&&in_array($stored['input']['kind']??null,CatalogRepository::kinds($tariffTab),true)&&$stored['accepted']===null)$tariffDraftIndex[]=['id'=>$stored['id'],'key'=>$stored['key'],'pending'=>$stored['pending']];
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():403);$error=\Kulturhus\Native\Language\TariffMessages::text($failure->getMessage(),$tariffMessages);$tariffDraft=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\TariffMessages::text('Tarife konnten nicht geladen werden.',$tariffMessages);$tariffDraft=null;}
