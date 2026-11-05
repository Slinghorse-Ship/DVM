<?php declare(strict_types=1);
/** New synthetic personal accounts; no earlier account is used as a mutation target. */
function ownRolesBrowserTargets(PDO $db,array $fixture):array
{
    $config=\Kulturhus\Native\TestSupport::config();if(($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-roles-interface')throw new RuntimeException('Owned role browser targets required.');
    $admin=(new \Kulturhus\Native\Integration\ManagerBridge($db,'own-list-admin','native',static fn()=>null))->access();$auth=\Kulturhus\Native\Auth\Runtime::service($db);$registry=new \Kulturhus\Verein\Registry($db,'own-list-admin');$public=[];$before=[];
    $cases=getenv('DVM_ROLES_CASE');if($cases!==false&&!preg_match('/^[0-7]$/D',$cases))throw new RuntimeException('Owned role browser case required.');
    foreach($cases===false?range(0,7):[(int)$cases]as$case){
        $actor='own-roles-browser-'.$case;\Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser)VALUES(?,'own-list-club','member',0)",[$actor]);
        $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$bridge->access();$invite=$auth->createInvitation($admin,$bridge->nativeActorId(),$actor.'@example.invalid');$account=$auth->acceptInvitation($invite['token'],$fixture['password']);
        $name='Rollenname <em data-own-identity>0</em> ä🙂 '.$case;$profile=$registry->saveContact(null,0,'person',$name,$actor.'@example.invalid','Own new native role fixture place');
        $public[$case]=['actor'=>$actor,'native'=>$bridge->nativeActorId(),'account'=>$account['id'],'profile'=>$profile,'profile_name'=>$name];
        $before[$case]=['actor'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM actors WHERE id=?',[$actor])->fetch(),'native'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_users WHERE id=?',[$bridge->nativeActorId()])->fetch(),'account'=>\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$account['id']])->fetch()];
    }
    $self=null;
    if($cases===false||$cases==='0'){
        $actor='own-roles-self-manager';\Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser)VALUES(?,'own-list-club','member',0)",[$actor]);
        $data=\Kulturhus\Verein\Settings\RoleDraft::role(null)['data'];$data['name']='Own explicit self-reduction manager role';$data['description']='Own original general role manager';$data['settings_access']='1';$data['website_custom']='1';
        $settings=new \Kulturhus\Verein\Settings($db,'own-list-admin');$role=$settings->saveRole('',0,$data);$settings->assignRole($actor,$role,0);
        $bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$bridge->access();$email=$actor.'@example.invalid';$invite=$auth->createInvitation($admin,$bridge->nativeActorId(),$email);$account=$auth->acceptInvitation($invite['token'],$fixture['password']);
        $self=['actor'=>$actor,'native'=>$bridge->nativeActorId(),'account'=>$account['id'],'role'=>$role,'email'=>$email];
    }
    $actor='own-roles-pending';\Kulturhus\Native\Shared\Db::run($db,"INSERT INTO actors(id,club_id,role,is_superuser)VALUES(?,'own-list-club','member',0)",[$actor]);$bridge=new \Kulturhus\Native\Integration\ManagerBridge($db,$actor,'native',static fn()=>null);$bridge->access();$email=$actor.'@example.invalid';$auth->createInvitation($admin,$bridge->nativeActorId(),$email);$pending=['actor'=>$actor,'email'=>$email];
    return['public'=>$public,'before'=>$before,'self'=>$self,'pending'=>$pending];
}

