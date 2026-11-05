<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;

use PDO;
use Kulturhus\Native\Shared\{Access, Db, SecretBox};

/** Provider secrets are authenticated ciphertext; only public account details reach forms. */
final class Accounts
{
    private const FIELDS = [
        "eventbrite" => ["organization", "token"],
        "sumup" => [
            "merchant_code",
            "token",
            "affiliate_app_id",
            "affiliate_key",
            "reader_id",
            "reader_name",
            "reader_list",
        ],
        "paypal" => ["client_id", "client_secret", "merchant_id", "mode"],
        "meta" => ["page_id", "instagram_id", "token", "api_version"],
        "stripe" => ["account_id", "mode", "secret_key", "webhook_secret"],
    ];
    private const SECRETS = ["token", "affiliate_key", "client_secret", "secret_key", "webhook_secret"];

    public function __construct(private PDO $db, private SecretBox $box) {}

    /** Named presets are selected per register; no sale has to type a provider identifier. */
    public static function readers(array $account):array
    {
        $choices=[];$legacy=$account['reader_id']??'';$legacyName=$account['reader_name']??'';if(is_string($legacy)&&$legacy!=='')$choices[$legacy]=is_string($legacyName)&&$legacyName!==''?$legacyName:'Vereins-Kartenleser';
        $list=$account['reader_list']??'';if(!is_string($list))throw new \DomainException('Kartenleserliste prüfen.');
        foreach(preg_split('/\R/u',$list)?:[]as$line){if(trim($line)==='')continue;$parts=explode('|',$line);if(count($parts)!==2)throw new \DomainException('Kartenleser je Zeile als Name | Kennung eintragen.');[$name,$id]=array_map('trim',$parts);if($name===''||strlen($name)>160||preg_match('/[\x00-\x1f\x7f]/',$name)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id))throw new \DomainException('Kartenleser je Zeile mit einem Namen und gültiger Kennung eintragen.');if(isset($choices[$id])&&$choices[$id]!==$name)throw new \DomainException('Eine Kartenleser-Kennung hat mehrere Namen. Bitte eindeutig eintragen.');if(in_array($name,$choices,true)&&($choices[$id]??null)!==$name)throw new \DomainException('Bitte jedem Kartenleser einen eigenen Namen geben.');$choices[$id]=$name;if(count($choices)>30)throw new \DomainException('Höchstens 30 Kartenleser hinterlegen.');}
        return $choices;
    }

    private static function fields(string $provider): array
    {
        return self::FIELDS[$provider] ??
            throw new \DomainException("Unbekannter Dienst.");
    }

    /** Blank secret fields retain the existing secret only for the same target account. */
    public function save(
        Access $access,
        string $provider,
        #[\SensitiveParameter] array $input,
        int $version,
    ): void {
        $access->require("website", "manage");
        $fields = self::fields($provider);
        Db::atomic($this->db, function () use (
            $access,
            $provider,
            $input,
            $version,
            $fields,
        ) {
            $u = $access->require("website", "manage");
            $club = $u["club_id"];
            $row = $this->row($club, $provider, true);
            if (($row ? (int) $row["version"] : 0) !== $version) {
                throw new \DomainException(
                    "Verbindungsstand wurde geändert. Bitte neu laden.",
                );
            }
            $old = $row
                ? $this->box->open(
                    "api:" . $club . ":" . $provider,
                    $row["ciphertext"],
                )
                : [];
            $payload = [];
            foreach ($fields as $field) {
                $value = $input[$field] ?? (in_array($field,['reader_id','reader_name','reader_list'],true)&&!array_key_exists($field,$input)?($old[$field]??''):'');
                if (
                    !is_string($value) ||
                    strlen($value) > 4096 ||
                    preg_match($field==='reader_list'?'/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/':($field==='reader_name'?'/[\\x00-\\x1f\\x7f]/':'/[\\x00-\\x20\\x7f]/'), $value)
                ) {
                    throw new \DomainException("Verbindungsangaben prüfen.");
                }
                $payload[$field] = $value;
            }
            $same = !empty($old) && empty($old["disconnected"]);
            foreach (
                match ($provider) {
                    "meta" => ["page_id", "instagram_id"],
                    "eventbrite" => ["organization"],
                    "sumup" => ["merchant_code"],
                    "paypal" => ["client_id", "merchant_id"],
                    "stripe" => ["account_id", "mode"],
                }
                as $field
            ) {
                $same = $same && ($old[$field] ?? "") === $payload[$field];
            }
            if ($same) {
                foreach (self::SECRETS as $field) {
                    if (
                        array_key_exists($field, $payload) &&
                        $payload[$field] === ""
                    ) {
                        $payload[$field] = $old[$field] ?? "";
                    }
                }
            }
            if (
                $provider === "eventbrite" &&
                (!preg_match('/^[0-9]{1,40}$/D', $payload["organization"]) ||
                    $payload["token"] === "")
            ) {
                throw new \DomainException(
                    "Eventbrite-Organisation und Token erforderlich.",
                );
            }
            if (
                $provider === "sumup" &&
                (!preg_match(
                    '/^[A-Za-z0-9_-]{1,60}$/D',
                    $payload["merchant_code"],
                ) ||
                    $payload["token"] === "")
            ) {
                throw new \DomainException(
                    "SumUp-Händler und Token erforderlich.",
                );
            }
            if($provider==='sumup'){
                if(($payload['reader_id']??'')!==''&&!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$payload['reader_id']))throw new \DomainException('Kartenleser-Kennung prüfen.');
                if(strlen($payload['reader_name']??'')>160)throw new \DomainException('Bitte einen kürzeren Namen für den Kartenleser wählen.');
                self::readers($payload);
                if(!empty($old)&&($old['merchant_code']??'')!==$payload['merchant_code'])foreach(['reader_id','reader_name','reader_list']as$field)if(!array_key_exists($field,$input))$payload[$field]='';
            }
            if (
                $provider === "paypal" &&
                (!in_array($payload["mode"], ["sandbox", "live"], true) ||
                    $payload["client_id"] === "" ||
                    $payload["client_secret"] === "" ||
                    !preg_match('/^[A-Z0-9]{1,64}$/D', $payload["merchant_id"]))
            ) {
                throw new \DomainException(
                    "PayPal-Modus, Händlerkennung und Zugang erforderlich.",
                );
            }
            if (
                $provider === "meta" &&
                (!preg_match('/^[0-9]{1,40}$/D', $payload["page_id"]) ||
                    !preg_match(
                        '/^(?:[0-9]{1,40})?$/D',
                        $payload["instagram_id"],
                    ) ||
                    !preg_match(
                        '/^v[0-9]{1,2}\\.0$/D',
                        $payload["api_version"],
                    ) ||
                    $payload["token"] === "")
            ) {
                throw new \DomainException(
                    "Meta-Konto und API-Version prüfen. Bei neuem Zielkonto einen neuen Zugangstoken eingeben.",
                );
            }
            if($provider==='stripe'&&(!preg_match('/^acct_[A-Za-z0-9]{1,80}$/D',$payload['account_id'])||!in_array($payload['mode'],['test','live'],true)||!preg_match('/^(?:sk|rk)_'.$payload['mode'].'_[A-Za-z0-9]{1,200}$/D',$payload['secret_key'])||($payload['webhook_secret']!==''&&!preg_match('/^whsec_[A-Za-z0-9]{1,200}$/D',$payload['webhook_secret']))))throw new \DomainException('Stripe-Konto, Modus und privaten Schlüssel prüfen. Bei anderem Konto oder Modus neue Schlüssel eingeben.');
            $payload["connection_id"] = $same
                ? self::binding($old)
                : bin2hex(random_bytes(16));
            $payload["changed_at"] = gmdate("Y-m-d H:i:s");
            $this->write($club, $provider, $payload, $version + 1, $u["id"]);
            if ($provider === "meta") {
                $this->clearMeta($club);
            }
        });
    }

    private function row(
        string $club,
        string $provider,
        bool $lock = false,
    ): array|false {
        return Db::run(
            $this->db,
            "SELECT version,ciphertext FROM native_private_settings WHERE club_id=? AND purpose=?" .
                ($lock
                    ? " FOR UPDATE"
                    : ($this->db->inTransaction()
                        ? " FOR SHARE"
                        : "")),
            [$club, "api-" . $provider],
        )->fetch();
    }

    private function write(
        string $club,
        string $provider,
        array $payload,
        int $version,
        string $actor,
    ): void {
        Db::run(
            $this->db,
            "INSERT INTO native_private_settings(club_id,purpose,version,ciphertext,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version),ciphertext=VALUES(ciphertext),updated_by=VALUES(updated_by)",
            [
                $club,
                "api-" . $provider,
                $version,
                $this->box->seal("api:" . $club . ":" . $provider, $payload),
                $actor,
            ],
        );
    }

    private function clearMeta(string $club): void
    {
        Db::run(
            $this->db,
            "DELETE FROM native_social_checks WHERE club_id=? AND (feature LIKE 'facebook-%' OR feature LIKE 'instagram-%')",
            [$club],
        );
        // Native inbox originals keep their exact previous account binding.
        $archive=new \Kulturhus\Native\Communication\OriginalArchive($this->db,$this->box);
        $archive->retainWhere($club,'attention',"channel IN ('facebook','instagram')",[],'disconnected');
        $archive->retainWhere($club,'object',"channel IN ('facebook','instagram')",[],'disconnected');
        Db::run(
            $this->db,
            "DELETE FROM native_social_attention WHERE club_id=? AND channel IN ('facebook','instagram')",
            [$club],
        );
        Db::run(
            $this->db,
            "UPDATE native_social_objects SET comments_cipher=NULL,comments_at=NULL,insights_json=NULL,insights_at=NULL WHERE club_id=? AND channel IN ('facebook','instagram')",
            [$club],
        );
        Db::run(
            $this->db,
            "UPDATE native_social_check_plans SET enabled=0,next_at=NULL,state='paused',version=version+1 WHERE club_id=? AND channel='meta'",
            [$club],
        );
    }

    /** A retained tombstone keeps versions monotonic and prevents reconnecting stale forms. */
    public function disconnect(
        Access $access,
        string $provider,
        int $version,
    ): void {
        $access->require("website", "manage");
        self::fields($provider);
        Db::atomic($this->db, function () use ($access, $provider, $version) {
            $u = $access->require("website", "manage");
            $club = $u["club_id"];
            $row = $this->row($club, $provider, true);
            if (!$row || (int) $row["version"] !== $version) {
                throw new \DomainException(
                    "Verbindungsstand wurde geändert. Bitte neu laden.",
                );
            }
            $this->write(
                $club,
                $provider,
                ["disconnected" => true, "changed_at" => gmdate("Y-m-d H:i:s")],
                $version + 1,
                $u["id"],
            );
            if ($provider === "meta") {
                $this->clearMeta($club);
                Db::run(
                    $this->db,
                    "UPDATE native_delivery_jobs SET status='cancelled',reason='account_disconnected' WHERE club_id=? AND kind='meta' AND status='pending'",
                    [$club],
                );
            }
        });
    }

    public static function binding(array $account): string
    {
        return $account["connection_id"] ??
            hash(
                "sha256",
                "legacy:" .
                    ($account["page_id"] ?? "") .
                    ":" .
                    ($account["instagram_id"] ?? ""),
            );
    }

    public function get(string $club, string $provider, ?int &$revision = null): array
    {
        self::fields($provider);
        $row = $this->row($club, $provider);
        if (!$row) {
            throw new \DomainException("Verbindung nicht eingerichtet.");
        }
        $payload = $this->box->open(
            "api:" . $club . ":" . $provider,
            $row["ciphertext"],
        );
        if (!empty($payload["disconnected"])) {
            throw new \DomainException(
                "Verbindung wurde getrennt. Bitte neu verbinden.",
            );
        }
        $revision=(int)$row['version'];
        return $payload;
    }

    public function status(Access $access, string $provider): array
    {
        $access->require("website", "manage");
        $fields = self::fields($provider);
        $club = $access->club();
        $row = $this->row($club, $provider);
        $payload = $row
            ? $this->box->open(
                "api:" . $club . ":" . $provider,
                $row["ciphertext"],
            )
            : [];
        $configured = (bool) $row && empty($payload["disconnected"]);
        $details = [];
        if ($configured) {
            foreach ($fields as $field) {
                if (!in_array($field, self::SECRETS, true)) {
                    $details[$field] = $payload[$field] ?? "";
                }
            }
        }
        return [
            "configured" => $configured,
            "version" => $row ? (int) $row["version"] : 0,
            "details" => $details,
            "changed_at" => $payload["changed_at"] ?? null,
        ];
    }
}
