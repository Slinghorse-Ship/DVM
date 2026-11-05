<?php namespace ProcessWire;
// Requires an isolated local ProcessWire database. Never run against hosting.
if(getenv('KULTURHUS_LOCAL_DEMO')!=='1'||PHP_SAPI!=='cli')throw new \RuntimeException('Local CLI only');
require '/var/www/html/index.php';
require_once '/var/www/html/site/src/PortalSecurity.php';
$wire=wire();$wire->modules->refresh();$tfa=$wire->modules->get('TfaKulturhusEmail');
if(!$tfa)$tfa=$wire->modules->install('TfaKulturhusEmail');
$n=0;function check($ok,$label){global $n;if(!$ok)throw new \RuntimeException($label);echo 'PASS '.$label."\n";++$n;}
$before=(int)$wire->database->query('SELECT COUNT(*) FROM field_tfa_type WHERE data<>0')->fetchColumn();
$u=$wire->users->add('mfa-check-'.bin2hex(random_bytes(6)));$password=bin2hex(random_bytes(24));$u->email='mfa-test@example.invalid';$u->pass=$password;$u->save();
$code='';$delivery=true;$hook=$tfa->addHookBefore('deliverCode',function($event)use(&$code,&$delivery){$event->replace=true;$code=(string)$event->arguments(1);$event->return=$delivery;});
try{
 $wire->users->setCurrentUser($u);
 $render=function($data=[])use($wire){$_SERVER['REQUEST_METHOD']=$data?'POST':'GET';$wire->input->post->removeAll();if($data){$data[$wire->session->CSRF->getTokenName()]=$wire->session->CSRF->getTokenValue();foreach($data as $k=>$v)$wire->input->post->set($k,$v);$_POST=$data;}return \Kulturhus\PortalSecurity::render($wire);};
 check(str_contains($render(),'Nicht aktiviert'),'disabled by default');
 check(str_contains($render(['security_action'=>'enable','security_password'=>'wrong']),'Passwort stimmt nicht'),'password required');
 $html=$render(['security_action'=>'enable','security_password'=>$password]);if(!str_contains($html,'Code versendet'))echo strip_tags($html);check(str_contains($html,'Code versendet'),'confirmation sent through adapter');
 check(!$tfa->enabledForUser($u,$tfa->getUserSettings($u)),'not enabled before email confirmation');
 check(str_contains($render(['security_action'=>'confirm','security_code'=>'00000000']),'Code stimmt nicht'),'wrong confirmation rejected');
 check(str_contains($render(['security_action'=>'confirm','security_code'=>$code]),'Status: <strong>Aktiv'),'confirmed enrollment activates');
 check($u->get('tfa_type') instanceof TfaKulturhusEmail,'native factor selected');
 check(str_contains($render(['security_action'=>'confirm','security_code'=>$code]),'Code abgelaufen'),'confirmation cannot replay');
 $settings=$tfa->getUserSettings($u);check($tfa->startUser($u,$settings),'native login challenge starts');$loginCode=$code;
 check($tfa->isValidUserCode($u,'00000000',$settings)===false,'wrong login code rejected');
 check($tfa->isValidUserCode($u,$loginCode,$settings)===true,'valid login code accepted');
 check($tfa->isValidUserCode($u,$loginCode,$settings)===false,'login code one use');
 $tfa->startUser($u,$settings);$set=new \ReflectionMethod(Tfa::class,'sessionSet');$set->invoke($tfa,'kh_expires',time()-1);
 check($tfa->isValidUserCode($u,$code,$settings)===0,'expired login challenge rejected');
 $wire->cache->delete('kh-security-attempt-'.$u->id);$delivery=false;
 check(str_contains($render(['security_action'=>'disable','security_password'=>$password]),'Code konnte nicht versendet'),'failed delivery reported');
 check($tfa->enabledForUser($u,$tfa->getUserSettings($u)),'failed delivery keeps protection');
 check(!str_contains($render(),'name="security_code"'),'no confirmation form after delivery failure');
 $delivery=true;$render(['security_action'=>'disable','security_password'=>$password]);
 check($tfa->enabledForUser($u,$tfa->getUserSettings($u)),'disable request alone keeps protection');
 check(str_contains($render(['security_action'=>'confirm','security_code'=>$code]),'Nicht aktiviert'),'confirmed disabling works');
 check(!$u->get('tfa_type'),'native factor removed only after confirmation');
 $wire->cache->delete('kh-security-attempt-'.$u->id);$render(['security_action'=>'enable','security_password'=>$password]);$pending=$wire->session->getFor('kh_account_security','pending');$pending['expires']=time()-1;$wire->session->setFor('kh_account_security','pending',$pending);
 check(str_contains($render(['security_action'=>'confirm','security_code'=>$code]),'Code abgelaufen'),'expired enrollment rejected');
 check((int)$wire->database->query('SELECT COUNT(*) FROM field_tfa_type WHERE data<>0')->fetchColumn()===$before,'existing users not opted in');
 echo "$n account security checks passed\n";
}finally{$tfa->removeHook($hook);$wire->session->removeAllFor('kh_account_security');$wire->cache->delete('kh-security-attempt-'.$u->id);$wire->users->setCurrentUser($wire->users->getGuestUser());$wire->users->delete($u);}
