<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceInternalMovementPostings.php';

use DomainException;
use PDO;
require_once __DIR__.'/BalanceSourceHistory.php';
require_once __DIR__.'/BalanceNonInvoiceSources.php';
require_once __DIR__.'/BalanceTaxMapping.php';
require_once __DIR__.'/BalanceIssuedInvoices.php';
require_once __DIR__.'/BalancePeriodSale.php';
require_once __DIR__.'/BalanceInvoiceClassification.php';

/** Read-only source adapter. Only BalanceLedger may commit the resulting journal entries. */
final class BalanceSources
{
    use BalanceNonInvoiceSources;
    use BalanceIssuedInvoices;
    private bool $lock=false;
    private array $projected=[];
    public function __construct(private PDO $db,private string $actorId){}
    private function actor():array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        return $a;
    }
    private function rows(string $sql,array $args):array
    {
        return DocumentCatalog::query($this->db,$sql.($this->lock?' FOR UPDATE':''),$args)->fetchAll();
    }
    private static function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
    private static function identity(string $type,string $id):void
    {
        if(!in_array($type,['invoice','transaction','depreciation'],true)||$id===''||strlen($id)>160||preg_match('/[\x00-\x1f]/',$id))throw new DomainException('Belegkennung prüfen.');
    }
    public function binding(string $type,string $id):?array
    {
        self::identity($type,$id);$a=$this->actor();
        if(isset($this->projected[$type.':'.$id]))return $this->projected[$type.':'.$id];
        $b=$this->rows('SELECT * FROM balance_sources WHERE club_id=? AND source_type=? AND source_id=?',[$a['club_id'],$type,$id])[0]??null;
        if($b)foreach(['snapshot','options','entry_ids'] as $key)$b[$key]=json_decode($b[$key],true,512,JSON_THROW_ON_ERROR);
        return $b;
    }
    private function invoice(string $club,string $id):array
    {
        $e=$this->rows('SELECT id,kind,document_date,amount_cents,description,display_number,status FROM finance_entries WHERE club_id=? AND id=?',[$club,$id])[0]??null;
        if(!$e)throw new DomainException('Rechnung nicht verfügbar.');
        // Payment flags/versions are not economic changes to the original invoice.
        $e['cancelled']=$e['status']==='cancelled';unset($e['status']);
        $positions=$this->rows('SELECT ordinal,description,gross_cents,tax_rate,tax_cents,sphere,account_code,account_category,catalog_year,booking_key,input_tax FROM accounting_positions WHERE club_id=? AND entry_id=? ORDER BY ordinal',[$club,$id]);
        $claim=$this->rows('SELECT r.id,r.contact_id,r.amount_cents,r.state,c.name AS contact_name FROM finance_reimbursement_claims r JOIN contacts c ON c.club_id=r.club_id AND c.id=r.contact_id WHERE r.club_id=? AND r.entry_id=?',[$club,$id])[0]??null;
        if($claim&&$claim['state']==='cancelled')$claim=null;
        if($claim){$claim['waived']=$claim['state']==='waived';unset($claim['state']);}
        $files=$this->rows("SELECT d.id,d.state,d.kind,d.origin,f.mime,f.revision,f.sha256 FROM document_links l JOIN documents d ON d.club_id=l.club_id AND d.id=l.document_id JOIN document_files f ON f.club_id=d.club_id AND f.document_id=d.id WHERE l.club_id=? AND l.source_type='entry' AND l.source_id=? AND f.revision=(SELECT MAX(v.revision) FROM document_files v WHERE v.club_id=d.club_id AND v.document_id=d.id) ORDER BY d.id",[$club,$id]);
        $reversals=$this->rows('SELECT entry_id,reversed_on,reason FROM finance_reversals WHERE club_id=? AND entry_id=?',[$club,$id]);
        if($reversals)$e['cancelled']=true;
        $assets=$this->rows('SELECT l.ordinal,l.item_id,i.ownership,p.version,p.payload FROM invoice_position_items l JOIN inventory_items i ON i.club_id=l.club_id AND i.id=l.item_id LEFT JOIN asset_book_plans p ON p.club_id=l.club_id AND p.item_id=l.item_id WHERE l.club_id=? AND l.entry_id=? ORDER BY l.ordinal,l.item_id',[$club,$id]);
        return ['entry'=>$e,'positions'=>$positions,'claim'=>$claim,'documents'=>$files,'assets'=>$assets];
    }
    private static function accountId(string $kind,string $id):string{return 'src_'.$kind.'_'.substr(hash('sha256',$id),0,20);}
    public function bankAccounts():array
    {
        $a=$this->actor();$out=[];
        foreach($this->rows('SELECT id,name FROM finance_accounts WHERE club_id=? ORDER BY id',[$a['club_id']]) as $bank)$out[self::accountId('bank',$bank['id'])]=['name'=>mb_substr('Bank/Kasse '.$bank['name'],0,160),'group'=>'cash','skr_code'=>''];
        return $out;
    }
    private static function account(array &$p,string $id,string $name,string $group,string $code=''):string
    {
        // SKR42 here is the original position's reference, not a released E-Bilanz mapping.
        $p['accounts'][$id]=['name'=>mb_substr($name,0,160),'group'=>$group,'skr_code'=>$code];return $id;
    }
    private static function line(string $account,int $cents,bool $debit,string $sphere=''):array
    {
        return ['account'=>$account,'debit_cents'=>$debit?$cents:0,'credit_cents'=>$debit?0:$cents,'sphere'=>$sphere];
    }
    private static function date(string $date,int $year):void
    {
        if(TaxCatalog::documentYear($date)!==$year)throw new DomainException('Leistungs- bzw. Buchungsdatum muss im gewählten Kalenderjahr liegen.');
    }
    private static function voucher(array &$p,string $date,string $description,array $lines):void
    {
        $p['vouchers'][]=['date'=>$date,'description'=>mb_substr($description,0,500),'lines'=>BalanceCalculation::lines($lines,$p['accounts'])];
    }
    /** Deterministic proposal; re-read and compare this exact hash inside the posting transaction. */
    public function preview(int $year,string $type,string $id,array $options=[],bool $lock=false):array
    {
        BalanceCalculation::year($year);self::identity($type,$id);$a=$this->actor();
        if($lock&&!$this->db->inTransaction())throw new DomainException('Quellensperre benötigt einen Speichervorgang.');
        $this->lock=$lock;
        $p=['type'=>$type,'id'=>$id,'year'=>$year,'options'=>$options,'snapshot'=>[],'accounts'=>[],'vouchers'=>[],'evidence'=>$type.': '.$id,'issues'=>[]];
        try{
            if($type==='invoice')$this->invoiceProposal($p,$a['club_id']);
            elseif($type==='transaction')$this->transactionProposal($p,$a['club_id']);
            else $this->depreciationProposal($p,$a['club_id']);
            if(!$p['vouchers']&&!AssetDisposal::nonPosting($p))throw new DomainException('Keine übernahmefähige Buchung vorhanden.');
        }catch(DomainException $e){$p['issues'][]=$e->getMessage();$p['vouchers']=[];}
        catch(\JsonException){$p['issues'][]='Gespeicherte Belegdaten sind beschädigt. Original prüfen und Bestand abstimmen.';$p['vouchers']=[];}
        $p['hash']=BalanceSourceHistory::proposalHash($p);
        return $p;
    }
    /** Same original-invoice controls, with an explicit date window and unchanged source catalog. */
    public function periodInvoice(array $period,string $id,array $options,bool $lock=false,?string $dayPolicy=null):array
    {
        $period=BalancePeriod::check($period);self::identity('invoice',$id);$a=$this->actor();
        if($lock&&!$this->db->inTransaction())throw new DomainException('Quellensperre benötigt einen Speichervorgang.');
        $previous=$this->lock;$this->lock=$lock;
        try{
            $entry=$this->rows('SELECT document_date,kind FROM finance_entries WHERE club_id=? AND id=?',[$a['club_id'],$id])[0]??null;
            if(!$entry)throw new DomainException('Rechnung nicht verfügbar.');
            $year=TaxCatalog::documentYear($entry['document_date']);
            if($entry['kind']==='contribution'){
                $years=array_unique(array_column($this->rows('SELECT year FROM member_dues_people WHERE club_id=? AND entry_id=?',[$a['club_id'],$id]),'year'));
                if(count($years)!==1)throw new DomainException('Ein eindeutiges Beitragsjahr ist erforderlich.');$year=(int)reset($years);
            }
            BalanceCalculation::year($year);
            $p=['type'=>'invoice','id'=>$id,'year'=>$year,'options'=>$options,'snapshot'=>[],'accounts'=>[],'vouchers'=>[],'evidence'=>'invoice: '.$id,'issues'=>[]];
            try{$this->invoiceProposal($p,$a['club_id'],$period,$dayPolicy);}catch(DomainException $e){$p['issues'][]=$e->getMessage();$p['vouchers']=[];}
            $p['snapshot']['source_catalog_year']=$year;return $p;
        }finally{$this->lock=$previous;}
    }
    /** Same factual donation checks; original catalog and receipt period remain distinct. */
    public function periodDonation(array $period,string $transactionId,string $entryId,array $options,bool $lock=false):array
    {
        $period=BalancePeriod::check($period);$a=$this->actor();
        if($lock&&!$this->db->inTransaction())throw new DomainException('Quellensperre benötigt einen Speichervorgang.');
        $previous=$this->lock;$this->lock=$lock;
        $p=['options'=>$options,'snapshot'=>[],'accounts'=>[],'lines'=>[],'issues'=>[]];
        try{
            $tx=$this->rows('SELECT id,account_id,booked_on,cents,reversal_of FROM finance_transactions WHERE club_id=? AND id=?',[$a['club_id'],$transactionId])[0]??null;
            $allocation=array_values(array_filter(PaymentFunding::postings($this->db,$a['club_id'],$transactionId,$lock)['rows'],static fn(array$row):bool=>$row['entry_id']===$entryId));
            $entry=$this->rows('SELECT document_date,kind FROM finance_entries WHERE club_id=? AND id=?',[$a['club_id'],$entryId])[0]??null;
            if(!$tx||!$entry||$entry['kind']!=='donation'||!$allocation||!BalancePeriod::contains($period,$tx['booked_on']))throw new DomainException('Zugeordnete Geldspende im Abschlusszeitraum fehlt.');
            if($tx['reversal_of']!==null||$this->rows('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=?',[$a['club_id'],$transactionId]))throw new DomainException('Zurückgezahlte Geldspende gesondert abstimmen.');
            $p['year']=TaxCatalog::documentYear($entry['document_date']);BalanceCalculation::year($p['year']);
            // A transaction can contain several allocation rows for the same donation.
            $combined=['entry_id'=>$entryId,'cents'=>array_sum(array_column($allocation,'cents'))];
            $this->donationPayment($p,$a['club_id'],$tx,$combined,$p['lines'],$period);
        }catch(DomainException $e){$p['issues'][]=$e->getMessage();$p['lines']=[];}
        finally{$this->lock=$previous;}
        return $p;
    }
    private function invoiceProposal(array &$p,string $club,?array $period=null,?string $dayPolicy=null):void
    {
        $o=$p['options'];
        if(array_diff(array_keys($o),['recognised_on','private_paid_on','invoice_classification'])||!is_string($o['recognised_on']??null)||!is_string($o['private_paid_on']??null))throw new DomainException('Leistungsdatum und gegebenenfalls Datum der privaten Zahlung prüfen.');
        if($period===null)self::date($o['recognised_on'],$p['year']);
        elseif(!BalancePeriod::contains($period,$o['recognised_on']))throw new DomainException('Leistungsdatum muss im gewählten Abschlusszeitraum liegen.');
        $s=$this->invoice($club,$p['id']);$p['snapshot']=$s;$e=$s['entry'];$expense=$e['kind']==='expense';
        if(!$expense&&array_key_exists('invoice_classification',$o))throw new DomainException('Die Einkaufszuordnung gehört zu einer Eingangsrechnung.');
        if($e['kind']==='contribution'){$this->contributionProposal($p,$club,$period);return;}
        if($e['kind']==='donation')throw new DomainException('Geldspenden über den zugeordneten Zahlungseingang übernehmen, nicht als offene Rechnung.');
        if($period!==null&&$this->rows('SELECT item_id FROM asset_sale_links WHERE club_id=? AND entry_id=?',[$club,$p['id']]))throw new DomainException('Anlagenverkauf ist bereits einem Kalenderabschluss zugeordnet.');
        if($e['cancelled']||!in_array($e['kind'],['expense','income'],true))throw new DomainException('Nur laufende Eingangs- und Ausgangsrechnungen übernehmen. Beiträge, Spenden und Stornos gesondert prüfen.');
        $issuedOriginal=$this->issuedOriginal($p,$club);
        $periodSale=$this->rows('SELECT item_id FROM balance_period_asset_sales WHERE club_id=? AND entry_id=?',[$club,$p['id']]);
        if($period===null&&$periodSale)throw new DomainException('Verkaufsrechnung gehört zu einem fest zugeordneten Abschlusszeitraum.');
        $sale=$period===null?$this->saleEvidence($p,$club):(new BalancePeriodSale($this->db,$this->actorId))->invoice($club,$p['id'],$period,$o['recognised_on'],$this->lock);
        if($sale&&$period!==null)$p['snapshot']['period_sale']=$sale;
        $originals=array_filter($s['documents'],static fn($d)=>$d['id']===$issuedOriginal||($d['state']==='active'&&$d['origin']==='upload'&&in_array($d['mime'],['application/pdf','application/xml','image/jpeg','image/png','image/heic','image/heif'],true)));
        if(!$originals)throw new DomainException('Aktiver Originalbeleg zur Rechnung fehlt.');
        foreach($s['documents'] as $d)(new Documents($this->db,$this->actorId))->document($d['id']);
        if(!$s['positions']||count($s['positions'])>50||array_sum(array_column($s['positions'],'gross_cents'))!==(int)$e['amount_cents'])throw new DomainException('Rechnungspositionen und Gesamtsumme stimmen nicht überein.');
        $tax=$this->rows("SELECT payload FROM club_settings WHERE club_id=? AND section='tax'",[$club])[0]??null;
        $tax=$tax?json_decode($tax['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $deducts=(bool)array_filter($s['positions'],static fn($v)=>$v['input_tax']==='deductible');
        $p['snapshot']['input_tax_eligibility']=$deducts?($tax['input_tax_eligibility']??'unknown'):'not_claimed';
        $classification=[];
        if(array_key_exists('invoice_classification',$o)){
            if(!$expense)throw new DomainException('Die Einkaufszuordnung gehört zu einer Eingangsrechnung.');
            $classification=BalanceInvoiceClassification::resolve($p['year'],$s['positions'],$o['invoice_classification']);
            if($classification)$p['snapshot']['invoice_classification']=$classification;
        }
        $lines=[];$assetAccounts=[];$revenueRates=$expense?[]:BalanceTaxMapping::revenueRates($p['year']);
        foreach($s['positions'] as $pos){
            $pos['sphere']=TaxCatalog::sphereOf((string)$pos['sphere']);
            // Derived posting only: the issued invoice and its original position stay untouched.
            if($sale&&$period!==null){$pos['account_code']=$sale['sale_code'];$pos['account_category']=TaxCatalog::accounts($p['year'])[$sale['sale_code']]['category']??'';$pos['booking_key']='';}
            $code=$pos['account_code'];$catalog=TaxCatalog::accounts($p['year']);$r=$catalog[$code]??null;
            if((int)$pos['catalog_year']!==$p['year']||!$r||!$r['active']||$pos['account_category']!==$r['category']||!in_array($pos['sphere'],['1','2','3','4'],true))throw new DomainException('Jahresbezogene SKR42-Zuordnung und Vereinsbereich der Positionen prüfen.');
            if($period!==null){
                $endYear=(int)substr($period['end'],0,4);$target=TaxCatalog::accounts($endYear)[$code]??null;
                if(!$target||!$target['active']||array_intersect_key($target,array_flip(['name','category','function']))!==array_intersect_key($r,array_flip(['name','category','function'])))throw new DomainException('Das SKR42-Konto hat sich zwischen Belegjahr und Abschlussjahr geändert. Kontenüberleitung gesondert prüfen.');
                if($pos['booking_key']!==''&&(TaxCatalog::supportedKeys($p['year'])[$pos['booking_key']]??null)!==(TaxCatalog::supportedKeys($endYear)[$pos['booking_key']]??null))throw new DomainException('Der Steuerschlüssel hat sich zum Abschlussjahr geändert. Überleitung prüfen.');
                $p['snapshot']['period_catalog'][$code]=$target;
            }
            $group=$sale?$sale['group']:match($r['category']){'fixed'=>'fixed_assets','expense'=>$expense?'expense':'','revenue'=>!$expense?'revenue':'',default=>''};
            if($group===''||(!$expense&&$group==='fixed_assets'))throw new DomainException('Diese Kostenart benötigt eine gesonderte Bilanzzuordnung.');
            if(isset($revenueRates[$code])&&$revenueRates[$code]!==((int)$pos['tax_rate']))throw new DomainException('Erlöskonto '.$code.' und Umsatzsteuersatz der Rechnungsposition stimmen nicht überein.');
            if(!$sale&&in_array($code,['48450','48490','68850','68890'],true))throw new DomainException('Verkaufsrechnung zuerst mit dem vollständigen Anlagenabgang verknüpfen.');
            $automaticRevenue=!$expense&&$r['function']==='AM'&&(isset($revenueRates[$code])||$sale);
            if($automaticRevenue&&$pos['booking_key']!=='')throw new DomainException('Ein Automatikkonto benötigt keinen zusätzlichen Steuerschlüssel. Kontierung prüfen.');
            $issues=$automaticRevenue?[]:InputTaxTreatment::exportIssues($pos+['entry_kind'=>$e['kind']]);if($issues)throw new DomainException(implode(' ',$issues));
            $gross=(int)$pos['gross_cents'];$vat=(int)$pos['tax_cents'];
            if($expense&&$pos['input_tax']==='deductible'&&$p['snapshot']['input_tax_eligibility']!=='yes')throw new DomainException('Vorsteuerabzug widerspricht den Vereinseinstellungen oder ist noch ungeklärt.');
            $separate=$expense?$pos['input_tax']==='deductible':$vat>0;
            $net=$gross-($separate?$vat:0);if($net<=0)throw new DomainException('Bemessungsgrundlage der Position prüfen.');
            if($separate&&$vat>0){
                if(!$automaticRevenue){
                    $rule=TaxCatalog::supportedKeys($p['year'])[$pos['booking_key']]??null;
                    if(!$rule||$rule['direction']!==($expense?'expense':'income')||$rule['local_rate']!==(int)$pos['tax_rate'])throw new DomainException('Steuerschlüssel benötigt eine gesonderte Prüfung.');
                }
                $vatAccount=self::account($p,$expense?'src_input_vat':'src_output_vat',$expense?'Abziehbare Vorsteuer':'Umsatzsteuer',$expense?'other_assets':'liabilities',$expense?'14000':'38000');
                $lines[]=self::line($vatAccount,$vat,$expense);
            }
            if(isset($classification[(int)$pos['ordinal']])){
                // All VAT validation above uses the unchanged original position. Only
                // the derived journal account is narrowed; never book VAT twice.
                $code=$classification[(int)$pos['ordinal']]['account_code'];$r=$catalog[$code];
                if($period!==null){
                    $endYear=(int)substr($period['end'],0,4);$target=TaxCatalog::accounts($endYear)[$code]??null;
                    if(!$target||!$target['active']||array_intersect_key($target,array_flip(['name','category','function']))!==array_intersect_key($r,array_flip(['name','category','function'])))throw new DomainException('Die abgeleitete Zuordnung hat sich zum Abschlussjahr geändert. Überleitung prüfen.');
                    $p['snapshot']['period_catalog'][$code]=$target;
                }
            }
            $account=self::account($p,'src_code_'.$code,'SKR42 '.$code.' · '.$r['name'],$group,$code);
            $lines[]=self::line($account,$net,$expense,$group==='fixed_assets'?'':$pos['sphere']);
            if($group==='fixed_assets'){
                $assets=array_values(array_filter($s['assets'],static fn($v)=>(int)$v['ordinal']===(int)$pos['ordinal']));$basis=0;
                if(!$assets)throw new DomainException('Anschaffung zuerst mit Gegenständen und geprüften Buchwertplänen verknüpfen.');
                foreach($assets as $asset){
                    $links=$this->rows('SELECT entry_id,ordinal FROM invoice_position_items WHERE club_id=? AND item_id=? ORDER BY entry_id,ordinal',[$club,$asset['item_id']]);
                    if(count($links)!==1||isset($assetAccounts[$asset['item_id']]))throw new DomainException('Gegenstand ist mehreren Anschaffungspositionen zugeordnet. Doppelten Zugang zuerst klären.');
                    $plan=$asset['payload']?json_decode($asset['payload'],true,64,JSON_THROW_ON_ERROR):null;
                    if(!$plan||$asset['ownership']!=='club'||$plan['account']!==$code||$plan['sphere']!==$pos['sphere']||$plan['acquired_on']!==$o['recognised_on'])throw new DomainException('Anschaffungsdatum, Eigentum und Buchwertplan des Gegenstands prüfen.');
                    if(($plan['method']??null)==='substance_original'){require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSubstance.php';OriginalSubstance::plan($plan);if($period!==null)throw new DomainException('Substanzverzehr: Zeitraum gesondert prüfen.');$substanceEvidence=new AssetValuationEvidence($this->db,$this->actorId);$substanceEvidence->acquisition($plan['acquisition_document'],$p['id'],$this->lock);$substanceEvidence->check($plan['estimate_document'],$this->lock);}
                    if(($plan['method']??null)==='cost')(new AssetValuationEvidence($this->db,$this->actorId))->acquisition($plan['valuation_document'],$p['id'],$this->lock);
                    if(($plan['method']??null)===PartnershipBookPlans::METHOD){
                        if($period!==null)throw new DomainException('HGB-Beteiligungsregister zunächst an den tatsächlichen Abschlusszeitraum anbinden.');
                        PartnershipBookPlans::evidence($this->db,$this->actorId,$plan,null,$this->lock);
                        (new AssetValuationEvidence($this->db,$this->actorId))->acquisition($plan['partnership_plan']['document'],$p['id'],$this->lock);
                    }
                    if($period!==null){require_once __DIR__.'/BalancePeriodAssets.php';BalancePeriodAssets::calculate($plan,$period,null,$dayPolicy);}
                    else AssetBooks::schedule($plan,$p['year']); // Validate the method before capitalising, not only at year-end.
                    if(($plan['method']??'')==='gwg_immediate'){
                        GwgDepreciation::check($plan['gwg']??null,$plan['basis_cents'],$plan['acquired_on']);
                        if(count($assets)!==1||$plan['gwg']['net_cents']!==$gross-$vat||$plan['gwg']['vat_cents']!==$vat||$plan['gwg']['deductible_cents']!==($separate?$vat:0))throw new DomainException('GWG einzeln einer Rechnungsposition zuordnen; Nettokosten und Vorsteuer müssen mit der Rechnung übereinstimmen.');
                    }
                    $basis+=(int)$plan['basis_cents'];$assetAccounts[$asset['item_id']]=$account;
                }
                if($basis!==$net)throw new DomainException('Buchwertgrundlagen der Gegenstände müssen genau dem Anschaffungsbetrag entsprechen.');
            }
        }
        $claim=$s['claim'];$member=null;
        $settlement=self::account($p,self::accountId($expense?'pay':'recv',$p['id']),($expense?'Verbindlichkeit ':'Forderung ').$e['display_number'],$expense?'liabilities':'receivables');
        if($claim){
            if(!$expense||$claim['waived']||(int)$claim['amount_cents']!==(int)$e['amount_cents'])throw new DomainException('Abweichende Erstattung oder Verzicht gesondert prüfen.');
            if($period===null?TaxCatalog::documentYear($o['private_paid_on'])!==$p['year']:!BalancePeriod::contains($period,$o['private_paid_on']))throw new DomainException('Tatsächliches Datum der privaten Zahlung im Abschlusszeitraum angeben. Zeitraumübergreifende Vorleistung gesondert abgrenzen.');
            if($o['private_paid_on']<$o['recognised_on'])throw new DomainException('Private Vorauszahlung vor Lieferung/Leistung gesondert abgrenzen.');
            $member=self::account($p,self::accountId('member',$p['id']),'Erstattung '.$e['display_number'],'member_payables');
        }elseif($o['private_paid_on']!=='')throw new DomainException('Keine private Vorleistung zu dieser Rechnung vorhanden.');
        $direct=$member!==null&&$o['private_paid_on']===$o['recognised_on'];
        $lines[]=self::line($direct?$member:$settlement,(int)$e['amount_cents'],!$expense);
        self::voucher($p,$o['recognised_on'],'Rechnung '.$e['display_number'].' · '.$e['description'],$lines);
        if($member!==null&&!$direct)self::voucher($p,$o['private_paid_on'],'Private Zahlung '.$e['display_number'],[self::line($settlement,(int)$e['amount_cents'],true),self::line($member,(int)$e['amount_cents'],false)]);
        $p['snapshot']['settlement_account']=$member??$settlement;$p['snapshot']['asset_accounts']=$assetAccounts;
    }
    private function saleEvidence(array &$p,string $club):?array
    {
        $link=$this->rows('SELECT item_id,year FROM asset_sale_links WHERE club_id=? AND entry_id=?',[$club,$p['id']])[0]??null;
        if(!$link)return null;
        $annual=$this->rows('SELECT snapshot FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?',[$club,$link['item_id'],$link['year']])[0]??null;
        if(!$annual)throw new DomainException('Jahresstand zum Anlagenverkauf fehlt.');
        [$plan,$proof]=AssetDisposal::unpack(json_decode($annual['snapshot'],true,512,JSON_THROW_ON_ERROR));
        if(!$proof||$proof['kind']!=='sale'||$proof['sale_entry']!==$p['id']||(int)$link['year']!==$p['year']||$proof['date']!==$p['options']['recognised_on'])
            throw new DomainException('Verkaufsrechnung und Anlagenabgang sind nicht abgestimmt.');
        AssetDisposal::check($plan,$proof);
        if(!hash_equals($proof['sale_hash'],AssetSale::hash($p['snapshot'])))throw new DomainException('Verkaufsrechnung oder Zuordnung wurde nach dem Anlagenabgang verändert.');
        $values=AssetSale::amounts($plan,$proof['date'],$proof['gross_cents'],$proof['rate'],$proof['vat_kind']??'domestic');
        if(count($p['snapshot']['positions'])!==1||$p['snapshot']['positions'][0]['account_code']!==$values['sale_code'])
            throw new DomainException('Erlöskonto und Ergebnis des Anlagenverkaufs stimmen nicht überein.');
        $p['snapshot']['asset_sale']=['item_id'=>$link['item_id'],'year'=>(int)$link['year'],'disposal'=>$proof];
        return $values;
    }
    private function transactionProposal(array &$p,string $club):void
    {
        if(array_diff(array_keys($p['options']),['donation_use'])||(isset($p['options']['donation_use'])&&!in_array($p['options']['donation_use'],['unrestricted','needs_review'],true)))throw new DomainException('Angaben zur Verwendung der Geldspenden prüfen.');
        $tx=$this->rows('SELECT id,account_id,booked_on,cents,reversal_of FROM finance_transactions WHERE club_id=? AND id=?',[$club,$p['id']])[0]??null;
        if(!$tx)throw new DomainException('Bankumsatz nicht verfügbar.');
        self::date($tx['booked_on'],$p['year']);
        $reversals=$this->rows('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=?',[$club,$p['id']]);
        if($tx['reversal_of']!==null||$reversals)throw new DomainException('Stornierten Bankumsatz gesondert abstimmen.');
        $postings=PaymentFunding::postings($this->db,$club,$p['id'],$this->lock);$alloc=$postings['rows'];
        $excess=ExcessReturnPostings::movement($this->db,$this->actorId,$p['id'],$this->lock);
        $internal=BalanceInternalMovementPostings::movement($this->db,$this->actorId,$p['id'],$this->lock);
        if((!$alloc&&!$excess['amount']&&!$internal['amount'])||array_sum(array_map(static fn(array$row):int=>abs((int)$row['cents']),$alloc))+$excess['amount']+$internal['amount']!==abs((int)$tx['cents']))throw new DomainException('Bankumsatz zuerst vollständig Belegen oder tatsächlichen Überschussrückzahlungen zuordnen.');
        $p['snapshot']=['transaction'=>$tx,'allocations'=>$alloc,'invoices'=>[]];
        $bank=self::accountId('bank',$tx['account_id']);$p['accounts'][$bank]=$this->bankAccounts()[$bank]??throw new DomainException('Bankkonto nicht verfügbar.');
        $lines=[self::line($bank,abs((int)$tx['cents']),(int)$tx['cents']>0)];
        if($internal['amount']){$p['snapshot']['internal_movements']=$internal['snapshot'];$p['accounts']+=$internal['accounts'];array_push($lines,...$internal['lines']);}
        if($postings['returns'])$p['snapshot']['partial_returns']=$postings['returns'];
        if($excess['amount']){$p['snapshot']['excess_returns']=$excess['snapshot'];$p['accounts']+=$excess['accounts'];array_push($lines,...$excess['lines']);}
        foreach($alloc as $allocation){
            $kind=$this->rows('SELECT kind FROM finance_entries WHERE club_id=? AND id=?',[$club,$allocation['entry_id']])[0]['kind']??null;
            if($kind==='donation'){$this->donationPayment($p,$club,$tx,$allocation,$lines);continue;}
            $binding=$this->binding('invoice',$allocation['entry_id']);
            if(!$binding||(int)$binding['tax_year']>$p['year'])throw new DomainException('Zugeordnete Rechnung zuerst in das Bilanzjournal übernehmen; Vorauszahlungen gesondert behandeln.');
            $check=$this->preview((int)$binding['tax_year'],'invoice',$allocation['entry_id'],$binding['options'],$this->lock);
            if($check['issues']||!hash_equals($binding['source_hash'],$check['hash'])||!$this->intact($club,$binding,$check['vouchers']))throw new DomainException('Zugeordnete Rechnung wurde geändert oder storniert. Zuerst das Bilanzjournal abstimmen.');
            $e=$binding['snapshot']['entry'];$claim=$binding['snapshot']['claim'];
            if((((int)$tx['cents']<0)!==($e['kind']==='expense'))!==((int)$allocation['cents']<0))throw new DomainException('Zahlungsrichtung und Rechnung stimmen nicht überein.');
            if($tx['booked_on']<$binding['options']['recognised_on']||($claim&&$tx['booked_on']<$binding['options']['private_paid_on']))throw new DomainException('Zahlung liegt vor der zugehörigen Leistung oder privaten Vorleistung.');
            if(PaymentFunding::paid($this->db,$club,$allocation['entry_id'],(int)$e['amount_cents'],$this->lock)>(int)$e['amount_cents'])throw new DomainException('Zahlungszuordnung überschreitet den Rechnungsbetrag.');
            $key=$binding['snapshot']['settlement_account'];$account=$check['accounts'][$key];$p['accounts'][$key]=$account;
            $lines[]=self::line($key,abs((int)$allocation['cents']),(int)$tx['cents']<0);
            $p['snapshot']['invoices'][$allocation['entry_id']]=['hash'=>$binding['source_hash'],'account'=>$key];
        }
        if($p['options']&&!isset($p['snapshot']['donations']))throw new DomainException('Dieser Umsatz enthält keine Geldspende. Vorschau neu öffnen.');
        self::voucher($p,$tx['booked_on'],$internal['amount']?($alloc||$excess['amount']?'Zahlung und eigene Umbuchung':'Umbuchung zwischen eigenen Konten'):(isset($p['snapshot']['donations'])?'Zahlung zu Belegen':'Zahlung zu Rechnungen'),$lines);
    }
    private function depreciationProposal(array &$p,string $club):void
    {
        if($p['year']>=(int)date('Y'))throw new DomainException('Abschreibungen erst für abgeschlossene Kalenderjahre übernehmen.');
        if($p['options']!==[])throw new DomainException('Abschreibung wird aus dem festgestellten Jahresstand übernommen.');
        $suffix=':'.$p['year'];
        if(!str_ends_with($p['id'],$suffix))throw new DomainException('Jahr der Abschreibungskennung stimmt nicht überein.');
        $item=substr($p['id'],0,-strlen($suffix));
        $row=$this->rows('SELECT * FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?',[$club,$item,$p['year']])[0]??null;
          if(!$row)throw new DomainException('Festgestellter Abschreibungs-Jahresstand fehlt.');
          $valuationSnapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
          if(isset($valuationSnapshot['substance'])){require_once __DIR__.'/BalanceSubstanceSources.php';(new BalanceSubstanceSources($this->db,$this->actorId))->annual($p,$row,$this->lock);return;}
          if(isset($valuationSnapshot['exceptional'])){
              require_once __DIR__.'/BalanceExceptionalSources.php';(new BalanceExceptionalSources($this->db,$this->actorId))->annual($p,$row,$this->lock);return;
          }
          if(isset($valuationSnapshot['own_book'])){
              require_once __DIR__.'/BalanceOwnIntangibleSources.php';(new BalanceOwnIntangibleSources($this->db,$this->actorId))->annual($p,$row,$this->lock);return;
          }
          if(isset($valuationSnapshot['partnership_book'])){
              require_once __DIR__.'/BalancePartnershipBookSources.php';(new BalancePartnershipBookSources($this->db,$this->actorId))->annual($p,$row,$this->lock);return;
          }
          if(isset($valuationSnapshot['valuation'])){
              require_once __DIR__.'/BalanceCostAssetSources.php';(new BalanceCostAssetSources($this->db,$this->actorId))->annual($p,$row,$this->lock);return;
          }
        [$plan,$disposal]=AssetDisposal::unpack(json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR));
        $schedule=AssetDisposal::schedule($plan,$p['year'],$disposal);
        $partial=($disposal['kind']??null)==='partial_scrap';$partialRecord=null;
        if($disposal){
            if(!$partial&&TaxCatalog::documentYear($disposal['date'])!==$p['year'])throw new DomainException('Abgang und Jahresstand gehören nicht zum selben Jahr.');
            $document=(new Documents($this->db,$this->actorId))->document($disposal['document_id']);
            if($document['state']!=='active'||!in_array('finance',$document['scopes'],true)||(int)$document['revision']!==$disposal['revision']||!hash_equals($document['sha256'],$disposal['sha256']))throw new DomainException('Ausmusterungsnachweis wurde verändert. Originalstand prüfen.');
            if($disposal['kind']==='software_end'){
                $file=$this->rows('SELECT SHA2(bytes,256) actual_sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=?',[$club,$disposal['document_id'],$disposal['revision']])[0]??null;
                if(!$file||!hash_equals($disposal['sha256'],$file['actual_sha256']))throw new DomainException('Originalnachweis zum Lizenzende ist beschädigt oder fehlt.');
            }
            if($partial){
                require_once dirname(__DIR__, 2).'/Finance/Assets/AssetPartialDisposalRecords.php';
                $partialRecord=(new AssetPartialDisposalRecords($this->db,$this->actorId))->forAnnual($row);
                foreach([$disposal,$disposal['allocation']] as $ref){
                    $doc=(new Documents($this->db,$this->actorId))->document($ref['document_id']);
                    $file=$this->rows('SELECT sha256,SHA2(bytes,256) actual_sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=?',[$club,$ref['document_id'],$ref['revision']])[0]??null;
                    if($doc['state']!=='active'||!in_array('finance',$doc['scopes'],true)||(int)$doc['revision']!==$ref['revision']||!$file||!hash_equals($ref['sha256'],$file['sha256'])||!hash_equals($ref['sha256'],$file['actual_sha256']))throw new DomainException('Originalnachweise des Teilabgangs wurden verändert.');
                }
                $eventYear=TaxCatalog::documentYear($disposal['date']);
                if($eventYear<$p['year']){
                    $eventId=$item.':'.$eventYear;$event=$this->binding('depreciation',$eventId);
                    $eventCheck=$this->preview($eventYear,'depreciation',$eventId,[],$this->lock);
                    if(!$event||$eventCheck['issues']||!hash_equals($event['source_hash'],$eventCheck['hash'])||!$this->intact($club,$event,$eventCheck['vouchers']))throw new DomainException('Ursprünglicher Teilabgang ist nicht vollständig gebucht.');
                }
            }
        }
        foreach(['opening_cents','depreciation_cents','closing_cents'] as $key)if((int)$row[$key]!==$schedule[$key])throw new DomainException('Abschreibungsstand stimmt nicht mit dem Buchwertplan überein.');
        $links=$this->rows('SELECT entry_id FROM invoice_position_items WHERE club_id=? AND item_id=? ORDER BY entry_id',[$club,$item]);
        if(count($links)!==1)throw new DomainException('Gegenstand muss genau einer übernommenen Anschaffungsrechnung zugeordnet sein.');
        $b=$this->binding('invoice',$links[0]['entry_id']);
        if(!$b||(int)$b['tax_year']>$p['year'])throw new DomainException('Anschaffungsrechnung zuerst in das Bilanzjournal übernehmen.');
        $check=$this->preview((int)$b['tax_year'],'invoice',$links[0]['entry_id'],$b['options'],$this->lock);
        $account=$b['snapshot']['asset_accounts'][$item]??null;
        if(!$account||$check['issues']||!hash_equals($b['source_hash'],$check['hash'])||!$this->intact($club,$b,$check['vouchers']))throw new DomainException('Anschaffung oder Buchwertplan geändert. Zuerst abstimmen.');
        $asset=array_values(array_filter($b['snapshot']['assets'],static fn($v)=>$v['item_id']===$item))[0]??null;
        if(!$asset||json_decode($asset['payload'],true,64,JSON_THROW_ON_ERROR)!==$plan||(int)$asset['version']!==(int)$row['plan_version'])throw new DomainException('Festgestellte Abschreibung und Anschaffungsplan stimmen nicht überein.');
        $p['snapshot']=['annual'=>$row,'invoice_hash'=>$b['source_hash']];$p['accounts'][$account]=$check['accounts'][$account];
        if($partialRecord){
            $purchase=$partialRecord['review']['purchase'];
            if(!hash_equals($purchase['source_hash'],$b['source_hash'])||!hash_equals($purchase['history_sha256'],$b['history_sha256']))throw new DomainException('Anschaffungsbeleg des Teilabgangs geändert.');
            $p['snapshot']['partial_review']=['id'=>$partialRecord['id'],'sha256'=>$partialRecord['sha256']];
        }
        $sale=null;
        if(($disposal['kind']??'')==='sale'){
            $saleBinding=$this->binding('invoice',$disposal['sale_entry']);
            if(!$saleBinding||(int)$saleBinding['tax_year']!==$p['year'])throw new DomainException('Verkaufsrechnung zuerst in das Bilanzjournal übernehmen.');
            $saleCheck=$this->preview($p['year'],'invoice',$disposal['sale_entry'],$saleBinding['options'],$this->lock);
            if($saleCheck['issues']||!hash_equals($saleBinding['source_hash'],$saleCheck['hash'])||!$this->intact($club,$saleBinding,$saleCheck['vouchers'])
                ||($saleCheck['snapshot']['asset_sale']['item_id']??null)!==$item)throw new DomainException('Verkaufsrechnung wurde geändert oder storniert. Anlagenabgang abstimmen.');
            $p['snapshot']['sale_invoice_hash']=$saleBinding['source_hash'];
            $sale=AssetSale::amounts($plan,$disposal['date'],$disposal['gross_cents'],$disposal['rate'],$disposal['vat_kind']??'domestic');
        }
        $prior=$this->binding('depreciation',$p['id']);
        if($sale&&($prior===null||($prior['snapshot']['sale_tax_accounts_reserved']??false)===true)){
            $p['snapshot']['sale_tax_accounts_reserved']=true;$catalog=TaxCatalog::accounts($p['year']);
            foreach([[$disposal['rate']===19?'48450':'48490','revenue'],[$disposal['rate']===19?'68850':'68890','expense']] as [$code,$group])
                self::account($p,'src_code_'.$code,'SKR42 '.$code.' · '.$catalog[$code]['name'],$group,$code);
            foreach([['48550','revenue'],['68950','expense']] as [$code,$group])
                self::account($p,'src_sale_book_'.$code,'Restbuchwert verkaufter Ausstattung',$group,$code);
        }
        $dep=self::depreciationAccount($plan,$prior['snapshot']??null);
        if(isset($dep['marker']))$p['snapshot']['depreciation_skr_code']=$dep['marker'];
        $expense=self::account($p,$dep['id'],$dep['name'],'expense',$dep['code']);
        $amount=(int)$row['depreciation_cents'];$remaining=$schedule['disposal_cents']??0;
        $software=($disposal['kind']??null)==='software_end';
        $lossCode=$software?'68960':'68950';$lossId=$software?'src_software_disposal':'src_asset_scrapping';
        $lossName=$software?'Restbuchwert beendeter Softwarelizenz':'Restbuchwert ausgemusterter Ausstattung';
        // Reserve the rest-value account even when the book value is already zero:
        // a separately confirmed tax life can still have a positive remaining value.
        // Existing bound snapshots keep their exact account set and hash.
        if(in_array($disposal['kind']??null,['scrap','partial_scrap','software_end'],true)&&($prior===null||($prior['snapshot']['scrapping_account_reserved']??false)===true)){
            $p['snapshot']['scrapping_account_reserved']=true;
            self::account($p,$lossId,$lossName,'expense',$lossCode);
        }
        if($amount<=0&&!$disposal)throw new DomainException('Keine positive Jahresabschreibung vorhanden.');
        $lines=[];
        if($amount>0)$lines[]=self::line($expense,$amount,true,$plan['sphere']);
        if($remaining>0){
            $loss=$sale?self::account($p,'src_sale_book_'.$sale['book_code'],'Restbuchwert verkaufter Ausstattung',$sale['group'],$sale['book_code'])
                :self::account($p,$lossId,$lossName,'expense',$lossCode);
            $lines[]=self::line($loss,$remaining,true,$plan['sphere']);
        }
        if($lines){$lines[]=self::line($account,$amount+$remaining,false);self::voucher($p,$partial?($p['year'].'-12-31'):($disposal['date']??($p['year'].'-12-31')),($sale?'Anlagenverkauf ':($software?'Lizenz beendet ':($partial?'Abschreibung nach Teilabgang ':($disposal?'Ausmusterung ':'Abschreibung ')))).$p['id'],$lines);}
        // Freeze account identities only for new sources or already marked snapshots.
        // Adding this to an older binding would change its retained hash.
        if($disposal&&($prior===null||array_key_exists('disposal_accounts',$prior['snapshot']))){
            $map=$sale?[$disposal['rate']===19?'48450':'48490'=>'src_code_'.($disposal['rate']===19?'48450':'48490'),
                '48550'=>'src_sale_book_48550',$disposal['rate']===19?'68850':'68890'=>'src_code_'.($disposal['rate']===19?'68850':'68890'),
                '68950'=>'src_sale_book_68950']:[$lossCode=>$lossId];
            $p['snapshot']['disposal_accounts']=$map;
        }
    }
    /** Existing source hashes keep their old account; new sources separate software/equipment. */
    public static function depreciationAccount(array $plan,?array $retainedSnapshot):array
    {
        if(($plan['method']??'')==='gwg_immediate')return ['id'=>'src_gwg_depreciation','name'=>'Sofortabschreibung GWG','code'=>'62600'];
        $code=AssetOriginalDepreciation::code($plan,false);
        if(isset($plan['depreciation_code'])&&$retainedSnapshot!==null&&($retainedSnapshot['depreciation_skr_code']??null)!==$code)throw new DomainException('Historisches Abschreibungskonto gehört zum ursprünglichen Plan und bleibt unverändert.');
        if($code&&($retainedSnapshot===null||isset($retainedSnapshot['depreciation_skr_code'])))return ['id'=>'src_depreciation_'.$code,'name'=>$code==='62000'?'Abschreibung Software':($code==='62640'?'Auflösung Sammelposten':'Abschreibung Ausstattung'),'code'=>$code,'marker'=>$code];
        return ['id'=>'src_depreciation','name'=>'Abschreibungen','code'=>''];
    }
    private function intact(string $club,array $b,array $vouchers):bool
    {
        if(isset($this->projected[$b['source_type'].':'.$b['source_id']]))return true;
        try{(new BalanceSourceHistory($this->db,$this->actorId,$this->lock))->checked($b);}catch(DomainException|\JsonException){return false;}
        if(count($b['entry_ids'])!==count($vouchers))return false;
        if(!$b['entry_ids']&&!AssetDisposal::nonPosting(['type'=>$b['source_type'],'id'=>$b['source_id'],'year'=>(int)$b['tax_year'],'snapshot'=>$b['snapshot'],'vouchers'=>$vouchers]))return false;
        foreach($b['entry_ids'] as $index=>$id){
            $entry=$this->rows('SELECT booked_on,description,posting_lines FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$club,$b['tax_year'],$id])[0]??null;
            if(!$entry||$entry['booked_on']!==$vouchers[$index]['date']||$entry['description']!==$vouchers[$index]['description']||json_decode($entry['posting_lines'],true,64,JSON_THROW_ON_ERROR)!==$vouchers[$index]['lines'])return false;
            if($this->rows('SELECT id FROM balance_entries WHERE club_id=? AND tax_year=? AND reversal_of=?',[$club,$b['tax_year'],$id]))return false;
        }
        return true;
    }
    /** A read-only, complete correction preview, including dependent payments/depreciation. */
    public function correctionPreview(int $year,string $type,string $id,array $options=[],bool $lock=false):array
    {
        BalanceCalculation::year($year);self::identity($type,$id);$a=$this->actor();
        if($lock&&!$this->db->inTransaction())throw new DomainException('Korrekturprüfung benötigt einen Speichervorgang.');
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();$this->lock=$lock;
        $out=['year'=>$year,'type'=>$type,'id'=>$id,'items'=>[],'issues'=>[]];
        try{
            try{
                $state=$this->rows('SELECT state,version FROM balance_years WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])[0]??null;
                if(!$state||$state['state']!=='open')throw new DomainException('Korrekturen nur im offenen Bilanzjahr. Festgeschriebene Jahre bleiben unverändert.');
                $out['version']=(int)$state['version'];
                $root=$this->binding($type,$id);
                if(!$root||(int)$root['tax_year']!==$year)throw new DomainException('Übernommener Beleg gehört nicht zu diesem Bilanzjahr.');
                $targets=[$root];
                if($type==='transaction')$targets=$this->donationCorrectionTargets($a['club_id'],$root);
                if($type==='invoice'){
                    // Old AND current links matter: an allocation may have moved to another invoice.
                    $linked=$this->rows('SELECT transaction_id FROM finance_allocations WHERE club_id=? AND entry_id=? AND voided_at IS NULL',[$a['club_id'],$id]);
                    $txIds=array_column($linked,'transaction_id');
                    $all=$this->rows("SELECT source_type,source_id FROM balance_sources WHERE club_id=? AND source_type<>'invoice' ORDER BY source_type,source_id LIMIT 10001",[$a['club_id']]);
                    if(count($all)>10000)throw new DomainException('Zu viele Folgebelege für eine gemeinsame Korrektur.');
                    foreach($all as $row){
                        $b=$this->binding($row['source_type'],$row['source_id']);$s=$b['snapshot'];
                        $affected=$b['source_type']==='transaction'
                            ?isset($s['invoices'][$id])||in_array($b['source_id'],$txIds,true)
                            :isset($root['snapshot']['asset_accounts'][$s['annual']['item_id']??''])||(($root['snapshot']['asset_sale']['item_id']??null)!==null&&($root['snapshot']['asset_sale']['item_id']??null)===($s['annual']['item_id']??null));
                        if($affected)$targets[]=$b;
                    }
                }
                if(count($targets)>100)throw new DomainException('Mehr als 100 betroffene Belege. Korrektur gesondert abstimmen.');
                foreach($targets as $b){
                    if((int)$b['tax_year']!==$year)throw new DomainException('Zahlung oder Abschreibung gehört zu einem anderen Jahr. Jahresübergreifende Korrektur gesondert abstimmen.');
                    $history=(new BalanceSourceHistory($this->db,$this->actorId,$lock))->checked($b);$last=$history?end($history):null;
                    if(!$last)throw new DomainException('Für diese ältere Übernahme fehlt die gespeicherte Buchungsvorschau. Originalstand gesondert prüfen.');
                    $isRoot=$b['source_type']===$type&&$b['source_id']===$id;
                    $p=$this->preview($year,$b['source_type'],$b['source_id'],$isRoot?$options:$b['options'],$lock);
                    if($p['issues'])throw new DomainException(implode(' ',$p['issues']));
                    if($isRoot&&$type==='invoice'){
                        $allocations=$this->rows('SELECT cents FROM finance_allocations WHERE club_id=? AND entry_id=? AND voided_at IS NULL',[$a['club_id'],$id]);
                        if(array_sum(array_column($allocations,'cents'))>(int)$p['snapshot']['entry']['amount_cents'])throw new DomainException('Rechnungsbetrag liegt unter den zugeordneten Zahlungen. Zahlungszuordnung zuerst klären.');
                        $paid=$allocations||(bool)array_filter($targets,static fn($v)=>$v['source_type']==='transaction');
                        if($paid&&($b['snapshot']['claim']['contact_id']??null)!==($p['snapshot']['claim']['contact_id']??null))throw new DomainException('Erstattungsempfänger nach einer Zahlung geändert. Tatsächlichen Zahlungsweg zuerst gesondert klären.');
                    }
                    if(hash_equals($b['source_hash'],$p['hash'])){
                        if($isRoot)throw new DomainException('Keine Änderung gegenüber der übernommenen Fassung.');
                        continue;
                    }
                    $out['items'][]=['before'=>$last,'binding'=>$b,'proposal'=>$p];
                    $virtual=$b;$virtual['source_hash']=$p['hash'];$virtual['snapshot']=$p['snapshot'];$virtual['options']=$p['options'];
                    $this->projected[$b['source_type'].':'.$b['source_id']]=$virtual;
                }
            }catch(DomainException $e){$out['issues'][]=$e->getMessage();$out['items']=[];}
            catch(\JsonException){$out['issues'][]='Gespeicherter Belegverlauf ist beschädigt. Originalstand prüfen.';$out['items']=[];}
            $out['hash']=hash('sha256',self::json($out));
            if($own)$this->db->commit();return $out;
        }catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally{$this->projected=[];$this->lock=false;}
    }
    public function candidates(int $year):array
    {
        BalanceCalculation::year($year);$a=$this->actor();$club=$a['club_id'];$from=$year.'-01-01';$to=($year+1).'-01-01';$out=[];
        foreach($this->rows("SELECT e.id,e.display_number,e.document_date,e.description,e.kind FROM finance_entries e WHERE e.club_id=? AND e.kind<>'donation' AND e.status<>'cancelled' AND ((e.document_date>=? AND e.document_date<?) OR (e.kind='contribution' AND EXISTS(SELECT 1 FROM member_dues_people d WHERE d.club_id=e.club_id AND d.entry_id=e.id AND d.year=?))) ORDER BY e.document_date,e.id LIMIT 10001",[$club,$from,$to,$year]) as $e){
            // A notice sent in another year belongs to its assessed year, not twice to both years.
            $dues=$e['kind']==='contribution'?$this->rows('SELECT year FROM member_dues_people WHERE club_id=? AND entry_id=?',[$club,$e['id']]):[];
            if($dues&&!array_filter($dues,static fn($r)=>(int)$r['year']===$year))continue;
            $date=substr($e['document_date'],0,4)===(string)$year?$e['document_date']:$from;
            $issued=$e['kind']==='income'?$this->rows("SELECT payload FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND kind='invoice' AND state='issued'",[$club,$e['id']]):[];
            if($issued){
                try{$data=json_decode($issued[0]['payload'],true,64,JSON_THROW_ON_ERROR);$delivery=$data['delivery_date']??null;
                    if(is_string($delivery)&&TaxCatalog::documentYear($delivery)===$year)$date=$delivery;
                }catch(\JsonException){/* Keep candidate visible; the source preview reports damaged evidence. */}
            }
            $out[]=['type'=>'invoice','id'=>$e['id'],'name'=>$e['display_number'].' · '.$e['description'],'date'=>$date];
        }
        foreach($this->rows('SELECT id,booked_on,description FROM finance_transactions WHERE club_id=? AND booked_on>=? AND booked_on<? ORDER BY booked_on,id LIMIT 10001',[$club,$from,$to]) as $t)$out[]=['type'=>'transaction','id'=>$t['id'],'name'=>$t['description'],'date'=>$t['booked_on']];
        foreach($this->rows('SELECT y.item_id,y.depreciation_cents,y.snapshot,i.name FROM asset_book_years y JOIN inventory_items i ON i.club_id=y.club_id AND i.id=y.item_id WHERE y.club_id=? AND y.year=? ORDER BY y.item_id LIMIT 10001',[$club,$year]) as $i){
            [, $disposal]=AssetDisposal::unpack(json_decode($i['snapshot'],true,512,JSON_THROW_ON_ERROR));
            $eventThisYear=$disposal&&TaxCatalog::documentYear($disposal['date'])===$year;
            $costSnapshot=json_decode($i['snapshot'],true,512,JSON_THROW_ON_ERROR);$cost=isset($costSnapshot['valuation']);$partnership=isset($costSnapshot['partnership_book']);
            $own=isset($costSnapshot['own_book']);
            if((int)$i['depreciation_cents']>0||$eventThisYear||$cost||$partnership||$own)$out[]=['type'=>'depreciation','id'=>$i['item_id'].':'.$year,
                'name'=>($own?'HGB-Entwicklungsregister · ':($partnership?'HGB-Beteiligungsbewertung · ':($cost?'Anlagenbewertung · ':($eventThisYear?match($disposal['kind']){'sale'=>'Anlagenverkauf · ','partial_scrap'=>'Teilabgang · ','software_end'=>'Lizenz beendet · ',default=>'Ausmusterung · '}:'Abschreibung · ')))).$i['name'],
                'date'=>$eventThisYear&&$disposal['kind']!=='partial_scrap'?$disposal['date']:($year.'-12-31')];
        }
        if(count($out)>10000)throw new DomainException('Zu viele Belege für eine gemeinsame Jahresprüfung.');
        return $out;
    }
    public function yearBindings(int $year):array
    {
        BalanceCalculation::year($year);$a=$this->actor();$out=[];
        foreach($this->rows('SELECT source_type,source_id FROM balance_sources WHERE club_id=? AND tax_year=? ORDER BY source_type,source_id',[$a['club_id'],$year]) as $row)$out[]=$this->binding($row['source_type'],$row['source_id']);
        return $out;
    }
    /** No inferred completion: missing, changed or reversed sources block year finalisation. */
    public function reviewYear(int $year,bool $lock=false,?array $calculation=null):array
    {
        BalanceCalculation::year($year);$a=$this->actor();$this->lock=$lock;
        if($lock&&!$this->db->inTransaction())throw new DomainException('Quellenprüfung benötigt einen Speichervorgang.');
        $issues=[];$sources=[];
        foreach($this->rows('SELECT source_type,source_id FROM balance_sources WHERE club_id=? AND tax_year=? ORDER BY source_type,source_id',[$a['club_id'],$year]) as $r){
            $b=$this->binding($r['source_type'],$r['source_id']);$p=$this->preview($year,$r['source_type'],$r['source_id'],$b['options'],$lock);
            if($p['issues']||!hash_equals($b['source_hash'],$p['hash'])||!$this->intact($a['club_id'],$b,$p['vouchers']))$issues[]=$r['source_type'].' '.$r['source_id'].': Quelle geändert oder Buchung storniert. Abstimmung erforderlich.';
            try{$b['history']=(new BalanceSourceHistory($this->db,$this->actorId,$lock))->checked($b);}catch(DomainException|\JsonException){$issues[]=$r['source_type'].' '.$r['source_id'].': Belegverlauf beschädigt.';}
            $sources[]=$b;
        }
        foreach($this->candidates($year) as $c)if(!$this->binding($c['type'],$c['id']))$issues[]=$c['name'].': noch nicht im Bilanzjournal berücksichtigt.';
        // These non-cash records are not in finance_transactions. Never report them as covered by the cash adapter.
        $from=$year.'-01-01';$to=($year+1).'-01-01';
        if($this->rows('SELECT id FROM goods_donations WHERE club_id=? AND received_on>=? AND received_on<? LIMIT 1',[$a['club_id'],$from,$to]))$issues[]='Sachspenden vorhanden: Zugang, Wertansatz und Gegenbuchung sind noch gesondert abzustimmen.';
        if($this->rows('SELECT id FROM donation_waivers WHERE club_id=? AND waived_on>=? AND waived_on<? AND cancelled_at IS NULL LIMIT 1',[$a['club_id'],$from,$to]))$issues[]='Aufwandsspenden vorhanden: Anspruch und Verzicht sind noch gesondert abzustimmen.';
        if($calculation===null){try{$calculation=(new BalanceLedger($this->db,$this->actorId))->report($year)['calculation'];}catch(DomainException $e){$issues[]=$e->getMessage();}}
        if($calculation){
            foreach($this->rows('SELECT id,name,opening_cents FROM finance_accounts WHERE club_id=? AND opened_on<=? ORDER BY id',[$a['club_id'],$year.'-12-31']) as $bank){
                $transactions=$this->rows('SELECT id,cents FROM finance_transactions WHERE club_id=? AND account_id=? AND booked_on<=? ORDER BY booked_on,id',[$a['club_id'],$bank['id'],$year.'-12-31']);
                $expected=(int)$bank['opening_cents']+array_sum(array_column($transactions,'cents'));
                $actual=$calculation['accounts'][self::accountId('bank',$bank['id'])]['closing_cents']??0;
                if($actual!==$expected)$issues[]=$bank['name'].': Bilanzsaldo und erfasste Geldbewegungen stimmen nicht überein.';
            }
        }
        return ['issues'=>$issues,'sources'=>$sources];
    }
}
