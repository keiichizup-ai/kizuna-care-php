<?php
// lib/google_health_client.php
// Google Health API用の共通関数。既存プロジェクトに合わせて gh_pdo() だけ確認してください。
declare(strict_types=1);
require_once __DIR__ . '/../config/google_health.php';

function gh_pdo(): PDO {
    if (file_exists(__DIR__ . '/../config/db.php')) require_once __DIR__ . '/../config/db.php';
    if (file_exists(__DIR__ . '/../funcs.php')) require_once __DIR__ . '/../funcs.php';
    if (function_exists('db_conn')) return db_conn();
    if (function_exists('db')) return db();
    throw new RuntimeException('DB接続関数 db_conn() または db() が見つかりません。');
}

function gh_encrypt(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    $key = hash('sha256', GOOGLE_HEALTH_TOKEN_KEY, true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) throw new RuntimeException('encrypt failed');
    return base64_encode($iv . $cipher);
}

function gh_decrypt(?string $encoded): ?string {
    if ($encoded === null || $encoded === '') return $encoded;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) throw new RuntimeException('bad encrypted token');
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = hash('sha256', GOOGLE_HEALTH_TOKEN_KEY, true);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) throw new RuntimeException('decrypt failed');
    return $plain;
}

function gh_http(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $h = $headers;
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    $bodyText = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($bodyText === false) throw new RuntimeException($err);
    return ['status' => $status, 'body' => $bodyText, 'json' => json_decode($bodyText, true)];
}

function gh_token(array $params): array {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) throw new RuntimeException('token error: ' . $body);
    return $json;
}

function gh_api_json(string $accessToken, string $method, string $path, $body = null): array {
    $res = gh_http($method, 'https://health.googleapis.com/v4' . $path, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ], $body);
    if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('Google Health API error: ' . $res['body']);
    return is_array($res['json']) ? $res['json'] : [];
}

