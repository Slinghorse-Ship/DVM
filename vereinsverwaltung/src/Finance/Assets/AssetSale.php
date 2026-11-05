<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/AssetTypes.php';

/** One complete movable asset, one issued domestic sales invoice, one disposal. */
final class AssetSale
{
    public static function choices(PDO $db,string $actor,array $plan,int $year):array
    {
        $a=Permissions::actor($db,$actor);if($a['finance_access']!=='write')return [];$out=[];
        $rows=DocumentCatalog::query($db,"SELECT o.entry_id,o.number,o.payload FROM outgoing_invoices o LEFT JOIN asset_sale_links l ON l.club_id=o.club_id AND l.entry_id=o.entry_id LEFT JOIN balance_sources b ON b.club_id=o.club_id AND b.source_type='invoice' AND b.source_id=o.entry_id WHERE o.club_id=? AND o.kind='invoice' AND o.state='issued' AND l.entry_id IS NULL AND b.source_id IS NULL ORDER BY o.issued_at DESC LIMIT 500",[$a['club_id']])->fetchAll();
        foreach($rows as $row){
            try{$data=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){continue;}
            if(!is_array($data)||!is_string($data['delivery_date']??null)||TaxCatalog::documentYear($data['delivery_date'])!==$year||!is_array($data['positions']??null)||!array_is_list($data['positions'])||count($data['positions'])!==1)continue;
            $position=$data['positions'][0];
            if(!is_array($position)||!is_int($position['gross']??null)||!is_string($position['description']??null)||($position['quantity']??null)!==1000||($position['unit']??null)!=='C62'||TaxCatalog::sphereOf((string)($position['sphere']??''))!==$plan['sphere']||!in_array($position['rate']??null,['0','19'],true))continue;
            try{$values=self::amounts($plan,$data['delivery_date'],(int)$position['gross'],(int)$position['rate']);}catch(DomainException){continue;}
            $out[]=['entry_id'=>$row['entry_id'],'label'=>$row['number'].' · '.$position['description'],'date'=>$data['delivery_date']]+$values;
        }
        return $out;
    }
    public static function saleCodes(int $rate,string $vatKind='domestic'):array
    {
        if(!in_array($vatKind,['domestic','export_4_1a','intra_eu_4_1b'],true)||!in_array($rate,[0,19],true)||($vatKind!=='domestic'&&$rate!==0))throw new DomainException('Anlagenverkauf: belegte Steuerbefreiung und Lieferungsart prüfen.');
        return match($vatKind){'export_4_1a'=>['48440','68840'],'intra_eu_4_1b'=>['48480','68880'],default=>[$rate===19?'48450':'48490',$rate===19?'68850':'68890']};
    }
    public static function amounts(array $plan,string $date,int $gross,int $rate,string $vatKind='domestic'):array
    {
        $year=TaxCatalog::documentYear($date);
        if(!$year||!in_array($year,TaxCatalog::YEARS,true)||$date<($plan['acquired_on']??'')||$gross<=0||$gross>BalanceCalculation::LIMIT||!in_array($rate,[0,19],true))
            throw new DomainException('Vollständigen inländischen Anlagenverkauf mit 19 % Umsatzsteuer oder belegter Steuerbefreiung prüfen.');
        if(!in_array($plan['account']??'',AssetTypes::codes('disposable'),true))throw new DomainException('Verkauf ist hier für bewegliche Ausstattung vorgesehen.');
        $schedule=AssetBooks::schedule($plan,$year,(int)substr($date,5,2));
        $vat=$rate===0?0:intdiv($gross*$rate+intdiv(100+$rate,2),100+$rate);$net=$gross-$vat;
        $gain=$net>=$schedule['closing_cents'];[$gainCode,$lossCode]=self::saleCodes($rate,$vatKind);
        return ['gross_cents'=>$gross,'tax_cents'=>$vat,'net_cents'=>$net,'book_cents'=>$schedule['closing_cents'],
            'result_cents'=>$net-$schedule['closing_cents'],'gain'=>$gain,
            'sale_code'=>$gain?$gainCode:$lossCode,
            'book_code'=>$gain?'48550':'68950','group'=>$gain?'revenue':'expense'];
    }
    public static function hash(array $snapshot):string{return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    /** Natural journal balance for an exact disposal code; null means not evidenced by this item. */
    public static function movement(array $item,string $code,int $year):?int
    {
        $proof=$item['disposal']??null;
        if(!$proof||TaxCatalog::documentYear($proof['date'])!==$year)return null;
        if(in_array($proof['kind'],['scrap','partial_scrap'],true))return $code==='68950'?($item['book_disposals_cents']??0):null;
        if($proof['kind']==='software_end')return $code==='68960'?($item['book_disposals_cents']??0):null;
        if($proof['kind']!=='sale')return null;
        $plan=['basis_cents'=>$item['basis_cents'],'residual_cents'=>$item['residual_cents'],'months'=>$item['months'],
            'acquired_on'=>$item['acquired_on'],'account'=>$item['skr_code'],'method'=>$item['method']??'linear_monthly'];
        foreach(['gwg','degressive'] as $key)if(isset($item[$key]))$plan[$key]=$item[$key];
        AssetDisposal::check($plan,$proof);$v=self::amounts($plan,$proof['date'],$proof['gross_cents'],$proof['rate'],$proof['vat_kind']??'domestic');
        if($code===$v['sale_code'])return $v['gain']?$v['net_cents']:-$v['net_cents'];
        if($code===$v['book_code'])return $v['gain']?-$v['book_cents']:$v['book_cents'];
        // The opposite gain/loss branch may be reserved or reversed in a tax ledger.
        if(in_array($code,[...self::saleCodes($proof['rate'],$proof['vat_kind']??'domestic'),'48550','68950'],true))return 0;
        return null;
    }
    /** Caller holds the club writer lock; original prices and issued PDF are never changed. */
    public static function prepare(PDO $db,string $actor,array $plan,array $input):array
    {
        if(!$db->inTransaction())throw new DomainException('Anlagenverkauf benötigt einen Speichervorgang.');
        $a=Permissions::actor($db,$actor);$entry=$input['sale_entry']??null;$date=$input['disposal_date']??null;
        if(!is_string($entry)||!preg_match('/^[a-f0-9]{32}$/D',$entry)||!is_string($date)||($input['sale_confirmed']??'')!=='1')
            throw new DomainException('Verkaufsrechnung, Übergabedatum und vollständigen Verkauf ausdrücklich bestätigen.');
        $sources=new BalanceSources($db,$actor);$evidence=$sources->issuedEvidence($entry,$date,true);
        if(DocumentCatalog::query($db,"SELECT 1 FROM balance_period_sources WHERE club_id=? AND source_type='invoice' AND source_id=? FOR UPDATE",[$a['club_id'],$entry])->fetchColumn()
            ||DocumentCatalog::query($db,'SELECT 1 FROM balance_period_asset_sales WHERE club_id=? AND entry_id=? FOR UPDATE',[$a['club_id'],$entry])->fetchColumn())throw new DomainException('Verkaufsrechnung gehört bereits zu einem Abschlusszeitraum. Keine zweite Anlagenzuordnung im Kalenderjahr.');
        if($sources->binding('invoice',$entry))throw new DomainException('Die Verkaufsrechnung wurde bereits in die Bilanz übernommen. Vor einer Anlagenzuordnung gesondert abstimmen.');
        if(DocumentCatalog::query($db,'SELECT item_id FROM asset_sale_links WHERE club_id=? AND entry_id=? FOR UPDATE',[$a['club_id'],$entry])->fetchColumn())
            throw new DomainException('Diese Verkaufsrechnung gehört bereits zu einem anderen Anlagenabgang.');
        if(count($evidence['positions'])!==1||$evidence['assets']||$evidence['claim'])throw new DomainException('Pro Anlagenverkauf eine eigene Rechnung mit genau einem Gegenstand verwenden.');
        $position=$evidence['positions'][0];$issued=$evidence['issued_invoice']['payload'];
        if(TaxCatalog::sphereOf((string)$position['sphere'])!==$plan['sphere']||$issued['positions'][0]['quantity']!==1000||$issued['positions'][0]['unit']!=='C62')
            throw new DomainException('Verkaufsrechnung muss genau ein Stück und denselben Vereinsbereich wie der Gegenstand enthalten.');
        $vatKind=$input['sale_vat_kind']??'domestic';if(!is_string($vatKind))throw new DomainException('Lieferungsart prüfen.');
        $values=self::amounts($plan,$date,(int)$position['gross_cents'],(int)$position['tax_rate'],$vatKind);
        if((int)$position['tax_cents']!==$values['tax_cents'])throw new DomainException('Umsatzsteuer der Verkaufsrechnung stimmt nicht überein.');
        if((int)$position['tax_rate']===0&&trim((string)($issued['vat_reason']??''))==='')throw new DomainException('Steuerbefreiung muss aus der ausgegebenen Verkaufsrechnung hervorgehen.');
        $finance=new Finance($db,$actor);
        $finance->updatePositionsLocked('entry',$entry,(int)$finance->entry($entry)['version'],[[
            'description'=>$position['description'],'amount'=>OutgoingInvoices::amount($values['gross_cents']),
            'tax_rate'=>(string)$position['tax_rate'],'sphere'=>$position['sphere'],'account'=>$values['sale_code'],'booking_key'=>'','input_tax'=>'unknown'
        ]],'Anlagenverkauf: Erlös und Restbuchwert getrennt nach SKR42 zugeordnet.');
        $evidence=$sources->issuedEvidence($entry,$date,true);$id=$evidence['issued_invoice']['document_id'];
        $document=array_values(array_filter($evidence['documents'],static fn($d)=>$d['id']===$id))[0];
        return ['kind'=>'sale','date'=>$date,'reason'=>trim((string)($input['disposal_reason']??'')),
            'document_id'=>$id,'revision'=>(int)$document['revision'],'sha256'=>$document['sha256'],'complete'=>true,
            'sale_entry'=>$entry,'sale_hash'=>self::hash($evidence),'gross_cents'=>$values['gross_cents'],
            'tax_cents'=>$values['tax_cents'],'rate'=>(int)$position['tax_rate'],'vat_reason'=>$issued['vat_reason']]+($vatKind==='domestic'?[]:['vat_kind'=>$vatKind]);
    }
    public static function check(array $plan,array $proof):void
    {
        if(array_diff(array_keys($proof),['kind','date','reason','document_id','revision','sha256','complete','sale_entry','sale_hash','gross_cents','tax_cents','rate','vat_reason','vat_kind'])
            ||!is_string($proof['sale_entry']??null)||!preg_match('/^[a-f0-9]{32}$/D',$proof['sale_entry'])
            ||!is_string($proof['sale_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$proof['sale_hash'])
            ||(array_key_exists('vat_kind',$proof)&&!is_string($proof['vat_kind']))
            ||!is_int($proof['gross_cents']??null)||!is_int($proof['tax_cents']??null)||!is_int($proof['rate']??null)
            ||!is_string($proof['vat_reason']??null)||mb_strlen($proof['vat_reason'])>500||($proof['rate']===0&&trim($proof['vat_reason'])===''))
            throw new DomainException('Vollständigen Verkaufsnachweis prüfen.');
        $values=self::amounts($plan,$proof['date'],$proof['gross_cents'],$proof['rate'],$proof['vat_kind']??'domestic');
        if($values['tax_cents']!==$proof['tax_cents'])throw new DomainException('Umsatzsteuer im Verkaufsnachweis prüfen.');
    }
}
