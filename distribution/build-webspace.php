<?php declare(strict_types=1);
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
ini_set("zend.exception_ignore_args", "1");
require dirname(__DIR__) . "/vereinsverwaltung/native/bootstrap.php";
try {
    $options = getopt("", ["target:", "with-ocr"]);
    $result = \Kulturhus\Native\Installer\WebBundle::build(
        dirname(__DIR__),
        (string) ($options["target"] ?? ""),
        array_key_exists("with-ocr", $options),
    );
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES,
    ) . "\n";
} catch (Throwable $error) {
    fwrite(
        STDERR,
        "Webspace-Paket nicht erstellt: " . $error->getMessage() . "\n",
    );
    exit(1);
}
