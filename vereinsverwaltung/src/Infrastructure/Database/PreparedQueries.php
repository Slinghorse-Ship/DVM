<?php declare(strict_types=1);
namespace Kulturhus\Verein\Infrastructure\Database;

use PDO;
use PDOException;
use PDOStatement;

/** MySQL 1615 occurs before execution; never retry other statement outcomes. */
final class PreparedQueries
{
    public static function run(PDO $db, string $sql, array $parameters = []): PDOStatement
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $statement = $db->prepare($sql);
                $statement->execute($parameters);
                return $statement;
            } catch (PDOException $error) {
                if ($attempt >= 2 || ($error->errorInfo[0] ?? null) !== 'HY000' || (int)($error->errorInfo[1] ?? 0) !== 1615) {
                    throw $error;
                }
                foreach ($parameters as $value) {
                    if ($value !== null && !is_scalar($value)) throw $error;
                }
                // Recreate only the statement; transaction and callback ownership stay unchanged.
            }
        }
    }
}
