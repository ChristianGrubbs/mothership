# Install the backup pull on the Windows host (docs/ops/linux-host.md, "Backups"). Run once, elevated, as the account
# that owns the VM. Idempotent: it keeps an existing key and re-registers the task.
param(
  [string]$Destination = 'G:\Mothership\backups',
  [string]$ScriptDir = 'D:\Mothership\scripts',
  [string]$KeyFile = (Join-Path $env:USERPROFILE '.ssh\mothership_backup_ed25519'),
  [string]$User = $env:USERNAME
)
$ErrorActionPreference = 'Stop'
New-Item -ItemType Directory -Force -Path $Destination, $ScriptDir, (Split-Path $KeyFile) | Out-Null
# The sets include app.env and the tunnel credentials: only the owner, SYSTEM and Administrators may read them.
& icacls.exe $Destination /inheritance:r /grant:r "$($User):(OI)(CI)F" 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' | Out-Null
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