function ownRolesBrowserTargetFilter(string $table,array $targets,?array $self=null):array
{
    $field=match($table){'actors'=>'id','native_users'=>'id','native_auth_accounts'=>'id','native_grants'=>'actor_id','settings_role_assignments'=>'actor_id','admission_board','admission_board_checks'=>'actor_id','native_identity_changes'=>'account_id',default=>null};
    if($field===null)return['',[]];$key=match($table){'native_users','native_grants'=>'native','native_auth_accounts','native_identity_changes'=>'account',default=>'actor'};$ids=array_column($targets,$key);
    if($table==='settings_role_assignments'&&$self)$ids[]=$self['actor'];
    return[' WHERE '.$field.' NOT IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids];
}

function ownRolesBrowserSelfVerify(PDO $db,?array $self,?array $result,callable $check):void
{
    if(!$self)return;
    $assignment=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM settings_role_assignments WHERE actor_id=?',[$self['actor']])->fetch();
    $row=$result?\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_role_requests WHERE actor_id=? AND request_key=?',[$self['actor'],$result['key']])->fetch():null;
    $original=$row?json_decode($row['original_json'],true,64,JSON_THROW_ON_ERROR):null;
    $check($row&&$assignment===['actor_id'=>$self['actor'],'club_id'=>'own-list-club','role_id'=>null,'version'=>2]&&$original['actor_id']===$self['actor']&&$original['operation']==='role-assign'&&$original['input']['role']==='__base__'&&$original['result']['state']['role_id']===null&&$original['result']['state']['version']===2&&hash('sha256',$row['original_json'])===$row['original_sha256'],'actual native self-reduction accepts exactly its first original and current base version while retaining every earlier role revision');
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$service=new \Kulturhus\Verein\Settings\RoleRequests($db,$self['actor']);$denied=false;
    try{$service->receipt($result['key']);}catch(DomainException $failure){$denied=$failure->getCode()===403;}
    $check($denied&&!\Kulturhus\Verein\Permissions::actor($db,$self['actor'])['settings_access']&&ownOriginalDatabaseHash($db,$tables)===$before,'actual reduced account cannot use its earlier original key to regain private role metadata and rejection changes no whole original row');
}

function ownRolesBrowserTargetsVerify(PDO $db,array $targets,array $before,array $actions,callable $check):void
{
    foreach($targets as$case=>$target){
        $old=$before[$case];$expectedActor=$old['actor'];$expectedActor['contact_id']=$target['profile'];$expectedActor['is_superuser']=1;$expectedNative=$old['native'];$expectedNative['name']=$target['profile_name'];$expectedAccount=$old['account'];$expectedAccount['version']+=2;
        $actor=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM actors WHERE id=?',[$target['actor']])->fetch();$native=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_users WHERE id=?',[$target['native']])->fetch();$account=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_auth_accounts WHERE id=?',[$target['account']])->fetch();
        $assignment=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM settings_role_assignments WHERE actor_id=?',[$target['actor']])->fetch();$board=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM admission_board WHERE actor_id=?',[$target['actor']])->fetch();$checks=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM admission_board_checks WHERE actor_id=? ORDER BY board_version',[$target['actor']])->fetchAll();$changes=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM native_identity_changes WHERE account_id=? ORDER BY action',[$target['account']])->fetchAll();
        $grants=\Kulturhus\Native\Shared\Db::run($db,'SELECT module,capability FROM native_grants WHERE actor_id=? ORDER BY module,capability',[$target['native']])->fetchAll(PDO::FETCH_NUM);
        $action=$actions[(string)$case]??null;
        $check($action&&$grants===[['accounts','manage'],['accounts','read'],['chat','edit'],['chat','read']]&&$actor===$expectedActor&&$native===$expectedNative&&$account===$expectedAccount&&$assignment['role_id']===$action['role_id']&&(int)$assignment['version']===1&&$board['office']==='2. Vorstand'&&(int)$board['version']===2&&!(bool)$board['enabled']&&array_column($checks,'board_version')===[1,2]&&array_unique(array_column($checks,'checked_by'))===['own-list-admin']&&array_column($changes,'action')===['identity.native.assigned','identity.native.superuser.granted'],'actual native browser changes only its explicitly approved new person role office profile and technical fields while preserving every other whole account field and authentication secret');
    }
}
