<?php
declare(strict_types=1);

// Batch checker endpoint.
//
// Accepts POST JSON payload:
// - { "name": "a.com\nb.com" }  (matches nawala.asia style)
// - or { "domains": ["a.com","b.com"] }
//
// Response:
// - { "data": [ { domain, nawala:{blocked}, network:{blocked}, ... }, ... ] }

require_once __DIR__ . '/check.php';

if (realpath(__FILE__) !== realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
  // When included accidentally, do nothing.
  exit;
}

header('Content-Type: application/json; charset=utf-8');

function parse_domains_input(array $body): array {
  $raw = '';
  if (isset($body['domains']) && is_array($body['domains'])) {
    $items = [];
    foreach ($body['domains'] as $d) {
      if (is_string($d) && trim($d) !== '') $items[] = (string)$d;
    }
    return $items;
  }

  if (isset($body['name']) && is_string($body['name'])) {
    $raw = $body['name'];
  } elseif (isset($body['query']) && is_string($body['query'])) {
    $raw = $body['query'];
  } elseif (isset($body['domain']) && is_string($body['domain'])) {
    $raw = $body['domain'];
  }

  if ($raw === '') return [];

  // Accept common separators (comma/newline/space).
  $parts = preg_split('/[\s,;]+/', $raw) ?: [];
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p === '') continue;
    $out[] = $p;
  }
  return $out;
}

function normalize_blocklist_line_fast(string $line): string {
  $line = trim($line);
  if ($line === '') return '';
  if (substr($line, 0, 1) === '#') return '';

  $line = strtolower($line);
  if (str_starts_with($line, '*.' )) $line = substr($line, 2);

  $line = rtrim($line, '.');
  if (str_starts_with($line, 'www.')) $line = substr($line, 4);
  if (str_starts_with($line, '*')) $line = substr($line, 1);

  return $line;
}

function scan_local_blocklist_files_batch(array $needles, string $dir): array {
  // Returns:
  // - blockedMap: normalizedNeedle => true
  // - sourcesMap: normalizedNeedle => [source1, source2...]
  $blockedMap = [];
  $sourcesMap = [];

  if (empty($needles)) return ['blockedMap' => $blockedMap, 'sourcesMap' => $sourcesMap];

  $needleSet = [];
  foreach ($needles as $d) $needleSet[$d] = true;

  if (!is_dir($dir)) return ['blockedMap' => $blockedMap, 'sourcesMap' => $sourcesMap];

  $files = @glob($dir . '/domains_*.txt') ?: [];
  // If empty, caller will treat as unavailable.
  foreach ($files as $file) {
    $fileName = basename($file);
    $fp = @fopen($file, 'rb');
    if ($fp === false) continue;

    while (!feof($fp)) {
      $line = fgets($fp);
      if ($line === false) break;
      $norm = normalize_blocklist_line_fast((string)$line);
      if ($norm === '') continue;
      if (isset($needleSet[$norm])) {
        $blockedMap[$norm] = true;
        if (!isset($sourcesMap[$norm])) $sourcesMap[$norm] = [];
        $sourcesMap[$norm][] = 'local:' . $fileName;

        // Early exit if we've matched everything.
        if (count($blockedMap) === count($needleSet)) break 2;
      }
    }
    @fclose($fp);
  }

  return ['blockedMap' => $blockedMap, 'sourcesMap' => $sourcesMap];
}

function scan_local_trustpositif_batch(array $needles, string $localPath, string $sourceTag = 'trustpositif:assets/db/domains'): array {
  // Returns: blockedMap + sourcesMap (single source)
  $blockedMap = [];
  $sourcesMap = [];
  if (empty($needles) || !is_file($localPath) || (int)@filesize($localPath) <= 0) {
    return ['blockedMap' => $blockedMap, 'sourcesMap' => $sourcesMap];
  }

  $needleSet = [];
  foreach ($needles as $d) {
    $needleSet[$d] = true;
  }

  $fp = @fopen($localPath, 'rb');
  if ($fp === false) {
    return ['blockedMap' => $blockedMap, 'sourcesMap' => $sourcesMap];
  }

  while (!feof($fp)) {
    $line = fgets($fp);
    if ($line === false) {
      break;
    }
    $norm = normalize_blocklist_line_fast((string)$line);
    if ($norm === '') {
      continue;
    }
    if (isset($needleSet[$norm])) {
      $blockedMap[$norm] = true;
      $sourcesMap[$norm] = [$sourceTag];
      if (count($blockedMap) === count($needleSet)) {
        break;
      }
    }
  }
  @fclose($fp);

  return ['blockedMap' => $blockedMap, 'sourcesMap' => $sourcesMap];
}

