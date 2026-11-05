<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

use PDO;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};

/** Personal credentials and tokens are authenticated ciphertext, never form values or audit text. */
final class Connection
{
    public function __construct(
        private PDO $db,
        private SecretBox $box,
        private ?Login $login = null,
    ) {
        $this->login ??= new Login();
    }

    private function row(string $club, bool $lock = false): array|false
    {
        return Db::run(
            $this->db,
            "SELECT version,ciphertext FROM native_private_settings WHERE club_id=? AND purpose='api-stadtlandfunk'" .
                ($lock
                    ? " FOR UPDATE"
                    : ($this->db->inTransaction()
                        ? " FOR SHARE"
                        : "")),
            [$club],
        )->fetch();
    }

    private function write(
        string $club,
        array $data,
        int $version,
        string $actor,
    ): void {
        Db::run(
            $this->db,
            "INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,'api-stadtlandfunk',?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by)",
            [
                $club,
                $version,
                $this->box->seal("api:" . $club . ":stadtlandfunk", $data),
                $actor,
            ],
        );
    }

    private function current(
        Access $access,
        int $version,
        bool $lock = false,
    ): array {
        $access->require("website", "manage");
        $club = $access->club();
        $row = $this->row($club, $lock);
        if (($row ? (int) $row["version"] : 0) !== $version) {
            throw new \DomainException(
                "Verbindungsstand wurde geändert. Bitte neu laden.",
            );
        }
        return $row
            ? $this->box->open(
                "api:" . $club . ":stadtlandfunk",
                $row["ciphertext"],
            )
            : [];
    }

    public function connect(
        Access $access,
        #[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password,
        int $version,
        bool $rememberPassword = true,
    ): void {
        $old = $this->current($access, $version);
        $club = $access->club();
        if (
            $password === "" &&
            empty($old["disconnected"]) &&
            ($old["username"] ?? null) === $username
        ) {
            $password = $old["password"] ?? "";
        }
        $tokens = $this->login->connect($username, $password);
        $identity = $this->login->account($tokens["access_token"]);
        Db::atomic($this->db, function () use (
            $access,
            $club,
            $tokens,
            $identity,
            $version,
            $username,
            $password,
            $rememberPassword,
        ) {
            $old = $this->current($access, $version, true);
            $u = $access->require("website", "manage");
            if ($u["club_id"] !== $club) {
                throw new \DomainException("Vereinszuordnung geändert.");
            }
            $same =
                empty($old["disconnected"]) &&
                ($old["account_id"] ?? null) === $identity["account_id"] &&
                ($old["home_area_id"] ?? null) === $identity["home_area_id"];
            $data = $tokens +
                $identity + [
                    "username" => $username,
                    "password" => $rememberPassword ? $password : "",
                    "actor_id" => $u["id"],
                    "authority" =>
                        $access instanceof
                        \Kulturhus\Native\Integration\ManagerAccess
                            ? "manager"
                            : "native",
                    "connection_id" => $same
                        ? $old["connection_id"] ?? bin2hex(random_bytes(16))
                        : bin2hex(random_bytes(16)),
                    "changed_at" => gmdate("Y-m-d H:i:s"),
                    "checked_at" => gmdate("Y-m-d H:i:s"),
                ];
            $this->write($club, $data, $version + 1, $u["id"]);
            if (!$same) {
                $this->clearActivity($club);
            }
        });
    }

    /** Refresh is serialized with the encrypted row, and never changes its target binding. */
    public function verify(Access $access, int $version): array
    {
        Db::atomic($this->db, function () use ($access, $version) {
            $data = $this->current($access, $version, true);
            $u = $access->require("website", "manage");
            if (empty($data["access_token"]) || !empty($data["disconnected"])) {
                throw new \DomainException("StadtLand.Funk zuerst verbinden.");
            }
            if (($data["expires_at"] ?? 0) <= time() + 60) {
                try {
                    if (
                        isset($data["refresh_expires_at"]) &&
                        $data["refresh_expires_at"] <= time()
                    ) {
                        throw new ReauthenticationRequired(
                            "Gemeldete Refresh-Laufzeit abgelaufen.",
                        );
                    }
                    $tokens = $this->login->refresh($data["refresh_token"]);
                } catch (ReauthenticationRequired) {
                    if (empty($data["username"]) || empty($data["password"])) {
                        throw new \DomainException(
                            "Token-Erneuerung abgelaufen. Bitte anmelden oder ein verschlüsseltes Passwort für erneute Anmeldungen hinterlegen.",
                        );
                    }
                    $tokens = $this->login->connect(
                        $data["username"],
                        $data["password"],
                    );
                }
                $data = array_replace($data, $tokens);
            }
            $identity = $this->login->account($data["access_token"]);
            if (
                ($data["account_id"] ?? "") !== $identity["account_id"] ||
                ($data["home_area_id"] ?? "") !== $identity["home_area_id"]
            ) {
                throw new \DomainException(
                    "Konto oder Heimatgebiet beim Anbieter geändert. Bitte ausdrücklich neu verbinden.",
                );
            }
            $data = array_replace($data, $identity, [
                "checked_at" => gmdate("Y-m-d H:i:s"),
            ]);
            $this->write($u["club_id"], $data, $version + 1, $u["id"]);
        });
        return $this->status($access);
    }

