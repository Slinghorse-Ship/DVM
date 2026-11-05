<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** One subscriber's detached value snapshot; no PDO, session or identity setters. */
final class Event
{
    private mixed $value;
    public function __construct(public readonly string $contract, public readonly string $phase, private array $values, mixed $result = null)
    {
        $this->values = Values::copy($values);
        $this->value = Values::copy($result);
    }
    public function arguments(): array { return $this->values; }
    public function setArguments(array $arguments): void
    {
        if ($this->phase !== 'before') throw new \DomainException('Argumente können nur vorher geändert werden.');
        $this->values = Values::copy($arguments);
    }
    public function result(): mixed { return $this->value; }
    public function setResult(mixed $value): void
    {
        if ($this->phase !== 'after') throw new \DomainException('Das Ergebnis kann nur nachher geändert werden.');
        $this->value = Values::copy($value);
    }
}
