<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Signed save link for an existing, verified club-owned Google Generic Class. */
final class MemberCardGoogleWallet
{
 private static function base64url(string $bytes):string{return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
 public static function token(array $card,#[\SensitiveParameter] array $credentials,int $now):string
 {
  $issuer=$credentials['issuer_id']??null;$class=$credentials['class_id']??null;$email=$credentials['client_email']??null;$key=$credentials['private_key']??null;
  if(!is_string($issuer)||!preg_match('/\A[0-9]{6,30}\z/D',$issuer)||!is_string($class)||!preg_match('/\A'.preg_quote($issuer,'/').'\.[A-Za-z0-9._-]{1,100}\z/D',$class)||!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!str_ends_with($email,'.iam.gserviceaccount.com')||!is_string($key)||$now<1)throw new DomainException('Google Wallet ist nicht vollständig eingerichtet.',422);
  [$issued,$expires]=MemberCardWalletPayload::dates($card,$now);
  $locale=($card['locale']??'de')==='en'?'en':'de';$text=static fn(string $value):array=>['defaultValue'=>['language'=>$locale,'value'=>$value]];
  $object=['id'=>$issuer.'.dvm_'.$card['id'],'classId'=>$class,'state'=>'ACTIVE','cardTitle'=>$text($card['club_name']),'header'=>$text($card['name']),'subheader'=>$text($locale==='en'?'Membership card':'Mitgliederausweis'),'barcode'=>['type'=>'QR_CODE','value'=>$card['verify_url'],'alternateText'=>$card['member_number']],'validTimeInterval'=>['start'=>['date'=>$issued->format(\DateTimeInterface::RFC3339)],'end'=>['date'=>$expires->format(\DateTimeInterface::RFC3339)]],'textModulesData'=>[['id'=>'member_number','header'=>$locale==='en'?'Member number':'Mitgliedsnummer','body'=>$card['member_number']]]];
  $claims=['iss'=>$email,'aud'=>'google','typ'=>'savetowallet','iat'=>$now,'exp'=>$now+1800,'origins'=>[MemberCardSource::origin('https://'.parse_url($card['verify_url'],PHP_URL_HOST))],'payload'=>['genericObjects'=>[$object]]];$header=['alg'=>'RS256','typ'=>'JWT'];$message=self::base64url(json_encode($header,JSON_THROW_ON_ERROR)).'.'.self::base64url(json_encode($claims,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $private=@openssl_pkey_get_private($key);$details=$private===false?false:openssl_pkey_get_details($private);if(!$details||$details['type']!==OPENSSL_KEYTYPE_RSA||$details['bits']<2048||!@openssl_sign($message,$signature,$private,OPENSSL_ALGO_SHA256))throw new DomainException('Google-Wallet-Schlüssel prüfen.',422);
  return$message.'.'.self::base64url($signature);
 }
}
