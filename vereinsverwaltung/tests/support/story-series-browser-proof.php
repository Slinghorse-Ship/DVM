<?php declare(strict_types=1);
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Communication\{ImageHistory,StorySeries};
/** Real image studio and multipart action, in the disposable suite only. */
$browserPage=$event('browser-master',25);$service->run($club,20);$browserItem=Db::run($db,"SELECT item_id FROM native_social_event_preparations WHERE page_id=? AND kind='story' ORDER BY created_at,item_id LIMIT 1",[$browserPage['id']])->fetchColumn();
foreach(['web','sessions']as$directory)if(!is_dir($root.'/'.$directory))mkdir($root.'/'.$directory,0700);
$socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$proof=TestSupport::evidenceDirectory();$fixture=['root'=>$root,'origin'=>'http://127.0.0.1:'.$port,'actor'=>$a->user()['id'],'item'=>$browserItem,'evidence'=>$proof];$fixturePath=$root.'/browser.json';file_put_contents($fixturePath,json_encode($fixture,JSON_THROW_ON_ERROR));chmod($fixturePath,0600);putenv('DVM_STORY_BROWSER_FIXTURE='.$fixturePath);
$log=fopen($proof.'/http.log','ab');$server=proc_open([...TestSupport::phpCommand(),'-d','upload_max_filesize=8M','-d','post_max_size=64M','-d','max_file_uploads=20','-S','127.0.0.1:'.$port,'-t',$root.'/web',__DIR__.'/story-series-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($server))throw new RuntimeException('Own story browser server unavailable');fclose($pipes[0]);
for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}
$allTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$filter=static fn($table)=>in_array($table,['native_social_images','native_communication_save_requests'],true)?[' WHERE item_id<>?',[$browserItem]]:['',[]];
try{
    foreach([true,false]as$hint){
        $input=$state['config'];$input['story_day_hint']=$hint;$state=$service->save($a,$input,true,$state['version']);$before=ownOriginalDatabaseHash($db,$allTables,$filter);$fixture['hint']=$hint;
        $process=proc_open(['node',dirname(__DIR__).'/story-series-browser.mjs'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fwrite($pipes[0],json_encode($fixture,JSON_THROW_ON_ERROR));fclose($pipes[0]);$status=proc_close($process);$check($status===0,'actual story image studio exports and saves full pixel matrix with hint '.($hint?'enabled':'disabled'));
        $result=json_decode(file_get_contents($proof.'/browser-'.(int)$hint.'.json'),true,64,JSON_THROW_ON_ERROR);$row=(new ImageHistory($db,$a))->originals($browserItem,[$result['image']])[0];$meta=json_decode($row['metadata_json'],true,64,JSON_THROW_ON_ERROR);$check(StorySeries::same($meta['document'],$result['document'])&&$meta['story_hint']===$hint&&count($meta['story_series']['sha256'])===7,'browser save binds full original layers and all seven private day variants');
        $variantPaths=StorySeries::paths($root.'/communication-images',$row['id']);$check(StorySeries::same(StorySeries::sources($variantPaths),$meta['story_series']['sha256']),'each browser-encoded full-size PNG is actually retained with its original hash');
        $firstVariant=imagecreatefrompng($variantPaths['base']);$dayVariant=imagecreatefrompng($variantPaths['d7']);$sameOutside=true;$differentInside=false;
        for($y=0;$y<1920;$y+=4)for($x=0;$x<1080;$x+=4){$same=imagecolorat($firstVariant,$x,$y)===imagecolorat($dayVariant,$x,$y);if($y<294||$y>372||$x<590||$x>970){if(!$same)$sameOutside=false;}elseif(!$same)$differentInside=true;}
        unset($firstVariant,$dayVariant);$check($sameOutside&&$differentInside,'real browser variants change only the managed day badge and preserve all surrounding design pixels');
        $main=imagecreatefrompng($images->file($row['id'])['path']);$expected=imagecreatefrompng($variantPaths[$hint?'initial':'base']);$same=true;for($y=0;$y<1920;$y+=13)for($x=0;$x<1080;$x+=13)if(imagecolorat($main,$x,$y)!==imagecolorat($expected,$x,$y))$same=false;if(!$same){copy($images->file($row['id'])['path'],$proof.'/main-difference.png');copy($variantPaths[$hint?'initial':'base'],$proof.'/expected-difference.png');for($dy=0;$dy<1920;$dy+=13){for($dx=0;$dx<1080;$dx+=13)if(imagecolorat($main,$dx,$dy)!==imagecolorat($expected,$dx,$dy)){echo json_encode(['pixel_diagnostic'=>['x'=>$dx,'y'=>$dy,'main'=>imagecolorat($main,$dx,$dy),'expected'=>imagecolorat($expected,$dx,$dy)],'evidence'=>$proof],JSON_THROW_ON_ERROR)."\n";break 2;}}}unset($main,$expected);$check($same,'visible saved image matches the selected day-hint artwork');
        $check(ownOriginalDatabaseHash($db,$allTables,$filter)===$before,'actual multipart save preserves every unrelated whole database row');
    }
    echo json_encode(['story_browser_evidence'=>$proof],JSON_THROW_ON_ERROR)."\n";
}finally{proc_terminate($server);proc_close($server);fclose($log);}
