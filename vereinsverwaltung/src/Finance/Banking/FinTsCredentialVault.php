<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/**
 * Opt-in storage for a FinTS login used by reads and explicitly approved
 * payment dialogs.
 *
 * The credential is encrypted outside the public web directory. TANs,
 * challenges and bank protocol state are deliberately never stored here.
 * The file is only useful together with the private installation key, and
 * the short-lived FinTS dialog still applies to every individual read.
 */
final class FinTsCredentialVault
{
    private const VERSION = 1;
    private const MAX_FIELD = 200;

    public function __construct(
        private string $root,
        private string $stateKey,
        private string $clubId,
    ) {
        if (strlen($this->stateKey) !== 32 || $this->clubId === '') {
            throw new DomainException('Privater FinTS-Schlüssel fehlt.');
        }
    }

    private function path(): string
    {
        return $this->root.'/fints-credentials.enc';
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'kh-fints-credential-v1', $this->stateKey, true);
    }

    private function aad(): string
    {
        return 'kh-fints-credential-v1|'.$this->clubId;
    }

    /** @return array{user:string,pin:string,expires:int,bank_id:string} */
    public function load(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            throw new DomainException('Kein gespeicherter FinTS-Zugang vorhanden.');
        }
        $raw = (string)file_get_contents($path);
        if (strlen($raw) < 29 || strlen($raw) > 4096) {
            throw new DomainException('Gespeicherter FinTS-Zugang ist ungültig.');
        }
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            $this->aad()
        );
        if ($plain === false) {
            throw new DomainException('Gespeicherter FinTS-Zugang konnte nicht geprüft werden.');
        }
        try {
            $data = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DomainException('Gespeicherter FinTS-Zugang ist ungültig.');
        }
        if (!is_array($data) || (int)($data['version'] ?? 0) !== self::VERSION) {
            throw new DomainException('Gespeicherter FinTS-Zugang ist veraltet.');
        }
        $user = $data['user'] ?? null;
        $pin = $data['pin'] ?? null;
        $bankId = $data['bank_id'] ?? '';
        $expires = (int)($data['expires'] ?? 0);
        if (!is_string($user) || $user === '' || strlen($user) > self::MAX_FIELD || !is_string($pin) || $pin === '' || strlen($pin) > self::MAX_FIELD || !is_string($bankId) || strlen($bankId) > self::MAX_FIELD || $expires < 0) {
            throw new DomainException('Gespeicherter FinTS-Zugang ist unvollständig.');
        }
        if ($expires !== 0 && $expires <= time()) {
            $this->delete();
            throw new DomainException('Der gespeicherte FinTS-Zugang ist abgelaufen.');
        }
        return ['user' => $user, 'pin' => $pin, 'expires' => $expires, 'bank_id' => $bankId];
    }

    public function save(string $user, string $pin, string $bankId = ''): void
    {
        if ($user === '' || strlen($user) > self::MAX_FIELD || $pin === '' || strlen($pin) > self::MAX_FIELD) {
            throw new DomainException('Bankkennung und PIN prüfen.');
        }
        $data = json_encode([
            'version' => self::VERSION,
            'user' => $user,
            'pin' => $pin,
            'bank_id' => $bankId,
            'expires' => 0,
        ], JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($data, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $this->aad());
        if ($cipher === false) {
            throw new DomainException('FinTS-Zugang konnte nicht verschlüsselt werden.');
        }
        $tmp = $this->path().'.'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $iv.$tag.$cipher, LOCK_EX) === false) {
            throw new DomainException('FinTS-Zugang konnte nicht gespeichert werden.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->path())) {
            @unlink($tmp);
            throw new DomainException('FinTS-Zugang konnte nicht gespeichert werden.');
        }
        @chmod($this->path(), 0600);
    }

    public function delete(): void
    {
        $path = $this->path();
        if (is_file($path) && !unlink($path)) {
            throw new DomainException('Gespeicherter FinTS-Zugang konnte nicht gelöscht werden.');
        }
    }

    /** @return array{saved:bool,expires:int,bank_id:string} */
    public function status(): array
    {
        try {
            $credential = $this->load();
        } catch (DomainException) {
            return ['saved' => false, 'expires' => 0, 'bank_id' => ''];
        }
        return ['saved' => true, 'expires' => $credential['expires'], 'bank_id' => $credential['bank_id']];
    }
}
