<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;
/** A failed acknowledgement is not a validation error or evidence that no change committed. */
final class AccountOperationUnconfirmed extends \RuntimeException
{
    public function __construct(\Throwable $previous){parent::__construct('Ergebnis nicht bestätigt. Aktuellen Kontostand neu öffnen und die ursprüngliche Änderung prüfen.',0,$previous);}
}
