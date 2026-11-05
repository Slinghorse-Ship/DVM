<?php declare(strict_types=1);
namespace Kulturhus\Native\System;

/** Fixed server root, regular owned originals, bounded reads; never a request-chosen filename. */
final class PrivateFiles
{
    public function __construct(public readonly string $root)
    {
        if(!str_starts_with($root,'/')||str_contains($root,"\0")||realpath($root)!==$root||is_link($root)||!is_dir($root)||(fileperms($root)&0077))throw new \DomainException('Geschützte eigene Systemlaufzeit erforderlich.');$this->owner($root);
        $path='';foreach(explode('/',trim($root,'/'))as$segment){$path.='/'.$segment;if(is_link($path))throw new \DomainException('Systemlaufzeit darf keinen symbolischen Verzeichnispfad verwenden.');}
    }
    private function owner(string $path):void
    {if(function_exists('posix_geteuid')&&fileowner($path)!==posix_geteuid())throw new \DomainException('Eigene Systemlaufzeit prüfen.');}
    public function file(string $relative,bool $optional=false,int $max=1048576):?string
    {
        if(!preg_match('~^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$~D',$relative)||in_array('..',explode('/',$relative),true)||in_array('.',explode('/',$relative),true))throw new \LogicException('Festen privaten Systempfad verwenden.');$path=$this->root;
        foreach(explode('/',$relative)as$segment){$path.='/'.$segment;if(is_link($path))throw new \DomainException('Privaten Systemlink ablehnen.');}
        if(!file_exists($path)){if($optional)return null;throw new \DomainException('Ursprünglicher privater Systemnachweis fehlt.');}
        if(!is_file($path)||(fileperms($path)&0077)||filesize($path)>$max)throw new \DomainException('Begrenzten privaten Originalnachweis prüfen.');$this->owner($path);return $path;
    }
    public function json(string $relative,bool $optional=false,int $max=1048576):?array
    {
        $path=$this->file($relative,$optional,$max);if($path===null)return null;$handle=fopen($path,'rb');if(!$handle)throw new \DomainException('Privaten Originalnachweis lesen.');try{$bytes=stream_get_contents($handle,$max+1);if(!is_string($bytes)||strlen($bytes)>$max)throw new \DomainException('Privaten Originalnachweis begrenzen.');$value=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);if(!is_array($value)||($value!==[]&&array_is_list($value)))throw new \DomainException('Privaten Originalnachweis prüfen.');return $value;}finally{fclose($handle);}
    }
    public function maintenance():bool
    {foreach(['upgrade-hold','restore-hold']as$name){if(is_link($this->root.'/'.$name))throw new \DomainException('Wartungsnachweis prüfen.');if(file_exists($this->root.'/'.$name)){$this->file($name);return true;}}return false;}
}
