<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use Kulturhus\Native\Payments\{PayPal,Money};
use Kulturhus\Native\Shared\Db;
/** PayPal is a separate mapped finance account. Fees remain separate from gross payments. */
final class PayPalAccount
{
    public function __construct(private PDO $db,private string $actor,private PayPal $api,private array $account){}
    private function access():array
    {$a=Permissions::actor($this->db,$this->actor);if($a['finance_access']!=='write'||($this->account['mode']??'')!=='live'||empty($this->account['merchant_id']))throw new \DomainException('Finanz-Schreibrecht und produktives PayPal-Konto erforderlich.');return $a;}
    public function remote():array
    {$this->access();return ['identification_hash'=>'paypal:'.$this->account['merchant_id'],'name'=>'PayPal · '.$this->account['merchant_id']];}
    public function map(string $financeAccount):string
    {$remote=$this->remote();$this->api->balances();return (new BankImport($this->db,$this->actor))->map('paypal','PRODUCTION',$remote,$financeAccount);}
    public static function rows(array $details):array
    {
        $rows=[];
        foreach($details as $detail){$info=$detail['transaction_info']??[];$id=$info['transaction_id']??'';$event=$info['transaction_event_code']??'';$status=$info['transaction_status']??'';
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9]{1,24}$/D',$id)||!is_string($event)||!preg_match('/^T[0-9]{4}$/D',$event)||!in_array($status,['S','P','D','V'],true)||!is_string($info['transaction_initiation_date']??null))throw new \DomainException('PayPal-Umsatz unvollständig.');
            $date=(new \DateTimeImmutable($info['transaction_initiation_date']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
            $party=($detail['payer_info']['payer_name']['given_name']??'').' '.($detail['payer_info']['payer_name']['surname']??'');$party=mb_substr(trim($party),0,190);
            foreach(['gross'=>'transaction_amount','fee'=>'fee_amount'] as $kind=>$field){$amount=$info[$field]??null;if($amount===null&&$kind==='fee')continue;$value=$amount['value']??null;$currency=$amount['currency_code']??null;
                if(!is_string($value)||!preg_match('/^-?(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',$value)||!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))throw new \DomainException('PayPal-Betrag ungültig.');$negative=str_starts_with($value,'-');$absolute=ltrim($value,'-');if(Money::cents($absolute)===0)continue;
                // The referenced bank row records reversals as a conflict; it never rewrites an old booking.
                $rows[]=['entry_reference'=>'paypal:'.$id.':'.$event.':'.$kind,'status'=>$status==='S'?'BOOK':'PDNG','booking_date'=>$date,'transaction_amount'=>['amount'=>$absolute,'currency'=>$currency],'credit_debit_indicator'=>$negative?'DBIT':'CRDT','remittance_information'=>[($kind==='fee'?'PayPal-Gebühr':'PayPal-Umsatz').' '.$id.' '.$event],'debtor'=>['name'=>$party],'creditor'=>['name'=>$kind==='fee'?'PayPal':$party]];
            }
        }return $rows;
    }
    public function sync(string $mapping,string $from,string $to,string $key):array
    {
        $a=$this->access();$service=new BankImport($this->db,$this->actor);$map=$service->mapping($mapping);if($map['provider']!=='paypal')throw new \DomainException('PayPal-Kontozuordnung erforderlich.');
        $rows=self::rows($this->api->reporting($from,$to));$balance=$this->api->balances();$this->access();
        $run=$service->fetch($mapping,'PRODUCTION',$this->remote(),$from,$to,$key,static fn($next)=>['transactions'=>$rows,'continuation_key'=>'']);
        if($run['state']==='complete')Db::run($this->db,'INSERT INTO paypal_balance_snapshots(club_id,mapping_id,observed_at,payload) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE observed_at=VALUES(observed_at),payload=VALUES(payload)',[$a['club_id'],$mapping,$balance['as_of_time'],json_encode($balance,JSON_THROW_ON_ERROR)]);
        return $run;
    }
}
