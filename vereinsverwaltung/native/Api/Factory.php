<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Integration\ManagerBridge;
final class Factory
{
    public static function operationKey():string
    {
        $path=(string)getenv('KH_NATIVE_AUTH_KEY_FILE');if(!$path||is_link($path)||!is_file($path))throw Problem::unauthorized();
        $key=file_get_contents($path);if(!is_string($key)||strlen($key)!==32)throw Problem::unauthorized();
        return hash_hmac('sha256','native-api-v1',$key,true);
    }
    public static function tokens(\PDO $db):Tokens{return new Tokens($db,\Kulturhus\Native\Auth\Runtime::service($db),self::operationKey());}
    public static function session(\PDO $db,#[\SensitiveParameter]string $token):Context
    {
        try{$auth=\Kulturhus\Native\Auth\Runtime::service($db);$account=$auth->resolve($token);$bridge=ManagerBridge::fromNativeSession($db,$auth,$token);if(($account['club_id']??null)!==ManagerBridge::clubKey($bridge->managerClub()))throw Problem::unauthorized();return new Context($db,$bridge,$account['id'],'session');}catch(\Throwable){throw Problem::unauthorized();}
    }
    public static function bearer(\PDO $db,#[\SensitiveParameter]string $token):Context
    {
        $tokens=self::tokens($db);$row=$tokens->resolve($token);
        $guard=static function(\PDO $connection,string $actor)use($tokens,$token,$row):void{
            $current=$tokens->resolve($token);if($current['account_id']!==$row['account_id']||$current['manager_actor']!==$actor||$current['manager_club']!==$row['manager_club'])throw Problem::unauthorized();
        };
        try{return new Context($db,new ManagerBridge($db,$row['manager_actor'],'native',$guard),$row['account_id'],'bearer',json_decode($row['scopes'],true,16,JSON_THROW_ON_ERROR));}catch(\Throwable){throw Problem::unauthorized();}
    }
}
