<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

final class Definition
{
    public const API_VERSION = 1;
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $version,
        public readonly array $dependencies = [],
        public readonly array $fields = [],
        public readonly bool $autoload = true,
        public readonly int $api = self::API_VERSION,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$id) || mb_strlen($label)>100 || trim($label)==='' || !self::version($version) || $api!==self::API_VERSION || count($fields)>30) throw new \DomainException('Erweiterungsbeschreibung ungültig.');
        foreach ($dependencies as $dependency=>$minimum) if (!is_string($dependency) || !preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$dependency) || !is_string($minimum) || !self::version($minimum) || $id===$dependency) throw new \DomainException('Erweiterungsabhängigkeit ungültig.');
        foreach ($fields as $key=>$field) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/D',$key) || !is_array($field) || !is_string($field['label']??null) || mb_strlen($field['label'])>100 || !in_array($field['type']??null,['text','boolean','integer','select'],true) || !array_key_exists('default',$field)) throw new \DomainException('Konfigurationsfeld ungültig.');
            if ($field['type']==='text' && (!is_int($field['max']??null) || $field['max']<1 || $field['max']>2000)) throw new \DomainException('Textbegrenzung fehlt.');
            if ($field['type']==='integer' && (!is_int($field['min']??null) || !is_int($field['max']??null) || $field['min']>$field['max'])) throw new \DomainException('Zahlbegrenzung fehlt.');
            if ($field['type']==='select' && (!is_array($field['options']??null) || !$field['options'] || count($field['options'])>20)) throw new \DomainException('Auswahl fehlt.');
            if($field['type']==='select')foreach($field['options'] as $option=>$label)if(strlen((string)$option)>100||!is_string($label)||mb_strlen($label)>100)throw new \DomainException('Auswahlwert ungültig.');
        }
        $this->configuration([]);
    }
    private static function version(string $version): bool { return preg_match('/^(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})$/D',$version)===1; }
    public function configuration(array $values): array
    {
        if (array_diff(array_keys($values),array_keys($this->fields))) throw new \DomainException('Unbekanntes Konfigurationsfeld.');
        $result=[];
        foreach ($this->fields as $key=>$field) {
            $value=array_key_exists($key,$values)?$values[$key]:$field['default'];
            $valid=match($field['type']) {
                'text'=>is_string($value)&&mb_strlen($value)<=$field['max']&&!str_contains($value,"\0"),
                'boolean'=>is_bool($value),
                'integer'=>is_int($value)&&$value>=$field['min']&&$value<=$field['max'],
                'select'=>is_string($value)&&array_key_exists($value,$field['options']),
            };
            if (!$valid) throw new \DomainException('Konfiguration prüfen: '.$field['label']);
            if(($field['format']??null)==='local-path'&&(!is_string($value)||!Values::localPath($value)))throw new \DomainException('Lokale Zieladresse prüfen.');
            $result[$key]=$value;
        }
        if (strlen(json_encode($result,JSON_THROW_ON_ERROR))>16384) throw new \DomainException('Konfiguration zu groß.');
        return $result;
    }
}
