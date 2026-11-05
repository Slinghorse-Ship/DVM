<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/BalanceSegmentCalculation.php';

/** Human-readable accounting workpapers; never an ELSTER import or filing approval. */
final class BalanceSegmentExport
{
    private static function csv(array $rows):string
    {
        $f=fopen('php://temp','r+');
        try{fwrite($f,"\xEF\xBB\xBF");foreach($rows as $row){
            $cells=array_map(static fn($v)=>!is_int($v)&&preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".(string)$v:(string)$v,$row);
            fputcsv($f,$cells,';','"','');
        }rewind($f);return stream_get_contents($f);}finally{fclose($f);}
    }
    public static function files(array $state):array
    {
        $a=$state['allocation'];$report=$state['journal'];
        if(BalanceSegmentCalculation::rebuild($report,$a)!==$a)throw new DomainException('Aufteilung vor dem Export erneut prüfen.');
        $approved=$state['current_approval']===true&&$a['complete']&&$state['carry_current']===true;
        $status=$approved?'Aufteilung freigegeben':'ENTWURF – Aufteilung nicht freigegeben';
        $summary=[['Status','Jahr','Bereich-ID','Bereich / Betrieb','Sphäre','Vermögen Cent','Kapital und Schulden einschließlich Ergebnis Cent','Erträge Cent','Aufwendungen Cent','Ergebnis Cent']];
        $accounts=[['Bereich-ID','Bereich / Betrieb','Sphäre','Konto','SKR42-Referenz','Bezeichnung','Anfangssaldo Cent','Soll Cent','Haben Cent','Endsaldo Cent']];
        foreach($a['segments'] as $id=>$unit){
            $c=$unit['calculation'];$summary[]=[$status,$a['year'],$id,$unit['name'],$unit['sphere'],$c['asset_total_cents']??'',$c['passive_total_cents']??'',$c['income_cents']??'',$c['expense_cents']??'',$c['annual_result_cents']??''];
            foreach($c['accounts']??[] as $account=>$row)$accounts[]=[$id,$unit['name'],$unit['sphere'],$account,$row['skr_code'],$row['name'],$row['opening_cents'],$row['debit_cents'],$row['credit_cents'],$row['closing_cents']];
        }
        $proof=[['Originalbuchung','Zeile','Datum','Buchungstext','Konto','Soll / Haben','Originalbetrag Cent','Bereich-ID','Teilbetrag Cent','Zuordnungsnachweis','Status']];
        foreach(BalanceSegmentCalculation::sources($report) as $key=>$row){
            if($row['voucher']==='opening'&&isset($a['capital_carry'][$row['line']['account']]))continue;
            $assigned=$a['allocations'][$key]??null;
            foreach($assigned['parts']??[''=>''] as $id=>$amount)$proof[]=[$row['voucher'],$row['ordinal'],$row['date'],$row['description'],$row['line']['account'],$row['line']['debit_cents']>0?'Soll':'Haben',$row['cents'],$id,$amount,$assigned['reason']??'',$assigned?'Zugeordnet':'Offen'];
        }
        $capital=[['Konto','SKR42-Referenz','Bereich-ID','Bereich / Betrieb','Anfangssaldo Cent (Soll positiv, Haben negativ)','Kapital Cent','Vorjahr','Status']];
        foreach($a['capital_carry']??[] as $account=>$parts)foreach($parts as $unit=>$net)$capital[]=[$account,$report['accounts'][$account]['skr_code'],$unit,$a['units'][$unit]['name'],$net,-$net,$a['year']-1,$status];
        $snapshot=['kind'=>'balance_segment_workpaper','schema'=>1,'year'=>$a['year'],'revision'=>$state['version'],'status'=>$status,'current_approval'=>$approved,'tax_export_ready'=>false,'journal_sha256'=>$report['closed_sha256'],'carry_forward'=>$state['carry_forward'],'carry_current'=>$state['carry_current'],'history'=>$state['history'],'allocation'=>$a];
        $json=json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $readme=$status."\nJahr: ".$a['year']."\nStand: ".$state['version']."\n\nTeilbilanzen und Kontennachweise zur unveränderten Vereinsbilanz. Alle Beträge in den CSV-Dateien sind ganze Centbeträge. Leere Felder bedeuten: noch nicht berechnet, nicht null Euro.\n\nDie Freigabe betrifft ausschließlich die buchhalterische Aufteilung. Diese Dateien sind keine Steuererklärung, keine E-Bilanz und kein ELSTER-Import. Anlagenspiegel und steuerliche Überleitung sind nicht je Betrieb enthalten.\n";
        $files=['bereiche/LESEN.txt'=>$readme,'bereiche/uebersicht.csv'=>self::csv($summary),'bereiche/kontennachweis.csv'=>self::csv($accounts),'bereiche/zuordnungsnachweis.csv'=>self::csv($proof),'bereiche/datenstand.json'=>$json];
        if(!empty($a['capital_carry'])){
            $files['bereiche/kapitalvortrag.csv']=self::csv($capital);
            $files['bereiche/LESEN.txt'].="\nKapitalvortrag: Gegenläufige Kapitalbestände der Bereiche bleiben getrennt erhalten, auch wenn sie sich im Gesamtverein aufheben. Sie stammen unverändert aus dem Vorjahr; keine neue Buchung und keine Forderung oder Schuld gegenüber Dritten.\n";
        }
        if(!empty($a['transfers'])){
            $rows=[['Originalbuchung','Datum','Kapitalkonto','SKR42-Referenz','Bereich-ID','Bereich','Kapitalzugang Cent (Abgang negativ)','Nachweis','Status']];
            $dates=array_column($report['entries'],'date','id');
            foreach($a['transfers'] as $voucher=>$transfer)foreach($transfer['parts'] as $unit=>$net)$rows[]=[$voucher,$dates[$voucher],$transfer['account'],$report['accounts'][$transfer['account']]['skr_code'],$unit,$a['units'][$unit]['name'],-$net,$transfer['reason'],$status];
            $files['bereiche/mitteluebertragungen.csv']=self::csv($rows);
            $files['bereiche/LESEN.txt'].="\nInterne Mittelübertragungen: Ausdrücklich bestätigte endgültige Finanzierung zwischen Vereinsbereichen. Kein neuer Bankumsatz, keine Forderung gegen Dritte, kein zusätzlicher Aufwand oder Ertrag. Die gegenläufigen Kapitalbewegungen werden im Gesamtverein eliminiert; Bereichskontennachweise enthalten die Bewegungen. Eine steuerliche Zulässigkeit folgt daraus nicht automatisch.\n";
        }
        if(!empty($a['asset_shares'])){
            $costFields=['cost_opening_cents','additions_cents','accumulated_opening_cents','accumulated_closing_cents','book_opening_cents','impairment_cents','writeup_cents'];
            $hasCost=false;foreach($a['asset_shares'] as $id=>$_)if(($report['asset_schedule']['items'][$id]['method']??null)==='cost')$hasCost=true;
            $rows=[['Gegenstand-ID','Bezeichnung','Betrieb-ID','Betrieb','Maßstab','Einheiten','Gesamteinheiten','Anschaffungskosten am Jahresende Cent','Abschreibung im Jahr Cent','Buchwert am Jahresende Cent','Abgang Anschaffungskosten Cent','Abgang kumulierte Abschreibung Cent','Abgang Buchwert Cent','Nachweis','Status']];
            if($hasCost)$rows[0]=[...$rows[0],'Anschaffungskosten Anfang Cent','Zugänge Cent','Kumulierte Wertminderung Anfang Cent','Kumulierte Wertminderung Ende Cent','Buchwert Anfang Cent','Wertminderung im Jahr Cent','Wertaufholung im Jahr Cent'];
            foreach($a['asset_shares'] as $item=>$share)foreach($share['weights'] as $unit=>$weight){$v=$a['asset_schedules'][$unit]['items'][$item];$original=$report['asset_schedule']['items'][$item];
                $row=[$item,$original['name']??$original['receipt_number'],$unit,$a['units'][$unit]['name'],BalanceAssetAllocation::MEASURES[$share['measure']],$weight,array_sum($share['weights']),$v['cost_closing_cents'],$v['depreciation_cents'],$v['book_closing_cents'],$v['disposals_cents']??0,$v['accumulated_disposals_cents']??0,$v['book_disposals_cents']??0,$share['reason'],$status];
                if($hasCost)foreach($costFields as $field)$row[]=$v[$field]??0;$rows[]=$row;
            }
            $files['bereiche/anlagenanteile.csv']=self::csv($rows);
            $files['bereiche/LESEN.txt'].="\nAnlagenanteile: Interne Nutzungsaufteilung zwischen Betrieben desselben steuerlichen Vereinsbereichs. Kein zweiter Gegenstand, kein zusätzlicher Aufwand, keine geänderte steuerliche Vermögenszuordnung. Der Gesamtgegenstand bleibt unverändert. Die Werte müssen mit der Buchungsaufteilung abgestimmt sein.\n";
        }
        return $files;
    }
}
