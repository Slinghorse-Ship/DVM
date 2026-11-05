param([string]$Workspace = (Resolve-Path "$PSScriptRoot/../..").Path, [string]$Ids = $env:KULTURHUS_TUTORIAL_IDS)
$taskSource = Join-Path $Workspace 'docs/vereinsverwaltung/handbuch/tutorials.json'
$taskOutput = Join-Path $Workspace '.local/tutorial-build'
New-Item -ItemType Directory -Force -Path $taskOutput | Out-Null
$taskVoice = New-Object -ComObject SAPI.SpVoice
$taskGerman = @($taskVoice.GetVoices() | Where-Object { $_.GetDescription() -match 'Hedda.*German' })
if ($taskGerman.Count -ne 1) { throw 'German narration voice unavailable; do not silently produce an English or mute tutorial.' }
$taskVoice.Voice = $taskGerman[0]
$taskVoice.Rate = -1
$taskTutorials = Get-Content -Raw -Encoding utf8 $taskSource | ConvertFrom-Json
$taskSelected = @($Ids -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
foreach ($taskId in $taskSelected) { if ($taskId -notin $taskTutorials.id) { throw ('Unknown tutorial: ' + $taskId) } }
foreach ($tutorial in $taskTutorials) {
    if ($taskSelected.Count -gt 0 -and $tutorial.id -notin $taskSelected) { continue }
    $sceneNumber = 0
    foreach ($scene in $tutorial.scenes) {
        $sceneNumber++
        $taskFilename = Join-Path $taskOutput ($tutorial.id + '-' + $sceneNumber + '.wav')
        $taskStream = New-Object -ComObject SAPI.SpFileStream
        try {
            $taskStream.Open($taskFilename, 3, $false)
            $taskVoice.AudioOutputStream = $taskStream
            [void]$taskVoice.Speak($scene.text, 0)
        } finally { $taskStream.Close() }
    }
    Write-Output ('Narration: ' + $tutorial.id)
}
