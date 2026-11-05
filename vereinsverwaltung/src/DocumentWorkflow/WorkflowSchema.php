<?php
declare(strict_types=1);

namespace Kulturhus\Verein\DocumentWorkflow;

require_once __DIR__ . '/WorkflowException.php';

final class WorkflowSchema
{
    /** Explicit installation only: MySQL DDL implicitly commits, so a transaction is forbidden. */
    public static function install(\PDO $pdo): void
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new WorkflowException('Das Dokumentenmodul benötigt MySQL.');
        }
        if ($pdo->inTransaction()) {
            throw new WorkflowException('Das Schema darf nicht innerhalb einer Transaktion installiert werden.');
        }
        $sql = file_get_contents(dirname(__DIR__, 2) . '/resources/document-workflows/schema.sql');
        if ($sql === false) {
            throw new WorkflowException('Das lokale Workflow-Schema fehlt.');
        }
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        $inspections = file_get_contents(dirname(__DIR__, 2) . '/resources/document-workflows/inspections.sql');
        if ($inspections === false) throw new WorkflowException('Das lokale Gefährdungsprüfungsschema fehlt.');
        foreach (explode(';', $inspections) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
        $creations=file_get_contents(dirname(__DIR__,2).'/resources/document-workflows/creation-requests.sql');
        if($creations===false)throw new WorkflowException('Das lokale Anlegeprotokollschema fehlt.');
        foreach(explode(';',$creations)as$statement)if(trim($statement)!=='')$pdo->exec($statement);
    }
}
