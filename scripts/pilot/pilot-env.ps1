# OSSI laptop pilot: the settings that differ from development.
#
# Dot-sourced by the other pilot scripts (. .\pilot-env.ps1). Real environment
# variables win over .env (Laravel loads .env without overwriting them), so these
# override only what's listed here. Everything else, the mail password included,
# still comes from your .env, which these scripts never change.

$PilotRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$PilotPort = 8010
$PilotUrl = 'https://ossi.tail4ab8aa.ts.net'
$PilotDatabase = Join-Path $PilotRoot 'database\pilot.sqlite'
$PilotBackups = Join-Path $PilotRoot 'database\pilot-backups'
$PilotLogs = Join-Path $PilotRoot 'storage\logs\pilot'
$Tailscale = 'C:\Program Files\Tailscale\tailscale.exe'

# Production mode: debug pages off, destructive DB commands blocked, strict passwords, HTTPS.
$env:APP_ENV = 'production'
$env:APP_DEBUG = 'false'
$env:APP_URL = $PilotUrl
$env:ASSET_URL = $PilotUrl

# Its own database: real people only, no demo data. Sessions, cache and the queue live
# in the database too, so they're separate from development as well.
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $PilotDatabase

$env:SESSION_SECURE_COOKIE = 'true'

# Reverb (live bell) isn't reachable through the tunnel; the bell updates on each page load.
$env:BROADCAST_CONNECTION = 'log'

New-Item -ItemType Directory -Force -Path $PilotBackups, $PilotLogs | Out-Null
