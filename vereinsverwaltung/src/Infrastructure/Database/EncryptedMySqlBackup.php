<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Versioned, authenticated streaming SQL backup. No plaintext is staged during creation. */
final class EncryptedMySqlBackup
{
    private const MAGIC = "KHSQL01\n";
    private const CHUNK = 65536;

    /** An existing daily archive is verified, never replaced. Caller supplies a private directory. */
    public static function create(\PDO $db, string $dsn, string $destination, string $password): array
    {
        $key = self::key($password);
        if (!is_dir(dirname($destination)) || is_link($destination)) throw new \RuntimeException('Privates Sicherungsziel prüfen.');
        $old = umask(0077);
        try { $lock = fopen($destination.'.lock', 'c'); } finally { umask($old); }
        if (!$lock) throw new \RuntimeException('Sicherungssperre nicht verfügbar.');
        $temporary = $destination.'.partial-'.bin2hex(random_bytes(8)); $file = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Eine tägliche Sicherung läuft bereits.');
            if (file_exists($destination)) return ['created'=>false] + self::verify($destination, $password);
            $old = umask(0077);
            try { $file = fopen($temporary, 'xb'); } finally { umask($old); }
            if (!$file) throw new \RuntimeException('Verschlüsselte Sicherungsdatei kann nicht angelegt werden.');
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            self::write($file, self::MAGIC.$header);
            $hash = hash_init('sha256'); $bytes = 0;
            MySqlBackup::export($db, $dsn, static function(string $sql) use ($file, &$state, $hash, &$bytes): void {
                for ($offset = 0, $length = strlen($sql); $offset < $length; $offset += self::CHUNK) {
                    $part = substr($sql, $offset, self::CHUNK);
                    hash_update($hash, $part); $bytes += strlen($part);
                    self::frame($file, sodium_crypto_secretstream_xchacha20poly1305_push($state, $part, self::MAGIC));
                }
            });
            $manifest = ['format'=>1, 'bytes'=>$bytes, 'sha256'=>hash_final($hash)];
            self::frame($file, sodium_crypto_secretstream_xchacha20poly1305_push($state, json_encode($manifest, JSON_THROW_ON_ERROR), self::MAGIC, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL));
            if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new \RuntimeException('Verschlüsselte Sicherung konnte nicht abgeschlossen werden.');
            fclose($file); $file = null;
            $verified = self::verify($temporary, $password);
            // No-clobber publication: another writer or an operator's file is never replaced.
            if (!link($temporary, $destination)) throw new \RuntimeException('Sicherungsziel bereits vorhanden oder nicht verfügbar.');
            return ['created'=>true] + $verified;
        } finally {
            if (is_resource($file)) fclose($file);
            if (is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock); sodium_memzero($key);
        }
    }

    public static function verify(string $archive, string $password): array
    {
        return self::read($archive, $password, null);
    }

    /** Explicit recovery only: publish SQL after complete authentication; never restore a database here. */
    public static function decryptTo(string $archive, string $password, string $destination): array
    {
        if (file_exists($destination) || is_link($destination) || !is_dir(dirname($destination))) throw new \RuntimeException('Neues privates Wiederherstellungsziel angeben.');
        $temporary = $destination.'.partial-'.bin2hex(random_bytes(8));
        $old = umask(0077); try { $file = fopen($temporary, 'xb'); } finally { umask($old); }
        if (!$file) throw new \RuntimeException('Wiederherstellungsdatei nicht verfügbar.');
        try {
            $result = self::read($archive, $password, static fn(string $sql) => self::write($file, $sql));
            if (!fflush($file)) throw new \RuntimeException('Wiederherstellungsdatei konnte nicht abgeschlossen werden.');
            fclose($file); $file = null;
            if (!link($temporary, $destination)) throw new \RuntimeException('Wiederherstellungsziel bereits vorhanden oder nicht verfügbar.');
            return $result;
        } finally { if (is_resource($file)) fclose($file); if (is_file($temporary)) unlink($temporary); }
    }

    private static function read(string $archive, string $password, ?callable $sink): array
    {
        $key = self::key($password);
        if (is_link($archive) || !is_file($archive)) throw new \RuntimeException('Sicherungsdatei nicht verfügbar.');
        $file = fopen($archive, 'rb');
        if (!$file) throw new \RuntimeException('Sicherungsdatei nicht lesbar.');
        try {
            if (self::exact($file, strlen(self::MAGIC)) !== self::MAGIC) throw new \RuntimeException('Unbekanntes Sicherungsformat.');
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::exact($file, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $hash = hash_init('sha256'); $bytes = 0;
            while (true) {
                $size = unpack('N', self::exact($file, 4))[1];
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) throw new \RuntimeException('Beschädigte Sicherungslänge.');
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::exact($file, $size), self::MAGIC);
                if ($result === false) throw new \RuntimeException('Sicherungsschlüssel falsch oder Sicherung beschädigt.');
                [$plain, $tag] = $result;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $manifest = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
                    $digest = hash_final($hash);
                    if (($manifest['format'] ?? null) !== 1 || ($manifest['bytes'] ?? null) !== $bytes || !is_string($manifest['sha256'] ?? null) || !hash_equals($digest, $manifest['sha256']) || $bytes === 0 || fread($file, 1) !== '' || !feof($file)) throw new \RuntimeException('Sicherung ist unvollständig oder verändert.');
                    return ['format'=>1, 'bytes'=>$bytes, 'sha256'=>$digest, 'encrypted'=>true, 'verified'=>true];
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) throw new \RuntimeException('Unbekannter Sicherungsblock.');
                hash_update($hash, $plain); $bytes += strlen($plain);
                if ($sink !== null) $sink($plain);
            }
        } finally { fclose($file); sodium_memzero($key); }
    }

    private static function key(string $password): string
    {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') || !preg_match('/^[a-f0-9]{64}$/D', $password)) throw new \RuntimeException('Gültigen Sicherungsschlüssel und Sodium prüfen.');
        return sodium_crypto_generichash('kulturhus-daily-mysql-v1', hex2bin($password), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }
    private static function frame($file, string $cipher): void { self::write($file, pack('N', strlen($cipher)).$cipher); }
    private static function write($file, string $data): void
    {
        while ($data !== '') { $n = fwrite($file, $data); if ($n === false || $n === 0) throw new \RuntimeException('Sicherungsdatei konnte nicht vollständig geschrieben werden.'); $data = substr($data, $n); }
    }
    private static function exact($file, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) { $part = fread($file, $length - strlen($data)); if ($part === false || $part === '') throw new \RuntimeException('Sicherung ist abgeschnitten.'); $data .= $part; }
        return $data;
    }
}
