<?php declare(strict_types=1);
require_once __DIR__.'/editorial-browser-fixture.php';
function ownCacheBrowser(PDO $db):void {
    $fixture=ownEditorialBrowserFixture($db);$registry=new \Kulturhus\Verein\Registry($db,$fixture['accounts']['admin']['actor']);
    foreach($fixture['accounts']as$role=>$account){$contact=$registry->saveContact(null,0,'person','Own cache '.$role,'own-cache-'.$role.'@example.invalid','Own isolated cache fixture');\Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$account['actor']]);\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();}
    \Kulturhus\Verein\Database::ensureContributionRates($db);$club=\Kulturhus\Native\Integration\ManagerBridge::clubKey($fixture['club']);$store=new \Kulturhus\Native\Cache\Store($fixture['root'].'/public-cache',$club);
    for($i=0;$i<44;++$i)$store->set('module-originals','Original cache entry '.str_pad((string)$i,2,'0',STR_PAD_LEFT),['SECRET-CACHE-VALUE'=>'Own private original'],0,['own-browser-tag:'.$i]);
    $long=new \Kulturhus\Native\Cache\Store($fixture['root'].'/public-cache',$club,clock:static fn()=>time()+1);$long->set('module-'.str_repeat('a',50),str_repeat('L',200),['SECRET-CACHE-VALUE'=>'Own private long original'],0,['own-browser-tag:'.str_repeat('b',90)]);
    $pages=new \Kulturhus\Native\Cms\Pages($db,$fixture['admin']);$page=$pages->save(['slug'=>'cache-source','title'=>'Own published cache original','body'=>'<p>Own exact unchanged public content ä🙂</p>']);$pages->publish($page['id'],$page['version']);
    file_put_contents($fixture['root'].'/media/retained-original.txt','Own cache browser original ä🙂');$mediaHash=hash_file('sha256',$fixture['root'].'/media/retained-original.txt');$keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];
    $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions']));$original=ownOriginalDatabaseHash($db,$tables);$server=null;
    try{
        $server=ownEditorialBrowserStart($fixture,['result_file'=>$fixture['root'].'/cache-browser-result.json']);$process=proc_open(['node',dirname(__DIR__).'/native-cache-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual protected cache browser failed.');
        $curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);
        try{
            $request=static function(string $url)use($curl):int{curl_setopt($curl,CURLOPT_URL,$url);if(curl_exec($curl)===false)throw new RuntimeException('Own cache revocation HTTP unavailable');return (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);};
            if($request($fixture['origin'].'/own-test-enter?role=admin')!==303||$request($fixture['origin'].'/vereinsverwaltung/?view=settings&tab=cache&cache_json=1')!==200)throw new RuntimeException('Own native cache session did not open.');
            $native=\Kulturhus\Native\Integration\ManagerBridge::actorKey($fixture['accounts']['admin']['actor'],$fixture['club']);\Kulturhus\Native\Shared\Db::run($db,'UPDATE native_auth_accounts SET suspended=1 WHERE actor_id=?',[$native]);
            try{if($request($fixture['origin'].'/vereinsverwaltung/?view=settings&tab=cache&cache_json=1')!==303)throw new RuntimeException('Suspended native session retained cache authority.');}finally{\Kulturhus\Native\Shared\Db::run($db,'UPDATE native_auth_accounts SET suspended=0 WHERE actor_id=?',[$native]);}
            echo "PASS actual native session suspension immediately denies protected cache metadata\n";
        }finally{curl_close($curl);}
        if($original!==ownOriginalDatabaseHash($db,$tables)||$keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')]||$mediaHash!==hash_file('sha256',$fixture['root'].'/media/retained-original.txt'))throw new RuntimeException('Cache administration changed original business data, keys or media.');
        echo json_encode(['suite'=>'native-cache-originals','original_tables'=>count($tables),'original_bytes'=>'exact','original_keys'=>'exact','original_media'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
    }finally{if($server){proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);}}
}
