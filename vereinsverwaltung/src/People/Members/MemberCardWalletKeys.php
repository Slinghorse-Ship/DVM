<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Validate actual signing credentials. Pure formatters cannot confer provider trust. */
final class MemberCardWalletKeys
{
    public static function apple(string $type,string $team,#[\SensitiveParameter]string $p12,#[\SensitiveParameter]string $password,int $now):array
    {
        if(!preg_match('/\Apass\.[A-Za-z0-9.-]{1,180}\z/D',$type)||!preg_match('/\A[A-Z0-9]{10}\z/D',$team)||strlen($p12)<100||strlen($p12)>262144||strlen($password)>1000)throw new DomainException('Pass-Kennung, Team und Apple-Zertifikat prüfen.',422);
        $parts=[];if(!@openssl_pkcs12_read($p12,$parts,$password)||!is_string($parts['cert']??null)||!is_string($parts['pkey']??null))throw new DomainException('Apple-Zertifikat oder dessen Passwort prüfen.',422);
        return self::appleStored(['pass_type_id'=>$type,'team_id'=>$team,'certificate'=>$parts['cert'],'private_key'=>$parts['pkey']],$now);
    }
    public static function appleStored(#[\SensitiveParameter]array $credentials,int $now):array
    {
        $type=$credentials['pass_type_id']??null;$team=$credentials['team_id']??null;$certPem=$credentials['certificate']??null;$privatePem=$credentials['private_key']??null;
        if(!is_string($type)||!preg_match('/\Apass\.[A-Za-z0-9.-]{1,180}\z/D',$type)||!is_string($team)||!preg_match('/\A[A-Z0-9]{10}\z/D',$team)||!is_string($certPem)||!is_string($privatePem))throw new DomainException('Apple-Wallet-Einrichtung prüfen.',422);
        $cert=@openssl_x509_read($certPem);$key=@openssl_pkey_get_private($privatePem);$info=$cert===false?false:openssl_x509_parse($cert);
        if(!$info||$key===false||!openssl_x509_check_private_key($cert,$key)||($info['subject']['UID']??null)!==$type||($info['subject']['OU']??null)!==$team)throw new DomainException('Apple-Zertifikat muss zur Pass-Kennung, zum Team und zum privaten Schlüssel gehören.',422);
        $path=dirname(__DIR__,3).'/resources/wallet/';$issuer=file_get_contents($path.'AppleWWDRCAG4.cer');$root=file_get_contents($path.'AppleIncRootCertificate.cer');
        if(!is_string($issuer)||!hash_equals('ea4757885538dd8cb59ff4556f676087d83c85e70902c122e42c0808b5bce14c',hash('sha256',$issuer))||!is_string($root)||!hash_equals('b0b1730ecbc7ff4505142c49f1295e6eda6bcaed7e2c68c5be91b5a11001f024',hash('sha256',$root)))throw new DomainException('Öffentliche Apple-Zertifikatskette ist nicht verfügbar.',503);
        $pem=static fn(string$der):string=>"-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der),64,"\n")."-----END CERTIFICATE-----\n";
        if(!self::chain($certPem,$pem($issuer),$pem($root),$now))throw new DomainException('Apple-Pass-Zertifikat ist abgelaufen oder nicht von der unterstützten Apple-Zertifikatskette bestätigt.',422);
        return['pass_type_id'=>$type,'team_id'=>$team,'certificate'=>$certPem,'private_key'=>$privatePem,'wwdr_chain'=>$pem($issuer),'certificate_expires_at'=>gmdate(DATE_ATOM,$info['validTo_time_t']),'certificate_fingerprint'=>openssl_x509_fingerprint($cert,'sha256')];
    }
    /** Mathematical chain verification; production apple() always supplies fixed Apple anchors. */
    public static function chain(string $leaf,string $issuer,string $root,int $now):bool
    {
        $certs=[];foreach([$leaf,$issuer,$root]as$pem){$c=@openssl_x509_read($pem);$d=$c===false?false:openssl_x509_parse($c);if(!$d||($d['validFrom_time_t']??PHP_INT_MAX)>$now||($d['validTo_time_t']??0)<=$now)return false;$certs[]=[$c,$d];}
        foreach([[0,1],[1,2],[2,2]]as[$a,$b]){if($certs[$a][1]['issuer']!==$certs[$b][1]['subject'])return false;$key=openssl_pkey_get_public($certs[$b][0]);if($key===false||openssl_x509_verify($certs[$a][0],$key)!==1)return false;}
        foreach([1,2]as$i)if(!str_contains($certs[$i][1]['extensions']['basicConstraints']??'','CA:TRUE'))return false;
        $usage=$certs[0][1]['extensions']['keyUsage']??'';if($usage!==''&&!str_contains($usage,'Digital Signature'))return false;return true;
    }
    public static function google(string $issuer,string $class,#[\SensitiveParameter]string $json):array
    {
        if(!preg_match('/\A[0-9]{6,30}\z/D',$issuer)||!preg_match('/\A'.preg_quote($issuer,'/').'\.[A-Za-z0-9._-]{1,100}\z/D',$class)||strlen($json)>131072)throw new DomainException('Google-Aussteller und Ausweisklasse prüfen.',422);
        try{$d=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Google-Dienstkontodatei prüfen.',422);}
        if(!is_array($d)||($d['type']??null)!=='service_account'||($d['token_uri']??null)!=='https://oauth2.googleapis.com/token'||!is_string($d['client_email']??null)||!filter_var($d['client_email'],FILTER_VALIDATE_EMAIL)||!str_ends_with($d['client_email'],'.iam.gserviceaccount.com')||!is_string($d['private_key']??null))throw new DomainException('Google-Dienstkonto und privaten Schlüssel prüfen.',422);
        $key=@openssl_pkey_get_private($d['private_key']);$info=$key===false?false:openssl_pkey_get_details($key);if(!$info||$info['type']!==OPENSSL_KEYTYPE_RSA||$info['bits']<2048)throw new DomainException('Google Wallet benötigt einen gültigen privaten RSA-Schlüssel mit mindestens 2048 Bit.',422);
        return['issuer_id'=>$issuer,'class_id'=>$class,'client_email'=>$d['client_email'],'private_key'=>$d['private_key']];
    }
    public static function publicMetadata(string $provider,#[\SensitiveParameter]array $credentials):array
    {
        $keys=match($provider){'apple'=>['pass_type_id','team_id','certificate_expires_at','certificate_fingerprint'],'google'=>['issuer_id','class_id','client_email'],default=>throw new DomainException('Wallet-Anbieter prüfen.',422)};
        return array_intersect_key($credentials,array_flip($keys));
    }
}
