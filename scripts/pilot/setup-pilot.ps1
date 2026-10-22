# One time: create the pilot database (empty, no demo data), with the shifts and
# Philippine holidays it needs, and build the assets. Refuses to run twice.
#
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\setup-pilot.ps1

. (Join-Path $PSScriptRoot 'pilot-env.ps1')
Set-Location $PilotRoot

if (Test-Path $PilotDatabase) {
    Write-Host "The pilot database already exists: $PilotDatabase" -ForegroundColor Yellow
    Write-Host 'Nothing changed. (To start over, stop the pilot and move that file away yourself.)'
    exit 1
}

New-Item -ItemType File -Path $PilotDatabase | Out-Null

php artisan migrate --force
if ($LASTEXITCODE -ne 0) { Write-Host 'Migration failed.' -ForegroundColor Red; exit 1 }

# Reference data only: the default shift and the holidays. Never DemoSeeder.
php artisan db:seed --class=ShiftSeeder --force
php artisan db:seed --class=HolidaySeeder --force

npm run build

Write-Host ''
Write-Host 'Pilot database ready.' -ForegroundColor Green
Write-Host 'Next: create the first Super Admin (it asks for their employee number and a password):'
Write-Host '  powershell -ExecutionPolicy Bypass -File scripts\pilot\pilot-artisan.ps1 app:create-super-admin you@example.com "Your Name"'
