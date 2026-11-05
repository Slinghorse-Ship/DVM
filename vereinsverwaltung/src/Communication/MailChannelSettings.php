<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Channel configuration is server-internal; only superusers may render or change it. */
final class MailChannelSettings
{
    public const CHANNELS = [
        "board" => "Vorstand",
        "press" => "Presse",
        "newsletter" => "Newsletter",
        "invoices" => "Rechnungen",
    ];
    public const PURPOSES = [
        "membership" => "Mitgliedschaft und Zugangsdaten",
        "password_reset" => "Passwort zurücksetzen",
        "tickets" => "Tickets und Wertgutscheine",
        "mfa" => "Sicherheitscodes (Zwei-Faktor)",
        "invitations" => "Einladungen und Vereinsnachrichten",
        "contributions" => "Beitragsrechnungen",
        "reminders" => "Zahlungserinnerungen",
        "press" => "Pressemitteilungen",
        "newsletter" => "Newsletter und Newsletter-Bestätigung",
        "invoices" => "Allgemeine Ausgangsrechnungen",
    ];
    public function __construct(
        private PDO $db,
        private string $actor,
        #[\SensitiveParameter] private string $key,
    ) {
        if (strlen($key) < 32) {
            throw new DomainException("Geschützter Serverschlüssel fehlt.");
        }
    }
    private function access(): array
    {
        $a = Permissions::actor($this->db, $this->actor);
        if (empty($a["is_superuser"])) {
            throw new DomainException(
                "Nur der Superuser darf Postfächer und Versandzuordnungen ändern.",403,
            );
        }
        return $a;
    }
    public static function defaults(): array
    {
        $channels = [];
        foreach (["board", "press", "newsletter"] as $channel) {
            $channels[$channel] = [
                "override" => false,
                "host" => "",
                "port" => "465",
                "user" => "",
                "from" => "",
                "name" =>
                    self::CHANNELS[$channel] . " · Mein Verein",
                "reply" => "",
            ];
        }
        return [
            "channels" => $channels,
            "routes" => [
                "membership" => "board",
                "password_reset" => "board",
                "tickets" => "board",
                "mfa" => "board",
                "invitations" => "board",
                "contributions" => "board",
                "reminders" => "board",
                "press" => "press",
                "newsletter" => "newsletter",
                "invoices" => "invoices",
            ],
        ];
    }
    private static function cryptKey(string $key): string
    {
        return hash_hkdf(
            "sha256",
            $key,
            32,
            "kulturhus-mail-channel-settings-v1",
        );
    }
    private static function decode(
        string $cipher,
        string $club,
        #[\SensitiveParameter] string $key,
    ): array {
        $raw = base64_decode($cipher, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            throw new DomainException(
                "Mailzugang kann nicht entschlüsselt werden.",503,
            );
        }
        $json = openssl_decrypt(
            substr($raw, 28),
            "aes-256-gcm",
            self::cryptKey($key),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            $club,
        );
        if (!is_string($json)) {
            throw new DomainException(
                "Mailzugang kann nicht entschlüsselt werden.",503,
            );
        }
        return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    }
    private function encode(#[\SensitiveParameter] array $values, string $club): string
    {
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt(
            json_encode($values, JSON_THROW_ON_ERROR),
            "aes-256-gcm",
            self::cryptKey($this->key),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $club,
        );
        if (!is_string($cipher)) {
            throw new DomainException(
                "Mailzugang konnte nicht geschützt werden.",503,
            );
        }
        return base64_encode($iv . $tag . $cipher);
    }
    private static function row(PDO $db, string $club,bool $write=false): array|false
    {
        $q = $db->prepare(
            "SELECT * FROM mail_channel_settings WHERE club_id=?" .
                ($db->inTransaction() ? ($write?" FOR UPDATE NOWAIT":" FOR SHARE NOWAIT") : ""),
        );
        $q->execute([$club]);
        return $q->fetch();
    }
    private static function configData(array|false $row): array
    {
        if (!$row) {
            return self::defaults();
        }
        try {
            $data = json_decode(
                (string) $row["config"],
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            throw new DomainException(
                "Versandkonfiguration ist beschädigt. Bitte Postfächer in den Einstellungen öffnen und speichern.",
                503,
                $e,
            );
        }
        if (
            !is_array($data) ||
            !is_array($data["channels"] ?? null) ||
            !is_array($data["routes"] ?? null)
        ) {
            throw new DomainException(
                "Versandkonfiguration ist unvollständig. Bitte Postfächer in den Einstellungen öffnen und speichern.",503,
            );
        }
        // Existing saved routing predates ticket confirmations. Retain every original
        // channel choice and add only this newly introduced purpose in memory.
        $data["routes"]["tickets"] ??= self::defaults()["routes"]["tickets"];
        return $data;
    }
    public function state(): array
    {
        return $this->read(function():array{
            $a=$this->access();$r=self::row($this->db,$a["club_id"]);
            return ["version"=>(int)($r["version"]??0),"data"=>self::configData($r)];
        });
    }
    public static function formValues(array $input, array $saved): array
    {
        $out = $saved;
        foreach ($out["channels"] as $channel => &$row) {
            $posted = is_array($input["channels"][$channel] ?? null)
                ? $input["channels"][$channel]
                : [];
            foreach ($row as $key => $value) {
                $row[$key] =
                    $key === "override"
                        ? in_array($posted[$key] ?? false, [true, 1, "1"], true)
                        : (is_string($posted[$key] ?? null)
                            ? $posted[$key]
                            : $value);
            }
        }
        unset($row);
        foreach ($out["routes"] as $purpose => &$channel) {
            if (is_string($input["routes"][$purpose] ?? null)) {
                $channel = $input["routes"][$purpose];
            }
        }
        unset($channel);
        return $out;
    }
    private function plan(#[\SensitiveParameter] array $input,array $prior,#[\SensitiveParameter] array $secrets):array
    {
    $data = self::defaults();
    foreach ($data["channels"] as $channel => &$row) {
        $posted = $input["channels"][$channel] ?? null;
        if (!is_array($posted)) {
            throw new DomainException(
                "Alle Postfachangaben vollständig übermitteln.",422);
        }
        foreach ($row as $name => $default) {
            $value =
                $posted[$name] ??
                ($name === "override" ? false : $default);
            if ($name === "override") {
                $row[$name] = SettingsSchema::bool($value);
                continue;
            }
            if (
                !is_string($value) ||
                preg_match('//u', $value) !== 1 ||
                ($name === "name" ? mb_strlen($value, "UTF-8") : strlen($value)) > 254 ||
                preg_match('/[\x00-\x1f\x7f]/', $value)
            ) {
                throw new DomainException(
                    self::CHANNELS[$channel] . ": Angaben prüfen.",422);
            }
            $row[$name] = trim($value);
        }
        if (
            !in_array($row["port"], ["465", "587"], true) ||
            ($row["host"] !== "" &&
                !preg_match(
                    '/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/iD',
                    $row["host"],
                )) ||
            str_ends_with(strtolower($row["host"]), ".localhost")
        ) {
            throw new DomainException(
                self::CHANNELS[$channel] .
                    ": Öffentlichen SMTP-Host und TLS-Port prüfen.",422);
        }
        foreach (["from", "reply"] as $field) {
            if (
                $row[$field] !== "" &&
                !filter_var($row[$field], FILTER_VALIDATE_EMAIL)
            ) {
                throw new DomainException(
                    self::CHANNELS[$channel] .
                        ": E-Mail-Adresse prüfen.",422);
            }
        }
        $password = $posted["password"] ?? "";
        if (
            !is_string($password) ||
            strlen($password) > 1000 ||
            preg_match('//u', $password) !== 1 ||
            preg_match('/[\x00\r\n]/', $password)
        ) {
            throw new DomainException("Postfachpasswort prüfen.",422);
        }
        $clear = SettingsSchema::bool($posted["clear_password"] ?? false);
        if ($clear && $password !== "") {
            throw new DomainException("Passwort entweder neu eingeben oder ausdrücklich entfernen.",422);
        }
        if (
            ($prior["channels"][$channel]["host"] !==
                $row["host"] ||
                $prior["channels"][$channel]["user"] !==
                    $row["user"]) &&
            ($secrets[$channel] ?? "") !== "" &&
            $password === "" &&
            !$clear
        ) {
            throw new DomainException(
                self::CHANNELS[$channel] .
                    ": Bei neuem Server oder Benutzernamen das Passwort neu eingeben oder ausdrücklich entfernen.",422);
        }
        if ($clear) {
            $secrets[$channel] = "";
        } elseif ($password !== "") {
            $secrets[$channel] = $password;
        }
        if (
            $row["override"] &&
            ($row["host"] === "" ||
                $row["user"] === "" ||
                $row["from"] === "" ||
                ($secrets[$channel] ?? "") === "")
        ) {
            throw new DomainException(
                self::CHANNELS[$channel] .
                    ": Zum Verwenden alle Angaben und das Passwort eintragen.",422);
        }
    }
    unset($row);
    foreach ($data["routes"] as $purpose => &$channel) {
        $value = $input["routes"][$purpose] ?? null;
        if (!is_string($value) || !isset(self::CHANNELS[$value])) {
            throw new DomainException(
                "Für jeden Versandzweck ein Postfach auswählen.",422);
        }
        $channel = $value;
    }
    unset($channel);
        return ['data'=>$data,'secrets'=>$secrets];
    }
    private function read(callable $operation):array
    {
        $own=!$this->db->inTransaction();
        try{if($own)$this->db->exec('START TRANSACTION');$result=$operation();if($own)$this->db->commit();return $result;}
        catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();if($failure instanceof \PDOException)$this->busy($failure);throw $failure;}
    }
    private function busy(\PDOException $failure):never
    {
        if(in_array((int)($failure->errorInfo[1]??0),[1205,3572],true))throw new DomainException('Postfächer werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);
        throw $failure;
    }
    /** Redacted current configuration and credential presence; never return secret values. */
    public function reviewContext():array
    {
        return $this->read(function():array{
            $a=$this->access();$old=self::row($this->db,$a['club_id']);$secrets=$old?self::decode($old['secrets'],$a['club_id'],$this->key):[];
            $present=[];foreach(array_keys(self::defaults()['channels'])as$channel)$present[$channel]=is_string($secrets[$channel]??null)&&$secrets[$channel]!=='';
            return ['version'=>(int)($old['version']??0),'data'=>self::configData($old),'passwords'=>$present,'credential_binding'=>$old?hash_hmac('sha256',$old['secrets'],self::cryptKey($this->key)):''];
        });
    }
    public function review(#[\SensitiveParameter] array $input,int $version):array
    {
        return $this->read(function()use($input,$version):array{
            $a=$this->access();$old=self::row($this->db,$a['club_id']);
            if((int)($old['version']??0)!==$version)throw new DomainException('Postfächer wurden inzwischen geändert. Bitte neu laden und vergleichen.',409);
            $plan=$this->plan($input,self::configData($old),$old?self::decode($old['secrets'],$a['club_id'],$this->key):[]);
            $present=[];foreach(array_keys(self::defaults()['channels'])as$channel)$present[$channel]=is_string($plan['secrets'][$channel]??null)&&$plan['secrets'][$channel]!=='';
            return ['data'=>$plan['data'],'passwords'=>$present];
        });
    }
    public function save(#[\SensitiveParameter] array $input,int $version):void
    {
        $q=$this->db->prepare('SELECT club_id FROM actors WHERE id=?');$q->execute([$this->actor]);$club=$q->fetchColumn();
        if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{\Kulturhus\Verein\Infrastructure\Database\Transactions::run($this->db,function()use($input,$version,$club):void{
            $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
            $a=$this->access();if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
            $old=self::row($this->db,$club,true);
            if((int)($old['version']??0)!==$version)throw new DomainException('Postfächer wurden inzwischen geändert. Bitte neu laden und vergleichen.',409);
            $plan=$this->plan($input,self::configData($old),$old?self::decode($old['secrets'],$club,$this->key):[]);
            $this->db->prepare('INSERT INTO mail_channel_settings VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),config=VALUES(config),secrets=VALUES(secrets),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute([$club,$version+1,json_encode($plan['data'],JSON_THROW_ON_ERROR),$this->encode($plan['secrets'],$club),$a['id'],gmdate('c')]);
            $this->db->prepare("INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,'mail-channels.saved','mail-channels',?,?)")->execute([$club,$a['id'],$version+1,gmdate('c')]);
        });}catch(\PDOException $failure){$this->busy($failure);}
    }
    /** Explicit transfer into the selected common route; never replaces another saved access. */
    public function adoptNative(#[\SensitiveParameter] array $smtp, int $version): void
    {
        $q=$this->db->prepare('SELECT club_id FROM actors WHERE id=?');$q->execute([$this->actor]);$club=$q->fetchColumn();
        if(!is_string($club)||$club==='')throw new DomainException('Eigenen aktiven Verein auswählen.',403);
        try{\Kulturhus\Verein\Infrastructure\Database\Transactions::run(
            $this->db,
            function () use ($smtp, $version,$club): void {
                $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$club]);if(!$q->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);
                $a = $this->access();if($a['club_id']!==$club)throw new DomainException('Vereinszuordnung inzwischen geändert.',403);
                $old = self::row($this->db, $a["club_id"],true);
                if ((int) ($old["version"] ?? 0) !== $version) {
                    throw new DomainException(
                        "Postfächer wurden inzwischen geändert. Bitte neu laden.",
                    );
                }
                $data = self::configData($old);
                $channel = $data["routes"]["newsletter"];
                if ($channel === "invoices") {
                    throw new DomainException(
                        "Für die Übernahme zuerst ein freies SMTP-Postfach dem Newsletter zuordnen. Das Rechnungsfach bleibt unverändert.",
                    );
                }
                $current = $data["channels"][$channel];
                $secrets = $old
                    ? self::decode($old["secrets"], $a["club_id"], $this->key)
                    : [];
                if (
                    $current["override"] ||
                    $current["host"] !== "" ||
                    $current["user"] !== "" ||
                    $current["from"] !== "" ||
                    ($secrets[$channel] ?? "") !== ""
                ) {
                    throw new DomainException(
                        "Das gewählte gemeinsame Postfach enthält bereits eigene Angaben. Bitte Zugänge in den Postfacheinstellungen vergleichen.",
                    );
                }
                $data["channels"][$channel] = [
                    "override" => true,
                    "host" => $smtp["host"],
                    "port" => (string) $smtp["port"],
                    "user" => $smtp["username"],
                    "from" => $smtp["from"],
                    "name" => $current["name"],
                    "reply" => $smtp["reply_to"],
                    "password" => $smtp["password"],
                ];
                $this->save($data, $version);
            },
        );}catch(\PDOException $failure){$this->busy($failure);}
    }
    /** Internal runtime API. Never serialize its result into HTML, logs or API responses. */
    public static function resolve(
        PDO $db,
        string $club,
        #[\SensitiveParameter] string $key,
        string $purpose,
    ): array {
        if (!isset(self::PURPOSES[$purpose])) {
            throw new DomainException("Unbekannter Versandzweck.");
        }
        $r = self::row($db, $club);
        $data = self::configData($r);
        $channel = $data["routes"][$purpose];
        if ($channel === "invoices") {
            return [
                "channel" => $channel,
                "override" => InvoiceMailbox::smtpConfiguration(
                    $db,
                    $club,
                    $key,
                ),
            ];
        }
        $config = $data["channels"][$channel];
        if (!$config["override"]) {
            return ["channel" => $channel, "override" => null];
        }
        $secrets = self::decode($r["secrets"], $club, $key);
        if (($secrets[$channel] ?? "") === "") {
            throw new DomainException(
                "Aktiver Mailzugang ist nicht vollständig.",
            );
        }
        return [
            "channel" => $channel,
            "override" => [
                "host" => $config["host"],
                "port" => (int) $config["port"],
                "user" => $config["user"],
                "password" => $secrets[$channel],
                "from" => $config["from"],
                "name" => $config["name"],
                "reply" => $config["reply"],
            ],
        ];
    }
}
