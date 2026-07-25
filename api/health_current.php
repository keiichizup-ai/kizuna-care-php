<?php
// api/health_current.php
declare(strict_types=1);
require_once __DIR__ . '/../lib/google_health_client.php';
header('Content-Type: application/json; charset=utf-8');
try {
    // TODO: 既存の家族ログインチェックを入れる
    $personId = (int)($_GET['person_id'] ?? 0); $date = $_GET['date'] ?? date('Y-m-d');
    if ($personId <= 0) throw new RuntimeException('person_id is required');
    $pdo = gh_pdo();
    $q=$pdo->prepare('SELECT * FROM health_daily_summaries WHERE person_id=:p AND summary_date=:d LIMIT 1'); $q->execute([':p'=>$personId, ':d'=>$date]); $daily=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT * FROM health_ai_summaries WHERE person_id=:p AND summary_date=:d LIMIT 1'); $q->execute([':p'=>$personId, ':d'=>$date]); $ai=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT id, exercise_type, display_name, started_at, ended_at, duration_seconds, steps, distance_meters, avg_heart_rate, has_gps FROM health_exercise_sessions WHERE person_id=:p AND DATE(started_at)=:d ORDER BY started_at DESC LIMIT 10'); $q->execute([':p'=>$personId, ':d'=>$date]); $ex=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT status,last_synced_at,last_error FROM health_connections WHERE person_id=:p AND provider='google_health' LIMIT 1"); $q->execute([':p'=>$personId]); $conn=$q->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'date'=>$date,'connection'=>$conn ?: null,'daily'=>$daily ?: null,'ai_summary'=>$ai ?: null,'exercises'=>$ex], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
