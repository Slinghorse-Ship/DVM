<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodDisposal.php';
require_once __DIR__.'/BalanceSourceHistory.php';

/** Read-only bridge: a retained calendar closing is evidence, never a replacement journal. */
final class BalancePeriodAssetCarry
{
    public function __construct(private PDO $db,private string $actor){}
    private function source(string $type,string $id,bool $lock):array
    {
        $sources=new BalanceSources($this->db,$this->actor);$binding=$sources->binding($type,$id);
        if(!$binding)throw new DomainException('Ursprüngliche Anschaffung oder Abschreibung im Kalenderjournal fehlt.');
        $p=$sources->preview((int)$binding['tax_year'],$type,$id,$binding['options'],$lock);
        if($p['issues']||!hash_equals($binding['source_hash'],$p['hash'])||!(new BalanceSourceHistory($this->db,$this->actor,$lock))->checked($binding))throw new DomainException('Kalenderbeleg und unveränderter Buchungsverlauf sind nicht abgestimmt.');
        return $p;
    }
    public function acquisition(string $id,array $period,bool $lock):array
    {
        $p=$this->source('invoice',$id,$lock);
        if(sprintf('%04d-12-31',$p['year'])>=$period['start'])throw new DomainException('Anschaffung im Kalenderjournal überschneidet sich mit dem neuen Abschlusszeitraum.');
        return $p;
    }
    public function previous(array $ref,string $item,array $plan,array $invoice,array $period,bool $lock):array
    {
        if(($ref['kind']??null)!=='calendar'||!ctype_digit($ref['id']??''))throw new DomainException('Festgeschriebenen Kalender-Vorzeitraum auswählen.');
        $year=(int)$ref['id'];$prior=(new BalanceLedger($this->db,$this->actor))->report($year);
        if($prior['state']!=='closed'||$prior['issues']||$prior['closed_sha256']!==$ref['sha256']||!BalancePeriod::follows(BalancePeriod::calendar($year),$period))throw new DomainException('Kalenderabschluss ist nicht mehr unverändert freigegeben.');
        $schedule=$prior['asset_schedule']??null;$asset=$schedule['items'][$item]??null;
        if(!$asset||$schedule['issues']||($asset['invoice_id']??null)!==$invoice['id']||($asset['acquisition_hash']??null)!==$invoice['hash'])throw new DomainException('Festgeschriebenes Anlagenverzeichnis und Anschaffungsnachweis stimmen nicht überein.');
        foreach(['sphere','acquired_on','months','residual_cents'] as $key)if(($asset[$key]??null)!==$plan[$key])throw new DomainException('Anlagenplan und festgeschriebenes Anlagenverzeichnis weichen ab.');
        if($asset['skr_code']!==$plan['account']||$asset['account']!==($invoice['snapshot']['asset_accounts'][$item]??null))throw new DomainException('Anlagenkonto im Kalenderabschluss prüfen.');
        if(($plan['method']??null)==='cost'){
            BalanceAssetAllocation::checkYear($asset,$year);
            $p=$this->source('depreciation',$item.':'.$year,$lock);$value=$p['snapshot']['valuation']??null;
            if(!is_array($value)||$value!==($asset['valuation']??null)||($asset['cost_plan']??null)!==$plan)
                throw new DomainException('Ursprünglichen Kostenbewertungsstand im Kalenderabschluss nachweisen.');
            $snapshot=['plan'=>$plan,'schedule'=>['period'=>BalancePeriod::calendar($year),'depreciation_cents'=>0]+$value['book'],
                'valuation'=>$value,'valuation_accounts'=>$asset['valuation_accounts']];
            $hash=hash('sha256',BalancePeriodLedger::json(['calendar-cost-asset-carry-v1',$ref,$item,$invoice['hash'],$p['hash'],$snapshot]));
            return ['hash'=>$hash,'snapshot'=>$snapshot];
        }
        $proof=$asset['disposal']??null;
        $sale=($proof['kind']??null)==='sale'?$this->sale($item,$plan,$proof,$lock):null;
        $sources=new BalanceSources($this->db,$this->actor);$previous=null;$hashes=[];
        for($y=TaxCatalog::documentYear($plan['acquired_on']);$y<=$year;++$y){
            $activeProof=$proof!==null&&$proof['date']<=sprintf('%04d-12-31',$y)?$proof:null;
            $s=BalancePeriodDisposal::calculate($plan,BalancePeriod::calendar($y),$previous,$activeProof);
            $binding=$sources->binding('depreciation',$item.':'.$y);
            if($binding||$s['depreciation_cents']>0||($activeProof!==null&&TaxCatalog::documentYear($activeProof['date'])===$y)){
                $p=$this->source('depreciation',$item.':'.$y,$lock);$annual=$p['snapshot']['annual'];
                [$storedPlan,$storedProof]=AssetDisposal::unpack(json_decode($annual['snapshot'],true,64,JSON_THROW_ON_ERROR));
                if($storedPlan!==$plan||$storedProof!==$activeProof)throw new DomainException('Historische Anlagenbewegung widerspricht dem festgeschriebenen Abschluss.');
                // Calendar rows historically store full acquisition cost as the first opening.
                if((int)$annual['depreciation_cents']!==$s['depreciation_cents']||(int)$annual['closing_cents']!==$s['book_closing_cents'])throw new DomainException('Historische Abschreibung und geprüfter Zeitraumwert sind nicht abgestimmt.');
                $hashes[(string)$y]=$p['hash'];
            }
            $previous=$s;
        }
        foreach(['cost_opening_cents','additions_cents','cost_closing_cents','accumulated_opening_cents','depreciation_cents','accumulated_closing_cents','book_opening_cents','book_closing_cents'] as $key)if(($asset[$key]??null)!==$previous[$key])throw new DomainException('Kalender-Anlagenverzeichnis stimmt nicht centgenau mit dem bestätigten Originalplan überein.');
        foreach(['disposals_cents','accumulated_disposals_cents','book_disposals_cents'] as $key)if(($asset[$key]??0)!==($previous[$key]??0))throw new DomainException('Historischer Anlagenabgang ist nicht abgestimmt.');
        $snapshot=['plan'=>$plan,'schedule'=>$previous];if($proof!==null)$snapshot['disposal']=$proof;
        if($sale!==null)$snapshot+=$sale;
        $hash=hash('sha256',BalancePeriodLedger::json(['calendar-asset-carry-v1',$ref,$item,$invoice['hash'],$hashes,$snapshot]));
        return ['hash'=>$hash,'snapshot'=>$snapshot];
    }
    /** Revalidate calendar evidence; it is carried at zero, never reclassified or posted again. */
    public function sale(string $item,array $plan,array $proof,bool $lock):array
    {
        $year=TaxCatalog::documentYear($proof['date']);
        $r=(new BalanceLedger($this->db,$this->actor))->report($year);
        if($r['state']!=='closed'||$r['issues'])throw new DomainException('Ursprünglichen Kalenderabschluss des Verkaufs zuerst abstimmen.');
        $p=$this->source('depreciation',$item.':'.$year,$lock);
        [$oldPlan,$oldProof]=AssetDisposal::unpack(json_decode($p['snapshot']['annual']['snapshot'],true,64,JSON_THROW_ON_ERROR));
        $invoice=$this->source('invoice',$proof['sale_entry'],$lock);
        if($oldPlan!==$plan||$oldProof!==$proof||$invoice['year']!==$year
            ||($invoice['snapshot']['asset_sale']['item_id']??null)!==$item
            ||($p['snapshot']['sale_invoice_hash']??null)!==$invoice['hash'])throw new DomainException('Historischer Verkauf und unveränderte Originalbelege stimmen nicht überein.');
        return ['sale_values'=>AssetSale::amounts($plan,$proof['date'],$proof['gross_cents'],$proof['rate'],$proof['vat_kind']??'domestic'),
            'sale_origin'=>['kind'=>'calendar','year'=>$year,'invoice_sha256'=>$invoice['hash'],'disposal_sha256'=>$p['hash']]];
    }
}
