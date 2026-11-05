$ErrorActionPreference='Stop'
$workflowRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$workflowPhp=Join-Path $workflowRoot '.local/php-8.5.10/php.exe'
$workflowExt=Join-Path $workflowRoot '.local/php-8.5.10/ext'
$workflowConfig=Join-Path $workflowRoot '.local/native-cms-integration/workflow-manager.json'
if(Get-NetTCPConnection -State Listen -LocalPort 8118 -ErrorAction SilentlyContinue){throw 'Port 8118 belegt. Bestehender Prozess bleibt unverändert.'}
$workflowPreviousConfig=$env:KH_NATIVE_TEST_CONFIG
Push-Location $workflowRoot
try{
    $workflowFlags=@('-n','-d',"extension_dir=$workflowExt",'-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=sodium','-d','extension=openssl','-d','extension=fileinfo')
    if(!(Test-Path -LiteralPath $workflowConfig)){
        & $workflowPhp @workflowFlags 'vereinsverwaltung/bin/native-fixture.php' workflow-manager
        if($LASTEXITCODE -ne 0){throw 'Neue isolierte Fixture konnte nicht registriert werden.'}
    }
    $env:KH_NATIVE_TEST_CONFIG=$workflowConfig
    & $workflowPhp @workflowFlags 'vereinsverwaltung/bin/native-manager-demo-seed.php'
    if($LASTEXITCODE -ne 0){throw 'Eigene Manager-Fixture konnte nicht ergänzt werden.'}
    & $workflowPhp @workflowFlags 'vereinsverwaltung/bin/document-workflow-manager-demo.php'
    if($LASTEXITCODE -ne 0){throw 'Eigene Gefährdungsbeurteilung konnte nicht ergänzt werden.'}
    $workflowRuntime=Join-Path $workflowRoot '.local/native-cms-integration/workflow-manager-runtime'
    $workflowArguments=@('-n','-d',('extension_dir="'+$workflowExt+'"'),'-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=sodium','-d','extension=openssl','-d','extension=fileinfo','-d','display_errors=0','-d','post_max_size=8M','-d','upload_max_filesize=3M','-S','127.0.0.1:8118','-t','vereinsverwaltung/public','vereinsverwaltung/tests/support/native-manager-router.php')
    $workflowProcess=Start-Process -FilePath $workflowPhp -ArgumentList $workflowArguments -WorkingDirectory $workflowRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $workflowRuntime 'php.stdout.log') -RedirectStandardError (Join-Path $workflowRuntime 'php.stderr.log')
    Write-Output "Lokaler Vereinsmanager: http://127.0.0.1:8118/vereinsverwaltung/?view=documents&tab=workflows (PID $($workflowProcess.Id)). Nur synthetische Daten; Mailversand gesperrt."
}finally{$env:KH_NATIVE_TEST_CONFIG=$workflowPreviousConfig;Pop-Location}