    /** Removes local tokens without revoking any other app login or existing public event. */
    public function disconnect(Access $access, int $version): void
    {
        Db::atomic($this->db, function () use ($access, $version) {
            $this->current($access, $version, true);
            $u = $access->require("website", "manage");
            if ($version === 0) {
                throw new \DomainException(
                    "Keine gespeicherte Verbindung vorhanden.",
                );
            }
            $this->write(
                $u["club_id"],
                ["disconnected" => true, "changed_at" => gmdate("Y-m-d H:i:s")],
                $version + 1,
                $u["id"],
            );
            $this->clearActivity($u["club_id"]);
        });
    }

    private function clearActivity(string $club): void
    {
        $archive=new \Kulturhus\Native\Communication\OriginalArchive($this->db,$this->box);
        $archive->retainWhere($club,'attention',"channel='stadtlandfunk'",[],'disconnected');
        $archive->retainWhere($club,'object',"channel='stadtlandfunk'",[],'disconnected');
        Db::run(
            $this->db,
            "DELETE FROM native_social_attention WHERE club_id=? AND channel='stadtlandfunk'",
            [$club],
        );
        Db::run(
            $this->db,
            "DELETE FROM native_social_checks WHERE club_id=? AND feature LIKE 'stadtlandfunk-%'",
            [$club],
        );
        Db::run(
            $this->db,
            "UPDATE native_social_objects SET comments_cipher=NULL,comments_at=NULL WHERE club_id=? AND channel='stadtlandfunk'",
            [$club],
        );
        Db::run(
            $this->db,
            "UPDATE native_social_check_plans SET enabled=0,next_at=NULL,state='paused',version=version+1 WHERE club_id=? AND channel='stadtlandfunk'",
            [$club],
        );
    }

    private function replyContext(Access $access, ?string $binding = null, bool $refresh = false, bool $publication = false): array
    {
        $access->require("social", $publication ? "publish" : "manage");
        $club = $access->club();
        $row = $this->row($club);
        if (!$row) {
            throw new \DomainException("StadtLand.Funk zuerst verbinden.");
        }
        $data = $this->box->open(
            "api:" . $club . ":stadtlandfunk",
            $row["ciphertext"],
        );
        if (!empty($data["disconnected"]) || empty($data["access_token"])) {
            throw new \DomainException("StadtLand.Funk zuerst verbinden.");
        }
        $owner = \Kulturhus\Native\Shared\Authority::resolve($this->db, [
            "created_by" => $data["actor_id"],
            "authority" => $data["authority"],
            "club_id" => $club,
        ]);
        $owner->require("website", "manage");
        if ($owner->club() !== $club) {
            throw new \DomainException(
                "Verbindungsverantwortlicher gehört nicht mehr zum Verein.",
            );
        }
        if ($refresh && ($data["expires_at"] ?? 0) <= time() + 60) {
            $this->verify($owner, (int) $row["version"]);
            $row = $this->row($club);
            $data = $this->box->open(
                "api:" . $club . ":stadtlandfunk",
                $row["ciphertext"],
            );
        }
        if ($binding !== null && ($data["connection_id"] ?? null) !== $binding) throw new \DomainException("Antwort gehört zu einer früheren StadtLand.Funk-Verbindung.");
        return [$club, $data, $owner];
    }

    /** No provider request; current owner and exact account binding are still required. */
    public function replyIdentity(Access $access, ?string $binding = null): array
    {
        [, $data] = $this->replyContext($access, $binding);
        $identity = [];
        foreach (['account_id', 'home_area_id', 'connection_id'] as $key) {
            if (!is_string($data[$key] ?? null) || !preg_match('/^[A-Za-z0-9_:-]{1,200}$/D', $data[$key])) throw new \DomainException('Persönliche Kontobindung nicht bestätigt. Neu verbinden.');
            $identity[$key] = $data[$key];
        }
        return $identity;
    }

