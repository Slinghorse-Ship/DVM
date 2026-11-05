<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/BalanceInternalMovementPostings.php';
use DomainException;
use PDO;
require_once __DIR__.'/BalanceSources.php';
require_once __DIR__.'/BalancePeriodDepreciation.php';
require_once __DIR__.'/BalancePeriodInvoiceCarry.php';
require_once __DIR__.'/BalancePeriodSourceCatalogue.php';

/** Derives dated journal entries from original invoices and fully assigned bank transactions. */
final class BalancePeriodSources
{
    private bool $lock=false;
    public function __construct(private PDO $db,private string $actor){}
    private function access():array
    {
        $a=Permissions::actor($this->db,$this->actor);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');return $a;
    }
    private function rows(string $sql,array $args):array{return DocumentCatalog::query($this->db,$sql.($this->lock?' FOR UPDATE':''),$args)->fetchAll();}
    public static function identity(string $type,string $id):void
    {
        if($type==='depreciation'&&preg_match('/^[a-zA-Z0-9_-]{1,128}:[0-9]{8}-[0-9]{8}$/D',$id))return;
        if(!in_array($type,['invoice','transaction'],true)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Rechnung oder Bankumsatz auswählen.');
    }
    public static function hash(array $p):string
    {
        unset($p['hash']);return hash('sha256',json_encode(['balance-period-source-v1',$p],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    public static function formOptions(string $type,array $form):array
    {
        if($type==='transaction')return BalanceSources::formOptions($type,$form);
        if($type==='depreciation'){
            if(($form['asset_options']??'')==='')return [];
            if(!is_string($form['asset_options'])||strlen($form['asset_options'])>100000)throw new DomainException('Anlagenbewegung erneut prüfen.');
            try{$options=json_decode($form['asset_options'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Anlagenbewegung erneut prüfen.');}
            if(!is_array($options)||array_diff(array_keys($options),['disposal','sphere_changes','valuations'])||(array_key_exists('disposal',$options)&&!is_array($options['disposal']))||(array_key_exists('sphere_changes',$options)&&!is_array($options['sphere_changes']))||(array_key_exists('valuations',$options)&&!is_array($options['valuations'])))throw new DomainException('Anlagenbewegung erneut prüfen.');
            return $options;
        }
        if($type!=='invoice')throw new DomainException('Rechnung oder Bankumsatz auswählen.');
        foreach(['recognised_on','private_paid_on'] as $key)if(!is_string($form[$key]??null))throw new DomainException('Leistungsdatum und private Zahlung prüfen.');
        return BalanceSources::formOptions($type,$form);
    }
    public function owner(string $type,string $id):?array
    {
        self::identity($type,$id);$a=$this->access();return $this->rows('SELECT period_id,event_revision FROM balance_period_sources WHERE club_id=? AND source_type=? AND source_id=?',[$a['club_id'],$type,$id])[0]??null;
    }
    public function binding(string $type,string $id):?array
    {
        $owner=$this->owner($type,$id);if(!$owner)return null;
        // Rebuild immutable journal evidence without recursively reviewing bank sources.
        $r=(new BalancePeriodLedger($this->db,$this->actor))->report($owner['period_id'],0,false);$b=$r['sources'][$type.':'.$id]??null;
        if(!$b||$b['first_revision']!==(int)$owner['event_revision'])throw new DomainException('Belegzuordnung und Buchungsverlauf stimmen nicht überein.');
        return $b+['period_id'=>$owner['period_id'],'period'=>$r['period'],'period_state'=>$r['state'],'period_issues'=>$r['issues']];
    }
    public function preview(array $period,string $type,string $id,array $options=[],bool $lock=false):array
    {
        $period=BalancePeriod::check($period);self::identity($type,$id);$a=$this->access();
        if($lock&&!$this->db->inTransaction())throw new DomainException('Quellensperre benötigt einen Speichervorgang.');
        $before=$this->lock;$this->lock=$lock;
        $p=['type'=>$type,'id'=>$id,'year'=>(int)substr($period['end'],0,4),'period'=>$period,'options'=>$options,'snapshot'=>[],'accounts'=>[],'vouchers'=>[],'evidence'=>$type.': '.$id,'issues'=>[]];
        try{
            if($type==='invoice'){
                $definition=BalanceReadSnapshot::remember($this->db,__METHOD__.':definition',[$a['club_id'],BalancePeriod::key($period)],function()use($a,$period):array{
                    $row=$this->rows('SELECT definition FROM balance_periods WHERE club_id=? AND id=?',[$a['club_id'],BalancePeriod::key($period)])[0]??null;return $row===null?[]:json_decode($row['definition'],true,512,JSON_THROW_ON_ERROR);
                });$dayPolicy=BalancePeriodDayAllocation::policy($definition);
                $invoice=(new BalanceSources($this->db,$this->actor))->periodInvoice($period,$id,$options,$lock,$dayPolicy);
                foreach(['snapshot','accounts','vouchers','evidence','issues'] as $key)$p[$key]=$invoice[$key];
                if($dayPolicy!==null)$p['snapshot']['day_allocation_policy']=$dayPolicy;
            }elseif($type==='depreciation')(new BalancePeriodDepreciation($this->db,$this->actor))->proposal($p,$lock);
            else $this->transactionProposal($p,$a['club_id']);
            if(!$p['vouchers']&&!$p['issues']&&!self::nonPosting($p))throw new DomainException('Keine übernahmefähige Buchung vorhanden.');
            foreach($p['vouchers'] as $v)if(!BalancePeriod::contains($period,$v['date']))throw new DomainException('Buchungsdatum liegt außerhalb des Abschlusszeitraums.');
        }catch(DomainException $e){$p['issues'][]=$e->getMessage();$p['vouchers']=[];}
        catch(\JsonException){$p['issues'][]='Gespeicherte Belegdaten sind beschädigt. Original prüfen.';$p['vouchers']=[];}
        finally{$this->lock=$before;}
        $p['hash']=self::hash($p);return $p;
    }
    public static function nonPosting(array $p):bool{return $p['type']==='depreciation'&&($p['snapshot']['schedule']['depreciation_cents']??null)===0&&($p['snapshot']['schedule']['book_disposals_cents']??0)===0&&($p['snapshot']['schedule']['impairment_cents']??0)===0&&($p['snapshot']['schedule']['writeup_cents']??0)===0;}
    private function transactionProposal(array &$p,string $club):void
    {
        if(array_diff(array_keys($p['options']),['donation_use'])||(isset($p['options']['donation_use'])&&!in_array($p['options']['donation_use'],['unrestricted','needs_review'],true)))throw new DomainException('Angaben zur Verwendung der Geldspenden prüfen.');
        $tx=$this->rows('SELECT id,account_id,booked_on,cents,reversal_of FROM finance_transactions WHERE club_id=? AND id=?',[$club,$p['id']])[0]??null;
        if(!$tx||!BalancePeriod::contains($p['period'],$tx['booked_on']))throw new DomainException('Bankumsatz im gewählten Abschlusszeitraum auswählen.');
        if($tx['reversal_of']!==null||$this->rows('SELECT id FROM finance_transactions WHERE club_id=? AND reversal_of=?',[$club,$p['id']]))throw new DomainException('Stornierten Bankumsatz gesondert abstimmen.');
        $postings=PaymentFunding::postings($this->db,$club,$p['id'],$this->lock);$alloc=$postings['rows'];
        $excess=ExcessReturnPostings::movement($this->db,$this->actor,$p['id'],$this->lock);
        $internal=BalanceInternalMovementPostings::movement($this->db,$this->actor,$p['id'],$this->lock);
        if((!$alloc&&!$excess['amount']&&!$internal['amount'])||array_sum(array_map(static fn(array$row):int=>abs((int)$row['cents']),$alloc))+$excess['amount']+$internal['amount']!==abs((int)$tx['cents']))throw new DomainException('Bankumsatz zuerst vollständig Belegen oder tatsächlichen Überschussrückzahlungen zuordnen.');
        $bank='src_bank_'.substr(hash('sha256',$tx['account_id']),0,20);$p['accounts'][$bank]=(new BalanceSources($this->db,$this->actor))->bankAccounts()[$bank]??throw new DomainException('Bankkonto nicht verfügbar.');
        $line=static fn(string $id,int $amount,bool $debit):array=>['account'=>$id,'debit_cents'=>$debit?$amount:0,'credit_cents'=>$debit?0:$amount,'sphere'=>''];
        $lines=[$line($bank,abs((int)$tx['cents']),(int)$tx['cents']>0)];$p['snapshot']=['transaction'=>$tx,'allocations'=>$alloc,'invoices'=>[]];
        if($internal['amount']){$p['snapshot']['internal_movements']=$internal['snapshot'];$p['accounts']+=$internal['accounts'];array_push($lines,...$internal['lines']);}
        if($postings['returns'])$p['snapshot']['partial_returns']=$postings['returns'];
        if($excess['amount']){$p['snapshot']['excess_returns']=$excess['snapshot'];$p['accounts']+=$excess['accounts'];array_push($lines,...$excess['lines']);}
        $donations=[];
        foreach($alloc as $allocation){
            $kind=$this->rows('SELECT kind FROM finance_entries WHERE club_id=? AND id=?',[$club,$allocation['entry_id']])[0]['kind']??null;
            if($kind==='donation'){
                if(isset($donations[$allocation['entry_id']]))continue;$donations[$allocation['entry_id']]=true;
                $gift=(new BalanceSources($this->db,$this->actor))->periodDonation($p['period'],$p['id'],$allocation['entry_id'],$p['options'],$this->lock);
                $p['snapshot']['donations'][$allocation['entry_id']]=$gift['snapshot']['donations'][$allocation['entry_id']]??[];
                if($gift['issues'])throw new DomainException(implode(' ',$gift['issues']));
                $p['snapshot']['donation_catalogs'][$allocation['entry_id']]=['source_year'=>$gift['year'],'source'=>$gift['snapshot']['automatic_revenue_reference'],'period'=>$gift['snapshot']['period_catalog']];
                $p['accounts']+=$gift['accounts'];array_push($lines,...$gift['lines']);continue;
            }
            $b=$this->binding('invoice',$allocation['entry_id']);
            if($b===null)$old=(new BalancePeriodInvoiceCarry($this->db,$this->actor))->original($allocation['entry_id'],$p['period'],$this->lock);
            else{
                if($b['period_issues']||$b['period']['start']>$p['period']['start']||($b['period']!==$p['period']&&($b['period']['end']>=$p['period']['start']||$b['period_state']!=='closed')))throw new DomainException('Zugeordnete Rechnung zuerst in diesen oder einen geprüften Vorzeitraum übernehmen.');
                $old=$b['proposal'];$fresh=$this->preview($b['period'],'invoice',$allocation['entry_id'],$old['options'],$this->lock);
                if($fresh['issues']||!hash_equals($old['hash'],$fresh['hash']))throw new DomainException('Rechnung oder Kontierung wurde geändert. Belegübernahme zuerst abstimmen.');
            }
            $e=$old['snapshot']['entry'];$claim=$old['snapshot']['claim'];
            if((((int)$tx['cents']<0)!==($e['kind']==='expense'))!==((int)$allocation['cents']<0))throw new DomainException('Zahlungsrichtung und Rechnung stimmen nicht überein.');
            if($tx['booked_on']<$old['options']['recognised_on']||($claim&&$tx['booked_on']<$old['options']['private_paid_on']))throw new DomainException('Zahlung vor der Leistung oder privaten Vorleistung gesondert abgrenzen.');
            if(PaymentFunding::paid($this->db,$club,$allocation['entry_id'],(int)$e['amount_cents'],$this->lock)>(int)$e['amount_cents'])throw new DomainException('Zahlungszuordnung überschreitet den Rechnungsbetrag.');
            $account=$old['snapshot']['settlement_account'];$p['accounts'][$account]=$old['accounts'][$account];$lines[]=$line($account,abs((int)$allocation['cents']),(int)$tx['cents']<0);
            $origin=$b===null?['calendar_year'=>$old['year']]:['period_id'=>$b['period_id']];
            $p['snapshot']['invoices'][$allocation['entry_id']]=$origin+['hash'=>$old['hash'],'account'=>$account];
        }
        if($p['options']&&!$donations)throw new DomainException('Dieser Umsatz enthält keine Geldspende. Vorschau neu öffnen.');
        $p['vouchers'][]=['date'=>$tx['booked_on'],'description'=>$internal['amount']?($alloc||$excess['amount']?'Zahlung und eigene Umbuchung':'Umbuchung zwischen eigenen Konten'):($donations?'Zahlung zu Belegen':'Zahlung zu Rechnungen'),'lines'=>BalanceCalculation::lines($lines,$p['accounts'])];
    }
    public function candidates(array $period):array
    {
        $period=BalancePeriod::check($period);$a=$this->access();$args=[$a['club_id'],$period['start'],$period['end']];
        // Issued originals already contain the economic performance date. Keep the
        // document date for its own catalog, but never hide a cross-boundary invoice.
        $performance="COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(o.payload) THEN o.payload ELSE '{}' END,'$.delivery_date')),''),e.document_date)";
        $assessedYear="(SELECT MIN(d.year) FROM member_dues_people d WHERE d.club_id=e.club_id AND d.entry_id=e.id)";
        $contributionDate="CASE WHEN LEFT(e.document_date,4)=$assessedYear THEN e.document_date ELSE CONCAT($assessedYear,'-01-01') END";
        $performance="CASE WHEN e.kind='contribution' THEN COALESCE($contributionDate,e.document_date) ELSE $performance END";
        $invoices=$this->rows("SELECT e.id,e.kind,e.display_number,e.description,e.document_date,e.amount_cents,$performance performance_date FROM finance_entries e LEFT JOIN outgoing_invoices o ON o.club_id=e.club_id AND o.entry_id=e.id AND o.kind='invoice' AND o.state='issued' WHERE e.club_id=? AND e.status<>'cancelled' AND e.kind<>'donation' AND ((e.kind<>'contribution' AND e.document_date BETWEEN ? AND ?) OR $performance BETWEEN ? AND ?) ORDER BY performance_date,e.id LIMIT 201",[...$args,$period['start'],$period['end']]);
        foreach($invoices as &$invoice)if(!TaxCatalog::documentYear($invoice['performance_date']))$invoice['performance_date']=$invoice['document_date'];unset($invoice);
        $transactions=$this->rows('SELECT id,booked_on,cents,description FROM finance_transactions WHERE club_id=? AND booked_on BETWEEN ? AND ? ORDER BY booked_on,id LIMIT 201',$args);
        return ['invoices'=>array_slice($invoices,0,200),'transactions'=>array_slice($transactions,0,200),'truncated'=>count($invoices)>200||count($transactions)>200];
    }
    /** Unreviewed originals must not disappear behind a manual completeness checkbox. */
    public function missing(array $period):array
    {
        $issues=(new BalancePeriodSourceCatalogue($this->db,$this->actor))->missing($period);
        $a=$this->access();$args=[$a['club_id'],$period['start'],$period['end']];
        if($this->rows('SELECT id FROM goods_donations WHERE club_id=? AND received_on BETWEEN ? AND ? LIMIT 1',$args))$issues[]='Sachspenden: Zugang und Gegenbuchung für diesen Zeitraum abstimmen.';
        if($this->rows('SELECT id FROM donation_waivers WHERE club_id=? AND waived_on BETWEEN ? AND ? AND cancelled_at IS NULL LIMIT 1',$args))$issues[]='Aufwandsspenden: Anspruch und Verzicht für diesen Zeitraum abstimmen.';
        return $issues;
    }
    public function bankIssues(array $period,array $calculation):array
    {
        $period=BalancePeriod::check($period);$a=$this->access();$issues=[];
        foreach($this->rows('SELECT id,name,opening_cents FROM finance_accounts WHERE club_id=? AND opened_on<=? ORDER BY id',[$a['club_id'],$period['end']]) as $bank){
            $rows=$this->rows('SELECT cents FROM finance_transactions WHERE club_id=? AND account_id=? AND booked_on<=? ORDER BY booked_on,id',[$a['club_id'],$bank['id'],$period['end']]);
            $expected=(int)$bank['opening_cents']+array_sum(array_column($rows,'cents'));$key='src_bank_'.substr(hash('sha256',$bank['id']),0,20);
            if(($calculation['accounts'][$key]['closing_cents']??0)!==$expected)$issues[]=$bank['name'].': Buchungsstand und Bankumsätze stimmen zum Ende des Zeitraums nicht überein.';
        }
        return $issues;
    }
}
