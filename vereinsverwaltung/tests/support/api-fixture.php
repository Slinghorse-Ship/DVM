<?php declare(strict_types=1);
require_once __DIR__.'/editorial-browser-fixture.php';
function ownApiFixture(PDO $db):array
{
    \Kulturhus\Native\Installer\SchemaRegistry::install($db);\Kulturhus\Native\Api\Schema::install($db);$fixture=ownEditorialBrowserFixture($db);putenv('VEREIN_PRIVATE_ROOT='.$fixture['root']);$fixture['password']='Own isolated workflow password 2026!';
    $key=file_get_contents($fixture['root'].'/auth.key');$auth=\Kulturhus\Native\Auth\Runtime::service($db);
    \Kulturhus\Native\Shared\Db::run($db,"INSERT INTO clubs VALUES('own-api-other','Own API other club')");\Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES('own-api-other-admin','own-api-other','member',1)");
    $other=new \Kulturhus\Native\Integration\ManagerBridge($db,'own-api-other-admin','native',static fn()=>null);$access=$other->access();$invite=$auth->createInvitation($access,$other->nativeActorId(),'own-api-other@example.invalid');$auth->acceptInvitation($invite['token'],$fixture['password']);$fixture['other']=['actor'=>'own-api-other-admin','token'=>$auth->passwordLogin('own-api-other@example.invalid',$fixture['password'],'own-api-other')['session_token'],'access'=>$access];
    return $fixture;
}

/** Keep every original record, while allowing genuinely new API-created records. */
function ownApiOriginalRecords(PDO $db,array $exclude=[]):array
{
    $proof=[];
    foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){if(str_starts_with($table,'native_api_')||in_array($table,$exclude,true))continue;
        $columns=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);$rows=[];$selection=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',$columns));
        // MySQL invisible generated uniqueness columns are absent from SELECT *.
        // Compare the same explicit full column set both before and after.
        foreach($db->query('SELECT '.$selection.' FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row){$hash=hash('sha256',serialize($row));$rows[$hash]=($rows[$hash]??0)+1;}
        $proof[$table]=['columns'=>$columns,'rows'=>$rows];
    }
    return $proof;
}
function ownApiAssertOriginalRecords(PDO $db,array $proof):void
{
    foreach($proof as $table=>$expected){$columns=implode(',',array_map(static fn(string $column):string=>'`'.$column.'`',$expected['columns']));$current=[];
        foreach($db->query('SELECT '.$columns.' FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row){$hash=hash('sha256',serialize($row));$current[$hash]=($current[$hash]??0)+1;}
        foreach($expected['rows'] as $hash=>$count)if(($current[$hash]??0)<$count)throw new RuntimeException('Original API record changed: '.$table);
    }
}
