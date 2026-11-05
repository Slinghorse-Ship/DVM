<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
final class Factory
{
    public static function manager(\PDO $db,string $actor,bool $session=true): Manager
    {
        $guard=null;
        if($session&&PHP_SAPI!=='cli'){
            if(!is_string($_SESSION['auth_token']??null))throw new \DomainException('Native Anmeldung erforderlich.');
            $bridge=\Kulturhus\Native\Integration\ManagerBridge::fromNativeSession($db,\Kulturhus\Native\Auth\Runtime::service($db),$_SESSION['auth_token']);
            $guard=static function()use($bridge,$actor):void{if($bridge->managerActor()!==$actor)throw new \DomainException('Aktuelle Anmeldung geändert.');};
        }
        return new Manager($db,new Access($db,$actor,$guard),Runtime::catalog());
    }
}
