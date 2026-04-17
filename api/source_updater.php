<?php
declare(strict_types=1);

/**
 * Pembaruan data Skiddle-ID & ABSPositif — dipanggil dari cron/CLI saja.
 * Checker (`check.php`) tidak melakukan unduh/HEAD; hanya membaca cache di `cache/`.
 */
require_once __DIR__ . '/check.php';

const NAWALA_SOURCES_META_FILE = __DIR__ . '/../cache/sources_meta.json';

function nawala_updater_load_meta(): array {
  if (!is_file(NAWALA_SOURCES_META_FILE)) return ['urls' => []];
  $raw = @file_get_contents(NAWALA_SOURCES_META_FILE);
  if ($raw === false || $raw === '') return ['urls' => []];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : ['urls' => []];
}

function nawala_updater_save_meta(array $meta): void {
  $dir = dirname(NAWALA_SOURCES_META_FILE);
  if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
  }
  @file_put_contents(
    NAWALA_SOURCES_META_FILE,
    json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
  );
}

function nawala_updater_parse_http_headers(string $raw, int $httpCode): array {
  $out = [
    'http_code' => $httpCode,
    'etag' => '',
    'last-modified' => '',
    'content-length' => '',
  ];
  if ($raw === '') return $out;
  $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
  if (!empty($lines)) {
    array_shift($lines);
  }
  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || stripos($line, 'HTTP/') === 0) {
      continue;
    }
    $pos = strpos($line, ':');
    if ($pos === false) {
      continue;
    }
    $name = strtolower(trim(substr($line, 0, $pos)));
    $value = trim(substr($line, $pos + 1));
    if ($name === 'etag') {
      $out['etag'] = $value;
    }
    if ($name === 'last-modified') {
      $out['last-modified'] = $value;
    }
    if ($name === 'content-length') {
      $out['content-length'] = $value;
    }
  }
  return $out;
}

function nawala_updater_fingerprint_from_head(array $h): string {
  $etag = (string)($h['etag'] ?? '');
  $lm = (string)($h['last-modified'] ?? '');
  $cl = (string)($h['content-length'] ?? '');
  if ($etag !== '') {
    return 'etag:' . $etag;
  }
  if ($lm !== '' || $cl !== '') {
    return 'lmcl:' . $lm . '|' . $cl;
  }
  return '';
}

function nawala_updater_http_head_single(string $url, int $timeoutSeconds = 12): ?array {
  if (!function_exists('curl_init')) {
    return null;
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_HEADER => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
    CURLOPT_TIMEOUT => $timeoutSeconds,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'NawalaAPI-SourceUpdater/1.0',
  ]);
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($raw === false || !is_string($raw)) {
    return null;
  }
  if ($code < 200 || $code >= 400) {
    return null;
  }
  return nawala_updater_parse_http_headers($raw, $code);
}

/**
 * @param list<string> $urls
 * @return array<string, array|null>
 */
function nawala_updater_http_head_multi(array $urls, int $timeoutSeconds = 12): array {
  $out = [];
  foreach ($urls as $u) {
    $out[$u] = null;
  }

  if (!function_exists('curl_multi_init')) {
    foreach ($urls as $u) {
      $out[$u] = nawala_updater_http_head_single($u, $timeoutSeconds);
    }
    return $out;
  }

  $mh = curl_multi_init();
  $chs = [];
  foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_NOBODY => true,
      CURLOPT_HEADER => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
      CURLOPT_TIMEOUT => $timeoutSeconds,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
      CURLOPT_USERAGENT => 'NawalaAPI-SourceUpdater/1.0',
      CURLOPT_PRIVATE => $url,
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[] = $ch;
  }

  $start = microtime(true);
  do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
      curl_multi_select($mh, 0.2);
    }
  } while ($running && (microtime(true) - $start) < ($timeoutSeconds + 1.0));

  foreach ($chs as $ch) {
    $url = (string)curl_getinfo($ch, CURLINFO_PRIVATE);
    $raw = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (is_string($raw) && $code >= 200 && $code < 400) {
      $out[$url] = nawala_updater_parse_http_headers($raw, $code);
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);

  return $out;
}

