<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** One reviewed, private host entry point; never accepts an HTTP path or PHP upload. */
final class HostPlugins
{
    private static array $cache=[];

    private static function privatePath(string $path,string $root,bool $directory): void
    {
        if(PHP_OS_FAMILY==='Windows'||!function_exists('posix_geteuid')||!str_starts_with($path,'/')||str_contains($path,"\0")||str_contains($path,'\\'))throw new \DomainException('Privater Erweiterungspfad nicht prüfbar.');
        $segments=explode('/',trim($path,'/'));$cursor='';
        foreach($segments as $segment){if($segment===''||$segment==='.'||$segment==='..')throw new \DomainException('Privater Erweiterungspfad ungültig.');$cursor.='/'.$segment;if(is_link($cursor))throw new \DomainException('Symbolische Erweiterungspfade nicht erlaubt.');}
        $resolved=realpath($path);$base=realpath($root);
        if(!$resolved||!$base||($resolved!==$base&&!str_starts_with($resolved,$base.'/'))||($directory?!is_dir($resolved):!is_file($resolved)))throw new \DomainException('Private Erweiterungsdatei nicht verfügbar.');
        $paths=[$base];$relative=ltrim(substr($resolved,strlen($base)),'/');$current=$base;
        if($relative!=='')foreach(explode('/',$relative) as $part){$current.='/'.$part;$paths[]=$current;}
        foreach($paths as $item){$stat=lstat($item);if(!$stat||$stat['uid']!==posix_geteuid()||($stat['mode']&0077)!==0)throw new \DomainException('Private Erweiterungsrechte prüfen.');}
        foreach([dirname(__DIR__,2).'/public',$_SERVER['DOCUMENT_ROOT']??''] as $webroot){$web=$webroot!==''?realpath($webroot):false;if($web&&($base===$web||str_starts_with($base,$web.'/')))throw new \DomainException('Erweiterungen müssen außerhalb des Webroots liegen.');}
    }

    public static function catalog(): Catalog
    {
        $base=new Catalog(new WebsiteNotice(),new EditorialLayouts());$root=getenv('VEREIN_PRIVATE_ROOT');
        if(!is_string($root)||$root==='')return $base;
        $file=rtrim($root,'/').'/reviewed-plugins.php';
        // Missing optional entry point is ordinary: only locally shipped modules load.
        if(!is_file($file)&&!is_link($file))return $base;
        try{
            self::privatePath($root,$root,true);self::privatePath($file,$root,false);
            $hash=hash_file('sha256',$file);$cacheKey=$file.':'.$hash;
            if(isset(self::$cache[$cacheKey])){
                foreach(self::$cache[$cacheKey]->ordered() as $id=>$plugin){if(in_array($id,['website-notice','editorial-layouts'],true))continue;foreach(self::$cache[$cacheKey]->sources($id)as$source)self::privatePath($source,$root,false);}
                return self::$cache[$cacheKey];
            }
            $handle=fopen($file,'rb');if(!$handle||!flock($handle,LOCK_SH))throw new \DomainException('Lokale Erweiterungsregistrierung nicht verfügbar.');
            $level=ob_get_level();ob_start();
            try{
                $plugins=(static function(string $entry):mixed{return require $entry;})($file);
                $output=ob_get_contents();
                if($output!==''||!is_array($plugins)||!array_is_list($plugins)||count($plugins)>50||!hash_equals($hash,hash_file('sha256',$file)))throw new \DomainException('Lokale Erweiterungsregistrierung ungültig.');
                foreach($plugins as $plugin){
                    if(!$plugin instanceof Plugin)throw new \DomainException('Lokale Erweiterungsschnittstelle fehlt.');
                    $source=(new \ReflectionClass($plugin))->getFileName();if(!$source)throw new \DomainException('Lokale Erweiterungsquelle fehlt.');self::privatePath($source,$root,false);
                    $base->register($plugin);
                    foreach($base->sources($plugin->definition()->id)as$source)self::privatePath($source,$root,false);
                }
                $base->ordered();
            }finally{
                while(ob_get_level()>$level)ob_end_clean();flock($handle,LOCK_UN);fclose($handle);
            }
            // Request-local only. A different source checksum is revalidated.
            if(count(self::$cache)<16)self::$cache[$cacheKey]=$base;
            return $base;
        }catch(\Throwable){$safe=new Catalog(new WebsiteNotice(),new EditorialLayouts());$safe->report('host_registry_failed');return $safe;}
    }
}
