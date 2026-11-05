<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Explicit §6(2) election, not a conclusion from a low invoice amount. No pool. */
final class GwgDepreciation
{
    public static function input(array $input,int $basis,string $date):array
    {
        foreach(['gwg_net','gwg_vat','gwg_deductible'] as $key)if(!is_string($input[$key]??null))throw new DomainException('Rechnungsbetrag ohne Umsatzsteuer und Vorsteueranteil prüfen.');
        if(($input['asset_reviewed']??'')!=='1'||($input['gwg_independent']??'')!=='1'||($input['gwg_no_pool']??'')!=='1')throw new DomainException('Selbstständige Nutzbarkeit und Verzicht auf den Sammelposten im Anschaffungsjahr bestätigen.');
        $data=['net_cents'=>Finance::cents($input['gwg_net']),'vat_cents'=>Finance::cents($input['gwg_vat']),
            'deductible_cents'=>Finance::cents($input['gwg_deductible']),'independent_movable'=>true,'no_pool'=>true];
        self::check($data,$basis,$date);return $data;
    }
    public static function check(mixed $data,int $basis,string $date):void
    {
        if(!is_array($data)||array_diff(array_keys($data),['net_cents','vat_cents','deductible_cents','independent_movable','no_pool']))throw new DomainException('GWG-Nachweis fehlt.');
        foreach(['net_cents','vat_cents','deductible_cents'] as $key)if(!is_int($data[$key]??null)||$data[$key]<0)throw new DomainException('GWG-Beträge prüfen.');
        if(!in_array(TaxCatalog::documentYear($date),[2025,2026],true)||$data['net_cents']<=0||$data['net_cents']>80000
            ||$data['vat_cents']>intdiv($data['net_cents']*19+50,100)||$data['deductible_cents']>$data['vat_cents']
            ||$basis!==$data['net_cents']+$data['vat_cents']-$data['deductible_cents']
            ||($data['independent_movable']??null)!==true||($data['no_pool']??null)!==true)
            throw new DomainException('Sofortabschreibung: beweglicher, selbstständig nutzbarer Gegenstand bis 800 Euro ohne enthaltene Umsatzsteuer; Kosten und Jahreswahlrecht anhand der Rechnung prüfen.');
    }
}
