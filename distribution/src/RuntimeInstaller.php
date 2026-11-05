<?php declare(strict_types=1);

/** Configure already verified/extracted private runtime artifacts; no downloads or secrets. */
final class RuntimeInstaller
{
    private const MUSTANG = "42d7868cb68264874a7b8cab4c3587b03b23ccc7cd72373da917f66758bb9736";
    private const KOSIT = "799e64befca97d4080e03608c80b85dd5a5ecc5f4ae4f35d1116ec2855b9a7c9";

    public static function configure(
        string $runtimeRoot,
        string $releaseRoot,
        string $privateDataDir,
        string $sandbox = "landlock",
        ?string $containerImage = null,
        ?string $hostPython = null,
    ): array {
        $environment = self::configureOcr(
            $runtimeRoot,
            $releaseRoot,
            $privateDataDir,
            $sandbox,
            $containerImage,
            $hostPython,
        );
        $runtimeRoot = dirname(dirname($environment["VEREIN_PYTHON"]));
        $privateDataDir = dirname(dirname($environment["VEREIN_OCR_RUNTIME"]));
        $releaseRoot = realpath($releaseRoot);
        $kosit = $runtimeRoot . "/kosit-1.6.3-xrechnung-20260831";
        $hybrid = $runtimeRoot . "/hybrid-2.26.0";
        foreach (
            [
                $kosit . "/jdk-17.0.20.1+1-jre/bin/java",
                $kosit . "/config/scenarios.xml",
            ]
            as $file
        ) {
            self::fileInRoot($file, $runtimeRoot);
        }
        foreach (
            [
                $kosit . "/validator.jar" => self::KOSIT,
                $hybrid . "/mustang.jar" => self::MUSTANG,
            ]
            as $file => $hash
        ) {
            self::fileInRoot($file, $runtimeRoot);
            if (!hash_equals($hash, hash_file("sha256", $file))) {
                throw new RuntimeException(
                    "Freigegebener Laufzeithash stimmt nicht: " .
                        basename($file),
                );
            }
        }
        $shell = self::shell();
        self::write(
            $kosit . "/java-private",
            $shell .
                'jre="$runtime/jdk-17.0.20.1+1-jre"' .
                "\n" .
                'export LD_LIBRARY_PATH="$runtime/glibc-compat-2.41:$jre/lib:$jre/lib/server"' .
                "\n" .
                'exec "$jre/bin/java" "-Djava.io.tmpdir=$runtime/java-tmp" "$@"' .
                "\n",
            0700,
        );
        self::write(
            $hybrid . "/java-private",
            $shell .
                'exec "$runtime/../kosit-1.6.3-xrechnung-20260831/java-private" "$@"' .
                "\n",
            0700,
        );
        self::write(
            $hybrid . "/python-private",
            $shell .
                'python=$(CDPATH= cd -- "$runtime/../python" && pwd)' .
                "\n" .
                'ocr=$(CDPATH= cd -- "$runtime/../ocr-5.5.3" && pwd)' .
                "\n" .
                'export PYTHONHOME="$python/usr"' .
                "\n" .
                "export PYTHONDONTWRITEBYTECODE=1" .
                "\n" .
                'exec "$python/lib64/ld-linux-x86-64.so.2" --library-path "$ocr/python-compat:$python/lib/x86_64-linux-gnu:$python/usr/lib/x86_64-linux-gnu" "$python/usr/bin/python3.11" "$@"' .
                "\n",
            0700,
        );
        foreach (
            [$kosit . "/java-tmp", $kosit . "/results", $hybrid . "/work"]
            as $dir
        ) {
            self::directory($dir);
        }
        self::preflight($runtimeRoot);
        $configDir = $privateDataDir . "/runtime-config";
        require_once $releaseRoot .
            "/vereinsverwaltung/src/Finance/Invoices/EInvoiceValidator.php";
        self::json($configDir . "/kosit.json", [
            "java" => $kosit . "/java-private",
            "jar" => $kosit . "/validator.jar",
            "repository" => $kosit . "/config",
            "scenarios" => $kosit . "/config/scenarios.xml",
            "workdir" => $kosit . "/results",
            "validator_sha256" => self::KOSIT,
            "configuration_sha256" => Kulturhus\Verein\EInvoiceValidator::configurationHash(
                $kosit . "/config",
            ),
        ]);
        require_once $releaseRoot .
            "/vereinsverwaltung/src/Finance/Invoices/HybridEInvoice.php";
        self::json($configDir . "/hybrid.json", [
            "java" => $hybrid . "/java-private",
            "jar" => $hybrid . "/mustang.jar",
            "python" => $hybrid . "/python-private",
            "packages" => $hybrid . "/packages",
            "workdir" => $hybrid . "/work",
            "jar_sha256" => self::MUSTANG,
            "java_sha256" => hash_file("sha256", $hybrid . "/java-private"),
            "python_sha256" => hash_file("sha256", $hybrid . "/python-private"),
            "packages_sha256" => Kulturhus\Verein\HybridEInvoice::packageHash(
                $hybrid . "/packages",
            ),
        ]);
        return $environment + [
            "VEREIN_KOSIT_RUNTIME" => $configDir . "/kosit.json",
            "VEREIN_HYBRID_RUNTIME" => $configDir . "/hybrid.json",
        ];
    }