// ---- Main handler ----
$rawBody = file_get_contents('php://input') ?: '';
$body = [];
if ($rawBody !== '') {
  $decoded = json_decode($rawBody, true);
  if (is_array($decoded)) $body = $decoded;
}

$fallbackBody = [];
if (empty($body) && !empty($_POST) && is_array($_POST)) {
  $fallbackBody = $_POST;
} elseif (empty($body) && !empty($_GET) && is_array($_GET)) {
  $fallbackBody = $_GET;
}
$body = !empty($body) ? $body : $fallbackBody;

$domainsRaw = parse_domains_input($body);
if (empty($domainsRaw)) {
  response(['data' => [], 'error' => 'No domains provided.'], 400);
}

// Normalize & validate
$normalized = [];
$invalid = 0;
foreach ($domainsRaw as $d) {
  if (!is_string($d) || trim($d) === '') continue;
  $nd = normalize_domain($d);
  if ($nd === '' || !is_valid_domain($nd)) {
    $invalid++;
    continue;
  }
  $normalized[$nd] = true; // dedupe
}

$domains = array_keys($normalized);
if (empty($domains)) {
  response(['data' => [], 'error' => 'No valid domains.'], 400);
}

if (count($domains) > 100) {
  $domains = array_slice($domains, 0, 100);
}

$checkedAt = (new DateTimeImmutable('now'))->format('c');

// DNS resolve + nawala UDP probe
$dnsByDomain = [];
$nawalaByDomain = [];
foreach ($domains as $domain) {
  $dnsByDomain[$domain] = dns_resolved($domain);
  $nawalaByDomain[$domain] = nawala_dns_probe($domain);
}

// Skiddle-ID / ABSPositif: hanya baca cache lokal; pembaruan = cron/update_sources.php
ensure_blocklist_files_local();
ensure_trustpositif_domains_local();
$needles = $domains;
$skiddleScan = scan_local_blocklist_files_batch(
  $needles,
  __DIR__ . '/../cache/blocklist_files'
);

// ABSPositif: domains + domains_isp + IP ISP (cache lokal; pembaruan = cron).
$trustPositifLocalUsable = is_file(TRUSTPOSITIF_LOCAL_PATH) && (int)@filesize(TRUSTPOSITIF_LOCAL_PATH) > 0;

$trustScan = ['blockedMap' => [], 'sourcesMap' => []];
if ($trustPositifLocalUsable) {
  $trustScan = scan_local_trustpositif_batch($needles, TRUSTPOSITIF_LOCAL_PATH, 'trustpositif:assets/db/domains');
}
$domainsIspPath = TRUSTPOSITIF_DOMAINS_ISP_LOCAL_PATH;
if (is_file($domainsIspPath) && (int)@filesize($domainsIspPath) > 0) {
  $ispDomainScan = scan_local_trustpositif_batch($needles, $domainsIspPath, 'trustpositif:assets/db/domains_isp');
  foreach ($ispDomainScan['blockedMap'] as $d => $_) {
    if (!isset($trustScan['blockedMap'][$d])) {
      $trustScan['blockedMap'][$d] = true;
      $trustScan['sourcesMap'][$d] = $ispDomainScan['sourcesMap'][$d] ?? ['trustpositif:assets/db/domains_isp'];
    }
  }
}
$ipSet = trustpositif_ip_isp_set();
if ($ipSet !== null) {
  foreach ($needles as $domain) {
    if (isset($trustScan['blockedMap'][$domain])) {
      continue;
    }
    foreach (dns_a_records_ipv4($domain) as $ip) {
      if (isset($ipSet[$ip])) {
        $trustScan['blockedMap'][$domain] = true;
        $trustScan['sourcesMap'][$domain] = ['trustpositif:assets/db/ipaddress_isp'];
        break;
      }
    }
  }
}

// HTTP probe tasks for candidates that are not already "blocked likely".
$httpTasks = []; // each: ['domain'=>..., 'schemes'=>['https','http']]
$result = [];

