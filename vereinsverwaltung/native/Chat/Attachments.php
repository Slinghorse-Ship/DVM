<?php declare(strict_types=1);
namespace Kulturhus\Native\Chat;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Ordered private attachments share one original message and retry identity. */
final class Attachments
{
    private string $root;
    public function __construct(private PDO $db,private Access $access,string $root)
    {
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$root)||is_link($root))throw new DomainException('Privaten lokalen Anhangspeicher wählen.');
        if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root))throw new DomainException('Anhangspeicher nicht verfügbar.');
        $this->root=(string)realpath($root);
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??''] as $webroot){
            if($webroot!==''&&($resolved=realpath($webroot))&&$this->inside($this->root,$resolved))throw new DomainException('Anhänge müssen außerhalb des Webroots liegen.');
        }
    }
    public const MAX_FILES=20;
    public function send(string $id,string $body,string $key,string $source,string $name,?int $replyTo=null):array
    {
        return $this->sendMany($id,$body,$key,[['source'=>$source,'name'=>$name]],$replyTo);
    }
    public function sendMany(string $id,string $body,string $key,array $sources,?int $replyTo=null):array
    {
        if(!array_is_list($sources)||count($sources)<1||count($sources)>self::MAX_FILES)throw new DomainException('Ein bis zwanzig Anhänge auswählen.');
        if(count($sources)>1&&!$this->extrasInstalled())throw new DomainException('Mehrfachanhänge zuerst in der Einrichtung aktualisieren.');
        $chat=new Workspace($this->db,$this->access);$thread=$chat->get($id);$this->access->require('chat','edit');Workspace::requestKey($key);
        $sourceBytes=0;
        foreach($sources as$source){
            if(!is_array($source)||!is_string($source['source']??null)||!is_string($source['name']??null)||preg_match('~^[a-z][a-z0-9+.-]*://~i',$source['source'])||is_link($source['source'])||!is_file($source['source']))throw new DomainException('Eigene Anhangdateien auswählen.');
            $size=filesize($source['source']);if($size===false||$size<1||$size>\Kulturhus\Native\Cms\CameraPhotos::INPUT_BYTES||($sourceBytes+=$size)>\Kulturhus\Native\Cms\CameraPhotos::BATCH_INPUT_BYTES)throw new DomainException('Kameraoriginale bis 256 MiB je Datei und zusammen bis 512 MiB auswählen.');
        }
        $directory=$this->root.'/'.hash('sha256',$thread['club_id']);
        if(is_link($directory)||(!is_dir($directory)&&!@mkdir($directory,0700)&&!is_dir($directory)))throw new DomainException('Privater Dateibereich nicht verfügbar.');
        $staged=[];$keep=false;
        try{
            foreach($sources as$source){
                if(!is_array($source)||!is_string($source['source']??null)||!is_string($source['name']??null))throw new DomainException('Eigene Anhangdateien auswählen.');
                $inspected=$this->inspect($source['source'],$source['name']);[$bytes,$mime,$name]=$inspected;
                $fileId=bin2hex(random_bytes(16));$path=$directory.'/'.$fileId.'.bin';$handle=@fopen($path,'xb');
                if(!$handle)throw new DomainException('Anhang konnte nicht gespeichert werden.');
                $staged[]=['file_id'=>$fileId,'path'=>$path,'name'=>$name,'mime'=>$mime,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
                $written=fwrite($handle,$bytes);fclose($handle);@chmod($path,0600);unset($bytes);
                if($written!==$staged[array_key_last($staged)]['bytes'])throw new DomainException('Anhang unvollständig.');
                if(isset($inspected[3])){
                    $origin=$directory.'/'.$fileId.'.origin.json';$staged[array_key_last($staged)]['origin']=$inspected[3];$staged[array_key_last($staged)]['origin_path']=$origin;
                    $originBytes=json_encode($inspected[3],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$originHandle=@fopen($origin,'xb');if(!$originHandle)throw new DomainException('Kameraherkunft konnte nicht gesichert werden.');try{if(fwrite($originHandle,$originBytes)!==strlen($originBytes))throw new DomainException('Kameraherkunft konnte nicht gesichert werden.');}finally{fclose($originHandle);}@chmod($origin,0600);
                }
            }
            $body=trim($body)===''?(count($staged)===1?'📎 '.$staged[0]['name']:'📎 '.count($staged).' Dateien'):Workspace::text($body,5000);
            $first=$staged[0];
            // Retain the existing single-file replay contract, including old accepted requests.
            $hash=count($staged)===1?Workspace::fingerprint(['file',$body,$replyTo,$first['name'],$first['mime'],$first['sha256']]):Workspace::fingerprint(['files',$body,$replyTo,array_map(static fn($file)=>[$file['name'],$file['mime'],$file['bytes'],$file['sha256']],$staged)]);
            $origins=array_column($staged,'origin');if($origins!==[])$hash=Workspace::fingerprint(['camera-files-v1',$hash,array_map(static fn($file)=>$file['origin']??null,$staged)]);
            return Db::atomic($this->db,function()use($chat,$id,$body,$key,$thread,$hash,$staged,$replyTo,&$keep):array{
                $locked=$chat->locked($id,'edit');if($locked['club_id']!==$thread['club_id'])throw new Conflict('Vereinszugehörigkeit geändert.');
                $existing=$chat->request($locked,$key);
                if($existing){if(!hash_equals($existing['request_hash'],$hash))throw new Conflict('Anfragekennung bereits anders verwendet.');return ['seq'=>(int)$existing['seq'],'thread_id'=>$id,'duplicate'=>true];}
                $message=$chat->append($locked,$this->access->user()['id'],$body,$key,$hash,$replyTo);
                foreach($staged as$index=>$file){
                    $args=[$thread['club_id'],$id,$message['seq']];
                    if($index===0)$sql='INSERT INTO native_chat_files(club_id,thread_id,message_seq,file_id,name,mime,bytes,sha256) VALUES(?,?,?,?,?,?,?,?)';
                    else{$sql='INSERT INTO native_chat_file_extras(club_id,thread_id,message_seq,ordinal,file_id,name,mime,bytes,sha256) VALUES(?,?,?,?,?,?,?,?,?)';$args[]=$index+1;}
                    array_push($args,$file['file_id'],$file['name'],$file['mime'],$file['bytes'],$file['sha256']);Db::run($this->db,$sql,$args);
                }
                $keep=true;return$message;
            });
        }catch(\Throwable $error){
            if($keep){
                // A COMMIT can be accepted before its acknowledgement is lost. Never delete
                // its original bytes. If the connection cannot confirm, retain private files.
                try{$keep=(bool)Db::run($this->db,'SELECT 1 FROM native_chat_files WHERE club_id=? AND thread_id=? AND file_id=?',[$thread['club_id'],$id,$staged[0]['file_id']])->fetchColumn();}catch(\Throwable){$keep=true;}
            }
            throw$error;
        }finally{if(!$keep)foreach($staged as$file)foreach([$file['path'],$file['origin_path']??null]as$owned)if(is_string($owned)&&!is_link($owned)&&is_file($owned))@unlink($owned);}
    }
    /** Validates the real PHP single/multiple upload structure before accepting any file. */
    public static function uploads(?array $input):array
    {
        if($input===null)return[];
        if(!is_array($input['error']??null))$input=array_map(static fn($value)=>[$value],$input);
        foreach(['error','tmp_name','name']as$key)if(!is_array($input[$key]??null)||!array_is_list($input[$key])||count($input[$key])!==count($input['error']))throw new DomainException('Anhangauswahl prüfen.');
        if(count($input['error'])>self::MAX_FILES)throw new DomainException('Höchstens zwanzig Anhänge auswählen.');
        $files=[];foreach($input['error']as$i=>$error){
            if($error===UPLOAD_ERR_NO_FILE)continue;
            if($error!==UPLOAD_ERR_OK||!is_string($input['tmp_name'][$i])||!is_uploaded_file($input['tmp_name'][$i])||!is_string($input['name'][$i]))throw new DomainException('Anhang konnte nicht hochgeladen werden. Bitte Dateien erneut auswählen.');
            $files[]=['source'=>$input['tmp_name'][$i],'name'=>$input['name'][$i]];
        }
        return$files;
    }
    public function file(string $id,int $seq,int $ordinal=1):array
    {
        if($ordinal<1||$ordinal>self::MAX_FILES)throw new DomainException('Eigenen Anhang auswählen.');
        if($ordinal>1&&!$this->extrasInstalled())throw new DomainException('Anhang nicht verfügbar.');
        $thread=(new Workspace($this->db,$this->access))->get($id);
        $sql='SELECT file_id,name,mime,bytes,sha256 FROM '.($ordinal===1?'native_chat_files':'native_chat_file_extras').' WHERE club_id=? AND thread_id=? AND message_seq=?';$args=[$thread['club_id'],$id,$seq];
        if($ordinal!==1){$sql.=' AND ordinal=?';$args[]=$ordinal;}
        $file=Db::run($this->db,$sql,$args)->fetch();
        if(!$file||!preg_match('/^[a-f0-9]{32}$/D',$file['file_id']))throw new DomainException('Anhang nicht verfügbar.');
        $directory=$this->root.'/'.hash('sha256',$thread['club_id']);$candidate=$directory.'/'.$file['file_id'].'.bin';$path=realpath($candidate);
        if(!$path||is_link($directory)||is_link($candidate)||!$this->inside($path,$this->root)||!is_file($path)||!hash_equals($file['sha256'],hash_file('sha256',$path)))throw new DomainException('Anhang nicht verfügbar.');
        unset($file['file_id']);return ['path'=>$path]+$file;
    }
    private function inspect(string $source,string $original,?array $origin=null):array
    {
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$source)||is_link($source)||!is_file($source))throw new DomainException('Anhänge: lokale Datei bis 8 MB.');
        try{$camera=\Kulturhus\Native\Cms\CameraPhotos::sourceKind($source,$original);}catch(\InvalidArgumentException$error){throw new DomainException($error->getMessage(),0,$error);}
        if($camera!==null){
            $sourceName=basename(str_replace('\\','/',$original));if($sourceName===''||strlen($sourceName)>240||!mb_check_encoding($sourceName,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$sourceName))throw new DomainException('Ungültiger Dateiname.');
            $size=filesize($source);if($size===false||$size<8||$size>\Kulturhus\Native\Cms\CameraPhotos::INPUT_BYTES)throw new DomainException('Kamerafoto bis 256 MiB auswählen.');$hash=hash_file('sha256',$source);$before=stat($source);if(!$before||!is_string($hash))throw new DomainException('Kameraoriginal konnte nicht geprüft werden.');
            try{return \Kulturhus\Native\Cms\CameraPhotos::withFile($source,$original,function(string$prepared)use($source,$original,$camera,$size,$hash,$before):array{
                $result=$this->inspect($prepared,$original,['version'=>1,'kind'=>$camera,'name'=>basename(str_replace('\\','/',$original)),'bytes'=>$size,'sha256'=>$hash]);clearstatcache(true,$source);$after=@stat($source);
                if(!$after||is_link($source)||!is_file($source))throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');foreach(['dev','ino','size','mtime','ctime']as$key)if($before[$key]!==$after[$key])throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');if(!hash_equals($hash,(string)hash_file('sha256',$source)))throw new DomainException('Kameraoriginal wurde während der Vorbereitung geändert.');return$result;
            });}catch(\InvalidArgumentException|\RuntimeException$error){throw new DomainException($error->getMessage(),0,$error);}
        }
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i',$source)||is_link($source)||!is_file($source)||filesize($source)<1||filesize($source)>8*1024*1024)throw new DomainException('Anhänge: lokale Datei bis 8 MB.');
        $bytes=file_get_contents($source);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$ext='';
        if(in_array($mime,['image/png','image/jpeg','image/webp'],true)){
            $info=@getimagesizefromstring($bytes);
            if(!$info||($info['mime']??'')!==$mime||$info[0]*$info[1]>4000000||max($info[0],$info[1])>6000)throw new DomainException('Chatbilder dürfen höchstens 4 Megapixel groß sein.');
            $image=@imagecreatefromstring($bytes);if(!$image)throw new DomainException('Bild nicht decodierbar.');
            // A fresh pixel canvas excludes original metadata and trailing/polyglot bytes.
            $image=$mime==='image/jpeg'?\Kulturhus\Native\Cms\ImageOrientation::apply($image,$source):$image;
            $width=imagesx($image);$height=imagesy($image);$target=imagecreatetruecolor($width,$height);imagealphablending($target,false);imagesavealpha($target,true);imagecopy($target,$image,0,0,0,0,$width,$height);
            ob_start();$ok=match($mime){'image/jpeg'=>imagejpeg($target,null,86),'image/webp'=>imagewebp($target,null,85),default=>imagepng($target,null,6)};$bytes=(string)ob_get_clean();unset($target,$image);
            if(!$ok||$bytes===''||strlen($bytes)>8*1024*1024)throw new DomainException('Aufbereitetes Bild ist zu groß.');
            $ext=match($mime){'image/jpeg'=>'jpg','image/webp'=>'webp',default=>'png'};
        }elseif($mime==='application/pdf'){
            if(!preg_match('/\A%PDF-1\.[0-7]/',$bytes)||!str_contains(substr($bytes,-2048),'%%EOF')||preg_match('~/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia|XFA|Encrypt)\b~i',$bytes))throw new DomainException('Dieses PDF enthält nicht unterstützte oder aktive Inhalte.');
            $ext='pdf';
        }elseif(in_array($mime,['text/plain','application/octet-stream'],true)&&mb_check_encoding($bytes,'UTF-8')&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$bytes)){
            $mime='text/plain';$ext='txt';
        }else throw new DomainException('Erlaubt: PDF, PNG, JPG, WebP oder UTF-8-Text.');
        $name=basename(str_replace('\\','/',$original));
        if(!mb_check_encoding($name,'UTF-8'))$name='Anhang';
        $name=preg_replace('/[\x00-\x1f\x7f<>:"|?*]/u','',$name);
        $name=pathinfo($name,PATHINFO_FILENAME);$name=trim(mb_substr($name,0,160),' .');
        $result=[$bytes,$mime,($name===''?'Anhang':$name).'.'.$ext];if($origin!==null){$origin['prepared_sha256']=hash('sha256',$bytes);$result[]=$origin;}return$result;
    }
    private function extrasInstalled():bool
    {
        return(bool)Db::run($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='native_chat_file_extras'")->fetchColumn();
    }
    private function inside(string $path,string $root):bool
    {
        $path=strtolower(str_replace('\\','/',$path));$root=rtrim(strtolower(str_replace('\\','/',$root)),'/');return $path===$root||str_starts_with($path,$root.'/');
    }
}
