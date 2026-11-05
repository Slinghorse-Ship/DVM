<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;

/** Synchronous, request-local hooks; trusted PHP is deliberately not a sandbox. */
final class Hooks
{
    private array $contracts = [];
    private array $listeners = [];
    private array $active = [];
    private array $failures = [];
    private int $sequence = 0;

    public function define(Contract $contract): void
    {
        if (isset($this->contracts[$contract->name])) throw new \DomainException('Erweiterungsvertrag bereits definiert.');
        $this->contracts[$contract->name] = $contract;
    }

    public function on(string $owner, string $name, string $phase, \Closure $callback, int $priority = 0): void
    {
        $contract = $this->contract($name);
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $owner) || !in_array($phase, ['before', 'after', 'replace'], true) || abs($priority) > 1000 || ($phase === 'replace' && !$contract->replaceable)) {
            throw new \DomainException('Ungültige Hook-Registrierung.');
        }
        $this->listeners[$name][$phase][] = ['owner'=>$owner, 'callback'=>$callback, 'priority'=>$priority, 'sequence'=>$this->sequence++];
    }

    public function remove(string $owner): void
    {
        foreach ($this->listeners as &$phases) foreach ($phases as &$rows) $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['owner'] !== $owner));
    }

    private function contract(string $name): Contract
    {
        return $this->contracts[$name] ?? throw new \DomainException('Unbekannter Erweiterungsvertrag.');
    }

    private function subscribers(string $name, string $phase): array
    {
        $rows = $this->listeners[$name][$phase] ?? [];
        usort($rows, static fn(array $a,array $b): int => [$a['priority'],$a['sequence']] <=> [$b['priority'],$b['sequence']]);
        return $rows;
    }

    private function failed(string $owner, string $name, string $phase): void
    {
        // Bounded identifiers only: exception messages may contain customer data.
        if (count($this->failures) < 50) $this->failures[] = ['owner'=>$owner, 'contract'=>$name, 'phase'=>$phase, 'code'=>'hook_failed'];
    }

    public function failures(): array { return $this->failures; }

    public function dispatch(string $name, array $arguments, \Closure $original): mixed
    {
        $contract = $this->contract($name);
        $contract->authorize();
        $contract->validateArguments($arguments);
        $arguments = Values::copy($arguments);
        if (isset($this->active[$name]) || count($this->active) >= 16) throw new \DomainException('Rekursiver Erweiterungsaufruf.');
        $this->active[$name] = true;
        try {
            foreach ($this->subscribers($name, 'before') as $listener) {
                $contract->authorize();
                $event = new Event($name, 'before', $arguments);
                try {
                    ($listener['callback'])($event);
                    $contract->validateArguments($event->arguments());
                    $arguments = $event->arguments();
                } catch (\Throwable) { $this->failed($listener['owner'], $name, 'before'); }
                $contract->authorize();
            }
            $originalCalled = false;
            $originalResult = null;
            $originalFailure = null;
            $core = function() use ($contract,$arguments,$original,&$originalCalled,&$originalResult,&$originalFailure): mixed {
                if ($originalCalled) {
                    if ($originalFailure) throw $originalFailure;
                    return $originalResult;
                }
                $contract->authorize();
                $originalCalled = true;
                try {
                    $originalResult = $original($arguments);
                    $contract->authorize();
                    $contract->validateResult($originalResult);
                    return $originalResult;
                } catch (\Throwable $error) { $originalFailure = $error; throw $error; }
            };
            $invoke = $core;
            foreach (array_reverse($this->subscribers($name, 'replace')) as $listener) {
                $next = $invoke;
                $invoke = function() use ($contract,$name,$arguments,$listener,$next,&$originalFailure): mixed {
                    $contract->authorize();
                    $event = new Event($name, 'replace', $arguments);
                    $called = false; $nextResult = null; $nextFailure = null;
                    $once = function() use ($next,&$called,&$nextResult,&$nextFailure): mixed {
                        if (!$called) { $called = true; try { $nextResult = $next(); } catch (\Throwable $error) { $nextFailure = $error; } }
                        if ($nextFailure) throw $nextFailure;
                        return $nextResult;
                    };
                    try {
                        $result = ($listener['callback'])($event, $once);
                        $contract->validateResult($result);
                    } catch (\Throwable $error) {
                        $contract->authorize();
                        if ($originalFailure) throw $originalFailure;
                        if ($nextFailure) throw $nextFailure;
                        $this->failed($listener['owner'], $name, 'replace');
                        $result = $once();
                    }
                    $contract->authorize();
                    return $result;
                };
            }
            $result = $invoke();
            if ($originalFailure) throw $originalFailure;
            foreach ($this->subscribers($name, 'after') as $listener) {
                $contract->authorize();
                $event = new Event($name, 'after', $arguments, $result);
                try {
                    ($listener['callback'])($event);
                    $contract->validateResult($event->result());
                    $result = $event->result();
                } catch (\Throwable) { $this->failed($listener['owner'], $name, 'after'); }
                $contract->authorize();
            }
            $contract->authorize();
            return $result;
        } finally { unset($this->active[$name]); }
    }
}
