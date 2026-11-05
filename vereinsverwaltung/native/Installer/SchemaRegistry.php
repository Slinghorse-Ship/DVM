<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;
use Kulturhus\Native\Shared\Db;

/** Explicit maintenance connection; completed module versions survive interrupted DDL. */
final class SchemaRegistry
{
    public const VERSIONS=['identity'=>10,'cms'=>18,'auth'=>8,'communication'=>19,'delivery'=>15,'chat'=>3,'pos'=>1,'workflows'=>3,'status'=>1,'extensions'=>1,'language'=>1,'api'=>1,'files'=>1,'system'=>1,'cms_transfer'=>1,'comments'=>1,'appearance'=>1];

    public static function assertCurrent(PDO $db): void
    {
        $current=Db::run($db,'SELECT module,version FROM native_schema_versions')->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($current)!==count(self::VERSIONS)) throw new \DomainException('Modulinstallation unvollständig.');
        foreach (self::VERSIONS as $module=>$version) if ((int)($current[$module]??0)!==$version) throw new \DomainException('Modulinstallation nicht aktuell: '.$module);
    }
    public static function install(PDO $db, SetupPermit|UpgradePermit|null $permit=null, ?string $target=null): array
    {
        if (PHP_SAPI !== 'cli') {
            if (!$permit || !$target) throw new \DomainException('Browser-Schemaverwaltung benötigt den privaten Einrichtungsschlüssel.');
            $permit->assertActive($target);
        }
        if ($permit instanceof UpgradePermit) $permit->assertMaintenance($target??'',$db);
        if ($db->inTransaction() || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \DomainException('Schemaverwaltung benötigt eine eigene MySQL-CLI-Verbindung.');
        }
        require_once dirname(__DIR__,2).'/src/autoload.php';
        require_once dirname(__DIR__,2).'/src/Infrastructure/Database/Database.php';
        require_once dirname(__DIR__,2).'/src/DocumentWorkflow/WorkflowSchema.php';
        $lock = 'native-schema-'.hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn());
        $lock = substr($lock, 0, 64);
        if ((int)Db::run($db, 'SELECT GET_LOCK(?,10)', [$lock])->fetchColumn() !== 1) {
            throw new \DomainException('Eine Schemaverwaltung läuft bereits.');
        }
        try {
            // The existing baseline requires an empty database and owns its migration history.
            \Kulturhus\Verein\Database::migrate($db);
            $db->exec("CREATE TABLE IF NOT EXISTS native_schema_versions (
                module VARCHAR(32) PRIMARY KEY, version INT NOT NULL, applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
            $steps = [
                'identity' => \Kulturhus\Native\Integration\Schema::install(...),
                'api' => \Kulturhus\Native\Api\Schema::install(...),
                'cms' => \Kulturhus\Native\Cms\Schema::install(...),
                'files' => \Kulturhus\Native\Files\Schema::install(...),
                'system' => \Kulturhus\Native\System\Schema::install(...),
                'auth' => \Kulturhus\Native\Auth\Schema::install(...),
                'communication' => \Kulturhus\Native\Communication\Schema::install(...),
                'delivery' => \Kulturhus\Native\Shared\RuntimeSchema::install(...),
                'chat' => \Kulturhus\Native\Chat\Schema::install(...),
                'pos' => \Kulturhus\Native\Pos\Schema::install(...),
                'workflows' => \Kulturhus\Verein\DocumentWorkflow\WorkflowSchema::install(...),
                'status' => \Kulturhus\Native\Status\Schema::install(...),
                'extensions' => \Kulturhus\Native\Extensions\Schema::install(...),
                'language' => \Kulturhus\Native\Language\Schema::install(...),
                'appearance' => \Kulturhus\Native\Appearance\Schema::install(...),
                'cms_transfer' => \Kulturhus\Native\CmsTransfer\Schema::install(...),
                'comments' => \Kulturhus\Native\Comments\Schema::install(...),
            ];
            foreach ($steps as $module => $install) {
                $version = Db::run($db, 'SELECT version FROM native_schema_versions WHERE module=?', [$module])->fetchColumn();
                $target=self::VERSIONS[$module];
                if ($version !== false && ((int)$version<1 || (int)$version>$target)) {
                    throw new \DomainException('Nicht unterstützte Modulversion: '.$module);
                }
                if ($version === false || (int)$version<$target) {
                    $install($db);
                    Db::run($db, 'INSERT INTO native_schema_versions VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE version=VALUES(version),applied_at=VALUES(applied_at)', [$module,$target]);
                }
            }
            return Db::run($db, 'SELECT module,version FROM native_schema_versions ORDER BY module')->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            Db::run($db, 'SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
