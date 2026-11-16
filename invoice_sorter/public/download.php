<?php
/**
 * PDF Invoice Sorter - Secure File Download & Preview Endpoint
 */

require_once __DIR__ . '/../config/settings.php';

$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? trim($_GET['type']) : 'sorted'; // 'sorted' or 'inbox'
$disposition = isset($_GET['mode']) && $_GET['mode'] === 'inline' ? 'inline' : 'attachment';

if (!$jobId) {
    http_response_code(400);
    exit('無効なジョブIDです。');
}

try {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        exit('ジョブが見つかりません。');
    }

    $filePath = '';
    $downloadName = '';

    if ($type === 'inbox') {
        $filePath = $job['inbox_path'];
        $downloadName = $job['original_filename'];
    } else {
        $filePath = !empty($job['sorted_path']) ? $job['sorted_path'] : $job['inbox_path'];
        $downloadName = !empty($job['sorted_filename']) ? $job['sorted_filename'] : $job['original_filename'];
    }

    // パス・トラバーサル対策とファイル存在チェック
    $realPath = realpath($filePath);
    $realStorage = realpath(STORAGE_DIR);

    if (!$realPath || !file_exists($realPath) || strpos($realPath, $realStorage) !== 0) {
        http_response_code(404);
        exit('ファイルが存在しないか、アクセスが拒否されました。');
    }

    // PDFの送信
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($realPath));
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($realPath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    writeAppLog("Download error for Job #{$jobId}: " . $e->getMessage(), "ERROR");
    exit('ダウンロード処理中にエラーが発生しました。');
}
