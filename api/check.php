<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  header('Content-Type: application/json; charset=utf-8');
}

function response(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function normalize_domain(string $domain): string {
  $domain = trim($domain);
  $domain = strtolower($domain);

  // Remove scheme if user pastes full URL.
  $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
  $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;

  // Remove path/query fragments if any.
  // Use a delimiter that won't conflict with '#' inside the character class.
  $domain = preg_replace('~[/?#].*$~', '', $domain) ?? $domain;

  return $domain;
}

function is_valid_domain(string $domain): bool {
  // Basic validation: labels + TLD letters (2+). Prevent IPs and weird chars.
  // Examples allowed: example.com, sub.example.co.id
  // Examples rejected: 1.2.3.4, localhost, example, -bad.com, bad-.com
  return (bool)preg_match(
    '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
    $domain
  );
}

function dns_resolved(string $domain): array {
  $a = checkdnsrr($domain, 'A');
  $aaaa = checkdnsrr($domain, 'AAAA');
  $resolved = $a || $aaaa;
  return [
    'resolved' => $resolved,
    'a' => $a,
    'aaaa' => $aaaa,
  ];
}

const NAWALA_DNS_SERVERS = [
  // Used by nawala-checker (ABSPositif / Komdigi).
  ['address' => '180.131.144.144', 'keyword' => 'internetpositif'],
  ['address' => '103.155.26.28', 'keyword' => 'trustpositif'],
];

// Observed placeholder IP for blocked domains when querying 103.155.26.28.
const NAWALA_BLOCK_IPS = [
  '103.155.26.29' => true,
];

function dns_udp_query_a(string $domain, string $serverIp, int $timeoutMs = 1500): array {
  $domain = normalize_domain($domain);
  if ($domain === '') return ['ips' => [], 'ede' => []];

  $labels = explode('.', $domain);
  $qname = '';
  foreach ($labels as $label) {
    $len = strlen($label);
    if ($len < 1 || $len > 63) return ['ips' => [], 'ede' => []];
    $qname .= chr($len) . $label;
  }
  $qname .= "\0";

  $id = random_int(0, 0xffff);
  $flags = 0x0100; // standard query, recursion desired
  $header = pack('nnnnnn', $id, $flags, 1, 0, 0, 0);
  $question = $qname . pack('nn', 1, 1); // A / IN

  // Add EDNS0 OPT record so DNS server can include EDE (Extended DNS Error).
  $udpPayload = 1232;
  $opt = "\0" . pack('nnNn', 41, $udpPayload, 0, 0);

  $packet = $header . $question . $opt;

  $timeoutSec = max(0.1, $timeoutMs / 1000);
  $errno = 0;
  $errstr = '';
  $sock = @stream_socket_client('udp://' . $serverIp . ':53', $errno, $errstr, $timeoutSec, STREAM_CLIENT_CONNECT);
  if ($sock === false) return ['ips' => [], 'ede' => []];

  @stream_set_timeout($sock, (int)$timeoutSec, (int)(($timeoutMs % 1000) * 1000));
  $sent = @fwrite($sock, $packet);
  if ($sent === false) {
    @fclose($sock);
    return ['ips' => [], 'ede' => []];
  }

  $buf = @fread($sock, 4096);
  @fclose($sock);
  if (!is_string($buf) || strlen($buf) < 12) return ['ips' => [], 'ede' => []];

  $h = unpack('nid/nflags/nqd/nan/ns/nar', substr($buf, 0, 12));
  $qd = (int)($h['qd'] ?? 0);
  $an = (int)($h['an'] ?? 0);
  $ns = (int)($h['ns'] ?? 0);
  $ar = (int)($h['ar'] ?? 0);

  if ($qd <= 0 || $an < 0) return ['ips' => [], 'ede' => []];

  $offset = 12;

  $skipName = function(string $data, int $off): int {
    $len = ord($data[$off] ?? "\0");
    while ($len > 0) {
      // pointer
      if (($len & 0xC0) === 0xC0) return $off + 2;
      $off += 1 + $len;
      $len = ord($data[$off] ?? "\0");
    }
    return $off + 1;
  };

  // Skip question section.
  for ($i = 0; $i < $qd; $i++) {
    $offset = $skipName($buf, $offset);
    $offset += 4; // QTYPE + QCLASS
  }

  $ips = [];
  // Parse answer section.
  for ($i = 0; $i < $an; $i++) {
    $offset = $skipName($buf, $offset);
    if ($offset + 10 > strlen($buf)) break;

    $type = unpack('n', substr($buf, $offset, 2))[1] ?? 0;
    $class = unpack('n', substr($buf, $offset + 2, 2))[1] ?? 0;
    $rdlen = unpack('n', substr($buf, $offset + 8, 2))[1] ?? 0;
    $offset += 10; // name skipped + type/class/ttl/rdlen

    if ($offset + $rdlen > strlen($buf)) break;
    if ($type === 1 && $class === 1 && $rdlen === 4) {
      $ipLong = unpack('N', substr($buf, $offset, 4))[1] ?? null;
      if ($ipLong !== null) $ips[] = long2ip($ipLong);
    }
    $offset += $rdlen;
  }

  // Skip authority section to reach additional section.
  for ($i = 0; $i < $ns; $i++) {
    $offset = $skipName($buf, $offset);
    if ($offset + 10 > strlen($buf)) break;
    $rdlen = unpack('n', substr($buf, $offset + 8, 2))[1] ?? 0;
    $offset += 10 + $rdlen;
    if ($offset > strlen($buf)) break;
  }

  $ede = [];
  // Parse additional section to extract EDE (OPT option code 15).
  for ($i = 0; $i < $ar; $i++) {
    $offset = $skipName($buf, $offset);
    if ($offset + 10 > strlen($buf)) break;

    $type = unpack('n', substr($buf, $offset, 2))[1] ?? 0;
    $class = unpack('n', substr($buf, $offset + 2, 2))[1] ?? 0;
    $ttl = unpack('N', substr($buf, $offset + 4, 4))[1] ?? 0;
    $rdlen = unpack('n', substr($buf, $offset + 8, 2))[1] ?? 0;
    $offset += 10;

    if ($offset + $rdlen > strlen($buf)) break;

    if ($type === 41 && $rdlen > 0) {
      $end = $offset + $rdlen;
      $optOff = $offset;
      while ($optOff + 4 <= $end) {
        $optCode = unpack('n', substr($buf, $optOff, 2))[1] ?? 0;
        $optLen = unpack('n', substr($buf, $optOff + 2, 2))[1] ?? 0;
        $optOff += 4;
        if ($optOff + $optLen > $end) break;

        $optData = substr($buf, $optOff, $optLen);
        $optOff += $optLen;

        if ($optCode === 15) {
          $edeCode = null;
          $edeText = '';
          if ($optLen >= 2) {
            $edeCode = unpack('n', substr($optData, 0, 2))[1] ?? null;
            $edeText = substr($optData, 2);
          }
          $ede[] = [
            'code' => $edeCode,
            'text' => $edeText,
            'udp_payload' => $class,
            'ttl' => $ttl,
          ];
        }
      }
    }

    $offset += $rdlen;
    if ($offset > strlen($buf)) break;
  }

  return [
    'ips' => array_values(array_unique($ips)),
    'ede' => $ede,
  ];
}

function nawala_dns_probe(string $domain): array {
  $ipsMatched = [];
  $edeMatched = [];
  $tried = [];
  foreach (NAWALA_DNS_SERVERS as $srv) {
    $serverIp = (string)($srv['address'] ?? '');
    $keyword = (string)($srv['keyword'] ?? '');
    if ($serverIp === '') continue;

    $res = dns_udp_query_a($domain, $serverIp);
    $ips = is_array($res['ips'] ?? null) ? $res['ips'] : [];
    $ede = is_array($res['ede'] ?? null) ? $res['ede'] : [];

    $tried[] = ['server' => $serverIp, 'keyword' => $keyword, 'ips' => $ips, 'ede' => $ede];
    if (empty($ips) && empty($ede)) continue;

    if (!empty($ede)) {
      $edeMatched = array_merge($edeMatched, $ede);
    }

    foreach ($ips as $ip) {
      if (isset(NAWALA_BLOCK_IPS[$ip])) {
        $ipsMatched[] = $ip;
      }
    }
  }

  $blocked = (!empty($edeMatched)) || !empty($ipsMatched);

  return [
    'blocked' => $blocked,
    'ips_matched' => array_values(array_unique($ipsMatched)),
    'ede' => $edeMatched,
    'tried' => $tried,
  ];
}

// The blocklist is split in multiple files in the Skiddle-ID/blocklist repo.
// (Some deployments previously referenced a single `domains` file that now 404.)
const BLOCKLIST_URLS = [
  'https://raw.githubusercontent.com/Skiddle-ID/blocklist/main/domains_001.txt',
  'https://raw.githubusercontent.com/Skiddle-ID/blocklist/main/domains_002.txt',
  'https://raw.githubusercontent.com/Skiddle-ID/blocklist/main/domains_003.txt',
  'https://raw.githubusercontent.com/Skiddle-ID/blocklist/main/domains_004.txt',
];
const BLOCKLIST_MEMBER_CACHE_TTL_SECONDS = 3600; // 1 jam
const BLOCKLIST_FILES_DIR = __DIR__ . '/../cache/blocklist_files';
const BLOCKLIST_FILES_REFRESH_TTL_SECONDS = 86400; // 24 jam

const TRUSTPOSITIF_DOMAINS_URL = 'https://trustpositif.komdigi.go.id/assets/db/domains';
const TRUSTPOSITIF_DOMAINS_ISP_URL = 'https://trustpositif.komdigi.go.id/assets/db/domains_isp';
const TRUSTPOSITIF_IPADDRESS_ISP_URL = 'https://trustpositif.komdigi.go.id/assets/db/ipaddress_isp';
const TRUSTPOSITIF_LOCAL_PATH = __DIR__ . '/../cache/trustpositif/domains.txt';
/** Cache ISP lists alongside Skiddle blocklists (sama dengan unduhan cron). */
const TRUSTPOSITIF_DOMAINS_ISP_LOCAL_PATH = BLOCKLIST_FILES_DIR . '/domains_isp';
const TRUSTPOSITIF_IPADDRESS_ISP_LOCAL_PATH = BLOCKLIST_FILES_DIR . '/ipaddress_isp';
const TRUSTPOSITIF_MEMBER_CACHE_TTL_SECONDS = 3600; // 1 jam
const TRUSTPOSITIF_FILES_REFRESH_TTL_SECONDS = 86400; // 24 jam

/**
 * URL unduhan ISP (Komdigi) boleh dioverride lewat env bila VPS tidak reach host Komdigi
 * tetapi masih reach mirror (mis. raw.githubusercontent.com).
 *
 * Contoh mirror komunitas (MIT): https://github.com/alsyundawy/TrustPositif
 *   NAWALA_IPADDRESS_ISP_DOWNLOAD_URL=https://raw.githubusercontent.com/alsyundawy/TrustPositif/main/ipaddress_isp
 * Untuk domains_isp mirror biasanya berbentuk .7z di repo itu — tidak langsung drop-in ke file teks resmi.
 */
function nawala_env_non_empty(string $name): ?string {
  $v = getenv($name);
  if (!is_string($v)) {
    return null;
  }
  $v = trim($v);
  return $v !== '' ? $v : null;
}

function nawala_trustpositif_domains_isp_download_url(): string {
  return nawala_env_non_empty('NAWALA_DOMAINS_ISP_DOWNLOAD_URL') ?? TRUSTPOSITIF_DOMAINS_ISP_URL;
}

function nawala_trustpositif_ipaddress_isp_download_url(): string {
  return nawala_env_non_empty('NAWALA_IPADDRESS_ISP_DOWNLOAD_URL') ?? TRUSTPOSITIF_IPADDRESS_ISP_URL;
}

/**
 * Unduh blocklist / ABSPositif digerakkan oleh cron (`cron/update_sources.php`), bukan oleh API checker.
 */

function download_url_to_file(string $url, string $destPath, int $timeoutSeconds = 120, bool $unboundedTransferWithSlowAbort = false): bool {
  $destDir = dirname($destPath);
  if (!is_dir($destDir)) {
    @mkdir($destDir, 0777, true);
  }

  $fp = @fopen($destPath, 'wb');
  if ($fp === false) return false;

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $opts = [
      CURLOPT_FILE => $fp,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_USERAGENT => 'NawalaAPI-Checker/1.0',
    ];
    if ($unboundedTransferWithSlowAbort) {
      // Asset Komdigi bisa sangat besar; batasi koneksi awal, tanpa cap total waktu unduh.
      // Abort bila throughput terlalu rendah terlalu lama (indikasi macet / jaringan putus).
      $connect = min(60, max(10, $timeoutSeconds));
      $opts[CURLOPT_CONNECTTIMEOUT] = $connect;
      $opts[CURLOPT_TIMEOUT] = 0;
      $opts[CURLOPT_LOW_SPEED_LIMIT] = 256;
      $opts[CURLOPT_LOW_SPEED_TIME] = 900;
    } else {
      $opts[CURLOPT_CONNECTTIMEOUT] = min(10, $timeoutSeconds);
      $opts[CURLOPT_TIMEOUT] = $timeoutSeconds;
    }
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    $errNo = curl_errno($ch);
    curl_close($ch);
    @fclose($fp);
    return $ok !== false && $errNo === 0 && (file_exists($destPath) && filesize($destPath) > 0);
  }

  // Fallback: stream copy (may be slower).
  $in = @fopen($url, 'rb');
  if ($in === false) {
    @fclose($fp);
    return false;
  }
  stream_copy_to_stream($in, $fp);
  @fclose($in);
  @fclose($fp);
  return file_exists($destPath) && filesize($destPath) > 0;
}

