<?php declare(strict_types=1);
require_once __DIR__.'/editorial-browser-fixture.php';
function ownRoutingBrowser(PDO $db):void
{
    $f=ownEditorialBrowserFixture($db);$access=$f['admin'];$pages=new \Kulturhus\Native\Cms\Pages($db,$access);$model=new \Kulturhus\Native\Cms\DataModel($db,$access);
    $policy=['segments'=>[['name'=>'category','type'=>'slug']],'pagination'=>['mode'=>'path','marker'=>'page','maximum'=>100],'slash'=>'never'];
    $model->saveTemplate(['key'=>'routing_browser','label'=>'Eigene Adressregeln','renderer'=>'standard','fields'=>[],'url_policy'=>$policy]);
    $model->saveTemplate(['key'=>'routing_programme_browser','label'=>'Programm mit Jahr','renderer'=>'standard','fields'=>[],'url_policy'=>['segments'=>[['name'=>'year','type'=>'integer','use'=>'event-year']],'pagination'=>['mode'=>'path','marker'=>'seite','maximum'=>100],'slash'=>'always']]);
    $publish=static function(array $input)use($pages):array{$p=$pages->save($input);return$pages->publish($p['id'],$p['version']);};
    $containers=[];foreach(['aktuelles','galerie','routing']as$slug)$containers[$slug]=$publish(['title'=>'Öffentlich '.$slug,'slug'=>$slug,'template'=>'routing_browser']);
    $publish(['title'=>'Programm','slug'=>'veranstaltungen','template'=>'routing_programme_browser']);
    for($i=1;$i<=13;++$i)$publish(['title'=>'Geschichte '.$i,'slug'=>'aktuelles/routing-'.$i,'template'=>'news','news_date'=>'2050-01-'.str_pad((string)$i,2,'0',STR_PAD_LEFT)]);
    for($i=1;$i<=21;++$i)$publish(['title'=>'Veranstaltung '.$i,'slug'=>'veranstaltungen/routing-'.$i,'template'=>'event','event_start'=>'2050-01-'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'T20:00','event_location'=>'Eigener Saal']);
    $publish(['title'=>'ANDERES-JAHR','slug'=>'veranstaltungen/routing-other','template'=>'event','event_start'=>'2051-01-01T20:00']);
    for($i=1;$i<=7;++$i)$publish(['title'=>'Album '.$i,'slug'=>'galerie/routing-'.$i,'template'=>'gallery']);
    $longBase='aktuelles/'.str_repeat('a',80).'/'.str_repeat('b',80).'/'.str_repeat('c',8);$publish(['title'=>'Maximaler Originalpfad','slug'=>$longBase,'template'=>'routing_browser']);
    $publish(['title'=>'Exaktes Kind','slug'=>'routing/exact']);$pages->save(['title'=>'GEHEIMES-ORIGINAL','slug'=>'routing/private']);$publish(['title'=>'Eigenes Pagerkind','slug'=>'routing/page/2']);
    $language=new \Kulturhus\Native\Language\Settings($db,$access);$language->save(['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[]],['code'=>'fr','label'=>'Français','enabled'=>false,'fallback'=>'de','messages'=>[]]]],0);
    $translations=new \Kulturhus\Native\Language\Translations($db,$access);$tr=$translations->save($containers['aktuelles']['id'],'en',['slug'=>'stories','fields'=>['title'=>'Confirmed stories']],$containers['aktuelles']['published_revision'],0);$translations->publish($containers['aktuelles']['id'],'en',$tr['version']);
    \Kulturhus\Native\Shared\Db::run($db,"UPDATE native_users SET club_id=? WHERE id='reader'",[$access->club()]);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);$data=['native_origin'=>'http://127.0.0.1:'.$port,'template'=>'routing_browser','long_path'=>'/'.$longBase.'/'.str_repeat('z',80).'/page/2'];
    $nativeRuntime=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.\Kulturhus\Native\TestSupport::config()['database'];foreach([$nativeRuntime,$nativeRuntime.'/sessions',$nativeRuntime.'/media']as$dir)if(!is_dir($dir))mkdir($dir,0700,true);copy($f['root'].'/auth.key',$nativeRuntime.'/auth.key');chmod($nativeRuntime.'/auth.key',0600);$f['native_runtime']=$nativeRuntime;
    $export=$f;unset($export['admin']);$export['data']=$data;$file=$f['root'].'/fixture.json';file_put_contents($file,json_encode($export,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_EDITORIAL_WORKFLOW_FIXTURE='.$file);
    $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','khcms_definitions','khcms_definition_revisions','khcms_state']));$before=ownOriginalDatabaseHash($db,$tables);$keys=[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')];$servers=[];
    try{foreach([$f['origin'],$data['native_origin']]as$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($f['root'].'/routing-'.$port.'.log','ab');$proc=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$f['root'].'/web',__DIR__.'/routing-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($proc))throw new RuntimeException('Own routing server unavailable.');fclose($pipes[0]);$servers[]=[$proc,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        $proc=proc_open(['node',dirname(__DIR__).'/native-routing-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($proc)||proc_close($proc)!==0)throw new RuntimeException('Actual routing browser failed.');
        if($before!==ownOriginalDatabaseHash($db,$tables)||$keys!==[hash_file('sha256',$f['root'].'/auth.key'),hash_file('sha256',$f['root'].'/private.key')])throw new RuntimeException('Routing UI changed original rows or keys.');
        echo json_encode(['suite'=>'native-routing-originals','tables'=>count($tables),'original_bytes'=>'exact','keys'=>'exact','live_transport'=>false],JSON_THROW_ON_ERROR)."\n";
    }finally{$artifacts=getenv('DVM_ROUTING_ARTIFACTS')?:'/tmp/kulturhus-routing-browser-proof';if(!is_dir($artifacts))mkdir($artifacts,0700,true);foreach($servers as[$proc,$log]){proc_terminate($proc);proc_close($proc);fclose($log);}foreach(glob($f['root'].'/routing-*.log')as$log)copy($log,$artifacts.'/'.basename($log));}
}
