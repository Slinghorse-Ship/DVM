<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** A host-owned contract. Extensions cannot register or weaken its access guard. */
final class Contract
{
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly \Closure $guard,
        public readonly \Closure $arguments,
        public readonly \Closure $result,
        public readonly bool $replaceable = false,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $name) || !in_array($kind, ['method', 'property', 'url'], true)) {
            throw new \DomainException('Ungültiger Erweiterungsvertrag.');
        }
    }

    public function validateArguments(array $arguments): void
    {
        if (!Values::plain($arguments) || ($this->arguments)($arguments) !== true) throw new \DomainException('Erweiterungsargumente entsprechen nicht dem Vertrag.');
    }

    public function validateResult(mixed $result): void
    {
        if (!Values::plain($result) || ($this->result)($result) !== true) throw new \DomainException('Erweiterungsergebnis entspricht nicht dem Vertrag.');
    }

    public function authorize(): void
    {
        if (($this->guard)() !== true) throw new \DomainException('Keine Berechtigung für diesen Erweiterungsvertrag.');
    }
}
