<?php declare(strict_types=1);
require_once dirname(__DIR__,3).'/vendor/autoload.php';
require_once dirname(__DIR__,2).'/src/bootstrap.php';
require_once __DIR__.'/editorial-browser-fixture.php';
require_once __DIR__.'/original-database-hash.php';
function ownLanguagesBrowser(PDO $db,string $unusedSource):void
{
    $fixture=ownEditorialBrowserFixture($db);$access=$fixture['admin'];$settings=new \Kulturhus\Native\Language\Settings($db,$access);
    $settings->save(['source'=>'de','languages'=>[['code'=>'de','label'=>'Deutsch','enabled'=>true,'fallback'=>null,'messages'=>[]],['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[]],['code'=>'fr','label'=>'Français','enabled'=>false,'fallback'=>'de','messages'=>[]]]],0);
    $section=bin2hex(random_bytes(16));$pages=new \Kulturhus\Native\Cms\Pages($db,$access);$source=$pages->save(['title'=>'Vereinsleben und gemeinsame Veranstaltungen','slug'=>'vereinsleben','body_html'=>'<p>Ursprünglicher öffentlicher Inhalt.</p>','content_sections'=>[['id'=>$section,'heading'=>'Bildbeschreibung','body_html'=>'<p>Originaler Abschnitt bleibt erhalten.</p>','layout'=>'text','button_label'=>'Zum Original','button_url'=>'/site/start']]]);$source=$pages->publish($source['id'],$source['version']);
    $home=$pages->save(['title'=>'Eigene ursprüngliche Startseite','slug'=>'start','home_layout'=>['hero_title'=>'Hero-Überschrift','hero_html'=>'<p>Unveränderter ursprünglicher Einstieg.</p>']]);$home=$pages->publish($home['id'],$home['version']);
    $translations=new \Kulturhus\Native\Language\Translations($db,$access);$translations->save($source['id'],'en',['slug'=>'club-life','fields'=>['title'=>'Club life','body_html'=>'<p>Confirmed public translation.</p>']],$source['published_revision'],0);$translations->publish($source['id'],'en',1);
    for($i=1;$i<=21;$i++){$search=$pages->save(['title'=>'Suchoriginal '.$i,'slug'=>'suchoriginal-'.$i]);$search=$pages->publish($search['id'],$search['version']);$translations->save($search['id'],'en',['slug'=>'search-confirmed-'.$i,'fields'=>['title'=>'Confirmed language search '.$i]],$search['published_revision'],0);$translations->publish($search['id'],'en',1);}
    $editor=\Kulturhus\Native\Integration\ManagerBridge::actorKey($fixture['accounts']['editor']['actor'],$fixture['club']);$settings->saveRights($editor,'en',['edit'],0);
    // The standalone renderer's fixed public reader belongs to this fresh fixture club too.
    \Kulturhus\Native\Shared\Db::run($db,"UPDATE native_users SET club_id=? WHERE id='reader'",[$access->club()]);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$nativePort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $artifacts=\Kulturhus\Native\TestSupport::evidenceDirectory();putenv('DVM_LANGUAGE_ARTIFACTS='.$artifacts);
    $data=['page_id'=>$source['id'],'section_id'=>$section,'home_id'=>$home['id'],'source_revision'=>$source['published_revision'],'result_file'=>$fixture['root'].'/language-result.json','native_origin'=>'http://127.0.0.1:'.$nativePort];
    $file=$fixture['root'].'/fixture.json';$export=$fixture;unset($export['admin']);$export['data']=$data;file_put_contents($file,json_encode($export,JSON_THROW_ON_ERROR));chmod($file,0600);putenv('DVM_EDITORIAL_WORKFLOW_FIXTURE='.$file);
    $tables=array_values(array_filter($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),static fn(string $table):bool=>!str_starts_with($table,'native_language_')&&$table!=='native_auth_sessions'&&$table!=='khcms_state'));$before=ownOriginalDatabaseHash($db,$tables);$keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];$servers=[];
    try{
        foreach([$fixture['origin'],$data['native_origin']]as$origin){$port=parse_url($origin,PHP_URL_PORT);$log=fopen($fixture['root'].'/language-'.$port.'.log','ab');$process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),'-S','127.0.0.1:'.$port,'-t',$fixture['root'].'/web',__DIR__.'/languages-browser-router.php'],[0=>['pipe','r'],1=>$log,2=>$log],$pipes);if(!is_resource($process))throw new RuntimeException('Own language HTTP server unavailable.');fclose($pipes[0]);$servers[]=[$process,$log];for($i=0;$i<100;$i++){$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($probe){fclose($probe);break;}usleep(50000);}}
        $process=proc_open(['node',dirname(__DIR__).'/native-languages-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual protected language browser failed.');
        if(ownOriginalDatabaseHash($db,$tables)!==$before||$keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')])throw new RuntimeException('Language interface changed original business rows or keys.');
        echo json_encode(['suite'=>'native-languages-browser-originals','original_tables'=>count($tables),'original_bytes'=>'exact','original_keys'=>'exact','live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
    }finally{foreach($servers as[$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}$artifacts=getenv('DVM_LANGUAGE_ARTIFACTS')?:\Kulturhus\Native\TestSupport::evidenceDirectory();if(!is_dir($artifacts))mkdir($artifacts,0700,true);foreach(glob($fixture['root'].'/language-*.log')as$log)copy($log,$artifacts.'/'.basename($log));}
}