/**
 * Hanya cek apakah cache blocklist Skiddle ada di disk.
 * Unduh/pembaruan: jalankan `cron/update_sources.php`.
 */
function ensure_blocklist_files_local(): bool {
  if (!is_dir(BLOCKLIST_FILES_DIR)) {
    @mkdir(BLOCKLIST_FILES_DIR, 0777, true);
  }
  foreach (BLOCKLIST_URLS as $url) {
    $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);
    $localPath = BLOCKLIST_FILES_DIR . '/' . $fileName;
    if (is_file($localPath) && (int)@filesize($localPath) > 0) {
      return true;
    }
  }
  return false;
}

function domain_in_local_blocklist_file(string $localPath, string $domain): bool {
  if (!is_file($localPath)) return false;
  $needle = normalize_domain($domain);
  if ($needle === '') return false;

  try {
    $file = new SplFileObject($localPath, 'r');
    $file->setFlags(SplFileObject::DROP_NEW_LINE);
    foreach ($file as $line) {
      $norm = normalize_blocklist_line((string)$line, $needle);
      if ($norm === $needle) return true;
    }
  } catch (Throwable $e) {
    return false;
  }

  return false;
}

function fetch_remote_text(string $url, int $timeoutSeconds = 10): ?string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
      CURLOPT_TIMEOUT => $timeoutSeconds,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_USERAGENT => 'NawalaAPI-Checker/1.0',
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
      curl_close($ch);
      return null;
    }
    curl_close($ch);
    return is_string($result) ? $result : null;
  }

  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => $timeoutSeconds,
      'ignore_errors' => true,
      'header' => 'User-Agent: NawalaAPI-Checker/1.0' . "\r\n",
    ],
    'ssl' => [
      'verify_peer' => false,
      'verify_peer_name' => false,
    ],
  ]);

  $text = @file_get_contents($url, false, $context);
  return is_string($text) ? $text : null;
}

