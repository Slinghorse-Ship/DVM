<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;
final class Money
{
    public static function cents(mixed $amount):int
    {
        if(is_float($amount)||is_int($amount)){
            if(!is_finite((float)$amount)||$amount<0||$amount>1000000||abs($amount*100-round($amount*100))>0.000001)throw new \DomainException('Zahlungsbetrag ungenau.');
            $amount=number_format($amount,2,'.','');
        }
        if(!is_string($amount)||!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D',$amount,$m))throw new \DomainException('Zahlungsbetrag ungültig.');
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public static function decimal(int $cents):string
    {if($cents<0||$cents>100000000)throw new \DomainException('Betrag außerhalb des Limits.');return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
    public static function identifier(string $id):string
    {if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id))throw new \DomainException('Ungültige Zahlungsreferenz.');return rawurlencode($id);}
}
