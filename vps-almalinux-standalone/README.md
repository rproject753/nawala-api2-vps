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

## 5a) Push ke GitHub sekali jalan (Windows)

Setelah mengedit kode di folder dev (`nawala-api2`), jalankan dari PowerShell:

```powershell
cd C:\xampp\htdocs\nawala-api2\vps-almalinux-standalone
.\publish-to-github.ps1 -Message "jelaskan perubahan"
```

Script ini menyalin `api`, `cron`, `vps-almalinux-standalone`, `.github`, `index.php`, `.gitignore` ke **clone repo** lalu `git commit` + `git push origin main`.

- Default folder clone: `c:\xampp\htdocs\nawala-api2-vps-fullsync` — ubah dengan env `NAWALA_VPS_GIT_DIR` jika clone kamu di path lain.
- Cek dulu tanpa commit: `.\publish-to-github.ps1 -WhatIf`
- Hanya commit, tidak push: `.\publish-to-github.ps1 -NoPush -Message "..."`
- Push butuh **Git** + login GitHub (HTTPS). Kalau ada **GitHub CLI** (`gh`), token akan dipakai otomatis.

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

## 6) Mirror `domains_isp` lewat GitHub (panduan dari awal — Actions + VPS tiap 15 menit, unduh hanya jika berubah)

**Ide:** runner **GitHub Actions** (bisa ke Komdigi) tiap **15 menit** memperbarui file di **GitHub Release** tetap (`domains-isp-cache`). VPS tiap **15 menit** hanya mengambil `domains_isp.sha256` kecil dulu; **kalau SHA256 sama dengan yang terakhir diterapkan di VPS, file besar tidak diunduh lagi.**

### A) Di GitHub (sekali + otomatis)

1. Pastikan repo `nawala-api2-vps` punya file workflow:  
   `.github/workflows/komdigi-domains_isp.yml`  
   (jadwal `*/15 * * * *` UTC, unggah `domains_isp` + `domains_isp.sha256` ke Release tag `domains-isp-cache`).
2. **Settings → Actions → General** → izinkan Actions untuk repo ini.
3. Push workflow ke `main` (token GitHub butuh scope **`workflow`** bila lewat CLI).
4. Di tab **Actions**, jalankan workflow **Komdigi domains_isp mirror** sekali **Run workflow** sampai sukses (agar Release `domains-isp-cache` terbentuk dan ada aset).
5. Cek di browser (repo **public** paling mudah):  
   `https://github.com/rproject753/nawala-api2-vps/releases/expanded_assets/domains-isp-cache`  
   harus ada **`domains_isp`** dan **`domains_isp.sha256`**.

### B) Di VPS (Ubuntu, dari awal)

1. Pasang cron + curl (biasanya sudah ada):

```bash
sudo apt update
sudo apt install -y curl cron
sudo systemctl enable --now cron
```

2. Clone / taruh project (sesuaikan path):

```bash
sudo mkdir -p /var/www
sudo chown "$USER:$USER" /var/www
cd /var/www
git clone https://github.com/rproject753/nawala-api2-vps.git nawala-api2-vps
```

3. Siapkan folder cache:

```bash
mkdir -p /var/www/nawala-api2-vps/cache/blocklist_files
```

4. Jalankan skrip sekali manual (uji):

```bash
chmod +x /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh
APP_DIR=/var/www/nawala-api2-vps /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh
```

Run kedua kalinya: harus muncul pesan **domains_isp unchanged** jika GitHub belum mengganti checksum.

5. Pasang crontab tiap **15 menit**:

```bash
crontab -e
```

Tambahkan baris:

```cron
*/15 * * * * APP_DIR=/var/www/nawala-api2-vps /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh >> /var/log/nawala-domains-isp-github.log 2>&1
```

6. Log (opsional):

```bash
sudo touch /var/log/nawala-domains-isp-github.log
sudo chown "$USER:$USER" /var/log/nawala-domains-isp-github.log
```

**File state di VPS:** `cache/blocklist_files/domains_isp.applied.sha256` — menyimpan SHA256 terakhir yang sudah diterapkan; jangan dihapus kecuali mau paksa unduh ulang nanti.

**URL publik (repo public):**  
`https://github.com/rproject753/nawala-api2-vps/releases/download/domains-isp-cache/domains_isp`  
`https://github.com/rproject753/nawala-api2-vps/releases/download/domains-isp-cache/domains_isp.sha256`

**Catatan:** push workflow ke GitHub butuh token dengan scope **`workflow`** (atau unggah file YAML lewat UI).

## 7) Mirror unduhan ISP lewat env (mis. repo [alsyundawy/TrustPositif](https://github.com/alsyundawy/TrustPositif))

Kalau Komdigi timeout tetapi **raw.githubusercontent.com** masih jalan, set env sebelum `php cron/update_sources.php` atau di baris cron:

```bash
export NAWALA_IPADDRESS_ISP_DOWNLOAD_URL="https://raw.githubusercontent.com/alsyundawy/TrustPositif/main/ipaddress_isp"
```

**`domains_isp` dari mirror .7z** (otomatis diekstrak ke `cache/blocklist_files/domains_isp` — butuh `7zz`/`7z` di server, mis. `sudo apt install -y p7zip-full`):

```bash
export NAWALA_DOMAINS_ISP_DOWNLOAD_URL="https://raw.githubusercontent.com/alsyundawy/TrustPositif/main/domains_isp.7z"
APP_DIR=/var/www/nawala-api2-vps PHP_BIN=/usr/bin/php ./update_sources.sh --force
```

Tanpa env, URL default tetap ke **Komdigi** resmi.