function get_proxy_config(): ?array {
  // Disabilitkan sesuai permintaan: checker ini tidak boleh menggunakan proxy.
  return null;
}

function file_contains_domain_candidate(string $filePath, string $needleDomain): bool {
  $needle = normalize_domain($needleDomain);
  if ($needle === '') return false;
  if (!is_file($filePath) || (int)@filesize($filePath) <= 0) return false;

  $fp = @fopen($filePath, 'rb');
  if ($fp === false) return false;

  $domainRegex = '/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}/i';
  $found = false;
  $tail = '';

  while (!feof($fp)) {
    $chunk = fread($fp, 1024 * 1024); // 1MB
    if (!is_string($chunk) || $chunk === '') continue;

    // Keep a small tail to reduce edge misses around chunk boundaries.
    $chunk = $tail . $chunk;
    $tail = substr($chunk, -256); // last bytes for next iteration

    if (preg_match_all($domainRegex, $chunk, $m)) {
      foreach ($m[0] as $cand) {
        if (normalize_domain((string)$cand) === $needle) {
          $found = true;
          break 2;
        }
      }
    }
  }

  @fclose($fp);
  return $found;
}

/**
 * Hanya cek apakah file ABSPositif sudah ada di cache lokal.
 * Unduh/pembaruan: jalankan `cron/update_sources.php`.
 */
