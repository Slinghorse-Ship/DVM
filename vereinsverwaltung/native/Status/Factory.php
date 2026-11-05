<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
use Kulturhus\Native\Shared\SecretBox;
final class Factory
{
    public static function environment(\PDO $db,string $actor,?string $storage=null,bool $session=true):Environment {
        $key=(string)getenv('KH_NATIVE_PRIVATE_KEY_FILE');
        $storage??=(string)(getenv('KH_NATIVE_MANAGER_MEDIA_ROOT')?:dirname($key).'/media');
        $guard=null;
        if($session&&PHP_SAPI!=='cli'&&is_string($_SESSION['auth_token']??null)){
            $bridge=\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$_SESSION['auth_token']);
            $guard=static function()use($bridge,$actor):void{if($bridge->managerActor()!==$actor)throw new \DomainException('Aktuelle Sitzung geändert.');};
        }
        return new Environment($db,$actor,SecretBox::fromFile($key),$storage,null,$guard);
    }
}
