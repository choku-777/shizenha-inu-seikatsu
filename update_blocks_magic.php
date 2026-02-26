<?php
/**
 * カスタムブロック登録用ワンタイムスクリプト（本番環境用）
 * 実行完了後は直ちに削除してください。
 */

require __DIR__.'/../vendor/autoload.php';

// 環境変数の読み込み等初期化
$env = new \Dotenv\Dotenv(__DIR__.'/../');
$env->load();

// DB接続情報
$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    die('DATABASE_URL is not set.');
}

// mysql://user:password@host/dbname などの形式をパース
$parsedUrl = parse_url($dbUrl);

if ($parsedUrl['scheme'] !== 'mysql') {
    die('This script only supports MySQL/MariaDB.');
}

$host = $parsedUrl['host'];
$dbname = ltrim($parsedUrl['path'], '/');
$user = $parsedUrl['user'];
$pass = isset($parsedUrl['pass']) ? $parsedUrl['pass'] : '';
$port = isset($parsedUrl['port']) ? $parsedUrl['port'] : 3306;

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "DB Connection Success.<br>";

    $pdo->beginTransaction();

    // 1. 既存のカスタムブロック関連レコードを削除（冪等性確保）
    $pdo->exec("DELETE FROM dtb_block_position WHERE block_id >= 100");
    $pdo->exec("DELETE FROM dtb_block WHERE id >= 100");
    echo "Cleared old custom blocks.<br>";

    // 2. ブロック定義の登録
    $stmtBlock = $pdo->prepare("
        INSERT INTO dtb_block (id, device_type_id, block_name, file_name, use_controller, deletable, update_date, create_date, discriminator_type)
        VALUES (?, 10, ?, ?, 0, 1, NOW(), NOW(), 'block')
    ");

    $blocks = [
        [100, 'カスタムヘッダー', 'header'],
        [101, 'メインビジュアル(ヒーロー)', 'hero'],
        [102, '看板商品セクション', 'featured'],
        [103, '商品カテゴリ一覧', 'categories'],
        [104, '馬肉の魅力', 'horsemeat'],
        [105, 'ブランドコンセプト', 'concept'],
        [106, '3つの約束', 'promises'],
        [107, 'お客様の声', 'reviews'],
        [108, 'SNSセクション', 'sns'],
        [109, 'FAQ', 'faq'],
        [110, 'お知らせ', 'news'],
        [111, 'お問い合わせCTA', 'contact_cta'],
        [112, 'カスタムフッター', 'footer']
    ];

    foreach ($blocks as $b) {
        $stmtBlock->execute($b);
    }
    echo "Registered 13 custom blocks to dtb_block.<br>";

    // 3. トップページ（layout_id = 1）のブロック配置をリセットし、新しい配置を登録
    // 旧ヘッダー等を含む全配置をクリア
    $pdo->exec("DELETE FROM dtb_block_position WHERE layout_id = 1");
    
    $stmtPos = $pdo->prepare("
        INSERT INTO dtb_block_position (layout_id, block_id, section, block_row, discriminator_type)
        VALUES (?, ?, ?, ?, 'blockposition')
    ");

    $positions = [
        // Header (section=3)
        [1, 100, 3, 1],
        // MainTop (section=6)
        [1, 101, 6, 1],
        [1, 102, 6, 2],
        [1, 103, 6, 3],
        [1, 104, 6, 4],
        [1, 105, 6, 5],
        [1, 106, 6, 6],
        [1, 107, 6, 7],
        [1, 108, 6, 8],
        [1, 109, 6, 9],
        [1, 110, 6, 10],
        [1, 111, 6, 11],
        // Footer (section=10)
        [1, 112, 10, 1]
    ];

    foreach ($positions as $p) {
        $stmtPos->execute($p);
    }
    echo "Registered block positions for layout_id=1.<br>";

    $pdo->commit();
    echo "<br><b>SUCCESS! All database migrations perfectly completed.</b><br>";
    echo "このファイルはセキュリティのため、使い終わったら直ちに削除してください。";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<b>ERROR:</b> " . $e->getMessage();
}
