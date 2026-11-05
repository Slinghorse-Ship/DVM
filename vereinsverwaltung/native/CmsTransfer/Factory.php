<?php declare(strict_types=1);
namespace Kulturhus\Native\CmsTransfer;
use DomainException;
require_once dirname(__DIR__,2).'/src/Infrastructure/Runtime/ProductionRuntime.php';
final class Factory
{
    public static function privateRoot(string $mediaRoot):string
    {
        $root=\Kulturhus\Verein\ProductionRuntime::enabled()?(string)getenv('VEREIN_PRIVATE_ROOT'):dirname($mediaRoot);
        if($root===''||!str_starts_with($root,'/')||str_contains($root,"\0"))throw new DomainException('Privater CMS-Speicher nicht eingerichtet.');return rtrim($root,'/');
    }
}
