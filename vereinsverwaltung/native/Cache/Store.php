<?php declare(strict_types=1);
namespace Kulturhus\Native\Cache;

/** Private, bounded JSON cache. Values are disposable; source records never live here. */
final class Store
{
    public const PAGE_SIZE=10;
    private string $directory;
    private \Closure $clock;
    private ?\Closure $guard;
    public function __construct(
        string $root,
        private string $scope,
        private int $maxEntries=256,
        private int $maxBytes=33554432,
        private int $maxItemBytes=1048576,
        ?callable $clock=null,
        ?callable $guard=null,
    ) {
        self::text($scope,128);
        if($maxEntries<1||$maxEntries>4096||$maxBytes<512||$maxBytes>268435456||$maxItemBytes<128||$maxItemBytes>$maxBytes)throw new \InvalidArgumentException('Cachegrenzen prüfen.');
        if($root===''||str_contains($root,"\0")||is_link($root))throw new \RuntimeException('Privater Cachepfad ungültig.');
        if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root))throw new \RuntimeException('Privater Cache nicht verfügbar.');
        $real=realpath($root);if($real===false)throw new \RuntimeException('Privater Cache nicht verfügbar.');
        $this->directory=$real.'/scope-'.hash('sha256',$scope);
        if(is_link($this->directory)||(!is_dir($this->directory)&&!@mkdir($this->directory,0700)&&!is_dir($this->directory)))throw new \RuntimeException('Privater Vereinscache nicht verfügbar.');
        $this->clock=$clock?\Closure::fromCallable($clock):time(...);$this->guard=$guard?\Closure::fromCallable($guard):null;
    }
    public function scope():string{return $this->scope;}
    private static function text(string $text,int $max):void{if($text===''||strlen($text)>$max||!preg_match('//u',$text)||preg_match('/[\x00-\x1f\x7f]/u',$text))throw new \InvalidArgumentException('Cachebezeichnung prüfen.');}
    private static function namespace(string $namespace):void{if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,63}$/D',$namespace))throw new \InvalidArgumentException('Cachebereich prüfen.');}
    private static function dependencies(array $dependencies):array{if(count($dependencies)>24)throw new \InvalidArgumentException('Zu viele Cacheabhängigkeiten.');foreach($dependencies as$key=>$value){if(!is_string($key)||(!is_string($value)&&!is_int($value)))throw new \InvalidArgumentException('Cacheabhängigkeit prüfen.');self::text($key,128);self::text((string)$value,256);$dependencies[$key]=(string)$value;}ksort($dependencies,SORT_STRING);return $dependencies;}
    private static function tags(array $tags):array{if(count($tags)>24)throw new \InvalidArgumentException('Zu viele Cachemarkierungen.');foreach($tags as$tag){if(!is_string($tag))throw new \InvalidArgumentException('Cachemarkierung prüfen.');self::text($tag,128);}return array_values(array_unique($tags));}
    private static function id(string $namespace,string $key):string{self::namespace($namespace);self::text($key,512);return hash('sha256',$namespace."\0".$key);}
    private function lock(callable $operation,bool $write=false):mixed {
        if($this->guard)($this->guard)();$path=$this->directory.'/.lock';if(is_link($path))throw new \RuntimeException('Cachesperre ungültig.');$handle=@fopen($path,'c+b');
        if($handle===false)throw new \RuntimeException('Cache nicht verfügbar.');@chmod($path,0600);
        try{if(!flock($handle,$write?LOCK_EX:LOCK_SH))throw new \RuntimeException('Cachesperre nicht verfügbar.');if($this->guard)($this->guard)();return $operation();}finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    private function epoch():string{$path=$this->directory.'/.epoch';if(is_link($path))throw new \RuntimeException('Cachestand ungültig.');if(!is_file($path))return 'initial';$value=@file_get_contents($path);if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))throw new \RuntimeException('Cachestand nicht verfügbar.');return $value;}
    private function advance():void{$this->atomic('.epoch',bin2hex(random_bytes(16)));}
    private function atomic(string $name,string $bytes):void {
        $destination=$this->directory.'/'.$name;if(is_link($destination))throw new \RuntimeException('Cacheeintrag ungültig.');$temp=tempnam($this->directory,'write-');if($temp===false)throw new \RuntimeException('Cache nicht schreibbar.');
        try{@chmod($temp,0600);if(@file_put_contents($temp,$bytes)!==strlen($bytes)||!@rename($temp,$destination))throw new \RuntimeException('Cache nicht vollständig gespeichert.');}finally{if(is_file($temp))@unlink($temp);}
    }
    private function read(string $id):?array {
        $path=$this->directory.'/'.$id.'.cache.json';clearstatcache(true,$path);if(is_link($path)||!is_file($path))return null;$size=@filesize($path);if($size===false||$size>$this->maxItemBytes)return null;
        try{$row=json_decode((string)@file_get_contents($path),true,48,JSON_THROW_ON_ERROR);if(!is_array($row)||($row['format']??null)!==1||($row['scope']??null)!==$this->scope||!is_string($row['namespace']??null)||!is_string($row['key']??null)||!is_int($row['created']??null)||!is_int($row['expires']??null)||!is_array($row['tags']??null)||!is_array($row['dependencies']??null)||!array_key_exists('value',$row)||self::id($row['namespace'],$row['key'])!==$id)return null;self::tags($row['tags']);self::dependencies($row['dependencies']);$row['id']=$id;$row['bytes']=$size;return $row;}catch(\Throwable){return null;}
    }
    /** Only owned cache files are ever enumerated or removed. */
    private function entries():array{$rows=[];foreach(new \DirectoryIterator($this->directory)as$file){if($file->isLink()||!$file->isFile()||!preg_match('/^([a-f0-9]{64})\.cache\.json$/D',$file->getFilename(),$m))continue;$rows[$m[1]]=$this->read($m[1]);}return $rows;}
    private function remove(string $id):bool{$path=$this->directory.'/'.$id.'.cache.json';return !is_link($path)&&is_file($path)&&@unlink($path);}
    private function snapshot(string $id,array $dependencies):array{return $this->lock(function()use($id,$dependencies){$row=$this->read($id);return['epoch'=>$this->epoch(),'hit'=>$row!==null&&($row['expires']===0||$row['expires']>($this->clock)())&&$row['dependencies']===$dependencies,'value'=>$row['value']??null];});}
    /** A cached null is a hit; use has() when it must be distinguished from a miss. */
    public function get(string $namespace,string $key,array $dependencies=[]):mixed{$r=$this->snapshot(self::id($namespace,$key),self::dependencies($dependencies));return $r['hit']?$r['value']:null;}
    public function has(string $namespace,string $key,array $dependencies=[]):bool{return $this->snapshot(self::id($namespace,$key),self::dependencies($dependencies))['hit'];}
    public function set(string $namespace,string $key,mixed $value,int $ttl=3600,array $tags=[],array $dependencies=[]):bool{return $this->store($namespace,$key,$value,$ttl,$tags,$dependencies,null);}
    private function store(string $namespace,string $key,mixed $value,int $ttl,array $tags,array $dependencies,?string $epoch):bool {
        $id=self::id($namespace,$key);if($ttl<0||$ttl>2592000)throw new \InvalidArgumentException('Cachedauer muss zwischen 0 und 30 Tagen liegen.');$tags=self::tags($tags);$dependencies=self::dependencies($dependencies);self::jsonValue($value);$now=($this->clock)();
        $bytes=json_encode(['format'=>1,'scope'=>$this->scope,'namespace'=>$namespace,'key'=>$key,'created'=>$now,'expires'=>$ttl===0?0:$now+$ttl,'tags'=>$tags,'dependencies'=>$dependencies,'value'=>$value],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
        if(strlen($bytes)>$this->maxItemBytes)return false;
        return $this->lock(function()use($epoch,$id,$bytes){if($epoch!==null&&!hash_equals($epoch,$this->epoch()))return false;$this->clean();$rows=$this->entries();unset($rows[$id]);$total=array_sum(array_map(static fn($r)=>$r['bytes']??0,$rows));uasort($rows,static fn($a,$b)=>($a['created']??0)<=>($b['created']??0));foreach($rows as$old=>$row){if(count($rows)<$this->maxEntries&&$total+strlen($bytes)<=$this->maxBytes)break;if($this->remove($old)){$total-=$row['bytes']??0;unset($rows[$old]);}}if(count($rows)>=$this->maxEntries||$total+strlen($bytes)>$this->maxBytes)return false;$this->atomic($id.'.cache.json',$bytes);$this->advance();return true;},true);
    }
    private static function jsonValue(mixed $value,int $depth=0):void{if($depth>24)throw new \InvalidArgumentException('Cachewert zu tief verschachtelt.');if(is_array($value)){foreach($value as$v)self::jsonValue($v,$depth+1);return;}if($value===null||is_string($value)||is_bool($value)||is_int($value)||(is_float($value)&&is_finite($value)))return;throw new \InvalidArgumentException('Cachewerte müssen JSON-Daten sein.');}
    /** Render outside the lock; invalidation while rendering prevents a stale write. */
    public function remember(string $namespace,string $key,callable $produce,int $ttl=3600,array $tags=[],array $dependencies=[]):mixed{$dependencies=self::dependencies($dependencies);$r=$this->snapshot(self::id($namespace,$key),$dependencies);if($r['hit'])return $r['value'];$value=$produce();$this->store($namespace,$key,$value,$ttl,$tags,$dependencies,$r['epoch']);return $value;}
    private function clean():int{$removed=0;$now=($this->clock)();foreach($this->entries()as$id=>$row){if($row===null||($row['expires']!==0&&$row['expires']<=$now))$removed+=(int)$this->remove($id);}foreach(new \DirectoryIterator($this->directory)as$file){if(!$file->isLink()&&$file->isFile()&&preg_match('/^write-[a-zA-Z0-9]{6}$/D',$file->getFilename())&&$file->getMTime()<$now-3600)$removed+=(int)@unlink($file->getPathname());}return $removed;}
    public function cleanup():int{return $this->lock(function(){$n=$this->clean();if($n)$this->advance();return $n;},true);}
    public function delete(string $namespace,string $key):bool{return $this->deleteId(self::id($namespace,$key));}
    public function deleteId(string $id):bool{if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new \InvalidArgumentException('Cacheeintrag prüfen.');return $this->lock(function()use($id){$this->advance();return $this->remove($id);},true);}
    /** Tag invalidation crosses namespaces within this tenant, never tenant boundaries. */
    public function invalidate(string $tag):int{self::text($tag,128);return $this->lock(function()use($tag){$this->advance();$n=0;foreach($this->entries()as$id=>$row){if($row!==null&&in_array($tag,$row['tags'],true))$n+=(int)$this->remove($id);}return $n;},true);}
    public function clear(?string $namespace=null):int{if($namespace!==null)self::namespace($namespace);return $this->lock(function()use($namespace){$this->advance();$n=0;foreach($this->entries()as$id=>$row){if($namespace===null||($row['namespace']??null)===$namespace)$n+=(int)$this->remove($id);}return $n;},true);}
    /** Metadata only: neither cached values nor filesystem paths leave this API. */
    public function listing(int $page=1,?string $namespace=null):array {
        if($page<1||$page>1000000)throw new \InvalidArgumentException('Cacheseite prüfen.');if($namespace!==null)self::namespace($namespace);
        return $this->lock(function()use($page,$namespace){$all=$this->entries();$rows=[];$names=[];$bytes=0;$expired=0;foreach($all as$id=>$row){if($row===null)continue;$names[$row['namespace']]=true;$bytes+=$row['bytes'];$stale=$row['expires']!==0&&$row['expires']<=($this->clock)();$expired+=(int)$stale;if($namespace===null||$namespace===$row['namespace'])$rows[]=['id'=>$id,'namespace'=>$row['namespace'],'key'=>$row['key'],'created'=>$row['created'],'expires'=>$row['expires'],'bytes'=>$row['bytes'],'tags'=>$row['tags'],'expired'=>$stale];}usort($rows,static fn($a,$b)=>[$b['created'],$a['id']]<=>[$a['created'],$b['id']]);$total=count($rows);$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min($page,$pages);$names=array_keys($names);sort($names,SORT_STRING);return['rows'=>array_slice($rows,($page-1)*self::PAGE_SIZE,self::PAGE_SIZE),'total'=>$total,'page'=>$page,'pages'=>$pages,'namespaces'=>$names,'bytes'=>$bytes,'entries'=>count($all),'expired'=>$expired,'limits'=>['entries'=>$this->maxEntries,'bytes'=>$this->maxBytes,'item_bytes'=>$this->maxItemBytes]];});
    }
}
