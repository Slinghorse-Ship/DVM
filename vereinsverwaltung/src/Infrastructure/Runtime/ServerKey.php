<?php declare(strict_types=1);
namespace Kulturhus\Verein\Infrastructure\Runtime;

/** Native installations use their persistent private key through the shared native runtime. */
final class ServerKey
{
    /** Preserve the historical synthetic hex-key format; create its private parent on fresh checkouts. */
    public static function syntheticArchive(string $directory): string
    {
        if (
            is_link($directory) ||
            (!is_dir($directory) &&
                !mkdir($directory, 0700, true) &&
                !is_dir($directory))
        ) {
            throw new \DomainException(
                "Lokaler Archivschlüsselordner nicht verfügbar.",
            );
        }
        $path = $directory . "/contact-archive.key";
        if (is_link($path)) {
            throw new \DomainException(
                "Lokaler Archivschlüssel benötigt eine eigene Datei.",
            );
        }
        $mask = umask(0077);
        try {
            $file = fopen($path, "c+");
        } finally {
            umask($mask);
        }
        if (!$file) {
            throw new \DomainException(
                "Lokaler Archivschlüssel nicht verfügbar.",
            );
        }
        try {
            if (!flock($file, LOCK_EX)) {
                throw new \DomainException("Archivschlüssel belegt.");
            }
            $key = trim((string) stream_get_contents($file));
            if ($key === "") {
                $key = bin2hex(random_bytes(32));
                if (fwrite($file, $key) !== 64 || !fflush($file)) {
                    throw new \DomainException(
                        "Archivschlüssel nicht gespeichert.",
                    );
                }
                chmod($path, 0600);
            }
            if (strlen($key) !== 64 || !ctype_xdigit($key)) {
                throw new \DomainException("Archivschlüssel prüfen.");
            }
            return hash("sha256", $key, true);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public static function nativeRuntime(string $path): string
    {
        $existing = trim((string) getenv("APP_SECRET"));
        return strlen($existing) >= 32
            ? hash("sha256", $existing, true)
            : self::nativeFile($path);
    }
    public static function nativeFile(string $path): string
    {
        if (
            $path === "" ||
            is_link($path) ||
            !is_file($path) ||
            fileperms($path) & 0077
        ) {
            throw new \DomainException(
                "Privater Serverschlüssel benötigt eine geschützte Datei (0600).",
            );
        }
        $key = file_get_contents($path);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new \DomainException(
                "Privater Serverschlüssel ist ungültig.",
            );
        }
        return hash_hkdf("sha256", $key, 32, "kulturhus-manager-master-v1");
    }
}
