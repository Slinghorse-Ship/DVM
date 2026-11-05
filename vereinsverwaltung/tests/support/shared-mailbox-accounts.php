<?php declare(strict_types=1);
// Independent synthetic club; no saved original mailbox configuration is replaced.
(static function()use($db,$check,$deny):void{
    $club='own-three-mailboxes';$actor='own-three-mailboxes-admin';$key=random_bytes(32);
    \Kulturhus\Native\Shared\Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?)',[$club,'Own three shared mailboxes']);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'members',1)",[$actor,$club]);
    $boxes=[];$ids=[];
    foreach(\Kulturhus\Verein\Communication\Mailbox::ACCOUNTS as$account=>$label){
        $email=$account.'@example.invalid';$raw="From: sender@example.invalid\r\nTo: $email\r\nSubject: Own $account original\r\nMessage-ID: <$account@example.invalid>\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nExact $account body ä🙂\r\n";
        $reader=static fn(array $c,int $validity,int $last):array=>['uidvalidity'=>71,'messages'=>$last?[]:[['uid'=>1,'original'=>$raw]]];
        $box=new \Kulturhus\Verein\Communication\Mailbox($db,$actor,$key,$reader,static function():never{throw new RuntimeException('No actual send permitted');},static fn():array=>['from'=>$email,'reply_to'=>$email],$account);
        $box->saveSettings(['enabled'=>'1','email'=>$email,'imap_host'=>'imap.example.invalid','imap_user'=>$email,'imap_password'=>'Own synthetic password','folder'=>'INBOX','since'=>'2026-01-01'],0);
        $check($box->fetch()['new']===1&&$box->fetch()['new']===0,'shared '.$account.' retains an independent exact UID cursor');
        $rows=$box->listing()['rows'];$ids[$account]=$rows[0]['id'];$boxes[$account]=$box;
        $check(count($rows)===1&&$box->original($ids[$account])===$raw,'shared '.$account.' exposes only its exact original bytes');
    }
    foreach($boxes as$name=>$box){
        $check($box->listing()['total']===1,'shared '.$name.' listing excludes the other configured accounts');
        foreach($ids as$other=>$id)if($other!==$name){$deny(fn()=>$box->get($id),'shared '.$name.' refuses a crossed '.$other.' original');$deny(fn()=>$box->saveReply($id,bin2hex(random_bytes(16)),0,'Wrong mailbox reply'),'shared '.$name.' refuses a reply to a crossed '.$other.' message');}
    }
    $settings=$boxes['press']->settings();$boxes['press']->saveSettings(['enabled'=>'1','email'=>'press@example.invalid','imap_host'=>'imap.example.invalid','imap_user'=>'press@example.invalid','folder'=>'Archive','since'=>'2026-01-01'],1);
    $check($boxes['press']->get($ids['press'])['envelope']['subject']==='Own press original'&&$boxes['press']->settings()['password_set'],'changed press folder retains its older original and encrypted password');
    $deny(fn()=>$boxes['board']->get($ids['press']),'a retained previous press binding never falls into the board mailbox');
    $deny(fn()=>\Kulturhus\Verein\Communication\MailboxForm::command(['view'=>'messages','tab'=>'inbox','mailbox_account'=>'press'],['action'=>'mailbox-fetch','csrf'=>'own','mailbox_account'=>'invoices','mailbox_state'=>'active','mailbox_assigned'=>'all','mailbox_page'=>'1']),'crossed full original mailbox selection is rejected before any fetch');
}) ();
