# Install the backup pull on the Windows host (docs/ops/linux-host.md, "Backups"). Run once, elevated, as the account
# that owns the VM. Idempotent: it keeps an existing key and re-registers the task.
param(
  [string]$Destination = 'G:\Mothership\backups',
  [string]$ScriptDir = 'D:\Mothership\scripts',
  [string]$KeyFile = (Join-Path $env:USERPROFILE '.ssh\mothership_backup_ed25519'),
  [string]$User = $env:USERNAME
)
$ErrorActionPreference = 'Stop'
# Resolve against PowerShell's current location (GetFullPath would use the process directory) and drop a trailing \.
$Destination = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Destination)
# A drive or share root would be drive-relative once trimmed (G:\ -> G:) and is never a backup folder.
if ($Destination.TrimEnd('\') -eq ([IO.Path]::GetPathRoot($Destination)).TrimEnd('\')) { throw "Destination must be a folder, not the root $Destination" }
$Destination = $Destination.TrimEnd('\')
New-Item -ItemType Directory -Force -Path $Destination, $ScriptDir, (Split-Path $KeyFile) | Out-Null
# The sets include app.env and the tunnel credentials: only the owner, SYSTEM and Administrators may read them.
# Replace the folder's DACL outright (no inherited or leftover explicit entries), make everything already inside
# inherit only from it, then check the result.
$allowed = @(
  (New-Object Security.Principal.NTAccount("$env:COMPUTERNAME\$User")).Translate([Security.Principal.SecurityIdentifier]).Value,
  'S-1-5-18',      # SYSTEM
  'S-1-5-32-544'   # Administrators
)
$acl = New-Object Security.AccessControl.DirectorySecurity
$acl.SetAccessRuleProtection($true, $false)
foreach ($sid in $allowed) {
  $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule(
    (New-Object Security.Principal.SecurityIdentifier($sid)), 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
}
Set-Acl -LiteralPath $Destination -AclObject $acl
if (Get-ChildItem -LiteralPath $Destination -Force) {
  & icacls.exe (Join-Path $Destination '*') /reset /T /C /Q | Out-Null
  if ($LASTEXITCODE -ne 0) { throw "icacls /reset exited $LASTEXITCODE" }
}
$items = @(Get-Item -LiteralPath $Destination) + @(Get-ChildItem -LiteralPath $Destination -Recurse -Force)
foreach ($item in $items) {
  foreach ($rule in (Get-Acl -LiteralPath $item.FullName).Access) {
    $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
    if ($allowed -notcontains $sid) { throw "unexpected access for $sid on $($item.FullName)" }
    if ($item.FullName.TrimEnd('\') -ne $Destination -and -not $rule.IsInherited) { throw "explicit access entry left on $($item.FullName)" }
  }
}
$source = Join-Path $PSScriptRoot 'pull-backup.ps1'
$target = Join-Path $ScriptDir 'pull-backup.ps1'
if ((Resolve-Path $source).Path -ne [IO.Path]::GetFullPath($target)) { Copy-Item -Force $source $target }
if (-not (Test-Path $KeyFile)) {
  & "$env:WINDIR\System32\OpenSSH\ssh-keygen.exe" -q -t ed25519 -N '""' -C 'mothership-backup-pull' -f $KeyFile
  if ($LASTEXITCODE -ne 0) { throw "ssh-keygen exited $LASTEXITCODE" }
}
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$ScriptDir\pull-backup.ps1`" -Destination `"$Destination`" -KeyFile `"$KeyFile`""
# Every 6 hours from 00:30 local time.
$trigger = New-ScheduledTaskTrigger -Once -At ((Get-Date).Date.AddMinutes(30)) -RepetitionInterval (New-TimeSpan -Hours 6)
$principal = New-ScheduledTaskPrincipal -UserId "$env:COMPUTERNAME\$User" -LogonType S4U -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Hours 1) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'Mothership Backup Pull' -TaskPath '\Mothership\' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Write-Output "Authorize this key in the VM for backup.sh stream only (see the runbook):"
Get-Content "$KeyFile.pub"