function ensure_trustpositif_domains_local(): bool {
  $destDir = dirname(TRUSTPOSITIF_LOCAL_PATH);
  if (!is_dir($destDir)) {
    @mkdir($destDir, 0777, true);
  }
  return is_file(TRUSTPOSITIF_LOCAL_PATH) && (int)@filesize(TRUSTPOSITIF_LOCAL_PATH) > 0;
}

/**
 * @return array<string, true>|null Set IPv4 dari cache ipaddress_isp (sekali per request).
 */
function trustpositif_ip_isp_set(): ?array {
  static $done = false;
  static $set = null;
  if ($done) {
    return $set;
  }
  $done = true;
  $path = TRUSTPOSITIF_IPADDRESS_ISP_LOCAL_PATH;
  if (!is_file($path) || (int)@filesize($path) <= 0) {
    return null;
  }
  $out = [];
  try {
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
      return null;
    }
    while (($line = fgets($fp)) !== false) {
      $ip = trim((string)$line);
      if ($ip !== '' && strpos($ip, ':') === false) {
        $out[$ip] = true;
      }
    }
    @fclose($fp);
  } catch (Throwable $e) {
    return null;
  }
  $set = $out;
  return $set;
}

/** Alamat IPv4 hasil resolusi DNS publik untuk domain. */
function dns_a_records_ipv4(string $domain): array {
  $domain = normalize_domain($domain);
  if ($domain === '') {
    return [];
  }
  if (!function_exists('dns_get_record')) {
    return [];
  }
  $recs = @dns_get_record($domain, DNS_A);
  if (!is_array($recs)) {
    return [];
  }
  $out = [];
  foreach ($recs as $r) {
    if (($r['type'] ?? '') === 'A' && isset($r['ip']) && is_string($r['ip'])) {
      $out[] = $r['ip'];
    }
  }
  return array_values(array_unique($out));
}

