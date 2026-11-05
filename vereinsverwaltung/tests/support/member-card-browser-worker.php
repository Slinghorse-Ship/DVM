<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
$cfg=\Kulturhus\Native\TestSupport::config();if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-member-cards'||($cfg['owner']??'')!=='isolated-native-ci')exit(1);$db=\Kulturhus\Native\TestSupport::db();$action=$argv[1]??'';
if(in_array($action,['lock','lock-origin','lock-branding'],true)){$db->exec('START TRANSACTION');$sql=match($action){'lock-origin'=>"SELECT club_id FROM native_club_settings WHERE club_id='".\Kulturhus\Native\Integration\ManagerBridge::clubKey('own-card-club')."' FOR UPDATE",'lock-branding'=>"SELECT club_id FROM club_settings WHERE club_id='own-card-club' AND section='club' FOR UPDATE",default=>"SELECT id FROM contacts WHERE club_id='own-card-club' AND id='member-one' FOR UPDATE"};$db->query($sql)->fetch();echo"LOCKED\n";fflush(STDOUT);fgets(STDIN);$db->rollBack();}
elseif($action==='failure-on')$db->exec("CREATE TRIGGER own_card_browser_failure BEFORE INSERT ON member_card_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned browser command failure'; END");
elseif($action==='failure-off')$db->exec('DROP TRIGGER own_card_browser_failure');
elseif($action==='source-change')$db->exec("UPDATE memberships SET version=version+1 WHERE club_id='own-card-club' AND id='membership-member-one'");
elseif($action==='wallet-change'){$f=json_decode(file_get_contents(getenv('DVM_MEMBER_CARD_FIXTURE')),true,64,JSON_THROW_ON_ERROR);$wallet=new \Kulturhus\Verein\MemberCardWallet($db,'own-card-admin',\Kulturhus\Native\Shared\SecretBox::fromFile($f['root'].'/private.key'));$s=$wallet->state();$wallet->toggle('google',$s['google']['version'],false);}
else exit(1);
