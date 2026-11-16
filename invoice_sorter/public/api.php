<?php
/**
 * PDF Invoice Sorter - API Endpoint
 */

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/settings.php';

$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

try {
    $db = getDbConnection();

    switch ($action) {
        // -------------------------------------------------------------
        // 設定の取得
        // -------------------------------------------------------------
        case 'get_settings':
            $stmt = $db->query("SELECT key, value FROM settings");
            $settings = [];
            while ($row = $stmt->fetch()) {
                $settings[$row['key']] = $row['value'];
            }
            echo json_encode(['success' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            break;

        // -------------------------------------------------------------
        // 設定の保存
        // -------------------------------------------------------------
        case 'update_settings':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POSTリクエストが必要です。');
            }
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }

            if (isset($input['naming_pattern'])) {
                setSetting('naming_pattern', trim($input['naming_pattern']));
            }
            if (isset($input['folder_pattern'])) {
                setSetting('folder_pattern', trim($input['folder_pattern']));
            }
            writeAppLog("Settings updated by user", "INFO");
            echo json_encode(['success' => true, 'message' => '設定を保存しました。'], JSON_UNESCAPED_UNICODE);
            break;

        // -------------------------------------------------------------
        // ジョブ一覧・サマリーの取得
        // -------------------------------------------------------------
        case 'list':
            $stmt = $db->query("
                SELECT id, original_filename, file_hash, status, client_name,
                       invoice_date, payment_date, year_month, sorted_filename,
                       sorted_path, folder_structure, gdrive_synced, gcp_synced,
                       error_message, ai_confidence, created_at, updated_at
                FROM jobs
                ORDER BY id DESC
            ");
            $jobs = $stmt->fetchAll();

            // サマリー集計
            $summary = [
                'total' => count($jobs),
                'queued' => 0,
                'processing' => 0,
                'completed' => 0,
                'needs_review' => 0,
                'error' => 0
            ];
            foreach ($jobs as $j) {
                $st = $j['status'];
                if (isset($summary[$st])) {
                    $summary[$st]++;
                }
            }

            echo json_encode([
                'success' => true,
                'jobs' => $jobs,
                'summary' => $summary,
                'activeWorkerRunning' => ($summary['processing'] > 0)
            ], JSON_UNESCAPED_UNICODE);
            break;

        // -------------------------------------------------------------
        // スキャンPDFアップロード（複数可）
        // -------------------------------------------------------------
        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POSTリクエストが必要です。');
            }
            if (empty($_FILES['pdfFiles'])) {
                throw new Exception('PDFファイルが送信されていません。');
            }

            $files = $_FILES['pdfFiles'];
            $fileCount = is_array($files['name']) ? count($files['name']) : 1;
            $uploadedJobs = [];

            for ($i = 0; $i < $fileCount; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];

                if ($error !== UPLOAD_ERR_OK || empty($tmpName) || !is_uploaded_file($tmpName)) {
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($ext !== 'pdf') {
                    continue; // PDFのみ
                }

                // ファイルハッシュ
                $fileHash = md5_file($tmpName);
                $uniqueName = date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.pdf';
                $destPath = INBOX_DIR . '/' . $uniqueName;

                if (!move_uploaded_file($tmpName, $destPath)) {
                    writeAppLog("Failed to save uploaded file: {$name}", "ERROR");
                    continue;
                }

                // DB登録
                $stmt = $db->prepare("
                    INSERT INTO jobs (original_filename, file_hash, inbox_path, status, created_at, updated_at)
                    VALUES (:orig, :hash, :inbox, 'queued', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    ':orig' => $name,
                    ':hash' => $fileHash,
                    ':inbox' => $destPath
                ]);
                $jobId = $db->lastInsertId();

                $uploadedJobs[] = [
                    'id' => $jobId,
                    'name' => $name
                ];
                writeAppLog("Job #{$jobId} queued: {$name} ({$size} bytes)", "INFO");
            }

            echo json_encode([
                'success' => true,
                'count' => count($uploadedJobs),
                'jobs' => $uploadedJobs,
                'message' => count($uploadedJobs) . ' 件のPDFをアップロードし、仕分けキューに登録しました。'
            ], JSON_UNESCAPED_UNICODE);
            break;

        // -------------------------------------------------------------
        // AI再仕分け要求（キューに戻す）
        // -------------------------------------------------------------
        case 'requeue':
            $jobId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$jobId) throw new Exception('無効なジョブIDです。');

            // 処理中のものは二重実行防止のため拒否
            $stmt = $db->prepare("SELECT status, original_filename FROM jobs WHERE id = :id");
            $stmt->execute([':id' => $jobId]);
            $current = $stmt->fetch();
            if (!$current) throw new Exception('ジョブが見つかりません。');
            if ($current['status'] === 'processing') {
                throw new Exception('AI処理中のため、現在再実行できません。');
            }

            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'queued', error_message = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([':id' => $jobId]);
            writeAppLog("Job #{$jobId} requeued for AI processing: " . $current['original_filename'], "INFO");

            echo json_encode(['success' => true, 'message' => 'AI再仕分けキューに登録しました。'], JSON_UNESCAPED_UNICODE);
            break;

        // -------------------------------------------------------------
        // ユーザーによる手動修正＆フォルダ再配置
        // -------------------------------------------------------------
        case 'update_job':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $jobId = isset($input['id']) ? (int)$input['id'] : 0;
            if (!$jobId) throw new Exception('無効なジョブIDです。');

            $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
            $stmt->execute([':id' => $jobId]);
            $job = $stmt->fetch();
            if (!$job) throw new Exception('ジョブが見つかりません。');
            if ($job['status'] === 'processing') {
                throw new Exception('AI処理中のため、現在編集できません。');
            }

            $clientName = trim($input['client_name'] ?? $job['client_name']);
            $invoiceDate = trim($input['invoice_date'] ?? $job['invoice_date']);
            $paymentDate = trim($input['payment_date'] ?? $job['payment_date']);

            // 年月の算出
            $yearMonth = '';
            if (preg_match('/^(\\d{4})[-\\/](\\d{1,2})/', $invoiceDate, $m)) {
                $yearMonth = sprintf('%04d-%02d', $m[1], $m[2]);
            } else {
                $yearMonth = date('Y-m');
            }

            // 命名規則とフォルダパスの適用
            $namingPattern = getSetting('naming_pattern', 'invoice_date,client_name,payment_date');
            $folderPattern = getSetting('folder_pattern', 'client_then_month');

            $newFileName = buildSortedFilename($namingPattern, $clientName, $invoiceDate, $paymentDate, 'pdf');
            $folderRel = buildFolderPath($folderPattern, $clientName, $yearMonth);
            $targetDir = SORTED_DIR . '/' . $folderRel;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $targetPath = $targetDir . $newFileName;

            // 原本から仕分け先へコピー（原本は常にinboxに保持）
            $sourceFile = (!empty($job['inbox_path']) && file_exists($job['inbox_path']))
                ? $job['inbox_path']
                : ((!empty($job['sorted_path']) && file_exists($job['sorted_path'])) ? $job['sorted_path'] : null);

            if ($sourceFile && file_exists($sourceFile)) {
                $copied = copy($sourceFile, $targetPath);
                if ($copied && !empty($job['sorted_path']) && file_exists($job['sorted_path']) && $job['sorted_path'] !== $targetPath && $job['sorted_path'] !== $job['inbox_path']) {
                    @unlink($job['sorted_path']); // 旧仕分けファイルを削除
                }
                cleanEmptyDirectories(SORTED_DIR);
            }

            $stmt = $db->prepare("
                UPDATE jobs
                SET client_name = :client,
                    invoice_date = :inv_date,
                    payment_date = :pay_date,
                    year_month = :ym,
                    sorted_filename = :sorted_fn,
                    sorted_path = :sorted_p,
                    folder_structure = :f_rel,
                    status = 'completed',
                    error_message = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ':client' => $clientName,
                ':inv_date' => $invoiceDate,
                ':pay_date' => $paymentDate,
                ':ym' => $yearMonth,
                ':sorted_fn' => $newFileName,
                ':sorted_p' => $targetPath,
                ':f_rel' => $folderRel,
                ':id' => $jobId
            ]);

            writeAppLog("Job #{$jobId} manually updated & rearranged: {$targetPath}", "INFO");
            echo json_encode([
                'success' => true,
                'message' => '仕分け内容を更新し、フォルダとファイル名を再配置しました。',
                'sorted_filename' => $newFileName,
                'folder' => $folderRel
            ], JSON_UNESCAPED_UNICODE);
            break;

        // -------------------------------------------------------------
        // ジョブ削除
        // -------------------------------------------------------------
        case 'delete_job':
            $jobId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$jobId) throw new Exception('無効なジョブIDです。');

            $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
            $stmt->execute([':id' => $jobId]);
            $job = $stmt->fetch();
            if (!$job) throw new Exception('ジョブが見つかりません。');
            if ($job['status'] === 'processing') {
                throw new Exception('AI処理中のジョブは削除できません。');
            }

            // ファイル削除
            if (!empty($job['inbox_path']) && file_exists($job['inbox_path'])) {
                @unlink($job['inbox_path']);
            }
            if (!empty($job['sorted_path']) && file_exists($job['sorted_path'])) {
                @unlink($job['sorted_path']);
            }

            $stmt = $db->prepare("DELETE FROM jobs WHERE id = :id");
            $stmt->execute([':id' => $jobId]);

            writeAppLog("Job #{$jobId} deleted by user", "INFO");
            echo json_encode(['success' => true, 'message' => 'ジョブを削除しました。'], JSON_UNESCAPED_UNICODE);
            break;

        // =============================================================
        // ワーカー連携用エンドポイント（ローカルMacワーカーから呼び出し）
        // =============================================================
        case 'worker_poll':
            // 待機中のジョブを1件取得し、ロック（processingへ）
            $stmt = $db->prepare("
                SELECT * FROM jobs
                WHERE status = 'queued'
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute();
            $job = $stmt->fetch();

            if ($job) {
                $up = $db->prepare("UPDATE jobs SET status = 'processing', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $up->execute([':id' => $job['id']]);
                writeAppLog("Worker picked up Job #{$job['id']} for AI analysis", "INFO");

                // 原本PDFのBase64またはダウンロード案内
                $pdfData = file_exists($job['inbox_path']) ? base64_encode(file_get_contents($job['inbox_path'])) : null;

                echo json_encode([
                    'success' => true,
                    'has_job' => true,
                    'job' => $job,
                    'pdf_base64' => $pdfData
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => true, 'has_job' => false], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'worker_complete':
            // ワーカーからのAI解析結果の登録
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $jobId = isset($input['id']) ? (int)$input['id'] : 0;
            if (!$jobId) throw new Exception('無効なジョブIDです。');

            $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :id");
            $stmt->execute([':id' => $jobId]);
            $job = $stmt->fetch();
            if (!$job) throw new Exception('ジョブが見つかりません。');

            $isSuccess = !empty($input['success']);
            if (!$isSuccess) {
                $err = trim($input['error_message'] ?? 'AI解析エラー');
                $stmt = $db->prepare("UPDATE jobs SET status = 'error', error_message = :err, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':err' => $err, ':id' => $jobId]);
                writeAppLog("Job #{$jobId} failed with error: {$err}", "ERROR");
                echo json_encode(['success' => true, 'message' => 'エラー状態を記録しました。'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $clientName = trim($input['client_name'] ?? '');
            $invoiceDate = trim($input['invoice_date'] ?? '');
            $paymentDate = trim($input['payment_date'] ?? '');
            $aiConfidence = isset($input['confidence']) ? (float)$input['confidence'] : 0.9;
            $aiRawOutput = trim($input['raw_output'] ?? '');

            // 年月の算出
            $yearMonth = '';
            if (preg_match('/^(\\d{4})[-\\/](\\d{1,2})/', $invoiceDate, $m)) {
                $yearMonth = sprintf('%04d-%02d', $m[1], $m[2]);
            } else {
                $yearMonth = date('Y-m');
            }

            // 命名規則とフォルダパスの決定
            $namingPattern = getSetting('naming_pattern', 'invoice_date,client_name,payment_date');
            $folderPattern = getSetting('folder_pattern', 'client_then_month');

            $newFileName = buildSortedFilename($namingPattern, $clientName, $invoiceDate, $paymentDate, 'pdf');
            $folderRel = buildFolderPath($folderPattern, $clientName, $yearMonth);
            $targetDir = SORTED_DIR . '/' . $folderRel;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $targetPath = $targetDir . $newFileName;

            // 原本から仕分け先へコピー
            if (file_exists($job['inbox_path'])) {
                $copied = copy($job['inbox_path'], $targetPath);
                if ($copied && !empty($job['sorted_path']) && file_exists($job['sorted_path']) && $job['sorted_path'] !== $targetPath) {
                    @unlink($job['sorted_path']); // 旧仕分けファイルを削除
                }
            }
            cleanEmptyDirectories(SORTED_DIR);

            // クライアント名または請求日が取れなかった場合は要確認(needs_review)
            $newStatus = (empty($clientName) || empty($invoiceDate)) ? 'needs_review' : 'completed';

            $stmt = $db->prepare("
                UPDATE jobs
                SET client_name = :client,
                    invoice_date = :inv_date,
                    payment_date = :pay_date,
                    year_month = :ym,
                    sorted_filename = :sorted_fn,
                    sorted_path = :sorted_p,
                    folder_structure = :f_rel,
                    status = :status,
                    ai_confidence = :conf,
                    ai_raw_output = :raw,
                    error_message = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ':client' => $clientName,
                ':inv_date' => $invoiceDate,
                ':pay_date' => $paymentDate,
                ':ym' => $yearMonth,
                ':sorted_fn' => $newFileName,
                ':sorted_p' => $targetPath,
                ':f_rel' => $folderRel,
                ':status' => $newStatus,
                ':conf' => $aiConfidence,
                ':raw' => $aiRawOutput,
                ':id' => $jobId
            ]);

            writeAppLog("Job #{$jobId} AI completed: {$clientName} / {$invoiceDate} -> {$targetPath} (Status: {$newStatus})", "INFO");
            echo json_encode([
                'success' => true,
                'message' => 'AI仕分け完了を記録しました。',
                'status' => $newStatus,
                'sorted_filename' => $newFileName,
                'folder' => $folderRel
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception('未対応のアクションです。');
    }

} catch (Exception $e) {
    http_response_code(400);
    writeAppLog("API Exception: " . $e->getMessage(), "ERROR");
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
