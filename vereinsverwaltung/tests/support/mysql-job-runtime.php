<?php declare(strict_types=1);

/** Immutable real SDKs, with a separate private scratch directory per test job. */
function mysqlJobRuntime(): array
{
    $environment = getenv();
    $directory =
        sys_get_temp_dir() . "/kh-mysql-runtime-" . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException("Private test runtime unavailable");
    }
    foreach (
        [
            "KOSIT" => ["java", "jar", "scenarios", "repository"],
            "HYBRID" => ["java", "jar", "python", "packages"],
        ]
        as $kind => $keys
    ) {
        $variable = "VEREIN_" . $kind . "_RUNTIME";
        $path = $environment[$variable] ?? "";
        if ($path === "" || !is_file($path)) {
            continue;
        }
        $source = realpath($path);
        $config = json_decode(
            (string) file_get_contents($source),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;
            if (!is_string($value) || $value === "") {
                throw new RuntimeException("Incomplete SDK test configuration");
            }
            if (
                $value[0] !== "/" &&
                !preg_match("~^[A-Za-z]:[/\\\\]~D", $value)
            ) {
                $value = dirname($source) . "/" . $value;
            }
            $config[$key] =
                realpath($value) ?:
                throw new RuntimeException("Missing immutable SDK dependency");
        }
        $scratch = $directory . "/" . strtolower($kind);
        if (!mkdir($scratch, 0700)) {
            throw new RuntimeException("SDK scratch directory unavailable");
        }
        $config["workdir"] = $scratch;
        $target = $directory . "/" . strtolower($kind) . ".json";
        if (
            file_put_contents(
                $target,
                json_encode($config, JSON_THROW_ON_ERROR),
            ) === false ||
            !chmod($target, 0600)
        ) {
            throw new RuntimeException("Private SDK configuration unavailable");
        }
        $environment[$variable] = $target;
    }
    return [$environment, $directory];
}

function removeMysqlJobRuntime(string $directory): void
{
    if (
        !str_starts_with(
            $directory,
            sys_get_temp_dir() . "/kh-mysql-runtime-",
        ) ||
        !is_dir($directory)
    ) {
        throw new RuntimeException("Unknown test runtime");
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
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
    rmdir($directory);
}
