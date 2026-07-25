<?php
// jobs/sync_google_health.php
// cron例: 0 */3 * * * /usr/local/bin/php /path/to/kizuna/jobs/sync_google_health.php
// 昨日分: php jobs/sync_google_health.php yesterday
declare(strict_types=1);
require_once __DIR__ . '/../lib/google_health_client.php';

function upsert_daily(PDO $pdo, int $personId, string $date, array $daily, array $raw): void {
    $stmt = $pdo->prepare("INSERT INTO health_daily_summaries
      (person_id, summary_date, steps, sleep_minutes, resting_heart_rate, avg_heart_rate, min_heart_rate, max_heart_rate, hrv_value, spo2_avg, respiratory_rate, distance_meters, raw_json)
      VALUES (:p,:d,:steps,:sleep,:rhr,:ahr,:minhr,:maxhr,:hrv,:spo2,:rr,:dist,:raw)
      ON DUPLICATE KEY UPDATE steps=VALUES(steps), sleep_minutes=VALUES(sleep_minutes), resting_heart_rate=VALUES(resting_heart_rate), avg_heart_rate=VALUES(avg_heart_rate), min_heart_rate=VALUES(min_heart_rate), max_heart_rate=VALUES(max_heart_rate), hrv_value=VALUES(hrv_value), spo2_avg=VALUES(spo2_avg), respiratory_rate=VALUES(respiratory_rate), distance_meters=VALUES(distance_meters), raw_json=VALUES(raw_json), updated_at=NOW()");
    $stmt->execute([
        ':p'=>$personId, ':d'=>$date, ':steps'=>$daily['steps'] ?? null, ':sleep'=>$daily['sleep_minutes'] ?? null,
        ':rhr'=>$daily['resting_heart_rate'] ?? null, ':ahr'=>$daily['avg_heart_rate'] ?? null, ':minhr'=>$daily['min_heart_rate'] ?? null, ':maxhr'=>$daily['max_heart_rate'] ?? null,
        ':hrv'=>$daily['hrv_value'] ?? null, ':spo2'=>$daily['spo2_avg'] ?? null, ':rr'=>$daily['respiratory_rate'] ?? null, ':dist'=>$daily['distance_meters'] ?? null,
        ':raw'=>json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
}

function save_exercise(PDO $pdo, int $personId, array $dp, string $access): void {
    $name = $dp['name'] ?? null; $ex = $dp['exercise'] ?? null; if (!$name || !$ex) return;
    $id = gh_resource_id($name); $interval = $ex['interval'] ?? []; $m = $ex['metricsSummary'] ?? [];
    $dist = isset($m['distanceMillimeters']) ? (int)round(((float)$m['distanceMillimeters'])/1000) : null;
    $stmt = $pdo->prepare("INSERT INTO health_exercise_sessions
      (person_id, external_id, exercise_type, display_name, started_at, ended_at, duration_seconds, steps, distance_meters, avg_heart_rate, raw_json)
      VALUES (:p,:eid,:type,:name,:st,:en,:dur,:steps,:dist,:ahr,:raw)
      ON DUPLICATE KEY UPDATE exercise_type=VALUES(exercise_type), display_name=VALUES(display_name), started_at=VALUES(started_at), ended_at=VALUES(ended_at), duration_seconds=VALUES(duration_seconds), steps=VALUES(steps), distance_meters=VALUES(distance_meters), avg_heart_rate=VALUES(avg_heart_rate), raw_json=VALUES(raw_json), updated_at=NOW()");
    $stmt->execute([
        ':p'=>$personId, ':eid'=>$id, ':type'=>$ex['exerciseType'] ?? null, ':name'=>$ex['displayName'] ?? null,
        ':st'=>gh_dt($interval['startTime'] ?? null), ':en'=>gh_dt($interval['endTime'] ?? null),
        ':dur'=>isset($ex['activeDuration']) ? (int)rtrim((string)$ex['activeDuration'],'s') : null,
        ':steps'=>isset($m['steps']) ? (int)$m['steps'] : null, ':dist'=>$dist,
        ':ahr'=>isset($m['averageHeartRateBeatsPerMinute']) ? (int)$m['averageHeartRateBeatsPerMinute'] : null,
        ':raw'=>json_encode($dp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    $sid = (int)$pdo->lastInsertId();
    if ($sid === 0) { $q=$pdo->prepare('SELECT id FROM health_exercise_sessions WHERE external_id=:e'); $q->execute([':e'=>$id]); $sid=(int)$q->fetchColumn(); }
    try {
        $points = gh_parse_tcx(gh_export_tcx($access, $name));
        if ($points) {
            $pdo->prepare('DELETE FROM health_gps_route_points WHERE exercise_session_id=:id')->execute([':id'=>$sid]);
            $ins=$pdo->prepare('INSERT INTO health_gps_route_points (exercise_session_id, latitude, longitude, altitude_meters, recorded_at) VALUES (:sid,:lat,:lon,:alt,:t)');
            foreach ($points as $p) $ins->execute([':sid'=>$sid, ':lat'=>$p['latitude'], ':lon'=>$p['longitude'], ':alt'=>$p['altitude_meters'], ':t'=>$p['recorded_at']]);
            $pdo->prepare('UPDATE health_exercise_sessions SET has_gps=1 WHERE id=:id')->execute([':id'=>$sid]);
        }
    } catch (Throwable $e) {}
}

function sync_one(PDO $pdo, array $conn, string $date): void {
    $access = gh_access_token($pdo, $conn); $raw=[]; $daily=[]; $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $raw['steps'] = gh_daily_rollup($access, 'steps', $date); $daily['steps'] = gh_extract_steps($raw['steps']);
    $filter = sprintf('sleep.interval.civil_end_time >= "%s" AND sleep.interval.civil_end_time < "%s"', $date, $next);
    $raw['sleep'] = gh_reconcile($access, 'sleep', $filter); $daily['sleep_minutes'] = gh_extract_sleep($raw['sleep']);
    $raw['heart_rate'] = gh_daily_rollup($access, 'heart-rate', $date); $hr = gh_extract_hr($raw['heart_rate']);
    $daily['avg_heart_rate'] = $hr['avg'] !== null ? (int)round($hr['avg']) : null;
    $daily['min_heart_rate'] = $hr['min'] !== null ? (int)round($hr['min']) : null;
    $daily['max_heart_rate'] = $hr['max'] !== null ? (int)round($hr['max']) : null;

    foreach ([
        'daily-resting-heart-rate'=>['daily_resting_heart_rate','resting_heart_rate'],
        'daily-heart-rate-variability'=>['daily_heart_rate_variability','hrv_value'],
        'daily-oxygen-saturation'=>['daily_oxygen_saturation','spo2_avg'],
        'daily-respiratory-rate'=>['daily_respiratory_rate','respiratory_rate'],
    ] as $type=>$meta) {
        try { $j = gh_reconcile($access, $type, sprintf('%s.date >= "%s" AND %s.date < "%s"', $meta[0], $date, $meta[0], $next)); $raw[$type]=$j; $daily[$meta[1]]=gh_num($j, ['beatsPerMinute','value','average','percentage','rmssd','breathsPerMinute']); } catch (Throwable $e) { $raw[$type.'_error']=$e->getMessage(); }
    }
    try { $raw['distance'] = gh_daily_rollup($access, 'distance', $date); $daily['distance_meters'] = gh_extract_distance_meters($raw['distance']); } catch (Throwable $e) { $raw['distance_error']=$e->getMessage(); }

    upsert_daily($pdo, (int)$conn['person_id'], $date, $daily, $raw);
    try { foreach ((gh_list_exercises($access, $date)['dataPoints'] ?? []) as $dp) save_exercise($pdo, (int)$conn['person_id'], $dp, $access); } catch (Throwable $e) {}
    $pdo->prepare('UPDATE health_connections SET last_synced_at=NOW(), last_error=NULL WHERE id=:id')->execute([':id'=>$conn['id']]);
}

$pdo = gh_pdo();
$date = $argv[1] ?? date('Y-m-d'); if ($date === 'yesterday') $date = date('Y-m-d', strtotime('-1 day'));
foreach ($pdo->query("SELECT * FROM health_connections WHERE provider='google_health' AND status='active'")->fetchAll(PDO::FETCH_ASSOC) as $conn) {
    try { sync_one($pdo, $conn, $date); echo "[OK] person_id={$conn['person_id']} date={$date}\n"; }
    catch (Throwable $e) { $pdo->prepare('UPDATE health_connections SET last_error=:e WHERE id=:id')->execute([':e'=>$e->getMessage(), ':id'=>$conn['id']]); echo "[NG] person_id={$conn['person_id']} {$e->getMessage()}\n"; }
}
