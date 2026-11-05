<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** Request-local and club-local registrations; each use rechecks the exact live host approval. */
final class Registry
{
    private array $definitions=[];
    private array $controls=[];
    public function register(string $owner,string $name,string $hostSource,\Closure $guard,Definition|Control $definition,?array $sources=null):void
    {
        Binding::name($owner);Binding::name($name);if(!preg_match('/\A[a-f0-9]{64}\z/D',$hostSource))throw new \InvalidArgumentException('Geprüfte lokale Erweiterungsquelle fehlt.');
        if($guard()!==true)throw new \DomainException('Lokale Typfreigabe entzogen.');
        if($sources!==null)foreach($definition->sources()as$file)if(!in_array(realpath($file),$sources,true))throw new \DomainException('Alle Typfunktionen zuerst als lokale Erweiterungsquellen prüfen.');
        $kind=$definition instanceof Control?'controls':'definitions';$key=$owner.':'.$name;if(isset($this->{$kind}[$key])||count($this->{$kind})>=100)throw new \DomainException('Lokaler Typ bereits registriert oder Registrierung voll.');
        $this->{$kind}[$key]=['value'=>$definition,'host'=>$hostSource,'guard'=>$guard,'fingerprint'=>$definition->fingerprint()];
    }
    public function remove(string $owner):void
    {Binding::name($owner);foreach(['definitions','controls']as$kind)foreach(array_keys($this->{$kind})as$key)if(str_starts_with($key,$owner.':'))unset($this->{$kind}[$key]);}
    public function choices(string $kind='fieldtype'):array
    {
        $items=[];$entries=$this->entries($kind);
        foreach($entries as$key=>$entry)try{$binding=$this->bindingFor($key,$entry);$this->resolve($binding,$kind);$items[]=['label'=>$entry['value']->label,'binding'=>$binding,...($kind==='fieldtype'?['type'=>$entry['value']->storageType]:['types'=>$entry['value']->storageTypes])];}catch(\DomainException|\InvalidArgumentException){}
        return $items;
    }
    public function binding(string $owner,string $name,string $kind='fieldtype'):array
    {
        $key=Binding::name($owner).':'.Binding::name($name);$entry=$this->entries($kind)[$key]??throw new \DomainException('Lokaler Typ nicht verfügbar.');$binding=$this->bindingFor($key,$entry);$this->resolve($binding,$kind);return $binding;
    }
    public function available(array $binding,string $kind='fieldtype'):bool
    {try{$this->resolve($binding,$kind);return true;}catch(\DomainException|\InvalidArgumentException){return false;}}
    public function descriptor(array $binding,string $kind='fieldtype'):array
    {$entry=$this->resolve($binding,$kind);return ['label'=>$entry['value']->label,'binding'=>Binding::normalize($binding),...($kind==='fieldtype'?['type'=>$entry['value']->storageType]:['types'=>$entry['value']->storageTypes])];}
    public function normalize(array $binding,array $field,mixed $value,bool $forSearch=false):mixed
    {
        $entry=$this->resolve($binding,'fieldtype');$result=$entry['value']->normalize($field,$value,$forSearch);$this->resolve($binding,'fieldtype');return $result;
    }
    public function render(array $binding,array $field,string $name,mixed $value,bool $readonly=false):string
    {
        $entry=$this->resolve($binding,'inputfield');$result=$entry['value']->render($field,$name,$value,$readonly);$this->resolve($binding,'inputfield');return $result;
    }
    public function parse(array $binding,array $field,mixed $value):mixed
    {
        $entry=$this->resolve($binding,'inputfield');$result=$entry['value']->parse($field,$value);$this->resolve($binding,'inputfield');return $result;
    }
    private function entries(string $kind):array
    {return match($kind){'fieldtype'=>$this->definitions,'inputfield'=>$this->controls,default=>throw new \InvalidArgumentException('Unbekannte lokale Typart.')};}
    private function bindingFor(string $key,array $entry):array
    {[$owner,$name]=explode(':',$key,2);return ['owner'=>$owner,'name'=>$name,'version'=>$entry['value']->version,'source_hash'=>hash('sha256',$entry['host'].'|'.$entry['fingerprint'])];}
    private function resolve(array $binding,string $kind):array
    {
        $binding=Binding::normalize($binding);$key=$binding['owner'].':'.$binding['name'];$entry=$this->entries($kind)[$key]??throw new \DomainException('Gespeicherter lokaler Typ ist nicht verfügbar.');
        if(($entry['guard'])()!==true||$entry['value']->fingerprint()!==$entry['fingerprint']||$this->bindingFor($key,$entry)!==$binding)throw new \DomainException('Gespeicherte Typquelle oder aktuelle Freigabe stimmt nicht überein.');return $entry;
    }
}
