<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodLedger.php';
require_once __DIR__.'/BalancePeriodAreas.php';
require_once __DIR__.'/BalancePeriodTaxReconciliations.php';

/** Working papers, not an ERiC filing. Calendar totals must never impersonate fiscal periods. */
final class BalancePeriodExport
{
    public function __construct(private PDO $db,private string $actor){}
    private static function csv(array $rows):string
    {
        $f=fopen('php://temp','r+');fwrite($f,"\xEF\xBB\xBF");
        foreach($rows as $row){$cells=array_map(static fn($v)=>!is_int($v)&&preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".(string)$v:(string)$v,$row);fputcsv($f,$cells,';','"','');}
        rewind($f);$bytes=stream_get_contents($f);fclose($f);return $bytes;
    }
    public function build(string $id):array
    {
        return BalanceReadSnapshot::run($this->db,fn()=>$this->buildSnapshot($id));
    }
    private function buildSnapshot(string $id):array
    {
        // One read snapshot: no mix of a new journal with an older report or vice versa.
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $ledger=new BalancePeriodLedger($this->db,$this->actor);$report=$ledger->report($id);$history=$ledger->history($id);
            if($report['issues'])throw new DomainException(implode(' ',$report['issues']));
            $rows=[['Zeitraum-ID','Beginn','Ende','Buchung','Datum','Bezeichnung','Nachweis','Konto','SKR42','Vereinsbereich','Soll Cent','Haben Cent','Storno von']];
            foreach($report['entries'] as $v)foreach($v['lines'] as $line)$rows[]=[$id,$report['period']['start'],$report['period']['end'],$v['id'],$v['date'],$v['description'],$v['evidence'],$line['account'],$report['accounts'][$line['account']]['skr_code'],$line['sphere'],$line['debit_cents'],$line['credit_cents'],$v['reversal_of']??''];
            $balances=[['Zeitraum-ID','Beginn','Ende','Konto','Bezeichnung','SKR42','Anfangssaldo Cent','Soll Cent','Haben Cent','Endsaldo Cent']];
            foreach($report['calculation']['accounts'] as $key=>$r)$balances[]=[$id,$report['period']['start'],$report['period']['end'],$key,$r['name'],$r['skr_code'],$r['opening_cents'],$r['debit_cents'],$r['credit_cents'],$r['closing_cents']];
            $payload=['kind'=>'balance_period_working_papers','version'=>1,'period'=>$report['period'],'id'=>$id,'state'=>$report['state'],'journal_sha256'=>$report['journal_sha256'],'tax_export_ready'=>false,'report'=>$report,'history'=>$history];
            $files=['buchungsjournal.csv'=>self::csv($rows),'kontensalden.csv'=>self::csv($balances),'pruefverlauf.json'=>BalancePeriodLedger::json($payload),
                'LESEN.txt'=>"Abschlusszeitraum: ".$report['period']['start'].' bis '.$report['period']['end']."\nBuchungsstand: ".($report['state']==='closed'?'festgeschrieben':'Entwurf')."\n\nArbeitsunterlagen zur Prüfung der Buchhaltung. Keine Steuererklärung und kein ELSTER-Importdatensatz. Dieser Zeitraum wird nicht mit einem anderen Abschluss desselben Kalenderjahres zusammengefasst. Anfangs- und Endsalden sind vorzeichenbehaftete Sollsalden. Steuerliche Bewertung und Anlagenverzeichnis gesondert abstimmen.\n"];
            $assetRows=[['Zeitraum-ID','Anlage','Bezeichnung','Anschaffungskosten Anfang Cent','Zugänge Cent','Abgänge Anschaffungskosten Cent','Anschaffungskosten Ende Cent','Kumulierte Abschreibung Anfang Cent','Abschreibung Zeitraum Cent','Abgänge kumulierte Abschreibung Cent','Kumulierte Abschreibung Ende Cent','Buchwert Anfang Cent','Abgänge Restbuchwert Cent','Buchwert Ende Cent','Abgangsdatum','Bestätigt']];
            foreach($report['asset_schedule']['items'] as $itemId=>$asset){$s=$asset['schedule'];$assetRows[]=[$id,$itemId,$asset['name'],$s['cost_opening_cents'],$s['additions_cents'],$s['disposals_cents']??0,$s['cost_closing_cents'],$s['accumulated_opening_cents'],$s['depreciation_cents'],$s['accumulated_disposals_cents']??0,$s['accumulated_closing_cents'],$s['book_opening_cents'],$s['book_disposals_cents']??0,$s['book_closing_cents'],$asset['disposal']['date']??'',$asset['posted']?'ja':'nein'];}
            $files['anlagenverzeichnis.csv']=self::csv($assetRows);$files['anlagenverzeichnis.json']=BalancePeriodLedger::json($report['asset_schedule']);
            $costRows=[['Zeitraum-ID','Anlage','Bezeichnung','Wertseite','Kosten Anfang Cent','Zugänge Cent','Kostenabgang Cent','Kosten Ende Cent','Wertminderung Anfang Cent','Wertminderung Zeitraum Cent','Wertaufholung Zeitraum Cent','Wertminderung Abgang Cent','Wertminderung Ende Cent','Wert Anfang Cent','Restwertabgang Cent','Wert Ende Cent','Originalnachweis-ID','Originalnachweis-SHA256','Bewertung-SHA256']];
            $costEvents=[['Zeitraum-ID','Anlage','Ereignis-ID','Datum','Art','Wertseite','Wertminderung Cent','Wertaufholung Cent','Kostenabgang Cent','Restwertabgang Cent','Grund','Rechtsgrundlage','Nachweis-ID','Nachweis-Revision','Nachweis-SHA256','Aufteilungsnachweis-ID','Aufteilungsnachweis-SHA256']];
            foreach($report['asset_schedule']['items'] as $itemId=>$asset){
                if(($asset['plan']['method']??null)!=='cost')continue;$value=$asset['valuation'];$original=$asset['plan']['valuation_document'];
                foreach(['book'=>'Buchwert','tax'=>'Steuerwert'] as $side=>$label){
                    $v=$value[$side];$costRows[]=[$id,$itemId,$asset['name'],$label,...array_values($v),$original['document_id'],$original['sha256'],$value['sha256']];
                    foreach($value['movements'] as $i=>$movement){$event=$value['source']['events'][$i];$v=$movement[$side];$doc=$event['document'];$split=$event['allocation_document']??null;
                        $costEvents[]=[$id,$itemId,$event['id'],$event['on'],$event['kind'],$label,$v['impairment_cents'],$v['writeup_cents'],$event['cost_cents']??0,$v['book_disposals_cents'],$event['reason'],$event['legal_basis'],$doc['document_id'],$doc['revision'],$doc['sha256'],$split['document_id']??'',$split['sha256']??''];
                    }
                }
            }
            if(count($costRows)>1){$files['anlagenbewertungen.csv']=self::csv($costRows);$files['anlagenbewertungsereignisse.csv']=self::csv($costEvents);}
            $sphereRows=[['Zeitraum-ID','Anlage','Datum','Bisheriger Bereich','Neuer Bereich','Anschaffungskosten Cent','Kumulierte Buchabschreibung Cent','Buchwert beim Wechsel Cent','Steuerwert vorher Cent','Steuerwert nachher Cent','Steuerliche Einlage Cent','Steuerliche Entnahme Cent','Steuerlicher Bewertungsgewinn/-verlust Cent','Nachweis-ID','Nachweis-SHA256']];
            $sphereTotals=[['Zeitraum-ID','Anlage','Bereich am Anfang','Bereich am Ende','Steuerwert Anfang Cent','Ursprüngliche Anschaffung steuerlich Cent','Einlagen Cent','Entnahmen Cent','Bewertungsgewinn/-verlust Cent','Steuerliche Abschreibung Cent','Steuerwert Ende Cent','Bereichsnachweis-SHA256']];
            foreach($report['asset_schedule']['items'] as $itemId=>$asset){
                if(!isset($asset['sphere_change']))continue;$proof=$asset['sphere_change'];$t=$proof['tax'];
                $sphereTotals[]=[$id,$itemId,$proof['book']['opening_sphere'],$proof['closing_sphere'],$t['opening_cents'],$t['additions_cents'],$t['contributions_cents'],$t['withdrawals_cents'],$t['valuation_result_cents'],$t['depreciation_cents'],$t['closing_cents'],$proof['sha256']];
                foreach($proof['book']['movements'] as $i=>$v){$tax=$t['movements'][$i];$doc=$proof['changes'][$i];$sphereRows[]=[$id,$itemId,$v['on'],$v['from_sphere'],$v['to_sphere'],$v['cost_cents'],$v['accumulated_cents'],$v['book_cents'],$tax['before_cents'],$tax['after_cents'],$tax['contribution_cents'],$tax['withdrawal_cents'],$tax['result_cents'],$doc['document_id'],$doc['sha256']];}
            }
            if(count($sphereTotals)>1){$files['anlagen-bereichswechsel.csv']=self::csv($sphereRows);$files['anlagen-bereichswerte.csv']=self::csv($sphereTotals);}
            $sales=[['Zeitraum-ID','Anlage','Verkaufsrechnung-ID','Übergabedatum','Brutto Cent','Umsatzsteuer Cent','Netto Cent','Restbuchwert Cent','Gewinn/Verlust Cent','Erlöskonto SKR42','Buchwertkonto SKR42']];
            foreach($report['asset_schedule']['items'] as $itemId=>$asset){
                if(!isset($asset['sale_values'])||!BalancePeriod::contains($report['period'],$asset['disposal']['date']))continue;
                $v=$asset['sale_values'];$sales[]=[$id,$itemId,$asset['disposal']['sale_entry'],$asset['disposal']['date'],$v['gross_cents'],$v['tax_cents'],$v['net_cents'],$v['book_cents'],$v['result_cents'],$v['sale_code'],$v['book_code']];
            }
            if(count($sales)>1)$files['anlagenverkaeufe.csv']=self::csv($sales);
            if($report['state']==='closed'&&Database::tableExists($this->db,'balance_period_area_reviews')){
                $club=Permissions::actor($this->db,$this->actor)['club_id'];
                if(DocumentCatalog::query($this->db,'SELECT 1 FROM balance_period_area_reviews WHERE club_id=? AND period_id=? LIMIT 1',[$club,$id])->fetchColumn()){
                    $areas=(new BalancePeriodAreas($this->db,$this->actor))->view($id);
                    if(!$areas['current_approval'])throw new DomainException('Bereichsaufteilung zuerst mit dem Buchungsstand abstimmen und bestätigen.');
                    $files['vereinsbereiche.json']=BalancePeriodLedger::json(['period'=>$report['period'],'history'=>$areas['history'],'proof'=>$areas['proof']]);
                    $areaRows=[['Zeitraum-ID','Bereich','Erträge Cent','Aufwand Cent','Ergebnis Cent','Interner Vorschuss offen Cent','Interne Rückzahlung offen Cent']];
                    foreach($areas['proof']['statement']['units'] as $u)$areaRows[]=[$id,$u['name'],$u['totals']['income_cents'],$u['totals']['expense_cents'],$u['totals']['annual_result_cents'],$u['internal']['closing_advance_cents'],$u['internal']['closing_owed_cents']];
                    $files['vereinsbereiche.csv']=self::csv($areaRows);
                    $areaBalances=[['Zeitraum-ID','Bereich','Steuerlicher Bereich','Konto','Bezeichnung','SKR42','Anfangssaldo Cent','Soll Cent','Haben Cent','Endsaldo Cent']];
                    foreach($areas['proof']['statement']['units'] as $unitId=>$unit)foreach($unit['accounts'] as $key=>$account)$areaBalances[]=[$id,$unit['name'],$unit['sphere'],$key,$account['name'],$account['skr_code'],$account['opening_cents'],$account['debit_cents'],$account['credit_cents'],$account['closing_cents']];
                    $files['bereichsbilanzen.csv']=self::csv($areaBalances);
                    if(isset($areas['proof']['assets'])){
                        $usage=$areas['proof']['assets'];$files['anlagennutzung.json']=BalancePeriodLedger::json($usage);
                        $assetAreas=[['Zeitraum-ID','Gegenstand','Bereich','Anschaffungskosten Anfang Cent','Zugänge Cent','Abgänge Cent','Anschaffungskosten Ende Cent','Abschreibung Anfang Cent','Abschreibung Zeitraum Cent','Abgang Abschreibung Cent','Abschreibung Ende Cent','Buchwert Anfang Cent','Abgang Buchwert Cent','Buchwert Ende Cent','Nutzungswechsel Anschaffungskosten Cent','Nutzungswechsel Abschreibung Cent','Nutzungswechsel Buchwert Cent']];
                        $hasCost=false;foreach($usage['items'] as $item)if(($item['plan']['method']??null)==='cost')$hasCost=true;
                        if($hasCost)$assetAreas[0]=[...$assetAreas[0],'Wertminderung Zeitraum Cent','Wertaufholung Zeitraum Cent'];
                        foreach($usage['schedules'] as $unitId=>$unit)foreach($unit['items'] as $itemId=>$v){$row=[$id,$usage['items'][$itemId]['name'],$areas['input']['units'][$unitId]['name'],$v['cost_opening_cents'],$v['additions_cents'],$v['disposals_cents'],$v['cost_closing_cents'],$v['accumulated_opening_cents'],$v['depreciation_cents'],$v['accumulated_disposals_cents'],$v['accumulated_closing_cents'],$v['book_opening_cents'],$v['book_disposals_cents'],$v['book_closing_cents'],$v['cost_transfer_cents']??0,$v['accumulated_transfer_cents']??0,$v['book_transfer_cents']??0];
                            if($hasCost)$row=[...$row,$v['impairment_cents']??0,$v['writeup_cents']??0];$assetAreas[]=$row;}
                        $files['anlagennutzung.csv']=self::csv($assetAreas);
                        if(!empty($usage['movements'])){
                            $moves=[['Zeitraum-ID','Gegenstand','Datum','Bereich','Steuerlicher Bereich','Anlagenkonto','Internes Kapitalkonto','Umbuchung Anschaffungskosten Cent','Umbuchung kumulierte Abschreibung Cent','Umbuchung Buchwert Cent','Interne Kapitalzuführung/-minderung Cent']];
                            foreach($usage['movements'] as $itemId=>$changes)foreach($changes as $change)foreach($change['parts'] as $unitId=>$v){
                                if(!array_filter($v))continue;$unit=$areas['input']['units'][$unitId];
                                $moves[]=[$id,$usage['items'][$itemId]['name'],$change['on'],$unit['name'],$unit['sphere'],$usage['items'][$itemId]['fixed_account'],$report['result_account'],$v['cost_transfer_cents'],$v['accumulated_transfer_cents'],$v['book_transfer_cents'],$v['book_transfer_cents']];
                            }
                            $files['anlagen-umbuchungen.csv']=self::csv($moves);
                        }
                    }
                    $income=(new BalancePeriodAreas($this->db,$this->actor))->incomeReconciliation($id);
                    $files['ergebnisabgleich.json']=BalancePeriodLedger::json($income);
                    $files['ergebnisabgleich.txt']=BalanceClubIncomeReconciliation::summary($income);
                    if(Database::tableExists($this->db,'balance_period_tax_reviews')&&DocumentCatalog::query($this->db,'SELECT 1 FROM balance_period_tax_reviews WHERE club_id=? AND period_id=? LIMIT 1',[$club,$id])->fetchColumn())$files+=(new BalancePeriodTaxReconciliations($this->db,$this->actor))->files($id);
                }
            }
            $manifest=['period_id'=>$id,'period'=>$report['period'],'journal_sha256'=>$report['journal_sha256'],'files'=>array_map(static fn($v)=>hash('sha256',$v),$files)];$files['dateinachweis.json']=BalancePeriodLedger::json($manifest);
            if($own)$this->db->commit();return ['files'=>$files,'snapshot'=>$payload,'hash'=>hash('sha256',BalancePeriodLedger::json($manifest))];
        }catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function zip(string $id):string
    {
        $bundle=$this->build($id);
        if(!class_exists(\ZipArchive::class))throw new DomainException('ZIP-Laufzeit fehlt.');
        $path=tempnam(sys_get_temp_dir(),'kh-period-');if($path===false)throw new DomainException('Arbeitsdatei fehlt.');
        $zip=null;$opened=false;
        try{
            if(!chmod($path,0600))throw new DomainException('Arbeitsdatei konnte nicht geschützt werden.');
            $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::OVERWRITE)!==true)throw new DomainException('ZIP konnte nicht erstellt werden.');
            $opened=true;
            foreach($bundle['files'] as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new DomainException('Datei konnte nicht gebündelt werden.');
            if(!$zip->close())throw new DomainException('ZIP konnte nicht abgeschlossen werden.');$opened=false;
            $bytes=file_get_contents($path);if($bytes===false)throw new DomainException('Export konnte nicht gelesen werden.');return $bytes;
        }finally{if($opened)$zip->close();if(is_file($path))unlink($path);}
    }
}
