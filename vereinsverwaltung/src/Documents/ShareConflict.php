<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
/** A stale personal grant must never release a later, unreviewed original. */
final class ShareConflict extends \DomainException
{
 public function __construct(){parent::__construct('Das Dokument oder die persönlichen Freigaben wurden inzwischen geändert. Aktuelle Fassung neu öffnen und vor dem Speichern prüfen.');}
}
