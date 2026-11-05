<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/autoload.php';
$cfg=\Kulturhus\Native\TestSupport::config();if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-extensions-interface')throw new RuntimeException('Exact owned extension interface worker required.');
$f=json_decode(file_get_contents(getenv('DVM_EXTENSION_UI_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$db=\Kulturhus\Native\TestSupport::db();putenv('VEREIN_PRIVATE_ROOT='.$f['root']);$actor=$f['accounts']['admin']['actor'];
switch($argv[1]??''){
 case'fail-on':$db->exec("CREATE TRIGGER own_extension_ui_browser_failure BEFORE UPDATE ON native_extensions FOR EACH ROW BEGIN IF NEW.plugin_id='own-ui-00' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owned extension browser SQL failure'; END IF; END");break;
 case'fail-off':$db->exec('DROP TRIGGER IF EXISTS own_extension_ui_browser_failure');break;
 case'role-off':case'role-on':$q=$db->prepare('UPDATE actors SET is_superuser=? WHERE id=? AND club_id=?');$q->execute([(int)($argv[1]==='role-on'),$actor,$f['club']]);break;
 case'save-other':$manager=\Kulturhus\Native\Extensions\Factory::manager($db,$actor,false);foreach($manager->listing()as$row)if($row['id']==='own-ui-00'){$manager->change('own-ui-00','configure',$row['revision'],['text'=>'Owned deliberate competing original','count'=>17,'option'=>false,'style'=>'beta']);break;}break;
 default:throw new RuntimeException('Unknown owned extension worker action.');
}
