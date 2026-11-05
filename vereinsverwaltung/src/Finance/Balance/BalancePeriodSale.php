<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Dated sales reuse issued originals, never rewrite invoice positions or their PDF. */
final class BalancePeriodSale
{
    /** Resolve the invoice's retained journal line, not another account with the same SKR code. */
    public static function postingAccount(array $report,array $item):string
    {
        $e=$item['sale_posting']??null;$sale=$item['sale_values']??null;$disposal=$item['disposal']??null;
        if(!is_array($e)||array_keys($e)!==['entry_id','source_id','source_sha256','account']||!is_array($sale)||!is_array($disposal)
            ||$e['source_id']!==$disposal['sale_entry']||!is_string($e['source_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$e['source_sha256']))throw new DomainException('Ursprüngliche Verkaufsrechnung und gebuchte Erlöszeile nachweisen.');
        $entries=array_column($report['entries'],null,'id');$entry=$entries[$e['entry_id']]??null;$key=$e['account'];
        if(!is_string($key)||($report['accounts'][$key]['skr_code']??null)!==$sale['sale_code']||$report['accounts'][$key]['group']!==$sale['group']
            ||!$entry||($entry['reversal_of']??null)!==null||$entry['date']!==$disposal['date'])throw new DomainException('Verkaufsrechnung und ursprüngliches Erlöskonto stimmen nicht überein.');
        $lines=array_values(array_filter($entry['lines'],static fn($l)=>$l['account']===$key));
        if(count($lines)!==1||$lines[0]['debit_cents']!==0||$lines[0]['credit_cents']!==$sale['net_cents']||$lines[0]['sphere']!==$item['plan']['sphere'])throw new DomainException('Originalbetrag des Anlagenverkaufs stimmt nicht mit der Buchung überein.');
        foreach($entries as $other)if(($other['reversal_of']??null)===$e['entry_id'])throw new DomainException('Aufgehobene Verkaufsbuchung nicht weiterverwenden.');
        return $key;
    }
    /** Read-only projection of already replay-validated sources; original source hashes stay unchanged. */
    public static function postingEvidence(array $report,array $item):array
    {
        $id=$item['disposal']['sale_entry'];$b=$report['sources']['invoice:'.$id]??null;$p=$b['proposal']??null;$sale=$item['sale_values'];
        if(!$p||$p['type']!=='invoice'||$p['id']!==$id||$p['period']!==$report['period']||$p['issues']
            ||!hash_equals($p['hash'],BalancePeriodSources::hash($p))
            ||($p['snapshot']['period_sale']??null)!==($sale+['source_id'=>$item['source_id'],'source_sha256'=>$item['source_sha256']]))throw new DomainException('Anlagenverkauf zuerst mit der bestätigten Verkaufsrechnung abstimmen.');
        $matches=[];$entries=array_column($report['entries'],null,'id');
        foreach($b['entry_ids'] as $entryId)foreach($entries[$entryId]['lines']??[] as $line){
            $key=$line['account'];if(($p['accounts'][$key]['skr_code']??null)===$sale['sale_code']&&$p['accounts'][$key]['group']===$sale['group'])$matches[]=['entry_id'=>$entryId,'source_id'=>$id,'source_sha256'=>$p['hash'],'account'=>$key];
        }
        if(count($matches)!==1)throw new DomainException('Eindeutige Originalbuchung der Verkaufsrechnung fehlt.');
        self::postingAccount($report,$item+['sale_posting'=>$matches[0]]);return $matches[0];
    }
    public function __construct(private PDO $db,private string $actor){}
    private function rows(string $sql,array $args,bool $lock=false):array
    {return DocumentCatalog::query($this->db,$sql.($lock?' FOR UPDATE':''),$args)->fetchAll();}
    public function fromForm(array $plan,array $form):array
    {
        foreach(['sale_entry','disposal_date','disposal_reason'] as $key)if(!is_string($form[$key]??null))throw new DomainException('Verkaufsrechnung, Übergabedatum und Grund angeben.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$form['sale_entry'])||($form['sale_confirmed']??'')!=='1')throw new DomainException('Verkaufsrechnung auswählen und vollständigen Verkauf bestätigen.');
        $s=(new BalanceSources($this->db,$this->actor))->issuedEvidence($form['sale_entry'],$form['disposal_date']);
        $this->position($plan,$s);
        $d=array_values(array_filter($s['documents'],static fn($d)=>$d['id']===$s['issued_invoice']['document_id']))[0];
        $p=['kind'=>'sale','date'=>$form['disposal_date'],'reason'=>trim($form['disposal_reason']),
            'document_id'=>$d['id'],'revision'=>(int)$d['revision'],'sha256'=>$d['sha256'],'complete'=>true,
            'sale_entry'=>$form['sale_entry'],'sale_hash'=>AssetSale::hash($s),
            'gross_cents'=>(int)$s['positions'][0]['gross_cents'],'tax_cents'=>(int)$s['positions'][0]['tax_cents'],
            'rate'=>(int)$s['positions'][0]['tax_rate'],'vat_reason'=>$s['issued_invoice']['payload']['vat_reason']];
        $vatKind=$form['sale_vat_kind']??'domestic';if(!is_string($vatKind))throw new DomainException('Lieferungsart prüfen.');
        if($vatKind!=='domestic')$p['vat_kind']=$vatKind;
        AssetDisposal::check($plan,$p);return ['disposal'=>$p];
    }
    private function position(array $plan,array $s):void
    {
        if(count($s['positions'])!==1||$s['assets']||$s['claim'])throw new DomainException('Eine eigene Verkaufsrechnung mit genau einem Gegenstand verwenden.');
        $p=$s['positions'][0];$original=$s['issued_invoice']['payload']['positions'][0];
        if(TaxCatalog::sphereOf((string)$p['sphere'])!==$plan['sphere']||TaxCatalog::sphereOf((string)($original['sphere']??''))!==$plan['sphere']||$original['quantity']!==1000||$original['unit']!=='C62'
            ||!in_array((int)$p['tax_rate'],[0,19],true))throw new DomainException('Verkaufsrechnung muss ein Stück im selben Vereinsbereich und einen unterstützten Steuersatz enthalten.');
    }
    public function check(string $club,string $item,array $plan,array $proof,bool $lock):void
    {
        AssetDisposal::check($plan,$proof);
        if($lock&&!$this->db->inTransaction())throw new DomainException('Verkaufsprüfung benötigt einen Speichervorgang.');
        $s=(new BalanceSources($this->db,$this->actor))->issuedEvidence($proof['sale_entry'],$proof['date'],$lock);$this->position($plan,$s);
        $d=array_values(array_filter($s['documents'],static fn($d)=>$d['id']===$s['issued_invoice']['document_id']))[0];
        if(!hash_equals($proof['sale_hash'],AssetSale::hash($s))||$proof['document_id']!==$d['id']||$proof['revision']!==(int)$d['revision']||!hash_equals($proof['sha256'],$d['sha256'])
            ||$proof['gross_cents']!==(int)$s['positions'][0]['gross_cents']||$proof['tax_cents']!==(int)$s['positions'][0]['tax_cents']||$proof['rate']!==(int)$s['positions'][0]['tax_rate']
            ||$proof['vat_reason']!==$s['issued_invoice']['payload']['vat_reason'])throw new DomainException('Verkaufsrechnung oder Nachweis geändert. Original erneut prüfen.');
        if($this->rows('SELECT item_id FROM asset_sale_links WHERE club_id=? AND entry_id=?',[$club,$proof['sale_entry']],$lock)
            ||$this->rows("SELECT source_id FROM balance_sources WHERE club_id=? AND source_type='invoice' AND source_id=?",[$club,$proof['sale_entry']],$lock))throw new DomainException('Verkaufsrechnung ist bereits im Kalenderjournal verwendet.');
        $link=$this->rows('SELECT item_id,period_id FROM balance_period_asset_sales WHERE club_id=? AND entry_id=?',[$club,$proof['sale_entry']],$lock)[0]??null;
        if($link&&$link['item_id']!==$item)throw new DomainException('Verkaufsrechnung gehört bereits zu einem anderen Gegenstand.');
        $owner=(new BalancePeriodSources($this->db,$this->actor))->owner('invoice',$proof['sale_entry']);
        if($owner&&(!$link||$owner['period_id']!==$link['period_id']))throw new DomainException('Verkaufsrechnung bereits übernommen. Ursprüngliche Belegzuordnung zuerst abstimmen.');
    }
    /** Keep the once-issued sale identity even when an open-period source is corrected. */
    public function guard(string $club,string $item,array $period,?array $proof,bool $lock):void
    {
        $link=$this->rows('SELECT entry_id,period_id FROM balance_period_asset_sales WHERE club_id=? AND item_id=?',[$club,$item],$lock)[0]??null;
        if(!$link)return;
        if(!$proof||$proof['kind']!=='sale'||$proof['sale_entry']!==$link['entry_id'])throw new DomainException('Bestätigten Anlagenverkauf nicht entfernen oder durch eine andere Rechnung ersetzen.');
        $key=BalancePeriod::key($period);
        if(($proof['date']>=$period['start']&&$link['period_id']!==$key)||($proof['date']<$period['start']&&$link['period_id']>=$key))throw new DomainException('Verkauf und ursprünglichen Abschlusszeitraum prüfen.');
    }
    public static function values(array $proof,int $book):array
    {
        if($book<0||$book>BalanceCalculation::LIMIT)throw new DomainException('Restbuchwert des Verkaufs prüfen.');
        $gross=$proof['gross_cents'];$vat=$proof['tax_cents'];$net=$gross-$vat;$gain=$net>=$book;$rate=$proof['rate'];[$gainCode,$lossCode]=AssetSale::saleCodes($rate,$proof['vat_kind']??'domestic');
        return ['gross_cents'=>$gross,'tax_cents'=>$vat,'net_cents'=>$net,'book_cents'=>$book,'result_cents'=>$net-$book,'gain'=>$gain,
            'sale_code'=>$gain?$gainCode:$lossCode,'book_code'=>$gain?'48550':'68950','group'=>$gain?'revenue':'expense'];
    }
    /** Invoice follows a verified disposal. Disposal only reads the original invoice, avoiding a hash cycle. */
    public function invoice(string $club,string $entry,array $period,string $date,bool $lock):?array
    {
        $link=$this->rows('SELECT * FROM balance_period_asset_sales WHERE club_id=? AND entry_id=?',[$club,$entry],$lock)[0]??null;
        if(!$link)return null;
        if($link['period_id']!==BalancePeriod::key($period))throw new DomainException('Verkaufsrechnung gehört in den Abschlusszeitraum des Anlagenabgangs.');
        $sources=new BalancePeriodSources($this->db,$this->actor);$id=$link['item_id'].':'.$link['period_id'];$b=$sources->binding('depreciation',$id);
        if(!$b||$b['period_issues']||$b['first_revision']!==(int)$link['event_revision'])throw new DomainException('Bestätigter Anlagenverkauf fehlt.');
        $p=$sources->preview($period,'depreciation',$id,$b['proposal']['options'],$lock);$proof=$p['snapshot']['disposal']??null;
        if($p['issues']||!hash_equals($p['hash'],$b['proposal']['hash'])||!$proof||$proof['kind']!=='sale'||$proof['sale_entry']!==$entry||$proof['date']!==$date)throw new DomainException('Anlagenverkauf geändert. Abgang vor der Rechnung erneut prüfen.');
        return $p['snapshot']['sale_values']+['source_id'=>$id,'source_sha256'=>$p['hash']];
    }
    public function choices(array $period,array $plan,string $item):array
    {
        $a=Permissions::actor($this->db,$this->actor);if($a['finance_access']!=='write')return [];$out=[];
        foreach($this->rows("SELECT entry_id,number,payload FROM outgoing_invoices WHERE club_id=? AND kind='invoice' AND state='issued' ORDER BY issued_at DESC LIMIT 500",[$a['club_id']]) as $row){
            try{
                $data=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);if(!is_array($data)||!is_string($data['delivery_date']??null)||!BalancePeriod::contains($period,$data['delivery_date']))continue;
                $o=$this->fromForm($plan,['sale_entry'=>$row['entry_id'],'disposal_date'=>$data['delivery_date'],'disposal_reason'=>'Auswahlprüfung','sale_confirmed'=>'1']);
                $this->check($a['club_id'],$item,$plan,$o['disposal'],false);
                $out[]=['entry_id'=>$row['entry_id'],'label'=>$row['number'].' · '.$data['positions'][0]['description'],'date'=>$data['delivery_date']];
            }catch(DomainException|\JsonException){continue;}
        }return $out;
    }
}
