<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,PageTrash};
use Kulturhus\Native\Integration\ManagerBridge;
$c=TestSupport::config();if(PHP_SAPI!=='cli'||($c['owner']??'')!=='cloud-onboarding-synthetic'||!in_array($c['label']??'',['demo','integrated-manager'],true))throw new RuntimeException('Owned synthetic batch fixture required.');
$mode=$c['label']==='demo'?'standalone':'integrated';$db=TestSupport::db();$a=$mode==='integrated'?(new ManagerBridge($db,'manager-admin','native',static fn()=>null))->access():new Access($db,'admin');$pages=new Pages($db,$a);$trash=new PageTrash($db,$a);
$dir=dirname(__DIR__,3).'/.local/browser-tests';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/page-batches-'.$mode.'.json';
if(($argv[1]??'')==='check'){$f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);foreach($f['groups']as $group)foreach($group as $p){$now=$pages->get($p['id']);if($now['trashed']||$now['published_revision']!==null||$now['body_html']!==$p['body_html']||$now['slug']!==$p['slug'])throw new RuntimeException('Batch original changed or did not return privately.');}echo "Private batch originals retained after completed, paused, skipped and cancelled workflows: $mode.\n";exit();}
$f=['groups'=>[]];foreach([1440=>3,820=>41,390=>3]as $width=>$count)for($i=0;$i<$count;++$i){$slug='own-batch-'.$width.'-'.$i;$id=Db::run($db,'SELECT id FROM khcms_pages WHERE club_id=? AND slug=?',[$a->club(),$slug])->fetchColumn();$old=$id?$pages->get($id):null;$title='Own batch '.$width.' original '.$i;
if($old&&$old['title']!==$title)throw new RuntimeException('Owned batch address occupied.');if($old&&$old['trashed'])$old=$trash->restore($old['id'],$old['version']);$p=$pages->save(['id'=>$old['id']??'','title'=>$title,'slug'=>$slug,'body_html'=>'<p>Exact <strong>batch original 🎶 &amp; 0</strong></p>','nav_visible'=>false],$old['version']??0);$f['groups'][$width][]=$p;}
file_put_contents($file,json_encode($f,JSON_THROW_ON_ERROR));chmod($file,0600);echo "Owned batch browser originals prepared: $mode.\n";
