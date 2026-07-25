<?php
// api/person_profile.php
// 利用者名（姓・名）を取得/保存するAPI。
declare(strict_types=1);
require_once __DIR__ . '/../lib/person_profile.php';

header('Content-Type: application/json; charset=utf-8');

function pp_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // TODO: 既存の家族ログインチェックをここに入れてください。
    $pdo = kp_pdo();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $personId = (int)($_GET['person_id'] ?? 0);
        if ($personId <= 0) throw new RuntimeException('person_id が必要です');
        $profile = kp_get_person_profile($pdo, $personId);
        pp_json([
            'ok' => true,
            'profile' => $profile,
            'display_name' => kp_person_full_name($profile),
            'person_label' => kp_person_label($profile),
        ]);
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        $input = is_array($json) ? $json : $_POST;

        $personId = (int)($input['person_id'] ?? 0);
        if ($personId <= 0) throw new RuntimeException('person_id が必要です');

        $profile = kp_save_person_profile(
            $pdo,
            $personId,
            (string)($input['last_name'] ?? ''),
            (string)($input['first_name'] ?? ''),
            (string)($input['relation_label'] ?? ''),
            (string)($input['memo'] ?? '')
        );

        pp_json([
            'ok' => true,
            'profile' => $profile,
            'display_name' => kp_person_full_name($profile),
            'person_label' => kp_person_label($profile),
        ]);
    }

    pp_json(['ok' => false, 'error' => 'method not allowed'], 405);
} catch (Throwable $e) {
    pp_json(['ok' => false, 'error' => $e->getMessage()], 400);
}
