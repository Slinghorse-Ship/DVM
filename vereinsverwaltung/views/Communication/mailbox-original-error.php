<?php declare(strict_types=1);
$mailboxRecoveryText=static fn(string $source):string=>\Kulturhus\Native\Language\MailboxMessages::text($source,$managerLanguageMessages);
$mailboxRecoveryRoute=['view'=>'messages','tab'=>'inbox'];$mailboxOriginalId=$_POST['mail']??null;$mailboxOriginalReply=$_POST['reply_id']??null;
$mailboxHasOriginal=is_string($mailboxOriginalId)&&preg_match('/\A[a-f0-9]{32}\z/D',$mailboxOriginalId);
if($mailboxHasOriginal)$mailboxRecoveryRoute['mail']=$mailboxOriginalId;
?>
<section class="content" data-mailbox-workspace data-mailbox-rejected-context><link rel="stylesheet" href="mailbox-workflows.css"><h1><?=e($mailboxRecoveryText('Postfachauswahl prüfen'))?></h1>
<?php if(is_string($_POST['reply_text']??null)&&strlen($_POST['reply_text'])<=80000): ?><label class="mailbox-failed-reply"><?=e($mailboxRecoveryText('Dein Antworttext'))?><textarea class="form-control" rows="3" readonly><?=e($_POST['reply_text'])?></textarea></label><?php endif?>
<a class="btn btn-outline" rel="noopener" href="<?=e(url($mailboxRecoveryRoute))?>"><?=e($mailboxRecoveryText($mailboxHasOriginal?'Ursprüngliche Nachricht öffnen':'Postfach öffnen'))?></a>
<?php if($mailboxHasOriginal&&is_string($mailboxOriginalReply)&&preg_match('/\A[a-f0-9]{32}\z/D',$mailboxOriginalReply)):?><a class="btn btn-outline" rel="noopener" href="<?=e(url($mailboxRecoveryRoute+['reply'=>$mailboxOriginalReply]))?>"><?=e($mailboxRecoveryText('Ursprüngliche Antwort öffnen'))?></a><?php endif?>
</section>
