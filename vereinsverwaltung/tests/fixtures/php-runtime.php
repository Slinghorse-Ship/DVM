<?php declare(strict_types=1);

/** Keep offline PHP fixtures usable with both system and workspace-local PHP. */
function taxFixturePhpCommand(): array
{
    $command = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if ($ini !== false) array_push($command, '-c', $ini);
    $command[] = __DIR__.'/tax-eric-fixture.php';
    return $command;
}

function taxFixturePhpEnvironment(): array
{
    $environment = [];
    $libraryPath = getenv('LD_LIBRARY_PATH');
    if ($libraryPath !== false && $libraryPath !== '') $environment['LD_LIBRARY_PATH'] = $libraryPath;
    return $environment;
}