function nawala_updater_acquire_lock(string $lockFile, int $lockTimeoutSeconds, int $staleLockMaxAgeSeconds = 900): bool {
  // Jika proses sebelumnya crash, lock bisa tertinggal — buang jika sudah terlalu lama.
  if (is_file($lockFile) && (time() - filemtime($lockFile)) > $staleLockMaxAgeSeconds) {
    @unlink($lockFile);
  }

  $start = time();
  while ((time() - $start) < $lockTimeoutSeconds) {
    $fp = @fopen($lockFile, 'x');
    if ($fp !== false) {
      @fwrite($fp, (string)getmypid());
      @fclose($fp);
      return true;
    }
    usleep(500000);
  }
  return false;
}

function nawala_updater_invalidate_blocklist_member_cache(): void {
  $f = __DIR__ . '/../cache/blocklist_member_cache.ser';
  if (is_file($f)) {
    @unlink($f);
  }
}

function nawala_updater_invalidate_trustpositif_member_cache(): void {
  $f = __DIR__ . '/../cache/trustpositif_member_cache.ser';
  if (is_file($f)) {
    @unlink($f);
  }
}

/**
 * @return array{ok: bool, refreshed: list<string>, errors: list<string>, note?: string}
 */
function nawala_updater_sync_blocklists(bool $forceFull = false): array {
  $errors = [];
  $refreshed = [];

  if (!is_dir(BLOCKLIST_FILES_DIR)) {
    @mkdir(BLOCKLIST_FILES_DIR, 0777, true);
  }

  $lockFile = BLOCKLIST_FILES_DIR . '/.download.lock';
  $now = time();

  $localOk = static function (string $localPath): bool {
    return is_file($localPath) && (int)@filesize($localPath) > 0;
  };

  if (!nawala_updater_acquire_lock($lockFile, 240)) {
    return [
      'ok' => false,
      'refreshed' => [],
      'errors' => ['Could not acquire blocklist download lock'],
    ];
  }

  try {
    $meta = nawala_updater_load_meta();
    if (!isset($meta['urls']) || !is_array($meta['urls'])) {
      $meta['urls'] = [];
    }

    if ($forceFull) {
      foreach (BLOCKLIST_URLS as $url) {
        $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        $localPath = BLOCKLIST_FILES_DIR . '/' . $fileName;
        if (download_url_to_file($url, $localPath, 120)) {
          $refreshed[] = $fileName;
        } else {
          $errors[] = 'Download failed: ' . $fileName;
        }
      }
      nawala_updater_invalidate_blocklist_member_cache();
      foreach (BLOCKLIST_URLS as $url) {
        $nh = nawala_updater_http_head_single($url, 15);
        if (is_array($nh)) {
          $fp = nawala_updater_fingerprint_from_head($nh);
          if ($fp !== '') {
            $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
            $meta['urls'][$url] = array_merge($prev, [
              'fingerprint' => $fp,
              'last_download_at' => time(),
            ]);
          }
        }
      }
      $meta['skiddle_last_head_check'] = $now;
      nawala_updater_save_meta($meta);
      return ['ok' => empty($errors), 'refreshed' => $refreshed, 'errors' => $errors];
    }

    $anyMissing = false;
    foreach (BLOCKLIST_URLS as $url) {
      $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);
      $localPath = BLOCKLIST_FILES_DIR . '/' . $fileName;
      if (!$localOk($localPath)) {
        $anyMissing = true;
        break;
      }
    }

    if ($anyMissing) {
      $headsMissing = nawala_updater_http_head_multi(BLOCKLIST_URLS, 15);
      foreach (BLOCKLIST_URLS as $url) {
        $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        $localPath = BLOCKLIST_FILES_DIR . '/' . $fileName;
        if ($localOk($localPath)) {
          continue;
        }

        $hM = $headsMissing[$url] ?? null;
        $newFpM = is_array($hM) ? nawala_updater_fingerprint_from_head($hM) : '';
        $prevM = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
        $oldFpM = (string)($prevM['fingerprint'] ?? '');

        $meta['urls'][$url] = array_merge($prevM, ['last_head_at' => $now]);

        if ($newFpM !== '' && $oldFpM !== '' && $newFpM === $oldFpM) {
          $errors[] = 'Missing ' . $fileName . ' but HEAD matches meta fingerprint; use --force to re-download';
          continue;
        }

        if (download_url_to_file($url, $localPath, 120)) {
          $refreshed[] = $fileName;
        } else {
          $errors[] = 'Download failed (missing): ' . $fileName;
        }
      }
      if (!empty($refreshed)) {
        nawala_updater_invalidate_blocklist_member_cache();
      }
      foreach (BLOCKLIST_URLS as $url) {
        $nh = nawala_updater_http_head_single($url, 15);
        if (is_array($nh)) {
          $fp = nawala_updater_fingerprint_from_head($nh);
          if ($fp !== '') {
            $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
            $meta['urls'][$url] = array_merge($prev, [
              'fingerprint' => $fp,
              'last_download_at' => time(),
            ]);
          }
        }
      }
      $meta['skiddle_last_head_check'] = $now;
      nawala_updater_save_meta($meta);
      return ['ok' => empty($errors), 'refreshed' => $refreshed, 'errors' => $errors];
    }

    $heads = nawala_updater_http_head_multi(BLOCKLIST_URLS, 15);
    $urlsToRefresh = [];

    foreach (BLOCKLIST_URLS as $url) {
      $h = $heads[$url] ?? null;
      if (!is_array($h)) {
        continue;
      }
      $newFp = nawala_updater_fingerprint_from_head($h);
      $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
      $oldFp = (string)($prev['fingerprint'] ?? '');

      $meta['urls'][$url] = array_merge($prev, ['last_head_at' => $now]);

      if ($newFp === '') {
        continue;
      }
      if ($oldFp === '') {
        $meta['urls'][$url]['fingerprint'] = $newFp;
        continue;
      }
      if ($oldFp !== $newFp) {
        $urlsToRefresh[] = $url;
      }
    }

    $meta['skiddle_last_head_check'] = $now;
    nawala_updater_save_meta($meta);

    if (empty($urlsToRefresh)) {
      return [
        'ok' => true,
        'refreshed' => [],
        'errors' => [],
        'note' => 'Skiddle blocklists unchanged (HEAD fingerprint match)',
      ];
    }

    foreach ($urlsToRefresh as $url) {
      $fileName = basename(parse_url($url, PHP_URL_PATH) ?: $url);
      $localPath = BLOCKLIST_FILES_DIR . '/' . $fileName;
      if (download_url_to_file($url, $localPath, 120)) {
        $refreshed[] = $fileName;
      } else {
        $errors[] = 'Download failed: ' . $fileName;
      }
    }

    if (!empty($refreshed)) {
      nawala_updater_invalidate_blocklist_member_cache();
    }

    $meta = nawala_updater_load_meta();
    foreach ($urlsToRefresh as $url) {
      $nh = nawala_updater_http_head_single($url, 15);
      if (is_array($nh)) {
        $fp = nawala_updater_fingerprint_from_head($nh);
        if ($fp !== '') {
          $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
          $meta['urls'][$url] = array_merge($prev, [
            'fingerprint' => $fp,
            'last_download_at' => time(),
          ]);
        }
      }
    }
    nawala_updater_save_meta($meta);

    return ['ok' => empty($errors), 'refreshed' => $refreshed, 'errors' => $errors];
  } finally {
    @unlink($lockFile);
  }
}

