<?php declare(strict_types=1);
/** Fresh actors, languages and unknown originals before the whole-row baseline. */
function ownVideoStorageProfiles(PDO $db,string $root,callable $put,callable $id,string $bytes,string $fixture):array
{
    $profiles=[];
    foreach(['native','integrated']as$mode)foreach(['de','en']as$locale){
        $key=$mode.'-'.$locale;$club='own-storage-'.$key;$actor='own-storage-'.$key;$token=null;$managerActor=null;
        if($mode==='native'){
            Kulturhus\Native\Shared\Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$actor,$club,'Storage author <literal> ä🙂']);Kulturhus\Native\Shared\Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) SELECT ?,module,capability FROM native_grants WHERE actor_id='admin'",[$actor]);$access=new Kulturhus\Native\Shared\Access($db,$actor);
        }else{
            Kulturhus\Native\Shared\Db::run($db,'INSERT INTO clubs(id,name) VALUES(?,?)',[$club,'Own storage browser club']);Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',1)",[$actor,$club]);$managerActor=$actor;
            $bridge=new Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$access=$bridge->access();
            (new Kulturhus\Verein\Settings($db,$actor))->save('club',array_replace(Kulturhus\Verein\SettingsSchema::defaults('club'),['name'=>'Own storage club','street'=>'Own fixture street','postcode'=>'00000','city'=>'Own fixture city']),0);
            $contact=(new Kulturhus\Verein\Registry($db,$actor))->saveContact(null,0,'person','Own storage author',$actor.'@example.invalid','Own fixture address');Kulturhus\Native\Shared\Db::run($db,'UPDATE actors SET contact_id=? WHERE id=?',[$contact,$actor]);$access=$bridge->access();
            $auth=Kulturhus\Native\Auth\Runtime::service($db);$invite=$auth->createInvitation($access,$bridge->nativeActorId(),$actor.'@example.invalid');$auth->acceptInvitation($invite['token'],'Own storage browser password 2026!');$token=$auth->passwordLogin($actor.'@example.invalid','Own storage browser password 2026!',$key)['session_token'];$actor=$access->user()['id'];$club=$access->club();
        }
        (new Kulturhus\Native\Language\Catalog($db,$access))->select($locale);$work=new Kulturhus\Native\Communication\Workspace($db,$access,'social',$root.'/media');$item=$work->save(['title'=>'Own inventory source <literal> ä🙂','body'=>'Own original source body','kind'=>'reel','channels'=>[]]);(new Kulturhus\Native\Communication\Videos($db,$access,$root.'/media'))->save($item['id'],1,0,$fixture,'Earlier registered original <literal> ä🙂.mp4');$owner=$root.'/communication-videos/'.hash('sha256',$club);for($i=1;$i<=14;$i++)$put($owner,$id($i),$bytes);$put($owner,$id(19),'partial');$put($owner,$id(20),str_repeat('broken',100));
        $profiles[$key]=compact('mode','locale','actor','club','token','managerActor')+['item'=>$item['id'],'source'=>$id(1)];
    }
    return$profiles;
}
