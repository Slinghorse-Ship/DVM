<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Shared\Access;
final class Factory
{
    /** The same server-owned private runtime used by the public site and cache. */
    public static function manager(\PDO $db,Access $access,string $mediaRoot,string $csrf):Controller
    {
        $root=\Kulturhus\Verein\ProductionRuntime::enabled()?(string)getenv('VEREIN_PRIVATE_ROOT'):dirname($mediaRoot);
        if($root===''||!str_starts_with($root,'/')||str_contains($root,"\0"))throw new \DomainException('Privater Systembereich nicht eingerichtet.');
        return self::controller($db,$access,$root,$csrf);
    }
    public static function controller(\PDO $db,Access $access,string $privateRoot,string $csrf):Controller
    {return new Controller($db,new Authority($db,$access),new PrivateFiles(rtrim($privateRoot,'/')),$csrf);}
}
