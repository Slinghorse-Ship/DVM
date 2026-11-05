<?php declare(strict_types=1);
require_once __DIR__.'/editorial-browser-fixture.php';require_once __DIR__.'/original-database-hash.php';
function ownExtensionsBrowser(PDO $db):array
{
    $fixture=ownEditorialBrowserFixture($db);$registry=new \Kulturhus\Verein\Registry($db,$fixture['accounts']['admin']['actor']);
    foreach($fixture['accounts'] as $role=>$account){$contact=$registry->saveContact(null,0,'person','Own extension '.$role,'own-extension-'.$role.'@example.invalid','Own extension fixture');\Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$account['actor']]);\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$account['token'])->access();}
    \Kulturhus\Verein\Database::ensureContributionRates($db);
    $pages=new \Kulturhus\Native\Cms\Pages($db,$fixture['admin']);$page=$pages->save(['title'=>'Own extension page','slug'=>'own-extension-page','body_html'=>'<p>Own exact website original.</p>','excerpt'=>'Own original description','nav_visible'=>false]);$pages->publish($page['id'],$page['version']);
    $tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_extensions','native_extension_values','native_extension_history']));$original=ownOriginalDatabaseHash($db,$tables);$keys=[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')];$server=null;
    try{
        $server=ownEditorialBrowserStart($fixture,['result_file'=>$fixture['root'].'/extensions-result.json']);
        $node=proc_open(['node',dirname(__DIR__).'/native-extensions-browser.mjs'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($node)||proc_close($node)!==0)throw new RuntimeException('Actual protected extensions browser failed.');
        if(ownOriginalDatabaseHash($db,$tables)!==$original||$keys!==[hash_file('sha256',$fixture['root'].'/auth.key'),hash_file('sha256',$fixture['root'].'/private.key')])throw new RuntimeException('Extension browser changed an original business or key byte.');
        $result=json_decode(file_get_contents($fixture['root'].'/extensions-result.json'),true,32,JSON_THROW_ON_ERROR);echo json_encode(['suite'=>'native-extensions-browser-originals','original_tables'=>count($tables),'original_bytes'=>'exact','original_keys'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;return $result;
    }finally{if($server){proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);}}
}
