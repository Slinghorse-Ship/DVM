param([int]$Port = 8107, [string]$Fixture = 'demo')
$ErrorActionPreference='Stop'
$nativeRoot = Split-Path -Parent $PSScriptRoot
if($Port -lt 1024 -or $Port -gt 65535 -or $Port -in @(8091,8092)){throw 'Eigenen Loopback-Port zwischen 1024 und 65535 wählen; 8091/8092 sind reserviert.'}
$nativePhp=Join-Path $nativeRoot '.local/php-8.5.10/php.exe'
if($Fixture -notmatch '^[a-z][a-z0-9-]{0,35}$'){throw 'Ungültige lokale Fixture-Bezeichnung.'}
$nativeConfig=Join-Path $nativeRoot ".local/native-cms-integration/$Fixture.json"
if(!(Test-Path -LiteralPath $nativeConfig)){throw 'Zuerst bin/native-fixture.php demo ausführen. Bestehende Datenbanken werden nicht neu initialisiert.'}
$nativeListener=[System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback,$Port)
try{$nativeListener.Start()}catch{throw 'Port belegt. Bestehende Prozesse bleiben unberührt.'}finally{$nativeListener.Stop()}
$env:KH_NATIVE_TEST_CONFIG=$nativeConfig
$env:KH_NATIVE_LOCAL_DEMO='synthetic'
$env:KH_NATIVE_DEMO_PORT=[string]$Port
& $nativePhp -d extension=pdo_mysql (Join-Path $PSScriptRoot 'bin/native-demo-seed.php')
if($LASTEXITCODE -ne 0){throw 'Demo-Initialisierung fehlgeschlagen.'}
$nativeArgs=@('-d','extension=pdo_mysql','-d','display_errors=0','-d','upload_max_filesize=12M','-d','post_max_size=14M','-S',"127.0.0.1:$Port",'-t',(Join-Path $PSScriptRoot 'native/Web'),(Join-Path $PSScriptRoot 'native/Web/router.php'))
$nativeProcess=Start-Process -FilePath $nativePhp -ArgumentList $nativeArgs -WorkingDirectory $nativeRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $nativeRoot ".local/native-cms-integration/$Fixture.stdout.log") -RedirectStandardError (Join-Path $nativeRoot ".local/native-cms-integration/$Fixture.stderr.log")
$nativeProcess.Id | Set-Content -LiteralPath (Join-Path $nativeRoot ".local/native-cms-integration/$Fixture.pid")
Write-Output "Lokale native Demo gestartet: http://127.0.0.1:$Port/ (PID $($nativeProcess.Id))"
