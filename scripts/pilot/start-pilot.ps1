# Starts the pilot: the web server, the queue worker and the scheduler (each kept
# running by keep-running.ps1, in hidden windows), and the Tailscale public address.
# Safe to run again: anything already running is left alone.
#
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\start-pilot.ps1

. (Join-Path $PSScriptRoot 'pilot-env.ps1')

if (-not (Test-Path $PilotDatabase)) {
    Write-Host 'No pilot database yet. Run setup-pilot.ps1 first.' -ForegroundColor Red
    exit 1
}

$keeper = Join-Path $PSScriptRoot 'keep-running.ps1'

foreach ($name in 'web', 'queue', 'scheduler') {
    $running = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
        Where-Object { $_.CommandLine -like "*keep-running.ps1*-Name $name*" }

    if ($running) {
        Write-Host "${name}: already running"
        continue
    }

    Start-Process powershell.exe -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$keeper`"", '-Name', $name)
    Write-Host "${name}: started"
}

# Tailscale connected first: after a restart it can sit at "starting" until its tray app
# opens (seen 2026-10-21; unattended mode, `tailscale set --unattended=true`, avoids it).
$state = { try { (& $Tailscale status --json 2>$null | ConvertFrom-Json).BackendState } catch { '' } }
if ((& $state) -ne 'Running') {
    if (-not (Get-Process tailscale-ipn -ErrorAction SilentlyContinue)) {
        Start-Process 'C:\Program Files\Tailscale\tailscale-ipn.exe'
    }
    foreach ($i in 1..30) {
        if ((& $state) -eq 'Running') { break }
        Start-Sleep -Seconds 2
    }
}
if ((& $state) -ne 'Running') {
    Write-Host 'Tailscale is not connected yet, so the public address is off. Open Tailscale from the tray, sign in if asked, then run this again.' -ForegroundColor Yellow
    exit 1
}

# The fixed public address (it stays set across restarts; this just makes sure).
& $Tailscale funnel --bg --yes "http://127.0.0.1:$PilotPort" | Out-Null

Write-Host ''
Write-Host "Pilot running at $PilotUrl" -ForegroundColor Green
Write-Host "Logs: $PilotLogs"
