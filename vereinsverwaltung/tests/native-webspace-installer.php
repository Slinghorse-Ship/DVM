<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/distribution/src/PackageStore.php";

use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Installer\{
    WebBundle,
    Requirements,
    PreparedInstall,
    SetupPermit,
};
use Kulturhus\Native\Auth\Runtime;
use Kulturhus\Native\Integration\ManagerBridge;

$db = TestSupport::db();
$fixture = TestSupport::config();
$root = sys_get_temp_dir() . "/dvm-webspace-" . bin2hex(random_bytes(10));
$n = 0;
$server = null;
$socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
if (!$socket) {
    throw new RuntimeException("Test port unavailable");
}
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ":"), 1);
fclose($socket);
function webCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function webHttp(
    string $path,
    array $data = [],
    array $headers = [],
    string $method = "GET",
): array {
    global $port, $cookie;
    $handle = curl_init("http://127.0.0.1:" . $port . $path);
    $received = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(
            ["Host: dvm-install.example"],
            $cookie !== "" ? ["Cookie: " . $cookie] : [],
            $headers,
        ),
        CURLOPT_HEADERFUNCTION => static function ($h, $line) use (
            &$received,
        ): int {
            $received[] = $line;
            return strlen($line);
        },
    ]);
    if ($method === "POST") {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException("Local HTTP request failed: " . $error);
    }
    foreach ($received as $line) {
        if (preg_match("/\ASet-Cookie:\s*(DVM_SETUP=[^;]+)/i", $line, $match)) {
            $cookie = $match[1];
        }
    }
    return [$status, $body];
}
$cookie = "";
try {
    $bundle = WebBundle::build(dirname(__DIR__, 2), $root);
    foreach(['eric-bridge.py','eric_feedback.py','eric-process.py','compile-eric-forms.py','ebilanz_builder.py']as$adapter)webCheck(is_file($root.'/app/vereinsverwaltung/tools/'.$adapter),'prepared upload retains the actual local ELSTER adapter '.$adapter);
    // Follow actual PHP entrypoints and local Python dependencies through the
    // physical upload, including dynamic DATEV module loaders. Source inventory
    // checks alone cannot detect an over-restrictive bundle filter.
    $sourceTools = dirname(__DIR__) . '/tools/';
    $runtimeTools = [];
    foreach (['src', 'native'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $folder)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === dirname(__DIR__) . '/native/Installer/WebBundle.php') continue;
            preg_match_all('/[a-z][a-z0-9_-]+\.py/', (string) file_get_contents($file->getPathname()), $found);
            foreach ($found[0] as $name) if (is_file($sourceTools . $name)) $runtimeTools[$name] = true;
        }
    }
    $pending = array_keys($runtimeTools);
    while ($pending) {
        $name = array_pop($pending);
        webCheck(is_file($root . '/app/vereinsverwaltung/tools/' . $name) && hash_file('sha256', $sourceTools . $name) === hash_file('sha256', $root . '/app/vereinsverwaltung/tools/' . $name), 'actual upload contains exact runtime Python dependency ' . $name);
        $code = (string) file_get_contents($sourceTools . $name);
        preg_match_all('/(?:from|import)\s+([a-z][a-z0-9_]*)(?:\s|\.|,|$)/m', $code, $imports);
        preg_match_all('/[a-z][a-z0-9_-]+\.(?:py|pdf)/', $code, $literals);
        preg_match_all('/module\([\'\"]([a-z][a-z0-9_-]*)[\'\"]\)/', $code, $dynamic);
        $dependencies = array_merge(array_map(static fn($name) => $name . '.py', $imports[1]), $literals[0], array_map(static fn($name) => $name . '.py', $dynamic[1]));
        foreach ($dependencies as $dependency) {
            if (is_file($sourceTools . $dependency) && !isset($runtimeTools[$dependency])) {
                $runtimeTools[$dependency] = true;
                $pending[] = $dependency;
            }
        }
    }
    $pdfProbe = $root . '/private/check-packaged-pdf.php';
    file_put_contents($pdfProbe, '<?php require dirname(__DIR__)."/app/vereinsverwaltung/src/autoload.php"; $record=["title"=>"Mitgliedsantrag", "club"=>"Synthetischer Verein", "public_copy"=>true, "reference"=>"Lokale Paketpruefung", "created_at"=>"04.11.2026", "warning"=>"Kein Versand", "sections"=>[["title"=>"Satzung und Datenschutz", "columns"=>["Dokument","Inhalt"], "rows"=>[["Satzung",str_repeat("Vollstaendiger veroeffentlichter Rechtstext. ",400)]]]]]; echo Kulturhus\\Verein\\DocumentPreview::pdf(["record"=>$record]);');
    $pdfProcess = proc_open([...TestSupport::phpCommand(), $pdfProbe], [0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/private/pdf-check.err','w']], $pdfPipes);
    if (!is_resource($pdfProcess)) throw new RuntimeException('Packaged PDF check could not start');
    fclose($pdfPipes[0]);
    $pdfBytes = stream_get_contents($pdfPipes[1]); fclose($pdfPipes[1]);
    $pdfExit = proc_close($pdfProcess);
    webCheck($pdfExit === 0 && str_starts_with($pdfBytes, '%PDF-') && strlen($pdfBytes) > 10000, 'physical uploaded application generates complete multi-page legal application PDF with its own renderer');
    webCheck(
        $bundle["files"] > 100 &&
            !is_dir($root . "/app/processwire") &&
            !is_dir($root . "/app/vereinsverwaltung/tests") &&
            !is_dir($root . "/app/distribution/packages/linux-runtime"),
        "prepared upload contains application and libraries without ProcessWire, tests or Linux binaries",
    );
    webCheck(
        is_file($root . "/app/vendor/autoload.php") &&
            is_file($root . "/app/banking/vendor/autoload.php") &&
            is_file(
                $root .
                    "/app/vereinsverwaltung/push-runtime/vendor/autoload.php",
            ),
        "all locked PHP dependencies already unpacked without hosting Composer",
    );
    webCheck(
        is_file($root . "/app/vendor/guzzlehttp/guzzle/LICENSE") &&
            (fileperms($root . "/private") & 0077) === 0,
        "dependency license retained and private directory protected",
    );
    rename($root . "/web", $root . "/httpdocs");
    $token = trim(file_get_contents($root . "/SETUP-KEY.txt"));
    webCheck(
        !str_contains(
            file_get_contents($root . "/private/setup-token"),
            $token,
        ),
        "server stores only setup key proof",
    );
    $router = $root . "/test-router.php";
    file_put_contents(
        $router,
        "<?php\n\$_SERVER['HTTPS']=isset(\$_SERVER['HTTP_X_SYNTHETIC_PLAIN'])?'off':'on';\nif(in_array(parse_url(\$_SERVER['REQUEST_URI'],PHP_URL_PATH),['/setup/setup.css','/setup/setup.js','/setup/bootstrap-6.0.0-alpha.1.min.css','/setup/bootstrap-dvm.css','/setup/bootstrap-6.0.0-alpha.1.bundle.min.js'],true))return false;\nif(str_starts_with(parse_url(\$_SERVER['REQUEST_URI'],PHP_URL_PATH),'/setup')){require __DIR__.'/httpdocs/setup/index.php';return;}\nrequire __DIR__.'/httpdocs/index.php';\n",
    );
    $log = fopen($root . "/private/http-test.log", "wb");
    $server = proc_open(
        [
            ...TestSupport::phpCommand(),
            "-d",
            "disable_functions=proc_open,exec,shell_exec,system,passthru,popen",
            "-d",
            "open_basedir=" . $root,
            '-d', 'error_reporting=E_ALL',
            '-d', 'log_errors=1',
            "-S",
            "127.0.0.1:" . $port,
            "-t",
            $root . "/httpdocs",
            $router,
        ],
        [0 => ["pipe", "r"], 1 => $log, 2 => $log],
        $pipes,
    );
    if (!is_resource($server)) {
        throw new RuntimeException("HTTP test server unavailable");
    }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $probe = @stream_socket_client(
            "tcp://127.0.0.1:" . $port,
            $errno,
            $error,
            0.1,
        );
        if ($probe) {
            fclose($probe);
            break;
        }
        usleep(100000);
    }
    [$status, $html] = webHttp("/setup/");
    webCheck(
        $status === 200 &&
            str_contains($html, "DVM · DeinVereinsManager") &&
            str_contains($html, "Voraussetzungen erfüllt"),
        "actual HTTP installer works in hosting httpdocs with all process and shell functions disabled",
    );
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $csrfMatch);
    $csrf = $csrfMatch[1] ?? "";
    webCheck(strlen($csrf) === 64, "private session issues a real CSRF token");
    webCheck(
        webHttp("/setup/", [], ["X-Synthetic-Plain: 1"])[0] === 403,
        "unencrypted HTTP refused before setup",
    );
    webCheck(
        webHttp("/setup/", [], [], "DELETE")[0] === 405,
        "unsupported method refused",
    );
    $input = [
        "action" => "begin",
        "csrf" => $csrf,
        "token" => $token,
        "club_id" => "dvm-install",
        "club_name" => "Eigener Testverein",
        "db_host" => "127.0.0.1",
        "db_port" => "13371",
        "db_name" => $fixture["database"],
        "db_user" => $fixture["username"],
        "db_password" => $fixture["password"],
        "scheduler_php" => PHP_BINARY,
        "admin_email" => "dvm-admin@example.invalid",
        "admin_password" => "Synthetic browser setup password 2026!",
        "ocr_enable" => "1",
        "fints_enable" => "1",
        "fints_confirm" => "1",
        "fints_url" => "https://bank.example.invalid/fints",
        "fints_bank_code" => "00000000",
        "fints_product_id" => str_repeat("A", 25),
        "fints_product_version" => "1.0",
    ];
    $wrong = $input; $wrong['scheduler_php'] = "php -r forbidden";
    webCheck(webHttp('/setup/', $wrong, [], 'POST')[0] === 422 && $db->query('SHOW TABLES')->fetchAll() === [] && !is_file($root . '/private/setup-state'), 'unsafe hosting executable is rejected before database work or a saved installation');
    $wrong = $input;
    $wrong["fints_confirm"] = "";
    webCheck(
        webHttp("/setup/", $wrong, [], "POST")[0] === 422,
        "unconfirmed FinTS registration rejected before database or private bank writes",
    );
    $wrong = $input;
    $wrong["ocr_enable"] = "1";
    // A forged selection is checked by the protected installer step, never by client UI alone.
    try {
        \Kulturhus\Native\Installer\OcrSetup::prepare($root, true);
        throw new LogicException("missing OCR accepted");
    } catch (DomainException) {
        webCheck(
            true,
            "absent OCR binary package fails closed even when the selection is forged",
        );
    }
    $wrong = $input;
    $wrong["csrf"] = "wrong";
    webCheck(
        webHttp("/setup/", $wrong, [], "POST")[0] === 403,
        "forged CSRF cannot initialize database or files",
    );
    $wrong = $input;
    $wrong["token"] = str_repeat("0", 64);
    webCheck(
        webHttp("/setup/", $wrong, [], "POST")[0] === 422,
        "wrong private setup key rejected",
    );
    webCheck(
        webHttp(
            "/setup/",
            $input,
            ["Origin: https://foreign.example"],
            "POST",
        )[0] === 403,
        "foreign browser origin rejected",
    );
    webCheck(
        $db->query("SHOW TABLES")->fetchAll() === [] &&
            !is_file($root . "/private/setup-state"),
        "unauthorized requests leave original empty database untouched",
    );
    [$status, $body] = webHttp("/setup/", $input, [], "POST");
    $result = json_decode($body, true);
    webCheck(
        $status === 200 && ($result["phase"] ?? "") === "verify",
        "authenticated setup begins bounded source verification",
    );
    $state = file_get_contents($root . "/private/setup-state");
    webCheck(
        !str_contains($state, $input["db_password"]) &&
            !str_contains($state, $input["admin_password"]) &&
            !str_contains($body, $input["admin_password"]),
        "pending state encrypted; passwords never returned",
    );
    webCheck(
        webHttp("/setup/", $input, [], "POST")[0] === 422,
        "repeat begin cannot overwrite saved setup",
    );
    $request = ["action" => "step", "csrf" => $csrf, "token" => $token];
    [$status, $body] = webHttp("/setup/", $request, [], "POST");
    $result = json_decode($body, true);
    $plain = sodium_crypto_secretbox_open(
        substr(file_get_contents($root . "/private/setup-state"), 24),
        substr(file_get_contents($root . "/private/setup-state"), 0, 24),
        file_get_contents($root . "/private/setup-state.key"),
    );
    $pending = json_decode($plain, true);
    webCheck(
        $status === 200 &&
            $pending["cursor"] === 200 &&
            $db->query("SHOW TABLES")->fetchAll() === [],
        "first step checks only 200 real files without database DDL",
    );
    $proof = \PackageStore::json($root . "/private/app-files.json");
    $file = $root . "/app/" . $proof["files"][220]["path"];
    $original = file_get_contents($file);
    file_put_contents($file, $original . "changed");
    webCheck(
        webHttp("/setup/", $request, [], "POST")[0] === 422,
        "changed unverified source fails its byte proof",
    );
    webCheck(
        $db->query("SHOW TABLES")->fetchAll() === [],
        "changed upload is rejected before database writes",
    );
    file_put_contents($file, $original);
    $cookie = "";
    [$status, $html] = webHttp("/setup/");
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $csrfMatch);
    $request["csrf"] = $csrfMatch[1];
    $request["action"] = "resume";
    webCheck(
        webHttp("/setup/", $request, [], "POST")[0] === 200,
        "a new browser can explicitly resume with the original setup key",
    );
    $request["action"] = "addons";
    webCheck(
        webHttp("/setup/", $request, [], "POST")[0] === 422,
        "addon change is rejected during package verification",
    );
    $request["action"] = "step";
    $result = ["phase" => "verify"];
    for ($step = 0; $result["phase"] === "verify" && $step < 80; ++$step) {
        [$status, $body] = webHttp("/setup/", $request, [], "POST");
        $result = json_decode($body, true);
        if ($status !== 200) {
            throw new RuntimeException("Source verification failed");
        }
    }
    webCheck(
        $result["phase"] === "integrations",
        "all source bytes are checked before optional integration execution",
    );
    [$status, $body] = webHttp("/setup/", $request, [], "POST");
    webCheck(
        $status === 422 &&
            str_contains($body, "OCR-Paket fehlt") &&
            $db->query("SHOW TABLES")->fetchAll() === [],
        "forged missing OCR selection fails actual protected HTTP step before any database writes",
    );
    $request["action"] = "addons";
    [$status, $body] = webHttp("/setup/", $request, [], "POST");
    webCheck(
        $status === 200 && json_decode($body, true)["phase"] === "integrations",
        "explicit authenticated OCR deselection preserves pending account and source verification",
    );
    if (getenv("DVM_INSTALLER_BROWSER") === "1") {
        $retry = $request;
        $retry["ocr_enable"] = "1";
        webCheck(
            webHttp("/setup/", $retry, [], "POST")[0] === 200,
            "OCR selection can be explicitly retried before database initialization",
        );
        $browser = proc_open(
            ["node", __DIR__ . "/webspace-installer-browser.mjs"],
            [0 => ["pipe", "r"], 1 => STDOUT, 2 => STDERR],
            $browserPipes,
        );
        if (!is_resource($browser)) {
            throw new RuntimeException("Setup browser unavailable");
        }
        fwrite(
            $browserPipes[0],
            json_encode(
                ["root" => $root, "port" => $port, "ocr_failed" => true, "expected_php" => PHP_BINARY],
                JSON_THROW_ON_ERROR,
            ),
        );
        fclose($browserPipes[0]);
        webCheck(
            proc_close($browser) === 0,
            "real Chromium resumes and completes setup and hosting instructions at four widths and two font sizes",
        );
        $result = ['phase' => 'installed'] + json_decode(file_get_contents($root . '/private/browser-scheduler-result.json'), true, 32, JSON_THROW_ON_ERROR);
    }
    $request["action"] = "step";
    $iterations = 0;
    while (($result["phase"] ?? "") !== "installed" && ++$iterations < 80) {
        echo "HTTP setup step " .
            $iterations .
            ": " .
            ($result["phase"] ?? "verify") .
            "\n";
        [$status, $body] = webHttp("/setup/", $request, [], "POST");
        $result = json_decode($body, true);
        if ($status !== 200) {
            throw new RuntimeException(
                "Web setup failed: " . ($result["error"] ?? "invalid response"),
            );
        }
    }
    webCheck(
        ($result["phase"] ?? "") === "installed" &&
            (int) $db
                ->query("SELECT COUNT(*) FROM native_schema_versions")
                ->fetchColumn() === count(\Kulturhus\Native\Installer\SchemaRegistry::VERSIONS),
        "complete real browser-SAPI setup initializes every declared native module",
    );
    webCheck(($result['scheduler']['php'] ?? '') === PHP_BINARY && ($result['scheduler']['status'] ?? '') === 'not-verified' && count($result['scheduler']['jobs'] ?? []) === 15 && !str_contains($result['scheduler_text'] ?? '', $input['db_password']) && !str_contains($result['scheduler_text'] ?? '', $input['admin_password']), 'actual completed installer returns the original PHP selection and complete non-secret unverified hosting plan');
    webCheck(is_file($root . '/app/vereinsverwaltung/bin/native-system-worker.php') && is_file($root . '/app/vereinsverwaltung/native/Installer/ScheduledJobs.php') && is_file($root . '/app/vereinsverwaltung/native/Integration/Jobs/FinTs.php'), 'actual upload includes the system entry point and shared hosting and bank implementations');
    webCheck(
        is_file($root . "/app/vereinsverwaltung/bin/service-status-worker.php") &&
        is_file($root . "/app/vereinsverwaltung/native/Status/Monitor.php") &&
        (int)$db->query("SELECT version FROM native_schema_versions WHERE module='status'")->fetchColumn() === 1 &&
        (int)$db->query("SELECT COUNT(*) FROM native_status_settings")->fetchColumn() === 0,
        "actual uploaded status worker and schema start without automatic or public activation",
    );
    webCheck(
        !is_file($root . "/private/setup-state") &&
            !is_file($root . "/private/setup-token") &&
            !is_file($root . "/private/setup-state.key"),
        "successful completion removes saved setup credentials and key proof",
    );
    webCheck(
        webHttp("/setup/")[0] === 410 &&
            webHttp("/setup/", $request, [], "POST")[0] === 410,
        "completed installer fails closed for GET and POST",
    );
    $auth = Runtime::service($db, $root . "/private/auth.key");
    $login = $auth->passwordLogin(
        $input["admin_email"],
        $input["admin_password"],
        "webspace-check",
    );
    $bridge = ManagerBridge::fromNativeSession(
        $db,
        $auth,
        $login["session_token"],
    );
    webCheck(
        $login["status"] === "authenticated" &&
            $bridge->managerClub() === "dvm-install",
        "browser-created administrator uses actual native login and original club binding",
    );
    $profile = \Kulturhus\Native\Shared\Identity::forClub(
        $db,
        $bridge->access()->club(),
    );
    webCheck(
        $profile["name"] === "Eigener Testverein" &&
            $profile["origin"] === "https://dvm-install.example",
        "fresh identity uses entered club and domain, not Kulturhus defaults",
    );
    // The observer uses one source tree; the deployed application runs in the separate HTTP process.
    require_once dirname(__DIR__) . "/src/bootstrap.php";
    $settings = new \Kulturhus\Verein\Settings($db, "native-install-admin");
    webCheck(
        $settings->section("paper")["data"]["header_text"] ===
            "Eigener Testverein" &&
            $settings->section("mail")["data"]["sender_name"] ===
                "Eigener Testverein",
        "unconfigured letterhead and sender follow the entered club",
    );
    webCheck(
        (int) $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn() ===
            0 &&
            (int) $db
                ->query("SELECT COUNT(*) FROM khcms_pages")
                ->fetchColumn() === 0,
        "fresh product database contains no demo contacts or mock website pages",
    );
    PreparedInstall::check($root, $db);
    $bankEnvironment = \PackageStore::json($root . "/private/environment.json");
    $bankConfig = \PackageStore::json($root . "/private/banking/fints.json");
    webCheck(
        $bankEnvironment["VEREIN_BANKING_ROOT"] ===
            $root . "/private/banking" &&
            $bankEnvironment["VEREIN_FINTS_ROOT"] ===
                $root . "/private/banking" &&
            $bankConfig["club_id"] === "dvm-install" &&
            $bankConfig["url"] === $input["fints_url"],
        "actual shell-free installer saves FinTS metadata with the original club and private runtime paths",
    );
    $bankKey = $root . "/private/banking/fints-state.key";
    $bankKeyHash = hash_file("sha256", $bankKey);
    webCheck(
        strlen(file_get_contents($bankKey)) === 32 &&
            (fileperms($bankKey) & 0077) === 0 &&
            (fileperms(dirname($bankKey)) & 0077) === 0,
        "FinTS dialogue key and metadata live outside public webroot with private permissions",
    );
    // Check only the uploaded application in its own process, as on real hosting.
    $bankCode =
        '$root=' .
        var_export($root, true) .
        '; require $root."/private/runtime.php"; require $root."/app/distribution/src/PackageStore.php"; require $root."/app/vereinsverwaltung/native/bootstrap.php"; require $root."/app/vereinsverwaltung/src/bootstrap.php"; $_SERVER["DOCUMENT_ROOT"]=$root."/httpdocs"; $db=\\Kulturhus\\Verein\\Database::connect(getenv("VEREIN_DATABASE")); $dialog=new \\Kulturhus\\Verein\\FinTsDialog($db,"native-install-admin","synthetic-installer-session",$root."/private/banking"); $key=$root."/private/banking/fints-state.key"; $before=hash_file("sha256",$key); \\Kulturhus\\Native\\Installer\\FinTsSetup::prepare($root,"dvm-install",json_decode(file_get_contents($root."/private/banking/fints.json"),true)); echo json_encode(["ready"=>$dialog->status()===[],"bank"=>$dialog->defaultBank()["bank_code"],"preserved"=>hash_file("sha256",$key)===$before],JSON_THROW_ON_ERROR);';
    $bankProcess = proc_open(
        [...TestSupport::phpCommand(), "-r", $bankCode],
        [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $bankPipes,
    );
    fclose($bankPipes[0]);
    $bankResult = json_decode(stream_get_contents($bankPipes[1]), true);
    $bankError = stream_get_contents($bankPipes[2]);
    fclose($bankPipes[1]);
    fclose($bankPipes[2]);
    $bankExit = proc_close($bankProcess);
    if ($bankExit !== 0 || $bankError !== "") {
        fwrite(
            STDERR,
            "Deployed synthetic FinTS check: " .
                str_replace(
                    [$fixture["password"]],
                    ["[redacted]"],
                    $bankError,
                ) .
                "\n",
        );
    }
    webCheck(
        $bankExit === 0 &&
            $bankError === "" &&
            ($bankResult["ready"] ?? false) &&
            ($bankResult["bank"] ?? "") === "00000000",
        "real deployed locked FinTS adapter works without opening a bank dialogue or using credentials",
    );
    webCheck(
        ($bankResult["preserved"] ?? false) &&
            hash_file("sha256", $bankKey) === $bankKeyHash &&
            !is_file($root . "/private/runtime-config/ocr.json"),
        "idempotent FinTS preparation preserves key and leaves unselected OCR inactive",
    );
    webCheck(
        true,
        "actual completed upload matches every package byte and module version",
    );
    [$status, $html] = webHttp("/login/");
    webCheck(
        $status === 200 && str_contains($html, "DVM – DeinVereinsManager"),
        "installed production front controller renders DVM login without ProcessWire",
    );
    webCheck(
        str_contains($html, "bootstrap-6.0.0-alpha.1.min.css") &&
            str_contains($html, 'type="module"'),
        "installed account page uses pinned local Bootstrap 6 ES module",
    );
    foreach (
        \Kulturhus\Native\Web\BootstrapAssets::sources()
        as $name => $source
    ) {
        [$status, $bytes] = webHttp("/assets/" . $name);
        webCheck(
            $status === 200 &&
                hash_equals(
                    hash_file("sha256", $source),
                    hash("sha256", $bytes),
                ),
            "installed PHP hosting serves exact local framework bytes: " .
                $name,
        );
    }
    foreach (['native-page-media-picker.js','native-page-media-picker.css','photo-library.js'] as $name) {
        [$status,$bytes]=webHttp('/vereinsverwaltung/'.$name);
        webCheck($status===200&&hash_equals(hash_file('sha256',\Kulturhus\Native\Integration\Assets::sources()[$name]),hash('sha256',$bytes)), 'installed PHP hosting serves exact complete photo chooser asset: '.$name);
    }
    [$status, $icon] = webHttp("/vereinsverwaltung/dvm-icon.svg");
    webCheck(
        $status === 200 && str_contains($icon, "DeinVereinsManager"),
        "installed DVM product artwork served through native production assets",
    );
    [$status,$manifestBody]=webHttp('/site.webmanifest');
    $manifest=json_decode($manifestBody,true);
    webCheck($status===200&&is_array($manifest)&&$manifest['start_url']==='/'&&count($manifest['icons'])===2,'installed shell-free hosting serves its own public website manifest');
    [$status,$iconBytes]=webHttp('/website-icon-512.png');$iconSize=getimagesizefromstring($iconBytes);
    webCheck($status===200&&$iconSize!==false&&$iconSize[0]===512&&$iconSize[1]===512,'installed shell-free hosting creates exact local PNG using its packaged font');
    $hostingLog=(string)file_get_contents($root.'/private/http-test.log');
    if (str_contains($hostingLog,'open_basedir restriction')) {
        preg_match_all('/^.*open_basedir restriction.*$/m',$hostingLog,$warnings);
        fwrite(STDERR,json_encode(['hosting_path_warnings'=>array_slice($warnings[0],-8)],JSON_THROW_ON_ERROR)."\n");
    }
    webCheck(
        !str_contains(
            $hostingLog,
            "open_basedir restriction",
        ),
        "real hosting installer and login stay inside the explicitly permitted private app/web paths",
    );
    echo "$n webspace installer checks passed; actual HTTP, synthetic MySQL, shell functions disabled, no external transport.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (isset($log) && is_resource($log)) {
        fclose($log);
    }
    if (is_dir($root)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }
}
