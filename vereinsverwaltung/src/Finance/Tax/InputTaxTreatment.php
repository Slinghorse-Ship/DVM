<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** The invoice's VAT is evidence; deduction is a separate, explicitly reviewed decision. */
final class InputTaxTreatment
{
    public const OPTIONS=[
        'unknown'=>'Noch klären',
        'none'=>'Kein Vorsteuerabzug – Bruttobetrag bleibt Kosten/Wert',
        'deductible'=>'Vorsteuerabzug für diese Ausgabe geklärt',
    ];
    public static function validate(mixed $value,string $kind,string $bookingKey=''):string
    {
        if(!is_string($value)||!isset(self::OPTIONS[$value]))throw new DomainException('Vorsteuerbehandlung prüfen.');
        if($kind!=='expense'&&$value!=='unknown')throw new DomainException('Vorsteuerbehandlung ist nur für Ausgaben vorgesehen.');
        if($value==='none'&&$bookingKey!=='')throw new DomainException('Kein Vorsteuerabzug: keinen Vorsteuerschlüssel verwenden. Der Steuersatz der Originalrechnung bleibt erhalten.');
        return $value;
    }
    public static function exportIssues(array $row):array
    {
        if(($row['entry_kind']??'')!=='expense')return (int)($row['tax_rate']??0)>0&&($row['booking_key']??'')===''?['Steuerschlüssel für steuerpflichtige Einnahme fehlt']:[];
        $mode=$row['input_tax']??'unknown';$key=$row['booking_key']??'';
        if(!isset(self::OPTIONS[$mode]))return ['Vorsteuerbehandlung ungültig'];
        if($mode==='none')return $key!==''?['Kein Vorsteuerabzug und Vorsteuerschlüssel widersprechen sich']:[];
        if((int)($row['tax_rate']??0)===0)return $key!==''?['Steuerfreie Ausgabe darf keinen Vorsteuerschlüssel haben']:[];
        if($mode==='unknown')return ['Vorsteuerabzug der Ausgabe noch nicht ausdrücklich geklärt'];
        return $key===''?['Vorsteuerabzug gewählt: passender Vorsteuerschlüssel fehlt']:[];
    }
}
