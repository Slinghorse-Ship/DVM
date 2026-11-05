<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use Closure;
use DomainException;
/** Password recheck plus the existing optional factor; Vault consumes issued grants atomically. */
final class MemberBankStepUp
{
    private array $session;
    private const NS = "member_bank_stepup_v1";
    public function __construct(
        private PDO $db,
        private string $actor,
        array &$session,
        private string $sessionHash,
        private Closure $authenticate,
        private Closure $security,
        private Closure $deliver,
        private Closure $attempt,
        #[\SensitiveParameter] private string $key,
        private ?Closure $clock = null,
        private ?Closure $verifyFactor = null,
    ) {
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $sessionHash) ||
            strlen($key) < 32
        ) {
            throw new DomainException("Sichere Bestätigung nicht verfügbar.");
        }
        $this->session = &$session;
    }
    public static function production(PDO $db, string $actor): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === "") {
            throw new DomainException(
                "Aktive Vereinsmanager-Sitzung erforderlich.",
            );
        }
        if (ProductionRuntime::nativeManager()) {
            return \Kulturhus\Native\Integration\MemberBankAuthentication::create(
                $db,
                $actor,
            );
        }
        throw new DomainException('Die Bankbestätigung benötigt eine persönliche native Anmeldung.');
    }
    /** Local synthetic demo only: keeps the same grant flow without requiring an account login. */
    public static function localDemo(PDO $db, string $actor): self
    {
        if (
            ProductionRuntime::enabled() ||
            getenv("KULTURHUS_LOCAL_DEMO") !== "1"
        ) {
            throw new DomainException(
                "Lokale Demo-Sicherheitsprüfung nicht verfügbar.",
            );
        }
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === "") {
            throw new DomainException(
                "Aktive Vereinsmanager-Sitzung erforderlich.",
            );
        }
        $key = hash_hkdf(
            "sha256",
            ContactArchive::serverKey(),
            32,
            "member-bank-stepup-v1",
        );
        $sessionHash = hash("sha256", session_id());
        $security = static function () use ($db, $actor, $key): array {
            $a = Permissions::actor($db, $actor);
            return [
                "mfa" => false,
                "email" => "",
                "stamp" => hash_hmac(
                    "sha256",
                    json_encode(
                        [$a["id"], $a["club_id"], "local-demo"],
                        JSON_THROW_ON_ERROR,
                    ),
                    $key,
                ),
            ];
        };
        $expected =
            (string) (getenv("KULTURHUS_DEMO_BANK_PASSWORD") ?:
            "synthetic-password");
        return new self(
            $db,
            $actor,
            $_SESSION,
            $sessionHash,
            static fn(string $password): bool => $expected !== "" &&
                hash_equals($expected, $password),
            $security,
            static fn(string $email, string $code): bool => false,
            static function (string $phase): void {},
            $key,
        );
    }
    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }
    private function binding(array $binding): array
    {
        $actor = Permissions::actor($this->db, $this->actor);
        $contact = $binding["contact"] ?? null;
        $action = $binding["action"] ?? null;
        $version = $binding["version"] ?? null;
        $hash = $binding["draft_hash"] ?? null;
        if (
            !is_string($contact) ||
            !preg_match('/^[a-f0-9]{32}$/D', $contact) ||
            !in_array(
                $action,
                ["save", "mandate", "revoke", "delete", "reveal"],
                true,
            ) ||
            !is_int($version) ||
            $version < 0 ||
            !is_string($hash) ||
            !preg_match('/^[a-f0-9]{64}$/D', $hash)
        ) {
            throw new DomainException(
                "Bestätigung gehört nicht zu gültigen Angaben.",
            );
        }
        $q = $this->db->prepare(
            "SELECT 1 FROM contacts WHERE club_id=? AND id=?",
        );
        $q->execute([$actor["club_id"], $contact]);
        if (!$q->fetchColumn()) {
            throw new DomainException("Mitglied nicht verfügbar.");
        }
        return [
            "club" => $actor["club_id"],
            "actor" => $this->actor,
            "contact" => $contact,
            "action" => $action,
            "version" => $version,
            "draft_hash" => $hash,
            "session" => $this->sessionHash,
        ];
    }
    private function digest(array $binding): string
    {
        return hash_hmac(
            "sha256",
            json_encode($binding, JSON_THROW_ON_ERROR),
            $this->key,
        );
    }
    private function issue(array $binding, array $state): string
    {
        return \Kulturhus\Verein\Infrastructure\Database\Transactions::run(
            $this->db,
            function () use ($binding, $state): string {
                if (
                    ($this->security)() !== $state ||
                    $this->binding([
                        "contact" => $binding["contact"],
                        "action" => $binding["action"],
                        "version" => $binding["version"],
                        "draft_hash" => $binding["draft_hash"],
                    ]) !== $binding
                ) {
                    throw new DomainException(
                        "Sicherheitsstatus geändert. Bitte erneut beginnen.",
                    );
                }
                $token = bin2hex(random_bytes(32));
                $q = $this->db->prepare(
                    "INSERT INTO member_bank_grants(token_hash,club_id,actor_id,contact_id,action,expected_version,payload_hash,session_hash,expires_at,used_at) VALUES(?,?,?,?,?,?,?,?,?,NULL)",
                );
                $q->execute([
                    hash("sha256", $token),
                    $binding["club"],
                    $this->actor,
                    $binding["contact"],
                    $binding["action"],
                    $binding["version"],
                    $binding["draft_hash"],
                    $this->sessionHash,
                    $this->now() + 60,
                ]);
                return $token;
            },
        );
    }
    public function begin(
        array $binding,
        #[\SensitiveParameter] string $password,
    ): array {
        unset($this->session[self::NS]);
        $bound = $this->binding($binding);
        $state = ($this->security)();
        ($this->attempt)("password");
        if (
            $password === "" ||
            strlen($password) > 4096 ||
            !($this->authenticate)($password)
        ) {
            throw new DomainException("Aktuelles Passwort stimmt nicht.");
        }
        if (($this->security)() !== $state) {
            throw new DomainException(
                "Sicherheitsstatus geändert. Bitte erneut beginnen.",
            );
        }
        if (!$state["mfa"]) {
            return [
                "requires_code" => false,
                "token" => $this->issue($bound, $state),
            ];
        }
        $id = bin2hex(random_bytes(16));
        $codeHash = null;
        if ($this->verifyFactor === null) {
            $code = (string) random_int(10000000, 99999999);
            if (!($this->deliver)($state["email"], $code)) {
                throw new DomainException(
                    "Code konnte nicht versendet werden. Es wurde nichts geändert.",
                );
            }
            $codeHash = hash_hmac("sha256", $id . ":" . $code, $this->key);
        }
        $this->session[self::NS] = [
            "id" => $id,
            "binding" => $this->digest($bound),
            "stamp" => $state["stamp"],
            "code" => $codeHash,
            "expires" => $this->now() + 300,
            "tries" => 0,
        ];
        return [
            "requires_code" => true,
            "challenge" => $id,
            "expires" => $this->session[self::NS]["expires"],
        ];
    }
    public function confirm(
        string $challenge,
        array $binding,
        #[\SensitiveParameter] string $code,
    ): string {
        $bound = $this->binding($binding);
        $state = ($this->security)();
        ($this->attempt)("code");
        $pending = $this->session[self::NS] ?? null;
        if (
            !is_array($pending) ||
            !hash_equals($pending["id"], $challenge) ||
            !hash_equals($pending["binding"], $this->digest($bound)) ||
            !$state["mfa"] ||
            !hash_equals($pending["stamp"], $state["stamp"]) ||
            $pending["expires"] <= $this->now() ||
            $pending["tries"] >= 5
        ) {
            unset($this->session[self::NS]);
            throw new DomainException(
                "Bestätigung abgelaufen oder Angaben geändert. Bitte erneut beginnen.",
            );
        }
        $this->session[self::NS]["tries"]++;
        $valid =
            $this->verifyFactor !== null
                ? ($this->verifyFactor)($code, $pending["stamp"])
                : preg_match('/^[0-9]{8}$/D', $code) &&
                    is_string($pending["code"]) &&
                    hash_equals(
                        $pending["code"],
                        hash_hmac(
                            "sha256",
                            $challenge . ":" . $code,
                            $this->key,
                        ),
                    );
        if (!$valid) {
            throw new DomainException("Sicherheitscode stimmt nicht.");
        }
        unset($this->session[self::NS]);
        return $this->issue($bound, $state);
    }
}
