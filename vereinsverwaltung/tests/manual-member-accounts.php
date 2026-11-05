<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Registry,ManualMemberAccounts,DocumentCatalog};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic');INSERT INTO actors(id,club_id,role) VALUES('manager','a','members'),('reader','a','member')");
$registry=new Registry($db,'manager');
$accounts=new class {
 public array $rows=[];public int $created=0;public array $passwords=[];
 public function account(int $id):?array{return $this->rows[$id]??null;}
 public function createMemberAccount(string $username,string $email,string $password,string $key,array $allowedSharedUsers=[]):array{
  foreach($this->rows as $r)if(($r['key']??'')===$key){if(!$r['active'])throw new DomainException('inactive');return $r;}
  $base=$username;$suffix=1;while(in_array($username,array_column($this->rows,'username'),true))$username=$base.(++$suffix);
  foreach($this->rows as $r)if(strcasecmp($r['email'],$email)===0&&!in_array($r['id'],$allowedSharedUsers,true))throw new DomainException('existing email');
  $this->created++;$this->passwords[]=$password;$id=100+$this->created;
  return $this->rows[$id]=['id'=>$id,'username'=>$username,'email'=>$email,'active'=>true,'key'=>$key];
 }
};
putenv('APP_URL=https://www.kulturhus.de');putenv('APP_ENV=production');putenv('VEREIN_MODE=production');putenv('KULTURHUS_LOCAL_DEMO=0');putenv('KULTURHUS_DISABLE_MAIL=0');
$mail=[];$send=static function(...$args)use(&$mail){$mail[]=$args;};
$new=static function(string $email,bool $sendMail)use($registry){$id=$registry->saveContact(null,0,'person','Synthetic Member',$email,'Testweg 1');$registry->addMembership($id,'','aktiv','2026-01-01',$sendMail);return $id;};
$silent=$new('silent@example.test',false);$r=ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);
check($r['ready']===1&&$r['sent']===0&&count($mail)===0,'silent member gets account without email');
$status=ManualMemberAccounts::status($db,'manager',$silent);check($status['state']==='ready'&&$status['mail_status']==='disabled','silent status visible');
check(strlen($accounts->passwords[0])>=64,'new account has strong random password');
$row=DocumentCatalog::query($db,'SELECT * FROM manual_member_accounts WHERE contact_id=?',[$silent])->fetch();check(!str_contains(json_encode($row),$accounts->passwords[0]),'password not retained in queue');
ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check($accounts->created===1,'worker repetition does not create another account');
ManualMemberAccounts::request($db,'manager',$silent,true);$r=ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);
check($r['sent']===1&&$accounts->created===1,'later explicit mail choice uses same account');
check(str_contains($mail[0][2],'/login/?forgot=1')&&str_contains($mail[0][2],$status['username'])&&!str_contains($mail[0][2],$accounts->passwords[0]),'mail carries username and reset entry, never password');
ManualMemberAccounts::request($db,'manager',$silent,true);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check(count($mail)===1,'repeat choice cannot resend delivered mail');
$notify=$new('notify@example.test',true);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',null);check(ManualMemberAccounts::status($db,'manager',$notify)['state']==='ready'&&count($mail)===1,'account creation works without SMTP');
ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check(count($mail)===2,'pending mail sent once SMTP is available');
$accounts->rows[999]=['id'=>999,'username'=>'existing','email'=>'existing@example.test','active'=>true];$collision=$new('existing@example.test',true);$count=$accounts->created;ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);
check(ManualMemberAccounts::status($db,'manager',$collision)['state']==='needs_review'&&$accounts->created===$count&&count($mail)===2,'same email does not silently adopt unrelated account');
$db->prepare("INSERT INTO actors(id,club_id,role,contact_id) VALUES('pw:999','a','member',?)")->execute([$collision]);$db->exec("INSERT INTO processwire_identities VALUES(999,'pw:999',1,1)");
ManualMemberAccounts::request($db,'manager',$collision,true);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check(ManualMemberAccounts::status($db,'manager',$collision)['username']==='existing'&&$accounts->created===$count,'explicit existing binding reused without resetting password');
$noAddress=$new('',false);check(ManualMemberAccounts::status($db,'manager',$noAddress)['state']==='needs_address','missing email stays visible, member still saved');
$uncertain=$new('uncertain@example.test',true);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',static function(){throw new RuntimeException('uncertain transport');});
check(ManualMemberAccounts::status($db,'manager',$uncertain)['mail_status']==='uncertain','ambiguous SMTP result retained');
$before=count($mail);ManualMemberAccounts::request($db,'manager',$uncertain,true);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check(count($mail)===$before,'ambiguous sends never automatically replay');
$left=$new('left@example.test',true);$m=$registry->memberships($left)[0];$registry->endMembership($m['id'],1,'2026-08-01');$before=$accounts->created;ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check($accounts->created===$before,'ended member does not receive account');
denied(fn()=>ManualMemberAccounts::request($db,'reader',$silent,true),'member cannot request other identities');
$locked=(int)ManualMemberAccounts::status($db,'manager',$silent)['pw_user_id'];$db->prepare('UPDATE processwire_identities SET enabled=0 WHERE pw_user_id=?')->execute([$locked]);ManualMemberAccounts::request($db,'manager',$silent,false);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send);check(ManualMemberAccounts::status($db,'manager',$silent)['state']==='needs_review','disabled identity never reactivated');
check(!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'queue foreign keys valid');
echo "$checks manual account checks passed; no real account or mail\n";

$familyPayer=$new('household@example.test',false);ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send,$familyPayer);$registry->assignFamily($familyPayer,'','new','Household');$family=$registry->family($familyPayer);
$relative=$registry->saveContact(null,0,'person','Second Member','household@example.test','');$registry->addMembership($relative,'','aktiv','2026-01-01',false,['expected'=>'','target'=>$family['id']]);$result=ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send,$relative);
check($result['ready']===1,'explicit family permits second account at shared email');$payerAccount=ManualMemberAccounts::status($db,'manager',$familyPayer);$relativeAccount=ManualMemberAccounts::status($db,'manager',$relative);check($payerAccount['pw_user_id']!==$relativeAccount['pw_user_id'],'family retains separate account identities');
$unrelated=$new('household@example.test',false);$result=ManualMemberAccounts::run($db,'a',$accounts,'https://www.kulturhus.de',$send,$unrelated);check($result['blocked']===1,'unrelated shared email still requires review');
echo "$checks total manual account checks passed\n";
