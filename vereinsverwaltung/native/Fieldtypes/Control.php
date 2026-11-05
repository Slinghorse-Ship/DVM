<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;

/** Reviewed inputfield callbacks remain separate from their field's stored native type. */
final class Control
{
    public function __construct(public readonly string $label,public readonly array $storageTypes,public readonly string $version,private readonly \Closure $renderer,private readonly ?\Closure $parser=null)
    {
        if(trim($label)===''||mb_strlen($label)>160||!array_is_list($storageTypes)||!$storageTypes||count(array_filter($storageTypes,'is_string'))!==count($storageTypes)||array_diff($storageTypes,Definition::STORAGE_TYPES)||count(array_unique($storageTypes))!==count($storageTypes))throw new \InvalidArgumentException('Eingabefeld und native Typgrundlagen prüfen.');
        Binding::version($version);Source::hash($renderer);if($parser)Source::hash($parser);
    }
    public function fingerprint():string
    {return hash('sha256',json_encode([$this->label,$this->storageTypes,$this->version],JSON_THROW_ON_ERROR).Source::hash($this->renderer).($this->parser?Source::hash($this->parser):''));}
    public function sources():array
    {return array_values(array_unique([(new \ReflectionFunction($this->renderer))->getFileName(),...($this->parser?[(new \ReflectionFunction($this->parser))->getFileName()]:[])]));}
    public function render(array $field,string $name,mixed $value,bool $readonly=false):string
    {
        $this->assertField($field);self::inputName($name);$result=Invocation::call($this->renderer,$field,$name,$value,$readonly);
        if(!is_string($result))throw new \DomainException('Eingabefeld liefert keine gültige Ansicht.');
        return Markup::validate($result,$name,$readonly);
    }
    public function parse(array $field,mixed $value):mixed
    {$this->assertField($field);return $this->parser?Invocation::call($this->parser,$value,$field):$value;}
    private function assertField(array $field):void
    {if(!in_array($field['type']??null,$this->storageTypes,true)||!empty($field['multiple']))throw new \DomainException('Eingabefeld passt nicht zur nativen Typgrundlage.');}
    public static function inputName(string $name):void
    {if(strlen($name)>512||!preg_match('/\A[a-z][a-z0-9_]*(?:\[[a-zA-Z0-9_-]+\])*\z/D',$name))throw new \InvalidArgumentException('Gebundenen Eingabenamen prüfen.');}
}
