<?php declare(strict_types=1);
// This entry runs only in the prepared upload tree, never the development checkout.
if (
    !isset($dvmSetupRoot) ||
    !is_string($dvmSetupRoot) ||
    !is_file($dvmSetupRoot . "/private/app-files.json")
) {
    http_response_code(404);
    exit();
}
require $dvmSetupRoot . "/app/vereinsverwaltung/native/bootstrap.php";
require $dvmSetupRoot . "/app/distribution/src/PackageStore.php";
\Kulturhus\Native\Installer\WebSetup::serve($dvmSetupRoot);
