<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
/** A bounded collection must be retried with a smaller requested page size. */
final class QuerySize extends \DomainException
{
    public function __construct(){parent::__construct('Antwort zu groß. Eine kleinere Trefferzahl (limit) wählen.');}
}
