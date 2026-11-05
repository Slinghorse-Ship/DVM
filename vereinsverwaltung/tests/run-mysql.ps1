param([Parameter(Mandatory=$true)][string[]]$Tests)
$ErrorActionPreference='Stop'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$dockerArgs=@('run','--rm','--network','kulturhus-mysql-regression','-e','VEREIN_TEST_MYSQL=isolated-regression-only','-e','VEREIN_PYTHON=/opt/kulturhus/runtime-pdf-python')
foreach($part in @('vereinsverwaltung','banking','processwire','local-processwire','distribution','tests')){
 if(Test-Path (Join-Path $repo $part)){$dockerArgs+=@('--mount',"type=bind,source=$repo/$part,target=/workspace/$part,readonly")}
}
$dockerArgs+=@('--mount',"type=bind,source=$repo/audit,target=/test-results",'--workdir','/workspace','--entrypoint','php','kulturhus-mysql-regression:php8.4','vereinsverwaltung/tests/run-mysql-suite.php')
& docker @dockerArgs @Tests
exit $LASTEXITCODE
