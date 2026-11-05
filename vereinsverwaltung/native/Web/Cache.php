<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
final class Cache
{
    public function __construct(private string $root) {}
    public function remember(string $key, callable $render,string $scope='default',array $dependencies=[],array $tags=[],?string $label=null,int $ttl=3600): string
    {
        $store=new \Kulturhus\Native\Cache\Store($this->root,$scope);
        $html=$store->remember('website-html',($label===null?'html':(strlen($label)>200?hash('sha256',$label):$label)).':'.hash('sha256',$key),$render,$ttl,$tags,$dependencies);
        if(!is_string($html))throw new \RuntimeException('Websitecache enthält kein HTML.');
        return $html;
    }
}
