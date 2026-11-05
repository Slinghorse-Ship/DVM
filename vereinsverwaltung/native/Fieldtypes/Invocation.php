<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** A local validator/control must return its value without printing diagnostics into HTTP output. */
final class Invocation
{
    public static function call(\Closure $callback,mixed ...$arguments):mixed
    {
        $level=ob_get_level();ob_start();
        try{$result=$callback(...$arguments);if(ob_get_contents()!=='')throw new \DomainException('Lokale Typfunktion darf keine zusätzliche Ausgabe senden.');return $result;}
        catch(\DomainException|\InvalidArgumentException $error){throw $error;}
        catch(\Throwable $error){throw new \DomainException('Lokale Typfunktion ist derzeit nicht verfügbar.',0,$error);}
        finally{while(ob_get_level()>$level)ob_end_clean();}
    }
}
