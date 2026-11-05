<?php declare(strict_types=1);
use Kulturhus\Verein\Finance\Invoices\Reminder\{ReminderDraft, ReminderRequests, ReminderContext};

$reminderState=null;$reminderDraft=null;$reminderMessages=[];
if(!$reminderRoute)return;
try{
    $reminderMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
    $error=\Kulturhus\Native\Language\InvoiceReminderMessages::text($error,$reminderMessages);
    if(!$workspaceAllowed)throw new DomainException('Zahlungserinnerung am eigenen ursprünglichen Rechnungsdokument öffnen.',403);
    $reminderRequests??=new ReminderRequests($db,(string)$_SESSION['actor']);$reminderActor=$reminderRequests->assertAccess();
    if($reminderRequests->parentDocument($reminderInvoice)!==$reminderDocument)throw new DomainException('Ursprüngliche Rechnung dieser Erinnerung nicht verfügbar.',404);
    $reminderState=['source'=>null,'receipt'=>null,'history'=>null,'deliveries'=>null,'can_write'=>false,'errors'=>[]];
    $reminderProblem=static function(string $piece,DomainException $failure)use(&$reminderState):void{
        $reminderState['errors'][$piece]=$failure->getMessage();
        if(http_response_code()<400)http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);
    };
    try{$reminderRequests->assertAccess(true);$reminderState['can_write']=true;}catch(DomainException $failure){if($failure->getCode()!==403)throw $failure;}
    $reminderExplicitHistory=isset($_GET['reminder_history_q'])||isset($_GET['reminder_history_page']);
    $reminderSlot=hash('sha256',$reminderActor['club_id']."\0".$reminderActor['id']."\0".ReminderDraft::section($reminderInvoice));
    if($reminderPostedDraft!==null)$reminderDraft=ReminderDraft::get($_SESSION,$reminderActor,$reminderInvoice,$reminderPostedDraft['id']);
    elseif(isset($_GET['reminder_draft']))$reminderDraft=ReminderDraft::get($_SESSION,$reminderActor,$reminderInvoice,$_GET['reminder_draft']);
    elseif(!$reminderExplicitHistory&&!isset($_GET['reminder_job'])){$stored=$_SESSION['general_settings_slots'][$reminderSlot]??null;if(is_string($stored)&&isset($_SESSION['general_settings_drafts'][$stored]))$reminderDraft=ReminderDraft::get($_SESSION,$reminderActor,$reminderInvoice,$stored);}
    $reminderWasConfirmed=$reminderDraft&&$reminderDraft['accepted']!==null;
    if(isset($_GET['reminder_first']))try{
        if(!is_string($_GET['reminder_first']))throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);
        $first=$reminderRequests->receipt($_GET['reminder_first']);
        if($first['input']['invoice']!==$reminderInvoice||$first['result']['source_document']!==$reminderDocument)throw new DomainException('Eigene ursprüngliche Erinnerungsbestätigung nicht verfügbar.',404);
        $reminderState['receipt']=$first;
    }catch(DomainException $failure){$reminderProblem('receipt',$failure);}
    elseif($reminderDraft)try{$reminderState['receipt']=$reminderRequests->receipt($reminderDraft['key']);}catch(DomainException $failure){if($failure->getCode()!==404||$reminderDraft['accepted']!==null)$reminderProblem('receipt',$failure);}
    if($first=$reminderState['receipt']){
        foreach($_SESSION['general_settings_drafts']??[]as$stored)if($stored['actor']===$reminderActor['id']&&$stored['club']===$reminderActor['club_id']&&$stored['section']===ReminderDraft::section($reminderInvoice)&&$stored['key']===$first['key'])ReminderDraft::store($_SESSION,ReminderDraft::complete($stored,$first['key']));
        if($reminderDraft&&$reminderDraft['key']===$first['key'])$reminderDraft=ReminderDraft::complete($reminderDraft,$first['key']);
        if($error===\Kulturhus\Native\Language\InvoiceReminderMessages::text('Erinnerungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.',$reminderMessages)){$error='';if(http_response_code()===503)http_response_code(200);}
    }
    if(isset($_GET['reminder_new'])&&$reminderWasConfirmed&&!isset($_GET['reminder_first'])&&!isset($_GET['reminder_draft'])&&$reminderState['errors']===[]){$reminderDraft=null;$reminderState['receipt']=null;}
    try{
        $reminderState['history']=$reminderRequests->history($reminderInvoice,['query'=>$_GET['reminder_history_q']??'','page'=>$_GET['reminder_history_page']??'1']);
        if($reminderState['history']['error']!=='')throw new DomainException($reminderState['history']['error'],422);
    }catch(DomainException $failure){$reminderProblem('history',$failure);}
    try{$reminderState['deliveries']=(new \Kulturhus\Verein\Finance\Invoices\Reminder\ReminderDelivery($db,(string)$_SESSION['actor'],\Kulturhus\Verein\ContactArchive::serverKey()))->listing($reminderInvoice,['page'=>$_GET['reminder_delivery_page']??'1']);if($reminderState['deliveries']['error']!=='')throw new DomainException($reminderState['deliveries']['error'],422);}catch(DomainException $failure){$reminderProblem('deliveries',$failure);}
    if($reminderState['receipt'])$reminderState['source']=$reminderState['receipt']['review']['source'];
    elseif(!isset($_GET['reminder_job'])) try{$reminderState['source']=ReminderContext::read($db,(string)$_SESSION['actor'],$reminderInvoice,(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d'));}catch(DomainException $failure){$reminderProblem('source',$failure);}
    if(!isset($_GET['reminder_job'])&&!$reminderDraft&&!$reminderState['receipt']&&!isset($_GET['reminder_first'])&&!isset($_GET['reminder_closed'])&&!$reminderExplicitHistory&&$reminderState['errors']===[]&&$reminderState['can_write']){
        $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $reminderDraft=ReminderDraft::open($_SESSION,$reminderActor,$reminderRequests->initial($reminderInvoice,$today),true);
    }
}catch(DomainException $failure){http_response_code(in_array($failure->getCode(),[403,404,409,422,503],true)?$failure->getCode():422);$error=\Kulturhus\Native\Language\InvoiceReminderMessages::text($failure->getMessage(),$reminderMessages);}
catch(Throwable){http_response_code(503);$error=\Kulturhus\Native\Language\InvoiceReminderMessages::text('Erinnerungsbereich konnte nicht geladen werden.',$reminderMessages);}
