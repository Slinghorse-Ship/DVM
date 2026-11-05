<?php declare(strict_types=1);
use Kulturhus\Verein\Web\{MailingForm,MailingPage};

$mailingsHandled=false;$mailingPageState=null;
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||(!MailingForm::marked($_POST)&&!MailingPage::applies($_GET)))return;
$mailingsHandled=true;$mailingFailure='';$mailingStatus=200;
try{
    if(!$workspaceAllowed)throw new DomainException('Dieser Bereich ist nur für den aktuellen Vorstand und Superuser freigegeben.',403);
    try{\Kulturhus\Verein\BoardAccess::require($db,(string)$_SESSION['actor']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),403,$failure);}
    if(!is_string($_POST['csrf']??null)||!is_string($_SESSION['csrf']??null)||$_SESSION['csrf']===''||!hash_equals($_SESSION['csrf'],$_POST['csrf']))throw new DomainException('Formular abgelaufen. Bitte neu laden.',403);
    if(($_POST['mailing_recheck']??null)==='1'){MailingForm::metadata($_GET,$_POST);}
    else{
        $command=MailingForm::command($_GET,$_POST);$mailingService=new \Kulturhus\Verein\ClubMailings($db,(string)$_SESSION['actor']);
        switch($command['action']){
            case'mailing-save':$saved=$mailingService->save($command['id'],$command['subject'],$command['body'],$command['people'],$command['key'],$command['fields'],$command['attachments']);redirectTo(['view'=>'messages','mailing'=>$saved]);
            case'mailing-queue':$mailingService->queue($command['id'],$command['hash']);redirectTo(['view'=>'messages','mailing'=>$command['id']]);
            case'mailing-cancel':$mailingService->cancel($command['id'],$command['hash']);redirectTo(['view'=>'messages','mailing'=>$command['id']]);
            case'mailing-print':$document=$mailingService->letters($command['id'],$command['hash'],$command['key']);redirectTo(['view'=>'documents','doc'=>$document]);
        }
    }
}catch(DomainException $failure){$mailingStatus=in_array($failure->getCode(),[403,404,409,422],true)?$failure->getCode():422;$mailingFailure=$failure->getMessage();}
catch(Throwable){$mailingStatus=503;$mailingFailure='Das Ergebnis konnte nicht bestätigt werden. Vor einem weiteren Vorgang den gespeicherten Stand prüfen.';}
$mailingPageState=MailingPage::prepare($db,(string)$_SESSION['actor'],$_GET,$_POST,$mailingFailure,$mailingStatus);
if($mailingPageState['status']>=400)http_response_code($mailingPageState['status']);