function check_trustpositif_domain(string $domain, bool $allowDownload = false): array {
  // File ABSPositif diisi oleh cron `cron/update_sources.php` (bukan oleh API checker).
  // Parameter $allowDownload dipertahankan untuk kompatibilitas (tidak dipakai).

  $cacheDir = __DIR__ . '/../cache';
  if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
  }

  ensure_trustpositif_domains_local();

  $cacheFile = $cacheDir . '/trustpositif_member_cache.ser';
  if (is_file($cacheFile) && (time() - filemtime($cacheFile) < TRUSTPOSITIF_MEMBER_CACHE_TTL_SECONDS)) {
    $raw = @file_get_contents($cacheFile);
    $cached = @unserialize($raw, ['allowed_classes' => false]);
    if (is_array($cached) && isset($cached[$domain])) {
      return (array)$cached[$domain];
    }
  }

  $blocked = false;
  $sources = [];

  $tpLocalUsable = is_file(TRUSTPOSITIF_LOCAL_PATH) && (int)@filesize(TRUSTPOSITIF_LOCAL_PATH) > 0;

  if ($tpLocalUsable) {
    $blocked = file_contains_domain_candidate(TRUSTPOSITIF_LOCAL_PATH, $domain);
    if ($blocked) {
      $sources[] = 'trustpositif:assets/db/domains';
    }
  }

  if (!$blocked) {
    $ispDomainsPath = TRUSTPOSITIF_DOMAINS_ISP_LOCAL_PATH;
    if (is_file($ispDomainsPath) && (int)@filesize($ispDomainsPath) > 0) {
      if (domain_in_local_blocklist_file($ispDomainsPath, $domain)) {
        $blocked = true;
        $sources[] = 'trustpositif:assets/db/domains_isp';
      }
    }
  }

  if (!$blocked) {
    $ipSet = trustpositif_ip_isp_set();
    if ($ipSet !== null) {
      foreach (dns_a_records_ipv4($domain) as $ip) {
        if (isset($ipSet[$ip])) {
          $blocked = true;
          $sources[] = 'trustpositif:assets/db/ipaddress_isp';
          break;
        }
      }
    }
  }

  $result = [
    'blocked' => $blocked,
    'sources' => $sources,
  ];

  // Save small per-domain cache.
  $cache = [];
  if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $cache = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($cache)) $cache = [];
  }
  $cache[$domain] = $result;
  @file_put_contents($cacheFile, serialize($cache), LOCK_EX);

  return $result;
}

function normalize_blocklist_line(string $line, string $fallbackNeedle): string {
  $line = trim($line);
  if ($line === '') return '';

  // Ignore comments.
  if (substr($line, 0, 1) === '#') return '';

  $line = strtolower($line);
  $line = preg_replace('/^\*\./', '', $line) ?? $line; // normalize '*.example.com' to 'example.com'
  $line = rtrim($line, '.');

  // Normalize common 'www.' prefix.
  if (strpos($line, 'www.') === 0) $line = substr($line, 4);

  // If blocklist has wildcards like '*example.com', best-effort strip.
  if (strpos($line, '*') === 0) $line = substr($line, 1);

  return $line !== '' ? $line : $fallbackNeedle;
}

function stream_check_domain_in_url(string $url, string $needle, int $timeoutSeconds = 60): bool {
  $needle = normalize_domain($needle);
  if ($needle === '') return false;

  if (!function_exists('curl_init')) {
    // Fallback (may use more memory); avoid if possible.
    $text = fetch_remote_text($url, $timeoutSeconds);
    if ($text === null) return false;
    $lines = preg_split("/\r\n|\n|\r/", $text);
    foreach ($lines as $line) {
      $norm = normalize_blocklist_line((string)$line, $needle);
      if ($norm === $needle) return true;
    }
    return false;
  }

  $found = false;
  $buffer = '';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => $timeoutSeconds,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'NawalaAPI-Checker/1.0',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_BUFFERSIZE => 1024 * 128,
  ]);

  curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($chHandle, string $data) use (&$found, &$buffer, $needle): int {
    if ($found) {
      // Abort download early.
      return 0;
    }

    $buffer .= $data;
    while (($pos = strpos($buffer, "\n")) !== false) {
      $line = substr($buffer, 0, $pos);
      $buffer = substr($buffer, $pos + 1);

      $norm = normalize_blocklist_line($line, $needle);
      if ($norm === $needle) {
        $found = true;
        return 0; // abort
      }
    }

    return strlen($data);
  });

  // Execute; if we aborted early, curl might treat it as a write error.
  @curl_exec($ch);
  curl_close($ch);

  return $found;
}

