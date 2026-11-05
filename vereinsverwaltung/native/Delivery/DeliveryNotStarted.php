<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;
/** A local mail preflight failed before SMTP was created or contacted. */
final class DeliveryNotStarted extends \DomainException
{
    public const LABELS=['mail_delivery_disabled'=>'Versand deaktiviert','mail_configuration_missing'=>'Postfach einrichten','mail_message_invalid'=>'Nachricht prüfen'];
    public readonly bool $retryable;
    public function __construct(public readonly string $stage)
    {
        if(!isset(self::LABELS[$stage]))throw new \DomainException('Mailvorbereitung prüfen.');
        $this->retryable=$stage!=='mail_message_invalid';parent::__construct('Mailversand wurde nicht begonnen.');
    }
}
