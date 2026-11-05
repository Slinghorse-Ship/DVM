<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
final class Catalog
{
    public const PROBE_VERSION=2;
    public const GROUPS=['system'=>'System','social'=>'Social & Termine','payments'=>'Zahlungen','mail'=>'Mail & Push','tax'=>'Steuer & Bank'];
    public const SERVICES=[
        'application'=>['DVM','system',false], 'mysql'=>['MySQL','system',false],
        'storage'=>['Privater Speicher','system',false], 'queue'=>['Warteschlangen','system',false],
        'scheduler'=>['Geplante Dienste','system',false],
        'meta'=>['Meta · Facebook / Instagram','social',false], 'stadtlandfunk'=>['StadtLand.Funk','social',false],
        'eventbrite'=>['Eventbrite','social',false],
        'sumup'=>['SumUp','payments',true], 'stripe'=>['Stripe','payments',true], 'paypal'=>['PayPal','payments',true],
        'smtp'=>['Mailversand','mail',false], 'imap'=>['Mailempfang','mail',false], 'push'=>['Push','mail',false],
        'eric'=>['ELSTER / ERiC','tax',true], 'fints'=>['FinTS','tax',true],
    ];
    public const STATES=['ok'=>'In Ordnung','not_configured'=>'Nicht eingerichtet','disabled'=>'Deaktiviert','not_checked'=>'Nicht geprüft','restricted'=>'Eingeschränkt','down'=>'Nicht erreichbar','provider_error'=>'Anbieterstörung','application_error'=>'Anwendungsfehler','stale'=>'Nachweis veraltet'];
    public static function service(string $id):array{return self::SERVICES[$id]??throw new \DomainException('Unbekannter Dienst.');}
    public static function group(mixed $value):string {if(!is_string($value)||!isset(self::GROUPS[$value]))throw new \DomainException('Ungültige Dienstgruppe.');return $value;}
    public static function ids(mixed $value):array {if(!is_array($value)||!array_is_list($value)||count($value)>count(self::SERVICES))throw new \DomainException('Dienstauswahl prüfen.');foreach($value as$id)if(!is_string($id)||!isset(self::SERVICES[$id]))throw new \DomainException('Dienstauswahl prüfen.');return array_values(array_unique($value));}
}