function check_blocklist_domain(string $domain): array {
  $cacheDir = __DIR__ . '/../cache';
  if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
  }

  $cacheFile = $cacheDir . '/blocklist_member_cache.ser';
  $cacheTtl = BLOCKLIST_MEMBER_CACHE_TTL_SECONDS;

  if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $raw = @file_get_contents($cacheFile);
    $cached = @unserialize($raw, ['allowed_classes' => false]);
    if (is_array($cached) && isset($cached[$domain])) {
      return (array)$cached[$domain];
    }
  }

  $blocked = false;
  $sources = [];

  $usedLocal = false;
  if (ensure_blocklist_files_local()) {
    $usedLocal = true;
    foreach (BLOCKLIST_URLS as $url) {
      $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);
      $localPath = BLOCKLIST_FILES_DIR . '/' . $fileName;
      if (domain_in_local_blocklist_file($localPath, $domain)) {
        $blocked = true;
        $sources[] = 'local:' . $fileName;
        break;
      }
    }
  }

  // Fallback: remote streaming scan (slower) if local files are unavailable.
  if (!$blocked && !$usedLocal) {
    foreach (BLOCKLIST_URLS as $url) {
      $ok = stream_check_domain_in_url($url, $domain, 60);
      if ($ok) {
        $blocked = true;
        $sources[] = $url;
        break;
      }
    }
  }

  $result = [
    'blocked' => $blocked,
    'sources' => $sources,
  ];

  // Store small per-domain results for faster subsequent requests.
  $cache = [];
  if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $cache = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($cache)) $cache = [];
  }
  $cache[$domain] = $result;
  @file_put_contents($cacheFile, serialize($cache), LOCK_EX);

  return $result;
}

function detect_block(array $probe): array {
  $httpCode = $probe['http_code'];
  $finalUrl = strtolower($probe['final_url'] ?? '');
  $bodySample = strtolower($probe['body_sample'] ?? '');

  $reasons = [];

  // Common codes when blocked by filtering.
  if ($httpCode === 403) $reasons[] = 'HTTP 403 (akses ditolak)';
  if ($httpCode === 451) $reasons[] = 'HTTP 451 (dibatasi oleh aturan)';

  $keywords = [
    'diblokir',
    'situs diblokir',
    'situs ini diblokir',
    'halaman ini diblokir',
    'akses dibatasi',
    'akses ditolak',
    'kominfo',
    'komdigi',
    'trustpositif',
    'trust positif',
    'aduankonten',
    'aduan konten',
    'konten diblokir',
    'dihentikan',
    'akses terhadap',
    'tidak dapat diakses',
    'domain telah diblokir',
  ];

  foreach ($keywords as $kw) {
    if ($kw !== '' && strpos($bodySample, $kw) !== false) {
      $reasons[] = 'Konten mengandung kata kunci: ' . $kw;
    }
  }

  if ($finalUrl !== '') {
    if (strpos($finalUrl, 'kominfo') !== false || strpos($finalUrl, 'komdigi') !== false) {
      $reasons[] = 'Redirect mengarah ke domain layanan blokir';
    }
  }

  // "likely" to reduce false positives.
  $likely = count($reasons) > 0;

  // De-duplicate reasons while preserving order.
  $seen = [];
  $uniqueReasons = [];
  foreach ($reasons as $r) {
    if (!isset($seen[$r])) {
      $seen[$r] = true;
      $uniqueReasons[] = $r;
    }
  }

  return [
    'likely' => $likely,
    'reasons' => $uniqueReasons,
  ];
}

function http_probe(string $domain, string $scheme, int $timeoutSeconds = 5): array {
  $url = $scheme . '://' . $domain . '/';
  $reachable = false;
  $http_code = null;
  $error = null;
  $final_url = null;

  // Prefer cURL when available.
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      // HEAD request to keep response small and fast.
      CURLOPT_NOBODY => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
      CURLOPT_TIMEOUT => $timeoutSeconds,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_USERAGENT => 'NawalaAPI-Checker/1.0',
      CURLOPT_MAXREDIRS => 5,
    ]);

    $result = curl_exec($ch);
    if ($result === false) {
      $error = curl_error($ch) ?: 'unknown curl error';
    } else {
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    }
    curl_close($ch);
  } else {
    // Fallback: use get_headers (no body sniffing possible).
    $context = stream_context_create([
      'http' => [
        'method' => 'HEAD',
        'timeout' => $timeoutSeconds,
        'ignore_errors' => true,
        'header' => 'User-Agent: NawalaAPI-Checker/1.0' . "\r\n",
      ],
      'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
      ],
    ]);

    $headers = @get_headers($url, true, $context);
    if ($headers === false || !isset($headers[0])) {
      $error = 'http probe failed (no headers)';
    } else {
      // Example: "HTTP/1.1 200 OK"
      $line = is_string($headers[0]) ? $headers[0] : '';
      if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $line, $m)) {
        $http_code = (int)$m[1];
      }
    }
  }

  if ($http_code !== null && $http_code >= 200 && $http_code < 400) {
    $reachable = true;
  }

  return [
    'scheme' => $scheme,
    'reachable' => $reachable,
    'http_code' => $http_code,
    'error' => $error,
    'final_url' => $final_url,
  ];
}

