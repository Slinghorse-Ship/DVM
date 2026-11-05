<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceInternalClearing.php';

/** Read-only workpapers. Integration must separately authorize access and review. */
final class BalanceInternalClearingExport
{
    public static function files(array $proof):array
    {
        $p=BalanceInternalClearing::rebuild($proof);$year=$p['source']['year'];
        $rows=[['Vorgang','Zahlender Bereich','Begünstigter Bereich','Ursprungsjahr','Originalbuchung','Ursprünglicher Vorschuss Cent','Offen zum Jahresanfang Cent','Neu vorgestreckt Cent','Zurückgezahlt Cent','Noch offen Cent','Rückzahlung geplant bis','Stand am Jahresende','Nachweis']];
        $statuses=['open'=>'Offen','overdue'=>'Rückzahlungstermin überschritten','settled'=>'Ausgeglichen'];
        foreach($p['cases'] as $id=>$case)$rows[]=[$id,$p['units'][$case['from']]['name'],$p['units'][$case['to']]['name'],$case['origin_year'],$case['origin_voucher'],$case['original_cents'],$case['opening_cents'],$case['advanced_cents'],$case['repaid_cents'],$case['closing_cents'],$case['repay_by'],$statuses[$case['status']],$case['reason']];
        $movements=[['Bewegung','Vorgang','Originalbuchung','Datum','Art','Von Bereich','An Bereich','Betrag Cent','Nachweis']];
        foreach($p['movements'] as $id=>$m)$movements[]=[$id,$m['case'],$m['voucher'],$m['date'],$m['kind']==='advance'?'Vorgestreckt':'Zurückgezahlt',$p['units'][$m['from']]['name'],$p['units'][$m['to']]['name'],$m['cents'],$m['reason']];
        return [
            'verrechnungen/LESEN.txt'=>"Arbeitsnachweis interner Vorschüsse – $year\n\nENTWURF – keine Vorstandsfreigabe und keine Steuererklärung.\nAlle CSV-Beträge sind ganze Cent. Der Rückzahlungsstatus bezieht sich auf den 31.12.$year.\nDie Planung einer Rückzahlung ist keine steuerliche Fristberechnung oder Zulässigkeitsprüfung.\nBereiche gehören zum selben Verein. Keine Forderungen oder Schulden gegenüber Dritten, keine neuen Bankumsätze und keine zusätzlichen Einnahmen oder Ausgaben.\nDer Nachweis ändert keine Buchungen. Steuerliche Darstellung und Freigabe müssen gesondert geprüft werden.\n",
            'verrechnungen/offene-betraege.csv'=>self::csv($rows),
            'verrechnungen/bewegungen.csv'=>self::csv($movements),
            'verrechnungen/nachweis.json'=>json_encode($p,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
        ];
    }

    private static function csv(array $rows):string
    {
        $f=fopen('php://temp','r+');
        try{
            fwrite($f,"\xEF\xBB\xBF");
            foreach($rows as $row){
                $cells=array_map(static fn($v)=>!is_int($v)&&preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".(string)$v:(string)$v,$row);
                fputcsv($f,$cells,';','"','');
            }
            rewind($f);return stream_get_contents($f);
        }finally{fclose($f);}
    }
}
