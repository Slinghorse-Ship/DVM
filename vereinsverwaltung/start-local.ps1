param([ValidateSet('setup', 'start', 'check', 'verify', 'help')][string]$Mode = 'start')
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'start-demo.ps1') -Mode $Mode
exit $LASTEXITCODE
