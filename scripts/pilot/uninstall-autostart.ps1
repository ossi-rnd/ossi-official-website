# Removes the pilot's scheduled tasks (start at sign-in, daily backup). Doesn't stop
# a running pilot (stop-pilot.ps1) or touch the public address
# ("C:\Program Files\Tailscale\tailscale.exe" funnel --https=443 off).

foreach ($task in 'OSSI Pilot - start', 'OSSI Pilot - backup') {
    Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue
}

Write-Host 'Pilot scheduled tasks removed.'
