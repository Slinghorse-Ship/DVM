<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
$cfg=\Kulturhus\Native\TestSupport::config();if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-cache-interface')throw new RuntimeException('Exact owned cache interface worker required.');
$f=json_decode(file_get_contents(getenv('DVM_CACHE_UI_FIXTURE')),true,32,JSON_THROW_ON_ERROR);$db=\Kulturhus\Native\TestSupport::db();$path=$f['root'].'/public-cache';$held=$f['root'].'/retained-cache';
switch($argv[1]??''){
 case'role-off':case'role-on':$q=$db->prepare('UPDATE actors SET is_superuser=? WHERE id=? AND club_id=?');$q->execute([(int)($argv[1]==='role-on'),$f['accounts']['admin']['actor'],$f['club']]);break;
 case'blocked-on':if(!is_dir($path)||is_link($path)||file_exists($held)||!rename($path,$held)||!symlink($f['root'].'/media',$path))throw new RuntimeException('Owned cache failure setup refused.');break;
 case'blocked-off':if(is_link($path)){if(readlink($path)!==$f['root'].'/media'||!is_dir($held))throw new RuntimeException('Owned cache failure recovery refused.');unlink($path);rename($held,$path);}break;
 default:throw new RuntimeException('Unknown owned cache worker action.');
}
