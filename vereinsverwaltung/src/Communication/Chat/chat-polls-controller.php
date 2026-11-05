<?php declare(strict_types=1);
// Included only inside the authenticated, CSRF-checked POST handler.
$pollAction=input($_POST,'action');
if(in_array($pollAction,['chat-poll-create','chat-poll-vote','chat-poll-close'],true)){
    $pollChat=input($_POST,'chat');$polls=new \Kulturhus\Verein\ChatPolls($db,(string)$_SESSION['actor']);
    if($pollAction==='chat-poll-create'){
        $polls->create($pollChat,input($_POST,'question'),arrayInput($_POST,'poll_options'),input($_POST,'closes_on'),input($_POST,'request_key'));
        \Kulturhus\Verein\WebPushDelivery::kick();$_SESSION['notice']='Die Umfrage ist in der Unterhaltung verfügbar.';
    }elseif($pollAction==='chat-poll-vote'){
        $polls->vote($pollChat,input($_POST,'poll'),(int)input($_POST,'poll_option'),(int)input($_POST,'vote_version'),input($_POST,'request_key'));
        $_SESSION['notice']='Deine Antwort wurde gespeichert.';
    }else{
        $polls->close($pollChat,input($_POST,'poll'),(int)input($_POST,'poll_version'));$_SESSION['notice']='Die Umfrage ist beendet. Das Ergebnis bleibt sichtbar.';
    }
    redirectTo(['view'=>'chat','chat'=>$pollChat]);
}
