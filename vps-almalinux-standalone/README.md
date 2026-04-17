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

## 6) Mirror `domains_isp` lewat GitHub (VPS tidak perlu akses Komdigi)

Kalau VPS timeout ke Komdigi, pakai **GitHub Actions** (workflow `Komdigi domains_isp mirror`) yang tiap **15 menit** mengunduh dari Komdigi lalu mengunggah file ke **Release** dengan tag `domains-isp-cache`.

Setelah workflow pernah sukses, di VPS jalankan tiap 15 menit (cron):

```bash
chmod +x sync_domains_isp_from_github.sh
```

Contoh crontab:

```cron
*/15 * * * * APP_DIR=/var/www/nawala-api2-vps /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh >> /var/log/nawala-domains-isp-github.log 2>&1
```

URL publik file (repo harus **public** atau VPS punya token untuk private release):

`https://github.com/rproject753/nawala-api2-vps/releases/download/domains-isp-cache/domains_isp`

**Catatan:** push file di `.github/workflows/` ke GitHub butuh token dengan scope **workflow** (atau unggah manual lewat UI).

## 7) Mirror unduhan ISP lewat env (mis. repo [alsyundawy/TrustPositif](https://github.com/alsyundawy/TrustPositif))

Kalau Komdigi timeout tetapi **raw.githubusercontent.com** masih jalan, set env sebelum `php cron/update_sources.php` atau di baris cron:

```bash
export NAWALA_IPADDRESS_ISP_DOWNLOAD_URL="https://raw.githubusercontent.com/alsyundawy/TrustPositif/main/ipaddress_isp"
# opsional domains_isp hanya bila URL mengarah ke file teks yang kompatibel (bukan .7z):
# export NAWALA_DOMAINS_ISP_DOWNLOAD_URL="https://..."
```

Repo alsyundawy menyediakan `domains_isp` terutama sebagai **`domains_isp.7z`** — format beda dari unduhan resmi teks; untuk itu perlu langkah unpack terpisah atau tetap pakai mirror/resmi teks.
