<?php declare(strict_types=1);
namespace Kulturhus\Native\Cache;
use Kulturhus\Native\Shared\Access;
final class Factory
{
    /** Matches ProductionSite::PublicSite's private runtime, even when media live on another volume. */
    public static function manager(Access $access,string $mediaRoot):Administration
    {
        $root=\Kulturhus\Verein\ProductionRuntime::enabled()?(string)getenv('VEREIN_PRIVATE_ROOT'):dirname($mediaRoot);
        if($root===''||!str_starts_with($root,'/')||str_contains($root,"\0"))throw new \DomainException('Privater Websitecache nicht eingerichtet.');
        return new Administration($access,rtrim($root,'/').'/public-cache');
    }
}
