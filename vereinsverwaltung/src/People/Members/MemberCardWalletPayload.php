<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DateTimeImmutable;
use DomainException;

/** One canonical original-card contract for both signed wallet formats. */
final class MemberCardWalletPayload
{
    public static function dates(array $card,int $now):array
    {
        foreach(['id'=>32,'club_name'=>255,'name'=>255,'member_number'=>128,'verify_url'=>1500,'issued_at'=>40,'expires_at'=>40]as$key=>$max)MemberCardSource::text($card[$key]??null,$max);
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$card['id']))throw new DomainException('Mitgliederausweis erneut öffnen.',422);
        $p=parse_url($card['verify_url']);$origin=isset($p['scheme'],$p['host'])?$p['scheme'].'://'.$p['host']:'';
        if(!$p||MemberCardSource::origin($origin)!==$origin||($p['path']??null)!=='/mitgliederausweis/pruefen'||!preg_match('/\Atoken=[a-f0-9]{64}\z/D',$p['query']??'')||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment']))throw new DomainException('Öffentliche Ausweisadresse prüfen.',422);
        $dates=[];foreach(['issued_at','expires_at']as$key){$value=$card[$key];if(!preg_match('/\A[12][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/D',$value))throw new DomainException('Gültigkeit des Ausweises prüfen.',422);
            try{$date=new DateTimeImmutable($value);}catch(\Exception){throw new DomainException('Gültigkeit des Ausweises prüfen.',422);}if($date->format(DATE_ATOM)!==str_replace('Z','+00:00',$value))throw new DomainException('Gültigkeit des Ausweises prüfen.',422);$dates[]=$date;
        }
        if($dates[0]->getTimestamp()>$now||$dates[1]<=$dates[0]||$dates[1]->getTimestamp()<=$now)throw new DomainException('Der Mitgliederausweis ist noch nicht oder nicht mehr gültig.',409);return$dates;
    }
}
