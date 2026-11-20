param([string[]]$DnsNames = @('localhost'), [string[]]$IpAddresses = @('127.0.0.1'))
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
foreach ($name in $DnsNames) { if ($name -notmatch '^[A-Za-z0-9.-]+$') { throw 'Invalid DNS name.' } }
foreach ($ip in $IpAddresses) { $null = [Net.IPAddress]::Parse($ip) }
$certDir = Join-Path (Get-Location) 'docker/secrets/tls'
$null = New-Item -ItemType Directory -Force -Path $certDir
icacls $certDir /inheritance:r /grant:r "$([Security.Principal.WindowsIdentity]::GetCurrent().Name):(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
# All keys are generated locally; never upload or commit ca.key or key.pem.
if (!(Test-Path -LiteralPath (Join-Path $certDir 'ca.key'))) {
    docker compose exec -T console openssl req -x509 -newkey rsa:3072 -nodes -days 1825 -keyout /tmp/ddmaps-ca.key -out /tmp/ddmaps-ca.pem -subj '/CN=DDmaps Local CA' 2>$null
    if ($LASTEXITCODE) { throw 'Local CA generation failed.' }
    docker cp fleetbase-console-1:/tmp/ddmaps-ca.key (Join-Path $certDir 'ca.key')
    docker cp fleetbase-console-1:/tmp/ddmaps-ca.pem (Join-Path $certDir 'ca.pem')
}
docker cp (Join-Path $certDir 'ca.key') fleetbase-console-1:/tmp/ddmaps-ca.key
docker cp (Join-Path $certDir 'ca.pem') fleetbase-console-1:/tmp/ddmaps-ca.pem
$san = (@($DnsNames | ForEach-Object { "DNS:$_" }) + @($IpAddresses | ForEach-Object { "IP:$_" })) -join ','
"subjectAltName=$san`nextendedKeyUsage=serverAuth`nkeyUsage=digitalSignature,keyEncipherment`nbasicConstraints=CA:FALSE" | Set-Content -LiteralPath (Join-Path $certDir 'server.ext') -Encoding ascii
docker cp (Join-Path $certDir 'server.ext') fleetbase-console-1:/tmp/ddmaps-server.ext
docker compose exec -T console openssl req -new -newkey rsa:2048 -nodes -keyout /tmp/ddmaps-key.pem -out /tmp/ddmaps-server.csr -subj '/CN=DDmaps' 2>$null
if ($LASTEXITCODE) { throw 'Server key generation failed.' }
docker compose exec -T console openssl x509 -req -in /tmp/ddmaps-server.csr -CA /tmp/ddmaps-ca.pem -CAkey /tmp/ddmaps-ca.key -CAcreateserial -days 365 -extfile /tmp/ddmaps-server.ext -out /tmp/ddmaps-cert.pem 2>$null
if ($LASTEXITCODE) { throw 'Certificate signing failed.' }
docker cp fleetbase-console-1:/tmp/ddmaps-key.pem (Join-Path $certDir 'key.pem')
docker cp fleetbase-console-1:/tmp/ddmaps-cert.pem (Join-Path $certDir 'cert.pem')
docker compose exec -T console rm -f /tmp/ddmaps-ca.key /tmp/ddmaps-ca.pem /tmp/ddmaps-ca.srl /tmp/ddmaps-key.pem /tmp/ddmaps-server.csr /tmp/ddmaps-cert.pem /tmp/ddmaps-server.ext
docker compose restart console
if ($LASTEXITCODE) { throw 'Cannot reload console with new certificate.' }
Write-Host 'Trust ca.pem on each device using its system settings. Distribute only ca.pem; keep all private keys on this machine.'