/**
 * Sinkron assets/db/domains — logika HEAD / fingerprint / unduh sama seperti versi sebelum penambahan ISP.
 *
 * @return array{ok: bool, refreshed: bool, errors: list<string>, note?: string}
 */
function nawala_updater_sync_trustpositif_domains_only(bool $forceFull = false): array {
  $destDir = dirname(TRUSTPOSITIF_LOCAL_PATH);
  if (!is_dir($destDir)) {
    @mkdir($destDir, 0777, true);
  }

  $url = TRUSTPOSITIF_DOMAINS_URL;
  $now = time();
  $lock = $destDir . '/.download.lock';

  $localOk = is_file(TRUSTPOSITIF_LOCAL_PATH) && (int)@filesize(TRUSTPOSITIF_LOCAL_PATH) > 0;

  $doDownload = static function () use ($url): bool {
    return download_url_to_file($url, TRUSTPOSITIF_LOCAL_PATH, 60, true)
      && is_file(TRUSTPOSITIF_LOCAL_PATH)
      && (int)@filesize(TRUSTPOSITIF_LOCAL_PATH) > 0;
  };

  if (!nawala_updater_acquire_lock($lock, 240)) {
    return [
      'ok' => false,
      'refreshed' => false,
      'errors' => ['Could not acquire ABSPositif download lock'],
    ];
  }

  try {
    $meta = nawala_updater_load_meta();
    if (!isset($meta['urls']) || !is_array($meta['urls'])) {
      $meta['urls'] = [];
    }

    // --force: selalu unduh penuh (dipakai untuk perbaikan manual).
    if ($forceFull) {
      if (!$doDownload()) {
        return ['ok' => false, 'refreshed' => false, 'errors' => ['ABSPositif full download failed']];
      }
      nawala_updater_invalidate_trustpositif_member_cache();
      $nh = nawala_updater_http_head_single($url, 20);
      if (is_array($nh)) {
        $fp = nawala_updater_fingerprint_from_head($nh);
        if ($fp !== '') {
          $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
          $meta['urls'][$url] = array_merge($prev, [
            'fingerprint' => $fp,
            'last_download_at' => time(),
          ]);
        }
      }
      $meta['trustpositif_last_head_check'] = $now;
      nawala_updater_save_meta($meta);
      return ['ok' => true, 'refreshed' => true, 'errors' => [], 'note' => 'ABSPositif downloaded (--force)'];
    }

    // File hilang / kosong: HEAD dulu — unduh HANYA jika sidik beda atau belum punya baseline per-bandingan.
    if (!$localOk) {
      $h0 = nawala_updater_http_head_single($url, 20);
      $meta['trustpositif_last_head_check'] = $now;
      $prev0 = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
      $oldFp0 = (string)($prev0['fingerprint'] ?? '');
      $newFp0 = is_array($h0) ? nawala_updater_fingerprint_from_head($h0) : '';

      if (is_array($h0)) {
        $meta['urls'][$url] = array_merge($prev0, ['last_head_at' => $now]);
        if ($newFp0 !== '' && $oldFp0 !== '' && $newFp0 === $oldFp0) {
          // Remote tidak berubah menurut HEAD; tidak unduh (hemat bandwidth). Perbaikan lokal pakai --force.
          nawala_updater_save_meta($meta);
          return [
            'ok' => false,
            'refreshed' => false,
            'errors' => ['ABSPositif local file missing/empty but HEAD matches stored fingerprint; use --force to re-download'],
          ];
        }
      }

      if (!$doDownload()) {
        nawala_updater_save_meta($meta);
        return ['ok' => false, 'refreshed' => false, 'errors' => ['ABSPositif download failed (initial/repair)']];
      }
      nawala_updater_invalidate_trustpositif_member_cache();
      $nh = nawala_updater_http_head_single($url, 20);
      if (is_array($nh)) {
        $fp = nawala_updater_fingerprint_from_head($nh);
        if ($fp !== '') {
          $meta = nawala_updater_load_meta();
          $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
          $meta['urls'][$url] = array_merge($prev, [
            'fingerprint' => $fp,
            'last_download_at' => time(),
          ]);
        }
      }
      nawala_updater_save_meta($meta);
      return ['ok' => true, 'refreshed' => true, 'errors' => [], 'note' => 'ABSPositif downloaded (new or remote changed / no baseline)'];
    }

    $h = nawala_updater_http_head_single($url, 20);
    $meta['trustpositif_last_head_check'] = $now;

    if (!is_array($h)) {
      nawala_updater_save_meta($meta);
      return [
        'ok' => true,
        'refreshed' => false,
        'errors' => [],
        'note' => 'ABSPositif HEAD failed; no download (local cache kept)',
      ];
    }

    $newFp = nawala_updater_fingerprint_from_head($h);
    $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
    $oldFp = (string)($prev['fingerprint'] ?? '');

    $meta['urls'][$url] = array_merge($prev, ['last_head_at' => $now]);

    if ($newFp !== '' && $oldFp === '') {
      $meta['urls'][$url]['fingerprint'] = $newFp;
      nawala_updater_save_meta($meta);
      return [
        'ok' => true,
        'refreshed' => false,
        'errors' => [],
        'note' => 'ABSPositif baseline fingerprint saved (no download)',
      ];
    }

    $changed = ($newFp !== '' && $oldFp !== '' && $oldFp !== $newFp);
    nawala_updater_save_meta($meta);

    if (!$changed) {
      return [
        'ok' => true,
        'refreshed' => false,
        'errors' => [],
        'note' => 'ABSPositif unchanged (HEAD fingerprint match; no download)',
      ];
    }

    if (!$doDownload()) {
      return ['ok' => false, 'refreshed' => false, 'errors' => ['ABSPositif download after fingerprint change failed']];
    }
    nawala_updater_invalidate_trustpositif_member_cache();
    $meta = nawala_updater_load_meta();
    $nh = nawala_updater_http_head_single($url, 20);
    if (is_array($nh)) {
      $fp = nawala_updater_fingerprint_from_head($nh);
      if ($fp !== '') {
        $p2 = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
        $meta['urls'][$url] = array_merge($p2, ['fingerprint' => $fp, 'last_download_at' => time()]);
      }
    }
    nawala_updater_save_meta($meta);
    return ['ok' => true, 'refreshed' => true, 'errors' => [], 'note' => 'ABSPositif downloaded (fingerprint changed)'];
  } finally {
    @unlink($lock);
  }
}