function http_probe_schemes(string $domain, array $schemes, int $timeoutSeconds = 4): array {
  // Simple fallback: no cURL => sequential probes.
  if (!function_exists('curl_multi_init')) {
    $out = [];
    foreach ($schemes as $scheme) {
      $out[] = http_probe($domain, (string)$scheme, $timeoutSeconds);
    }
    return $out;
  }

  $multi = curl_multi_init();
  $handles = [];
  $results = [];

  foreach ($schemes as $scheme) {
    $scheme = (string)$scheme;
    $url = $scheme . '://' . $domain . '/';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_NOBODY => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
      CURLOPT_TIMEOUT => $timeoutSeconds,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_USERAGENT => 'NawalaAPI-Checker/1.0',
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_PRIVATE => $scheme, // keep scheme attached to handle
    ]);

    $handles[] = $ch;
    curl_multi_add_handle($multi, $ch);
  }

  // Run until timeout; curl_multi_exec is usually fast for TCP connect + HEAD.
  $start = microtime(true);
  do {
    $status = curl_multi_exec($multi, $running);
    if ($running) curl_multi_select($multi, 0.2);
  } while ($running && (microtime(true) - $start) < ($timeoutSeconds + 0.5));

  foreach ($handles as $ch) {
    $scheme = (string)curl_getinfo($ch, CURLINFO_PRIVATE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = null;
    if ($http_code === 0) {
      $error = curl_error($ch) ?: 'http probe failed';
    }

    $reachable = ($http_code !== null && $http_code >= 200 && $http_code < 400);
    $results[] = [
      'scheme' => $scheme,
      'reachable' => $reachable,
      'http_code' => ($http_code !== null && $http_code !== false) ? (int)$http_code : null,
      'error' => $error,
      'final_url' => $final_url,
    ];

    curl_multi_remove_handle($multi, $ch);
    curl_close($ch);
  }

  curl_multi_close($multi);

  // Keep a stable order matching input $schemes.
  $byScheme = [];
  foreach ($results as $r) $byScheme[$r['scheme']] = $r;
  $out = [];
  foreach ($schemes as $scheme) {
    $scheme = (string)$scheme;
    if (isset($byScheme[$scheme])) $out[] = $byScheme[$scheme];
  }
  return $out;
}

