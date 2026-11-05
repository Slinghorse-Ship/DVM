<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;

/** Keep editorial permissions and the actual configured sending purpose together. */
final class JobPurpose
{
    public static function module(string $kind): string
    {
        return match($kind){'meta','stadtlandfunk'=>'social','press'=>'press','confirm','reminder','renewal','campaign','test'=>'newsletter',default=>throw new \DomainException('Unbekannter Auftragszweck.')};
    }
}
