param()
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$secretPath = Join-Path (Get-Location) 'security.local.json'
function New-Secret { [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant() }
if (!(Test-Path -LiteralPath $secretPath)) {
    @{ root = (New-Secret); app = (New-Secret); socket = (New-Secret); databaseApplied = $false } | ConvertTo-Json | Set-Content -LiteralPath $secretPath -Encoding utf8
}
$security = Get-Content -LiteralPath $secretPath -Raw | ConvertFrom-Json
# Only this Windows user and SYSTEM may read the local credential file.
icacls $secretPath /inheritance:r /grant:r "$([Security.Principal.WindowsIdentity]::GetCurrent().Name):(F)" 'SYSTEM:(F)' | Out-Null
if (!$security.databaseApplied) {
    # This migration targets the audited, existing database with empty root password.
    # SQL goes through stdin; no passwords are placed in process arguments or logs.
    $sql = "CREATE USER IF NOT EXISTS 'ddmaps_app'@'%' IDENTIFIED BY '$($security.app)';`nALTER USER 'ddmaps_app'@'%' IDENTIFIED BY '$($security.app)';`nGRANT SELECT, INSERT, UPDATE, DELETE ON fleetbase.* TO 'ddmaps_app'@'%';`nALTER USER 'root'@'localhost' IDENTIFIED BY '$($security.root)';`nALTER USER 'root'@'%' IDENTIFIED BY '$($security.root)';"
    $sql | docker exec -i fleetbase-database-1 mysql -uroot
    if ($LASTEXITCODE -ne 0) { throw 'Database security migration failed. Inspect user state without printing credentials before retrying.' }
    $security.databaseApplied = $true
    $security | ConvertTo-Json | Set-Content -LiteralPath $secretPath -Encoding utf8
}
$envPath = Join-Path (Get-Location) '.env'
$content = if (Test-Path -LiteralPath $envPath) { Get-Content -LiteralPath $envPath -Raw } else { '' }
$values = @{ DDMAPS_DB_ROOT_PASSWORD = $security.root; DDMAPS_DB_APP_PASSWORD = $security.app; DDMAPS_SOCKET_AUTH_KEY = $security.socket }
foreach ($key in $values.Keys) {
    $content = [regex]::Replace($content, "(?m)^$key=.*\r?\n?", '')
    $content += "`n$key=$($values[$key])`n"
}
[IO.File]::WriteAllText($envPath, $content)
icacls $envPath /inheritance:r /grant:r "$([Security.Principal.WindowsIdentity]::GetCurrent().Name):(F)" 'SYSTEM:(F)' | Out-Null
$tlsDirectory = Join-Path (Get-Location) 'docker/secrets/tls'
$null = New-Item -ItemType Directory -Force -Path $tlsDirectory
icacls $tlsDirectory /inheritance:r /grant:r "$([Security.Principal.WindowsIdentity]::GetCurrent().Name):(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
Write-Host 'Database root password set, dedicated DML account created, socket signing key saved privately. Run docker compose up -d to apply container configuration.'
