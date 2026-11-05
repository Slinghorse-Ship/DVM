<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** Plugin receives registration only, not contract/guard ownership. */
final class HookScope
{
    public function __construct(private readonly Hooks $hooks,private readonly string $owner,private readonly \Closure $guard) {}
    public function on(string $name,string $phase,\Closure $callback,int $priority=0): void
    {
        $guard=$this->guard;
        $this->hooks->on($this->owner,$name,$phase,static function(Event $event,?\Closure $next=null)use($callback,$guard):mixed{
            if($guard()!==true)throw new \DomainException('Erweiterungsfreigabe entzogen.');
            $value=$next?$callback($event,$next):$callback($event);
            if($guard()!==true)throw new \DomainException('Erweiterungsfreigabe entzogen.');
            return $value;
        },$priority);
    }
}
