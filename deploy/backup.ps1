param(
    [string]$DestinationDirectory = (Join-Path $env:USERPROFILE 'miksprit-backups')
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.yaml'
$envFile = Join-Path $PSScriptRoot '.env.local'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw "Missing $envFile. Copy .env.example to .env.local and configure it first."
}
New-Item -ItemType Directory -Path $DestinationDirectory -Force | Out-Null
$resolvedDestination = (Resolve-Path -LiteralPath $DestinationDirectory).Path
$backupPath = Join-Path $resolvedDestination ("miksprit-{0:yyyyMMdd-HHmmss}.sql" -f (Get-Date))

$startInfo = [System.Diagnostics.ProcessStartInfo]::new()
$startInfo.FileName = 'docker'
$startInfo.UseShellExecute = $false
$startInfo.RedirectStandardOutput = $true
$startInfo.RedirectStandardError = $true
@('compose', '--env-file', $envFile, '-f', $composeFile, 'exec', '-T', 'mysql', 'sh', '-c', 'exec mysqldump --user=root --password="$MYSQL_ROOT_PASSWORD" --single-transaction --routines --events "$MYSQL_DATABASE"') | ForEach-Object { [void]$startInfo.ArgumentList.Add($_) }
$startInfo.RedirectStandardOutput = $true
$startInfo.StandardOutputEncoding = [System.Text.Encoding]::UTF8
$process = [System.Diagnostics.Process]::new()
$process.StartInfo = $startInfo
[void]$process.Start()
$stderrTask = $process.StandardError.ReadToEndAsync()
$file = [System.IO.File]::Create($backupPath)
try {
    $process.StandardOutput.BaseStream.CopyTo($file)
} finally {
    $file.Dispose()
}
$process.WaitForExit()
$stderr = $stderrTask.GetAwaiter().GetResult()
if ($process.ExitCode -ne 0 -or (Get-Item -LiteralPath $backupPath).Length -eq 0) {
    Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
    throw "Database dump failed. $stderr"
}

Write-Output "Backup created: $backupPath"
Write-Output "Protect and encrypt this file, store an off-site copy, and perform a restore drill."
