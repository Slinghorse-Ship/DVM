<?php declare(strict_types=1);
/** Whole intake-to-invoice regression. No network transport or production database. */
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,DocumentCatalog,PublicMembershipForm,MembershipApplications,ApplicationConfirmation,ApplicationNotifications,AdmissionVotes,Settings,SettingsSchema,Finance,MembershipAccountProvisioning,MembershipWelcome,Permissions,ApplicationMail};

final class RegistrationTestAccounts
{
    public array $users=[];
    public function account(int $id):?array{return $this->users[$id]??null;}
    public function findByEmail(string $email):array{return array_values(array_filter($this->users,static fn($u)=>strcasecmp($email,$u['email'])===0));}
    public function createMemberAccount(string $username,string $email,string $password,string $key):array
    {
        foreach($this->users as $u)if($u['username']===$username){if($u['key']!==$key)throw new DomainException('Test collision');return $u;}
        $id=900001+count($this->users);return $this->users[$id]=['id'=>$id,'username'=>$username,'email'=>$email,'password'=>$password,'key'=>$key,'active'=>true];
    }
    public function changePassword(int $id,string $password):void{$this->users[$id]['password']=$password;}
}
$n=0;
function checked(bool $value,string $label):void{global $n;if(!$value)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('flow-test','TEST – keine Zahlungsforderung'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('flow-manager','flow-test','members',1); INSERT INTO actor_capabilities VALUES('flow-manager','local_finance')");
$board=new AdmissionVotes($db,'flow-manager');
foreach(AdmissionVotes::OFFICES as $i=>$office){
    $db->prepare("INSERT INTO contacts(id,club_id,kind,name,email) VALUES(?,'flow-test','person',?,?)")->execute(['board-'.$i,'Fiktiver Vorstand '.$i,'board-'.$i.'@example.invalid']);
    $db->prepare("INSERT INTO actors(id,club_id,role,contact_id) VALUES(?,'flow-test','member',?)")->execute(['board-'.$i,'board-'.$i]);
    $board->grant('board-'.$i,0,$office,true,true);
}
$settings=new Settings($db,'flow-manager');
$settings->save('club',array_replace(SettingsSchema::defaults('club'),['name'=>'TEST – keine Zahlungsforderung','street'=>'Beispielweg 1','postcode'=>'00000','city'=>'Fiktiver Ort']),0);
$account=(new Finance($db,'flow-manager'))->createAccount('Fiktives Testkonto','offline','0','2026-01-01');
$settings->save('bank',array_replace(SettingsSchema::defaults('bank'),['account_id'=>$account,'holder'=>'TEST – NICHT BEZAHLEN','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX']),0);
$texts=SettingsSchema::defaults('texts_contributions');
$texts['invoice']['message']='FUNKTIONSTEST – NICHT BEZAHLEN. Diese Rechnung ist ausschließlich ein Prüfmuster für {{ZAHLUNG_FUER}} über {{ZAHLBETRAG}} und keine Zahlungsforderung.';
$texts['invoice']['intro']='FUNKTIONSTEST: Die folgende Position dient nur der Ablaufprüfung.';
$texts['invoice']['closing']='TEST – NICHT BEZAHLEN. Keine Zahlungsforderung. Die Bankangaben sind reine Beispieldaten.';
$settings->save('texts_contributions',$texts,0);
$intake=new MembershipApplications($db,'flow-test','flow-manager');
$cfg=$intake->settings()['config'];$cfg['enabled']=true;$intake->saveSettings($cfg,0);
$form=['first_name'=>'Fiktiver','last_name'=>'Ablauftest','birthday'=>'1990-01-02','email'=>'registration@example.invalid','address'=>"Beispielweg 1, 00000 Fiktiver Ort",'membership_type'=>'single','consent'=>'1','newsletter'=>'','csrf'=>'flow-csrf','request_key'=>bin2hex(random_bytes(16)),'configuration_version'=>'1'];
$invalid=$form;$invalid['email']='invalid';$state=PublicMembershipForm::handle($intake,$invalid,'flow-csrf',true);
checked(!$state['success']&&$state['values']['email']==='invalid','validation retains entered email without creating application');
$state=PublicMembershipForm::handle($intake,$form,'flow-csrf',true);
checked($state['success'],'public form accepts valid synthetic request');
PublicMembershipForm::handle($intake,$form,'flow-csrf',true);
checked((int)$db->query('SELECT COUNT(*) FROM application_confirmations')->fetchColumn()===1,'repeat submit creates one confirmation');
checked((int)$db->query('SELECT COUNT(*) FROM application_notifications')->fetchColumn()===0,'board is not notified before confirmation');
$messages=[];$boardMessages=[];
$capture=static function($email,$subject,$text,$html=null,$attachments=[])use(&$messages):void{$messages[]=['email'=>$email,'subject'=>$subject,'text'=>$text,'html'=>$html??ApplicationMail::html($text,'https://registration-audit.example.invalid',$subject),'attachments'=>$attachments];};
putenv('VEREIN_MODE=synthetic');
checked(ApplicationConfirmation::dispatch($db,'flow-test','https://registration-audit.example.invalid/mitglied-werden',$capture)===0,'synthetic environment blocks dispatch');
// Only in-memory records and recording callbacks; never initialize a real mail transport here.
foreach(['VEREIN_MODE','KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','APP_ENV'] as $key)putenv($key);
putenv('APP_URL=https://registration-audit.example.invalid');
checked(ApplicationConfirmation::dispatch($db,'flow-test','https://registration-audit.example.invalid/mitglied-werden',$capture)===1,'confirmation message produced exactly once');
checked(ApplicationConfirmation::dispatch($db,'flow-test','https://registration-audit.example.invalid/mitglied-werden',$capture)===0,'repeat worker does not duplicate confirmation');
preg_match('/confirm_application=([a-f0-9]{64})/',$messages[0]['text'],$matches);
checked(isset($matches[1]),'actual confirmation message contains a usable token');
$post=['action'=>'confirm-application','csrf'=>'flow-csrf','confirmation_token'=>$matches[1]];
checked(PublicMembershipForm::handle($intake,$post,'flow-csrf',true)['success'],'confirmation from the generated message creates a pending application');
PublicMembershipForm::handle($intake,$post,'flow-csrf',true);
checked((int)$db->query('SELECT COUNT(*) FROM membership_applications')->fetchColumn()===1,'repeated confirmation remains idempotent');
$id=$db->query('SELECT id FROM membership_applications')->fetchColumn();
checked($db->query('SELECT newsletter_status FROM application_confirmations')->fetchColumn()==='skipped','no newsletter subscription without opt-in');
checked(ApplicationNotifications::dispatch($db,'flow-test','https://registration-audit.example.invalid/vereinsverwaltung',static function($to,$subject,$text)use(&$boardMessages){$boardMessages[]=compact('to','subject','text');})===4,'four synthetic board notifications captured separately from applicant mail');
$board->open($id,true);
foreach(AdmissionVotes::OFFICES as $i=>$office)(new AdmissionVotes($db,'board-'.$i))->vote($id,'yes','',true);
checked(AdmissionVotes::result($db,'flow-test',$id)['result']==='accepted','all four synthetic board votes produce acceptance');
$contact=$intake->review($id,1,'accepted','Fiktiver Funktionstest',start:date('Y-m-d'),familyTarget:'single');
$membership=DocumentCatalog::query($db,'SELECT * FROM memberships WHERE contact_id=?',[$contact])->fetch();
checked($membership&&$membership['status']==='aktiv'&&$membership['member_number']!=='','accepted applicant has an active numbered membership');
$accounts=new RegistrationTestAccounts();$provisioning=new MembershipAccountProvisioning($db,'flow-test',$accounts,str_repeat('isolated-test-key-',3));
checked(MembershipWelcome::dispatch($db,'flow-test','https://registration-audit.example.invalid',$capture,$provisioning->provision(...),$provisioning->acknowledgeDelivery(...))===2,'welcome and invoice messages follow acceptance');
checked(count($accounts->users)===1,'one shared website identity is provisioned');
$user=array_values($accounts->users)[0];$actor=Permissions::actor($db,'pw:'.$user['id']);
checked($actor['contact_id']===$contact&&$actor['role']==='member'&&$actor['finance_access']==='none'&&!$actor['settings_access'],'shared account maps to own member without administrative access');
checked($provisioning->requiresPasswordChange($user['id']),'first-login password change is required');
$provisioning->changePassword($user['id'],'Only-a-synthetic-new-password-1!','Only-a-synthetic-new-password-1!');
checked(!$provisioning->requiresPasswordChange($user['id']),'synthetic first-login completion releases the onboarding requirement');
checked(MembershipWelcome::dispatch($db,'flow-test','https://registration-audit.example.invalid',$capture,$provisioning->provision(...),$provisioning->acknowledgeDelivery(...))===0&&count($messages)===3,'repeat worker produces no second welcome or invoice');
$welcome=DocumentCatalog::query($db,'SELECT * FROM membership_welcome WHERE application_id=?',[$id])->fetch();
$entry=DocumentCatalog::query($db,'SELECT * FROM finance_entries WHERE id=?',[$welcome['entry_id']])->fetch();
$invoice=DocumentCatalog::query($db,'SELECT * FROM contribution_invoices WHERE entry_id=?',[$entry['id']])->fetch();
$file=DocumentCatalog::query($db,'SELECT * FROM document_files WHERE document_id=? ORDER BY revision DESC LIMIT 1',[$invoice['document_id']])->fetch();
checked($entry['kind']==='contribution'&&(int)$entry['amount_cents']===4800&&$invoice['recipient_contact_id']===$contact,'48 EUR contribution and invoice are linked to the accepted member');
checked(count($messages[2]['attachments'])===1&&$messages[2]['attachments'][0]['bytes']===$file['bytes']&&str_starts_with($file['bytes'],'%PDF-'),'invoice message carries exactly the archived PDF');
checked($welcome['welcome_status']==='sent'&&$welcome['invoice_status']==='sent','saved dispatch states survive a repeat worker run');
checked(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)===[],'complete chain retains relational integrity');
if($target=getenv('VEREIN_REGISTRATION_AUDIT_BUNDLE')){
    foreach($messages as &$message){foreach($message['attachments'] as &$attachment){$attachment['base64']=base64_encode($attachment['bytes']);unset($attachment['bytes']);}unset($attachment);}unset($message);
    file_put_contents($target,json_encode(['kind'=>'isolated-registration-audit','created_at'=>gmdate('c'),'checks'=>$n,'production_records'=>0,'messages'=>$messages,'board_messages_captured_only'=>count($boardMessages)],JSON_THROW_ON_ERROR));
}
echo "$n complete registration workflow checks passed; in-memory data and recording callbacks only\n";