    /** The same pinned PDF/OCR runtimes also serve the native webspace package without Java. */
    public static function configureOcr(
        string $runtimeRoot,
        string $releaseRoot,
        string $privateDataDir,
        string $sandbox = "landlock",
        ?string $containerImage = null,
        ?string $hostPython = null,
    ): array {
        if (
            PHP_OS_FAMILY !== "Linux" ||
            !in_array(php_uname("m"), ["x86_64", "amd64"], true)
        ) {
            throw new RuntimeException("OCR benötigt Linux x86_64.");
        }
        if (!function_exists("proc_open")) {
            throw new RuntimeException(
                "OCR benötigt die PHP-Funktion proc_open; Hosting-Freigabe erforderlich.",
            );
        }
        foreach (["runtimeRoot", "releaseRoot", "privateDataDir"] as $name) {
            $resolved = realpath($$name);
            if (
                !$resolved ||
                !is_dir($resolved) ||
                str_contains($resolved, "\0") ||
                str_contains($resolved, "\n")
            ) {
                throw new RuntimeException(
                    "Installationsverzeichnis fehlt: " . $name,
                );
            }
            $$name = rtrim($resolved, "/");
        }
        $python = $runtimeRoot . "/python";
        $ocr = $runtimeRoot . "/ocr-5.5.3";
        foreach (
            [
                $python . "/usr/bin/python3.11",
                $python . "/lib64/ld-linux-x86-64.so.2",
                $ocr . "/bin/tesseract",
                $ocr . "/lib/ld-linux-x86-64.so.2",
            ]
            as $file
        ) {
            self::fileInRoot($file, $runtimeRoot);
        }
        foreach (
            [
                $ocr .
                "/tessdata-best-e12c65a91594/deu.traineddata" => "8407331d6aa0229dc927685c01a7938fc5a641d1a9524f74838cdac599f0d06e",
                $ocr .
                "/tessdata-best-e12c65a91594/eng.traineddata" => "8280aed0782fe27257a68ea10fe7ef324ca0f8d85bd2fd145d1c2b560bcb66ba",
            ]
            as $file => $hash
        ) {
            self::fileInRoot($file, $runtimeRoot);
            if (!hash_equals($hash, hash_file("sha256", $file))) {
                throw new RuntimeException(
                    "Freigegebener OCR-Modellhash stimmt nicht.",
                );
            }
        }
        $shell = self::shell();
        self::write(
            $python . "/run",
            $shell .
                'export PYTHONHOME="$runtime/usr"' .
                "\n" .
                'export PYTHONPATH="$runtime/usr/lib/python3/dist-packages:$runtime/opt/pdfdeps"' .
                "\n" .
                "export PYTHONDONTWRITEBYTECODE=1" .
                "\n" .
                'exec "$runtime/lib64/ld-linux-x86-64.so.2" --library-path "$runtime/lib/x86_64-linux-gnu:$runtime/usr/lib/x86_64-linux-gnu" "$runtime/usr/bin/python3.11" "$@"' .
                "\n",
            0700,
        );
        self::write(
            $ocr . "/run-python",
            $shell .
                'python=$(CDPATH= cd -- "$runtime/../python" && pwd)' .
                "\n" .
                'export PYTHONHOME="$python/usr"' .
                "\n" .
                'export PYTHONPATH="$runtime/python:$python/usr/lib/python3/dist-packages:$python/opt/pdfdeps"' .
                "\n" .
                "export PYTHONDONTWRITEBYTECODE=1" .
                "\n" .
                'exec "$python/lib64/ld-linux-x86-64.so.2" --library-path "$runtime/python-compat:$python/lib/x86_64-linux-gnu:$python/usr/lib/x86_64-linux-gnu" "$python/usr/bin/python3.11" "$@"' .
                "\n",
            0700,
        );
        foreach (
            [
                $privateDataDir . "/runtime-config",
                $releaseRoot . "/.local/vereinsverwaltung/pdf-tmp",
            ]
            as $dir
        ) {
            self::directory($dir);
        }
        $capabilities = self::preflightPdf($runtimeRoot);
        if (!in_array($sandbox, ["landlock", "docker"], true)) {
            throw new RuntimeException("Unbekannter OCR-Sandboxbetrieb.");
        }
        if (
            $sandbox === "landlock" &&
            ($capabilities["landlock_abi"] ?? 0) < 6
        ) {
            throw new RuntimeException(
                "OCR benötigt Landlock ABI 6 und seccomp; diese Hosting-Laufzeit unterstützt es nicht.",
            );
        }
        $configDir = $privateDataDir . "/runtime-config";
        $ocrConfig = [
            "python" => $ocr . "/run-python",
            "tesseract" => $ocr . "/bin/tesseract",
            "tesseract_loader" => $ocr . "/lib/ld-linux-x86-64.so.2",
            "tesseract_library_path" => $ocr . "/lib",
            "tessdata" => $ocr . "/tessdata-best-e12c65a91594",
            "language" => "deu+eng",
            "require_sandbox" => true,
            "sandbox_read_roots" => [$python, $ocr],
        ];
        if ($sandbox === "docker") {
            if (
                !$containerImage ||
                !preg_match(
                    "/\Adebian@sha256:[a-f0-9]{64}\z/D",
                    $containerImage,
                ) ||
                !$hostPython ||
                !str_starts_with($hostPython, "/") ||
                !is_executable($hostPython)
            ) {
                throw new RuntimeException(
                    "Fixiertes Docker-Abbild und absolute Python-Startlaufzeit erforderlich.",
                );
            }
            $tools = $releaseRoot . "/vereinsverwaltung/tools";
            self::json($configDir . "/ocr-container.json", [
                "runtime" => $runtimeRoot,
                "tools" => $tools,
                "image" => $containerImage,
            ]);
            $launcher =
                "#!/bin/sh" .
                "\nset -eu\nexec " .
                escapeshellarg($hostPython) .
                " " .
                escapeshellarg($tools . "/ocr-container-launcher.py") .
                " --config " .
                escapeshellarg($configDir . "/ocr-container.json") .
                ' "$@"' .
                "\n";
            self::write($configDir . "/ocr-container", $launcher, 0700);
            $ocrConfig["python"] = $configDir . "/ocr-container";
            $ocrConfig["sandbox_backend"] = "docker";
        }
        self::json($configDir . "/ocr.json", $ocrConfig);
        return [
            "VEREIN_PYTHON" => $python . "/run",
            "VEREIN_OCR_RUNTIME" => $configDir . "/ocr.json",
        ];
    }

