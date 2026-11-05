<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Archive-key authenticated ownership of one originally empty restore destination. */
final class RestoreProgress
{
    public const MARKER='native_restore_operation';
    public function __construct(private string $root,#[\SensitiveParameter] private string $key) {}

    private function signingKey(): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$this->key)) throw new \DomainException('Privaten Sicherungsschlüssel prüfen.');
        return hash_hkdf('sha256',hex2bin($this->key),32,'kulturhus-native-restore-state-v1');
    }
    public function save(array $state,bool $completed=false): void
    {
        $bytes=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        UpgradeFiles::atomic($this->root.($completed?'/private/restore-receipt.json':'/restore-progress.json'),json_encode(['state'=>$state,'mac'=>hash_hmac('sha256',$bytes,$this->signingKey())],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
    }
    public function read(bool $completed=false): array
    {
        $file=$this->root.($completed?'/private/restore-receipt.json':'/restore-progress.json');
        UpgradeFiles::privateFile($file); $envelope=\PackageStore::json($file);
        $bytes=json_encode($envelope['state']??null,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (!hash_equals(hash_hmac('sha256',$bytes,$this->signingKey()),$envelope['mac']??'') || ($envelope['state']['format']??0)!==2 || ($envelope['state']['target']??'')!==$this->root) throw new \DomainException('Wiederaufnahme gehört nicht zu diesem Archivschlüssel und Ziel.');
        return $envelope['state'];
    }
    public function marker(\PDO $db,array $state,bool $create=false): void
    {
        $exists=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_restore_operation'")->fetchColumn();
        if (!$exists && $create) {
            if ((int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()) throw new \DomainException('Bestehende fremde Datenbank bleibt unverändert.');
            $db->exec('CREATE TABLE native_restore_operation (id INT PRIMARY KEY,intent VARCHAR(48) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $exists=1;
        }
        if (!$exists) throw new \DomainException('Eigener Wiederherstellungsnachweis fehlt in der Datenbank.');
        $intent=$db->query('SELECT intent FROM native_restore_operation WHERE id=1')->fetchColumn();
        if ($intent===false && $create) { $query=$db->prepare('INSERT INTO native_restore_operation VALUES(1,?)');$query->execute([$state['intent']]);$intent=$state['intent']; }
        if (!hash_equals($state['intent'],(string)$intent)) throw new \DomainException('Fremde Datenbankwartung bleibt unverändert.');
    }
    public function assertImport(\PDO $db): array
    {
        $state=$this->read();
        if (PHP_SAPI!=='cli' || $state['phase']!=='sql' || $state['database']!==$db->query('SELECT DATABASE()')->fetchColumn() || $state['server']!==$db->query('SELECT @@server_uuid')->fetchColumn()) throw new \DomainException('Keine eigene fortsetzbare SQL-Wiederherstellung freigegeben.');
        $this->marker($db,$state);
        return $state;
    }
}
