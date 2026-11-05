<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** A browser may initialize schemas only with the uploader's private, single-use setup key. */
final class SetupPermit
{
    private function __construct(private string $root, private string $proof) {}

    public static function authenticate(
        string $root,
        #[\SensitiveParameter] string $token,
    ): self {
        $root = realpath($root) ?: "";
        $file = $root . "/private/setup-token";
        if (
            $root === "" ||
            is_file($root . "/installation.json") ||
            is_link($file) ||
            !is_file($file) ||
            !preg_match("/\A[a-f0-9]{64}\z/D", $token)
        ) {
            throw new \DomainException(
                "Einrichtungsschlüssel ungültig oder Einrichtung bereits abgeschlossen.",
            );
        }
        $proof = trim((string) file_get_contents($file));
        if (!hash_equals($proof, hash("sha256", $token))) {
            throw new \DomainException(
                "Einrichtungsschlüssel ungültig oder Einrichtung bereits abgeschlossen.",
            );
        }
        return new self($root, $proof);
    }

    public function assertActive(string $root): void
    {
        $file = $this->root . "/private/setup-token";
        if (
            realpath($root) !== $this->root ||
            is_file($this->root . "/installation.json") ||
            is_link($file) ||
            !is_file($file) ||
            !hash_equals($this->proof, trim((string) file_get_contents($file)))
        ) {
            throw new \DomainException(
                "Browser-Einrichtung nicht freigegeben.",
            );
        }
    }
}
