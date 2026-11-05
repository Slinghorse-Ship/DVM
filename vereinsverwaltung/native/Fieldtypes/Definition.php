<?php declare(strict_types=1);
namespace Kulturhus\Native\Fieldtypes;
use Kulturhus\Native\Cms\CustomFields;

/** A native storage contract encloses reviewed custom validation and normalization. */
final class Definition
{
    public const STORAGE_TYPES=['text','textarea','richtext','integer','decimal','boolean','date','datetime','url','email','select','page-reference','media-reference','file-reference'];
    public function __construct(public readonly string $label,public readonly string $storageType,public readonly string $version,private readonly \Closure $normalizer)
    {
        if(trim($label)===''||mb_strlen($label)>160||!in_array($storageType,self::STORAGE_TYPES,true))throw new \InvalidArgumentException('Bezeichnung und native Typgrundlage prüfen.');
        Binding::version($version);Source::hash($normalizer);
    }
    public function fingerprint():string
    {return hash('sha256',json_encode([$this->label,$this->storageType,$this->version],JSON_THROW_ON_ERROR).Source::hash($this->normalizer));}
    public function sources():array{return [(new \ReflectionFunction($this->normalizer))->getFileName()];}
    public function normalize(array $field,mixed $value,bool $forSearch=false):mixed
    {
        if(($field['type']??null)!==$this->storageType||!empty($field['multiple']))throw new \DomainException('Typbindung und native Einzelwertgrundlage stimmen nicht überein.');
        $validate=static function(mixed$entry)use($field,$forSearch):mixed{
            if($forSearch&&in_array($field['type'],['text','textarea','richtext','url','email','select'],true))return $entry===null||$entry===''?null:CustomFields::text($entry,$field['type']==='select'?200:100000);
            return CustomFields::value($field,$entry,$forSearch);
        };
        $input=$validate($value);$result=Invocation::call($this->normalizer,$input,$field,$forSearch);
        return $validate($result);
    }
}
