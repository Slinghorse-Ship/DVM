<?php declare(strict_types=1);
use Kulturhus\Native\Api\Factory as SystemAuthFactory;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\System\{Tasks,Runner};

/** A real private Scheduler→Runner→canonical AuthRetention path, without host/global cleanup. */
function ownSystemAuthCleanup(PDO $db,array $fixture,Tasks $tasks,Runner $runner,int $now):void
{
    $own=SystemAuthFactory::session($db,$fixture['accounts']['admin']['token'])->accountId;$other=SystemAuthFactory::session($db,$fixture['other']['token'])->accountId;$old=$now-\Kulturhus\Native\Auth\Retention::GRACE_SECONDS-1;$created=$old-3600;$ids=[];
    foreach(['own'=>$own,'foreign'=>$other]as$scope=>$account){
        foreach(['sessions','tokens','challenges']as$kind){$id=hash('sha256','own-system-auth-'.$scope.'-'.$kind);$ids[$scope][$kind]=$id;
            if($kind==='sessions')Db::run($db,"INSERT INTO native_auth_sessions(token_hash,account_id,expires_at,created_at,duration_mode) VALUES(?,?,?,?,'standard')",[$id,$account,$old,$created]);
            elseif($kind==='tokens')Db::run($db,"INSERT INTO native_auth_tokens(token_hash,account_id,purpose,expires_at,created_at) VALUES(?,?,'reset',?,?)",[$id,$account,$old,$created]);
            else Db::run($db,'INSERT INTO native_auth_challenges(token_hash,account_id,client_hash,expires_at) VALUES(?,?,?,?)',[$id,$account,hash('sha256','own-system-auth-client'),$old]);
        }
    }
    $global=hash('sha256','own-system-global-limit-retained');Db::run($db,'INSERT INTO native_auth_limits VALUES(?,7,?)',[$global,$old]);$globalOriginal=Db::run($db,'SELECT * FROM native_auth_limits WHERE scope_hash=?',[$global])->fetch();
    $count=static function(string $scope)use($db,$ids):int{$sum=0;foreach($ids[$scope]as$kind=>$id)$sum+=(int)Db::run($db,'SELECT COUNT(*) FROM native_auth_'.$kind.' WHERE token_hash=?',[$id])->fetchColumn();return $sum;};
    systemCheck($runner->tick()['runs']===0&&$count('own')===3&&$count('foreign')===3,'default paused local tasks preserve even eligible own and foreign authentication rows');
    $tasks->save('auth-cleanup',0,true,60,$now);$result=$runner->tick();$run=$tasks->history(['task'=>'auth-cleanup'])['rows'][0];
    systemCheck($result['completed']===1&&$result['failed']===0&&$run['status']==='completed'&&$run['summary']['count']===3,'real private scheduler and owner heartbeats execute the canonical club authentication cleanup with its closed result');
    systemCheck($count('own')===0&&$count('foreign')===3,'actual optional club task removes only its own expired technical sessions tokens and challenges');
    systemCheck(Db::run($db,'SELECT * FROM native_auth_limits WHERE scope_hash=?',[$global])->fetch()===$globalOriginal,'club task never changes even an eligible original global authentication limit');
    $current=array_column($tasks->listing(),null,'id')['auth-cleanup'];$tasks->save('auth-cleanup',$current['version'],false,60,$now);systemCheck($runner->tick()['runs']===0,'explicit current pause stops further local authentication work');
}