/**
 * Satu file Komdigi ISP (domains_isp / ipaddress_isp) — pola HEAD / fingerprint / unduh mengikuti domains_only.
 *
 * @param array<string, mixed> $meta
 * @return array{ok: bool, refreshed: bool, errors: list<string>, note: string}
 */
function nawala_updater_sync_trustpositif_isp_asset(
  string $url,
  string $path,
  bool $forceFull,
  array &$meta,
  int $now
): array {
  $doDownload = static function () use ($url, $path): bool {
    return download_url_to_file($url, $path, 60, true)
      && is_file($path)
      && (int)@filesize($path) > 0;
  };

  $label = basename($path);

  if ($forceFull) {
    if (!$doDownload()) {
      return ['ok' => false, 'refreshed' => false, 'errors' => ['ISP full download failed: ' . $label], 'note' => ''];
    }
    $nh = nawala_updater_http_head_single($url, 20);
    if (is_array($nh)) {
      $fp = nawala_updater_fingerprint_from_head($nh);
      if ($fp !== '') {
        $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
        $meta['urls'][$url] = array_merge($prev, [
          'fingerprint' => $fp,
          'last_download_at' => time(),
        ]);
      }
    }
    return ['ok' => true, 'refreshed' => true, 'errors' => [], 'note' => 'ISP ' . $label . ' (--force)'];
  }

  $localOk = is_file($path) && (int)@filesize($path) > 0;

  if (!$localOk) {
    $h0 = nawala_updater_http_head_single($url, 20);
    $prev0 = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
    $oldFp0 = (string)($prev0['fingerprint'] ?? '');
    $newFp0 = is_array($h0) ? nawala_updater_fingerprint_from_head($h0) : '';

    if (is_array($h0)) {
      $meta['urls'][$url] = array_merge($prev0, ['last_head_at' => $now]);
      if ($newFp0 !== '' && $oldFp0 !== '' && $newFp0 === $oldFp0) {
        nawala_updater_save_meta($meta);
        return [
          'ok' => false,
          'refreshed' => false,
          'errors' => [
            'ISP local missing/empty for ' . $label . ' but HEAD matches fingerprint; use --force to re-download',
          ],
          'note' => '',
        ];
      }
    }

    if (!$doDownload()) {
      return ['ok' => false, 'refreshed' => false, 'errors' => ['ISP download failed (repair): ' . $label], 'note' => ''];
    }
    $nh = nawala_updater_http_head_single($url, 20);
    if (is_array($nh)) {
      $fp = nawala_updater_fingerprint_from_head($nh);
      if ($fp !== '') {
        $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
        $meta['urls'][$url] = array_merge($prev, [
          'fingerprint' => $fp,
          'last_download_at' => time(),
        ]);
      }
    }
    return ['ok' => true, 'refreshed' => true, 'errors' => [], 'note' => 'ISP ' . $label . ' (repair)'];
  }

  $h = nawala_updater_http_head_single($url, 20);

  if (!is_array($h)) {
    return [
      'ok' => true,
      'refreshed' => false,
      'errors' => [],
      'note' => 'ISP ' . $label . ' HEAD failed; kept local',
    ];
  }

  $newFp = nawala_updater_fingerprint_from_head($h);
  $prev = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
  $oldFp = (string)($prev['fingerprint'] ?? '');

  $meta['urls'][$url] = array_merge($prev, ['last_head_at' => $now]);

  if ($newFp !== '' && $oldFp === '') {
    $meta['urls'][$url]['fingerprint'] = $newFp;
    return [
      'ok' => true,
      'refreshed' => false,
      'errors' => [],
      'note' => 'ISP ' . $label . ' baseline fingerprint saved',
    ];
  }

  $changed = ($newFp !== '' && $oldFp !== '' && $oldFp !== $newFp);

  if (!$changed) {
    return [
      'ok' => true,
      'refreshed' => false,
      'errors' => [],
      'note' => 'ISP ' . $label . ' unchanged',
    ];
  }

  if (!$doDownload()) {
    return [
      'ok' => false,
      'refreshed' => false,
      'errors' => ['ISP download after fingerprint change failed: ' . $label],
      'note' => '',
    ];
  }

  $nh = nawala_updater_http_head_single($url, 20);
  if (is_array($nh)) {
    $fp = nawala_updater_fingerprint_from_head($nh);
    if ($fp !== '') {
      $p2 = is_array($meta['urls'][$url] ?? null) ? $meta['urls'][$url] : [];
      $meta['urls'][$url] = array_merge($p2, ['fingerprint' => $fp, 'last_download_at' => time()]);
    }
  }
  return ['ok' => true, 'refreshed' => true, 'errors' => [], 'note' => 'ISP ' . $label . ' (changed)'];
}

