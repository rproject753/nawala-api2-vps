# Tutorial: AlmaLinux bersih + sinkron `domains_isp` dari GitHub (tiap 15 menit)

> **Update kode dari PC Windows:** gunakan `vps-almalinux-standalone/publish-to-github.ps1` (lihat `README.md` bagian **5a**) agar perubahan di folder dev langsung di-push ke GitHub tanpa menyalin manual ke folder clone.

Panduan ini untuk **VPS AlmaLinux tanpa cPanel**, dengan pola:

- **GitHub Actions** memperbarui mirror `domains_isp` di **GitHub Release** (tag `domains-isp-cache`) tiap **15 menit** (lihat `.github/workflows/komdigi-domains_isp.yml`).
- **VPS** tiap **15 menit** menjalankan skrip yang **hanya mengunduh file besar** `domains_isp` **jika SHA256 di GitHub berubah** (skrip `sync_domains_isp_from_github.sh`).

Asumsi path aplikasi: `/var/www/nawala-api2-vps` dan repo: `https://github.com/rproject753/nawala-api2-vps.git` (sesuaikan jika berbeda).

---

## Bagian 1 — GitHub (sekali + otomatis)

1. Pastikan di repo ada workflow: `.github/workflows/komdigi-domains_isp.yml` (unggah `domains_isp` + `domains_isp.sha256` ke Release tag **`domains-isp-cache`**, jadwal `*/15 * * * *` UTC).
2. Buka **Settings → Actions → General** → pastikan **Actions diizinkan** untuk repo ini.
3. **Push** workflow ke branch `main` (kalau lewat `git push` dari CLI, token GitHub perlu scope **`workflow`**).
4. Di tab **Actions**, pilih workflow **Komdigi domains_isp mirror** → **Run workflow** sekali sampai **sukses** (supaya Release `domains-isp-cache` dan kedua aset terbentuk).
5. Verifikasi di browser (repo **public** paling mudah):  
   `https://github.com/rproject753/nawala-api2-vps/releases/expanded_assets/domains-isp-cache`  
   harus ada **`domains_isp`** dan **`domains_isp.sha256`**.

---

## Bagian 2 — VPS AlmaLinux bersih (dari awal)

### 2.1 Update sistem dan paket

```bash
sudo dnf update -y
sudo dnf install -y curl wget git cronie php-cli php-common php-json php-mbstring php-curl
sudo systemctl enable --now crond
php -v
php -m | grep -i curl
```

### 2.2 Clone project

```bash
sudo mkdir -p /var/www
sudo chown "$USER:$USER" /var/www
cd /var/www
git clone https://github.com/rproject753/nawala-api2-vps.git nawala-api2-vps
cd /var/www/nawala-api2-vps
git pull
```

### 2.3 Folder cache dan izin skrip

```bash
mkdir -p /var/www/nawala-api2-vps/cache/blocklist_files
chmod +x /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh
```

### 2.4 Uji sinkron manual (wajib GitHub Release sudah ada)

```bash
APP_DIR=/var/www/nawala-api2-vps /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh
```

Jalankan **sekali lagi**. Jika checksum GitHub belum berubah, keluar log berisi **domains_isp unchanged** — artinya **tidak ada unduhan besar**.

### 2.5 Crontab tiap 15 menit

Siapkan log:

```bash
sudo touch /var/log/nawala-domains-isp-github.log
sudo chown "$USER:$USER" /var/log/nawala-domains-isp-github.log
```

Edit crontab:

```bash
crontab -e
```

Tambahkan baris:

```cron
*/15 * * * * APP_DIR=/var/www/nawala-api2-vps /var/www/nawala-api2-vps/vps-almalinux-standalone/sync_domains_isp_from_github.sh >> /var/log/nawala-domains-isp-github.log 2>&1
```

Simpan, lalu cek:

```bash
crontab -l
```

### 2.6 File state di VPS

Setelah sinkron sukses, VPS menyimpan SHA256 terakhir di:

`/var/www/nawala-api2-vps/cache/blocklist_files/domains_isp.applied.sha256`

Hapus file ini (dan opsional `domains_isp`) jika ingin **memaksa unduh ulang** penuh.

---

## Bagian 3 — (Opsional) Updater PHP penuh

Untuk Skiddle blocklist + sisa sumber TrustPositif lewat `cron/update_sources.php`:

```bash
chmod +x /var/www/nawala-api2-vps/vps-almalinux-standalone/update_sources.sh
APP_DIR=/var/www/nawala-api2-vps PHP_BIN=/usr/bin/php /var/www/nawala-api2-vps/vps-almalinux-standalone/update_sources.sh
```

Jika **Komdigi** tidak terjangkau dari VPS, lihat `README.md` bagian **7** (variabel env mirror, termasuk unduhan `domains_isp` dari `.7z` pihak ketiga dengan `p7zip`).

---

## Catatan firewall / SELinux

- **firewalld** pada default umumnya tidak memblok keluar ke GitHub; jika `curl` ke `github.com` gagal, periksa `sudo firewall-cmd --list-all`.
- Jika unduhan ke path cache ditolak oleh **SELinux**, sesuaikan konteks atau tanyakan penyedia VPS (di AlmaLinux bersih biasanya tidak jadi masalah untuk path di `/var/www`).

---

## URL unduhan publik (repo public)

- `https://github.com/rproject753/nawala-api2-vps/releases/download/domains-isp-cache/domains_isp`
- `https://github.com/rproject753/nawala-api2-vps/releases/download/domains-isp-cache/domains_isp.sha256`

Repo **private** membutuhkan autentikasi untuk mengambil aset Release; panduan ini mengasumsikan repo **public**.

---

## Gagal: `curl: (22) ... 404` saat menjalankan `sync_domains_isp_from_github.sh`

Artinya URL Release **tidak ada** atau **aset belum diunggah** (bukan salah VPS).

1. Buka **https://github.com/rproject753/nawala-api2-vps/releases** — harus ada release / tag **`domains-isp-cache`**.
2. Di release itu harus ada file **`domains_isp`** dan **`domains_isp.sha256`** (dibuat oleh workflow Actions).
3. Jika belum ada: di **Actions** jalankan workflow **Komdigi domains_isp mirror** sekali sampai **hijau**, lalu cek lagi halaman Releases.
4. Pastikan file `.github/workflows/komdigi-domains_isp.yml` sudah **ter-push** ke GitHub (push butuh scope **`workflow`**).
5. Tes di browser atau dari VPS:  
   `curl -sI "https://github.com/rproject753/nawala-api2-vps/releases/download/domains-isp-cache/domains_isp.sha256"`  
   baris pertama harus `HTTP/2 200` (bukan 404).

Versi skrip terbaru menampilkan pesan error yang menjelaskan URL yang gagal dan langkah perbaikan.
