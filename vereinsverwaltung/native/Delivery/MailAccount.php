<?php declare(strict_types=1);
namespace Kulturhus\Native\Delivery;
use PDO;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};
use Kulturhus\Native\Communication\Content;
use Kulturhus\Native\Integration\{ManagerAccess, ManagerClub};
use Kulturhus\Verein\{ContactArchive, MailChannelSettings};
require_once dirname(__DIR__, 2) . "/src/autoload.php";
final class MailAccount
{
    private ?\Closure $managerKey;
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        ?callable $managerKey = null,
    ) {
        $this->managerKey =
            $managerKey === null ? null : \Closure::fromCallable($managerKey);
    }
    private function key(): string
    {
        return $this->managerKey
            ? ($this->managerKey)()
            : ContactArchive::serverKey();
    }
    public function save(Access $access, array $data, int $version): void
    {
        $access->require("website", "manage");
        if (ManagerClub::id($this->db, $access->club()) !== null) {
            throw new \DomainException(
                "Gemeinsame Postfächer und Versandzwecke im Hauptmanager bearbeiten.",
            );
        }
        $host = Content::text($data["host"] ?? "", 253, true);
        $port = (int) ($data["port"] ?? 0);
        if (
            !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            !str_contains($host, ".") ||
            !in_array($port, [465, 587], true)
        ) {
            throw new \DomainException(
                "SMTP-Hostname und TLS-Port 465/587 erforderlich.",
            );
        }
        $config = [
            "host" => $host,
            "port" => $port,
            "username" => Content::text($data["username"] ?? "", 254, true),
            "password" => Content::text($data["password"] ?? "", 2000, true),
            "from" => Content::email($data["from"] ?? ""),
            "reply_to" => Content::email($data["reply_to"] ?? ""),
        ];
        Db::atomic($this->db, function () use ($access, $config, $version) {
            $actor = $access->require("website", "manage");
            $old = Db::run(
                $this->db,
                "SELECT version FROM native_private_settings WHERE club_id=? AND purpose='mail' FOR UPDATE",
                [$actor["club_id"]],
            )->fetch();
            if (($old ? (int) $old["version"] : 0) !== $version) {
                throw new \DomainException("Mailzugang inzwischen geändert.");
            }
            Db::run(
                $this->db,
                "INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'mail',?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by)",
                [
                    $actor["club_id"],
                    $version + 1,
                    $this->box->seal("mail:" . $actor["club_id"], $config),
                    $actor["id"],
                ],
            );
        });
    }
    public function status(Access $access): array
    {
        $access->require("website", "manage");
        $r = Db::run(
            $this->db,
            "SELECT version FROM native_private_settings WHERE club_id=? AND purpose='mail'",
            [$access->club()],
        )->fetch();
        if ($access instanceof ManagerAccess) {
            $state = (new MailChannelSettings(
                $this->db,
                $access->managerActor(),
                $this->key(),
            ))->state();
            $route = $state["data"]["routes"]["newsletter"];
            $configured =
                $route === "invoices"
                    ? (bool) Db::run(
                        $this->db,
                        "SELECT 1 FROM invoice_mailboxes WHERE club_id=? AND JSON_EXTRACT(config,'$.smtp_enabled')=true",
                        [ManagerClub::id($this->db, $access->club())],
                    )->fetchColumn()
                    : (bool) $state["data"]["channels"][$route]["override"];
            return [
                "shared" => true,
                "configured" => $configured,
                "version" => $state["version"],
                "channel" => $route,
                "legacy_version" => $r ? (int) $r["version"] : 0,
            ];
        }
        return [
            "shared" => false,
            "configured" => (bool) $r,
            "version" => $r ? (int) $r["version"] : 0,
        ];
    }

    public function migrate(
        Access $access,
        int $version,
        int $legacyVersion,
    ): void {
        if (!($access instanceof ManagerAccess)) {
            throw new \DomainException(
                "Aktuell bestätigter Managerzugang erforderlich.",
            );
        }
        Db::atomic($this->db, function () use (
            $access,
            $version,
            $legacyVersion,
        ): void {
            $actor = $access->require("website", "manage");
            $row = Db::run(
                $this->db,
                "SELECT version,ciphertext FROM native_private_settings WHERE club_id=? AND purpose='mail' FOR UPDATE",
                [$actor["club_id"]],
            )->fetch();
            if (!$row || (int) $row["version"] !== $legacyVersion) {
                throw new \DomainException(
                    "Bisheriger Website-Mailzugang inzwischen geändert oder bereits übernommen.",
                );
            }
            $config = $this->box->open(
                "mail:" . $actor["club_id"],
                $row["ciphertext"],
            );
            (new MailChannelSettings(
                $this->db,
                $access->managerActor(),
                $this->key(),
            ))->adoptNative($config, $version);
            Db::run(
                $this->db,
                "DELETE FROM native_private_settings WHERE club_id=? AND purpose='mail'",
                [$actor["club_id"]],
            );
        });
    }

    /** Internal worker configuration; never serialize this result into a response or log. */
    public function configuration(
        string $club,
        string $purpose = "newsletter",
    ): array {
        $manager = ManagerClub::id($this->db, $club);
        if ($manager !== null) {
            $resolved = MailChannelSettings::resolve(
                $this->db,
                $manager,
                $this->key(),
                $purpose,
            );
            $c = $resolved["override"];
            if ($c === null) {
                throw new \DomainException(
                    "Gemeinsames Postfach für diesen Versandzweck einrichten oder bisherigen Website-Zugang übernehmen.",
                );
            }
            return [
                "host" => $c["host"],
                "port" => $c["port"],
                "username" => $c["user"],
                "password" => $c["password"],
                "from" => $c["from"],
                "name" => $c["name"] ?? "",
                "reply_to" => $c["reply"] ?: $c["from"],
            ];
        }
        if (!isset(MailChannelSettings::PURPOSES[$purpose])) {
            throw new \DomainException(
                "Gültigen eigenen Mailversandzweck verwenden.",
            );
        }
        $row = Db::run(
            $this->db,
            "SELECT ciphertext FROM native_private_settings WHERE club_id=? AND purpose='mail'",
            [$club],
        )->fetch();
        if (!$row) {
            throw new \DomainException("Zentraler Mailzugang fehlt.");
        }
        return $this->box->open("mail:" . $club, $row["ciphertext"]);
    }
    /** Local preparation only; neither SMTP nor any other network transport is opened. */
    public function prepare(string $club,array $message,string $purpose='newsletter'):array
    {
        try{$c=$this->configuration($club,$purpose);}catch(\Throwable){throw new DeliveryNotStarted('mail_configuration_missing');}
        try{$mail=MailMessage::compose($c,$message);}catch(\Throwable){throw new DeliveryNotStarted('mail_message_invalid');}
        return [$c,$mail];
    }
    /** Worker-only: credentials never returned through a web endpoint. */
    public function send(
        string $club,
        array $message,
        string $purpose = "newsletter",
    ): string {
        if (
            getenv("KULTURHUS_DISABLE_MAIL") === "1" ||
            !in_array(getenv("KULTURHUS_LOCAL_DEMO"),[false,"","0"],true) ||
            in_array(getenv("VEREIN_MODE"),["synthetic"],true) ||
            in_array(getenv("APP_ENV"),["local","local-synthetic","development","test"],true)
        ) {
            throw new DeliveryNotStarted('mail_delivery_disabled');
        }
        [$c,$mail]=$this->prepare($club,$message,$purpose);
        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $c["host"],
            $c["port"],
            $c["port"] === 465,
        );
        $transport->setUsername($c["username"]);
        $transport->setPassword($c["password"]);
        $transport->getStream()->setTimeout(15);
        if ($c["port"] === 587) {
            $transport->setRequireTls(true);
        }
        $sent = $transport->send($mail);
        return $sent?->getMessageId() ?? "";
    }
}
