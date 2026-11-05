<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/TaxAnnualReport.php';
require_once __DIR__.'/TaxYearNotes.php';
require_once __DIR__.'/TaxDeclarationGuide.php';
require_once __DIR__.'/TaxForm2025.php';
require_once __DIR__.'/TaxFormWorkingPaper.php';
require_once __DIR__.'/TaxEricAdapter.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceLedger.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceSegments.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceSegmentExport.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceClearings.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceTaxReconciliations.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalancePeriodExport.php';

/** Recorded data and explicit annual notes; no ERiC submission or tax assessment. */
final class TaxPreparation
{
    public const ELSTER='https://www.elster.de/eportal/formulare-leistungen/alleformulare/kst';
    public function __construct(private PDO $db,private string $actor){}
    private function access(bool $write=false):array{try{$a=Permissions::actor($this->db,$this->actor);}catch(DomainException$e){throw new DomainException($e->getMessage(),403,$e);}if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanz-Leserecht für Steuerunterlagen erforderlich.',403);if($write&&$a['finance_access']!=='write')throw new DomainException('Finanz-Schreibrecht zum Speichern der Jahresunterlagen erforderlich.',403);return $a;}
    public function attachments():array
    {
        $this->access();return array_values(array_filter((new Documents($this->db,$this->actor))->listing(),fn($d)=>$d['scopes']===['finance']&&$d['origin']==='upload'&&$d['mime']==='application/pdf'));
    }
    public function attachmentsPage(string $search='',int $page=1):array
    {
        if(!mb_check_encoding($search,'UTF-8')||strlen($search)>200||$page<1||$page>1000000)throw new DomainException('Nachweissuche und Seitenangabe vollständig prüfen.',422);
        $rows=$this->attachments();$query=mb_strtolower($search,'UTF-8');if($query!=='')$rows=array_values(array_filter($rows,static fn(array$row):bool=>str_contains(mb_strtolower($row['title']."\n".$row['document_date']."\n".$row['id'],'UTF-8'),$query)));
        $count=count($rows);$pages=max(1,(int)ceil($count/6));$page=min($page,$pages);$rows=array_slice($rows,($page-1)*6,6);return compact('rows','count','page','pages','search');
    }
    public function attachmentChoice(string $id):array
    {
        $this->access();$document=(new Documents($this->db,$this->actor))->document($id);
        if($document['scopes']!==['finance']||$document['origin']!=='upload'||$document['mime']!=='application/pdf'||$document['state']!=='active')throw new DomainException('Nur aktive PDF-Unterlagen aus dem Finanzbereich auswählen.',409);
        return$document;
    }
    private static function csv(array $rows):string
    {
        $f=fopen('php://temp','r+');fwrite($f,"\xEF\xBB\xBF");
        foreach($rows as $row){$cells=array_map(static fn($v)=>!is_int($v)&&preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".(string)$v:(string)$v,$row);fputcsv($f,$cells,';','"','');}rewind($f);$result=stream_get_contents($f);fclose($f);return $result;
    }
    private static function json(mixed $value):string{return json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    public static function summaryHtml(array $snapshot,?callable $text=null):string
    {
        $taxText=$text??static fn(string $source,array $values=[]):string=>$source;
        $escape=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $money=static fn(?int $v):string=>$v===null?$taxText('Noch offen'):number_format($v/100,2,',','.').' €';
        ob_start();try{require dirname(__DIR__, 2).'/../views/Finance/Tax/tax-report-summary.php';return ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;}
    }
    public function build(int $from,int $to,string $activity,array $selected=[],array $yearNotes=[],array $guide=[]):array
    {
        $a=$this->access();if($from<2000||$to>(int)date('Y')||$to<$from||$to-$from>2)throw new DomainException('Ein bis drei Kalenderjahre zwischen 2000 und dem laufenden Jahr auswählen.');
        if(strlen($activity)>40000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$activity))throw new DomainException('Tätigkeitsbericht prüfen (maximal 40.000 Zeichen).');
        if(count($selected)>10||count(array_unique($selected,SORT_REGULAR))!==count($selected))throw new DomainException('Höchstens zehn unterschiedliche PDF-Unterlagen wählen.');
        foreach($selected as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Dokumentauswahl prüfen.');
        $manual=TaxYearNotes::normalize($yearNotes,$from,$to);$declaration=TaxDeclarationGuide::normalize($guide);
        $files=[];$reports=[];$periodReports=[];$docs=new Documents($this->db,$this->actor);$sources=[];$size=0;$rowCount=0;
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $generatedActivity=(new ActivityReport($this->db,$this->actor))->build($from,$to);
            $review=new ActivityReportReview($this->db,$this->actor);$activityDraft=$review->forPeriod($from,$to);
            $reviewedActivity=['kind'=>'tax','id'=>$activityDraft['id']??null,'version'=>(int)($activityDraft['version']??0),
                'body'=>$activityDraft['body']??$review->source($from,$to,$activity)['text'],
                'approved'=>$activityDraft!==null&&$activityDraft['supplement']===$activity&&$review->approved($activityDraft)];
            $stored=DocumentCatalog::query($this->db,"SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();$club=$stored?json_decode($stored,true,32,JSON_THROW_ON_ERROR):SettingsSchema::defaults('club');
            $tax=(new Settings($this->db,$this->actor))->section('tax');$basis=(string)($tax['data']['accounting_basis']??'euer');
            if(!in_array($basis,['euer','balance'],true))throw new DomainException('Jahresabschlussart in den Steuereinstellungen prüfen.');
            $finance=new FinanceReports($this->db,$this->actor);
            for($year=$from;$year<=$to;$year++){
                $range=['from'=>$year.'-01-01','to'=>$year.'-12-31','resolution'=>'month'];$income=$finance->income($range);$annual=(new TaxAnnualReport($this->db,$this->actor))->build($year);$journal=['rows'=>$annual['rows'],'signed_total_cents'=>$annual['totals']['result_cents'],'count'=>count($annual['rows'])];
                $rowCount+=count($journal['rows']);if($rowCount>20000)throw new DomainException('Mehr als 20.000 Journalzeilen. Die Steuerunterlagen bitte für einzelne Kalenderjahre erstellen.');
                unset($annual['rows']);$reports[$year]=['income'=>$income,'journal'=>$journal,'annual'=>$annual];$rows=[['Zahlungsdatum','Transaktion','Belegnummer','Betrag in Cent mit Vorzeichen','Steuersphäre','Sollkonto','Habenkonto','Beschreibung','Zahlungspartner','Prüfhinweis']];
                foreach($journal['rows'] as $r)$rows[]=[$r['payment_date'],$r['transaction_id'],$r['receipt_number'],$r['signed_cents'],$r['sphere'],$r['debit_account_number'],$r['credit_account_number'],$r['description'],$r['counterparty'],$r['info']];
                $files[$year.'/zahlungsjournal.csv']=self::csv($rows);$files[$year.'/auswertung.json']=self::json($income);
                $sphereRows=[['Sphäre','Bezeichnung','Einnahmen in Cent','Ausgaben in Cent','Ergebnis in Cent']];foreach($annual['spheres'] as $s)$sphereRows[]=[$s['key'],$s['label'],$s['income_cents'],$s['expense_cents'],$s['result_cents']];$files[$year.'/sphaeren.csv']=self::csv($sphereRows);
                $accountRows=[['Sphäre','Steuerkonto','Bezeichnung','Kontenplanbasis Jahr','Einnahmen in Cent','Ausgaben in Cent','Ergebnis in Cent']];foreach($annual['accounts'] as $s)$accountRows[]=[$s['sphere'],$s['account_code'],$s['account_name'],$s['catalog_year'],$s['income_cents'],$s['expense_cents'],$s['result_cents']];$files[$year.'/steuerkonten.csv']=self::csv($accountRows);
                $files[$year.'/pruefpunkte.json']=self::json($annual['issues']);$files[$year.'/jahresangaben.json']=self::json($manual[$year]);
                $balances=[['Konto','Art','Eröffnet am','Anfangsbestand in Cent','Geldbestand zum 31.12. in Cent']];$cashTotal=0;
                foreach(DocumentCatalog::query($this->db,'SELECT a.*,a.opening_cents+COALESCE((SELECT SUM(t.cents) FROM finance_transactions t WHERE t.club_id=a.club_id AND t.account_id=a.id AND t.booked_on<=?),0) closing FROM finance_accounts a WHERE a.club_id=? AND a.opened_on<=? ORDER BY a.name,a.id',[$year.'-12-31',$a['club_id'],$year.'-12-31']) as $account){$balances[]=[$account['name'],$account['kind'],$account['opened_on'],$account['opening_cents'],$account['closing']];$cashTotal+=(int)$account['closing'];}
                $reports[$year]['cash_cents']=$cashTotal;$files[$year.'/geldkonten.csv']=self::csv($balances);
                if($basis==='balance'){
                    $reports[$year]['balance']=(new BalanceLedger($this->db,$this->actor))->report($year);
                    if($reports[$year]['balance']['state']==='closed'&&Database::tableExists($this->db,'balance_closing_corrections')){
                        require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceClosingCorrections.php';
                        foreach((new BalanceClosingCorrections($this->db,$this->actor))->files($year) as $name=>$bytes)$files[$year.'/'.$name]=$bytes;
                    }
                    $sourceReview=(new BalanceSources($this->db,$this->actor))->reviewYear($year,false,$reports[$year]['balance']['calculation']);
                    $reports[$year]['balance_source_issues']=$sourceReview['issues'];
                    $files[$year.'/bilanz-quellennachweis.json']=self::json($sourceReview);
                    $accountProof=[['Konto','SKR42-Referenz','Bezeichnung','Anfangssaldo Cent','Soll Cent','Haben Cent','Endsaldo Cent']];
                    foreach($reports[$year]['balance']['calculation']['accounts']??[] as $accountId=>$account)$accountProof[]=[$accountId,$account['skr_code'],$account['name'],$account['opening_cents'],$account['debit_cents'],$account['credit_cents'],$account['closing_cents']];
                    $files[$year.'/bilanz-kontennachweis.csv']=self::csv($accountProof);
                    $assetProof=[];foreach($sourceReview['sources'] as $source)if($source['source_type']==='depreciation')$assetProof[]=$source['snapshot']['annual'];
                    $files[$year.'/bilanz-abschreibungsnachweis.json']=self::json($assetProof);
                    $assetSchedule=(new BalanceAssetSchedule($this->db,$this->actor))->build($year);
                    $reports[$year]['balance']['asset_schedule']=$assetSchedule;
                    $files[$year.'/bilanzjournal.json']=self::json($reports[$year]['balance']);
                    $reports[$year]['balance_asset_issues']=$assetSchedule['issues'];
                    $files[$year.'/bilanz-anlagenverzeichnis.json']=self::json($assetSchedule);
                    $assetRows=[['Gegenstand','Bezeichnung','Rechnungsnummer','SKR42-Referenz','Vereinsbereich','Anschaffung am','Anschaffungskosten Anfang Cent','Zugänge Cent','Anschaffungskosten Ende Cent','Kumulierte Abschreibung Anfang Cent','Jahresabschreibung Cent','Kumulierte Abschreibung Ende Cent','Buchwert Anfang Cent','Buchwert Ende Cent','Anschaffungskosten Abgang Cent','Kumulierte Abschreibung Abgang Cent','Restbuchwert Abgang Cent','Abgang am','Abgangsnachweis Dokument','Abgangsnachweis Dateifassung','Abgangsnachweis SHA256','Abgangsart','Verkaufsrechnung ID','Verkaufspreis Brutto Cent','Umsatzsteuer Verkauf Cent','Verkaufspreis Netto Cent']];
                    foreach($assetSchedule['items'] as $asset){
                        $disposal=$asset['disposal']??[];$sale=($disposal['kind']??'')==='sale';
                        $assetRows[]=[$asset['item_id'],$asset['name']??$asset['item_id'],$asset['receipt_number'],$asset['skr_code'],$asset['sphere'],$asset['acquired_on'],$asset['cost_opening_cents'],$asset['additions_cents'],$asset['cost_closing_cents'],$asset['accumulated_opening_cents'],$asset['depreciation_cents'],$asset['accumulated_closing_cents'],$asset['book_opening_cents'],$asset['book_closing_cents'],$asset['disposals_cents']??0,$asset['accumulated_disposals_cents']??0,$asset['book_disposals_cents']??0,$disposal['date']??'',$disposal['document_id']??'',$disposal['revision']??'',$disposal['sha256']??'',$disposal['kind']??'',$disposal['sale_entry']??'',$sale?$disposal['gross_cents']:'',$sale?$disposal['tax_cents']:'',$sale?$disposal['gross_cents']-$disposal['tax_cents']:''];
                    }
                    $files[$year.'/bilanz-anlagenverzeichnis.csv']=self::csv($assetRows);
                    if($reports[$year]['balance']['state']==='closed'&&!$reports[$year]['balance']['issues']&&Database::tableExists($this->db,'balance_segment_reviews')){
                        $segments=(new BalanceSegments($this->db,$this->actor))->get($year);
                        if($segments['version']>0){
                            $reports[$year]['balance_segments']=['revision'=>$segments['version'],'complete'=>$segments['allocation']['complete'],'current_approval'=>$segments['current_approval'],'carry_current'=>$segments['carry_current'],'sha256'=>$segments['allocation']['sha256'],'tax_export_ready'=>false];
                            foreach(BalanceSegmentExport::files($segments) as $name=>$bytes)$files[$year.'/'.$name]=$bytes;
                            if(Database::tableExists($this->db,'balance_clearing_reviews')){
                                $clearings=new BalanceClearings($this->db,$this->actor);$clearing=$clearings->get($year);
                                if($clearing['version']>0){
                                    $reports[$year]['balance_clearings']=['revision'=>$clearing['version'],'current_approval'=>$clearing['current_approval'],'source_current'=>$clearing['source_current'],'carry_current'=>$clearing['carry_current'],'sha256'=>$clearing['proof']['sha256'],'tax_export_ready'=>false];
                                    foreach($clearings->files($year) as $name=>$bytes)$files[$year.'/'.$name]=$bytes;
                                    if(Database::tableExists($this->db,'balance_tax_reconciliation_reviews')){
                                        $reconciliations=new BalanceTaxReconciliations($this->db,$this->actor);$reconciliation=$reconciliations->get($year);
                                        if($reconciliation['version']>0){
                                            $reports[$year]['balance_tax_reconciliation']=['revision'=>$reconciliation['version'],'sha256'=>$reconciliation['values']['sha256'],
                                                'current_approval'=>$reconciliation['current_approval'],'source_current'=>$reconciliation['source_current'],'carry_current'=>$reconciliation['carry_current'],'tax_export_ready'=>false];
                                            foreach($reconciliations->files($year) as $name=>$bytes)$files[$year.'/'.$name]=$bytes;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if($basis==='balance'&&Database::tableExists($this->db,'balance_periods')){
                $periodExport=new BalancePeriodExport($this->db,$this->actor);
                foreach((new BalancePeriodLedger($this->db,$this->actor))->periods() as $period){
                    $endingYear=(int)substr($period['ends_on'],0,4);if($endingYear<$from||$endingYear>$to)continue;
                    $bundle=$periodExport->build($period['id']);$periodReports[$period['id']]=$bundle['snapshot'];
                    foreach($bundle['files'] as $name=>$bytes)$files['abschlusszeitraeume/'.$period['id'].'/'.$name]=$bytes;
                }
            }
            foreach($selected as $id){$d=$docs->document($id);$f=$docs->file($id);if($d['scopes']!==['finance']||$d['origin']!=='upload'||$d['mime']!=='application/pdf'||$d['state']!=='active')throw new DomainException('Nur aktive PDF-Unterlagen aus dem Finanzbereich auswählen.');$size+=strlen($f['bytes']);if($size>10000000)throw new DomainException('Ausgewählte Unterlagen größer als 10 MB.');$filename='unterlagen/'.(count($sources)+1).'-'.preg_replace('/[^a-zA-Z0-9._-]/','_',$f['name']);$files[$filename]=$f['bytes'];$sources[]=['document_id'=>$id,'revision'=>$f['revision'],'sha256'=>$f['sha256'],'title'=>$d['title'],'filename'=>$filename];}
            if($own)$this->db->commit();
        }catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
        $files['taetigkeitsbericht.txt']=($reviewedActivity['approved']?'':"ENTWURF - noch nicht freigegeben\n\n").$reviewedActivity['body'];
        if($reviewedActivity['approved'])$files['taetigkeitsbericht-finanzamt.pdf']=$activityDraft['approved_pdf'];
        $files['vereins-und-steuerdaten.json']=self::json(['club'=>$club,'tax'=>$tax]);$files['fragen-zur-erklaerung.json']=self::json(['source_year'=>2025,'answers'=>$declaration]);
        $basisLabel=$basis==='balance'?'Bilanzierung':'Einnahmenüberschussrechnung (EÜR)';
        $files['LESEN.txt']="Körperschaftsteuer – Arbeitsunterlagen $from bis $to\nAbschlussart: $basisLabel\n\nDieses Paket enthält erfasste Geldbewegungen, deren Zuordnung zu Sphären und SKR42-Konten sowie ausdrücklich ergänzte Jahresangaben. Es ist keine ausgefüllte Steuererklärung und kein ELSTER-Importdatensatz. Leere Angaben bedeuten unbekannt, nicht automatisch null Euro.\n\nDie Geldkontenliste ist keine vollständige Vermögensübersicht. Forderungen, Verbindlichkeiten, Sachvermögen, Mittelverwendung und Rücklagen ergänzen und prüfen. Dokumentierte Inventarwerte sind keine automatisch ermittelten steuerlichen Buchwerte. Zahlungsüberschuss und manuelle Korrekturen ersetzen keine abschließende steuerliche Ergebnisermittlung. Das laufende Jahr ist nicht abgeschlossen.\n\nDer Fragenkatalog orientiert sich an der öffentlichen ELSTER-Hilfe 2025. Jahresabhängige Formulare und gesetzliche Grenzen für das tatsächliche Abgabejahr prüfen. Abweichende Wirtschaftsjahre werden hier nicht als Kalenderjahre umgedeutet.\n\nKSt 1 mit Anlage Gem in Mein ELSTER: ".self::ELSTER."\nDieses Paket ist keine Abgabe. Die Erklärung im Vereinsmanager oder in Mein ELSTER gesondert prüfen und übermitteln. Übermittlungsprotokoll, Erklärung und Bescheid aufbewahren.\n";
        $snapshot=['kind'=>'tax_preparation','version'=>3,'accounting_basis'=>$basis,'accounting_basis_label'=>$basisLabel,'from'=>$from,'to'=>$to,'club'=>$a['club_id'],'reports'=>$reports,'sources'=>$sources,'activity'=>$activity,'generated_activity'=>$generatedActivity,'club_data'=>$club,'tax'=>$tax,'year_notes'=>$manual,'declaration'=>$declaration,'declaration_source_year'=>2025];
        $snapshot['activity_review']=$reviewedActivity;
        if($periodReports)$snapshot['balance_periods']=$periodReports;
        $snapshot['prepared_year']=(int)date('Y');$snapshot['declaration_questions']=TaxDeclarationGuide::questions();$snapshot['declaration_issues']=TaxDeclarationGuide::issues($declaration);
        $snapshot['form_mapping']=TaxFormWorkingPaper::build($snapshot);$snapshot['eric']=TaxEricAdapter::status();$files['formularzuordnung.json']=self::json($snapshot['form_mapping']);
        $snapshot['field_labels']=['club_data'=>array_map(static fn($field)=>$field[0],SettingsSchema::fields('club')),'tax'=>array_map(static fn($field)=>$field[0],SettingsSchema::fields('tax'))];
        $style=(string)file_get_contents(dirname(__DIR__, 2).'/../public/tax-preparation.css');$files['uebersicht.html']='<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Steuer-Arbeitsunterlagen</title><style>'.$style.'</style><body><main class="tax-preparation">'.self::summaryHtml($snapshot).'</main></body></html>';
        $snapshot['file_hashes']=array_map(static fn($bytes)=>hash('sha256',$bytes),$files);$hash=hash('sha256',self::json($snapshot));$snapshot['preview_hash']=$hash;$files['datenstand.json']=self::json($snapshot);
        return ['files'=>$files,'snapshot'=>$snapshot,'hash'=>$hash];
    }
    /** Recompute and compare before storing: no new source values can silently replace the preview. */
    public function save(int $from,int $to,string $activity,array $selected,string $key,array $yearNotes=[],array $guide=[],?string $expectedHash=null,?array $annualCase=null):string
    {
        $actor=$this->access(true);
        if($annualCase!==null){$annualCase=TaxAutoPackageBinding::validate($annualCase);if($annualCase['club_id']!==$actor['club_id']||$annualCase['actor_id']!==$actor['id']||$annualCase['year']!==$to||$annualCase['reviewed_bundle_hash']!==$expectedHash||!hash_equals(TaxAutoPackageBinding::key($actor['club_id'],$actor['id'],$to,$annualCase['case_version'],$annualCase['source_hash']),$key))throw new DomainException('Ursprüngliche Jahrespaketbindung vollständig prüfen.',422);}
        $first=$this->savedRequest($key,$annualCase===null?$expectedHash:null);
        if($first){
            $snapshot=$first['snapshot'];if($annualCase!==null&&TaxAutoPackageBinding::original($first,$key)!==$annualCase)throw new DomainException('Diese Kennung gehört zu einem anderen ursprünglichen Jahrespaket.',409);
            if(($first['document']['created_by']??null)!==$actor['id']||$snapshot['from']!==$from||$snapshot['to']!==$to||$snapshot['activity']!==$activity||array_column($snapshot['sources'],'document_id')!==array_values($selected)||$snapshot['year_notes']!==TaxYearNotes::normalize($yearNotes,$from,$to)||($snapshot['declaration']??null)!==TaxDeclarationGuide::normalize($guide))throw new DomainException('Diese Kennung gehört zu anderen ursprünglichen Jahresangaben. Gespeicherten Ausgang lesen.',409);
            return$first['document']['id'];
        }
        if(!class_exists(\ZipArchive::class))throw new DomainException('ZIP-Laufzeit fehlt.');$bundle=$this->build($from,$to,$activity,$selected,$yearNotes,$guide);
        if($expectedHash!==null&&(!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)||!hash_equals($bundle['hash'],$expectedHash)))throw new DomainException('Daten oder Anlagen wurden seit der Vorschau geändert. Bitte die Vorschau erneut erstellen und prüfen.',409);
        if($annualCase!==null){unset($bundle['snapshot']['preview_hash']);$bundle['snapshot']['tax_auto_package']=$annualCase;$bundle['hash']=hash('sha256',self::json($bundle['snapshot']));$bundle['snapshot']['preview_hash']=$bundle['hash'];$bundle['files']['datenstand.json']=self::json($bundle['snapshot']);}
        $path=tempnam(sys_get_temp_dir(),'kh-tax-');if($path===false)throw new DomainException('Arbeitsdatei fehlt.');chmod($path,0600);
        try{$zip=new \ZipArchive();if($zip->open($path,\ZipArchive::OVERWRITE)!==true)throw new DomainException('ZIP konnte nicht erstellt werden.');foreach($bundle['files'] as $name=>$bytes)if(!$zip->addFromString($name,$bytes))throw new DomainException('Datei konnte nicht gebündelt werden.');if(!$zip->close())throw new DomainException('ZIP konnte nicht abgeschlossen werden.');$bytes=file_get_contents($path);}finally{if(is_file($path))unlink($path);}
        return (new Documents($this->db,$this->actor))->generated('koerperschaftsteuer-'.$from.'-'.$to.'.zip',$bytes,['title'=>'Körperschaftsteuer – Arbeitsunterlagen '.$from.'–'.$to,'kind'=>'export','scope'=>'finance','document_date'=>date('Y-m-d'),'notes'=>'Gespeicherter Vorbereitungsstand mit Jahreszahlen, Sphären und Ergänzungen. Nicht an das Finanzamt übermittelt. Finanz-Leserecht erforderlich.'],$key,$bundle['snapshot'],fn():array=>$this->access(true));
    }
    public function saved(string $id):array
    {
        $a=$this->access();$docs=new Documents($this->db,$this->actor);$d=$docs->document($id);$f=$docs->file($id);$snapshot=json_decode($f['snapshot'],true,512,JSON_THROW_ON_ERROR);
        if($d['origin']!=='generated'||$d['kind']!=='export'||$d['scopes']!==['finance']||!is_array($snapshot)||($snapshot['kind']??'')!=='tax_preparation'||!in_array((int)($snapshot['version']??0),[2,3],true)||($snapshot['club']??'')!==$a['club_id'])throw new DomainException('Dieser gespeicherte Vorbereitungsstand ist nicht verfügbar.');
        $expected=$snapshot['preview_hash']??'';$copy=$snapshot;unset($copy['preview_hash']);if(!is_string($expected)||!hash_equals($expected,hash('sha256',self::json($copy))))throw new DomainException('Der gespeicherte Datenstand konnte nicht geprüft werden.');
        return ['snapshot'=>$snapshot,'hash'=>$expected,'files'=>[],'document'=>$d,'saved_at'=>$f['created_at']];
    }

    /** Read an already accepted original before recomputing any present financial values. */
    public function savedRequest(string $key,?string $expectedHash=null):?array
    {
        $a=$this->access();
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||($expectedHash!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$expectedHash)))throw new DomainException('Ursprüngliche Kennung der Jahresunterlagen vollständig prüfen.',422);
        $q=$this->db->prepare('SELECT document_id FROM document_requests WHERE club_id=? AND request_key=?'.($this->db->inTransaction()?' FOR SHARE NOWAIT':''));
        $q->execute([$a['club_id'],$key]);$id=$q->fetchColumn();if($id===false)return null;
        $saved=$this->saved($id);$file=(new Documents($this->db,$this->actor))->file($id,1);
        if(!hash_equals($file['sha256'],hash('sha256',$file['bytes']))||$file['snapshot']!==(new Documents($this->db,$this->actor))->file($id)['snapshot'])throw new DomainException('Den unveränderten ursprünglichen Stand der Jahresunterlagen prüfen lassen.',503);
        if($expectedHash!==null&&!hash_equals($expectedHash,$saved['hash']))throw new DomainException('Diese Kennung gehört zu einer anderen ursprünglichen Jahresvorschau. Gespeicherten Ausgang lesen.',409);
        return$saved;
    }
    public function recent():array
    {
        $a=$this->access();$out=[];$docs=new Documents($this->db,$this->actor);
        $ids=DocumentCatalog::query($this->db,"SELECT d.id FROM documents d JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id AND f.revision=1 WHERE d.club_id=? AND d.kind='export' AND d.origin='generated' AND d.state='active' AND CASE WHEN json_valid(f.snapshot) THEN json_extract(f.snapshot,'$.kind') ELSE NULL END='tax_preparation' AND CASE WHEN json_valid(f.snapshot) THEN json_extract(f.snapshot,'$.version') ELSE NULL END IN (2,3) ORDER BY d.created_at DESC,d.id DESC LIMIT 12",[$a['club_id']])->fetchAll();
        foreach($ids as $row){try{$out[]=$docs->document($row['id']);}catch(DomainException){continue;}}return $out;
    }
    /** Reopen a saved working paper as a new draft; the archived file is never replaced. */
    public function editValues(string $id):array
    {
        $s=$this->saved($id)['snapshot'];$notes=[];$decimal=static fn(?int $v):string=>$v===null?'':number_format($v/100,2,',','');
        foreach($s['year_notes'] as $year=>$n){
            $row=array_intersect_key($n,array_flip(['record_completeness','record_completeness_note','valuation_note','reserves_status','funds_use_status','funds_use_note','corrections_status']));
            foreach(['receivables','liabilities','fixed_assets_book_value','other_assets'] as $key)$row[$key]=$decimal($n[$key.'_cents']??null);
            $row['reserves']=[];foreach($n['reserves'] as $r){$value=['purpose'=>$r['purpose'],'reason'=>$r['reason']];foreach(['opening','addition','use','closing'] as $key)$value[$key]=$decimal($r[$key.'_cents']);$row['reserves'][]=$value;}
            $row['corrections']=[];foreach($n['corrections'] as $c)$row['corrections'][]=['sphere'=>$c['sphere'],'direction'=>$c['direction'],'amount'=>$decimal($c['amount_cents']),'reason'=>$c['reason']];$notes[$year]=$row;
        }
        return ['from_year'=>(string)$s['from'],'to_year'=>(string)$s['to'],'activity_report'=>$s['activity'],'tax_documents'=>array_column($s['sources'],'document_id'),'tax_year_notes'=>$notes,'tax_guide'=>$s['declaration']];
    }
}
