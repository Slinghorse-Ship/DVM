<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
require_once __DIR__.'/FinanceReports.php';
require_once __DIR__.'/DatevOutgoingCredit.php';

/** Payment journal -> EXTF 700 / booking batch 13. Explicit bank mapping, no estimates. */
final class DatevExport
{
    public function __construct(private PDO $db,private string $actor){}
    public function preview(array $input):array
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try{$result=$this->buildPreview($input);if($own)$this->db->commit();return $result;}
        catch(\Throwable $error){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    private function buildPreview(array $input):array
    {
        $finance=new Finance($this->db,$this->actor);if(!$finance->canRead())throw new DomainException('Finanzberechtigung erforderlich.');
        $options=[];foreach(['from','to','advisor','client'] as $key){$v=$input[$key]??'';if(!is_string($v))throw new DomainException('Exportangaben prüfen.');$options[$key]=trim($v);}
        if(!preg_match('/^[0-9]{4,7}$/D',$options['advisor'])||(int)$options['advisor']<1001||!preg_match('/^[0-9]{1,5}$/D',$options['client'])||(int)$options['client']<1)throw new DomainException('DATEV-Beraternummer (1001–9999999) und Mandantennummer (1–99999) eingeben.');
        $year=TaxCatalog::documentYear($options['from']);if(!TaxCatalog::hasYear($year)||TaxCatalog::documentYear($options['to'])!==$year)throw new DomainException('Beide Exportdaten müssen im selben Jahr mit einem geprüften SKR42-Kontenplan liegen.');
        $mapping=$input['bank_codes']??[];if(!is_array($mapping))throw new DomainException('Geldkontenzuordnung prüfen.');$options['bank_codes']=[];$catalog=$finance->taxAccounts($year);$keys=TaxCatalog::supportedKeys($year);
        foreach($finance->accounts() as $account){$code=$mapping[$account['id']]??($account['tax_account']??'');if(!is_string($code)||($code!==''&&(!preg_match('/^[0-9]{5}$/D',$code)||!isset($catalog[$code])||!$catalog[$code]['active']||$catalog[$code]['category']!=='current'||str_contains($catalog[$code]['function']??'','AM')||str_contains($catalog[$code]['function']??'','AV'))))throw new DomainException('Gültiges aktives SKR42-Geldkonto für '.$account['name'].' auswählen.');$options['bank_codes'][$account['id']]=$code;}
        $settlement=$input['excess_codes']??[];if(!is_array($settlement)||array_diff(array_keys($settlement),array_keys(ExcessReturnPostings::ACCOUNTS)))throw new DomainException('Rückzahlungsverrechnung im DATEV-Formular prüfen.');$options['excess_codes']=[];
        foreach(ExcessReturnPostings::ACCOUNTS as $kind=>$definition){$code=$settlement[$kind]??'';if(!is_string($code)||($code!==''&&(!preg_match('/^[0-9]{5}$/D',$code)||empty($catalog[$code]['active'])||($catalog[$code]['category']??'')!==$definition['category']||preg_match('/AM|AV/',$catalog[$code]['function']??''))))throw new DomainException('Überschussrückzahlung: Ein aktives abgestimmtes '.($kind==='payable'?'Verbindlichkeitskonto':'Forderungskonto').' ohne Steuerautomatik wählen.');$options['excess_codes'][$kind]=$code;}
        $journal=(new FinanceReports($this->db,$this->actor))->journal($options);$errors=[];$rows=[];$fundingCache=[];$club=Permissions::finance($this->db,$this->actor)['club_id'];$credits=new DatevOutgoingCredit($this->db,$this->actor);
        foreach($journal['rows'] as $r){$projected=$credits->project($r,$catalog,$keys);$r=$projected['row'];$problems=$projected['issues'];$problems=[...$problems,...$this->fundingTaxIssues($club,$r,$fundingCache)];$code=$r['debit_account_number']?:$r['credit_account_number'];$bank=$options['bank_codes'][$r['account_id']]??'';
            if($bank==='')$problems[]='SKR42-Geldkonto fehlt';
            if(!$r['entry_id'])$problems[]='Zahlung noch keinem Beleg zugeordnet';
            if(!preg_match('/^[0-9]{5}$/D',$code)||(int)$r['catalog_year']!==$year||empty($catalog[$code]['active']))$problems[]='Belegposition ohne gültige SKR42-Kontierung für das Exportjahr';
            if($bank!==''&&$bank===$code)$problems[]='Geldkonto und Gegenkonto sind identisch';
            if(TaxCatalog::sphereOf((string)$r['sphere'])==='')$problems[]='Sphäre fehlt';
            if(!preg_match('/^[A-Za-z0-9_$&%*+\/-]{1,36}$/D',$r['receipt_number']))$problems[]='Belegnummer nicht DATEV-tauglich (höchstens 36 Zeichen; keine Leerzeichen oder Umlaute)';
            $key=$r['booking_key'];$auto=str_contains($catalog[$code]['function']??'','AM')||str_contains($catalog[$code]['function']??'','AV');
            if($auto)$problems[]='Automatikkonto: steuerliche Exportzuordnung zuerst fachlich klären';
            if($key!==''&&!isset($keys[$key]))$problems[]='Steuerschlüssel nicht unterstützt';
            if(empty($r['outgoing_credit_id']))$problems=[...$problems,...InputTaxTreatment::exportIssues($r)];
            $taxKind=$r['export_tax_kind']??($r['entry_kind']==='expense'?'expense':'income');
            if($key!==''&&isset($keys[$key])&&((int)$keys[$key]['local_rate']!==$r['tax_rate']||$keys[$key]['direction']!==$taxKind))$problems[]='Belegart, Steuersatz und Schlüssel widersprechen sich';
            $text=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$r['description']));if(mb_strlen($text)>60)$problems[]='Buchungstext länger als 60 Zeichen';
            if(preg_match('/^[=+@-]/u',$text))$problems[]='Buchungstext darf nicht mit einem Tabellen-Formelzeichen beginnen';
            $row=$r+['bank_code'=>$bank,'counter_code'=>$code,'export_text'=>$text,'problems'=>$problems];$rows[]=$row;
            foreach($problems as $problem)$errors[]=['entry_id'=>$r['entry_id'],'original_entry_id'=>$r['original_entry_id']??null,'transaction_id'=>$r['transaction_id'],'number'=>$r['receipt_number']?:$r['transaction_id'],'message'=>$problem];
        }
        $excessTotal=0;foreach(DatevExcessReturns::rows($this->db,$this->actor,$options) as $row){$rows[]=$row;$excessTotal+=$row['signed_cents'];foreach($row['problems'] as $problem)$errors[]=['entry_id'=>null,'transaction_id'=>$row['transaction_id'],'number'=>$row['receipt_number'],'message'=>$problem];}
        if(count($rows)>50000)throw new DomainException('Mehr als 50.000 DATEV-Zeilen. Zeitraum eingrenzen.');
        if(!$rows)$errors[]=['entry_id'=>null,'number'=>'','message'=>'Keine Zahlungen im gewählten Zeitraum.'];
        $result=['options'=>$options,'rows'=>$rows,'errors'=>$errors,'signed_total_cents'=>$journal['signed_total_cents']+$excessTotal];$result['hash']=hash('sha256',json_encode($result,JSON_THROW_ON_ERROR));return $result;
    }
    /** Automatic DATEV tax must reproduce the immutable source interval's exact tax cents. */
    private function fundingTaxIssues(string $club,array $row,array &$cache):array
    {
        if(!$row['entry_id']||$row['tax_rate']===null||(int)$row['tax_rate']===0||$row['booking_key']==='')return [];
        $returned=!empty($row['partial_return_id']);$key=$row['transaction_id'].':'.$row['entry_id'];
        if(!$returned){
            if(!array_key_exists($key,$cache)){
                $cache[$key]=false;
                if(PaymentFunding::available($this->db)){
                    $q=$this->db->prepare('SELECT allocation_id FROM finance_allocation_funding WHERE club_id=? AND source_id=? AND entry_id=? ORDER BY allocation_id');$q->execute([$club,$row['transaction_id'],$row['entry_id']]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
                    foreach($ids as$id){$funding=PaymentFundingOriginals::read($this->db,$club,$id);if(($funding['original']['basis']??null)==='fill-after-return')$cache[$key]=true;}
                }
            }
            if(!$cache[$key])return [];
        }
        $before=$row['position_paid_before_cents'];$after=$row['position_paid_after_cents'];$rate=(int)$row['tax_rate'];$vat=static fn(int$gross):int=>intdiv($gross*$rate+intdiv(100+$rate,2),100+$rate);
        if(!is_int($before)||!is_int($after)||$before<0||$after-$before!==(int)$row['amount_cents']||$vat($after)-$vat($before)!==$vat((int)$row['amount_cents']))return ['Die Centverteilung nach einer Rückzahlung erzeugt eine Steuer-Rundungsabweichung in DATEV. Den Centausgleich fachlich gesondert prüfen; keine zusätzliche Zahlung anlegen.'];
        return [];
    }

    public function download(array $options,string $expected):string
    {
        $preview=$this->preview($options);if(!hash_equals($preview['hash'],$expected))throw new DomainException('Daten oder Zuordnungen seit der Vorschau geändert. Vorschau neu erstellen.');
        if($preview['errors'])throw new DomainException('Vor dem DATEV-Download alle Prüfpunkte der Vorschau beheben.');return self::csv($preview);
    }
    private static function csv(array $p):string
    {
        $q=static fn($s)=>'"'.str_replace('"','""',(string)$s).'"';$o=$p['options'];
        $header=[$q('EXTF'),'700','21',$q('Buchungsstapel'),'13',gmdate('YmdHis').'000','',$q('RE'),$q('Kulturhus'),$q(''),(string)(int)$o['advisor'],(string)(int)$o['client'],substr($o['from'],0,4).'0101','5',str_replace('-','',$o['from']),str_replace('-','',$o['to']),$q('Kulturhus Zahlungen'),$q(''),'1','0','0',$q('EUR'),'',$q(''),'','',$q('42'),'','',$q(''),$q('Kulturhus')];
        $columns=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/datev-v13-columns.json'),true,512,JSON_THROW_ON_ERROR);if(count($columns)!==125)throw new DomainException('DATEV-Spaltenvorlage ungültig.');
        $lines=[implode(';',$header),implode(';',array_map($q,$columns))];
        foreach($p['rows'] as $r){$v=array_fill(0,125,'');$v[0]=intdiv($r['amount_cents'],100).','.str_pad((string)($r['amount_cents']%100),2,'0',STR_PAD_LEFT);$incoming=$r['signed_cents']>0;if($r['reversal_of']||!empty($r['partial_return_id']))$incoming=!$incoming;
            $v[1]=$q($incoming?'S':'H');$v[2]=$q('EUR');$v[6]=$r['bank_code'];$v[7]=$r['counter_code'];$v[8]=$r['booking_key']!==''?$q(str_pad($r['booking_key'],4,'0',STR_PAD_LEFT)):'';$v[9]=substr($r['payment_date'],8,2).substr($r['payment_date'],5,2);$v[10]=$q($r['receipt_number']);$v[13]=$q($r['export_text']);$v[20]=$q('Kulturhus Zahlung');$v[21]=$q($r['transaction_id']);$v[36]=$q($r['sphere']);$v[113]='0';if($r['reversal_of']||!empty($r['partial_return_id']))$v[117]=$q('1');$lines[]=implode(';',$v);
        }
        // Official manual DATEV import supports UTF-8 when a BOM is present.
        return "\xEF\xBB\xBF".implode("\r\n",$lines)."\r\n";
    }
}
