param([ValidateSet('setup', 'start', 'check', 'verify', 'help')][string]$Mode = 'start')
$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$entry = Join-Path $project 'tools/cloud/bootstrap.sh'
if (-not (Test-Path -LiteralPath $entry -PathType Leaf)) {
    throw 'Native Einrichtung: docs/WEBSPACE-INSTALLATION.md. Entwicklung aus Git: bash tools/cloud/bootstrap.sh --setup.'
}
$bash = Get-Command bash -ErrorAction Stop
& $bash.Source $entry "--$Mode"
exit $LASTEXITCODE
