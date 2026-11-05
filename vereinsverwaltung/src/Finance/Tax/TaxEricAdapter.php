<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/WindowsEricProcess.php';

/** Private native runtime; authorization and duplicate suppression belong to TaxFilings. */
final class TaxEricAdapter
{
 public const DEVELOPER_URL='https://www.elster.de/elsterweb/infoseite/entwickler';
 public const INTERFACE_URL='https://www.elster.de/elsterweb/entwickler/infoseite/schnittstellenbeschreibungen';
 public const ERIC_URL='https://www.elster.de/elsterweb/entwickler/infoseite/eric';
 public const XML_INTERFACE_VERSION='ElsterBasis-XML 11';
 public const MANUFACTURER_ID_LENGTH=5;
 public static function runtimePath():string{return (string)(getenv('VEREIN_ERIC_RUNTIME')?:((getenv('VEREIN_PRIVATE_ROOT')?:'')!==''?rtrim((string)getenv('VEREIN_PRIVATE_ROOT'),'/').'/runtime-config/eric.json':''));}
 public static function configuration():array{return self::readConfiguration(true);}
 /** Local XML generation needs a private manufacturer/process configuration, not an SDK binary. */
 public static function generationConfiguration():array{return self::readConfiguration(false);}
 private static function readConfiguration(bool $native):array
 {
  $c=self::registrationConfiguration();
  if($native)foreach(['library','scratch','catalog'] as $key)if(!is_string($c[$key]??null)||!realpath($c[$key]))throw new DomainException('ELSTER-Laufzeit ist unvollständig.');
  if(!is_array($c['command']??null)||!array_is_list($c['command'])||!$c['command']||count($c['command'])>12)throw new DomainException('ELSTER-Prüfprogramm fehlt.');
  foreach($c['command'] as $arg)if(!is_string($arg)||str_contains($arg,chr(0)))throw new DomainException('ELSTER-Prüfprogramm ungültig.');
  if(!is_file($c['command'][0]))throw new DomainException('ELSTER-Prüfprogramm nicht verfügbar.');
  if(!$native&&!isset($c['profiles']))$c['profiles']=[];
  if(!is_array($c['profiles']??null)||count($c['profiles'])>300)throw new DomainException('ELSTER-Formularkatalog fehlt.');
  return $c;
 }
 private static function registrationConfiguration():array
 {
  $path=self::runtimePath();$real=$path!==''?realpath($path):false;
  if(!$real||!is_file($real)||filesize($real)>2000000)throw new DomainException('Die private ELSTER-Laufzeit ist nicht eingerichtet.');
  $web=!empty($_SERVER['DOCUMENT_ROOT'])?realpath($_SERVER['DOCUMENT_ROOT']):false;
  if($web&&str_starts_with(str_replace('\\','/',$real),rtrim(str_replace('\\','/',$web),'/').'/'))throw new DomainException('ELSTER-Konfiguration muss außerhalb des Webroots liegen.');
  if(PHP_OS_FAMILY!=='Windows'&&(fileperms($real)&0077)!==0)throw new DomainException('ELSTER-Konfiguration benötigt private Dateirechte.');
  try{$c=json_decode((string)file_get_contents($real),true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new DomainException('ELSTER-Konfiguration ist ungültig.');}
  if(!is_array($c)||($c['version']??null)!==1||!preg_match('/^[0-9]{5}$/D',(string)($c['manufacturer_id']??'')))throw new DomainException('Fünfstellige ELSTER-Hersteller-ID und gültige Laufzeitkonfiguration erforderlich.');
  if(getenv('VEREIN_MODE')==='production'&&isset($c['club_id'])&&$c['club_id']!==getenv('VEREIN_CLUB'))throw new DomainException('ELSTER-Laufzeit gehört zu einem anderen Verein.');
  $c['_path']=$real;return $c;
 }
 public static function status():array
 {
  $r=['available'=>false,'code'=>'official_sdk_missing','label'=>'Nicht eingerichtet','message'=>'ELSTER ist noch nicht eingerichtet. Arbeitsunterlagen bleiben verfügbar.',
   'environment'=>'test','runtime_configured'=>self::runtimePath()!==''&&is_file(self::runtimePath()),'manufacturer_id_configured'=>false,'manufacturer_id_valid'=>false,'manufacturer_id_length'=>0,
   'interface'=>['name'=>'ERiC (ELSTER Rich Client)','type'=>'native-c-library','xml'=>self::XML_INTERFACE_VERSION],
   'source'=>self::DEVELOPER_URL,'interface_source'=>self::INTERFACE_URL,'eric_source'=>self::ERIC_URL,
   'requirements'=>['Private amtliche ERiC-Laufzeit','Fünfstellige ELSTER-Hersteller-ID','Geprüfte Formularstände','Testübermittlung vor ausdrücklicher Produktivfreigabe'],
   'production_enabled'=>false,'profiles'=>[],'runtime_verified'=>false,'runtime_checked_at'=>null,'balance_generation_configured'=>false];
  try{self::registrationConfiguration();$r['manufacturer_id_configured']=true;$r['manufacturer_id_valid']=true;$r['manufacturer_id_length']=5;}catch(DomainException){}
  try{$generation=self::generationConfiguration();$r['balance_generation_configured']=($generation['balance_production_preparation']??false)===true||($generation['balance_test_enabled']??false)===true;}catch(DomainException){}
  try{$c=self::configuration();$r['manufacturer_id_configured']=true;$r['manufacturer_id_valid']=true;$r['manufacturer_id_length']=5;
   $r['code']='configured';$r['label']='Für Prüfungen eingerichtet';$r['message']='Erklärungen können vorbereitet und amtlich geprüft werden. Testabgabe und echte Abgabe sind getrennte Vorgänge.';
   $r['available']=true;$r['production_enabled']=($c['production_enabled']??false)===true;
   foreach($c['profiles'] as $key=>$p)$r['profiles'][$key]=array_intersect_key($p,array_flip(['family','label','year','pdf_supported','transport_enabled']))+['production_allowed'=>($p['family']??null)!=='balance'&&$r['production_enabled']&&($p['transport_enabled']??false)===true&&in_array($key,$c['production_versions']??[],true)];
  }catch(DomainException $e){$r['message']=$e->getMessage();}
  return $r;
 }
 /** Explicit local SDK check. Never accepts XML, certificates or a transport action. */
 public static function probe():array
 {
  $status=self::status();$status['runtime_checked_at']=gmdate('c');
  if(!$status['available'])return $status;
  $status['available']=false;$status['code']='runtime_failed';$status['label']='Laufzeit nicht bestätigt';
  $status['message']='Die ELSTER-Laufzeit konnte nicht bestätigt werden.';
  try{
   $config=self::configuration();$before=[];
   foreach(['_path','library','catalog'] as $key){$hash=hash_file('sha256',$config[$key]);if(!is_string($hash))throw new DomainException('Laufzeit nicht lesbar.');$before[$key]=$hash;}
   $result=self::invoke(['action'=>'status','environment'=>'test']);
   $xml=$result['version_xml']??null;
   if(($result['code']??null)!==0||($result['available']??null)!==true||($result['transport_attempted']??null)!==false||($result['sent']??null)!==false||!is_string($xml)||strlen($xml)>65536||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))return $status;
   $previous=libxml_use_internal_errors(true);
   try{$document=new \DOMDocument();$valid=$document->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
   if(!$valid)return $status;
   foreach($before as $key=>$hash){clearstatcache(true,$config[$key]);if(!hash_equals($hash,(string)hash_file('sha256',$config[$key])))return $status;}
   $status['available']=true;$status['runtime_verified']=true;$status['code']='runtime_verified';$status['label']='Laufzeit geprüft';
   $status['message']='ERiC wurde gestartet und hat seine Version bestätigt. Keine Übermittlung durchgeführt.';
   $status['runtime_sha256']=hash('sha256',implode('', $before));$status['version_sha256']=hash('sha256',$xml);
  }catch(\Throwable){/* Paths, native logs and private configuration never enter public diagnostics. */}
  return $status;
 }
 public static function invoke(#[\SensitiveParameter] array $request):array
 {
  $c=($request['action']??null)==='balance-generate'?self::generationConfiguration():self::configuration();$command=$c['command'];$command[]=dirname(__DIR__, 2).'/../tools/eric-bridge.py';$command[]=$c['_path'];
  $env=[];foreach(['PATH','SystemRoot','WINDIR','TEMP','TMP','HOME'] as $key){$v=getenv($key);if($v!==false)$env[$key]=$v;}
  foreach(($c['environment']??[]) as $key=>$v){if(!in_array($key,['PYTHONHOME','PYTHONPATH','LD_LIBRARY_PATH','OPENSSL_CONF','SSL_CERT_FILE'],true)||!is_string($v)||str_contains($v,chr(0)))throw new DomainException('ELSTER-Prozessumgebung ungültig.');$env[$key]=$v;}
  $env['PYTHONDONTWRITEBYTECODE']='1';$env['PYTHONUTF8']='1';
  // This server-side development gate cannot be enabled through form/request data.
  if(getenv('VEREIN_ERIC_DISABLE_TRANSPORT')==='1')$env['VEREIN_ERIC_DISABLE_TRANSPORT']='1';
  $encoded=json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  if(strlen($encoded)>8388608)throw new DomainException('ELSTER-Auftrag überschreitet das Größenlimit.');
  if(PHP_OS_FAMILY==='Windows'){
   $runner=$c['windows_process_command']??null;
   if(!is_array($runner)||!array_is_list($runner)||!$runner||count($runner)>12)throw new DomainException('Die Windows-Prüflaufzeit ist nicht eingerichtet.');
   foreach($runner as $arg)if(!is_string($arg)||str_contains($arg,chr(0)))throw new DomainException('Windows-Prüfprogramm ungültig.');
   [$out,$err,$exit]=WindowsEricProcess::run($runner,$command,$env,$encoded);
   if(function_exists('sodium_memzero'))sodium_memzero($encoded);$encoded='';
  }else{
  $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);
  if(!is_resource($proc))throw new DomainException('ELSTER-Prüfprogramm konnte nicht gestartet werden.');
  $out='';$err='';$offset=0;$deadline=microtime(true)+100;$exit=null;$inputOpen=true;
  foreach($pipes as $pipe)stream_set_blocking($pipe,false);
  try{do{
   if(microtime(true)>$deadline)throw new DomainException('ELSTER hat nicht rechtzeitig geantwortet. Eine angefangene Abgabe nicht erneut auslösen; Status prüfen.');
   if($inputOpen){$n=fwrite($pipes[0],substr($encoded,$offset,16384));if($n===false)throw new DomainException('ELSTER-Auftrag konnte nicht übergeben werden.');$offset+=$n;if($offset===strlen($encoded)){fclose($pipes[0]);$inputOpen=false;if(function_exists('sodium_memzero'))sodium_memzero($encoded);$encoded='';}}
   $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
   if(strlen($out)>16000000||strlen($err)>1000000)throw new DomainException('ELSTER-Antwort überschreitet das Größenlimit.');
   $state=proc_get_status($proc);if(!$state['running']){$exit=$state['exitcode'];$out.=stream_get_contents($pipes[1]);break;}
   usleep(10000);
  }while(true);}finally{
   if($inputOpen)fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);$state=proc_get_status($proc);if($state['running'])proc_terminate($proc);proc_close($proc);
   if($encoded!==''&&function_exists('sodium_memzero'))sodium_memzero($encoded);
  }
  }
  try{$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new DomainException('ELSTER hat keine gültige Antwort geliefert. Einen Abgabeauftrag nicht blind wiederholen.');}
  if(!is_array($r)||!isset($r['code'])||!is_int($r['code']))throw new DomainException('ELSTER-Antwort unvollständig.');
  $knownFailure=$exit===2&&in_array($r['code'],[-1,-2],true)&&is_bool($r['transport_attempted']??null)&&($r['xml_sha256']??null)===($request['xml_sha256']??'')&&($r['environment']??null)===($request['environment']??'');
  if(($exit!==0||$r['code']<0)&&!$knownFailure)throw new DomainException((string)($r['message']??'ELSTER-Verarbeitung nicht abgeschlossen.'));
  return $r;
 }
 public static function validate(array $r):array{return self::invoke(array_replace($r,['action'=>'validate']));}
 public static function preview(array $r):array{return self::invoke(array_replace($r,['action'=>'preview']));}
 public static function transmit(array $r):array{return self::invoke(array_replace($r,['action'=>'send']));}
 public static function balanceGenerate(array $values,array $mapping,array $profile,string $environment='test'):array
 {
  if(!in_array($environment,['test','production'],true))throw new DomainException('Abgabeumgebung ungültig.');
  return self::invoke(['action'=>'balance-generate','environment'=>$environment,'values'=>$values,'mapping'=>(object)$mapping,'profile'=>$profile]);
 }
 /** Password and certificate travel only over private memory IO. No transport. */
 public static function certificate(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $pin):array
 {
  if(strlen($bytes)<1||strlen($bytes)>1048576||strlen($pin)<1||strlen($pin)>256||str_contains($pin,chr(0)))throw new DomainException('Zertifikat und Zertifikats-Passwort prüfen.');
  $r=self::invoke(['action'=>'certificate','certificate_b64'=>base64_encode($bytes),'pin'=>$pin]);
  if($r['code']!==0||($r['transport_attempted']??true)!==false||($r['sent']??true)!==false||!is_array($r['certificate']??null))throw new DomainException('Zertifikat und Zertifikats-Passwort prüfen.');
  return $r['certificate'];
 }
}
