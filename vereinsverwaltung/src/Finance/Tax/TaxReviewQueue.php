<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
use Throwable;

require_once dirname(__DIR__, 2).'/Finance/Invoices/InvoiceRecognition.php';

/** Derived follow-up work; deliberately no writable completion flag or tax assessment. */
final class TaxReviewQueue
{
    public function __construct(private PDO $db,private string $actor){}

    private static function reasons(string $sphere,string $account,?int $catalog,int $year):array
    {
        $out=[];
        if($sphere==='')$out['missing_sphere']='Den passenden Vereinsbereich (Sphäre) auswählen.';
        elseif(TaxCatalog::sphereOf($sphere)==='')$out['unknown_sphere']='Die nicht bekannte Sphäre prüfen und korrigieren.';
        elseif($sphere==='9')$out['technical_sphere']='Den technischen Sammelposten einem fachlichen Vereinsbereich zuordnen.';
        if($account==='')$out['missing_account']='Ein passendes Steuerkonto auswählen.';
        elseif($catalog!==$year||!TaxCatalog::hasYear($catalog))$out['unknown_catalog']='Die gespeicherte Kontenplanbasis passt nicht zu einem unterstützten Katalog des ausgewählten Jahres. Am Original und an der damaligen Buchhaltung prüfen.';
        return $out;
    }
    private static function inputTaxReasons(array $row):array
    {
        if(($row['entry_kind']??$row['kind']??'')!=='expense')return [];
        $row['entry_kind']='expense';$issues=InputTaxTreatment::exportIssues($row);
        return $issues?['input_tax_review'=>implode(' · ',$issues).'. In den Belegpositionen prüfen; Umsatzsteuer laut Original erhalten.']:[];
    }

    /**
     * Read metadata only; immutable document_files.sha256 binds the current cache.
     * XML/embedded_xml recognition caches work too. EInvoiceInspection has no
     * persisted field cache; a detail view or KoSIT report alone cannot establish
     * an invoice year here. Such files remain undated until captured/recorded.
     */
    private function inboxRows(string $club,int $year):array
    {
        $q=$this->db->prepare("SELECT d.id,CASE WHEN json_valid(r.result_json) THEN json_extract(r.result_json,'$.fields') END fields_json
            FROM documents d JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id
                AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id)
            LEFT JOIN invoice_recognition r ON r.club_id=d.club_id AND r.document_id=d.id AND r.state='ready'
                AND r.parser_version=? AND r.revision=f.revision AND r.sha256=f.sha256
            WHERE d.club_id=? AND d.state='active' AND d.origin='upload' AND d.kind='receipt'
                AND NOT EXISTS(SELECT 1 FROM document_links l WHERE l.club_id=d.club_id AND l.document_id=d.id AND l.source_type='entry')
            ORDER BY d.document_date,d.id LIMIT 20001");
        $q->execute([InvoiceRecognition::VERSION,$club]);$candidates=$q->fetchAll();
        if(count($candidates)>20000)throw new DomainException('Mehr als 20.000 offene Eingangsdateien. Die Nacharbeitsliste kann nicht vollständig angezeigt werden.');
        $documents=new Documents($this->db,$this->actor);$dated=[];$undated=[];
        foreach($candidates as $candidate){
            // Keep all document/folder/admission permissions, including mixed scopes.
            try{$doc=$documents->document($candidate['id']);}catch(DomainException){continue;}
            if(!in_array('finance',$doc['scopes'],true))continue;
            $decoded=is_string($candidate['fields_json'])?json_decode($candidate['fields_json'],true):null;
            $fields=is_array($decoded)?array_filter($decoded,static fn($value)=>is_string($value)&&strlen($value)<=500):[];
            $suggestions=InvoiceInbox::suggestions($fields);
            $date=$suggestions['document_date']??'';
            $dateKnown=$date!==''&&(int)substr($date,0,4)>=2000&&(int)substr($date,0,4)<=(int)date('Y');
            if($dateKnown&&(int)substr($date,0,4)!==$year)continue;
            $cents=null;if(isset($suggestions['amount'])){try{$cents=Finance::cents($suggestions['amount']);}catch(DomainException){}}
            $row=['id'=>'inbox:'.$doc['id'],'number'=>$suggestions['supplier_reference']??'Eingangsrechnung','title'=>$doc['title'],
                'counterparty'=>$suggestions['counterparty']??'','cents'=>$cents,'date'=>$dateKnown?$date:$doc['document_date'],
                'date_basis'=>$dateKnown?'recognition':'document_fallback','basis'=>[
                    $dateKnown?'Rechnungsdatum aus der aktuellen Erkennung; am Original noch prüfen.':'Rechnungsjahr noch ungeklärt. Angezeigt wird nur das Dokumentdatum; keine Zuordnung zum gewählten Steuerjahr.',
                    $cents===null?'Rechnungsbetrag noch am Original prüfen.':'Erkannter Bruttobetrag, noch nicht gebucht oder als Zahlung berücksichtigt.'
                ],'reasons'=>[['code'=>'unrecorded_invoice','message'=>'Rechnung noch nicht erfasst. Original und Angaben im Rechnungseingang prüfen und zuordnen.']],
                'url'=>'?'.http_build_query(['view'=>'finance','tab'=>'inbox','status'=>'open','inbox_doc'=>$doc['id']])];
            if(!$dateKnown)$row['reasons'][]=['code'=>'invoice_year_unknown','message'=>'Das Rechnungsdatum am Original prüfen; das Dokumentdatum kann nur der Eingangstag sein.'];
            if($dateKnown)$dated[]=$row;else $undated[]=$row;
        }
        return [$dated,$undated];
    }

