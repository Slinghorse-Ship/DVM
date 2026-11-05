<?php declare(strict_types=1);
require __DIR__.'/membership-welcome.php';
$previous=$n;
foreach([false,true] as $sendChoice){
 $input=array_replace($base,['last_name'=>$sendChoice?'Manual Notify':'Manual Silent','email'=>$sendChoice?'manual-notify@example.invalid':'manual-silent@example.invalid']);
 $manual=$intake->submit($input,bin2hex(random_bytes(16)),1,true,true,false,false,$sendChoice);
 $manualContact=$accept($manual);
 $job=\Kulturhus\Verein\DocumentCatalog::query($db,'SELECT * FROM manual_member_accounts WHERE club_id=? AND contact_id=?',['a',$manualContact])->fetch();
 ok((bool)$job['send_mail']===$sendChoice&&$job['application_id']===$manual,'manual application preserves email choice through admission');
 ok($welcome($manual)['welcome_status']==='manual_access','manual admission never enters initial-password mailing path');
 ok($job['state']==='prepared'&&$job['requested_by']==='clerk','board-scoped clerk can queue own accepted member');
}
echo ($n-$previous)." manual admission checks passed\n";
