<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

/** Read-only prerequisites. File availability does not certify a successful decoder run. */
final class PhotoRuntime
{
    public const RUNNERS=['vereinsverwaltung/tools/media/camera-photo.mjs','vereinsverwaltung/tools/media/raster-photo.mjs'];
    public const DEPENDENCIES=[
        'vereinsverwaltung/resources/photo-import/photo-libheif.js',
        'vereinsverwaltung/resources/photo-import/photo-libraw-worker.js',
        'vereinsverwaltung/resources/photo-import/photo-libraw.mjs',
        'vereinsverwaltung/resources/photo-import/photo-libraw.wasm',
        'vereinsverwaltung/resources/raster-photo/magick.mjs',
        'vereinsverwaltung/resources/raster-photo/magick.wasm',
        'vereinsverwaltung/native/Web/assets/photo-import-worker.js',
        'vereinsverwaltung/native/Web/assets/photo-raw-preview.mjs',
    ];
    public static function inspect(?string $app=null):array
    {
        $app=$app??dirname(__DIR__,3);
        $files=static function(array $paths)use($app):bool{foreach($paths as$path)if(!@is_file($app.'/'.$path)||!@is_readable($app.'/'.$path)||@filesize($app.'/'.$path)<1)return false;return true;};
        $processes=true;foreach(['proc_open','proc_get_status','proc_terminate','proc_close']as$function)$processes=$processes&&function_exists($function);
        $command=self::command();$temporary=sys_get_temp_dir();
        $result=['processes'=>$processes,'node'=>self::available($command),'runners'=>$files(self::RUNNERS),'codecs'=>$files(self::DEPENDENCIES),'temporary'=>@is_dir($temporary)&&@is_readable($temporary)&&@is_writable($temporary)];
        return $result+['present'=>!in_array(false,$result,true),'verified'=>false];
    }
    /** Plesk's CLI binaries exist independently of its optional Node web application. */
    public static function command():string
    {
        $configured=getenv('VEREIN_PHOTO_NODE');if(is_string($configured)&&$configured!=='')return $configured;
        if(self::available('node'))return 'node';
        foreach(['/opt/plesk/node/24/bin/node','/opt/plesk/node/22/bin/node','/opt/plesk/node/20/bin/node']as$path)if(self::available($path))return $path;
        return 'node';
    }
    private static function available(string $command):bool
    {
        if(self::executable($command))return true;
        // Hosting may allow launching its supplied runtime while open_basedir
        // prevents PHP from statting the runtime directory. Test the allowed
        // process API directly, with no shell, writes or decoder side effects.
        static $checked=[];if(array_key_exists($command,$checked))return $checked[$command];
        foreach(['proc_open','proc_get_status','proc_terminate','proc_close']as$f)if(!function_exists($f))return $checked[$command]=false;
        if($command===''||strlen($command)>4096||str_contains($command,"\0"))return $checked[$command]=false;
        $pipes=[];$process=@proc_open([$command,'--version'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))return $checked[$command]=false;
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$output='';$start=hrtime(true);
        try{do{$output.=stream_get_contents($pipes[1],128-strlen($output));stream_get_contents($pipes[2],512);$state=proc_get_status($process);if(!$state['running'])break;if(strlen($output)>=128||hrtime(true)-$start>2000000000){proc_terminate($process,9);return $checked[$command]=false;}usleep(10000);}while(true);$output.=stream_get_contents($pipes[1],128-strlen($output));return $checked[$command]=($state['exitcode']===0&&preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+\s*\z/D',$output)===1);}
        finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
    }
    private static function executable(string $command):bool
    {
        if($command===''||strlen($command)>4096||str_contains($command,"\0")||!function_exists('is_executable'))return false;
        $valid=static fn(string $path):bool=>@is_file($path)&&@is_executable($path);
        if(str_contains($command,'/')||(PHP_OS_FAMILY==='Windows'&&str_contains($command,'\\')))return $valid($command);
        $path=getenv('PATH');$path=$path===false?'/bin:/usr/bin':$path;
        $names=PHP_OS_FAMILY==='Windows'&&!str_contains($command,'.')?[$command,$command.'.exe']:[$command];
        foreach(explode(PATH_SEPARATOR,$path)as$directory)foreach($names as$name)if($valid(($directory===''?'.':$directory).DIRECTORY_SEPARATOR.$name))return true;
        return false;
    }
}
