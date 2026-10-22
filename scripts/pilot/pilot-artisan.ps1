# Run any artisan command against the PILOT (its database, production mode), e.g.:
#
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\pilot-artisan.ps1 migrate --force
#   powershell -ExecutionPolicy Bypass -File scripts\pilot\pilot-artisan.ps1 app:create-super-admin you@example.com "Your Name"
#
# Plain `php artisan …` in a normal terminal still works on your development database.

. (Join-Path $PSScriptRoot 'pilot-env.ps1')
Set-Location $PilotRoot

php artisan @args
exit $LASTEXITCODE
