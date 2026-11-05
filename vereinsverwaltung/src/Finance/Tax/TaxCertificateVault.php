<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Optional club certificate. No PIN, derived key, plaintext PFX or personal certificate on disk. */
final class TaxCertificateVault
{
    private const LIMIT = 1048576;
    public function __construct(private PDO $db, private string $actor) {}

    public function allowed(): bool
    {
        try { $this->access(); return true; } catch (DomainException) { return false; }
    }

    private function access(): array
    {
        $a = Permissions::actor($this->db, $this->actor);
        if ($a['finance_access'] !== 'write') throw new DomainException('Finanz-Schreibrecht erforderlich.');
        BoardAccess::require($this->db, $this->actor);
        return $a;
    }

    private function root(): string
    {
        $path = (string)getenv('VEREIN_ELSTER_CERTIFICATE_ROOT');
        if ($path === '' && getenv('VEREIN_PRIVATE_ROOT')) $path = rtrim((string)getenv('VEREIN_PRIVATE_ROOT'), '/').'/elster-certificates';
        $root = $path !== '' ? realpath($path) : false;
        if (!$root || !is_dir($root) || is_link($path) || !is_writable($root) || !function_exists('sodium_crypto_pwhash')) throw new DomainException('Die geschützte Zertifikatsablage ist nicht verfügbar. Eine Datei kann weiterhin einmalig verwendet werden.');
        foreach ([dirname(dirname(__DIR__, 2)).'/public', dirname(dirname(__DIR__, 2), 2).'/processwire', $_SERVER['DOCUMENT_ROOT'] ?? ''] as $web) {
            $web = $web !== '' ? realpath($web) : false;
            $norm = static fn(string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
            if ($web && ($norm($root) === $norm($web) || str_starts_with($norm($root), $norm($web).'/'))) throw new DomainException('Zertifikatsablage muss außerhalb des öffentlichen Website-Verzeichnisses liegen.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($root) & 0077) !== 0) throw new DomainException('Private Dateirechte für die Zertifikatsablage erforderlich.');
        return $root;
    }

    private function locked(callable $fn): mixed
    {
        $a = $this->access();
        $path = $this->root().'/'.hash('sha256', $a['club_id']).'.enc';
        if (is_link($path) || is_link($path.'.lock')) throw new DomainException('Zertifikatsablage prüfen.');
        $mask = umask(0077);
        try { $lock = fopen($path.'.lock', 'c'); } finally { umask($mask); }
        if (!$lock) throw new DomainException('Zertifikatsablage nicht verfügbar.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new DomainException('Zertifikat wird gerade bearbeitet. Bitte erneut öffnen.');
            $this->access();
            return $fn($path, $a);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function record(string $path): array
    {
        if (!file_exists($path)) return ['revision'=>'', 'saved'=>false];
        if (is_link($path) || !is_file($path) || filesize($path) > 1500000 || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0)) throw new DomainException('Gespeichertes Zertifikat prüfen.');
        try { $r = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new DomainException('Gespeichertes Zertifikat ist beschädigt.'); }
        if (!is_array($r) || ($r['format'] ?? null) !== 1 || !is_string($r['revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $r['revision']) || !is_bool($r['saved'] ?? null)) throw new DomainException('Gespeichertes Zertifikat ist ungültig.');
        return $r;
    }

    /** Board-only metadata. Merely viewing the page never decrypts the certificate. */
    public function status(): array
    {
        return $this->locked(static function(string $path): array {
            $r = self::record($path);
            return array_intersect_key($r, array_flip(['revision','saved','valid_until','saved_at']));
        });
    }

    private static function matchRevision(array $r, string $expected): void
    {
        if (!hash_equals($r['revision'], $expected)) throw new DomainException('Das gespeicherte Zertifikat wurde inzwischen geändert. Bitte die Seite erneut öffnen.');
    }

    private static function pin(#[\SensitiveParameter] string $pin): void
    {
        if (strlen($pin)<1 || strlen($pin)>256 || str_contains($pin, chr(0))) throw new DomainException('Zertifikats-Passwort eingeben.');
    }

    /** Argon2id parameters are fixed, never supplied by a file or request. */
    private static function key(#[\SensitiveParameter] string $pin, string $salt): string
    {
        self::pin($pin);
        return sodium_crypto_pwhash(32, $pin, $salt, 2, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private static function aad(string $club, array $r): string
    {
        return json_encode(['kh-elster-certificate-v1', $club, $r['revision'], $r['valid_until'], $r['saved_at']], JSON_THROW_ON_ERROR);
    }

    private static function write(string $path, array $r): void
    {
        $bytes = json_encode($r, JSON_THROW_ON_ERROR);
        $tmp = $path.'.partial-'.bin2hex(random_bytes(12));
        $mask = umask(0077);
        try { $f = fopen($tmp, 'xb'); } finally { umask($mask); }
        if (!$f) throw new DomainException('Zertifikat konnte nicht gespeichert werden.');
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || (function_exists('fsync') && !fsync($f))) throw new DomainException('Zertifikat konnte nicht vollständig gespeichert werden.');
            fclose($f); $f = null;
            if (!rename($tmp, $path)) throw new DomainException('Zertifikat konnte nicht ersetzt werden.');
        } finally { if (is_resource($f)) fclose($f); if (is_file($tmp)) unlink($tmp); }
    }

    public function save(#[\SensitiveParameter] string $bytes, #[\SensitiveParameter] string $pin, string $expected, bool $confirmed): void
    {
        $this->access(); self::pin($pin);
        if (!$confirmed) throw new DomainException('Bitte die Speicherung des Organisationszertifikats für diesen Verein bestätigen.');
        if (strlen($bytes)<1 || strlen($bytes)>self::LIMIT) throw new DomainException('ELSTER-Zertifikat auswählen, höchstens 1 MB.');
        // Verify the original PFX and PIN through ERiC, without any tax transmission.
        $info = TaxEricAdapter::certificate($bytes, $pin);
        if (($info['organization'] ?? null) !== true || ($info['test'] ?? null) !== false) throw new DomainException('Bitte ein Organisationszertifikat des Vereins verwenden. Persönliche Zertifikate und Testzertifikate werden nicht hinterlegt.');
        if (!is_string($info['valid_until'] ?? null) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $info['valid_until']) || strtotime($info['valid_until']) <= time()) throw new DomainException('Ein gültiges Organisationszertifikat verwenden.');
        $this->locked(function(string $path, array $a) use ($bytes, $pin, $expected, $info): void {
            self::matchRevision(self::record($path), $expected);
            $r = ['format'=>1, 'revision'=>bin2hex(random_bytes(32)), 'saved'=>true, 'valid_until'=>$info['valid_until'], 'saved_at'=>gmdate('Y-m-d\TH:i:s\Z')];
            $salt = random_bytes(16); $nonce = random_bytes(24); $key = self::key($pin, $salt);
            try { $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($bytes, self::aad($a['club_id'], $r), $nonce, $key); }
            finally { sodium_memzero($key); }
            $r['salt'] = base64_encode($salt); $r['nonce'] = base64_encode($nonce); $r['ciphertext'] = base64_encode($cipher);
            self::write($path, $r);
        });
    }

    /** For the already reviewed production submission only; no download route exists. */
    public function load(string $expected, #[\SensitiveParameter] string $pin): string
    {
        self::pin($pin);
        return $this->locked(function(string $path, array $a) use ($expected, $pin): string {
            $r = self::record($path); self::matchRevision($r, $expected);
            if (!$r['saved']) throw new DomainException('Kein Vereinszertifikat hinterlegt.');
            foreach (['salt','nonce','ciphertext','valid_until','saved_at'] as $field) if (!is_string($r[$field] ?? null)) throw new DomainException('Zertifikatsablage ist beschädigt.');
            $salt = base64_decode($r['salt'], true); $nonce = base64_decode($r['nonce'], true); $cipher = base64_decode($r['ciphertext'], true);
            if ($salt === false || strlen($salt)!==16 || $nonce === false || strlen($nonce)!==24 || $cipher === false || strlen($cipher)<17 || strlen($cipher)>self::LIMIT+16) throw new DomainException('Zertifikatsablage ist beschädigt.');
            $key = self::key($pin, $salt);
            try { $bytes = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, self::aad($a['club_id'], $r), $nonce, $key); }
            finally { sodium_memzero($key); }
            if ($bytes === false) throw new DomainException('Zertifikats-Passwort falsch oder gespeichertes Zertifikat beschädigt.');
            if (strtotime($r['valid_until']) <= time()) { sodium_memzero($bytes); throw new DomainException('Das Vereinszertifikat ist abgelaufen. Bitte ersetzen.'); }
            return $bytes;
        });
    }

    public function remove(string $expected): void
    {
        $this->locked(static function(string $path) use ($expected): void {
            self::matchRevision(self::record($path), $expected);
            // Keep only a fresh revision: stale forms cannot restore an old certificate.
            self::write($path, ['format'=>1, 'revision'=>bin2hex(random_bytes(32)), 'saved'=>false]);
        });
    }
}