    /** Called only after the caller's durable send intent; a write is never repeated. */
    public function reply(Access $access, string $binding, string $post, string $comment, string $text): array
    {
        $access->require('social', 'publish');
        [$club, $data, $owner] = $this->replyContext($access, $binding, true);
        $response = $this->login->reply($data['access_token'], $post, $comment, $text);
        $access->require('social', 'publish');
        $owner->require('website', 'manage');
        if ($access->club() !== $club || $owner->club() !== $club) throw new \DomainException('Vereinszuordnung während Antwort geändert.');
        $current = $this->replyIdentity($access, $binding);
        foreach ($current as $key => $value) if ($value !== $data[$key]) throw new \DomainException('Kontoziel während Antwort geändert.');
        return $response;
    }

    public function read(Access $access, string $path, ?string $binding = null): array
    {
        [$club, $data, $owner] = $this->replyContext($access, $binding, true);
        $response = $this->login->read($data["access_token"], $path);
        $access->require("social", "manage");
        $owner->require("website", "manage");
        $current = $this->row($club);
        if ($access->club() !== $club || $owner->club() !== $club) {
            throw new \DomainException(
                "Vereinszuordnung während Abruf geändert.",
            );
        }
        $now = $current
            ? $this->box->open(
                "api:" . $club . ":stadtlandfunk",
                $current["ciphertext"],
            )
            : [];
        if (
            !empty($now["disconnected"]) ||
            ($now["connection_id"] ?? null) !== $data["connection_id"]
        ) {
            throw new \DomainException(
                "Kontoverbindung während Abruf geändert.",
            );
        }
        return $response;
    }

    private function publicationCall(Access $access,string $binding,callable $call):array
    {
        $access->require('social','publish');[$club,$data,$owner]=$this->replyContext($access,$binding,true,true);
        $response=$call($data['access_token']);$access->require('social','publish');$owner->require('website','manage');
        if($access->club()!==$club||$owner->club()!==$club)throw new \DomainException('Vereinszuordnung während Veröffentlichung geändert.');
        $current=$this->publicationIdentity($access,$binding);foreach($current as$key=>$value)if($value!==$data[$key])throw new \DomainException('Kontoziel während Veröffentlichung geändert.');
        return $response;
    }

    public function publicationIdentity(Access $access,?string $binding=null):array
    {
        [, $data]=$this->replyContext($access,$binding,false,true);$identity=[];
        foreach(['account_id','home_area_id','connection_id']as$key){if(!is_string($data[$key]??null)||!preg_match('/\A[A-Za-z0-9_:-]{1,200}\z/D',$data[$key]))throw new \DomainException('Persönliche Kontobindung nicht bestätigt.');$identity[$key]=$data[$key];}
        return $identity;
    }

    public function publicationRead(Access $access,string $binding,string $path):array
    {return $this->publicationCall($access,$binding,fn($token)=>$this->login->read($token,$path,true));}

    public function upload(Access $access,string $binding,string $path,string $sha256):array
    {
        return $this->publicationCall($access,$binding,fn($token)=>$this->login->upload($token,$path,$sha256));
    }

    public function createEvent(Access $access,string $binding,array $event):array
    {
        return $this->publicationCall($access,$binding,fn($token)=>$this->login->createEvent($token,$event));
    }

    public function identity(Access $access): array
    {
        $access->require("social", "manage");
        $club = $access->club();
        $row = $this->row($club);
        if (!$row) {
            throw new \DomainException("StadtLand.Funk zuerst verbinden.");
        }
        $data = $this->box->open(
            "api:" . $club . ":stadtlandfunk",
            $row["ciphertext"],
        );
        if (!empty($data["disconnected"]) || empty($data["account_id"])) {
            throw new \DomainException("StadtLand.Funk zuerst verbinden.");
        }
        return [
            "account_id" => $data["account_id"],
            "home_area_id" => $data["home_area_id"],
            "connection_id" => $data["connection_id"],
        ];
    }

    public function status(Access $access): array
    {
        $access->require("website", "manage");
        $club = $access->club();
        $row = $this->row($club);
        $data = $row
            ? $this->box->open(
                "api:" . $club . ":stadtlandfunk",
                $row["ciphertext"],
            )
            : [];
        $configured =
            !empty($data["access_token"]) && empty($data["disconnected"]);
        return [
            "configured" => $configured,
            "version" => $row ? (int) $row["version"] : 0,
            "username" => $configured ? $data["username"] ?? "" : "",
            "password_saved" => $configured && !empty($data["password"]),
            "home_area_name" => $configured
                ? $data["home_area_name"] ?? ""
                : "",
            "expires_at" => $configured ? $data["expires_at"] ?? 0 : null,
            "refresh_expires_at" => $configured
                ? $data["refresh_expires_at"] ?? null
                : null,
            "needs_refresh" =>
                $configured && ($data["expires_at"] ?? 0) <= time() + 60,
            "changed_at" => $data["changed_at"] ?? null,
            "checked_at" => $configured ? $data["checked_at"] ?? null : null,
        ];
    }
}
