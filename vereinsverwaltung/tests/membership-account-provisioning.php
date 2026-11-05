<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,MembershipAccountProvisioning,Permissions,BoardAccess};
final class MemoryWebsiteAccounts
{
    public array $users=[];public int $created=0;public bool $loseReply=false;
    public function account(int $id):?array{return $this->users[$id]??null;}
    public function findByEmail(string $email):array{return array_values(array_filter($this->users,fn($u)=>strcasecmp($u['email'],$email)===0));}
    public function createMemberAccount(string $username,string $email,string $password,string $key):array
    {
        foreach($this->users as $u)if($u['username']===$username){if($u['key']!==$key||!$u['active'])throw new DomainException('Account collision');return $u;}
        if($this->findByEmail($email))throw new DomainException('Email collision');
        $id=100+(++$this->created);$this->users[$id]=['id'=>$id,'username'=>$username,'email'=>$email,'password'=>$password,'key'=>$key,'active'=>true];
        if($this->loseReply){$this->loseReply=false;throw new DomainException('Saved website account; reply lost');}return $this->users[$id];
    }
    public function changePassword(int $id,string $password):void{if($this->users[$id]['password']===$password)throw new DomainException('Same password');$this->users[$id]['password']=$password;}
}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Test');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('root','a','members',1)");
$accounts=new MemoryWebsiteAccounts;$service=new MembershipAccountProvisioning($db,'a',$accounts,str_repeat('synthetic-server-key-',3));
function fixture(PDO $db,string $id,string $state='accepted'):void{
    $db->prepare("INSERT INTO contacts(id,club_id,kind,name,email) VALUES(?,'a','person',?,?)")->execute([$id,'Synthetic '.$id,$id.'@example.invalid']);
    $db->prepare("INSERT INTO memberships(id,club_id,contact_id,member_number,status,starts_on) VALUES(?,'a',?,?,'aktiv','2026-09-05')")->execute(['m'.$id,$id,$id]);
    $db->prepare("INSERT INTO membership_applications(id,club_id,request_key,status,answers,configuration,consent_snapshot,configuration_version,submitted_at,reviewed_by,contact_id) VALUES(?,'a',?,?,'{}','{}','{}',1,'2026-09-05','root',?)")->execute(['app'.$id,$id,$state,$id]);
}
$n=0;function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $f,string $label):void{try{$f();}catch(DomainException){ok(true,$label);return;}throw new RuntimeException($label);}
fixture($db,'pending','pending');fixture($db,'rejected','rejected');
denied(fn()=>$service->provision('apppending','pending'),'pending application cannot create an account');
denied(fn()=>$service->provision('apprejected','rejected'),'rejected application cannot create an account');
ok($accounts->created===0,'no account before board acceptance');
fixture($db,'new');$result=$service->provision('appnew','new');$userId=$result['account_id'];
ok($result['created']&&$result['password_change_required']&&strlen($result['initial_password'])>=24,'new accepted member receives a strong temporary password and change requirement');
$actor=Permissions::actor($db,'pw:'.$userId);
ok($actor['role']==='member'&&$actor['contact_id']==='new'&&$actor['members_access']==='none'&&$actor['finance_access']==='none'&&!$actor['settings_access']&&!BoardAccess::superuser($db,$actor['id']),'new identity grants only personal member access');
ok($db->query('SELECT COUNT(*) FROM admission_board')->fetchColumn()===0,'provisioning grants no board office');
$stored=$db->query("SELECT password_cipher FROM membership_account_onboarding WHERE application_id='appnew'")->fetchColumn();
ok($stored!==$result['initial_password']&&!str_contains($stored,$result['initial_password']),'temporary password is encrypted in database');
$repeat=$service->provision('appnew','new');ok($repeat===$result&&$accounts->created===1,'retry returns the same account and temporary password without resetting it');
$db->exec("UPDATE processwire_identities SET enabled=0 WHERE pw_user_id=$userId");
denied(fn()=>$service->provision('appnew','new'),'revoked binding is not silently re-enabled');
$db->exec("UPDATE processwire_identities SET enabled=1 WHERE pw_user_id=$userId");
$accounts->users[$userId]['active']=false;denied(fn()=>$service->provision('appnew','new'),'inactive website account blocks delivery');$accounts->users[$userId]['active']=true;
$service->acknowledgeDelivery('appnew');$repeat=$service->provision('appnew','new');
ok(!isset($repeat['initial_password'])&&$service->requiresPasswordChange($userId),'delivery acknowledgement erases escrow but retains first-login requirement');
denied(fn()=>$service->changePassword($userId,'short','short'),'short replacement password rejected');
denied(fn()=>$service->changePassword($userId,'Longer Password Test','different'),'password confirmation must match');
denied(fn()=>$service->changePassword($userId,$result['initial_password'],$result['initial_password']),'temporary password cannot be reused as permanent password');
$service->changePassword($userId,'Synthetic replacement 1!','Synthetic replacement 1!');
ok(!$service->requiresPasswordChange($userId)&&$accounts->users[$userId]['password']==='Synthetic replacement 1!','successful password change unlocks the account');
denied(fn()=>$service->changePassword($userId,'Other Password 123!','Other Password 123!'),'first-login endpoint cannot reset an already changed password');
fixture($db,'existing');$accounts->users[501]=['id'=>501,'username'=>'existing-account','email'=>'existing@example.invalid','password'=>'existing-password-unchanged','key'=>'','active'=>true];
$db->exec("INSERT INTO actors(id,club_id,role,contact_id,is_superuser) VALUES('pw:501','a','member','existing',1);INSERT INTO processwire_identities VALUES(501,'pw:501',1,1)");
$existing=$service->provision('appexisting','existing');
ok($existing['username']==='existing-account'&&!$existing['created']&&!isset($existing['initial_password'])&&$accounts->users[501]['password']==='existing-password-unchanged','explicitly profile-bound existing account keeps its password');
ok(BoardAccess::superuser($db,'pw:501'),'existing role is preserved without a new grant');
fixture($db,'collision');$accounts->users[502]=['id'=>502,'username'=>'unrelated-account','email'=>'collision@example.invalid','password'=>'untouched','key'=>'','active'=>true];
denied(fn()=>$service->provision('appcollision','collision'),'email alone cannot claim an existing website account');
ok(!$db->query("SELECT 1 FROM actors WHERE contact_id='collision'")->fetchColumn()&&$accounts->users[502]['password']==='untouched','email collision neither binds nor resets the other account');
fixture($db,'lost');$accounts->loseReply=true;
denied(fn()=>$service->provision('applost','lost'),'lost website reply leaves a retryable durable intent');
$beforeCount=$accounts->created;$recovered=$service->provision('applost','lost');
ok($accounts->created===$beforeCount&&$recovered['created']&&isset($recovered['initial_password']),'retry recovers the previously created account without creating a duplicate');
denied(fn()=>(new MembershipAccountProvisioning($db,'a',$accounts,str_repeat('different-server-key-',3)))->provision('applost','lost'),'wrong server key cannot recover a temporary password');
$db->exec("UPDATE memberships SET status='ehemalig',ends_on='2026-09-05' WHERE contact_id='lost'");
denied(fn()=>$service->provision('applost','lost'),'ended membership cannot trigger another account delivery');
denied(fn()=>$service->provision('appnew','existing'),'application and contact binding cannot be swapped');
ok(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'database integrity remains intact');
echo "$n membership account checks passed\n";
