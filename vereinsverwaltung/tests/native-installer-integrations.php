<?php declare(strict_types=1);
require dirname(__DIR__) . "/native/bootstrap.php";
require dirname(__DIR__, 2) . "/distribution/src/PackageStore.php";
use Kulturhus\Native\Installer\{
    WebBundle,
    FinTsSetup,
    OcrSetup,
    Requirements,
    PreparedInstall,
};
use Kulturhus\Native\TestSupport;

$db = TestSupport::db();
$root = sys_get_temp_dir() . "/dvm-addon-" . bin2hex(random_bytes(10));
$n = 0;
function addonCheck(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$n;
    echo "PASS " . $label . "\n";
}
function addonReject(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (DomainException) {
        addonCheck(true, $label);
        return;
    }
    throw new RuntimeException($label);
}
$input = [
    "fints_enable" => "1",
    "fints_confirm" => "1",
    "fints_url" => "https://bank.example.invalid/fints",
    "fints_bank_code" => "00000000",
    "fints_product_id" => str_repeat("A", 25),
    "fints_product_version" => "1.0",
];
try {
    addonCheck(
        FinTsSetup::validate([], "addon-test") === null,
        "unselected FinTS creates no configuration",
    );
    foreach (
        [
            "fints_confirm" => "",
            "fints_url" => "http://bank.example.invalid/fints",
            "fints_bank_code" => "123",
            "fints_product_id" => str_repeat("A", 24),
            "fints_product_version" => "123456",
        ]
        as $key => $value
    ) {
        $bad = $input;
        $bad[$key] = $value;
        addonReject(
            fn() => FinTsSetup::validate($bad, "addon-test"),
            "invalid or unconfirmed " . $key . " rejected",
        );
    }
    foreach (
        [
            "https://user:pin@bank.example.invalid/fints",
            "https://bank.example.invalid/fints?pin=secret",
            "https://127.0.0.1/fints",
        ]
        as $url
    ) {
        $bad = $input;
        $bad["fints_url"] = $url;
        addonReject(
            fn() => FinTsSetup::validate($bad, "addon-test"),
            "credentials/query/IP bank endpoint rejected",
        );
    }
    $bundle = WebBundle::build(dirname(__DIR__, 2), $root, true);
    addonCheck(
        $bundle["ocr_runtime_packaged"] === true &&
            $bundle["external_actions"] === 0,
        "same existing upload builder prepares optional offline OCR",
    );
    $proof = PackageStore::json($root . "/private/app-files.json");
    foreach ($proof["files"] as $entry) {
        PackageStore::check($root . "/app/" . $entry["path"], $entry);
    }
    addonCheck(
        count(
            array_filter(
                $proof["files"],
                static fn($r) => str_starts_with($r["path"], "runtime/"),
            ),
        ) > 1000,
        "unpacked OCR binaries and licenses are covered by exact byte proof",
    );
    addonCheck(
        !is_dir($root . "/app/processwire") &&
            !is_dir($root . "/app/runtime/kosit-1.6.3-xrechnung-20260831"),
        "native OCR package needs neither ProcessWire nor Java",
    );
    foreach (
        [
            "recognize-invoice.py",
            "invoice_ocr_sandbox.py",
            "invoice_embedded_xml.py",
            "heic_invoice_image.py",
            "ocr-container-launcher.py",
        ]
        as $name
    ) {
        addonCheck(
            is_file($root . "/app/vereinsverwaltung/tools/" . $name),
            "required OCR tool included: " . $name,
        );
    }
    $manifest = PackageStore::json(
        $root . "/app/runtime/distribution/manifest.json",
    );
    $ids = [];
    foreach ($manifest["components"] as $component) {
        $ids = array_merge($ids, $component["source_ids"] ?? []);
    }
    addonCheck(
        !array_diff($ids, array_column($manifest["sources"], "id")) &&
            is_dir($root . "/app/runtime/distribution/LICENSES"),
        "GPL HEIC and other selected runtime source obligations remain in upload",
    );
    $requirements = Requirements::inspect($root . "/app");
    addonCheck(
        $requirements["ready"] &&
            $requirements["integrations"]["fints"] &&
            $requirements["integrations"]["ocr"]["can_check"],
        "installer reports locked FinTS and actual packaged OCR capabilities",
    );
    addonCheck(
        OcrSetup::prepare($root, false) === [] &&
            !is_dir($root . "/private/runtime-config"),
        "unselected OCR performs no runtime execution or activation",
    );
    $config = FinTsSetup::validate($input, "addon-test");
    $environment = FinTsSetup::prepare($root, "addon-test", $config);
    $key = $root . "/private/banking/fints-state.key";
    $hash = hash_file("sha256", $key);
    addonCheck(
        strlen(file_get_contents($key)) === 32 &&
            (fileperms($key) & 0077) === 0,
        "private FinTS setup writes its own persistent 32-byte key",
    );
    addonCheck(
        $environment["VEREIN_BANKING_ROOT"] === $root . "/private/banking" &&
            !glob($root . "/private/banking/dialog-*"),
        "FinTS setup prepares paths without credentials, sessions or a bank request",
    );
    FinTsSetup::prepare($root, "addon-test", $config);
    addonCheck(
        hash_file("sha256", $key) === $hash,
        "repeated FinTS setup preserves existing key",
    );
    addonReject(
        fn() => FinTsSetup::prepare($root, "foreign-club", $config),
        "other club cannot claim original bank binding",
    );
    $changed = $config;
    $changed["url"] = "https://different.example.invalid/fints";
    addonReject(
        fn() => FinTsSetup::prepare($root, "addon-test", $changed),
        "changed setup cannot silently replace original bank metadata",
    );
    $binary = $root . "/app/runtime/ocr-5.5.3/bin/tesseract";
    $original = file_get_contents($binary);
    file_put_contents($binary, "changed");
    $entry = current(
        array_filter(
            $proof["files"],
            static fn($r) => $r["path"] === "runtime/ocr-5.5.3/bin/tesseract",
        ),
    );
    try {
        PackageStore::check($binary, $entry);
        throw new LogicException("tampered OCR accepted");
    } catch (RuntimeException $e) {
        if ($e instanceof LogicException) {
            throw $e;
        }
        addonCheck(
            true,
            "modified OCR binary fails package proof before activation",
        );
    }
    file_put_contents($binary, $original);
    chmod($binary, 0755);
    $ocrProof = "blocked-by-hosting";
    try {
        $runtime = OcrSetup::prepare($root, true);
        addonCheck(
            is_file($runtime["VEREIN_OCR_RUNTIME"]) &&
                PackageStore::json($runtime["VEREIN_OCR_RUNTIME"])[
                    "require_sandbox"
                ] === true,
            "actual Landlock raster proof passes before activation",
        );
        $ocrProof = "actual-landlock-raster";
    } catch (DomainException $e) {
        if (!str_contains($e->getMessage(), "Landlock ABI 6")) {
            throw $e;
        }
        addonCheck(
            !is_file($root . "/private/runtime-config/ocr.json"),
            "unsupported hosting sandbox stops OCR without writing active configuration",
        );
    }
    if (getenv("DVM_INSTALLER_OCR_PROOF") === "1") {
        // prepare() already loaded the actual installer from the uploaded package.
        $runtime = RuntimeInstaller::configureOcr(
            $root . "/app/runtime",
            $root . "/app",
            $root . "/private",
            "docker",
            "debian@sha256:7c7b2c966bc9ee8cedfeef67e0e279108992c77681fa595db4a9d65c06ccc587",
            "/usr/bin/python3",
        );
        OcrSetup::prove($root . "/app", $runtime);
        addonCheck(
            PackageStore::json($runtime["VEREIN_OCR_RUNTIME"])[
                "require_sandbox"
            ] === true,
            "actual newly unpacked OCR recognizes synthetic raster inside isolated Docker",
        );
        $ocrProof = "actual-isolated-docker-raster";
    }
    addonCheck(
        $db->query("SHOW TABLES")->fetchAll() === [],
        "all addon preparation leaves original MySQL and financial records untouched",
    );
    echo json_encode(
        ["checks" => $n, "ocr_proof" => $ocrProof, "external_actions" => 0],
        JSON_THROW_ON_ERROR,
    ) . "\n";
} finally {
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir()
                ? rmdir($file->getPathname())
                : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
