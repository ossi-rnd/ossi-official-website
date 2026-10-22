# Keeps one pilot process running: if it stops or crashes, it starts again after
# 5 seconds. Started (hidden) by start-pilot.ps1, one per process; stop-pilot.ps1
# ends them. Its output goes to storage\logs\pilot\<name>.log.

param(
    [Parameter(Mandatory)][ValidateSet('web', 'queue', 'scheduler')][string] $Name
)

. (Join-Path $PSScriptRoot 'pilot-env.ps1')
Set-Location $PilotRoot

$log = Join-Path $PilotLogs "$Name.log"
# The PHP built-in server: it inherits the pilot settings above directly (no child
# process re-reading .env), and answers to any host name. router.php compresses and
# caches the built CSS/JS, then hands everything else to Laravel's own router.
$router = Join-Path $PSScriptRoot 'router.php'

$commands = @{
    # Laravel's router expects to run from public\ (as `artisan serve` does).
    web = "cd /d `"$PilotRoot\public`" && php -S 127.0.0.1:$PilotPort `"$router`""
    # Restarts every hour too, so a code update is picked up even without queue:restart.
    queue = "cd /d `"$PilotRoot`" && php artisan queue:work --sleep=3 --tries=3 --max-time=3600"
    scheduler = "cd /d `"$PilotRoot`" && php artisan schedule:work"
}

while ($true) {
    Add-Content -Path $log -Value "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] starting $Name"

    # Through cmd, so the log stays plain text (PowerShell 5's >> writes UTF-16).
    cmd.exe /c "$($commands[$Name]) >> `"$log`" 2>&1"

    Add-Content -Path $log -Value "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $Name stopped (exit $LASTEXITCODE); restarting in 5 s"
    Start-Sleep -Seconds 5
}
