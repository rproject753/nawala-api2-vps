# Standalone VPS AlmaLinux

Folder ini khusus untuk deployment updater di VPS yang **terpisah** dari instance PHP utama.

Tujuan utamanya:

- Instance updater jalan di folder VPS sendiri (`APP_DIR` terpisah).
- Tidak mengubah flow Windows/XAMPP.
- Tidak menambah dependensi ke endpoint API/checker utama.

## 1) Siapkan instance terpisah di VPS

Contoh direktori instance VPS:

- `/var/www/nawala-api2-vps`

Letakkan source project di folder tersebut (clone/copy terpisah dari instance web utama).

## 2) Install dependency sistem (AlmaLinux)

```bash
sudo dnf install -y php-cli php-curl cronie
sudo systemctl enable --now crond
```

## 3) Test manual updater standalone

```bash
cd /path/ke/repo-ini/vps-almalinux-standalone
chmod +x update_sources.sh install_cron.sh
APP_DIR=/var/www/nawala-api2-vps ./update_sources.sh
```

Force update:

```bash
APP_DIR=/var/www/nawala-api2-vps ./update_sources.sh --force
```

## 4) Pasang cron

```bash
cd /path/ke/repo-ini/vps-almalinux-standalone
APP_DIR=/var/www/nawala-api2-vps PHP_BIN=/usr/bin/php ./install_cron.sh
```

Default jadwal `*/30 * * * *`. Bisa override:

```bash
CRON_EXPR="*/15 * * * *" APP_DIR=/var/www/nawala-api2-vps ./install_cron.sh
```

## 5) Upload otomatis dari Windows (PowerShell)

Sudah disediakan script `deploy_to_vps.ps1` agar upload + setup VPS bisa sekali jalan.

Contoh (basic):

```powershell
cd C:\xampp\htdocs\nawala-api2\vps-almalinux-standalone
.\deploy_to_vps.ps1 -Host "1.2.3.4" -User "root"
```

Contoh dengan install dependency + custom path:

```powershell
cd C:\xampp\htdocs\nawala-api2\vps-almalinux-standalone
.\deploy_to_vps.ps1 `
  -Host "1.2.3.4" `
  -User "root" `
  -RemoteAppDir "/var/www/nawala-api2-vps" `
  -PhpBin "/usr/bin/php" `
  -CronExpr "*/30 * * * *" `
  -LogFile "/var/log/nawala-update.log" `
  -InstallDeps
```
