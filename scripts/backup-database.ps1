param([string]$Destination, [switch]$VerifyRestore)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
if (-not $Destination) { $Destination = Join-Path $projectRoot ('backups/' + (Get-Date -Format 'yyyyMMdd-HHmmss')) }
$null = New-Item -ItemType Directory -Force -Path $Destination
$Destination = (Resolve-Path -LiteralPath $Destination).Path
icacls $Destination /inheritance:r /grant:r "$([Security.Principal.WindowsIdentity]::GetCurrent().Name):(OI)(CI)F" 'SYSTEM:(OI)(CI)F' | Out-Null
$container = 'fleetbase-database-1'
$dumpFile = '/tmp/ddmaps-backup.sql'
docker exec $container sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --events --hex-blob --no-tablespaces fleetbase > /tmp/ddmaps-backup.sql'
if ($LASTEXITCODE) { throw 'Database backup failed.' }
docker cp "${container}:$dumpFile" (Join-Path $Destination 'fleetbase.sql')
if ($LASTEXITCODE) { throw 'Could not copy database backup.' }
if ((Get-Item -LiteralPath (Join-Path $Destination 'fleetbase.sql')).Length -lt 1000) { throw 'Database backup is unexpectedly small.' }
if ($VerifyRestore) {
    $restoreDatabase = 'ddmaps_restore_' + (Get-Date -Format 'yyyyMMddHHmmss')
    docker exec $container sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot -e 'CREATE DATABASE $restoreDatabase'"
    if ($LASTEXITCODE) { throw 'Cannot create isolated restore database.' }
    try {
        docker exec $container sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot $restoreDatabase < $dumpFile"
        if ($LASTEXITCODE) { throw 'Isolated database restore failed.' }
        $counts = docker exec $container sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot --batch --skip-column-names -e 'SELECT table_schema, COUNT(*) FROM information_schema.tables WHERE table_schema IN (`"fleetbase`", `"$restoreDatabase`") GROUP BY table_schema'"
        if ($LASTEXITCODE) { throw 'Restore table verification failed.' }
        $counts | Set-Content -LiteralPath (Join-Path $Destination 'restore-verification.txt')
        $tableCounts = @($counts | ForEach-Object { ($_ -split '\s+')[1] })
        if ($tableCounts.Count -ne 2 -or $tableCounts[0] -ne $tableCounts[1]) { throw 'Restored table counts differ from source.' }
        'Isolated restore verified.' | Write-Output
    } finally {
        docker exec $container sh -c "MYSQL_PWD=`"`$MYSQL_ROOT_PASSWORD`" mysql -uroot -e 'DROP DATABASE $restoreDatabase'"
        if ($LASTEXITCODE) { throw 'Could not remove isolated restore database.' }
    }
}
docker exec $container rm -f $dumpFile
Get-FileHash -LiteralPath (Join-Path $Destination 'fleetbase.sql') -Algorithm SHA256 | Select-Object Hash | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Destination 'checksum.json')
Write-Output "Backup saved: $Destination"
