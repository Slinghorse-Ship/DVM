<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

/** Own RFC 6238 implementation using PHP HMAC; no reference source code is embedded. */
final class Totp
{
    private const ALPHABET='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public static function secret():string{return self::encode(random_bytes(20));}
    public static function encode(string $bytes):string
    {
        $bits=0;$buffer=0;$result='';
        for($i=0;$i<strlen($bytes);$i++){$buffer=($buffer<<8)|ord($bytes[$i]);$bits+=8;while($bits>=5){$bits-=5;$result.=self::ALPHABET[($buffer>>$bits)&31];}$buffer&=(1<<$bits)-1;}
        if($bits)$result.=self::ALPHABET[($buffer<<(5-$bits))&31];return $result;
    }
    private static function decode(string $secret):string
    {
        if(!preg_match('/^[A-Z2-7]{16,160}$/D',$secret))throw new \DomainException('Ungültiger TOTP-Schlüssel.');
        $buffer=0;$bits=0;$bytes='';
        for($i=0;$i<strlen($secret);$i++){$buffer=($buffer<<5)|strpos(self::ALPHABET,$secret[$i]);$bits+=5;if($bits>=8){$bits-=8;$bytes.=chr(($buffer>>$bits)&255);}$buffer&=(1<<$bits)-1;}
        if($bits&&$buffer!==0)throw new \DomainException('Ungültiger TOTP-Schlüssel.');return $bytes;
    }
    public static function code(string $secret,int $timestamp,int $digits=6,string $algorithm='sha1'):string
    {
        if($timestamp<0||!in_array($digits,[6,8],true)||!in_array($algorithm,['sha1','sha256','sha512'],true))throw new \DomainException('Ungültige TOTP-Parameter.');
        $counter=intdiv($timestamp,30);$message=pack('N2',intdiv($counter,4294967296),$counter%4294967296);
        $mac=hash_hmac($algorithm,$message,self::decode($secret),true);$offset=ord($mac[strlen($mac)-1])&15;
        $number=((ord($mac[$offset])&127)<<24)|(ord($mac[$offset+1])<<16)|(ord($mac[$offset+2])<<8)|ord($mac[$offset+3]);
        return str_pad((string)($number%(10**$digits)),$digits,'0',STR_PAD_LEFT);
    }
    /** Returns an unused counter in the bounded drift window, never the same accepted step twice. */
    public static function match(string $secret,string $code,int $now,int $lastCounter):?int
    {
        if(!preg_match('/^[0-9]{6}$/D',$code))return null;
        $current=intdiv($now,30);
        foreach([$current,$current-1,$current+1] as $counter)if($counter>=0&&$counter>$lastCounter&&hash_equals(self::code($secret,$counter*30),$code))return $counter;
        return null;
    }
}
