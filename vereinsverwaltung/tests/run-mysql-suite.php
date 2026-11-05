<?php declare(strict_types=1);
if (getenv("VEREIN_TEST_MYSQL") !== "isolated-regression-only") {
    throw new RuntimeException("Isolated test runner required.");
}
require_once __DIR__ . "/support/mysql-job-runtime.php";
$tests = array_slice($argv, 1);
if (!$tests) {
    throw new RuntimeException("Choose explicit test entry points.");
}
$timeout = filter_var(
    getenv("VEREIN_TEST_TIMEOUT_SECONDS") ?: "300",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 60, "max_range" => 3600]],
);
if ($timeout === false) {
    throw new RuntimeException("Invalid bounded test timeout.");
}
foreach ($tests as $name) {
    if (
        !preg_match('/^[a-z0-9-]+\.php$/D', $name) ||
        !is_file(__DIR__ . "/" . $name) ||
        in_array($name, ["run-mysql-suite.php", "mysql-bootstrap.php"], true)
    ) {
        throw new RuntimeException("Invalid test: " . $name);
    }
}
$resultDirectory = getenv("VEREIN_TEST_RESULTS") ?: "/test-results";
$report =
    $resultDirectory .
    "/mysql-suite-" .
    gmdate("Ymd-His") .
    "-" .
    bin2hex(random_bytes(3)) .
    ".json";
$results = [];
$active = [];
while ($tests || $active) {
    while ($tests && count($active) < 3) {
        $name = array_shift($tests);
        if (
            !preg_match('/^[a-z0-9-]+\.php$/D', $name) ||
            !is_file(__DIR__ . "/" . $name) ||
            in_array(
                $name,
                ["run-mysql-suite.php", "mysql-bootstrap.php"],
                true,
            )
        ) {
            throw new RuntimeException("Invalid test");
        }
        [$environment, $runtime] = mysqlJobRuntime();
        $log = tempnam(sys_get_temp_dir(), "mysql-check-");
        $logStream = fopen($log, "wb");
        $p = proc_open(
            [
                PHP_BINARY,
                "-d",
                "auto_prepend_file=" . __DIR__ . "/mysql-bootstrap.php",
                __DIR__ . "/" . $name,
            ],
            [0 => ["file", "/dev/null", "r"], 1 => $logStream, 2 => $logStream],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        fclose($logStream);
        $active[$name] = [
            "process" => $p,
            "log" => $log,
            "runtime" => $runtime,
            "start" => microtime(true),
        ];
    }
    foreach ($active as $name => $job) {
        $state = proc_get_status($job["process"]);
        if ($state["running"] && microtime(true) - $job["start"] < $timeout) {
            continue;
        }
        if ($state["running"]) {
            proc_terminate($job["process"]);
            $code = 124;
        } else {
            $code = $state["exitcode"];
        }
        proc_close($job["process"]);
        $output = file_get_contents($job["log"]);
        unlink($job["log"]);
        removeMysqlJobRuntime($job["runtime"]);
        $results[$name] = [
            "exit" => $code,
            "seconds" => round(microtime(true) - $job["start"], 2),
            "output" => $output,
        ];
        if (is_dir($resultDirectory)) {
            file_put_contents(
                $report,
                json_encode(
                    $results,
                    JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_SLASHES |
                        JSON_INVALID_UTF8_SUBSTITUTE,
                ),
                LOCK_EX,
            );
        }
        echo json_encode(
            [
                "test" => $name,
                "exit" => $code,
                "tail" => $code === 0 ? "PASS" : substr($output, -2500),
            ],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ) . "\n";
        unset($active[$name]);
    }
    usleep(100000);
}
$failed = array_keys(array_filter($results, fn($r) => $r["exit"] !== 0));
echo json_encode(
    [
        "tests" => count($results),
        "failed" => $failed,
        "report" => is_dir($resultDirectory) ? $report : null,
    ],
    JSON_UNESCAPED_SLASHES,
) . "\n";
exit($failed ? 1 : 0);
