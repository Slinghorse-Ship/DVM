<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use PDO;
require_once __DIR__.'/CameraDocumentUploads.php';
/** Private, session-bound transport staging. Final domain actions retain their checks. */
final class LiveUploads
{
 private static array $accepted=[];
 private static array $consumed=[];
 private static array $preparedSources=[];
 private static array $owned=[];
 private static bool $cleanupRegistered=false;
 public const FIELDS=['create-entry'=>['receipt_file'],'document-upload-many'=>['document_files'],'document-upload'=>['document_file'],'document-version'=>['document_file'],'gema-upload-invoice'=>['gema_file'],'inventory-file'=>['inventory_file'],'member-import-upload'=>['member_file'],'free-import-preview'=>['csv_file'],'document-check-save'=>['check_file'],'minutes-save'=>['minute_pdf'],'paper-settings-native'=>['logo'],'settings-save'=>['logo','signature'],'settings-signature'=>['signature'],'chat-send'=>['chat_file']];
 private static function root():string{$root=session_save_path().'/live-uploads';if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new DomainException('Uploadspeicher nicht verfügbar.');return $root;}
 public static function file(string $path):bool{return is_uploaded_file($path)||isset(self::$accepted[$path]);}
 /** Bound allocation before reading a trusted multipart/staging path. Callers still authorize its upload. */
 public static function bytes(string $path,int $maximum):string
 {
  if($maximum<1||$maximum>10485760||str_contains($path,"\0")||preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||is_link($path)||!is_file($path))throw new DomainException('Lokale Uploaddatei und erlaubte Größe prüfen.');
  $input=@fopen($path,'rb');if(!$input)throw new DomainException('Datei nicht lesbar.');
  try{
   $before=fstat($input);if(!$before||($before['mode']&0170000)!==0100000||$before['size']<1||$before['size']>$maximum)throw new DomainException('Datei überschreitet die erlaubte Größe oder ist leer.');
   $bytes=stream_get_contents($input,$before['size']+1);$after=fstat($input);
   if(!is_string($bytes)||strlen($bytes)>$maximum)throw new DomainException('Datei überschreitet die erlaubte Größe.');
   if(!$after||strlen($bytes)!==$before['size'])throw new DomainException('Datei wurde während des Lesens verändert.');
   foreach(['dev','ino','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])throw new DomainException('Datei wurde während des Lesens verändert.');
   return $bytes;
  }finally{fclose($input);}
 }
 public static function outputLimit(string $field):int
 {return match($field){'chat_file','inventory_file','logo','signature'=>2097152,'member_file'=>5242880,'csv_file'=>1000000,default=>10485760};}
 private static function cameraField(string $field):bool
 {return in_array($field,['receipt_file','document_files','document_file','gema_file','inventory_file','chat_file','logo','signature'],true);}
 public static function prepared(string $path,string $name,int $maximum=10485760):CameraDocumentUploads
 {
  if(!self::file($path))throw new DomainException('Eigene vollständig übertragene Datei auswählen.');
  return CameraDocumentUploads::staged($path,$name,self::$preparedSources[$path]??null,$maximum);
 }
 /** Central POST boundary, after CSRF and optional staging attachment; ordinary inputs stay exact. */
 public static function prepareRequest(PDO $db,string $actor,string $action,array $data):void
 {
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new DomainException('Upload benötigt ein bestätigtes Formular.');
  $changes=[];$sourceTotal=0;$authorized=false;$new=[];
  try{foreach(self::FIELDS[$action]??[]as$field){
   if(!self::cameraField($field)||!isset($_FILES[$field])||!is_array($_FILES[$field]))continue;
   $input=$_FILES[$field];$multiple=is_array($input['error']??null);$errors=$multiple?$input['error']:[$input['error']??UPLOAD_ERR_NO_FILE];
   if(!array_is_list($errors)||count($errors)>($field==='document_files'?10:1))throw new DomainException('Eine bis zehn Dateien auswählen.');
   foreach($errors as$index=>$error){
    if($error===UPLOAD_ERR_NO_FILE)continue;$path=$multiple?($input['tmp_name'][$index]??null):($input['tmp_name']??null);$name=$multiple?($input['name'][$index]??null):($input['name']??null);
    if($error!==UPLOAD_ERR_OK||!is_string($path)||!is_string($name)||!self::file($path)||is_link($path)||!is_file($path))throw new DomainException('Datei nicht vollständig übertragen.');
    $size=filesize($path);if($size===false||$size<1||$size>CameraDocumentUploads::INPUT_BYTES||($sourceTotal+=$size)>CameraDocumentUploads::BATCH_INPUT_BYTES)throw new DomainException('Kameraoriginale bis 256 MiB je Datei und zusammen bis 512 MiB auswählen.');
    if(isset(self::$preparedSources[$path])){if(!$authorized){self::permit($db,$actor,$action,$data);$authorized=true;}continue;}
    if(!CameraDocumentUploads::camera($path,$name))continue;
    if(!$authorized){self::permit($db,$actor,$action,$data);$authorized=true;}
    $prepared=CameraDocumentUploads::read($path,$name,self::outputLimit($field));
    $target=tempnam(self::root(),'prepared-');if($target===false)throw new DomainException('Vorbereitete Datei konnte nicht gesichert werden.');$new[]=$target;@chmod($target,0600);
    if(file_put_contents($target,$prepared->bytes)!==strlen($prepared->bytes))throw new DomainException('Vorbereitete Datei konnte nicht gesichert werden.');
    self::$owned[$target]=hash('sha256',$prepared->bytes);self::$accepted[$target]=true;self::$preparedSources[$target]=$prepared->source;
    $values=['tmp_name'=>$target,'name'=>$prepared->name,'size'=>strlen($prepared->bytes),'type'=>'image/jpeg','error'=>UPLOAD_ERR_OK];foreach($values as$key=>$value){if($multiple)$input[$key][$index]=$value;else$input[$key]=$value;}
   }$changes[$field]=$input;
  }foreach($changes as$field=>$input)$_FILES[$field]=$input;
  if(!self::$cleanupRegistered){self::$cleanupRegistered=true;register_shutdown_function(self::cleanupPrepared(...));}
  }catch(\Throwable$error){foreach($new as$path){if(!is_link($path)&&is_file($path))@unlink($path);unset(self::$accepted[$path],self::$preparedSources[$path],self::$owned[$path]);}throw$error;}
 }
 public static function cleanupPrepared():void
 {foreach(self::$owned as$path=>$hash)if(!is_link($path)&&is_file($path)&&hash_equals($hash,(string)hash_file('sha256',$path)))@unlink($path);self::$owned=[];}
 public static function permit(PDO $db,string $actor,string $action,array $data):void
 {
  $a=Permissions::actor($db,$actor);$allowed=match($action){
   'create-entry'=>(new Finance($db,$actor))->canWrite()&&(new Documents($db,$actor))->canWrite(),
   'document-upload-many','document-upload','document-version'=>(new Documents($db,$actor))->canWrite(),
   'gema-upload-invoice'=>BoardAccess::allowed($db,$actor)&&(new Finance($db,$actor))->canWrite(),
   'inventory-file'=>(new Inventory($db,$actor))->canWrite(),
   'member-import-upload'=>(new Registry($db,$actor))->canWrite(),
   'document-check-save'=>BoardAccess::allowed($db,$actor),
   'minutes-save'=>(new Minutes($db,$actor))->canWrite(),
   'paper-settings-native','settings-save'=>(new Settings($db,$actor))->canManage(),
   'settings-signature'=>(new BoardSignature($db,$actor))->canUse(),
   'chat-send'=>$a['role']!=='editor',
   'free-import-preview'=>true,default=>false};
  if(!$allowed)throw new DomainException('Keine Berechtigung für diesen Upload.');
  if($action==='chat-send')(new ClubChat($db,$actor))->thread((string)($data['chat']??''));
  if($action==='free-import-preview')(new FreeTables($db,$actor))->table((string)($data['sheet']??''),true);
 }
 public static function stage(string $actor,string $action,string $field,array $file,string $key):array
 {
  if(!in_array($field,self::FIELDS[$action]??[],true)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new DomainException('Uploadziel ungültig.');
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null)||is_link($file['tmp_name']))throw new DomainException('Datei nicht vollständig übertragen.');
  $inputSize=filesize($file['tmp_name']);$camera=self::cameraField($field)&&CameraDocumentUploads::camera($file['tmp_name'],$file['name']);$max=self::outputLimit($field);
  if($inputSize===false||$inputSize<1||$inputSize>($camera?CameraDocumentUploads::INPUT_BYTES:$max))throw new DomainException('Datei überschreitet die erlaubte Größe oder ist leer.');
  $inputHash=hash_file('sha256',$file['tmp_name']);if(!is_string($inputHash))throw new DomainException('Datei nicht lesbar.');
  $root=self::root();foreach(glob($root.'/*.bin')?:[]as$path)if(!is_link($path)&&filemtime($path)<time()-3600){@unlink($path);$source=substr($path,0,-4).'.source';if(!is_link($source)&&is_file($source))@unlink($source);}
  foreach(glob($root.'/*.source')?:[]as$path)if(!is_link($path)&&filemtime($path)<time()-3600)@unlink($path);
  $_SESSION['live_uploads']??=[];$total=0;$sourceTotal=0;
  foreach($_SESSION['live_uploads']as$token=>$r){
   if($r['created']<time()-3600||!is_file($root.'/'.$token.'.bin')){unset($_SESSION['live_uploads'][$token]);continue;}
   $total+=$r['size'];$sourceTotal+=$r['input_size']??$r['size'];
   if($r['actor']===$actor&&$r['key']===$key){if($r['action']!==$action||$r['field']!==$field||($r['input_hash']??$r['hash'])!==$inputHash||($r['input_name']??$r['name'])!==$file['name'])throw new DomainException('Uploadkennung bereits anders verwendet.');self::stagingFile($token,$r);return ['token'=>$token,'name'=>$r['name'],'size'=>$r['size']];}
  }
  if(count($_SESSION['live_uploads'])>=20||$sourceTotal+$inputSize>CameraDocumentUploads::BATCH_INPUT_BYTES)throw new DomainException('Zu viele offene Uploads. Vorhandene Dateien zuerst speichern oder entfernen.');
  $prepared=$camera?CameraDocumentUploads::read($file['tmp_name'],$file['name'],$max):CameraDocumentUploads::fromBytes($file['name'],self::bytes($file['tmp_name'],$max),$max);
  [$name,$mime,$hash]=Documents::inspectFile($prepared->name,$prepared->bytes);
  $extensions=match($field){'receipt_file'=>['pdf','xml','png','jpg','jpeg','heic','heif'],'logo','signature'=>['png','jpg','jpeg'],'minute_pdf'=>['pdf'],'member_file'=>['csv','xlsx'],'csv_file'=>['csv'],'chat_file','inventory_file','gema_file'=>['pdf','png','jpg','jpeg'],default=>null};
  if($extensions&&!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),$extensions,true))throw new DomainException('Dateityp für diesen Bereich nicht erlaubt.');
  if($total+strlen($prepared->bytes)>31457280)throw new DomainException('Zu viele offene Uploads. Vorhandene Dateien zuerst speichern oder entfernen.');
  $token=bin2hex(random_bytes(32));$path=$root.'/'.$token.'.bin';$original=$root.'/'.$token.'.source';
  try{
   if($prepared->source!==null){if(!move_uploaded_file($file['tmp_name'],$original))throw new DomainException('Kameraoriginal konnte nicht gesichert werden.');@chmod($original,0600);if(!hash_equals($inputHash,(string)hash_file('sha256',$original)))throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');$handle=@fopen($path,'xb');if(!$handle)throw new DomainException('Upload konnte nicht gesichert werden.');try{if(fwrite($handle,$prepared->bytes)!==strlen($prepared->bytes))throw new DomainException('Upload konnte nicht gesichert werden.');}finally{fclose($handle);}}
   elseif(!move_uploaded_file($file['tmp_name'],$path))throw new DomainException('Upload konnte nicht gesichert werden.');
   @chmod($path,0600);
   $_SESSION['live_uploads'][$token]=['actor'=>$actor,'action'=>$action,'field'=>$field,'name'=>$name,'size'=>strlen($prepared->bytes),'mime'=>$mime,'hash'=>$hash,'key'=>$key,'created'=>time(),'input_name'=>$file['name'],'input_size'=>$inputSize,'input_hash'=>$inputHash,'camera_source'=>$prepared->source];
   return ['token'=>$token,'name'=>$name,'size'=>strlen($prepared->bytes)];
  }catch(\Throwable$error){foreach([$path,$original]as$owned)if(!is_link($owned)&&is_file($owned))@unlink($owned);throw$error;}
 }
 private static function stagingFile(string $token,array $record):string
 {
  $path=self::root().'/'.$token.'.bin';if(is_link($path)||!is_file($path)||!hash_equals($record['hash'],(string)hash_file('sha256',$path)))throw new DomainException('Upload nicht mehr verfügbar.');
  if(isset($record['camera_source'])){$source=self::root().'/'.$token.'.source';if(is_link($source)||!is_file($source)||filesize($source)!==$record['input_size']||!hash_equals($record['input_hash'],(string)hash_file('sha256',$source)))throw new DomainException('Kameraoriginal im Upload nicht mehr verfügbar.');}
  return$path;
 }
 public static function attach(string $actor,string $action,array $tokens):void
 {
  if(count($tokens)>2)throw new DomainException('Uploadfelder prüfen.');
  foreach($tokens as $field=>$list){if(!in_array($field,self::FIELDS[$action]??[],true)||!is_array($list)||!array_is_list($list)||count($list)>($field==='document_files'?10:1))throw new DomainException('Uploadzuordnung ungültig.');$files=[];$seen=[];
   foreach($list as $token){if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new DomainException('Uploadkennung ungültig.');if(isset($seen[$token]))throw new DomainException('Datei mehrfach im Uploadformular.');$seen[$token]=true;$r=$_SESSION['live_uploads'][$token]??null;if(!$r||$r['actor']!==$actor||$r['action']!==$action||$r['field']!==$field||$r['created']<time()-3600)throw new DomainException('Upload abgelaufen oder nicht für dieses Formular. Datei erneut auswählen.');$path=self::stagingFile($token,$r);self::$preparedSources[$path]=$r['camera_source']??null;$files[]=['name'=>$r['name'],'type'=>$r['mime'],'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>$r['size']];self::$accepted[$path]=true;self::$consumed[]=$token;
   }
   if(!$files)continue;if($field==='document_files'){$out=[];foreach(array_keys($files[0]) as $key)$out[$key]=array_column($files,$key);$_FILES[$field]=$out;}else $_FILES[$field]=$files[0];
  }
 }
 /** Verified draft metadata without allocating the private original file bytes. */
 public static function description(string $actor,string $token,string $action,string $field):array
 {
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$token)||!in_array($field,self::FIELDS[$action]??[],true))throw new DomainException('Uploadzuordnung ungültig.');
  $record=$_SESSION['live_uploads'][$token]??null;
  if(!is_array($record)||($record['actor']??null)!==$actor||($record['action']??null)!==$action||($record['field']??null)!==$field||($record['created']??0)<time()-3600)throw new DomainException('Upload abgelaufen oder nicht für dieses Formular. Datei erneut auswählen.');
  self::stagingFile($token,$record);return ['name'=>$record['name'],'size'=>$record['size'],'sha256'=>$record['hash']];
 }
 /** Read one exact draft upload without accepting, consuming or changing it. */
 public static function snapshot(string $actor,string $token,string $action,string $field,string $key):array
 {
  if(!preg_match('/^[a-f0-9]{64}$/D',$token)||!preg_match('/^[a-f0-9]{32}$/D',$key)||!in_array($field,self::FIELDS[$action]??[],true))throw new DomainException('Uploadzuordnung ungültig.');
  $r=$_SESSION['live_uploads'][$token]??null;
  if(!$r||$r['actor']!==$actor||$r['action']!==$action||$r['field']!==$field||($r['key']??'')!==$key||$r['created']<time()-3600)throw new DomainException('Upload abgelaufen oder nicht für diesen Entwurf. Bitte Datei erneut auswählen.');
  $path=self::stagingFile($token,$r);
  if(filesize($path)>10485760)throw new DomainException('Upload nicht mehr verfügbar oder zu groß.');
  $bytes=file_get_contents($path);if($bytes===false||strlen($bytes)!==(int)$r['size']||!hash_equals($r['hash'],hash('sha256',$bytes)))throw new DomainException('Upload wurde inzwischen geändert.');
  return ['bytes'=>$bytes,'mime'=>$r['mime'],'name'=>$r['name'],'sha256'=>$r['hash']];
 }
 private static function discard(string $token):void
 {foreach(['.bin','.source']as$suffix){$path=self::root().'/'.$token.$suffix;if(!is_link($path)&&is_file($path))@unlink($path);}}
 public static function remove(string $actor,string $token):void{if(!preg_match('/^[a-f0-9]{64}$/D',$token)||($_SESSION['live_uploads'][$token]['actor']??'')!==$actor)throw new DomainException('Upload nicht verfügbar.');self::discard($token);unset($_SESSION['live_uploads'][$token]);}
 public static function finish():void{foreach(array_unique(self::$consumed)as$token){self::discard($token);unset($_SESSION['live_uploads'][$token]);}self::$consumed=[];self::$accepted=[];self::$preparedSources=[];self::cleanupPrepared();}
}
