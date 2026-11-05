<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Fictional bank responses only. Never constructs a FinTS client or calls a transport. */
final class FinTsMock
{
    public static function rows(string $from,string $to,mixed $scenario='normal',?\PDO $db=null,?string $actor=null):array
    {
        if(!Runtime::active())throw new \DomainException('Getrennte öffentliche Demo erforderlich.');
        if(!is_string($scenario)||!in_array($scenario,['normal','unavailable'],true))throw new \DomainException('Demo-Szenario auswählen.');
        if($scenario==='unavailable')throw new \DomainException(Runtime::locale()==='en'?'Simulation: the demo bank is temporarily unavailable. Retry with the normal scenario.':'Simulation: Die Demo-Bank ist vorübergehend nicht erreichbar. Mit dem normalen Szenario erneut versuchen.');
        $path=Runtime::root().'/installed.json';Configuration::privateFile($path);$installation=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        $day=$installation['sample_day']??null;if(!is_string($day)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$day))throw new \DomainException('Fiktiver Bankbestand nicht eingerichtet.');
        $to=min($to,\Kulturhus\Verein\Finance::today());if($from>$to)return [];
        $rows=[];
        foreach([['IN-01','60.00','CRDT','Fiktiver FinTS-Mockup-Eingang / Fictional FinTS mock income'],['OUT-01','120.00','DBIT','Fiktiver FinTS-Mockup-Beleg / Fictional FinTS mock receipt'],['IN-02','90.00','CRDT','Weiterer fiktiver FinTS-Eingang / Another fictional FinTS income']] as [$id,$amount,$direction,$description]){
            if($day<$from||$day>$to)continue;
            $reference='DVM-DEMO-'.strtoupper(substr(Runtime::instance(),0,8)).'-'.$id;
            $rows[]=['status'=>'BOOK','transaction_amount'=>['currency'=>'EUR','amount'=>$amount],'credit_debit_indicator'=>$direction,'booking_date'=>$day,'entry_reference'=>$reference,'remittance_information'=>[$description,$reference],$direction==='CRDT'?'debtor':'creditor'=>['name'=>'Fiktive Demo-Bank / Fictional demo bank']];
        }
        // A submitted exercise order becomes a fictional bank row only when due.
        // Its immutable snapshot supplies the original purpose and party; no payment
        // is posted here. Import and reconciliation still use the ordinary workflow.
        if($db!==null&&$actor!==null){
            $access=\Kulturhus\Verein\Permissions::finance($db,$actor,true);
            if($access['club_id']!==Runtime::club())throw new \DomainException('Eigenen Demo-Verein auswählen.');
            $vault=\Kulturhus\Verein\MemberBankVault::fromEnvironment($db,$actor,\Kulturhus\Verein\ProductionRuntime::enabled());
            foreach([['finance_transfer_orders','execution_date','DBIT'],['sepa_orders','collection_date','CRDT']] as [$table,$date,$direction]){
                $query=$db->prepare("SELECT id,bank_reference FROM ".$table." WHERE club_id=? AND state='submitted' AND ".$date.">=? AND ".$date."<=? ORDER BY ".$date.",id");$query->execute([$access['club_id'],$from,$to]);
                foreach($query->fetchAll(\PDO::FETCH_ASSOC) as $order){
                    $reference='DEMO-FINTS-'.strtoupper(substr($order['id'],0,12));
                    if(!hash_equals($reference,(string)$order['bank_reference']))continue;
                    $service=$direction==='DBIT'?new \Kulturhus\Verein\TransferOrders($db,$actor,$vault):new \Kulturhus\Verein\SepaOrders($db,$actor,$vault);$saved=$service->get($order['id']);
                    $snapshot=$direction==='DBIT'?$saved['snapshot']:$vault->openCollection($saved['id'],$saved['sealed_snapshot']);
                    $source=$direction==='DBIT'?$snapshot['source']:$snapshot['creditor'];if(($source['iban']??null)!=='DE02120300000000202051')continue;
                    $rows[]=['status'=>'BOOK','transaction_amount'=>['currency'=>'EUR','amount'=>\Kulturhus\Verein\Finance::decimal((int)$saved['total_cents'])],'credit_debit_indicator'=>$direction,'booking_date'=>$saved[$date],'entry_reference'=>$reference,'remittance_information'=>[$direction==='DBIT'?$snapshot['purpose']:'Fiktiver Lastschrifteinzug / Fictional direct debit',$reference],$direction==='DBIT'?'creditor':'debtor'=>['name'=>$direction==='DBIT'?$snapshot['recipient']['name']:'Fiktive Demo-Mitglieder / Fictional demo members']];
                }
            }
        }
        return $rows;
    }
}
