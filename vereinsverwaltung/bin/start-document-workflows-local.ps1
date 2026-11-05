$ErrorActionPreference = 'Stop'
$workflowRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$workflowPhp = Join-Path $workflowRoot '.local/php-8.5.10/php.exe'
$workflowExt = Join-Path $workflowRoot '.local/php-8.5.10/ext'
$workflowPrivate = Join-Path $workflowRoot '.local/document-workflows-20261007'
if (Get-NetTCPConnection -State Listen -LocalPort 8117 -ErrorAction SilentlyContinue) { throw 'Port 8117 ist bereits belegt. Bestehende Prozesse bleiben unverändert.' }
Push-Location $workflowRoot
try {
    & $workflowPhp -n -d "extension_dir=$workflowExt" -d extension=pdo_mysql -d extension=mbstring 'vereinsverwaltung/bin/document-workflow-local.php' init
    if ($LASTEXITCODE -ne 0) { throw 'Lokale MySQL-Workflow-Demo konnte nicht eingerichtet werden.' }
    foreach ($workflowImporter in @('document-workflow-general.php','document-workflow-dguv.php','document-workflow-rwe.php')) {
        & $workflowPhp -n -d "extension_dir=$workflowExt" -d extension=pdo_mysql -d extension=mbstring (Join-Path 'vereinsverwaltung/bin' $workflowImporter)
        if ($LASTEXITCODE -ne 0) { throw 'Lokale Dokumentvorlagen konnten nicht eingerichtet werden.' }
    }
    $previousDemoFlag = $env:KULTURHUS_WORKFLOW_DEMO
    $previousWorkflowPython = $env:VEREIN_PYTHON
    try {
        $env:KULTURHUS_WORKFLOW_DEMO = '1'
        $env:VEREIN_PYTHON = 'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
        $workflowArguments = @('-n','-d',('extension_dir="'+$workflowExt+'"'),'-d','extension=pdo_mysql','-d','extension=mbstring','-d','display_errors=0','-S','127.0.0.1:8117','-t','vereinsverwaltung/public','vereinsverwaltung/tests/support/document-workflow-router.php')
        $workflowProcess = Start-Process -FilePath $workflowPhp -ArgumentList $workflowArguments -WorkingDirectory $workflowRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $workflowPrivate 'php.stdout.log') -RedirectStandardError (Join-Path $workflowPrivate 'php.stderr.log')
        $workflowProcess.Id | Set-Content -LiteralPath (Join-Path $workflowPrivate 'server.pid') -Encoding utf8
        $workflowReady = $false
        for ($workflowAttempt=0; $workflowAttempt -lt 10; $workflowAttempt++) {
            if ($workflowProcess.HasExited) { throw 'Der lokale PHP-Prozess wurde vorzeitig beendet. Lokales Fehlerprotokoll prüfen.' }
            try {
                $workflowResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:8117/workflows' -UseBasicParsing -TimeoutSec 2
                if ($workflowResponse.StatusCode -eq 200 -and $workflowResponse.Content.Contains('Gefährdungsbeurteilung')) { $workflowReady = $true; break }
            } catch { Start-Sleep -Milliseconds 200 }
        }
        if (-not $workflowReady) { throw 'Die lokale Workflow-Vorschau meldet keinen erfolgreichen Start. Lokales Fehlerprotokoll prüfen.' }
        Write-Output 'Lokale Workflow-Demo: http://127.0.0.1:8117/workflows'
    } finally { $env:KULTURHUS_WORKFLOW_DEMO = $previousDemoFlag; $env:VEREIN_PYTHON = $previousWorkflowPython }
} finally { Pop-Location }
