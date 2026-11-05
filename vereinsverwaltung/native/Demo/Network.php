<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Call before DNS, provider credentials, files or sockets at each transport boundary. */
final class Network
{
    public static function assertAllowed(): void
    {
        if(Runtime::active())throw new \DomainException(Runtime::locale()==='en'?'External actions are disabled in the demo.':'Externe Aktionen sind in der Demo gesperrt.');
    }
}
