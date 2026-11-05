<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration\StadtLandFunk;

/** Uses the observed app PKCE login; credentials only reach the fixed provider login. */
final class Login
{
    public const CALLBACK = "https://www.stadtlandfunk.app/";
    public const CLIENT = "stadtlandfunk";
    public const APP = "de.fhg.iese.dd.dorffunk.slf";
    private \Closure $http;

    public function __construct(?callable $http = null)
    {
        $transport = new Http();
        $this->http = $http
            ? \Closure::fromCallable($http)
            : $transport->request(...);
    }

    private static function encoded(string $value): string
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }

    public function connect(
        #[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password,
    ): array {
        if (
            $username === "" ||
            strlen($username) > 254 ||
            preg_match('/[\x00-\x20\x7f]/', $username) ||
            $password === "" ||
            strlen($password) > 1024 ||
            str_contains($password, "\0")
        ) {
            throw new \DomainException("Anmeldename und Passwort prüfen.");
        }
        $verifier = self::encoded(random_bytes(48));
        $state = self::encoded(random_bytes(24));
        $query = http_build_query(
            [
                "client_id" => self::CLIENT,
                "redirect_uri" => self::CALLBACK,
                "response_type" => "code",
                "scope" => "openid profile email offline_access",
                "prompt" => "login",
                "state" => $state,
                "code_challenge" => self::encoded(
                    hash("sha256", $verifier, true),
                ),
                "code_challenge_method" => "S256",
            ],
            "",
            "&",
            PHP_QUERY_RFC3986,
        );
        $r = ($this->http)(
            "GET",
            Http::ISSUER . "/protocol/openid-connect/auth?" . $query,
            [],
            null,
        );
        if ($r["status"] !== 200 || !is_string($r["body"])) {
            throw new \DomainException(
                "Loginformular nicht verfügbar. Bitte später erneut verbinden.",
            );
        }
        $dom = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadHTML($r["body"], LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
        $forms = (new \DOMXPath($dom))->query('//form[@id="kc-form-login"]');
        if (!$ok || $forms->length !== 1) {
            throw new \DomainException(
                "Zusätzlicher Anmeldeschritt beim Anbieter erforderlich. Verbindung nicht gespeichert.",
            );
        }
        $form = $forms->item(0);
        $action = $form->getAttribute("action");
        if (
            !Http::allowed($action) ||
            !str_starts_with(
                $action,
                Http::ISSUER . "/login-actions/authenticate?",
            )
        ) {
            throw new \DomainException(
                "Loginformular hat sich geändert. Verbindung nicht gespeichert.",
            );
        }
        $values = [];
        foreach ($form->getElementsByTagName("input") as $input) {
            if (
                $input->getAttribute("type") === "hidden" &&
                $input->getAttribute("name") !== ""
            ) {
                $values[$input->getAttribute("name")] = $input->getAttribute(
                    "value",
                );
            }
        }
        $values["username"] = $username;
        $values["password"] = $password;
        $r = ($this->http)(
            "POST",
            $action,
            ["Content-Type: application/x-www-form-urlencoded"],
            http_build_query($values, "", "&", PHP_QUERY_RFC3986),
        );
        unset($values, $password, $username);
        $location = $r["headers"]["location"] ?? "";
        $callback = parse_url($location);
        $params = [];
        if (
            !in_array($r["status"], [302, 303], true) ||
            !$callback ||
            ($callback["scheme"] ?? "") !== "https" ||
            ($callback["host"] ?? "") !== "www.stadtlandfunk.app" ||
            !in_array($callback["path"] ?? "", ["", "/"], true) ||
            isset($callback["user"]) ||
            isset($callback["pass"]) ||
            isset($callback["port"]) ||
            isset($callback["fragment"])
        ) {
            throw new \DomainException(
                "Anmeldung nicht bestätigt. Zugangsdaten oder zusätzliche Anmeldeschritte beim Anbieter prüfen.",
            );
        }
        parse_str($callback["query"] ?? "", $params);
        if (
            ($params["state"] ?? null) !== $state ||
            !is_string($params["code"] ?? null) ||
            $params["code"] === "" ||
            ($params["iss"] ?? Http::ISSUER) !== Http::ISSUER
        ) {
            throw new \DomainException(
                "Anmelderücksprung nicht bestätigt. Verbindung nicht gespeichert.",
            );
        }
        return $this->token([
            "client_id" => self::CLIENT,
            "grant_type" => "authorization_code",
            "redirect_uri" => self::CALLBACK,
            "code" => $params["code"],
            "code_verifier" => $verifier,
        ]);
    }

    public function refresh(#[\SensitiveParameter] string $refresh): array
    {
        return $this->token([
            "client_id" => self::CLIENT,
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh,
        ]);
    }

    private function token(#[\SensitiveParameter] array $form): array
    {
        $r = ($this->http)(
            "POST",
            Http::ISSUER . "/protocol/openid-connect/token",
            ["Content-Type: application/x-www-form-urlencoded"],
            http_build_query($form, "", "&", PHP_QUERY_RFC3986),
        );
        if (
            ($form["grant_type"] ?? "") === "refresh_token" &&
            ($r["status"] ?? 0) === 400
        ) {
            try {
                $error = json_decode($r["body"], true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $error = [];
            }
            if (($error["error"] ?? "") === "invalid_grant") {
                throw new ReauthenticationRequired(
                    "Token-Erneuerung abgelaufen. Erneute Kontoanmeldung erforderlich.",
                );
            }
        }
        $data = self::json($r);
        foreach (["access_token", "refresh_token"] as $key) {
            if (
                !is_string($data[$key] ?? null) ||
                $data[$key] === "" ||
                strlen($data[$key]) > 20000 ||
                preg_match('/[\x00-\x20\x7f]/', $data[$key])
            ) {
                throw new \DomainException(
                    "Anbieter hat keinen gültigen Konto-Token geliefert. Bitte neu verbinden.",
                );
            }
        }
        $now = time();
        if (
            strcasecmp($data["token_type"] ?? "", "Bearer") !== 0 ||
            !is_int($data["expires_in"] ?? null) ||
            $data["expires_in"] < 1 ||
            $data["expires_in"] > PHP_INT_MAX - $now
        ) {
            throw new \DomainException(
                "Token-Laufzeit nicht bestätigt. Bitte neu verbinden.",
            );
        }
        $refresh = $data["refresh_expires_in"] ?? null;
        if (
            $refresh !== null &&
            (!is_int($refresh) || $refresh < 0 || $refresh > PHP_INT_MAX - $now)
        ) {
            throw new \DomainException(
                "Refresh-Laufzeit nicht bestätigt. Bitte neu verbinden.",
            );
        }
        return [
            "access_token" => $data["access_token"],
            "refresh_token" => $data["refresh_token"],
            "expires_at" => $now + $data["expires_in"],
            "refresh_expires_at" =>
                is_int($refresh) && $refresh > 0 ? $now + $refresh : null,
        ];
    }

    public function account(#[\SensitiveParameter] string $token): array
    {
        $r = ($this->http)(
            "GET",
            Http::API . "/app/onboardingStatus",
            [
                "Accept: application/json",
                "Authorization: Bearer " . $token,
                "appVariantIdentifier: " . self::APP,
            ],
            null,
        );
        $data = self::json($r);
        $person = $data["ownPerson"] ?? [];
        $area = $data["homeGeoArea"] ?? [];
        foreach ([$person["id"] ?? null, $area["id"] ?? null] as $id) {
            if (
                !is_string($id) ||
                !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id)
            ) {
                throw new \DomainException(
                    "Persönliches Konto und Heimatgebiet nicht bestätigt.",
                );
            }
        }
        if (
            ($data["homeGeoAreaSelectionStatus"] ?? "") !== "VALID" ||
            !empty($data["onboardingRequired"])
        ) {
            throw new \DomainException(
                "Zuerst die Kontoeinrichtung und Heimatgebietsauswahl in StadtLand.Funk abschließen.",
            );
        }
        $label = $area["name"] ?? "";
        if (
            !is_string($label) ||
            strlen($label) > 300 ||
            preg_match("//u", $label) !== 1
        ) {
            throw new \DomainException("Heimatgebiet nicht bestätigt.");
        }
        return [
            "account_id" => $person["id"],
            "home_area_id" => $area["id"],
            "home_area_name" => $label,
        ];
    }

    public function read(
        #[\SensitiveParameter] string $token,
        string $path,
        bool $publication = false,
    ): array {
        if (
            (!in_array(parse_url($path,PHP_URL_PATH),["/grapevine/post/own","/grapevine/comment"],true)
                && (!$publication || !preg_match('~\A/grapevine/post/[A-Za-z0-9_-]{1,50}\z~D',$path))) ||
            str_contains($path, "#") ||
            !str_starts_with($path, "/")
        ) {
            throw new \DomainException("Unbekannter persönlicher Abruf.");
        }
        return self::json(
            ($this->http)(
                "GET",
                Http::API . $path,
                [
                    "Accept: application/json",
                    "Authorization: Bearer " . $token,
                    "appVariantIdentifier: " . self::APP,
                ],
                null,
            ),
        );
    }

    /** The observed app comment endpoint; never retries a write. */
    public function reply(#[\SensitiveParameter] string $token, string $post, string $comment, string $text): array
    {
        foreach ([$post, $comment] as $id) if (!preg_match('/^[A-Za-z0-9_:-]{1,200}$/D', $id)) throw new \DomainException('Anbieterkennung ungültig.');
        if ($text === '' || preg_match('//u', $text) !== 1 || preg_match_all('/./us', $text) > 2000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) throw new \DomainException('Antworttext prüfen.');
        $response = ($this->http)('POST', Http::API . '/grapevine/comment/event/commentCreateRequest', [
            'Accept: application/json', 'Content-Type: application/json',
            'Authorization: Bearer ' . $token, 'appVariantIdentifier: ' . self::APP,
        ], json_encode(['postId' => $post, 'replyToCommentId' => $comment, 'text' => $text], JSON_THROW_ON_ERROR));
        // Only an explicit, completely readable provider error is a known rejection.
        if (in_array($response['status'] ?? 0, [400, 401, 403, 404, 405, 410, 413, 415, 422], true)) {
            try { $error = json_decode($response['body'] ?? '', true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { $error = null; }
            if (is_array($error) && !array_is_list($error) && (isset($error['error']) || isset($error['errorCode']))) throw new ReplyRejected('StadtLand.Funk hat die Antwort abgelehnt. Original und Verbindung prüfen.');
        }
        return self::json($response);
    }

    /** One exact approved JPEG upload; temporary upload and event creation are separate phases. */
    public function upload(#[\SensitiveParameter] string $token,string $path,string $sha256):array
    {
        if(is_link($path)||!is_file($path)||filesize($path)>8*1024*1024||!hash_equals($sha256,hash_file('sha256',$path)))throw new \DomainException('Freigegebenes Bild nicht verfügbar.');
        $image=getimagesize($path);if(($image['mime']??'')!=='image/jpeg')throw new \DomainException('Freigegebenes JPEG erforderlich.');
        $boundary='Dvm'.bin2hex(random_bytes(24));$bytes=file_get_contents($path);if($bytes===false||!hash_equals($sha256,hash('sha256',$bytes)))throw new \DomainException('Freigegebenes Bild nicht verfügbar.');
        $body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"publication.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
        return self::json(($this->http)('POST',Http::API.'/media/temp',['Accept: application/json','Content-Type: multipart/form-data; boundary='.$boundary,'Authorization: Bearer '.$token,'appVariantIdentifier: '.self::APP],$body));
    }

    /** Fixed observed app endpoint; a write is never automatically repeated. */
    public function createEvent(#[\SensitiveParameter] string $token,array $event):array
    {
        $keys=['title','text','startTime','endTime','temporaryMediaItemIds','customLocationDefinition','hiddenForOtherHomeAreas'];
        if(array_diff(array_keys($event),$keys)||!is_string($event['title']??null)||trim($event['title'])===''||!is_string($event['text']??null)||!is_int($event['startTime']??null)||$event['startTime']<=0||(isset($event['endTime'])&&(!is_int($event['endTime'])||$event['endTime']<=$event['startTime']))||($event['hiddenForOtherHomeAreas']??false)!==false||!is_array($event['temporaryMediaItemIds']??null)||!array_is_list($event['temporaryMediaItemIds'])||count($event['temporaryMediaItemIds'])!==1||!is_string($event['temporaryMediaItemIds'][0])||!preg_match('/\A[A-Za-z0-9_-]{1,50}\z/D',$event['temporaryMediaItemIds'][0])||!is_array($event['customLocationDefinition']??null))throw new \DomainException('Veranstaltungsfassung nicht bestätigt.');
        $location=$event['customLocationDefinition'];
        if(!is_string($location['locationName']??null)||trim($location['locationName'])===''||array_diff(array_keys($location),['locationName','locationLookupString','addressStreet','addressZip','addressCity']))throw new \DomainException('Ursprünglichen Veranstaltungsort prüfen.');
        foreach([$event['title'],$event['text'],...array_values($location)]as$text)if(!is_string($text)||preg_match('//u',$text)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))throw new \DomainException('Ursprüngliche Veranstaltungsangaben prüfen.');
        return self::json(($this->http)('POST',Http::API.'/grapevine/event/happeningCreateRequest',['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token,'appVariantIdentifier: '.self::APP],json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    }

    public static function json(array $response): array
    {
        if (
            $cooldown = \Kulturhus\Native\Integration\Cooldown::forResponse(
                $response,
            )
        ) {
            throw $cooldown;
        }
        if (
            ($response["status"] ?? 0) < 200 ||
            ($response["status"] ?? 0) >= 300
        ) {
            throw new \DomainException(
                "StadtLand.Funk-Zugang nicht bestätigt. Bitte Verbindung und Anbieterrechte prüfen.",
            );
        }
        try {
            $data = json_decode(
                $response["body"],
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            throw new \DomainException("StadtLand.Funk-Antwort nicht lesbar.");
        }
        if (!is_array($data)) {
            throw new \DomainException(
                "StadtLand.Funk-Antwort nicht bestätigt.",
            );
        }
        return $data;
    }
}
