param([switch]$Build, [switch]$Migrate)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
docker info --format '{{.ServerVersion}}' | Out-Null
if ($LASTEXITCODE) { throw 'Start Docker Desktop first.' }
if (!(Test-Path -LiteralPath security.local.json)) { throw 'Run scripts/initialize-security.ps1 once before starting this audited installation.' }
if ($Build) {
    docker compose build application console
    if ($LASTEXITCODE) { throw 'Image build failed; current containers were not replaced.' }
}
docker compose up -d --wait --wait-timeout 180
if ($LASTEXITCODE) { throw 'Services did not become healthy. Run scripts/health-check.ps1.' }
if ($Migrate) {
    & "$PSScriptRoot/backup-database.ps1" -VerifyRestore
    docker compose run --rm --no-deps migration
    if ($LASTEXITCODE) { throw 'Migration failed. Preserve the backup and inspect migration status.' }
    docker compose restart application queue scheduler
    if ($LASTEXITCODE) { throw 'Service restart failed.' }
}
& "$PSScriptRoot/health-check.ps1"
Write-Host 'Console: https://localhost:4200 ; driver: https://localhost:4200/driver-track'
Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -notmatch 'Loopback|vEthernet|WSL|TAP' -and $_.IPAddress -notmatch '^169\.254\.' } | ForEach-Object { Write-Host "LAN: https://$($_.IPAddress):4200 (trusted certificate required for phone GPS)" }