    public function build(int $year):array
    {
        $actor=Permissions::actor($this->db,$this->actor);
        if(!in_array($actor['finance_access'],['read','write'],true))throw new DomainException('Finanzleserecht erforderlich.');
        if($year<2000||$year>(int)date('Y'))throw new DomainException('Ein Jahr zwischen 2000 und dem laufenden Jahr wählen.');
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{
            $report=(new TaxAnnualReport($this->db,$this->actor))->build($year);$rows=[];
            $query=$this->db->prepare("SELECT e.*,p.ordinal,p.sphere,p.account_code,p.catalog_year,p.tax_rate,p.booking_key,p.input_tax FROM finance_entries e LEFT JOIN accounting_positions p ON p.club_id=e.club_id AND p.entry_id=e.id WHERE e.club_id=? AND e.document_date>=? AND e.document_date<=? AND e.status!='cancelled' AND NOT EXISTS(SELECT 1 FROM finance_reversals r WHERE r.club_id=e.club_id AND r.entry_id=e.id) ORDER BY e.document_date,e.id,p.ordinal LIMIT 20001");
            $query->execute([$actor['club_id'],"$year-01-01","$year-12-31"]);$documents=$query->fetchAll();
            if(count($documents)>20000)throw new DomainException('Mehr als 20.000 Belegpositionen im Jahr. Die Nacharbeitsliste kann nicht vollständig angezeigt werden.');
            foreach($documents as $d){
                $reasons=self::reasons((string)($d['sphere']??''),(string)($d['account_code']??''),isset($d['catalog_year'])?(int)$d['catalog_year']:null,$year)+self::inputTaxReasons($d);
                if(!$reasons)continue;
                $key='entry:'.$d['id'];
                $rows[$key]??=['id'=>$key,'number'=>$d['display_number']?:sprintf('KH-%05d',$d['number']),'title'=>$d['description'],'counterparty'=>$d['counterparty'],'cents'=>(int)$d['amount_cents'],'date'=>$d['document_date'],'basis'=>[],'reasons'=>[],'url'=>'?'.http_build_query(['view'=>'finance','tab'=>'receipts','entry'=>$d['id']])];
                $rows[$key]['basis']['document']='Belegdatum im gewählten Jahr; Belegbetrag, unabhängig von der Zahlung.';
                foreach($reasons as $code=>$message)$rows[$key]['reasons'][$code.':'.($d['ordinal']??0)]=['code'=>$code,'message'=>($d['ordinal']===null?'':'Position '.$d['ordinal'].': ').$message];
            }
            foreach($report['rows'] as $r){
                $entry=(string)($r['entry_id']??'');
                $reasons=$entry===''?['unallocated_payment'=>'Diesen Kontoumsatz einem vorhandenen Beleg zuordnen oder den fehlenden Beleg erfassen.']:self::reasons((string)$r['sphere'],(string)$r['account_code'],isset($r['catalog_year'])?(int)$r['catalog_year']:null,$year)+self::inputTaxReasons($r);
                if(!$reasons)continue;
                $key=$entry!==''?'entry:'.$entry:'transaction:'.$r['transaction_id'];
                $exists=isset($rows[$key]);
                $rows[$key]??=['id'=>$key,'number'=>$r['receipt_number']?:$r['transaction_number'],'title'=>$r['description'],'counterparty'=>$r['counterparty'],'cents'=>0,'date'=>$r['payment_date'],'basis'=>[],'reasons'=>[],'url'=>'?'.http_build_query($entry!==''?['view'=>'finance','tab'=>'receipts','entry'=>$entry]:['view'=>'finance','tab'=>'bank','transaction'=>$r['transaction_id']])];
                if(!isset($rows[$key]['basis']['document']))$rows[$key]['cents']+=(int)$r['signed_cents'];
                $rows[$key]['basis']['payment']='Zahlungsdatum im gewählten Jahr; ohne Belegbetrag wird der betroffene Zahlungsanteil angezeigt.';
                // A document already lists each faulty position; avoid repeating it for each instalment.
                if(!$exists||!isset($rows[$key]['basis']['document']))foreach($reasons as $code=>$message)$rows[$key]['reasons'][$code]=['code'=>$code,'message'=>$message];
            }
            foreach($rows as &$r){$r['basis']=array_values($r['basis']);$r['reasons']=array_values($r['reasons']);}unset($r);
            [$inboxRows,$undated]=$this->inboxRows($actor['club_id'],$year);
            foreach($inboxRows as $row)$rows[$row['id']]=$row;
            uasort($rows,static fn($a,$b)=>[$a['date'],$a['id']]<=>[$b['date'],$b['id']]);
            $result=['year'=>$year,'rows'=>array_values($rows),'count'=>count($rows),'undated_rows'=>$undated,'undated_count'=>count($undated),'can_write'=>$actor['finance_access']==='write'];
            if($own)$this->db->commit();return $result;
        }catch(Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
