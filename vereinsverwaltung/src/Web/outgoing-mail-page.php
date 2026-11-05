<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\{OutgoingMailDraft,OutgoingMailInput,OutgoingMailRequests};
$outgoingmailState=null;$outgoingmailDraft=null;$outgoingmailMessages=[];
if(!$outgoingmailRoute||$outgoingmailInvoice===''||!is_string($outgoingmailInvoice))return;
try{
    $outgoingmailMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
    $error=\Kulturhus\Native\Language\OutgoingMailMessages::text($error,$outgoingmailMessages);
    if(!$workspaceAllowed)throw new DomainException('Ausgangsrechnung im eigenen Finanzbereich öffnen.',403);
    $outgoingmailRequests??=new OutgoingMailRequests($db,(string)$_SESSION['actor']);$outgoingmailActor=$outgoingmailRequests->assertAccess();
    $outgoingmailState=['context'=>null,'receipt'=>null,'history'=>null,'review'=>null,'errors'=>[]];
    $problem=static function(string $piece,DomainException $failure)use(&$outgoingmailState):void{$outgoingmailState['errors'][$piece]=$failure->getMessage();if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);};
    if($outgoingmailPostedDraft!==null)$outgoingmailDraft=OutgoingMailDraft::get($_SESSION,$outgoingmailActor,$outgoingmailInvoice,$outgoingmailPostedDraft['id']);
    elseif(isset($_GET['outgoingmail_draft']))$outgoingmailDraft=OutgoingMailDraft::get($_SESSION,$outgoingmailActor,$outgoingmailInvoice,$_GET['outgoingmail_draft']);
    if(isset($_GET['outgoingmail_receipt']))try{
        if(!is_string($_GET['outgoingmail_receipt']))throw new DomainException('Eigene ursprüngliche Rechnungs-Mailbestätigung auswählen.',422);
        $first=$outgoingmailRequests->receipt($_GET['outgoingmail_receipt']);if($first['input']['invoice']!==$outgoingmailInvoice)throw new DomainException('Eigene ursprüngliche Rechnungs-Mailbestätigung nicht verfügbar.',404);$outgoingmailState['receipt']=$first;
    }catch(DomainException $failure){$problem('receipt',$failure);}
    if($outgoingmailDraft&&($outgoingmailDraft['pending']||$outgoingmailDraft['accepted']!==null)&&!$outgoingmailState['receipt'])try{$outgoingmailState['receipt']=$outgoingmailRequests->receipt($outgoingmailDraft['key']);}catch(DomainException $failure){if($failure->getCode()!==404)$problem('receipt',$failure);}
    if($outgoingmailState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$outgoingmailActor['id']&&$stored['club']===$outgoingmailActor['club_id']&&$stored['section']===OutgoingMailDraft::section($outgoingmailInvoice)&&$stored['key']===$outgoingmailState['receipt']['key'])OutgoingMailDraft::store($_SESSION,OutgoingMailDraft::complete($stored,$stored['key']));
        if($outgoingmailDraft&&$outgoingmailDraft['key']===$outgoingmailState['receipt']['key'])$outgoingmailDraft=OutgoingMailDraft::complete($outgoingmailDraft,$outgoingmailDraft['key']);
        if($error===\Kulturhus\Native\Language\OutgoingMailMessages::text('Rechnungs-Mailausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.',$outgoingmailMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    try{$outgoingmailState['history']=$outgoingmailRequests->history($outgoingmailInvoice,['query'=>$_GET['outgoingmail_history_q']??'','page'=>$_GET['outgoingmail_history_page']??'1']);if($outgoingmailState['history']['error']!=='')throw new DomainException($outgoingmailState['history']['error'],422);}catch(DomainException $failure){$problem('history',$failure);}
    // An issued original is required only for the mail stage; draft creation has its own workflow.
    $invoiceState=(new \Kulturhus\Verein\OutgoingInvoices($db,(string)$_SESSION['actor']))->get($outgoingmailInvoice);
    if($invoiceState['state']!=='issued')return;
    try{$outgoingmailState['context']=$outgoingmailRequests->context($outgoingmailInvoice);}catch(DomainException $failure){$problem('context',$failure);}
    $explicitHistory=isset($_GET['outgoingmail_history_q'])||isset($_GET['outgoingmail_history_page']);$context=$outgoingmailState['context'];
    if(!$outgoingmailDraft&&!$outgoingmailState['receipt']&&!isset($_GET['outgoingmail_receipt'])&&!$explicitHistory&&!isset($_GET['outgoingmail_closed'])&&$context){
        $section=OutgoingMailDraft::section($outgoingmailInvoice);$slot=hash('sha256',$outgoingmailActor['club_id']."\0".$outgoingmailActor['id']."\0".$section);$stored=$_SESSION['general_settings_slots'][$slot]??null;
        if(!isset($_GET['outgoingmail_new'])&&is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored])){$candidate=OutgoingMailDraft::get($_SESSION,$outgoingmailActor,$outgoingmailInvoice,$stored);if($candidate['accepted']===null)$outgoingmailDraft=$candidate;}
        if(!$outgoingmailDraft&&in_array($context['delivery']['state']??null,[null,'blocked'],true)&&filter_var($context['invoice']['data']['buyer_email']??'',FILTER_VALIDATE_EMAIL)){
            try{$outgoingmailRequests->assertAccess(true);$outgoingmailDraft=OutgoingMailDraft::open($_SESSION,$outgoingmailActor,OutgoingMailInput::initial($context,$context['delivery']?'replace':'queue'),isset($_GET['outgoingmail_new']));}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
        }
    }
    if($outgoingmailDraft&&$outgoingmailDraft['accepted']===null&&!$outgoingmailDraft['pending']&&is_array($outgoingmailDraft['review']))try{
        $review=$outgoingmailRequests->prepare($outgoingmailDraft['input']);if(!hash_equals($outgoingmailDraft['review']['hash'],$review['hash']))throw new DomainException('Nachricht, Rechnung, Zahlungsstand oder Quellen inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.',409);$outgoingmailState['review']=$review;
    }catch(DomainException $failure){$problem('review',$failure);}
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\OutgoingMailMessages::text($failure->getMessage(),$outgoingmailMessages);$outgoingmailState=null;$outgoingmailDraft=null;}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\OutgoingMailMessages::text('Rechnungs-Mailbereich konnte nicht geladen werden.',$outgoingmailMessages);$outgoingmailState=null;$outgoingmailDraft=null;}
