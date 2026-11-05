<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Monthly reference data only. Never changes accounts, credentials or bank dialogues. */
final class FintsDirectory
{
    public const MAX_UPLOAD=16777216;
    private string $root;
    public function __construct(private PDO $db,private string $actor,?string $bankingRoot=null)
    {
        $this->root=rtrim($bankingRoot??FintsInstitutions::root(),'/').'/institutions';
    }
    public function canManage():bool
    {
        try{$this->authorize();return true;}catch(DomainException){return false;}
    }
    private function authorize():array
    {
        $a=Permissions::finance($this->db,$this->actor,true);
        if(empty($a['is_superuser'])||empty($a['settings_access']))throw new DomainException('Die Bankenliste kann nur die technische Vereinsverwaltung aktualisieren.');
        if(ProductionRuntime::enabled()&&$a['club_id']!==ProductionRuntime::club())throw new DomainException('Die Bankenliste gehört zu einer anderen Installation.');
        foreach([$this->root.'/owner.json',dirname($this->root).'/fints.json']as$path)if(file_exists($path)||is_link($path)){
            $owner=self::read($path);if(isset($owner['club_id'])&&$owner['club_id']!==$a['club_id'])throw new DomainException('Die Bankenliste gehört zu einem anderen Verein.');
        }
        return $a;
    }
    private static function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function read(string $path):array
    {
        if(is_link($path)||!is_file($path)||filesize($path)>8388608)throw new DomainException('Die gespeicherte Bankenliste ist nicht lesbar.');
        try{$v=json_decode((string)file_get_contents($path),true,24,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new DomainException('Die gespeicherte Bankenliste ist ungültig.');}
        if(!is_array($v))throw new DomainException('Die gespeicherte Bankenliste ist ungültig.');return $v;
    }
    private function directory(string $path):void
    {
        if(!str_starts_with($path,'/')||preg_match('~(?:^|/)\.\.(?:/|$)~',$path))throw new DomainException('Privates Verzeichnis für Bankenlisten erforderlich.');
        for($p=$path;$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new DomainException('Verzeichnisverknüpfungen sind für Bankenlisten nicht erlaubt.');
        $web=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:dirname(__DIR__,3).'/public');
        if($web&&($path===$web||str_starts_with($path,$web.'/')))throw new DomainException('Bankenlisten müssen außerhalb des Webverzeichnisses privat gespeichert werden.');
        if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new DomainException('Bankenliste konnte nicht vorbereitet werden.');
        $real=realpath($path);
        if(!$real||($web&&($real===$web||str_starts_with($real,$web.'/')))||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)))throw new DomainException('Bankenlisten müssen außerhalb des Webverzeichnisses privat gespeichert werden.');
        for($p=$path;$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new DomainException('Verzeichnisverknüpfungen sind für Bankenlisten nicht erlaubt.');
    }
    private static function immutable(string $path,string $bytes):void
    {
        if(file_exists($path)||is_link($path)){if(is_link($path)||!is_file($path)||!hash_equals(hash('sha256',$bytes),(string)hash_file('sha256',$path)))throw new DomainException('Eine frühere Bankenlisten-Fassung weicht ab.');return;}
        $f=@fopen($path,'xb');if(!$f)throw new DomainException('Bankenliste konnte nicht gespeichert werden.');
        $complete=false;
        try{if(!chmod($path,0600))throw new DomainException('Bankenliste konnte nicht privat gespeichert werden.');$offset=0;while($offset<strlen($bytes)){$n=fwrite($f,substr($bytes,$offset));if(!$n)throw new DomainException('Bankenliste konnte nicht vollständig gespeichert werden.');$offset+=$n;}if(!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new DomainException('Bankenliste konnte nicht gesichert werden.');$complete=true;}finally{fclose($f);if(!$complete)unlink($path);}
    }
    private function current():array
    {
        $path=$this->root.'/active.json';
        if(!file_exists($path)&&!is_link($path))$path=FintsInstitutions::legacyPath();
        if(!file_exists($path)&&!is_link($path))return ['revision'=>hash('sha256',''),'data'=>['institutions'=>[]]];
        return ['revision'=>(string)hash_file('sha256',$path),'data'=>self::read($path),'path'=>$path];
    }
    public function state():array
    {
        $this->authorize();$state=$this->current();$data=$state['data'];
        return ['revision'=>$state['revision'],'month'=>$data['month']??'','installed_at'=>$data['installed_at']??'','banks'=>count($data['institutions']??[]),'source_name'=>$data['source_name']??'','operation'=>$data['operation']??''];
    }
    private static function text(mixed $value,int $max):string
    {
        if(!is_string($value)||mb_strlen($value,'UTF-8')>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Die Bankenliste enthält ungültige Angaben.');return preg_replace('/\s+/u',' ',trim($value));
    }
    /** Only the one CSV is read. XLSX/PDF companions are retained, never executed or extracted. */
    public static function parse(string $bytes):array
    {
        if($bytes===''||strlen($bytes)>self::MAX_UPLOAD)throw new DomainException('ZIP oder CSV der Bankenliste bis 16 MiB auswählen.');
        if(str_starts_with($bytes,'PK')){
            if(!class_exists(\ZipArchive::class))throw new DomainException('ZIP-Unterstützung fehlt auf diesem Webspace. Die CSV aus dem Paket kann direkt hochgeladen werden.');
            $tmp=tempnam(sys_get_temp_dir(),'fints-reference-');if($tmp===false)throw new DomainException('Temporärer Upload nicht verfügbar.');chmod($tmp,0600);
            try{
                file_put_contents($tmp,$bytes);$zip=new \ZipArchive();if($zip->open($tmp)!==true)throw new DomainException('Das ZIP-Paket ist nicht lesbar.');
                try{
                    if($zip->numFiles<1||$zip->numFiles>16)throw new DomainException('Das ZIP-Paket enthält zu viele Dateien.');$csv=[];$total=0;
                    for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$name=$s['name']??'';$total+=(int)($s['size']??0);
                        $ops=0;$attrs=0;$zip->getExternalAttributesIndex($i,$ops,$attrs);
                        if(!is_string($name)||preg_match('~(?:^/|^[A-Za-z]:|\\\\|(?:^|/)\.\.(?:/|$)|\x00)~',$name)||(($attrs>>16)&0170000)===0120000||($s['encryption_method']??0)!==0||$total>67108864)throw new DomainException('Das ZIP-Paket enthält unsichere oder zu große Einträge.');
                        if(str_ends_with(strtolower($name),'.csv')){if(($s['size']??0)>8388608)throw new DomainException('Die Banken-CSV darf höchstens 8 MiB groß sein.');$csv[]=$i;}
                    }
                    if(count($csv)!==1)throw new DomainException('Das ZIP-Paket muss genau eine Banken-CSV enthalten.');
                    $raw=$zip->getFromIndex($csv[0],8388609);if(!is_string($raw)||strlen($raw)>8388608)throw new DomainException('Die Banken-CSV konnte nicht gelesen werden.');
                }finally{$zip->close();}
            }finally{unlink($tmp);}
        }else{$raw=$bytes;if(strlen($raw)>8388608)throw new DomainException('Die Banken-CSV darf höchstens 8 MiB groß sein.');}
        $raw=preg_replace('/^\xef\xbb\xbf/','',$raw);
        if(!mb_check_encoding($raw,'UTF-8'))$raw=mb_convert_encoding($raw,'UTF-8','Windows-1252');
        $stream=fopen('php://temp','w+');fwrite($stream,$raw);rewind($stream);
        try{
            $header=fgetcsv($stream,16384,';','"','');if(!is_array($header)||count($header)>60)throw new DomainException('Die Kopfzeile der Banken-CSV fehlt.');
            $header=array_map(static fn($v)=>preg_replace('/\s+/u',' ',trim((string)$v)),$header);$columns=array_flip($header);
            foreach(['BLZ','BIC','Institut','Ort','PIN/TAN-Zugang URL','Version']as$key)if(!array_key_exists($key,$columns)||count(array_keys($header,$key,true))!==1)throw new DomainException('Die CSV benötigt BLZ, BIC, Institut, Ort, PIN/TAN-Zugang URL und Version.');
            $stats=['source_rows'=>0,'without_pin_tan'=>0,'unsupported_version'=>0,'duplicates'=>0,'missing_bic'=>0];$banks=[];
            while(($row=fgetcsv($stream,16384,';','"',''))!==false){if($row===[null]||$row===[])continue;if(++$stats['source_rows']>20000||count($row)!==count($header))throw new DomainException('Zeilenaufbau oder Größe der Banken-CSV prüfen.');
                $url=self::text($row[$columns['PIN/TAN-Zugang URL']],500);if($url===''){$stats['without_pin_tan']++;continue;}
                if(!preg_match('/^(?:FinTS V)?3\.0$/D',trim($row[$columns['Version']]))){$stats['unsupported_version']++;continue;}
                $code=self::text($row[$columns['BLZ']],8);$bic=strtoupper(self::text($row[$columns['BIC']],11));$name=self::text($row[$columns['Institut']],200);$city=self::text($row[$columns['Ort']],200);$u=parse_url($url);$host=strtolower($u['host']??'');
                if(in_array($bic,['#NV','#N/A','0'],true))$bic='';if($bic==='')$stats['missing_bic']++;
                if(!preg_match('/^[0-9]{8}$/D',$code)||($bic!==''&&!preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D',$bic))||$name===''||!filter_var($url,FILTER_VALIDATE_URL)||($u['scheme']??'')!=='https'||!str_contains($host,'.')||!preg_match('/^[a-z0-9.-]+$/D',$host)||filter_var($host,FILTER_VALIDATE_IP)||preg_match('/(?:^|\.)(?:localhost|local|internal)$/D',$host)||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||isset($u['port'])&&$u['port']!==443)throw new DomainException('Die CSV enthält einen ungültigen FinTS-Bankendpunkt.');
                $url='https://'.$host.($u['path']??'');$id=$code.'-'.substr(hash('sha256',rtrim($url,'/')),0,24);
                if(isset($banks[$id])){$stats['duplicates']++;continue;}
                $banks[$id]=['id'=>$id,'bank_code'=>$code,'bic'=>$bic,'name'=>$name,'city'=>$city,'url'=>$url,'version'=>'3.0'];
                if(count($banks)>5000)throw new DomainException('Höchstens 5.000 FinTS-Bankendpunkte sind zulässig.');
            }
            if(!$banks)throw new DomainException('Die Datei enthält keine unterstützten FinTS-3-PIN/TAN-Zugänge.');ksort($banks);$stats['banks']=count($banks);return ['institutions'=>array_values($banks),'statistics'=>$stats];
        }finally{fclose($stream);}
    }
    public function preview(string $bytes,string $filename,string $month):array
    {
        $a=$this->authorize();if(!preg_match('/^20[0-9]{2}-(?:0[1-9]|1[0-2])$/D',$month))throw new DomainException('Monat der Bankenliste auswählen.');
        $parsed=self::parse($bytes);$old=$this->current();$byEndpoint=[];
        foreach($old['data']['institutions']??[]as$row)if(is_array($row)&&isset($row['id'],$row['bank_code'],$row['url']))$byEndpoint[$row['bank_code'].'|'.rtrim($row['url'],'/')]=$row['id'];
        // Preserve existing bank IDs only when the original endpoint remains exactly the same.
        foreach($parsed['institutions']as&$row){$key=$row['bank_code'].'|'.rtrim($row['url'],'/');if(isset($byEndpoint[$key]))$row['id']=$byEndpoint[$key];}unset($row);
        $oldEndpoints=array_fill_keys(array_keys($byEndpoint),true);$newEndpoints=[];foreach($parsed['institutions']as$row)$newEndpoints[$row['bank_code'].'|'.rtrim($row['url'],'/')]=true;
        $id=bin2hex(random_bytes(16));$data=$parsed+['format'=>1,'month'=>$month,'source_name'=>mb_substr(basename(str_replace('\\','/',$filename)),0,200),'source_sha256'=>hash('sha256',$bytes),'installed_at'=>gmdate('c'),'actor'=>$a['id'],'club_id'=>$a['club_id'],'operation'=>$id];
        $plan=['base'=>$old['revision'],'data'=>$data,'added'=>count(array_diff_key($newEndpoints,$oldEndpoints)),'removed'=>count(array_diff_key($oldEndpoints,$newEndpoints))];
        $this->directory(dirname($this->root));$this->directory($this->root);self::immutable($this->root.'/owner.json',self::json(['club_id'=>$a['club_id']]));$this->directory($this->root.'/versions');$dir=$this->root.'/versions/'.$id;$this->directory($dir);
        self::immutable($dir.'/source.bin',$bytes);self::immutable($dir.'/plan.json',self::json($plan));return $this->prepared($id);
    }
    private function plan(string $id):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Eigene geprüfte Bankenliste auswählen.');$a=$this->authorize();$dir=$this->root.'/versions/'.$id;
        $this->directory($this->root);if(is_link($this->root.'/versions')||is_link($dir))throw new DomainException('Die Vorschau ist nicht verfügbar.');$p=self::read($dir.'/plan.json');
        if(($p['data']['actor']??null)!==$a['id']||($p['data']['club_id']??null)!==$a['club_id']||($p['data']['operation']??null)!==$id)throw new DomainException('Die Vorschau gehört zu einem anderen Zugang.');
        $source=$dir.'/source.bin';if(is_link($source)||!is_file($source)||!hash_equals($p['data']['source_sha256']??'',(string)hash_file('sha256',$source)))throw new DomainException('Das ursprüngliche Uploadpaket wurde verändert.');return $p;
    }
    public function prepared(string $id):array
    {
        $p=$this->plan($id);$d=$p['data'];return ['id'=>$id,'month'=>$d['month'],'source_name'=>$d['source_name'],'statistics'=>$d['statistics'],'added'=>$p['added'],'removed'=>$p['removed'],'sample'=>array_slice($d['institutions'],0,5)];
    }
    public function apply(string $id,bool $confirmed):array
    {
        $this->authorize();if(!$confirmed)throw new DomainException('Geprüfte Bankenliste vor der Übernahme bestätigen.');if($this->db->inTransaction())throw new DomainException('Bankenliste außerhalb einer Buchung aktualisieren.');
        $this->directory($this->root);$lock=$this->root.'/update.lock';if(is_link($lock))throw new DomainException('Bankenliste ist gesperrt.');$f=fopen($lock,'c+');if(!$f)throw new DomainException('Bankenliste kann gerade nicht aktualisiert werden.');chmod($lock,0600);
        try{
            if(!flock($f,LOCK_EX|LOCK_NB))throw new DomainException('Eine andere Bankenliste wird gerade übernommen. Erneut versuchen.');
            $this->db->beginTransaction();try{
                $p=$this->plan($id);$current=$this->current();$dir=$this->root.'/versions/'.$id;$receipt=$dir.'/receipt.json';$bytes=self::json($p['data']);$revision=hash('sha256',$bytes);
                if(file_exists($receipt)||is_link($receipt)){if(self::read($receipt)!==['revision'=>$revision])throw new DomainException('Die frühere Übernahme konnte nicht bestätigt werden.');return $this->state()+['replayed'=>true];}
                if(($current['data']['operation']??'')===$id){if(!hash_equals($current['revision'],$revision))throw new DomainException('Die übernommene Fassung wurde verändert.');self::immutable($receipt,self::json(['revision'=>$revision]));return $this->state()+['replayed'=>true];}
                if(!hash_equals($p['base'],$current['revision']))throw new DomainException('Die Bankenliste wurde inzwischen aktualisiert. Datei neu prüfen.');
                // Retain complete earlier active bytes, including a legacy operational list.
                if(isset($current['path']))self::immutable($dir.'/previous.json',(string)file_get_contents($current['path']));
                self::immutable($dir.'/published.json',$bytes);$temp=$this->root.'/publish-'.$id.'.tmp';self::immutable($temp,$bytes);
                if(is_link($this->root.'/active.json')||!rename($temp,$this->root.'/active.json'))throw new DomainException('Die neue Bankenliste konnte nicht aktiviert werden.');
                self::immutable($receipt,self::json(['revision'=>$revision]));return $this->state()+['replayed'=>false];
            }finally{if($this->db->inTransaction())$this->db->rollBack();}
        }finally{flock($f,LOCK_UN);fclose($f);}
    }
}
