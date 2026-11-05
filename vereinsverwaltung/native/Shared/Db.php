<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;
use PDO;
use Kulturhus\Verein\Infrastructure\Database\Transactions;
use Kulturhus\Verein\Infrastructure\Database\PreparedQueries;
require_once dirname(__DIR__, 2) .
    "/src/Infrastructure/Database/Transactions.php";
require_once dirname(__DIR__, 2) . "/src/Infrastructure/Database/PreparedQueries.php";
final class Db
{
    public static function run(
        PDO $db,
        string $sql,
        array $parameters = [],
    ): \PDOStatement {
        return PreparedQueries::run($db, $sql, $parameters);
    }
    public static function atomic(PDO $db, callable $operation): mixed
    {
        return Transactions::run($db, $operation);
    }
}
