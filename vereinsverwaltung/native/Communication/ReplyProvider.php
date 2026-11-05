<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use Kulturhus\Native\Shared\Access;

/** Provider contracts sit outside the durable private reply workflow. */
interface ReplyProvider
{
    public function account(Access $access, array $source, ?string $binding = null): array;
    public function current(Access $access, array $account, array $source, array $row): void;
    public function publish(Access $access, array $account, array $source, array $row): string;
    public function proof(Access $access, array $account, array $row, string $remote): bool;
}
