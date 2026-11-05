<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
/** Explicit local OCR, immutable original binding, and no financial mutation. */
final class InvoiceRecognition
{
 public const VERSION='5';
 public function __construct(private PDO $db,private string $actor){}
 private function inventoryContext(string $item):array
 {
  $inventory=new Inventory($this->db,$this->actor);if(!$inventory->canWrite()||!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanz- und Inventar-Bearbeitungsrechte erforderlich.');
  return $inventory->item($item);
 }
 public function runInventory(string $document,int $revision,string $item):array
 {
  $inventoryItem=$this->inventoryContext($item);[,,$doc]=$this->context($document,true);
  if(!array_filter($doc['links'],static fn($link)=>($link['source_type']==='item'&&$link['source_id']===$item)||($link['source_type']==='entry'&&!empty($inventoryItem['source_entry'])&&$link['source_id']===$inventoryItem['source_entry'])))throw new DomainException('Die Rechnung gehört nicht zu diesem Gegenstand.');
  return $this->run($document,$revision);
 }
 public function runStaged(string $token,string $action,string $field,string $key,string $item):array
 {
  require_once dirname(__DIR__, 2).'/Infrastructure/Media/LiveUploads.php';
  $guard=function()use($action,$field,$item){
   $this->inventoryContext($item);
   if(!(new Finance($this->db,$this->actor))->canWrite()||!(new Inventory($this->db,$this->actor))->canWrite()||!(new Documents($this->db,$this->actor))->canWrite())throw new DomainException('Finanz-, Inventar- und Dokument-Bearbeitungsrechte erforderlich.');
   if(!in_array($action,['document-upload','document-upload-many'],true)||!in_array($field,LiveUploads::FIELDS[$action]??[],true))throw new DomainException('Bitte einen Rechnungsupload auswählen.');
  };
  $guard();$original=LiveUploads::snapshot($this->actor,$token,$action,$field,$key);
  $result=self::extract($original['bytes'],$original['mime']);
  $guard();$current=LiveUploads::snapshot($this->actor,$token,$action,$field,$key);
  if(!hash_equals($original['sha256'],$current['sha256']))throw new DomainException('Rechnungsupload wurde inzwischen geändert.');
  return $result+['sha256'=>$original['sha256'],'staging'=>true];
 }
 private function context(string $id,bool $write=false):array
 {
  $a=Permissions::actor($this->db,$this->actor);$f=new Finance($this->db,$this->actor);if($write?!$f->canWrite():!$f->canRead())throw new DomainException('Finanzberechtigung für die Rechnungserkennung erforderlich.');
  $d=new Documents($this->db,$this->actor);$doc=$d->document($id);if(!in_array($doc['kind'],['receipt','invoice'],true)||!in_array('finance',$doc['scopes'],true))throw new DomainException('Bitte einen Finanzbeleg auswählen.');return [$a,$d,$doc];
 }
 public function result(string $id):?array
 {
  [$a,$d,$doc]=$this->context($id);$q=$this->db->prepare('SELECT * FROM invoice_recognition WHERE club_id=? AND document_id=?');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row||(int)$row['revision']!==(int)$doc['revision']||$row['parser_version']!==self::VERSION)return null;
  $file=$d->file($id,(int)$row['revision']);if(!hash_equals($row['sha256'],hash('sha256',$file['bytes'])))return null;
  $result=json_decode($row['result_json'],true,512,JSON_THROW_ON_ERROR);return array_replace($result,['state'=>$row['state']==='running'&&(int)$row['lease_until']<time()?'failed':$row['state'],'revision'=>(int)$row['revision'],'sha256'=>$row['sha256']]);
 }
 public function run(string $id,int $revision=0):array
 {
  [$a,$docs,$doc]=$this->context($id,true);$revision=$revision?:$doc['revision'];if($revision!==(int)$doc['revision'])throw new DomainException('Die Datei wurde inzwischen geändert. Bitte neu öffnen.');$file=$docs->file($id,$revision);if(!in_array($file['mime'],['application/pdf','application/xml','image/jpeg','image/png','image/heic','image/heif'],true))throw new DomainException('Rechnungsauslese unterstützt XML, PDF, JPG, PNG und HEIC.');if(strlen($file['bytes'])>10485760)throw new DomainException('Texterkennung: höchstens 10 MB.');
  $cached=$this->result($id);if(($cached['state']??'')==='ready')return $cached;$hash=hash('sha256',$file['bytes']);$token=bin2hex(random_bytes(16));
  $this->db->beginTransaction();try{[$lockedActor]=$this->context($id,true);$q=$this->db->prepare('SELECT lease_until FROM invoice_recognition WHERE club_id=? AND document_id=?');$q->execute([$a['club_id'],$id]);if((int)$q->fetchColumn()>time())throw new DomainException('Die Rechnungserkennung läuft bereits. Bitte kurz warten.');
   $q=$this->db->prepare("INSERT INTO invoice_recognition(club_id,document_id,revision,sha256,parser_version,state,result_json,lease_token,lease_until,updated_at,actor_id) VALUES(?,?,?,?,?,'running','{}',?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),sha256=VALUES(sha256),parser_version=VALUES(parser_version),state='running',result_json='{}',lease_token=VALUES(lease_token),lease_until=VALUES(lease_until),updated_at=VALUES(updated_at),actor_id=VALUES(actor_id)");$q->execute([$a['club_id'],$id,$revision,$hash,self::VERSION,$token,time()+120,gmdate('c'),$this->actor]);$this->db->commit();
  }catch(\Throwable $e){$this->db->rollBack();throw $e;}
  try{$result=self::extract($file['bytes'],$file['mime']);}catch(DomainException $e){$result=['state'=>'failed','error'=>$e->getMessage(),'fields'=>[],'items'=>[]];}
  $this->db->beginTransaction();try{[$actor,$currentDocs,$current]=$this->context($id,true);if((int)$current['revision']!==$revision||!hash_equals($hash,hash('sha256',$currentDocs->file($id,$revision)['bytes'])))throw new DomainException('Die Datei wurde während der Erkennung geändert. Bitte erneut starten.');$q=$this->db->prepare('UPDATE invoice_recognition SET state=?,result_json=?,lease_token=\'\',lease_until=0,updated_at=? WHERE club_id=? AND document_id=? AND lease_token=? AND revision=? AND sha256=?');$q->execute([$result['state'],json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),gmdate('c'),$a['club_id'],$id,$token,$revision,$hash]);if($q->rowCount()!==1)throw new DomainException('Die Erkennung wurde inzwischen ersetzt. Bitte neu öffnen.');$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}
  return $result+['revision'=>$revision,'sha256'=>$hash];
 }
 public static function extract(string $bytes,string $mime,string $mode='recognize'):array
 {
  if(!in_array($mode,['recognize','preview','embedded_xml'],true)||($mode==='embedded_xml'&&$mime!=='application/pdf')||($mode==='preview'&&!in_array($mime,['image/heic','image/heif'],true)))throw new DomainException('Ungültiger Bildvorgang.');
  if(strlen($bytes)>10485760||!in_array($mime,['application/pdf','application/xml','image/jpeg','image/png','image/heic','image/heif'],true))throw new DomainException('Dateigröße oder Dateityp für Texterkennung nicht zulässig.');
  if($mime==='application/xml'){if($mode!=='recognize')throw new DomainException('Ungültiger XML-Vorgang.');return self::structured($bytes,'xml');}
  $config=getenv('VEREIN_OCR_RUNTIME')?:dirname(dirname(__DIR__, 2),2).'/.local/vereinsverwaltung/ocr-runtime.json';$runtime=self::runtime($config);
  $lock=@fopen(dirname($config).'/invoice-recognition.lock','c');if($lock===false)throw new DomainException('Texterkennung kann derzeit nicht sicher gestartet werden.');@chmod(dirname($config).'/invoice-recognition.lock',0600);
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new DomainException('Es wird gerade eine Rechnung ausgelesen. Bitte kurz warten und erneut starten.');}
  $dir=sys_get_temp_dir().'/kulturhus-ocr-'.bin2hex(random_bytes(16));$process=null;$pipes=[];
  try{if(!mkdir($dir,0700))throw new DomainException('Arbeitsordner für Texterkennung fehlt.');file_put_contents($dir.'/input.bin',$bytes);chmod($dir.'/input.bin',0600);file_put_contents($dir.'/request.json',json_encode(['mime'=>$mime,'runtime'=>$runtime,'mode'=>$mode],JSON_THROW_ON_ERROR));chmod($dir.'/request.json',0600);
   $environment=['PATH'=>PHP_OS_FAMILY==='Windows'?'C:\\Windows\\System32':'/usr/bin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8','TMPDIR'=>$dir,'TMP'=>$dir,'TEMP'=>$dir,'OMP_THREAD_LIMIT'=>'1','OMP_NUM_THREADS'=>'1'];if(PHP_OS_FAMILY==='Windows')$environment['SystemRoot']='C:\\Windows';if(getenv('KULTURHUS_LOCAL_DEMO')==='1')$environment['KULTURHUS_LOCAL_DEMO']='1';
   $process=proc_open([$runtime['python'],'-X','utf8','-s',dirname(__DIR__, 2).'/../tools/recognize-invoice.py',$dir],[0=>['file',$dir.'/request.json','r'],1=>['file',$dir.'/result.json','w'],2=>['file',$dir.'/error.log','w']],$pipes,dirname(__DIR__, 2),$environment,['bypass_shell'=>true]);if(!is_resource($process))throw new DomainException('Texterkennung konnte nicht gestartet werden.');$deadline=microtime(true)+95;
   do{$status=proc_get_status($process);if(!$status['running'])break;if(microtime(true)>$deadline){proc_terminate($process);throw new DomainException('Texterkennung dauerte zu lange. Bitte weniger Seiten verwenden.');}usleep(50000);}while(true);proc_close($process);$process=null;
   if($status['exitcode']!==0||!is_file($dir.'/result.json')||filesize($dir.'/result.json')>(($mode==='embedded_xml'||$mime==='application/pdf')?2900000:($mode==='preview'?2100000:600000)))throw new DomainException('Datei konnte nicht sicher erkannt werden. Bitte Original manuell prüfen.');$result=json_decode((string)file_get_contents($dir.'/result.json'),true);if(!is_array($result)||!in_array($result['state']??'',['ready','failed'],true))throw new DomainException('Texterkennung lieferte kein gültiges Ergebnis.');
   if($mode==='recognize'&&!empty($result['embedded'])){$xml=base64_decode((string)($result['xml_base64']??''),true);if($xml===false||strlen($xml)>2097152)throw new DomainException('Eingebettete Rechnungsdaten nicht sicher auswertbar.');return self::structured($xml,'embedded_xml');}
   return $result;
  }finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(glob($dir.'/*')?:[] as $path)if(is_file($path))unlink($path);if(is_dir($dir))rmdir($dir);flock($lock,LOCK_UN);fclose($lock);}
 }
 private static function structured(string $xml,string $source):array
 {
  require_once __DIR__.'/EInvoiceInspection.php';
  if(strlen($xml)>10485760||EInvoiceInspection::inspect($xml)['state']!=='recognized')throw new DomainException('Keine sicher erkannte strukturierte XML-Rechnung.');
  $details=EInvoiceFields::details($xml);$warnings=is_array($details['warnings']??null)?$details['warnings']:[];
  if(array_filter($details['items']??[],static fn($item)=>!empty($item['ambiguous'])))$warnings[]='Mindestens eine Position enthält mehrdeutige Angaben. Bitte am Original prüfen.';
  if(!empty($details['items_truncated']))$warnings[]='Nicht alle Positionen werden angezeigt. Bitte vollständiges Original prüfen.';
  $warnings[]='Strukturierte Rechnungsdaten: Angaben am Original prüfen; noch keine formale Regelprüfung.';
  return array_replace($details,['state'=>'ready','method'=>'xml','source'=>$source,'warnings'=>array_values(array_unique($warnings))]);
 }
 /** Resolve only trusted configuration paths, relative to its private file, in CLI and PHP-FPM. */
 public static function runtime(string $path):array
 {
  $file=realpath($path);if($file===false||!is_file($file))throw new DomainException('Lokale Texterkennung ist noch nicht eingerichtet.');
  $runtime=json_decode((string)file_get_contents($file),true);if(!is_array($runtime)||empty($runtime['python']))throw new DomainException('Lokale Texterkennung ist nicht betriebsbereit.');
  $resolve=static function($value,bool $directory=false)use($file):string{
   if(!is_string($value)||$value===''||str_contains($value,"\0"))throw new DomainException('Lokale Texterkennung ist nicht betriebsbereit.');
   $absolute=$value[0]==='/'||$value[0]==='\\'||preg_match('~^[A-Za-z]:[/\\\\]~D',$value);
   $resolved=realpath($absolute?$value:dirname($file).DIRECTORY_SEPARATOR.$value);
   if($resolved===false||($directory?!is_dir($resolved):!is_file($resolved)))throw new DomainException('Lokale Texterkennung ist nicht betriebsbereit.');return $resolved;
  };
  foreach(['python','tesseract','tesseract_loader'] as $key)if(isset($runtime[$key]))$runtime[$key]=$resolve($runtime[$key]);
  foreach(['tessdata','tesseract_library_path'] as $key)if(isset($runtime[$key]))$runtime[$key]=$resolve($runtime[$key],true);
  if(isset($runtime['sandbox_read_roots'])){if(!is_array($runtime['sandbox_read_roots']))throw new DomainException('Lokale Texterkennung ist nicht betriebsbereit.');$runtime['sandbox_read_roots']=array_map(static fn($value)=>$resolve($value,true),$runtime['sandbox_read_roots']);}
  if(isset($runtime['tesseract_loader'])!==isset($runtime['tesseract_library_path']))throw new DomainException('Lokale Texterkennung ist nicht vollständig eingerichtet.');
  return $runtime;
 }
}
