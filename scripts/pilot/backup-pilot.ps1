# Backs up the pilot database while it's running (SQLite's VACUUM INTO makes a
# consistent copy even mid-write), into database\pilot-backups. Keeps the last 14.
#
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\backup-pilot.ps1

. (Join-Path $PSScriptRoot 'pilot-env.ps1')

if (-not (Test-Path $PilotDatabase)) {
    Write-Host 'No pilot database to back up.' -ForegroundColor Yellow
    exit 1
}

$target = Join-Path $PilotBackups ("pilot-{0}.sqlite" -f (Get-Date -Format 'yyyy-MM-dd-HHmm'))

php (Join-Path $PSScriptRoot 'backup.php') $PilotDatabase $target
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $target)) {
    Add-Content -Path (Join-Path $PilotLogs 'backup.log') -Value "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] backup FAILED"
    Write-Host 'Backup failed.' -ForegroundColor Red
    exit 1
}

# Keep the 14 newest.
Get-ChildItem $PilotBackups -Filter 'pilot-*.sqlite' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 14 | Remove-Item -Force

Add-Content -Path (Join-Path $PilotLogs 'backup.log') -Value "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] backup ok: $target"
Write-Host "Backed up to $target" -ForegroundColor Green
