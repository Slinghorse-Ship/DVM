<?php declare(strict_types=1);
namespace Kulturhus\Native\Payments;

/** A signed Stripe notification is a trigger for canonical verification, never the payment proof itself. */
final class StripeWebhook
{
    public static function verify(string $body,string $header,#[\SensitiveParameter] string $secret,?int $now=null):array
    {
        if(strlen($body)>65536||strlen($header)>2048||!preg_match('/^whsec_[A-Za-z0-9]{1,200}$/D',$secret))throw new \DomainException('Stripe-Rückmeldung prüfen.');
        $timestamp=null;$signatures=[];
        foreach(explode(',',$header)as$part){$pair=explode('=',trim($part),2);if(count($pair)!==2)throw new \DomainException('Stripe-Signatur ungültig.');if($pair[0]==='t'){if($timestamp!==null||!preg_match('/^[0-9]{1,12}$/D',$pair[1]))throw new \DomainException('Stripe-Signaturzeit prüfen.');$timestamp=(int)$pair[1];}elseif($pair[0]==='v1'){if(!preg_match('/^[a-f0-9]{64}$/D',$pair[1]))throw new \DomainException('Stripe-Signatur ungültig.');$signatures[]=$pair[1];}}
        if($timestamp===null||abs(($now??time())-$timestamp)>300||$signatures===[])throw new \DomainException('Stripe-Signatur abgelaufen oder unvollständig.');
        $expected=hash_hmac('sha256',$timestamp.'.'.$body,$secret);$valid=false;foreach($signatures as$signature)$valid=$valid||hash_equals($expected,$signature);
        if(!$valid)throw new \DomainException('Stripe-Signatur stimmt nicht überein.');
        try{$event=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new \DomainException('Stripe-Rückmeldung unlesbar.');}
        if(!is_array($event)||!is_string($event['id']??null)||!preg_match('/^evt_[A-Za-z0-9]{1,100}$/D',$event['id'])||!is_string($event['type']??null)||!is_bool($event['livemode']??null)||!is_array($event['data']['object']??null))throw new \DomainException('Stripe-Rückmeldung unvollständig.');
        return $event;
    }
}
