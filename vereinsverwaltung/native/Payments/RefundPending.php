<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;
/** The provider accepted a request, but an independent final read is unavailable. Never authorises replay. */
final class RefundPending extends \RuntimeException
{
    public function __construct(public readonly array $checkpoint,?\Throwable $previous=null)
    {parent::__construct('Rückzahlung ist noch nicht unabhängig bestätigt. Nur den Anbieterstatus erneut lesen.',0,$previous);}
}
