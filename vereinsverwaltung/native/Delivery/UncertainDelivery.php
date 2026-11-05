<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;
final class UncertainDelivery extends \RuntimeException
{
    public function __construct(public readonly string $reference,public readonly string $stage)
    {parent::__construct('Veröffentlichungsergebnis ungeklärt.');}
}
