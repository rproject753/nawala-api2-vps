<#
.SYNOPSIS
  Sinkronkan folder dev (parent repo) ke clone GitHub lalu commit + push — satu perintah.

.DESCRIPTION
  Menyalin ke folder clone (default: c:\xampp\htdocs\nawala-api2-vps-fullsync):
  api, cron, vps-almalinux-standalone, .github, index.php, .gitignore (jika ada di dev).

  Setelah itu: git add -A, commit, push origin main.

  Set NAWALA_VPS_GIT_DIR jika clone repo kamu bukan path default.

.EXAMPLE
  cd C:\xampp\htdocs\nawala-api2\vps-almalinux-standalone
  .\publish-to-github.ps1 -Message "Update sync script"

.EXAMPLE
  .\publish-to-github.ps1 -WhatIf
#>
param(
  [Parameter(Mandatory = $false)]
  [string]$Message = "Sync from dev workspace",

  [string]$GitRepo = $env:NAWALA_VPS_GIT_DIR,

  [switch]$NoPush,

  [switch]$WhatIf
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Assert-CommandExists([string]$Name) {
  if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
    throw "Perintah '$Name' tidak ditemukan. Pasang Git for Windows."
  }
}

Assert-CommandExists "git"

$DevRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path

if ([string]::IsNullOrWhiteSpace($GitRepo)) {
  $GitRepo = "c:\xampp\htdocs\nawala-api2-vps-fullsync"
}

if (-not (Test-Path $GitRepo)) {
  throw "Folder clone tidak ada: $GitRepo — set NAWALA_VPS_GIT_DIR atau clone dulu: git clone https://github.com/rproject753/nawala-api2-vps.git"
}

$GitRepo = (Resolve-Path $GitRepo).Path
$gitDir = Join-Path $GitRepo ".git"
if (-not (Test-Path $gitDir)) {
  throw "Bukan repo git: $GitRepo"
}

function Invoke-RobocopyPair([string]$Relative) {
  $src = Join-Path $DevRoot $Relative
  $dst = Join-Path $GitRepo $Relative
  if (-not (Test-Path $src)) {
    Write-Warning "Lewati (tidak ada di dev): $Relative"
    return
  }
  if ($WhatIf) {
    Write-Host "[WhatIf] robocopy $src -> $dst /E"
    return
  }
  New-Item -ItemType Directory -Force -Path $dst | Out-Null
  & robocopy $src $dst /E /XD ".git" /NFL /NDL /NJH /NJS /NP
  if ($LASTEXITCODE -ge 8) {
    throw "robocopy gagal (exit $LASTEXITCODE): $Relative"
  }
}

Write-Host "Dev root:    $DevRoot"
Write-Host "GitHub dir: $GitRepo"
Write-Host ""

foreach ($rel in @("api", "cron", "vps-almalinux-standalone", ".github")) {
  Invoke-RobocopyPair $rel
}

$indexSrc = Join-Path $DevRoot "index.php"
$indexDst = Join-Path $GitRepo "index.php"
if (Test-Path $indexSrc) {
  if ($WhatIf) {
    Write-Host "[WhatIf] copy index.php"
  } else {
    Copy-Item -Path $indexSrc -Destination $indexDst -Force
  }
} else {
  Write-Warning "index.php tidak ada di dev, lewati."
}

$gi = Join-Path $DevRoot ".gitignore"
if (Test-Path $gi) {
  if (-not $WhatIf) {
    Copy-Item -Path $gi -Destination (Join-Path $GitRepo ".gitignore") -Force
  } else {
    Write-Host "[WhatIf] copy .gitignore"
  }
}

if ($WhatIf) {
  Write-Host "WhatIf selesai (tidak ada perubahan git)."
  exit 0
}

Push-Location $GitRepo
try {
  git add -A
  $porcelain = git status --porcelain
  if ([string]::IsNullOrWhiteSpace($porcelain)) {
    Write-Host "Tidak ada perubahan untuk di-commit."
    exit 0
  }

  git commit -m $Message

  if ($NoPush) {
    Write-Host "Commit selesai (--NoPush: tidak push)."
    exit 0
  }

  if (Get-Command gh -ErrorAction SilentlyContinue) {
    gh auth setup-git 2>$null | Out-Null
  }

  git push origin main
  Write-Host "Selesai: push ke origin/main."
} finally {
  Pop-Location
}
