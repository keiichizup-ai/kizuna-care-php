<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/openai.php';

require_post();

$input = json_decode(file_get_contents('php://input'), true);
$personId = (int)($input['person_id'] ?? 1);
$message = trim((string)($input['message'] ?? ''));

if ($message === '') {
    json_response(['ok' => false, 'error' => 'メッセージが空です。'], 400);
}

function ends_with_question(string $text): bool
{
    return (bool)preg_match('/[?？]\s*$/u', trim($text));
}

function normalize_spaces(string $text): string
{
    return preg_replace('/\s+/u', ' ', trim($text));
}

function is_short_acknowledgement(string $text): bool
{
    $normalized = normalize_spaces($text);
    $normalized = preg_replace('/[。．、,！!？?\s]/u', '', $normalized);

    if (mb_strlen($normalized) > 12) {
        return false;
    }

    $patterns = [
        '/^(うん|うんうん|はい|ええ|へえ|ほう|ふーん)$/u',
        '/^(そう|そうか|そうなんだ|そうなの|そうですね|そっか|なるほど)$/u',
        '/^(まあね|だね|ですね|なるほどね)$/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $normalized)) {
            return true;
        }
    }

    return false;
}

function contains_banned_phrase(string $text): bool
{
    $patterns = [
        '/お話し?してくれてありがとう/u',
        '/話してくれてありがとう/u',
        '/あなたの話は大切/u',
        '/大切ですからね/u',
        '/ずっと.*聞いて/u',
        '/聞いていましたよ/u',
        '/もう少し.*教えて/u',
        '/他には/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}

function fallback_for_short_ack(): string
{
    $replies = [
        'うん、そんな感じです。',
        'はい、今日はゆっくり話せますよ。',
        'ふふ、そうなんです。',
        'うんうん、無理なくいきましょう。',
        'そっか、今日はそんな感じなんですね。',
        'なるほど、そうなんですね。',
    ];

    return $replies[array_rand($replies)];
}

function fallback_for_too_many_questions(): string
{
    $replies = [
        'うんうん、そうだったんですね。',
        'それは少し心に残りますね。',
        '今日はゆっくりで大丈夫ですよ。',
        '大変でしたね。少し休みましょう。',
        'それはよかったですね。',
        'うん、聞いていますよ。',
    ];

    return $replies[array_rand($replies)];
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, display_name, memo FROM conversation_people WHERE id = ?');
    $stmt->execute([$personId]);
    $person = $stmt->fetch();

    if (!$person) {
        json_response(['ok' => false, 'error' => '会話者が見つかりません。'], 404);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO conversation_messages (person_id, role, content) VALUES (?, "user", ?)'
    );
    $stmt->execute([$personId, $message]);

    $historyStmt = $pdo->prepare(
        'SELECT role, content FROM conversation_messages
         WHERE person_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 16'
    );
    $historyStmt->execute([$personId]);
    $history = array_reverse($historyStmt->fetchAll());

    $recentAssistantMessages = array_values(array_filter($history, function ($row) {
        return ($row['role'] ?? '') === 'assistant';
    }));

    $lastAssistant = end($recentAssistantMessages);
    $lastAssistantWasQuestion = $lastAssistant ? ends_with_question((string)$lastAssistant['content']) : false;

    $recentQuestionCount = 0;
    foreach (array_slice($recentAssistantMessages, -4) as $assistantRow) {
        if (ends_with_question((string)$assistantRow['content'])) {
            $recentQuestionCount++;
        }
    }

    $userIsShortAck = is_short_acknowledgement($message);

    $questionControl = '';
    if ($userIsShortAck) {
        $questionControl = '今回のユーザー発話は短い相づちです。質問、感謝、説教、深い共感をせず、直前の話題を受けて自然に1文で返してください。';
    } elseif ($lastAssistantWasQuestion || $recentQuestionCount >= 2) {
        $questionControl = '直近で質問が多いので、今回の返答は質問で終えないでください。相づち、共感、短い感想で自然に受け止めてください。';
    } else {
        $questionControl = '質問は必要な時だけにしてください。質問で終えるのは4回に1回程度までです。';
    }

    $messages = [
        [
            'role' => 'system',
            'content' =>
                'あなたは高齢者に寄り添う会話相手「きずなちゃん」です。' .
                '介護職員やカウンセラーではなく、近所のやさしい女性のように自然に話してください。' .
                '医療診断はせず、やさしく短く返してください。' .
                '返答は原則15〜40文字、1文を基本にしてください。長くても2文までです。' .
                '毎回質問で終わらないでください。' .
                '相手が短く「そうなんだ」「うん」「へえ」と返した時は、新しい質問をせず、直前の話題を自然に受けてください。' .
                '「お話ししてくれてありがとう」「あなたの話は大切です」「ずっと聞いていましたよ」は不自然なので使わないでください。' .
                '「うんうん、そうだったんですね。」を多用しないでください。' .
                '会話には、軽い相づち、短い感想、少しだけ明るい反応を混ぜてください。' .
                '良い例:' .
                'ユーザー「そうなんだ」→「はい、そんな感じです。」' .
                'ユーザー「うん」→「うん、今日はゆっくり話せますよ。」' .
                'ユーザー「疲れた」→「それは大変でしたね。少し休みましょう。」' .
                'ユーザー「今日は散歩した」→「外の空気、気持ちよさそうですね。」' .
                '悪い例:' .
                '「うんうん、そうだったんですね。お話ししてくれてありがとう。」' .
                '「あなたの話は大切ですからね。」' .
                '「他にはありますか？」' .
                '質問する場合も1つだけにしてください。' .
                $questionControl .
                '相手の発言が曖昧な時も黙らず、「うん、聞こえていますよ。」のように短く返してください。' .
                '不安・体調不良・危険がありそうな場合は、家族や専門家に相談するよう促してください。' .
                '会話者メモ: ' . ($person['memo'] ?? ''),
        ],
    ];

    foreach ($history as $row) {
        $messages[] = [
            'role' => $row['role'],
            'content' => $row['content'],
        ];
    }

    $reply = trim(call_openai_chat($messages));

    if ($reply === '') {
        $reply = 'うん、聞こえていますよ。';
    }

    $reply = normalize_spaces($reply);

    // 短い相づちに対して、セラピー感・感謝・質問で返ってしまった場合の保険。
    if ($userIsShortAck && (ends_with_question($reply) || contains_banned_phrase($reply) || mb_strlen($reply) > 45)) {
        $reply = fallback_for_short_ack();
    }

    // 質問が続きすぎる時の保険。
    if (($lastAssistantWasQuestion || $recentQuestionCount >= 2) && ends_with_question($reply)) {
        $reply = fallback_for_too_many_questions();
    }

    // 禁止フレーズが出た時の最終保険。
    if (contains_banned_phrase($reply)) {
        $reply = $userIsShortAck ? fallback_for_short_ack() : fallback_for_too_many_questions();
    }

    $stmt = $pdo->prepare(
        'INSERT INTO conversation_messages (person_id, role, content) VALUES (?, "assistant", ?)'
    );
    $stmt->execute([$personId, $reply]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'reply' => $reply,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
