<?php declare(strict_types=1);
/** Local-only fixture allocator. Never uses or deletes the shared fixture database. */
final class DocumentWorkflowFixture
{
    public const OWNER = 'document-workflows-20261007';

    public static function registryDirectory(): string
    {
        return dirname(__DIR__, 3).'/.local/'.self::OWNER;
    }

    public static function connection(string $name): PDO
    {
        self::databaseRegistration($name);
        $config = self::serverConfig();
        $db = new PDO('mysql:host=127.0.0.1;port=13371;dbname='.$name.';charset=utf8mb4', 'root', $config['password'], self::options());
        self::assertServer($db);
        if ($db->query('SELECT DATABASE()')->fetchColumn() !== $name) throw new RuntimeException('Eigene Workflow-Fixture erforderlich.');
        return $db;
    }

    public static function demoRegistration(): array
    {
        $registration = self::readJson(self::registryDirectory().'/demo.json');
        if (($registration['owner'] ?? null) !== self::OWNER || !is_string($registration['database'] ?? null)) {
            throw new RuntimeException('Ungültige lokale Workflow-Registrierung.');
        }
        self::databaseRegistration($registration['database']);
        return $registration;
    }

    /** @return resource The caller releases this lock in a finally block. */
    public static function initializationLock()
    {
        self::ensureRegistryDirectory();
        $lock = @fopen(self::registryDirectory().'/init.lock', 'c+b');
        if ($lock === false) throw new RuntimeException('Lokale Initialisierungssperre nicht verfügbar.');
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Lokale Initialisierungssperre nicht verfügbar.');
        }
        return $lock;
    }

    /** Publish a complete new registration without replacing any existing file. */
    public static function writeRegistration(string $filename, array $registration): void
    {
        $name = $registration['database'] ?? null;
        if (!is_string($name)) throw new RuntimeException('Ungültige lokale Workflow-Registrierung.');
        self::validateName($name);
        if (($registration['owner'] ?? null) !== self::OWNER || !in_array($filename, [$name.'.json', 'demo.json'], true)) {
            throw new RuntimeException('Ungültige lokale Workflow-Registrierung.');
        }
        if ($filename === 'demo.json') self::databaseRegistration($name);
        self::ensureRegistryDirectory();
        $path = self::registryDirectory().'/'.$filename;
        if (file_exists($path) || is_link($path)) throw new RuntimeException('Eine vorhandene Workflow-Registrierung wird nicht ersetzt.');
        $bytes = json_encode($registration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temporary = self::registryDirectory().'/.registration-'.bin2hex(random_bytes(12)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) throw new RuntimeException('Lokale Workflow-Registrierung kann nicht geschrieben werden.');
        try {
            for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Lokale Workflow-Registrierung konnte nicht vollständig geschrieben werden.');
            }
            if (!fflush($handle) || !fsync($handle)) throw new RuntimeException('Lokale Workflow-Registrierung konnte nicht gesichert werden.');
            fclose($handle);
            $handle = null;
            // Same-filesystem hard links publish atomically and fail when the target exists.
            if (!@link($temporary, $path)) throw new RuntimeException('Lokale Workflow-Registrierung konnte nicht neu angelegt werden; vorhandene Dateien bleiben erhalten.');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private static function ensureRegistryDirectory(): void
    {
        $directory = self::registryDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Lokales Workflow-Verzeichnis kann nicht angelegt werden.');
        }
    }

    private static function validateName(string $name): void
    {
        if (!preg_match('/^kh_wf_[a-f0-9]{24}$/D', $name)) throw new RuntimeException('Eigene Workflow-Fixture erforderlich.');
    }

    private static function databaseRegistration(string $name): array
    {
        self::validateName($name);
        $registration = self::readJson(self::registryDirectory().'/'.$name.'.json');
        if (($registration['database'] ?? null) !== $name || ($registration['owner'] ?? null) !== self::OWNER) {
            throw new RuntimeException('Die Workflow-Datenbank gehört nicht zu dieser lokalen Umgebung.');
        }
        return $registration;
    }

    private static function readJson(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Lokale Workflow-Konfiguration fehlt oder ist ungültig.');
        $bytes = @file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Lokale Workflow-Konfiguration kann nicht gelesen werden.');
        try {
            $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Lokale Workflow-Konfiguration ist ungültig.');
        }
        if (!is_array($data)) throw new RuntimeException('Lokale Workflow-Konfiguration ist ungültig.');
        return $data;
    }

    private static function options(): array
    {
        return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    }

    private static function serverConfig(): array
    {
        $config = self::readJson(dirname(__DIR__, 3).'/.local/mysql-r71-test/connection.json');
        if (($config['host'] ?? '') !== '127.0.0.1' || ($config['port'] ?? 0) !== 13371 || !is_string($config['password'] ?? null)) {
            throw new RuntimeException('Isolierter lokaler MySQL-Testserver erforderlich.');
        }
        return $config;
    }

    private static function assertServer(PDO $db): void
    {
        $data = realpath(dirname(__DIR__, 3).'/.local/mysql-r71-test/data');
        if ($data === false) throw new RuntimeException('Lokaler MySQL-Datenpfad nicht verfügbar.');
        $expected = rtrim(str_replace('\\', '/', $data), '/').'/';
        $actual = rtrim(str_replace('\\', '/', (string)$db->query('SELECT @@datadir')->fetchColumn()), '/').'/';
        if ((int)$db->query('SELECT @@port')->fetchColumn() !== 13371 || $actual !== $expected) throw new RuntimeException('Falscher MySQL-Testserver.');
    }

    public static function create(): string
    {
        self::ensureRegistryDirectory();
        $config = self::serverConfig();
        $db = new PDO('mysql:host=127.0.0.1;port=13371;charset=utf8mb4', 'root', $config['password'], self::options());
        self::assertServer($db);
        $name = 'kh_wf_'.bin2hex(random_bytes(12));
        $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::writeRegistration($name.'.json', ['database' => $name, 'owner' => self::OWNER, 'created_at' => gmdate('c')]);
        return $name;
    }
}
