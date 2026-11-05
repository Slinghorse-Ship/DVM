<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** A private candidate workspace, never a path into the active application. */
final class CatalogImportProcess
{
    public const FILES=['original.pdf','catalog.json','comparison.json','review.json','review.html','package.zip','submitted-review.json'];
    public static function run(array $request,array $files):array
    {
        $python=getenv('VEREIN_SKR42_PYTHON')?:getenv('VEREIN_PYTHON');
        if(!$python){$config=getenv('VEREIN_OCR_RUNTIME')?:dirname(dirname(__DIR__, 1),2).'/.local/vereinsverwaltung/ocr-runtime.json';
            $python=InvoiceRecognition::runtime($config)['python'];}
        if(!is_string($python)||!is_file($python))throw new DomainException('Der Kontenplan-Import ist noch nicht eingerichtet. Bitte die Administration fragen.');
        $dir=sys_get_temp_dir().'/kulturhus-catalog-'.bin2hex(random_bytes(16));$process=null;
        if(!mkdir($dir,0700))throw new DomainException('Arbeitsordner für den Kontenplan-Import nicht verfügbar.');
        try{
            foreach($files as $name=>$bytes){if(!in_array($name,self::FILES,true)||!is_string($bytes)||strlen($bytes)>8388608)throw new DomainException('Importdatei ungültig.');file_put_contents($dir.'/'.$name,$bytes);chmod($dir.'/'.$name,0600);}
            file_put_contents($dir.'/request.json',json_encode($request,JSON_THROW_ON_ERROR));chmod($dir.'/request.json',0600);
            $env=['PATH'=>PHP_OS_FAMILY==='Windows'?'C:\\Windows\\System32':'/usr/bin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8','PYTHONUTF8'=>'1','PYTHONDONTWRITEBYTECODE'=>'1','PYTHONNOUSERSITE'=>'1','TMPDIR'=>$dir,'TMP'=>$dir,'TEMP'=>$dir];
            // Shared hosting may require a private ELF loader. Child importers
            // must use the configured launcher, not Python's bare executable.
            $env['KULTURHUS_CATALOG_PYTHON']=$python;
            if(PHP_OS_FAMILY==='Windows')$env['SystemRoot']='C:\\Windows';
            $process=proc_open([$python,'-X','utf8','-s',dirname(__DIR__, 1).'/../tools/datev_web.py',$dir],[0=>['file',$dir.'/request.json','r'],1=>['file',$dir.'/result.json','w'],2=>['file',$dir.'/errors.txt','w']],$pipes,dirname(__DIR__, 1),$env,['bypass_shell'=>true]);
            if(!is_resource($process))throw new DomainException('Kontenplan-Prüfung konnte nicht gestartet werden.');
            $deadline=microtime(true)+150;
            do{$status=proc_get_status($process);if(!$status['running'])break;if(microtime(true)>$deadline)throw new DomainException('Die Prüfung dauert zu lange. Bitte später erneut versuchen.');usleep(50000);}while(true);
            proc_close($process);$process=null;
            if($status['exitcode']!==0||!is_file($dir.'/result.json')||filesize($dir.'/result.json')>1000000)throw new DomainException('Kontenplan konnte nicht sicher geprüft werden. Original und Angaben prüfen.');
            $result=json_decode((string)file_get_contents($dir.'/result.json'),true,64,JSON_THROW_ON_ERROR);$out=[];
            foreach($result['files']??[] as $name){if(!in_array($name,self::FILES,true)||!is_file($dir.'/'.$name)||is_link($dir.'/'.$name)||filesize($dir.'/'.$name)>8388608)throw new DomainException('Prüfpaket unvollständig.');$out[$name]=(string)file_get_contents($dir.'/'.$name);}
            unset($result['files']);return ['result'=>$result,'files'=>$out];
        }finally{
            if(is_resource($process)){proc_terminate($process);proc_close($process);}
            $remove=static function(string $path)use(&$remove):void{foreach(new \DirectoryIterator($path) as $file){if($file->isDot())continue;if($file->isDir()&&!$file->isLink())$remove($file->getPathname());else unlink($file->getPathname());}rmdir($path);};
            $remove($dir);
        }
    }
}
