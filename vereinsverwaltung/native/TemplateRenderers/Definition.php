<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;

/** Finite declarative components or a reviewed local PHP closure; never uploaded code. */
final class Definition
{
    public const COMPONENTS=['title','excerpt','body','fields','media','documents','sections','listing'];
    public function __construct(
        public readonly string $label,
        public readonly string $version,
        public readonly array $components=['title','excerpt','body','fields','media','documents','sections','listing'],
        public readonly string $layout='stack',
        public readonly ?\Closure $render=null,
        public readonly array $styles=[],
        public readonly string $listing='auto',
    ) {
        Binding::normalize(['owner'=>'reviewed','name'=>'renderer','version'=>$version,'source_hash'=>str_repeat('a',64)]);
        if(trim($label)===''||mb_strlen($label)>100||!preg_match('//u',$label)||!array_is_list($components)||count($components)>8||count(array_unique($components))!==count($components)||array_diff($components,self::COMPONENTS)||!in_array($layout,['stack','cards','split'],true)||!in_array($listing,['none','auto','news','events'],true)||count($styles)>4)throw new \InvalidArgumentException('Deklarative Frontenddarstellung prüfen.');
        foreach($styles as$name=>$file)if(!is_string($name)||Binding::name($name)!==$name||!is_string($file))throw new \InvalidArgumentException('Feste Renderer-Stylesheets erforderlich.');
        $this->fingerprint();
    }
    public function sources():array
    {
        $files=array_values($this->styles);
        if($this->render){$r=new \ReflectionFunction($this->render);$file=$r->getFileName();if(!$file||$r->getClosureThis()!==null)throw new \InvalidArgumentException('Statische lokale Rendererfunktion erforderlich.');$files[]=$file;}
        return array_values(array_unique($files));
    }
    public function fingerprint():string
    {
        $files=[];foreach($this->sources()as$file){if(!is_file($file)||is_link($file)||filesize($file)>2097152)throw new \InvalidArgumentException('Geprüfte Rendererdatei nicht verfügbar.');$files[$file]=hash_file('sha256',$file);}ksort($files);
        $callback=null;
        if($this->render){$r=new \ReflectionFunction($this->render);$captures=$r->getStaticVariables();if(!\Kulturhus\Native\Extensions\Values::plain($captures)||strlen(json_encode($captures,JSON_THROW_ON_ERROR))>16384)throw new \InvalidArgumentException('Rendererkonfiguration auf einfache Werte begrenzen.');$callback=[$r->getStartLine(),$r->getEndLine(),$captures];}
        return hash('sha256',json_encode([$this->label,$this->version,$this->components,$this->layout,$this->listing,$files,$callback],JSON_THROW_ON_ERROR));
    }
}
