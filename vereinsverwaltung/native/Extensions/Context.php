<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Shared\Db;

/** Namespaced JSON state; initialization cannot write or perform DDL. */
final class Context
{
    public function __construct(private readonly \PDO $db, public readonly string $club, public readonly string $plugin, public readonly array $configuration, private readonly \Closure $guard, private readonly bool $writable=false,private readonly ?\Kulturhus\Native\TemplateRenderers\Scope $rendererScope=null,private readonly ?\Kulturhus\Native\Fieldtypes\Scope $fieldtypeScope=null) {}
    public function renderers():\Kulturhus\Native\TemplateRenderers\Scope
    {if(($this->guard)()!==true||$this->rendererScope===null)throw new \DomainException('Rendererregistrierung ist hier nicht verfügbar.');return $this->rendererScope;}
    public function fieldtypes():\Kulturhus\Native\Fieldtypes\Scope
    {if(($this->guard)()!==true||$this->fieldtypeScope===null)throw new \DomainException('Feldtypregistrierung ist hier nicht verfügbar.');return $this->fieldtypeScope;}
    private function check(string $name): void
    {
        if(($this->guard)()!==true)throw new \DomainException('Erweiterungszugriff entzogen.');
        if(!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$name))throw new \DomainException('Ungültiger Erweiterungsschlüssel.');
    }
    public function get(string $name, mixed $default=null): mixed
    {
        $this->check($name);$value=Db::run($this->db,'SELECT value_json FROM native_extension_values WHERE club_id=? AND plugin_id=? AND name=?',[$this->club,$this->plugin,$name])->fetchColumn();
        return $value===false?$default:json_decode($value,true,16,JSON_THROW_ON_ERROR);
    }
    public function put(string $name,mixed $value): void
    {
        $this->check($name);
        if(!$this->writable||!$this->db->inTransaction()||!Values::plain($value))throw new \DomainException('Erweiterungswerte nur im Lebenszyklus ändern.');
        $encoded=json_encode($value,JSON_THROW_ON_ERROR);if(strlen($encoded)>16384)throw new \DomainException('Erweiterungswert zu groß.');
        Db::run($this->db,'INSERT INTO native_extension_values VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)',[$this->club,$this->plugin,$name,$encoded]);
    }
    public function delete(string $name): void
    {
        $this->check($name);if(!$this->writable||!$this->db->inTransaction())throw new \DomainException('Erweiterungswerte nur im Lebenszyklus ändern.');
        Db::run($this->db,'DELETE FROM native_extension_values WHERE club_id=? AND plugin_id=? AND name=?',[$this->club,$this->plugin,$name]);
    }
}