foreach ($domains as $domain) {
  $nawalaProbe = $nawalaByDomain[$domain];
  $dns = $dnsByDomain[$domain];
  $nawalaBlocked = (bool)($nawalaProbe['blocked'] ?? false);

  $skiddleBlocked = (bool)isset($skiddleScan['blockedMap'][$domain]);
  $trustBlocked = (bool)isset($trustScan['blockedMap'][$domain]);

  $blockLikely = $skiddleBlocked || $trustBlocked || $nawalaBlocked;

  if (!$blockLikely && ($dns['resolved'] ?? false)) {
    $httpTasks[] = $domain;
  }

  $result[$domain] = [
    'domain' => $domain,
    'checked_at' => $checkedAt,
    'nawala' => [
      'blocked' => $nawalaBlocked,
      'ips_matched' => $nawalaProbe['ips_matched'] ?? [],
      'ede' => $nawalaProbe['ede'] ?? [],
      'tried' => $nawalaProbe['tried'] ?? [],
    ],
    'network' => [
      'blocked' => false,
      'reachable' => false,
    ],
    'block' => [
      'likely' => $blockLikely,
      'sources' => [],
    ],
    'http' => [
      'checked' => [],
      'reachable' => false,
    ],
  ];

  if ($skiddleBlocked) {
    $result[$domain]['block']['sources'] = $skiddleScan['sourcesMap'][$domain] ?? ['local:blocklist'];
  } elseif ($trustBlocked) {
    $result[$domain]['block']['sources'] = $trustScan['sourcesMap'][$domain] ?? ['trustpositif:assets/db/domains'];
  } elseif ($nawalaBlocked) {
    $result[$domain]['block']['sources'] = ['dns:nawala'];
  }
}

// Perform HTTP probes in parallel for domains needing it.
// We probe both schemes; final reachable = any scheme reachable.
$checkedHttpByDomain = [];
if (!empty($httpTasks)) {
  $timeoutSeconds = 4;
  if (function_exists('curl_multi_init')) {
    $multi = curl_multi_init();
    $handles = [];

    foreach ($httpTasks as $domain) {
      foreach (['https', 'http'] as $scheme) {
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
          CURLOPT_PRIVATE => $domain . '|' . $scheme,
        ]);
        $handles[] = $ch;
        curl_multi_add_handle($multi, $ch);
      }
    }

    $start = microtime(true);
    do {
      $status = curl_multi_exec($multi, $running);
      if ($running) curl_multi_select($multi, 0.2);
    } while ($running && (microtime(true) - $start) < ($timeoutSeconds + 0.8));

    foreach ($handles as $ch) {
      $priv = (string)(curl_getinfo($ch, CURLINFO_PRIVATE) ?? '');
      [$domain, $scheme] = array_pad(explode('|', $priv, 2), 2, '');
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

      $error = null;
      if ($http_code === 0) {
        $error = curl_error($ch) ?: 'http probe failed';
      }

      $reachable = ($http_code !== null && (int)$http_code >= 200 && (int)$http_code < 400);

      if (!isset($checkedHttpByDomain[$domain])) $checkedHttpByDomain[$domain] = [];
      $checkedHttpByDomain[$domain][] = [
        'scheme' => $scheme,
        'reachable' => $reachable,
        'http_code' => ($http_code !== false && $http_code !== null) ? (int)$http_code : null,
        'error' => $error,
        'final_url' => $final_url,
      ];

      curl_multi_remove_handle($multi, $ch);
      curl_close($ch);
    }
    curl_multi_close($multi);
  } else {
    // No curl_multi: fallback sequential (still okay for small batches).
    foreach ($httpTasks as $domain) {
      $checkedHttpByDomain[$domain] = http_probe_schemes($domain, ['https', 'http'], 4);
    }
  }
}

// Merge HTTP results into final per-domain response.
foreach ($domains as $domain) {
  $dns = $dnsByDomain[$domain];
  $dnsResolved = (bool)($dns['resolved'] ?? false);
  $httpChecked = $checkedHttpByDomain[$domain] ?? [];

  $reachable = false;
  foreach ($httpChecked as $p) {
    if (($p['reachable'] ?? false) === true) {
      $reachable = true;
      break;
    }
  }

  $networkBlocked = !$dnsResolved || !$reachable;

  $result[$domain]['network']['reachable'] = $reachable;
  $result[$domain]['network']['blocked'] = $networkBlocked;
  $result[$domain]['http']['checked'] = $httpChecked;
  $result[$domain]['http']['reachable'] = $reachable;

  // Pertahankan indikasi Skiddle/Trust/nawala dari loop pertama; tambahkan blok jaringan bila perlu.
  $result[$domain]['block']['likely'] = (bool)($result[$domain]['block']['likely'] ?? false)
    || ($result[$domain]['network']['blocked'] === true);
}

// Return array in input order.
$data = [];
foreach ($domains as $domain) $data[] = $result[$domain];

response([
  'data' => $data,
  'invalid' => $invalid,
], 200);

