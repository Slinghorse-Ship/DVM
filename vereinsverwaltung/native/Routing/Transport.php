<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use InvalidArgumentException;

/** Deployment-selected origin and mount. Neither Host nor forwarding headers select a target. */
final class Transport
{
    public readonly string $origin;
    public readonly bool $loopback;
    public function __construct(string $origin,public readonly bool $demo=false,public readonly bool $secure=true)
    {
        $parts=parse_url($origin);
        if(!$parts||!in_array($parts['scheme']??null,['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['path']??'',['','/'],true)||preg_match('/[\x00-\x20\x7f\\\\]/',$origin)||filter_var($origin,FILTER_VALIDATE_URL)===false)throw new InvalidArgumentException('Feste Website-Origin ohne Pfad oder Zugangsdaten erforderlich.');
        $this->loopback=in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true);
        if($parts['scheme']==='http'&&!$this->loopback)throw new InvalidArgumentException('Produktionswebsites behalten HTTPS.');
        $this->origin=rtrim($origin,'/');
    }
    public function needsHttps(Policy $policy):bool{return !$this->secure&&(!$this->loopback||$policy->definition['https']==='https');}
    public function absolute(string $path,bool $https=false):string
    {
        new RequestPath($path);
        $origin=$https?preg_replace('~\Ahttp://~','https://',$this->origin):$this->origin;
        return $origin.$path;
    }
    public function base(string $contentPath,bool $suffix=false):string
    {
        if(strlen($contentPath)>197||!preg_match('~\A[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D',$contentPath))throw new InvalidArgumentException('Veröffentlichte Basisadresse prüfen.');
        return $this->demo?'/site/'.$contentPath:($contentPath==='start'&&!$suffix?'/':'/'.$contentPath);
    }
    public function slash(string $path,Policy $policy):string
    {
        if($path==='/')return'/';
        $always=$policy->definition['slash']==='always'||($policy->definition['slash']==='inherit'&&!$this->demo);
        return rtrim($path,'/').($always?'/':'');
    }
}
