<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
use PDO;
use Kulturhus\Native\Shared\Access;
/** Manager authority is checked live. Stored native grants are never authority here. */
final class ManagerAccess extends Access
{
    public function __construct(PDO $db, private ManagerBridge $bridge)
    {
        parent::__construct($db, $bridge->nativeActorId());
    }
    public function user(): array
    {
        return $this->bridge->user();
    }
    public function managerActor(): string
    {
        return $this->bridge->managerActor();
    }
    public function require(string $module, string $capability): array
    {
        return $this->bridge->requireCapability($module, $capability);
    }
    public function directory(string $module): array
    {
        return $this->bridge->directory($module);
    }
}
