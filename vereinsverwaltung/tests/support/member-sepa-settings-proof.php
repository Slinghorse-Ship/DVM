<?php declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/autoload.php';
/** Private loopback-only adapter for real competing saves and real SQL failure. */
function ownMemberSepaSettingsProof(array $fixture):void
{
 $cfg=\Kulturhus\Native\TestSupport::config();$db=\Kulturhus\Native\TestSupport::db();
 if(PHP_SAPI!=='cli-server'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-member-sepa-settings'||!preg_match('/\Akh_native_[a-f0-9]{24}\z/D',$cfg['database']??'')||$db->query('SELECT DATABASE()')->fetchColumn()!==$cfg['database'])throw new RuntimeException('Owned SEPA settings proof required.');
 $token=$_SERVER['HTTP_X_OWNED_SEPA_SETTINGS_PROOF']??null;if(!is_string($token)||!hash_equals($fixture['data']['proof_token'],$token)){http_response_code(403);exit;}
 $service=new \Kulturhus\Verein\MemberBankSettings($db,'own-list-admin');$operation=$_POST['operation']??'state';
 if($operation==='correct'){
  $tag=$_POST['tag']??null;$known=array_column($fixture['data']['cases'],'tag');if(!is_string($tag)||!in_array($tag,$known,true))throw new RuntimeException('Exact owned setup case required.');
  $current=$service->get();$service->save($current['version'],['creditor_id'=>$current['creditor_id'],'creditor_name'=>'0 Later human correction '.$tag,'creditor_address'=>$current['creditor_address'],'sepa_enabled'=>(int)$current['sepa_enabled']===1?'1':'']);
 }elseif($operation==='fault-on'){
  $db->exec("CREATE TRIGGER own_member_sepa_browser_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='member-bank.configured' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned actual SEPA browser audit failure'; END IF; END");
 }elseif($operation==='fault-off'){$db->exec('DROP TRIGGER IF EXISTS own_member_sepa_browser_failure');}
 elseif($operation!=='state')throw new RuntimeException('Exact owned proof operation required.');
 $state=$service->get();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');echo json_encode(['state'=>$state,'fingerprint'=>\Kulturhus\Verein\MemberBankSettings::fingerprint($state)],JSON_THROW_ON_ERROR);
}
