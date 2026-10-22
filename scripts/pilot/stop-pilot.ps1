# Stops the pilot's web server, queue worker and scheduler. The public address then
# shows an error until start-pilot.ps1 runs again. Your development setup isn't touched.
#
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\stop-pilot.ps1

$all = Get-CimInstance Win32_Process
$keepers = $all | Where-Object { $_.Name -eq 'powershell.exe' -and $_.CommandLine -like '*keep-running.ps1*' }

function Stop-Tree([int] $ProcessId) {
    foreach ($child in ($all | Where-Object { $_.ParentProcessId -eq $ProcessId })) {
        Stop-Tree $child.ProcessId
    }
    Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
}

foreach ($keeper in $keepers) {
    Stop-Tree $keeper.ProcessId
}

Write-Host "Stopped $(@($keepers).Count) pilot process(es)."
