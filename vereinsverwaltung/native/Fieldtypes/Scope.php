<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** A reviewed plugin may register only under its own identity and host-owned fresh guard. */
final class Scope
{
    public function __construct(private readonly Registry $registry,private readonly string $owner,private readonly string $hostSource,private readonly \Closure $guard,private readonly ?array $sources=null){}
    public function register(string $name,Definition $definition):void
    {$this->registry->register($this->owner,$name,$this->hostSource,$this->guard,$definition,$this->sources);}
    public function control(string $name,Control $control):void
    {$this->registry->register($this->owner,$name,$this->hostSource,$this->guard,$control,$this->sources);}
}
