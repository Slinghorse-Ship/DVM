<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;

/** One-time compatibility for operator-preserved ProcessWire bcrypt passwords. */
final class PreservedPassword
{
    private const PREFIX='DVM-PW1:';

    /** No web request imports credentials. The envelope is bound to its native account. */
    public static function seal(#[\SensitiveParameter] string $bcrypt,#[\SensitiveParameter] string $systemSalt,#[\SensitiveParameter] string $key,string $accountId): string
    {
        if(PHP_SAPI!=='cli'||!self::bcrypt($bcrypt)||$systemSalt===''||str_contains($systemSalt,"\0")||strlen($systemSalt)>80||strlen($key)!==32||!preg_match('/\A[a-f0-9]{32}\z/D',$accountId))throw new \DomainException('Ursprüngliche Zugangsdaten und private Kontozuordnung prüfen.');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($bcrypt.$systemSalt,$nonce,self::key($key,$accountId)));
    }

    public static function verify(#[\SensitiveParameter] string $password,#[\SensitiveParameter] string $stored,#[\SensitiveParameter] string $key,string $accountId): bool
    {
        if(!str_starts_with($stored,self::PREFIX))return password_verify($password,$stored);
        if(strlen($key)!==32||!preg_match('/\A[a-f0-9]{32}\z/D',$accountId)||strlen($stored)>255)return false;
        $encoded=substr($stored,strlen(self::PREFIX));$raw=base64_decode($encoded,true);
        if($raw===false||base64_encode($raw)!==$encoded||strlen($raw)<101||strlen($raw)>180)return false;
        $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,self::key($key,$accountId));
        if($plain===false)return false;
        try{$hash=substr($plain,0,60);return self::bcrypt($hash)&&password_verify($password.substr($plain,60),$hash);}
        finally{sodium_memzero($plain);}
    }

    private static function key(#[\SensitiveParameter] string $key,string $accountId): string
    {return hash_hkdf('sha256',$key,32,'dvm-preserved-processwire-bcrypt-v1',$accountId);}

    private static function bcrypt(string $hash): bool
    {return preg_match('~\A\$2[aby]\$(?:0[4-9]|1[0-6])\$[./A-Za-z0-9]{53}\z~D',$hash)===1;}
}