function gh_api_raw(string $accessToken, string $path): string {
    $res = gh_http('GET', 'https://health.googleapis.com/v4' . $path, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/xml, text/xml, */*',
    ]);
    if ($res['status'] < 200 || $res['status'] >= 300) throw new RuntimeException('Google Health raw API error: ' . $res['body']);
    return $res['body'];
}

function gh_exchange_code(string $code): array {
    return gh_token([
        'client_id' => GOOGLE_HEALTH_CLIENT_ID,
        'client_secret' => GOOGLE_HEALTH_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => GOOGLE_HEALTH_REDIRECT_URI,
    ]);
}

function gh_access_token(PDO $pdo, array $conn): string {
    if (strtotime($conn['token_expires_at'] ?? '1970-01-01') > time() + 120) return gh_decrypt($conn['access_token_enc']);
    $refresh = gh_decrypt($conn['refresh_token_enc']);
    if (!$refresh) throw new RuntimeException('refresh_tokenがありません。再連携してください。');
    $token = gh_token([
        'client_id' => GOOGLE_HEALTH_CLIENT_ID,
        'client_secret' => GOOGLE_HEALTH_CLIENT_SECRET,
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
    ]);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($token['expires_in'] ?? 3500) - 60);
    $stmt = $pdo->prepare('UPDATE health_connections SET access_token_enc=:a, token_expires_at=:e, updated_at=NOW() WHERE id=:id');
    $stmt->execute([':a' => gh_encrypt($token['access_token']), ':e' => $expiresAt, ':id' => $conn['id']]);
    return $token['access_token'];
}

function gh_identity(string $accessToken): array {
    return gh_api_json($accessToken, 'GET', '/users/me/identity');
}

function gh_daily_rollup(string $accessToken, string $dataType, string $date): array {
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return gh_api_json($accessToken, 'POST', "/users/me/dataTypes/{$dataType}/dataPoints:dailyRollUp", [
        'range' => [
            'start' => ['date' => ['year' => $y, 'month' => $m, 'day' => $d], 'time' => ['hours' => 0, 'minutes' => 0, 'seconds' => 0, 'nanos' => 0]],
            'end' => ['date' => ['year' => $y, 'month' => $m, 'day' => $d], 'time' => ['hours' => 23, 'minutes' => 59, 'seconds' => 59, 'nanos' => 0]],
        ],
        'windowSizeDays' => 1,
    ]);
}

function gh_reconcile(string $accessToken, string $dataType, string $filter): array {
    $path = "/users/me/dataTypes/{$dataType}/dataPoints:reconcile?" . http_build_query([
        'dataSourceFamily' => 'users/me/dataSourceFamilies/google-wearables',
        'filter' => $filter,
    ]);
    return gh_api_json($accessToken, 'GET', $path);
}

function gh_list_exercises(string $accessToken, string $date): array {
    $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $filter = sprintf('exercise.interval.civil_start_time >= "%s" AND exercise.interval.civil_start_time < "%s"', $date, $next);
    return gh_api_json($accessToken, 'GET', '/users/me/dataTypes/exercise/dataPoints?' . http_build_query(['filter' => $filter]));
}

function gh_export_tcx(string $accessToken, string $resourceName): string {
    return gh_api_raw($accessToken, '/' . ltrim($resourceName, '/') . ':exportExerciseTcx?alt=media&partialData=true');
}

function gh_num($v, array $keys = []): ?float {
    if (is_array($v)) {
        foreach ($keys as $k) if (isset($v[$k]) && is_numeric($v[$k])) return (float)$v[$k];
        foreach ($v as $x) { $n = gh_num($x, $keys); if ($n !== null) return $n; }
    } elseif (is_numeric($v)) return (float)$v;
    return null;
}

function gh_extract_steps(array $j): ?int { return isset($j['rollupDataPoints'][0]['steps']['countSum']) ? (int)$j['rollupDataPoints'][0]['steps']['countSum'] : null; }
function gh_extract_sleep(array $j): ?int { $best = null; foreach (($j['dataPoints'] ?? []) as $dp) { $m = $dp['sleep']['summary']['minutesAsleep'] ?? null; if (is_numeric($m)) $best = max($best ?? 0, (int)$m); } return $best; }
function gh_extract_hr(array $j): array { $x = $j['rollupDataPoints'][0]['heartRate'] ?? $j; return ['avg'=>gh_num($x, ['beatsPerMinuteAvg','averageBeatsPerMinute','average']),'min'=>gh_num($x, ['beatsPerMinuteMin','minimum']),'max'=>gh_num($x, ['beatsPerMinuteMax','maximum'])]; }

function gh_extract_distance_meters(array $j): ?int {
    $distance = $j['rollupDataPoints'][0]['distance'] ?? null;

    if (!is_array($distance)) {
        return null;
    }

    if (isset($distance['millimetersSum']) && is_numeric($distance['millimetersSum'])) {
        return (int) round(((float)$distance['millimetersSum']) / 1000);
    }

    if (isset($distance['metersSum']) && is_numeric($distance['metersSum'])) {
        return (int) round((float)$distance['metersSum']);
    }

    if (isset($distance['kilometersSum']) && is_numeric($distance['kilometersSum'])) {
        return (int) round(((float)$distance['kilometersSum']) * 1000);
    }

    return null;
}
function gh_resource_id(string $name): string { $p = explode('/', $name); return end($p) ?: $name; }
function gh_dt(?string $s): ?string { $t = $s ? strtotime($s) : false; return $t ? date('Y-m-d H:i:s', $t) : null; }
function gh_parse_tcx(string $tcx): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($tcx); if (!$xml) return [];
    $out = [];
    foreach (($xml->xpath('//*[local-name()="Trackpoint"]') ?: []) as $tp) {
        $lat = $tp->xpath('./*[local-name()="Position"]/*[local-name()="LatitudeDegrees"]');
        $lon = $tp->xpath('./*[local-name()="Position"]/*[local-name()="LongitudeDegrees"]');
        if (!$lat || !$lon) continue;
        $time = $tp->xpath('./*[local-name()="Time"]');
        $alt = $tp->xpath('./*[local-name()="AltitudeMeters"]');
        $out[] = ['latitude'=>(float)$lat[0], 'longitude'=>(float)$lon[0], 'altitude_meters'=>$alt ? (float)$alt[0] : null, 'recorded_at'=>isset($time[0]) ? gh_dt((string)$time[0]) : null];
    }
    return $out;
}
