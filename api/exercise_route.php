<?php
// api/exercise_route.php
declare(strict_types=1);
require_once __DIR__ . '/../lib/google_health_client.php';
header('Content-Type: application/json; charset=utf-8');
try {
    // TODO: 既存の家族ログインチェックを入れる
    $sessionId = (int)($_GET['session_id'] ?? 0); if ($sessionId <= 0) throw new RuntimeException('session_id is required');
    $pdo=gh_pdo();
    $q=$pdo->prepare('SELECT latitude, longitude, altitude_meters, recorded_at FROM health_gps_route_points WHERE exercise_session_id=:id ORDER BY recorded_at ASC, id ASC');
    $q->execute([':id'=>$sessionId]);
    echo json_encode(['ok'=>true,'points'=>$q->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
