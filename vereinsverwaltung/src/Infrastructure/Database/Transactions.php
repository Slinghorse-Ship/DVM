<?php declare(strict_types=1);
namespace Kulturhus\Verein\Infrastructure\Database;

use PDO;

/** Composed services commit together; an inner failure rolls back its own writes. */
final class Transactions
{
    public static function run(PDO $db, callable $operation): mixed
    {
        if ($db->inTransaction()) {
            $savepoint = "kulturhus_" . bin2hex(random_bytes(8));
            $db->exec("SAVEPOINT " . $savepoint);
            try {
                $result = $operation();
                $db->exec("RELEASE SAVEPOINT " . $savepoint);
                return $result;
            } catch (\Throwable $error) {
                if ($db->inTransaction()) {
                    $db->exec("ROLLBACK TO SAVEPOINT " . $savepoint);
                    $db->exec("RELEASE SAVEPOINT " . $savepoint);
                }
                throw $error;
            }
        }
        $db->beginTransaction();
        try {
            $result = $operation();
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
