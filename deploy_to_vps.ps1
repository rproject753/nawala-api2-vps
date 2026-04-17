param(
  [Parameter(Mandatory = $true)]
  [string]$Host,

  [Parameter(Mandatory = $true)]
  [string]$User,

  [string]$LocalProjectDir = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,
  [string]$RemoteAppDir = "/var/www/nawala-api2-vps",
  [string]$PhpBin = "/usr/bin/php",
  [string]$CronExpr = "*/30 * * * *",
  [string]$LogFile = "/var/log/nawala-update.log",
  [switch]$InstallDeps
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function ConvertTo-BashLiteral([string]$Text) {
  $escaped = $Text -replace "'", "'""'""'"
  return "'" + $escaped + "'"
}

function Assert-CommandExists([string]$Name) {
  if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
    throw "Command '$Name' tidak ditemukan. Pastikan OpenSSH client terpasang."
  }
}

Assert-CommandExists "ssh"
Assert-CommandExists "scp"
Assert-CommandExists "tar"

$archiveName = "nawala-api2-vps-upload.tgz"
$localArchive = Join-Path $env:TEMP $archiveName
$remoteArchive = "/tmp/$archiveName"
$remoteScript = "/tmp/nawala-api2-bootstrap.sh"

Write-Host "Membuat archive project..."
if (Test-Path $localArchive) { Remove-Item $localArchive -Force }
tar -czf "$localArchive" -C "$LocalProjectDir" .

$installDepsBash = "echo 'Lewati install dependency (InstallDeps=false).'"
if ($InstallDeps.IsPresent) {
  $installDepsBash = @"
sudo dnf install -y php-cli php-curl cronie
sudo systemctl enable --now crond
"@
}

$remoteScriptContent = @"
#!/usr/bin/env bash
set -euo pipefail

REMOTE_APP_DIR=$(ConvertTo-BashLiteral $RemoteAppDir)
PHP_BIN=$(ConvertTo-BashLiteral $PhpBin)
CRON_EXPR=$(ConvertTo-BashLiteral $CronExpr)
LOG_FILE=$(ConvertTo-BashLiteral $LogFile)
ARCHIVE_PATH=$(ConvertTo-BashLiteral $remoteArchive)

mkdir -p "\${REMOTE_APP_DIR}"
tar -xzf "\${ARCHIVE_PATH}" -C "\${REMOTE_APP_DIR}"
rm -f "\${ARCHIVE_PATH}"

$installDepsBash

cd "\${REMOTE_APP_DIR}/vps-almalinux-standalone"
chmod +x update_sources.sh install_cron.sh

APP_DIR="\${REMOTE_APP_DIR}" PHP_BIN="\${PHP_BIN}" ./update_sources.sh
CRON_EXPR="\${CRON_EXPR}" APP_DIR="\${REMOTE_APP_DIR}" PHP_BIN="\${PHP_BIN}" LOG_FILE="\${LOG_FILE}" ./install_cron.sh

echo "Deploy selesai."
echo "APP_DIR: \${REMOTE_APP_DIR}"
echo "Cron: \${CRON_EXPR}"
"@

$tempRemoteScript = Join-Path $env:TEMP "nawala-api2-bootstrap.sh"
Set-Content -Path $tempRemoteScript -Value $remoteScriptContent -Encoding ascii

$target = "$User@$Host"

Write-Host "Upload archive ke VPS..."
scp "$localArchive" "${target}:${remoteArchive}" | Out-Null

Write-Host "Upload bootstrap script ke VPS..."
scp "$tempRemoteScript" "${target}:${remoteScript}" | Out-Null

Write-Host "Jalankan bootstrap di VPS..."
ssh $target "bash ${remoteScript} && rm -f ${remoteScript}"

Write-Host "Selesai. Verifikasi cron:"
ssh $target "crontab -l"
