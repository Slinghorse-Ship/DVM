<?php declare(strict_types=1);
use Kulturhus\Verein\{Finance,OutgoingInvoices,Documents};
use Kulturhus\Verein\Finance\Invoices\{OutgoingMailInput,OutgoingMailMessage};
use Kulturhus\Native\Delivery\MailMessage;

/** Actual issued credit and cancellation documents remain neutral mail attachments. */
function ownOutgoingMailCorrections(PDO $db,callable $check,array $fixture,array $sources):void
{
    $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();if(!preg_match('/\Akh_(?:native|test)_[a-f0-9]{24}\z/D',$name))throw new RuntimeException('Own actual invoice corrections required.');
    $service=$fixture['service'];$requests=$fixture['requests'];$finance=new Finance($db,$fixture['actor']);$documents=new Documents($db,$fixture['actor']);$parentBefore=$service->get($fixture['id']);$entryBefore=$finance->entry($parentBefore['entry_id']);$fileBefore=$documents->file($parentBefore['document_id'],1);$positionsBefore=$finance->positions('entry',$parentBefore['entry_id']);
    $credit=$service->correct($fixture['id'],(int)$parentBefore['version'],'credit','50.00','Own actual credit for the received paid share','2025-12-06',bin2hex(random_bytes(16)));
    $check($service->get($fixture['id'])===$parentBefore&&$finance->entry($parentBefore['entry_id'])===$entryBefore&&$documents->file($parentBefore['document_id'],1)===$fileBefore&&$finance->positions('entry',$parentBefore['entry_id'])===$positionsBefore,'actual fifty-euro issued credit for an actually paid share leaves the original invoice complete entry positions and original PDF exact');
    $unpaid=$service->get($sources['id']);$cancel=$service->correct($sources['id'],(int)$unpaid['version'],'cancellation','0.00','Own actual complete cancellation of the unpaid original','2025-12-06',bin2hex(random_bytes(16)));$cancelled=$service->get($sources['id']);$cancelledEntry=$finance->entry($unpaid['entry_id']);
    $check($cancelled['state']==='cancelled'&&$cancelledEntry['status']==='cancelled'&&$cancelled['document_id']===$unpaid['document_id']&&$documents->file($unpaid['document_id'],1)['sha256']===$sources['first']['result']['document_sha256'],'actual complete cancellation marks its original demand cancelled and retains the exact earlier original PDF');
    $firsts=[];
    foreach(['credit'=>$credit,'cancellation'=>$cancel]as$kind=>$id){
        $context=$requests->context($id);$input=OutgoingMailInput::initial($context);$review=$requests->prepare($input);$file=$documents->file($context['invoice']['document_id'],1);$snapshot=json_decode($file['snapshot'],true,64,JSON_THROW_ON_ERROR);
        $check($context['invoice']['kind']===$kind&&$context['payment']===null&&$context['source']['default_source']==='neutral-original-document'&&($snapshot['einvoice']['kosit']['state']??null)==='valid'&&($snapshot['einvoice']['validation']['state']??null)==='valid'&&$snapshot['payment_reference']===''&&!str_contains($review['message']['message'],'Verwendungszweck bei Überweisung')&&!str_contains($review['message']['message'],'Offener Betrag:'),'actual issued '.$kind.' uses its genuinely validated original hybrid PDF and a neutral document message without another payment request');
        $first=$requests->accept($input,bin2hex(random_bytes(16)),$review['hash'],true);$stored=OutgoingMailMessage::open($first['result']);$check($stored['payment_open_cents']===''&&$requests->receipt($first['key'])===$first&&$first['result']['document_sha256']===$file['sha256'],'actual '.$kind.' mail approval retains exact private first JSON and its own unchanged original attachment');$firsts[$id]=$first;
    }
    $captured=[];$sent=OutgoingInvoices::dispatch($db,$fixture['origin'],static function($to,$subject,$text,$html,$attachments)use(&$captured):void{MailMessage::compose(['from'=>'own-correction@example.invalid','reply_to'=>'own-correction@example.invalid'],['to'=>$to,'subject'=>$subject,'text'=>$text,'html'=>$html,'attachments'=>$attachments])->toString();$captured[]=hash('sha256',$attachments[0]['bytes']);},'own-list-club');
    $wanted=array_column(array_column(array_values($firsts),'result'),'document_sha256');sort($wanted);sort($captured);$check($sent===2&&$captured===$wanted,'actual worker composes both distinct issued correction PDFs once through real MIME with capturing callbacks and no SMTP');
    foreach($firsts as$first)if($requests->receipt($first['key'])!==$first)throw new RuntimeException('Actual correction delivery changed its first private original.');$check(true,'actual correction delivery retains each exact first private confirmation after completion');
}
