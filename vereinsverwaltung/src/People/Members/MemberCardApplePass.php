<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Only trusted Apple credentials reach the signed pass-package formatter. */
final class MemberCardApplePass
{
 public static function build(array $card,#[\SensitiveParameter] array $credentials,array $icons,int $now):string
 {
  $credentials=MemberCardWalletKeys::appleStored($credentials,$now);
  return self::package($card,$credentials,$icons,$now);
 }
 private static function package(array $card,#[\SensitiveParameter] array $credentials,array $icons,int $now):string
 {
  if(!function_exists('openssl_cms_sign')||!class_exists(\ZipArchive::class))throw new DomainException('Wallet-Ausgabe ist auf diesem Server nicht verfügbar.',503);
  $type=$credentials['pass_type_id']??null;$team=$credentials['team_id']??null;$certificate=$credentials['certificate']??null;$private=$credentials['private_key']??null;$chain=$credentials['wwdr_chain']??null;
  if(!is_string($type)||!preg_match('/\Apass\.[A-Za-z0-9.-]{1,180}\z/D',$type)||!is_string($team)||!preg_match('/\A[A-Z0-9]{10}\z/D',$team)||!is_string($certificate)||!is_string($private)||!is_string($chain)||$chain==='')throw new DomainException('Apple Wallet ist nicht vollständig eingerichtet.',422);
  $cert=@openssl_x509_read($certificate);$key=@openssl_pkey_get_private($private);$details=$cert===false?false:openssl_x509_parse($cert);
  if(!$details||$key===false||!openssl_x509_check_private_key($cert,$key)||($details['subject']['UID']??null)!==$type||($details['subject']['OU']??null)!==$team||($details['validFrom_time_t']??PHP_INT_MAX)>$now||($details['validTo_time_t']??0)<=$now)throw new DomainException('Apple-Wallet-Zertifikat und Schlüssel prüfen.',422);
  [$issued,$expires]=MemberCardWalletPayload::dates($card,$now);
  $files=[];foreach(['icon.png'=>29,'icon@2x.png'=>58]as$name=>$size){$bytes=$icons[$name]??null;if(!is_string($bytes)||strlen($bytes)>1048576||!str_starts_with($bytes,"\x89PNG\r\n\x1a\n"))throw new DomainException('Ausweislogo prüfen.',422);$image=@getimagesizefromstring($bytes);if(!$image||$image[0]!==$size||$image[1]!==$size)$image=false;if(!$image)throw new DomainException('Ausweislogo prüfen.',422);$files[$name]=$bytes;}
  $en=($card['locale']??'de')==='en';$pass=['formatVersion'=>1,'passTypeIdentifier'=>$type,'teamIdentifier'=>$team,'serialNumber'=>$card['id'],'organizationName'=>$card['club_name'],'description'=>$en?'Membership card':'Mitgliederausweis','logoText'=>$card['club_name'],'foregroundColor'=>'rgb(255, 255, 255)','backgroundColor'=>'rgb(36, 84, 62)','labelColor'=>'rgb(225, 239, 229)','expirationDate'=>$expires->format(\DateTimeInterface::RFC3339),'generic'=>['primaryFields'=>[['key'=>'member_name','label'=>$en?'MEMBER':'MITGLIED','value'=>$card['name']]],'secondaryFields'=>[['key'=>'member_number','label'=>$en?'MEMBER NUMBER':'MITGLIEDSNUMMER','value'=>$card['member_number']]],'auxiliaryFields'=>[['key'=>'valid_until','label'=>$en?'VALID UNTIL':'GÜLTIG BIS','value'=>$expires->format('Y-m-d')]],'backFields'=>[['key'=>'verification','label'=>$en?'CURRENT VALIDITY':'AKTUELLE GÜLTIGKEIT','value'=>$en?'Scan the QR code to check current membership and validity.':'QR-Code scannen, um die aktuelle Mitgliedschaft und Gültigkeit zu prüfen.']]],'barcodes'=>[['format'=>'PKBarcodeFormatQR','message'=>$card['verify_url'],'messageEncoding'=>'iso-8859-1','altText'=>$card['member_number']]]];$files['pass.json']=json_encode($pass,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);ksort($files);$manifest=[];foreach($files as$name=>$bytes)$manifest[$name]=sha1($bytes);$manifest=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $directory=sys_get_temp_dir().'/dvm-card-'.bin2hex(random_bytes(16));if(!mkdir($directory,0700))throw new DomainException('Wallet-Ausgabe ist vorübergehend nicht verfügbar.',503);
  try{
   foreach(['manifest.json'=>$manifest,'chain.pem'=>$chain]as$name=>$bytes)if(file_put_contents($directory.'/'.$name,$bytes)!==strlen($bytes)||!chmod($directory.'/'.$name,0600))throw new DomainException('Wallet-Ausgabe ist vorübergehend nicht verfügbar.',503);
   if(!@openssl_cms_sign($directory.'/manifest.json',$directory.'/signature',$cert,$key,[],OPENSSL_CMS_BINARY|OPENSSL_CMS_DETACHED,OPENSSL_ENCODING_DER,$directory.'/chain.pem'))throw new DomainException('Der Wallet-Ausweis konnte nicht signiert werden.',503);chmod($directory.'/signature',0600);$files['manifest.json']=$manifest;$files['signature']=file_get_contents($directory.'/signature');$archive=new \ZipArchive();if($archive->open($directory.'/card.pkpass',\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new DomainException('Wallet-Ausgabe ist vorübergehend nicht verfügbar.',503);
   try{foreach($files as$name=>$bytes)if(!$archive->addFromString($name,$bytes))throw new DomainException('Wallet-Ausgabe ist vorübergehend nicht verfügbar.',503);}finally{$archive->close();}chmod($directory.'/card.pkpass',0600);$result=file_get_contents($directory.'/card.pkpass');if(!is_string($result)||strlen($result)>4194304)throw new DomainException('Wallet-Ausgabe ist vorübergehend nicht verfügbar.',503);return$result;
  }finally{foreach(['manifest.json','chain.pem','signature','card.pkpass']as$name)if(is_file($directory.'/'.$name))unlink($directory.'/'.$name);rmdir($directory);}
 }
}