/**
 * Tambahan: assets/db/domains_isp & ipaddress_isp (satu lock, meta sama seperti updater lain).
 *
 * @return array{ok: bool, refreshed: bool, errors: list<string>, note?: string}
 */
function nawala_updater_sync_trustpositif_isp_only(bool $forceFull = false): array {
  if (!is_dir(BLOCKLIST_FILES_DIR)) {
    @mkdir(BLOCKLIST_FILES_DIR, 0777, true);
  }

  $now = time();
  $lock = BLOCKLIST_FILES_DIR . '/.download_trustpositif_isp.lock';

  if (!nawala_updater_acquire_lock($lock, 240)) {
    return [
      'ok' => false,
      'refreshed' => false,
      'errors' => ['Could not acquire Trust Positif ISP download lock'],
    ];
  }

  try {
    $meta = nawala_updater_load_meta();
    if (!isset($meta['urls']) || !is_array($meta['urls'])) {
      $meta['urls'] = [];
    }

    $pairs = [
      [nawala_trustpositif_domains_isp_download_url(), TRUSTPOSITIF_DOMAINS_ISP_LOCAL_PATH],
      [nawala_trustpositif_ipaddress_isp_download_url(), TRUSTPOSITIF_IPADDRESS_ISP_LOCAL_PATH],
    ];

    $anyRefreshed = false;
    $allErrors = [];
    $notes = [];

    foreach ($pairs as [$url, $path]) {
      $r = nawala_updater_sync_trustpositif_isp_asset($url, $path, $forceFull, $meta, $now);
      if ($r['refreshed']) {
        $anyRefreshed = true;
      }
      foreach ($r['errors'] as $e) {
        $allErrors[] = $e;
      }
      if (($r['note'] ?? '') !== '') {
        $notes[] = $r['note'];
      }
    }

    if ($anyRefreshed) {
      nawala_updater_invalidate_trustpositif_member_cache();
    }

    $meta['trustpositif_isp_last_head_check'] = $now;
    nawala_updater_save_meta($meta);

    $ok = empty($allErrors);

    return [
      'ok' => $ok,
      'refreshed' => $anyRefreshed,
      'errors' => $allErrors,
      'note' => $ok ? implode('; ', $notes) : implode('; ', $allErrors),
    ];
  } finally {
    @unlink($lock);
  }
}

