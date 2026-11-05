<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;

/** Request- and club-scoped; cached HTML never bypasses a live module/source guard. */
final class Registry
{
    private array $entries=[];
    public function register(string $owner,string $name,string $hostSource,\Closure $guard,Definition $definition,array $sources,string $state):void
    {
        Binding::name($owner);Binding::name($name);if(!preg_match('/\A[a-f0-9]{64}\z/D',$hostSource)||$guard()!==true)throw new \DomainException('Rendererfreigabe nicht verfügbar.');
        foreach($definition->sources()as$file)if(!in_array(realpath($file),$sources,true))throw new \DomainException('Rendererquellen zuerst vollständig beim Host zur Prüfung anmelden.');
        $key=$owner.':'.$name;if(isset($this->entries[$key])||count($this->entries)>=100)throw new \DomainException('Renderer bereits vorhanden oder Registrierung voll.');
        foreach($definition->styles as$file)self::css($file);
        $this->entries[$key]=['definition'=>$definition,'host'=>$hostSource,'guard'=>$guard,'fingerprint'=>$definition->fingerprint(),'state'=>$state];
    }
    public function remove(string $owner):void{foreach(array_keys($this->entries)as$key)if(str_starts_with($key,$owner.':'))unset($this->entries[$key]);}
    private function bindingFor(string $key,array $entry):array
    {[$owner,$name]=explode(':',$key,2);return ['owner'=>$owner,'name'=>$name,'version'=>$entry['definition']->version,'source_hash'=>hash('sha256',$entry['host'].'|'.$entry['fingerprint'])];}
    public function binding(string $owner,string $name):array
    {$key=Binding::name($owner).':'.Binding::name($name);$entry=$this->entries[$key]??throw new \DomainException('Renderer nicht verfügbar.');$binding=$this->bindingFor($key,$entry);$this->resolve($binding);return $binding;}
    private function resolve(array $binding):array
    {
        $binding=Binding::normalize($binding);$entry=$this->entries[Binding::key($binding)]??throw new \DomainException('Gespeicherte Frontenddarstellung nicht verfügbar.');
        if(($entry['guard'])()!==true||$entry['fingerprint']!==$entry['definition']->fingerprint()||$binding!==$this->bindingFor(Binding::key($binding),$entry))throw new \DomainException('Frontenddarstellung muss erneut geprüft werden.');
        return $entry;
    }
    public function available(array $binding):bool{try{$this->resolve($binding);return true;}catch(\Throwable){return false;}}
    public function choices():array
    {$out=[];foreach($this->entries as$key=>$entry){$binding=$this->bindingFor($key,$entry);if($this->available($binding))$out[]=['label'=>$entry['definition']->label,'binding'=>$binding];}return $out;}
    public function stamp(array $binding):string{$entry=$this->resolve($binding);return hash('sha256',Binding::token($binding).'|'.$entry['state']);}
    public function listing(array $binding):string{return $this->resolve($binding)['definition']->listing;}
    public function render(array $binding,RenderContext $context):string
    {
        $entry=$this->resolve($binding);$d=$entry['definition'];$level=ob_get_level();ob_start();
        try{
            if($d->render)$html=($d->render)($context);
            else{$html='<article class="template-renderer template-renderer--'.$d->layout.'">';foreach($d->components as$name){$fragment=$context->component($name);if($fragment!=='')$html.='<div class="template-renderer__'.$name.'">'.$fragment.'</div>';}$html.='</article>';}
            if(ob_get_contents()!==''||!is_string($html)||strlen($html)>2097152||!preg_match('//u',$html)||preg_match('~<\s*(?:html|head|body|base|meta|link|script|style|iframe|object|embed|main)\b~i',$html))throw new \DomainException('Frontenddarstellung lieferte keinen gültigen Inhaltsbereich.');
            $this->resolve($binding);return $html;
        }catch(\Throwable){throw new \DomainException('Frontenddarstellung derzeit nicht verfügbar.');}finally{while(ob_get_level()>$level)ob_end_clean();}
    }
    private static function css(string $file):string
    {$css=file_get_contents($file);if(!is_string($css)||strlen($css)>65536||!preg_match('//u',$css)||preg_match('~@import|url\s*\(|expression\s*\(|javascript|</~i',$css))throw new \DomainException('Renderer-Stylesheet muss lokal und ohne externe Ressourcen bleiben.');return $css;}
    public function styles(array $binding):array
    {$entry=$this->resolve($binding);$urls=[];foreach($entry['definition']->styles as$name=>$file)$urls[]='/extensions/'.$binding['owner'].'/renderers/'.$binding['name'].'/'.$binding['source_hash'].'/'.$name;return $urls;}
    public function asset(string $method,string $path):?array
    {
        if(!preg_match('~\A/extensions/([a-z][a-z0-9-]{0,63})/renderers/([a-z][a-z0-9-]{0,63})/([a-f0-9]{64})/([a-z][a-z0-9-]{0,63})\z~D',$path,$m))return null;
        if(!in_array($method,['GET','HEAD'],true))return ['status'=>405,'headers'=>['Allow'=>'GET, HEAD','Cache-Control'=>'no-store'],'body'=>''];
        try{$binding=$this->binding($m[1],$m[2]);if(!hash_equals($binding['source_hash'],$m[3]))throw new \DomainException();$entry=$this->resolve($binding);$file=$entry['definition']->styles[$m[4]]??throw new \DomainException();$css=self::css($file);$this->resolve($binding);return ['status'=>200,'headers'=>['Content-Type'=>'text/css; charset=utf-8','Cache-Control'=>'no-cache'],'body'=>$method==='HEAD'?'':$css];}catch(\Throwable){return ['status'=>404,'headers'=>['Cache-Control'=>'no-store'],'body'=>''];}
    }
}
