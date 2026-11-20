$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$services = docker compose ps --format json | ForEach-Object { $_ | ConvertFrom-Json }
if ($LASTEXITCODE) { throw 'Cannot inspect containers.' }
$failed = @($services | Where-Object { $_.State -ne 'running' -or ($_.Health -and $_.Health -ne 'healthy') })
$services | Select-Object Service, State, Health | Format-Table
if ($services.Count -lt 8 -or $failed.Count) { throw 'A required service is absent or unhealthy.' }
$response = Invoke-WebRequest 'https://localhost:4200/' -SkipCertificateCheck -TimeoutSec 10
if ($response.StatusCode -ne 200) { throw 'Console unavailable.' }
docker compose exec -T application php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "Failed jobs (historical): ".Illuminate\Support\Facades\DB::table("failed_jobs")->count()."; last 24h: ".Illuminate\Support\Facades\DB::table("failed_jobs")->where("failed_at", ">", now()->subDay())->count()."\n";'
if ($LASTEXITCODE) { throw 'Failed queue inspection.' }
$disks = Get-Volume | Where-Object { $_.DriveLetter -and $_.Size -gt 0 }
foreach ($disk in $disks) {
    $free = [math]::Round($disk.SizeRemaining / $disk.Size * 100, 1)
    Write-Host "Disk $($disk.DriveLetter): free $free%"
    if ($free -lt 10) { Write-Warning "Disk $($disk.DriveLetter) is below 10% free." }
}
