<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;

use PDO;
use Kulturhus\Native\Shared\Access;

/** Internal approved-message reader. The caller validates exact approved refs before any file read. */
final class PressMediaAccess extends Access
{
    public function __construct(PDO $db,private Access $source)
    {
        parent::__construct($db,$source->user()['id']);
    }
    public function user(): array { return $this->source->require('press','publish'); }
    public function require(string $module,string $capability): array
    {
        if($module!=='website'||$capability!=='read')throw new \DomainException('Nur die freigegebenen Pressebildquellen lesen.');
        return $this->source->require('press','publish');
    }
}