/**
 * ABSPositif domains (logika lama) + ISP Komdigi (penambahan).
 *
 * @return array{ok: bool, refreshed: bool, errors: list<string>, note?: string}
 */
function nawala_updater_sync_trustpositif(bool $forceFull = false): array {
  $domains = nawala_updater_sync_trustpositif_domains_only($forceFull);
  // Tetap lanjut unduh assets ISP (domains_isp, ipaddress_isp) walau `domains` gagal —
  // itu URL/host sama tetapi urutan & lock terpisah; gabungkan error agar jelas.
  $isp = nawala_updater_sync_trustpositif_isp_only($forceFull);

  $noteParts = array_filter([
    trim((string)($domains['note'] ?? '')),
    trim((string)($isp['note'] ?? '')),
  ]);
  $notes = implode(' | ', $noteParts);

  return [
    'ok' => ($domains['ok'] ?? false) && ($isp['ok'] ?? false),
    'refreshed' => ($domains['refreshed'] ?? false) || ($isp['refreshed'] ?? false),
    'errors' => array_merge($domains['errors'] ?? [], $isp['errors'] ?? []),
    'note' => $notes,
  ];
}

/**
 * @return array{ok: bool, blocklist: array, trustpositif: array}
 */
function nawala_run_all_source_syncs(bool $forceFull = false): array {
  $bl = nawala_updater_sync_blocklists($forceFull);
  $tp = nawala_updater_sync_trustpositif($forceFull);
  return [
    'ok' => ($bl['ok'] ?? false) && ($tp['ok'] ?? false),
    'blocklist' => $bl,
    'trustpositif' => $tp,
  ];
}