    private static function shell(): string
    {
        return "#!/bin/sh\nset -eu\numask 077\nruntime=$(CDPATH= cd -- \"$(dirname -- \"$0\")\" && pwd)\n";
    }

    public static function preflight(string $runtimeRoot): array
    {
        $data = self::preflightPdf($runtimeRoot);
        $data["java"] = trim(
            self::run([
                $runtimeRoot . "/kosit-1.6.3-xrechnung-20260831/java-private",
                "-version",
            ]),
        );
        return $data;
    }

    public static function preflightPdf(string $runtimeRoot): array
    {
        $code =
            'import sys,json,ctypes,platform,reportlab,pypdf,PIL,charset_normalizer; c=ctypes.CDLL(None,use_errno=True); print(json.dumps({"python":platform.python_version(),"reportlab":reportlab.Version,"pypdf":pypdf.__version__,"pillow":PIL.__version__,"charset_normalizer":charset_normalizer.__version__,"landlock_abi":c.syscall(444,0,0,1),"architecture":platform.machine()}))';
        $output = self::run([$runtimeRoot . "/python/run", "-B", "-c", $code]);
        $data = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        if (
            ($data["python"] ?? "") !== "3.11.2" ||
            ($data["reportlab"] ?? "") !== "4.4.9" ||
            ($data["pypdf"] ?? "") !== "6.10.0" ||
            ($data["pillow"] ?? "") !== "9.4.0" ||
            ($data["charset_normalizer"] ?? "") !== "3.5.1"
        ) {
            throw new RuntimeException(
                "Die PDF-Laufzeit entspricht nicht dem geprüften Paket.",
            );
        }
        return $data;
    }

