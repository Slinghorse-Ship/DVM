<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Appearance\{Preferences,Conflict};
use Kulturhus\Native\Shared\Access;
$cfg=TestSupport::config();
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||($cfg['label']??'')!=='native-appearance'||($cfg['owner']??'')!=='isolated-native-ci'||count($argv)!==3||!in_array($argv[1],['focus','compact'],true)||!preg_match('/\A[a-f0-9]{64}\z/D',$argv[2]))exit(1);
$db=TestSupport::db();$preferences=new Preferences($db,new Access($db,'own-appearance-race'));
fwrite(STDOUT,"ready\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='go')exit(1);
try{$preferences->save(['admin_view'=>$argv[1],'admin_color'=>'dark','admin_density'=>'comfortable'],0,$argv[2]);$result='saved';}catch(Conflict){$result='conflict';}
fwrite(STDOUT,json_encode(['result'=>$result],JSON_THROW_ON_ERROR)."\n");
