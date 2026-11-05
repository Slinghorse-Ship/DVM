param(
    [Parameter(Mandatory=$true)][string]$Fixture,
    [switch]$Stop
)
$ErrorActionPreference='Stop'
if($Fixture -notmatch '^[a-z][a-z0-9-]{0,35}$'){throw 'Ungültige registrierte Fixture-Bezeichnung.'}
$publicationRoot=[IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$publicationDirectory=Join-Path $publicationRoot '.local/native-cms-integration'
$publicationConfig=Join-Path $publicationDirectory ($Fixture+'.json')
if(!(Test-Path -LiteralPath $publicationConfig -PathType Leaf)){throw 'Registrierte synthetische Fixture fehlt; es wird keine Datenbank angelegt.'}
$publicationRuntime=Join-Path $publicationDirectory 'publication-workers'
if(!(Test-Path -LiteralPath $publicationRuntime)){New-Item -ItemType Directory -Path $publicationRuntime | Out-Null}
$publicationStatus=Join-Path $publicationRuntime ($Fixture+'.status.json')
$publicationStop=Join-Path $publicationRuntime ($Fixture+'.stop')
$publicationPhp=Join-Path $publicationRoot '.local/php-8.5.10/php.exe'
$publicationScript=Join-Path $PSScriptRoot 'bin/native-publication-worker.php'
function Read-PublicationState {
    if(Test-Path -LiteralPath $publicationStatus){try{return Get-Content -Raw -LiteralPath $publicationStatus | ConvertFrom-Json}catch{return $null}}
    return $null
}
function Test-PublicationProcess($state) {
    if(!$state -or $state.state -ne 'running' -or $state.fixture -ne $Fixture){return $false}
    $process=Get-Process -Id ([int]$state.pid) -ErrorAction SilentlyContinue
    if(!$process){return $false}
    try {
        return [string]::Equals($process.Path,$publicationPhp,[StringComparison]::OrdinalIgnoreCase) -and [Math]::Abs(($process.StartTime.ToUniversalTime()-[DateTimeOffset]::FromUnixTimeSeconds([long]$state.started_at).UtcDateTime).TotalSeconds) -lt 10
    }catch{return $false}
}
$publicationPrevious=Read-PublicationState
if($Stop){
    if(!(Test-PublicationProcess $publicationPrevious)){Write-Output "Kein bestätigter lokaler Publikationsworker für $Fixture aktiv.";return}
    if($publicationPrevious.instance -notmatch '^[a-f0-9]{16}$'){throw 'Workerinstanz ungültig; kein Prozess wird beendet.'}
    [IO.File]::WriteAllText($publicationStop,[string]$publicationPrevious.instance)
    for($publicationAttempt=0;$publicationAttempt -lt 40;$publicationAttempt++){
        Start-Sleep -Milliseconds 250
        $publicationState=Read-PublicationState
        if(!$publicationState -or $publicationState.instance -ne $publicationPrevious.instance -or $publicationState.state -ne 'running'){
            Write-Output "Lokaler Publikationsworker für $Fixture beendet (PID $($publicationPrevious.pid)).";return
        }
    }
    Write-Output "Stopp für $Fixture angefordert; ein laufender Datenbankvorgang darf sauber enden (PID $($publicationPrevious.pid)).";return
}
if(Test-PublicationProcess $publicationPrevious){Write-Output "Lokaler Publikationsworker läuft bereits für $Fixture (PID $($publicationPrevious.pid)).";return}
$publicationPreviousConfig=$env:KH_NATIVE_TEST_CONFIG
try {
    $env:KH_NATIVE_TEST_CONFIG=$publicationConfig
    $publicationArguments=@('-d','extension=pdo_mysql','-d','display_errors=0',('"'+$publicationScript+'"'),'--serve')
    $publicationProcess=Start-Process -FilePath $publicationPhp -ArgumentList $publicationArguments -WorkingDirectory $publicationRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $publicationRuntime ($Fixture+'.stdout.log')) -RedirectStandardError (Join-Path $publicationRuntime ($Fixture+'.stderr.log'))
    for($publicationAttempt=0;$publicationAttempt -lt 30;$publicationAttempt++){
        Start-Sleep -Milliseconds 200
        $publicationState=Read-PublicationState
        if($publicationState -and [int]$publicationState.pid -eq $publicationProcess.Id -and (Test-PublicationProcess $publicationState)){
            Write-Output "Lokaler Publikationsworker gestartet für $Fixture (PID $($publicationProcess.Id)); läuft bis zum expliziten Stopp oder Prozessende.";return
        }
        if($publicationProcess.HasExited){throw 'Publikationsworker vorzeitig beendet; lokales Workerprotokoll prüfen.'}
    }
    throw 'Start konnte noch nicht bestätigt werden; lokales Workerprotokoll prüfen. Kein fremder Prozess wurde beendet.'
}finally{$env:KH_NATIVE_TEST_CONFIG=$publicationPreviousConfig}