    private static function run(array $command): string
    {
        $pipes = [];
        $p = proc_open(
            $command,
            [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes,
            null,
            null,
            ["bypass_shell" => true],
        );
        if (!is_resource($p)) {
            throw new RuntimeException(
                "Laufzeitprüfung konnte nicht gestartet werden.",
            );
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = "";
        $deadline = microtime(true) + 15;
        try {
            do {
                $output .=
                    stream_get_contents($pipes[1]) .
                    stream_get_contents($pipes[2]);
                if (strlen($output) > 20000 || microtime(true) > $deadline) {
                    proc_terminate($p, 9);
                    throw new RuntimeException(
                        "Laufzeitprüfung überschreitet die Grenzen.",
                    );
                }
                $state = proc_get_status($p);
                if (!$state["running"]) {
                    break;
                }
                usleep(50000);
            } while (true);
            $output .=
                stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            if ($state["exitcode"] !== 0) {
                throw new RuntimeException(
                    "Private Laufzeit kann auf diesem System nicht gestartet werden.",
                );
            }
            return $output;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
    }
    private static function fileInRoot(string $file, string $root): void
    {
        $real = realpath($file);
        if (
            !$real ||
            !is_file($real) ||
            !str_starts_with($real, $root . "/") ||
            is_link($file)
        ) {
            throw new RuntimeException(
                "Private Laufzeitdatei fehlt oder verlässt das Ziel: " .
                    basename($file),
            );
        }
    }
    private static function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true))) {
            throw new RuntimeException(
                "Privates Arbeitsverzeichnis kann nicht angelegt werden.",
            );
        }
        chmod($path, 0700);
    }
    private static function json(string $path, array $data): void
    {
        self::write(
            $path,
            json_encode(
                $data,
                JSON_THROW_ON_ERROR |
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES,
            ) . "\n",
            0600,
        );
    }
    private static function write(string $path, string $bytes, int $mode): void
    {
        if (is_link($path)) {
            throw new RuntimeException(
                "Laufzeitdatei darf kein symbolischer Link sein.",
            );
        }
        if (file_exists($path)) {
            if (
                !is_file($path) ||
                !hash_equals(hash("sha256", $bytes), hash_file("sha256", $path))
            ) {
                throw new RuntimeException(
                    "Vorhandene Laufzeitkonfiguration wird nicht überschrieben: " .
                        basename($path),
                );
            }
            chmod($path, $mode);
            return;
        }
        $handle = fopen($path, "x");
        if (!$handle) {
            throw new RuntimeException(
                "Laufzeitkonfiguration kann nicht geschrieben werden.",
            );
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException(
                    "Laufzeitkonfiguration unvollständig.",
                );
            }
        } finally {
            fclose($handle);
        }
        chmod($path, $mode);
    }
}
