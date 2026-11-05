<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;
/** A known provider object exists; its next phase or confirmation remains pending. */
final class DeferredDelivery extends \RuntimeException
{
    public function __construct(public readonly string $reference,public readonly string $stage)
    {parent::__construct('Anbieter verarbeitet den Auftrag. Bestätigung steht aus.');}
}
