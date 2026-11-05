<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tables;

/** A fixed input message keeps original column names separate from interface text. */
final class InputError extends \DomainException
{
    public function __construct(public readonly string $source,public readonly array $parameters=[],?\Throwable $previous=null)
    {parent::__construct(strtr($source,$parameters),0,$previous);}
    public function translated(\Closure $text):string
    {
        $parameters=$this->parameters;$cause=$this->getPrevious();
        if($cause!==null)$parameters['{detail}']=$cause instanceof self?$cause->translated($text):$text($cause->getMessage());
        return strtr($text($this->source),$parameters);
    }
}
