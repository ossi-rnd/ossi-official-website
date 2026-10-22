# Registers two Windows scheduled tasks for your account:
#   "OSSI Pilot - start"   at sign-in: start-pilot.ps1 (so a restart doesn't leave it down)
#   "OSSI Pilot - backup"  every day at 2:00 AM: backup-pilot.ps1
# Remove them after the pilot with: uninstall-autostart.ps1
#
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\install-autostart.ps1

$user = "$env:USERDOMAIN\$env:USERNAME"
$run = { param($script) New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$(Join-Path $PSScriptRoot $script)`"" }
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName 'OSSI Pilot - start' -Force -Action (& $run 'start-pilot.ps1') -Trigger (New-ScheduledTaskTrigger -AtLogOn -User $user) -Settings $settings -Principal $principal | Out-Null
Register-ScheduledTask -TaskName 'OSSI Pilot - backup' -Force -Action (& $run 'backup-pilot.ps1') -Trigger (New-ScheduledTaskTrigger -Daily -At 2am) -Settings $settings -Principal $principal | Out-Null

Write-Host 'Scheduled: start at sign-in, backup daily at 2:00 AM.' -ForegroundColor Green
Write-Host 'Remove after the pilot with: powershell -ExecutionPolicy Bypass -File scripts\pilot\uninstall-autostart.ps1'
