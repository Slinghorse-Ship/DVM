<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

final class EInvoiceValidator
{
    public function __construct(private PDO $db,private string $actor){}
    public function canRun():bool{return Permissions::actor($this->db,$this->actor)['finance_access']==='write';}
    public static function configured():bool{return (string)getenv('VEREIN_KOSIT_RUNTIME')!=='';}
    public function latest(string $id):?array
    {
        $doc=(new Documents($this->db,$this->actor))->document($id);
        $q=$this->db->prepare('SELECT * FROM einvoice_validations WHERE club_id=? AND document_id=? ORDER BY id DESC LIMIT 1');$q->execute([$doc['club_id'],$id]);
        $r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
        $r['current']=(int)$r['revision']===(int)$doc['revision']&&hash_equals($r['sha256'],$doc['sha256']);return $r;
    }
    public function validate(string $id,int $revision,string $sha):array
    {
        if(!$this->canRun())throw new DomainException('Für die E-Rechnungsprüfung ist das Finanzschreibrecht erforderlich.');
        $documents=new Documents($this->db,$this->actor);$doc=$documents->document($id);$file=$documents->file($id,$revision);
        if(!in_array($doc['mime'],['application/xml','application/pdf'],true)||$revision!==(int)$doc['revision']||!hash_equals($doc['sha256'],$sha))throw new DomainException('Die Dateiversion hat sich geändert. Bitte neu öffnen.');
        $xml=EInvoiceInspection::xmlBytes((string)$file['bytes']);if($xml===null)throw new DomainException('Das PDF enthält keine eindeutige E-Rechnung.');
        $result=self::run($xml);
        // Record the exact tested original even if a replacement arrived during the process.
        $documents->document($id);if(!$this->canRun())throw new DomainException('Finanzschreibrecht wurde während der Prüfung entzogen.');
        $q=$this->db->prepare('INSERT INTO einvoice_validations(club_id,document_id,revision,sha256,actor_id,validator_sha256,configuration_sha256,state,scenario,report,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$doc['club_id'],$id,$revision,$sha,$this->actor,$result['validator_sha256'],$result['configuration_sha256'],$result['state'],$result['scenario'],$result['report'],gmdate('Y-m-d\TH:i:s\Z')]);
        return $result;
    }
    public static function configurationHash(string $directory):string
    {
        $files=[];$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){if($file->isLink())throw new DomainException('Validator-Konfiguration darf keine symbolischen Links enthalten.');if($file->isFile())$files[str_replace('\\','/',substr($file->getPathname(),strlen($directory)+1))]=hash_file('sha256',$file->getPathname());}
        ksort($files,SORT_STRING);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
    public static function run(string $bytes):array
    {
        $inspection=EInvoiceInspection::inspect($bytes);
        if($inspection['state']!=='recognized')throw new DomainException('Kein sicher erkanntes UBL-/CII-Rechnungsoriginal.');
        $path=(string)getenv('VEREIN_KOSIT_RUNTIME');$config=$path&&is_file($path)?json_decode((string)file_get_contents($path),true):null;
        if(!is_array($config))throw new DomainException('Die vollständige E-Rechnungsprüfung ist noch nicht eingerichtet.');
        foreach(['java','jar','scenarios','repository','workdir','validator_sha256','configuration_sha256'] as $key)if(empty($config[$key]))throw new DomainException('Validator-Laufzeit ist unvollständig eingerichtet.');
        // Relative trusted runtime paths remain usable inside SSH chroots and PHP-FPM.
        $runtimeDirectory=dirname((string)realpath($path));
        foreach(['java','jar','scenarios','repository','workdir'] as $key){
            if(!is_string($config[$key]))throw new DomainException('Validator-Pfade sind ungültig.');
            $value=$config[$key];
            if($value[0]!=='/'&&$value[0]!=='\\'&&!preg_match('~^[A-Za-z]:[/\\\\]~D',$value))$config[$key]=$runtimeDirectory.DIRECTORY_SEPARATOR.$value;
        }
        foreach(['java','jar','scenarios'] as $key)if(!is_file($config[$key]))throw new DomainException('Validator-Laufzeit fehlt.');
        $repository=realpath($config['repository']);$workdir=realpath($config['workdir']);
        if(!$repository||!$workdir||realpath($config['scenarios'])!==$repository.DIRECTORY_SEPARATOR.'scenarios.xml')throw new DomainException('Validator-Pfade sind ungültig.');
        $lock=fopen($workdir.DIRECTORY_SEPARATOR.'validation.lock','c');if(!$lock)throw new DomainException('Prüfsperre nicht verfügbar.');
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new DomainException('Eine E-Rechnungsprüfung läuft bereits. Bitte später erneut versuchen.');}
        $dir=$workdir.DIRECTORY_SEPARATOR.'check-'.bin2hex(random_bytes(12));$process=null;$previousTimeLimit=(int)ini_get('max_execution_time');
        try{
            $jarHash=hash_file('sha256',$config['jar']);$configHash=self::configurationHash($repository);
            if(!hash_equals($config['validator_sha256'],$jarHash)||!hash_equals($config['configuration_sha256'],$configHash))throw new DomainException('Validator-Artefakte stimmen nicht mit der freigegebenen Konfiguration überein.');
            if(!mkdir($dir,0700))throw new DomainException('Privates Prüfverzeichnis nicht verfügbar.');
            file_put_contents($dir.'/invoice.xml',$bytes);chmod($dir.'/invoice.xml',0600);
            $args=[$config['java'],'-Xmx256m','-Djavax.xml.accessExternalDTD=','-Djavax.xml.accessExternalSchema=file','-Djavax.xml.accessExternalStylesheet=file','-jar',$config['jar'],'-s',$config['scenarios'],'-r',$repository,'-o',$dir,$dir.'/invoice.xml'];
            set_time_limit(120);
            $process=proc_open($args,[0=>['pipe','r'],1=>['file',$dir.'/stdout.txt','w'],2=>['file',$dir.'/stderr.txt','w']],$pipes,$dir,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new DomainException('Validator konnte nicht gestartet werden.');fclose($pipes[0]);
            $exit=self::monitorProcess($process,$dir);proc_close($process);$process=null;
            $reportPath=$dir.'/invoice-report.xml';
            if(!in_array($exit,[0,1],true)||!is_file($reportPath)||filesize($reportPath)>8*1024*1024)throw new DomainException('Validator lieferte kein verwertbares Prüfergebnis.');
            $report=(string)file_get_contents($reportPath);$parsed=self::parseReport($report,hash('sha256',$bytes),$exit);
            return $parsed+['report'=>$report,'validator_sha256'=>$jarHash,'configuration_sha256'=>$configHash];
        }finally{
            set_time_limit($previousTimeLimit);
            if(is_resource($process)){proc_terminate($process,9);proc_close($process);}
            if(is_dir($dir)){foreach(scandir($dir) as $name)if($name!=='.'&&$name!=='..'&&is_file($dir.'/'.$name))unlink($dir.'/'.$name);rmdir($dir);}
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
    /** Separate resource guard so timeout/output handling can be tested without a fake validator. */
    public static function monitorProcess($process,string $directory,int $seconds=90,int $budget=16777216):int
    {
        $start=microtime(true);
        do{
            clearstatcache();$total=0;
            foreach(scandir($directory) as $name){if(in_array($name,['.','..','invoice.xml'],true))continue;$path=$directory.DIRECTORY_SEPARATOR.$name;if(is_file($path))$total+=(int)filesize($path);}
            if($total>$budget){proc_terminate($process,9);throw new DomainException('Prüfung wegen zu großer Prüfausgaben abgebrochen; kein gültiges Ergebnis.');}
            $status=proc_get_status($process);if(!$status['running'])return (int)$status['exitcode'];
            if(microtime(true)-$start>$seconds){proc_terminate($process,9);throw new DomainException('Prüfung wegen Zeitüberschreitung abgebrochen; kein gültiges Ergebnis.');}
            usleep(100000);
        }while(true);
    }
    public static function parseReport(string $report,string $sha,int $exit):array
    {
        if(str_contains($report,'<!DOCTYPE')||str_contains($report,'<!ENTITY'))throw new DomainException('Unsicherer Prüfbericht.');
        $previous=libxml_use_internal_errors(true);
        try{
            $dom=new \DOMDocument();if(!$dom->loadXML($report,LIBXML_NONET))throw new DomainException('Unlesbarer Prüfbericht.');
            $xp=new \DOMXPath($dom);$xp->registerNamespace('r','http://www.xoev.de/de/validator/varl/1');$xp->registerNamespace('s','http://www.xoev.de/de/validator/framework/1/scenarios');
            $hash=$xp->evaluate('string(/r:report/r:documentIdentification/r:documentHash/r:hashValue)');
            $algo=$xp->evaluate('string(/r:report/r:documentIdentification/r:documentHash/r:hashAlgorithm)');
            if($algo!=='SHA-256'||!hash_equals(base64_encode(hex2bin($sha)),$hash))throw new DomainException('Prüfbericht gehört nicht zum Original.');
            $accepted=$xp->query('/r:report/r:assessment/r:accept')->length===1;
            if(($exit===0)!==$accepted)throw new DomainException('Widersprüchlicher Prüfstatus.');
            return ['state'=>$accepted?'valid':'invalid','scenario'=>$xp->evaluate('string(/r:report/r:scenarioMatched/s:scenario/s:name)')];
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    public static function messages(string $report):array
    {
        if(str_contains($report,'<!DOCTYPE')||str_contains($report,'<!ENTITY'))return [];
        $previous=libxml_use_internal_errors(true);
        try{
            $dom=new \DOMDocument();if(!$dom->loadXML($report,LIBXML_NONET))return [];
            $xp=new \DOMXPath($dom);$xp->registerNamespace('r','http://www.xoev.de/de/validator/varl/1');$messages=[];
            foreach($xp->query('/r:report/r:scenarioMatched/r:validationStepResult/r:message') as $message){$messages[]=substr(trim(preg_replace('/\s+/u',' ',$message->textContent)),0,2000);if(count($messages)>=50)break;}
            return $messages;
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
