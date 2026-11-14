param(
    [Parameter(Mandatory = $true)]
    [string]$BackupFile
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.yaml'
$envFile = Join-Path $PSScriptRoot '.env.local'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw "Missing $envFile. Copy .env.example to .env.local and configure it first."
}
$resolvedBackup = (Resolve-Path -LiteralPath $BackupFile).Path
if ((Get-Item -LiteralPath $resolvedBackup).Length -eq 0 -or [IO.Path]::GetExtension($resolvedBackup) -ne '.sql') {
    throw 'Provide a non-empty .sql dump.'
}
$confirmation = Read-Host 'This overwrites objects/data in the configured database. Type RESTORE to continue'
if ($confirmation -cne 'RESTORE') { throw 'Restore cancelled.' }

$startInfo = [System.Diagnostics.ProcessStartInfo]::new()
$startInfo.FileName = 'docker'
$startInfo.UseShellExecute = $false
$startInfo.RedirectStandardInput = $true
$startInfo.RedirectStandardOutput = $true
$startInfo.RedirectStandardError = $true
@('compose', '--env-file', $envFile, '-f', $composeFile, 'exec', '-T', 'mysql', 'sh', '-c', 'exec mysql --user=root --password="$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"') | ForEach-Object { [void]$startInfo.ArgumentList.Add($_) }
$process = [System.Diagnostics.Process]::new()
$process.StartInfo = $startInfo
[void]$process.Start()
$stderrTask = $process.StandardError.ReadToEndAsync()
$stdoutTask = $process.StandardOutput.ReadToEndAsync()
$inputStream = [System.IO.File]::OpenRead($resolvedBackup)
try {
    $inputStream.CopyTo($process.StandardInput.BaseStream)
} finally {
    $inputStream.Dispose()
    $process.StandardInput.Close()
}
$process.WaitForExit()
$stderr = $stderrTask.GetAwaiter().GetResult()
if ($process.ExitCode -ne 0) { throw "Database restore failed. $stderr" }
Write-Output 'Restore completed. Verify the application and migration status before resuming traffic.'
