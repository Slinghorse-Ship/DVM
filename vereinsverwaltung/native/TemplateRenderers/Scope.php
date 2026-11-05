<?php declare(strict_types=1);
namespace Kulturhus\Native\TemplateRenderers;
final class Scope
{
    public function __construct(private readonly Registry $registry,private readonly string $owner,private readonly string $hostSource,private readonly \Closure $guard,private readonly array $sources,private readonly string $state){}
    public function register(string $name,Definition $definition):void{$this->registry->register($this->owner,$name,$this->hostSource,$this->guard,$definition,$this->sources,$this->state);}
}