// Execute main request handler only when this file is the web entrypoint.
// Jangan jalankan dari CLI (cron/update_sources.php mem-include file ini).
// Guard juga mencegah edge case realpath() yang gagal (mis. open_basedir) sehingga perbandingan false === false.
if (PHP_SAPI !== 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
  $domainInput = $_GET['domain'] ?? $_POST['domain'] ?? '';
  $domainInput = is_string($domainInput) ? $domainInput : '';

if ($domainInput === '') {
  response([
    'status' => 'invalid',
    'message' => 'Parameter `domain` wajib diisi.',
    'domain' => null,
  ], 400);
}

$domain = normalize_domain($domainInput);

if (!is_valid_domain($domain)) {
  response([
    'status' => 'invalid',
    'message' => 'Format domain tidak sesuai.',
    'domain' => $domain,
  ], 400);
}

$checkedAt = (new DateTimeImmutable('now'))->format('c');

$dns = dns_resolved($domain);

// Compute nawala DNS probe (fast UDP query with EDE) for "blocked" indicator.
$nawalaProbe = nawala_dns_probe($domain);

$blockLikely = false;
$blockReasons = [];
$blockSources = [];

// 1) Check Skiddle-ID first (local blocklists).
$skiddleCheck = check_blocklist_domain($domain);
if ($skiddleCheck['blocked'] ?? false) {
  $blockLikely = true;
  $blockReasons[] = 'Terdaftar di blocklist Skiddle-ID.';
  $blockSources = $skiddleCheck['sources'] ?? [];
}

// 2) If not found in Skiddle-ID, consult fast nawala DNS indicator.
if (!$blockLikely && ($nawalaProbe['blocked'] ?? false)) {
  $blockLikely = true;
  $blockReasons[] = 'Respon DNS Nawala cocok (indikasi blokir ABSPositif/Komdigi).';
  $blockSources[] = 'dns:nawala';
}

// 3) Optional ABSPositif DB check only from local cache (avoid slow remote download).
if (!$blockLikely) {
  $trustPositifCheck = check_trustpositif_domain($domain, false);
  if ($trustPositifCheck['blocked'] ?? false) {
    $blockLikely = true;
    $blockReasons[] = 'Terdaftar di database Trust Positif Komdigi (domains / domains_isp / ipaddress_isp, cache lokal).';
    $blockSources = $trustPositifCheck['sources'] ?? ['trustpositif:assets/db/domains'];
  }
}

// Network probing (HTTP) only when DNS resolves and not already blocked.
$checkedHttp = [];
$reachable = false;
if (!$blockLikely && $dns['resolved']) {
  // Parallel HEAD probes for https + http to reduce total latency.
  $checkedHttp = http_probe_schemes($domain, ['https', 'http'], 4);
  foreach ($checkedHttp as $p) {
    if (($p['reachable'] ?? false) === true) {
      $reachable = true;
      break;
    }
  }
}

$networkBlocked = (!$dns['resolved']) || (!$reachable);

// If we didn't probe HTTP (because blockLikely already true), "network blocked"
// should not automatically become true.
$networkBlocked = (!$dns['resolved']) || (!$blockLikely && !$reachable);

$httpBlockReasons = [];
$httpBlockSources = [];
if (!$blockLikely && $dns['resolved'] && !$reachable && is_array($checkedHttp)) {
  foreach ($checkedHttp as $p) {
    $httpCode = $p['http_code'] ?? null;
    $finalUrl = strtolower((string)($p['final_url'] ?? ''));

    if ($httpCode === 403) {
      $httpBlockReasons[] = 'HTTP 403 (akses ditolak)';
      $httpBlockSources[] = 'http:403';
    }
    if ($httpCode === 451) {
      $httpBlockReasons[] = 'HTTP 451 (dibatasi oleh aturan)';
      $httpBlockSources[] = 'http:451';
    }
    if ($finalUrl !== '' && (strpos($finalUrl, 'kominfo') !== false || strpos($finalUrl, 'komdigi') !== false)) {
      $httpBlockReasons[] = 'Redirect mengarah ke domain layanan blokir';
      $httpBlockSources[] = 'http:redirect-komdigi';
    }
  }
}

$httpBlockLikely = !empty($httpBlockReasons);

// Final status mapping (align blocked when either DNS Nawala/list OR network signals block).
if ($blockLikely) {
  response([
    'status' => 'blocked',
    'message' => 'Terindikasi diblokir (Skiddle-ID, database Trust Positif Komdigi termasuk ISP, atau indikasi DNS Nawala).',
    'domain' => $domain,
    'dns' => $dns,
    'nawala' => $nawalaProbe,
    'network' => [
      'blocked' => $networkBlocked,
      'reachable' => $reachable,
    ],
    'block' => [
      'likely' => true,
      'reasons' => $blockReasons ?: ['Terindikasi diblokir.'],
      'sources' => $blockSources,
    ],
    'http' => [
      'checked' => $checkedHttp,
      'reachable' => $reachable,
    ],
    'checked_at' => $checkedAt,
  ]);
}

if (!$dns['resolved']) {
  response([
    'status' => 'blocked',
    'message' => 'Terindikasi diblokir (DNS tidak ter-resolve).',
    'domain' => $domain,
    'dns' => $dns,
    'nawala' => $nawalaProbe,
    'network' => [
      'blocked' => true,
      'reachable' => false,
    ],
    'block' => [
      'likely' => true,
      'reasons' => ['DNS tidak ter-resolve (indikasi nawala/expired atau pemblokiran).'],
      'sources' => ['dns:unresolved'],
    ],
    'http' => [
      'checked' => [],
      'reachable' => false,
    ],
    'checked_at' => $checkedAt,
  ]);
}

if ($reachable) {
  response([
    'status' => 'ok',
    'message' => 'Domain terjangkau (DNS ada dan HTTP bisa diakses, serta tidak terindikasi diblokir).',
    'domain' => $domain,
    'dns' => $dns,
    'nawala' => $nawalaProbe,
    'network' => [
      'blocked' => false,
      'reachable' => true,
    ],
    'http' => [
      'checked' => $checkedHttp,
      'reachable' => true,
    ],
    'checked_at' => $checkedAt,
  ]);
}

if ($httpBlockLikely) {
  response([
    'status' => 'blocked',
    'message' => 'Terindikasi diblokir (HTTP menampilkan sinyal pemblokiran seperti 403/451).',
    'domain' => $domain,
    'dns' => $dns,
    'nawala' => $nawalaProbe,
    'network' => [
      'blocked' => true,
      'reachable' => false,
    ],
    'block' => [
      'likely' => true,
      'reasons' => $httpBlockReasons,
      'sources' => $httpBlockSources,
    ],
    'http' => [
      'checked' => $checkedHttp,
      'reachable' => false,
    ],
    'checked_at' => $checkedAt,
  ]);
}

response([
  'status' => 'down_http',
  'message' => 'DNS ada, tapi HTTP gagal dijangkau (kemungkinan down/proteksi lainnya).',
  'domain' => $domain,
  'dns' => $dns,
  'nawala' => $nawalaProbe,
  'network' => [
    'blocked' => true,
    'reachable' => false,
  ],
  'http' => [
    'checked' => $checkedHttp,
    'reachable' => false,
  ],
  'checked_at' => $checkedAt,
]);
}

