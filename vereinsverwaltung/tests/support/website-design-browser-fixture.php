<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\WebsiteDesign;
use Kulturhus\Native\Shared\Db;
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic website-design fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();WebsiteDesign::install($db);
$club=$mode==='standalone'?'synthetic-a':\Kulturhus\Native\Integration\ManagerBridge::clubKey('manager-demo');
$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/website-design-'.$mode.'.json';
$originals=static function()use($db,$club):array{
    $queries=['pages'=>['SELECT * FROM khcms_pages WHERE club_id=? ORDER BY id',[$club]],'revisions'=>['SELECT r.* FROM khcms_revisions r JOIN khcms_pages p ON p.id=r.page_id WHERE p.club_id=? ORDER BY r.page_id,r.revision',[$club]],'identity'=>['SELECT * FROM native_club_settings WHERE club_id=?',[$club]],'media'=>['SELECT * FROM khcms_media WHERE club_id=? ORDER BY id',[$club]]];$out=[];
    foreach($queries as $name=>[$sql,$params])$out[$name]=hash('sha256',json_encode(Db::run($db,$sql,$params)->fetchAll(),JSON_THROW_ON_ERROR));return $out;
};
if(($argv[1]??'')==='check'){
    $f=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    if($f['originals']!==$originals()||WebsiteDesign::forClub($db,$club)['template']!==$f['template'])throw new RuntimeException('Original content or final restored website design differs.');
    echo "Original draft/publication/revision/media/identity bytes retained and design restored: $mode.\n";exit();
}
$state=WebsiteDesign::forClub($db,$club);file_put_contents($file,json_encode(['template'=>$state['template'],'version'=>$state['version'],'originals'=>$originals()],JSON_THROW_ON_ERROR));chmod($file,0600);echo "Owned website-design fixture recorded: $mode.\n";
