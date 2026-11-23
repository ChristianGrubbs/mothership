# Pull one backup set from the Mothership VM and keep it on a disk that holds neither the VM nor Windows
# (docs/ops/linux-host.md, "Backups"). Run by scheduled task \Mothership\Mothership Backup Pull.
# The key below is authorized in the VM only for `backup.sh stream`, so this script cannot run anything else there.
param(
  [string]$Destination = 'G:\Mothership\backups',
  [string]$VmHost = 'mothership.mshome.net',
  [string]$KeyFile = (Join-Path $env:USERPROFILE '.ssh\mothership_backup_ed25519'),
  [int]$KeepDays = 14,
  [int]$KeepMin = 8
)
$ErrorActionPreference = 'Stop'
$runId = [guid]::NewGuid().ToString()
New-Item -ItemType Directory -Force -Path $Destination | Out-Null
$eventLog = Join-Path $Destination 'pull-backup.jsonl'

function Write-Event([string]$Level, [string]$Name, [hashtable]$Ctx = @{}) {
  $record = [ordered]@{ ts = (Get-Date).ToUniversalTime().ToString('o'); run_id = $runId; level = $Level; event = $Name;
    loc = 'deploy/linux-host/windows/pull-backup.ps1'; ctx = $Ctx }
  # UTF-8 without a byte-order mark, so every line parses as JSON.
  [IO.File]::AppendAllText($eventLog, ($record | ConvertTo-Json -Compress -Depth 4) + "`n", (New-Object Text.UTF8Encoding $false))
}

$stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMdd'T'HHmmss'Z'")
$part = Join-Path $Destination ".incoming-$stamp.tar"
$errFile = "$part.err"
$work = Join-Path $Destination ".incoming-$stamp"
try {
  Write-Event 'info' 'pull.started' @{ host = $VmHost }
  $ssh = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
  $knownHosts = Join-Path (Split-Path $KeyFile) 'mothership_known_hosts'
  # cmd.exe redirection keeps the tar stream byte-for-byte; PowerShell 5.1 redirection would re-encode it.
  $line = "`"$ssh`" -i `"$KeyFile`" -o BatchMode=yes -o ConnectTimeout=30 -o ServerAliveInterval=30 " +
    "-o UserKnownHostsFile=`"$knownHosts`" -o StrictHostKeyChecking=accept-new mothership@$VmHost > `"$part`" 2> `"$errFile`""
  & cmd.exe /d /c $line
  if ($LASTEXITCODE -ne 0) { throw "ssh exited $LASTEXITCODE`: $((Get-Content -Raw $errFile -ErrorAction SilentlyContinue) -replace '\s+$','')" }

  New-Item -ItemType Directory -Force -Path $work | Out-Null
  & "$env:WINDIR\System32\tar.exe" -xf $part -C $work
  if ($LASTEXITCODE -ne 0) { throw "tar exited $LASTEXITCODE" }
  $manifest = Get-Content -Raw (Join-Path $work 'manifest.json') | ConvertFrom-Json
  foreach ($file in $manifest.files) {
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $work $file.name)).Hash.ToLowerInvariant()
    if ($hash -ne $file.sha256) { throw "sha256 mismatch for $($file.name)" }
  }
  $final = Join-Path $Destination $manifest.set
  if (Test-Path $final) { throw "set $($manifest.set) already exists" }
  Rename-Item -LiteralPath $work -NewName $manifest.set
  Remove-Item -LiteralPath $part, $errFile -Force

  # Keep every set from the last $KeepDays days and never fewer than the newest $KeepMin.
  $sets = Get-ChildItem -LiteralPath $Destination -Directory | Where-Object Name -Match '^\d{8}T\d{6}Z$' | Sort-Object Name -Descending
  $cutoff = (Get-Date).ToUniversalTime().AddDays(-$KeepDays).ToString("yyyyMMdd'T'HHmmss'Z'")
  $pruned = 0
  foreach ($old in ($sets | Select-Object -Skip $KeepMin | Where-Object { $_.Name -lt $cutoff })) {
    Remove-Item -LiteralPath $old.FullName -Recurse -Force
    $pruned++
  }
  Get-ChildItem -LiteralPath $Destination -Filter '.incoming-*' | Where-Object LastWriteTime -lt (Get-Date).AddHours(-6) |
    Remove-Item -Recurse -Force

  $bytes = (Get-ChildItem -LiteralPath $final -File | Measure-Object Length -Sum).Sum
  Write-Event 'info' 'pull.stored' @{ set = $manifest.set; bytes = "$bytes"; tables = "$($manifest.postgres.tables)";
    rows = "$($manifest.postgres.rows)"; pruned = "$pruned" }
  Write-Output "stored $final ($bytes bytes)"
} catch {
  Write-Event 'error' 'pull.failed' @{ message = "$($_.Exception.Message)" }
  throw
}
