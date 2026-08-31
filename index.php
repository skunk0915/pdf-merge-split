<?php
// エラー表示設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

// セッション開始
session_start();

// 定数定義
define('MAX_MERGE_FILES', 100);
define('MAX_SINGLE_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('MAX_TOTAL_SIZE', 200 * 1024 * 1024);       // 200MB
define('LOG_DIR', __DIR__ . '/logs/');
define('LOG_FILE', LOG_DIR . 'app.log');

// ログディレクトリの作成
if (!file_exists(LOG_DIR)) {
    @mkdir(LOG_DIR, 0777, true);
}

/**
 * ログ記録関数
 * 直近のログを残し、容量が10MBを超えたら古いログを切り詰める
 * 
 * @param string $message ログ本文
 * @param string $level ログレベル (INFO, WARN, ERROR)
 */
function writeLog($message, $level = 'INFO') {
    $date = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $logLine = "[{$date}] [{$level}] [{$ip}] {$message}\n";

    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > 10 * 1024 * 1024) {
        $content = @file_get_contents(LOG_FILE);
        if ($content !== false) {
            $trimmed = substr($content, -5 * 1024 * 1024);
            $firstNewline = strpos($trimmed, "\n");
            if ($firstNewline !== false) {
                $trimmed = substr($trimmed, $firstNewline + 1);
            }
            @file_put_contents(LOG_FILE, "[{$date}] [INFO] [SYSTEM] ログファイルを直近5MBにローテーションしました\n" . $trimmed);
        }
    }

    @file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * アップロードエラーコードを日本語エラーメッセージに変換
 * 
 * @param int $errorCode エラーコード
 * @param string $fileName ファイル名
 * @return string エラーメッセージ
 */
function getUploadErrorMessage($errorCode, $fileName = '') {
    $prefix = $fileName !== '' ? "ファイル「{$fileName}」: " : '';
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $prefix . 'ファイルサイズが上限（100MB）を超えています。';
        case UPLOAD_ERR_PARTIAL:
            return $prefix . 'アップロードが途中で中断されました。通信環境をご確認のうえ再度お試しください。';
        case UPLOAD_ERR_NO_FILE:
            return $prefix . 'ファイルが送信されていません。';
        case UPLOAD_ERR_NO_TMP_DIR:
            return $prefix . 'サーバーの一時保存フォルダが見つかりません。';
        case UPLOAD_ERR_CANT_WRITE:
            return $prefix . 'サーバーディスクへの書き込みに失敗しました。';
        case UPLOAD_ERR_EXTENSION:
            return $prefix . 'PHP拡張モジュールによりアップロードが停止されました。';
        default:
            return $prefix . 'ファイルのアップロードに失敗しました（エラーコード: ' . $errorCode . '）。';
    }
}

// API: PDFページ数取得用のエンドポイント (非同期リクエスト用)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_page_count') {
    header('Content-Type: application/json');
    if (!empty($_FILES['pdfFile']['tmp_name'])) {
        $file = $_FILES['pdfFile'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => getUploadErrorMessage($file['error'], $file['name'] ?? '')]);
            exit;
        }
        if ($file['size'] > MAX_SINGLE_FILE_SIZE || filesize($file['tmp_name']) > MAX_SINGLE_FILE_SIZE) {
            echo json_encode(['success' => false, 'message' => 'ファイルサイズが上限（100MB）を超えています。']);
            exit;
        }
        require_once('vendor/autoload.php');
        try {
            $tempPdf = new \setasign\Fpdi\Fpdi();
            $pageCount = $tempPdf->setSourceFile($file['tmp_name']);
            echo json_encode(['success' => true, 'pageCount' => $pageCount]);
        } catch (Exception $e) {
            writeLog("get_page_count failed for " . ($file['name'] ?? '') . ": " . $e->getMessage(), "WARN");
            echo json_encode(['success' => false, 'message' => 'PDF解析エラー: ' . $e->getMessage()]);
        }
    } else {
        $error = isset($_FILES['pdfFile']['error']) ? getUploadErrorMessage($_FILES['pdfFile']['error']) : 'ファイルがアップロードされていません。';
        echo json_encode(['success' => false, 'message' => $error]);
    }
    exit;
}

// アップロードディレクトリの設定
$uploadDir = 'uploads/';
$outputDir = 'output/';

// ディレクトリが存在しない場合は作成
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
if (!file_exists($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// 一時ファイルの削除（24時間以上経過したファイル）
cleanupFiles($uploadDir);
cleanupFiles($outputDir);

// 処理メッセージの初期化
$message = '';
$downloadLink = '';

// POST全体のサイズ上限超過検知（PHPのpost_max_sizeを超えると$_POSTや$_FILESが空になる）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $contentLengthMB = round((int)$_SERVER['CONTENT_LENGTH'] / (1024 * 1024), 2);
    $message = "エラー: 送信データの合計サイズ（{$contentLengthMB}MB）がサーバーの上限（200MB）を超えています。ファイル数を減らすか、ファイルサイズを小さくして再度お試しください。";
    writeLog("POST size exceeded limit: {$contentLengthMB}MB", "ERROR");
}

// フォーム送信時の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PDFの結合処理
    if (isset($_POST['merge'])) {
        if (!empty($_FILES['pdfFiles']['name'][0])) {
            $expectedCount = isset($_POST['expected_count']) ? (int)$_POST['expected_count'] : null;
            $result = mergePdfs($_FILES['pdfFiles'], $expectedCount);
            if ($result['success']) {
                $message = '結合が完了しました。（結合ファイル数: ' . $result['fileCount'] . '、合計ページ数: ' . $result['pageCount'] . 'ページ）';
                $downloadLink = $result['file'];
            } else {
                $message = 'エラー: ' . $result['message'];
            }
        } else {
            $message = 'エラー: 結合するPDFファイルが選択されていないか、送信データが破損しています。';
            writeLog("Merge request failed: no files found in \$_FILES", "WARN");
        }
    }
    
    // PDFの分割処理
    if (isset($_POST['split'])) {
        if (!empty($_FILES['pdfFile']['name'])) {
            $pages = isset($_POST['pages']) ? trim($_POST['pages']) : '';
            $result = splitPdf($_FILES['pdfFile'], $pages);
            if ($result['success']) {
                $message = '分割が完了しました。（分割ファイル数: ' . $result['fileCount'] . '、合計ページ数: ' . $result['pageCount'] . 'ページ）';
                $downloadLink = $result['file'];
            } else {
                $message = 'エラー: ' . $result['message'];
            }
        } else {
            $message = 'エラー: 分割するPDFファイルが選択されていないか、送信データが破損しています。';
            writeLog("Split request failed: no file found in \$_FILES", "WARN");
        }
    }
}

/**
 * 複数のPDFファイルを結合する
 * 
 * @param array $files アップロードされたファイル情報
 * @param int|null $expectedCount 送信予定ファイル数
 * @return array 処理結果
 */
function mergePdfs($files, $expectedCount = null) {
    global $uploadDir, $outputDir;
    
    $uploadedCount = isset($files['name']) ? count($files['name']) : 0;
    
    // ファイル数上限チェック
    if ($expectedCount !== null && $expectedCount > MAX_MERGE_FILES) {
        $msg = "一度に結合できるファイル数は最大" . MAX_MERGE_FILES . "個までです。（選択されたファイル数: {$expectedCount}個）";
        writeLog("Merge failed: expected count ({$expectedCount}) > " . MAX_MERGE_FILES, "WARN");
        return ['success' => false, 'message' => $msg];
    }
    
    if ($uploadedCount > MAX_MERGE_FILES) {
        $msg = "一度に結合できるファイル数は最大" . MAX_MERGE_FILES . "個までです。（受信ファイル数: {$uploadedCount}個）";
        writeLog("Merge failed: uploaded count ({$uploadedCount}) > " . MAX_MERGE_FILES, "WARN");
        return ['success' => false, 'message' => $msg];
    }
    
    // 件数不整合（切り捨て検知）
    if ($expectedCount !== null && $expectedCount !== $uploadedCount) {
        $msg = "送信ファイル数（{$expectedCount}個）とサーバー受信ファイル数（{$uploadedCount}個）が一致しません。ファイル数上限（" . MAX_MERGE_FILES . "個）を超えたか、通信エラーが発生しました。";
        writeLog("Merge failed: count mismatch (expected: {$expectedCount}, actual: {$uploadedCount})", "WARN");
        return ['success' => false, 'message' => $msg];
    }
    
    if ($uploadedCount === 0) {
        return ['success' => false, 'message' => '結合するPDFファイルが選択されていません。'];
    }
    
    // 各ファイルの検証とサイズ合計チェック
    $totalSize = 0;
    $errors = [];
    
    for ($i = 0; $i < $uploadedCount; $i++) {
        $name = $files['name'][$i] ?? "ファイル#{$i}";
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $files['tmp_name'][$i] ?? '';
        $size = $files['size'][$i] ?? 0;
        
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = getUploadErrorMessage($error, $name);
            continue;
        }
        
        // 拡張子チェック
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            $errors[] = "ファイル「{$name}」: PDFファイル以外の形式は結合できません。";
            continue;
        }
        
        // 単一ファイルサイズチェック
        if ($size > MAX_SINGLE_FILE_SIZE || filesize($tmpName) > MAX_SINGLE_FILE_SIZE) {
            $errors[] = "ファイル「{$name}」: ファイルサイズが上限（100MB）を超えています。";
            continue;
        }
        
        $totalSize += $size;
    }
    
    if (!empty($errors)) {
        writeLog("Merge failed due to validation errors: " . implode("; ", $errors), "WARN");
        return ['success' => false, 'message' => implode('<br>', $errors)];
    }
    
    // 合計サイズチェック
    if ($totalSize > MAX_TOTAL_SIZE) {
        $totalMB = round($totalSize / (1024 * 1024), 2);
        $msg = "選択されたファイルの合計サイズ（{$totalMB}MB）が上限（200MB）を超えています。ファイル数を減らすか、サイズを小さくして再度お試しください。";
        writeLog("Merge failed: total size ({$totalMB}MB) > 200MB", "WARN");
        return ['success' => false, 'message' => $msg];
    }
    
    // ファイルを一時ディレクトリに移動
    $uploadedFiles = [];
    for ($i = 0; $i < $uploadedCount; $i++) {
        $tmpName = $files['tmp_name'][$i];
        $name = $files['name'][$i];
        $uploadPath = $uploadDir . uniqid() . '_' . $name;
        if (move_uploaded_file($tmpName, $uploadPath)) {
            $uploadedFiles[] = $uploadPath;
        } else {
            foreach ($uploadedFiles as $f) { @unlink($f); }
            writeLog("Merge failed: failed to move uploaded file {$name}", "ERROR");
            return ['success' => false, 'message' => "ファイル「{$name}」の一時保存に失敗しました。"];
        }
    }
    
    // TCPDF-FPDIを使用してPDFを結合
    require_once('vendor/autoload.php');
    
    try {
        $pdf = new \setasign\Fpdi\Fpdi();
        $totalPageCount = 0;
        
        foreach ($uploadedFiles as $file) {
            $pageCount = $pdf->setSourceFile($file);
            $totalPageCount += $pageCount;
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $template = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }
        
        $firstFileName = pathinfo($files['name'][0], PATHINFO_FILENAME);
        $outputFile = $outputDir . $firstFileName . '_merged.pdf';
        $pdf->Output('F', $outputFile);
        
        foreach ($uploadedFiles as $file) {
            @unlink($file);
        }
        
        writeLog("Merge successful: {$uploadedCount} files, {$totalPageCount} pages -> {$outputFile}", "INFO");
        
        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => count($uploadedFiles),
            'pageCount' => $totalPageCount
        ];
    } catch (Exception $e) {
        foreach ($uploadedFiles as $file) {
            @unlink($file);
        }
        writeLog("Merge exception: " . $e->getMessage(), "ERROR");
        return ['success' => false, 'message' => 'PDF結合エラー: ' . $e->getMessage()];
    }
}

/**
 * PDFファイルを指定されたページで分割する
 * 
 * @param array $file アップロードされたファイル情報
 * @param string $pages 分割するページ番号（例: "1,3-5,7"）
 * @return array 処理結果
 */
function splitPdf($file, $pages) {
    global $uploadDir, $outputDir;
    
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $name = $file['name'] ?? 'PDFファイル';
        writeLog("Split upload error: code {$errorCode} for {$name}", "WARN");
        return ['success' => false, 'message' => getUploadErrorMessage($errorCode, $name)];
    }
    
    $tmpName = $file['tmp_name'];
    $name = $file['name'];
    $size = $file['size'];
    
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
        return ['success' => false, 'message' => 'PDFファイルのみアップロードできます。'];
    }
    
    if ($size > MAX_SINGLE_FILE_SIZE || filesize($tmpName) > MAX_SINGLE_FILE_SIZE) {
        writeLog("Split failed: file {$name} exceeds 100MB", "WARN");
        return ['success' => false, 'message' => 'ファイルサイズが上限（100MB）を超えています。'];
    }
    
    $uploadPath = $uploadDir . uniqid() . '_' . $name;
    if (!move_uploaded_file($tmpName, $uploadPath)) {
        writeLog("Split failed: failed to move uploaded file {$name}", "ERROR");
        return ['success' => false, 'message' => 'ファイルのアップロードに失敗しました。'];
    }
    
    require_once('vendor/autoload.php');
    
    try {
        $pageNumbers = [];
        if (!empty($pages)) {
            $parts = explode(',', $pages);
            foreach ($parts as $part) {
                $part = trim($part);
                if (strpos($part, '-') !== false) {
                    $range = explode('-', $part);
                    $start = (int)trim($range[0] ?? 0);
                    $end = (int)trim($range[1] ?? 0);
                    if ($start > 0 && $end >= $start) {
                        for ($i = $start; $i <= $end; $i++) {
                            $pageNumbers[] = $i;
                        }
                    } else {
                        @unlink($uploadPath);
                        return ['success' => false, 'message' => "ページ範囲指定が無効です: 「{$part}」"];
                    }
                } else {
                    $num = (int)$part;
                    if ($num > 0) {
                        $pageNumbers[] = $num;
                    } else {
                        @unlink($uploadPath);
                        return ['success' => false, 'message' => "ページ番号が無効です: 「{$part}」"];
                    }
                }
            }
        }
        
        $sourceFile = $uploadPath;
        $tempPdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $tempPdf->setSourceFile($sourceFile);
        
        if (empty($pageNumbers)) {
            for ($i = 1; $i <= $pageCount; $i++) {
                $pageNumbers[] = $i;
            }
        }
        
        $pageNumbers = array_values(array_unique($pageNumbers));
        
        $splitFiles = [];
        $originalFilename = pathinfo($name, PATHINFO_FILENAME);
        $singlePageMode = count($pageNumbers) > 1;
        $actualPageCount = 0;
        
        if ($singlePageMode) {
            foreach ($pageNumbers as $pageNo) {
                if ($pageNo <= $pageCount && $pageNo > 0) {
                    $pdf = new \setasign\Fpdi\Fpdi();
                    $pdf->setSourceFile($sourceFile);
                    $template = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($template);
                    
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                    
                    $outputFile = $outputDir . $originalFilename . '_page' . $pageNo . '.pdf';
                    $pdf->Output('F', $outputFile);
                    $splitFiles[] = $outputFile;
                    $actualPageCount++;
                }
            }
            
            if ($actualPageCount === 0) {
                @unlink($uploadPath);
                return ['success' => false, 'message' => "指定されたページ（" . implode(',', $pageNumbers) . "）が元のPDF（全{$pageCount}ページ）に存在しません。"];
            }
            
            $zipFile = $outputDir . $originalFilename . '_split.zip';
            $zip = new ZipArchive();
            
            if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
                foreach ($splitFiles as $file) {
                    $zip->addFile($file, basename($file));
                }
                $zip->close();
                
                foreach ($splitFiles as $file) {
                    @unlink($file);
                }
                
                $outputFile = $zipFile;
            } else {
                $outputFile = $splitFiles[0];
            }
        } else {
            $pdf = new \setasign\Fpdi\Fpdi();
            $pdf->setSourceFile($sourceFile);
            
            foreach ($pageNumbers as $pageNo) {
                if ($pageNo <= $pageCount && $pageNo > 0) {
                    $template = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($template);
                    
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                    $actualPageCount++;
                }
            }
            
            if ($actualPageCount === 0) {
                @unlink($uploadPath);
                return ['success' => false, 'message' => "指定されたページが元のPDF（全{$pageCount}ページ）に存在しません。"];
            }
            
            $outputFile = $outputDir . $originalFilename . '_extracted.pdf';
            $pdf->Output('F', $outputFile);
        }
        
        @unlink($uploadPath);
        writeLog("Split successful: {$name} -> {$actualPageCount} pages", "INFO");
        
        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => $singlePageMode ? count($splitFiles) : 1,
            'pageCount' => $actualPageCount
        ];
    } catch (Exception $e) {
        @unlink($uploadPath);
        writeLog("Split exception: " . $e->getMessage(), "ERROR");
        return ['success' => false, 'message' => 'PDF分割エラー: ' . $e->getMessage()];
    }
}

/**
 * 古いファイルを削除する（24時間以上経過したファイル）
 * 
 * @param string $dir 対象ディレクトリ
 */
function cleanupFiles($dir) {
    $files = glob($dir . '*');
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 86400) {
                @unlink($file);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDFツール - 結合・分割</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo file_exists('css/style.css') ? filemtime('css/style.css') : time(); ?>">
</head>
<body>
    <h1>PDFツール - 結合・分割</h1>
    
    <div class="container">
        <div class="tab">
            <button class="tablinks active" onclick="openTab(event, 'merge')">PDF結合</button>
            <button class="tablinks" onclick="openTab(event, 'split')">PDF分割</button>
        </div>
        
        <!-- PDF結合タブ -->
        <div id="merge" class="tabcontent" style="display: block;">
            <form action="" method="post" enctype="multipart/form-data" id="mergeForm">
                <div class="limit-badge">上限: 最大100ファイル / 合計200MB / 1ファイル100MB</div>
                <label for="pdfFileAdd">PDFファイルを選択（複数可）:</label>
                <input type="file" name="pdfFileAdd" id="pdfFileAdd" accept=".pdf" multiple onchange="addFiles(this.files)">
                <button type="button" class="add-button" onclick="document.getElementById('pdfFileAdd').click()">ファイルを追加</button>
                
                <!-- クライアントサイドのエラー通知領域 -->
                <div id="mergeClientError" class="client-error-box"></div>
                
                <div id="dropZone" class="drop-zone">
                    <div class="drop-zone-text">ここにPDFファイルをドラッグ＆ドロップ（またはファイルを追加ボタン）</div>
                    <div id="fileList" class="file-list sortable"></div>
                </div>
                <p class="note">※ ファイルはドラッグ＆ドロップで順序を変更できます。「×」をクリックするとファイルを削除できます。<br>
                   ※ 一度に結合できるファイル数は最大100個、合計サイズは最大200MBまでです。</p>
                
                <!-- リアルタイムサマリー表示領域 -->
                <div id="mergeSummary" class="summary-container" style="display: none;">
                    <div class="summary-stats">
                        <div class="summary-item">
                            <span class="summary-label">選択ファイル数:</span>
                            <span id="mergeFileCount" class="summary-value">0 / 100</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">合計ページ数:</span>
                            <span id="mergePageCount" class="summary-value">0ページ</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">合計サイズ:</span>
                            <span id="mergeTotalSize" class="summary-value">0 MB / 200MB</span>
                        </div>
                    </div>
                    <div id="mergeWarningText" class="summary-warning" style="display: none;"></div>
                </div>

                <button type="button" id="mergeSubmitBtn" onclick="submitMergeForm()" class="submit-button">PDFを結合</button>
                <div id="fileInputsContainer" style="display: none;"></div>
            </form>
        </div>
        
        <!-- PDF分割タブ -->
        <div id="split" class="tabcontent">
            <form action="" method="post" enctype="multipart/form-data" id="splitForm">
                <div class="limit-badge">上限: 1ファイル最大100MB</div>
                <label for="pdfFile">PDFファイルを選択:</label>
                <input type="file" name="pdfFile" id="pdfFile" accept=".pdf" required onchange="onSplitFileChange(this)">
                
                <!-- クライアントサイドのエラー通知領域 -->
                <div id="splitClientError" class="client-error-box"></div>
                
                <div id="singleFileList" class="file-list"></div>
                
                <!-- リアルタイムサマリー表示領域 -->
                <div id="splitSummary" class="summary-container" style="display: none;">
                    <div class="summary-stats">
                        <div class="summary-item">
                            <span class="summary-label">選択ファイル数:</span>
                            <span class="summary-value">1</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">合計ページ数:</span>
                            <span id="splitPageCount" class="summary-value">0ページ</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">ファイルサイズ:</span>
                            <span id="splitFileSize" class="summary-value">0 MB</span>
                        </div>
                    </div>
                </div>

                <label for="pages">ページ指定（オプション）:</label>
                <input type="text" name="pages" id="pages" placeholder="例: 1,3-5,7">
                <p class="note">※ 空白の場合は全ページが対象になります。<br>
                   ※ カンマ区切りでページ番号を指定できます（例: 1,3,5）<br>
                   ※ ハイフンで範囲指定もできます（例: 1-5,7-9）<br>
                   ※ 1ファイルあたり最大100MBまでアップロード可能です。</p>
                
                <button type="submit" name="split" id="splitSubmitBtn" class="submit-button">PDFを分割</button>
            </form>
        </div>
    </div>
    
    <?php if (!empty($message)): ?>
        <div class="message <?php echo strpos($message, 'エラー') !== false ? 'error' : 'success'; ?>">
            <?php echo $message; ?>
            <?php if (!empty($downloadLink)): ?>
                <br>
                <a href="<?php echo htmlspecialchars($downloadLink, ENT_QUOTES, 'UTF-8'); ?>" class="download-link" download>ダウンロード</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <script>
        // 定数定義 (JS)
        const MAX_FILES = 100;
        const MAX_SINGLE_FILE_SIZE = 100 * 1024 * 1024; // 100MB
        const MAX_TOTAL_SIZE = 200 * 1024 * 1024;       // 200MB

        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }
        
        // ページ数を非同期で取得する関数
        async function getPageCount(file) {
            const formData = new FormData();
            formData.append('action', 'get_page_count');
            formData.append('pdfFile', file);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    return data.pageCount;
                } else {
                    console.warn('Page count error:', data.message);
                }
            } catch (e) {
                console.error('Error fetching page count:', e);
            }
            return null;
        }

        // ページ数解析の並行実行キュー（サーバー過負荷・レートリミット防止）
        const pageCountQueue = [];
        let activePageCountWorkers = 0;
        const MAX_CONCURRENT_PAGE_COUNT = 4; // 同時最大4並列で処理

        function enqueuePageCount(file) {
            pageCountQueue.push(file);
            processPageCountQueue();
        }

        async function processPageCountQueue() {
            if (activePageCountWorkers >= MAX_CONCURRENT_PAGE_COUNT || pageCountQueue.length === 0) {
                return;
            }

            const file = pageCountQueue.shift();
            activePageCountWorkers++;

            try {
                const count = await getPageCount(file);
                file.pageCount = count;
            } catch (e) {
                file.pageCount = null;
            } finally {
                activePageCountWorkers--;
                updateFileList();
                updateMergeSummary();
                processPageCountQueue();
            }
        }

        // クライアントエラー表示用関数
        function showClientError(containerId, messages) {
            const container = document.getElementById(containerId);
            if (!container) return;
            if (!messages || messages.length === 0) {
                container.style.display = 'none';
                container.innerHTML = '';
                return;
            }
            let html = '<div class="error-title">⚠️ 上限エラー・入力エラー</div><ul>';
            for (const msg of messages) {
                html += '<li>' + escapeHtml(msg) + '</li>';
            }
            html += '</ul>';
            container.innerHTML = html;
            container.style.display = 'block';
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function(m) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m];
            });
        }

        // 単一ファイル（分割）選択時の処理
        async function onSplitFileChange(input) {
            const errorContainer = 'splitClientError';
            showClientError(errorContainer, []);
            
            const submitBtn = document.getElementById('splitSubmitBtn');
            const summaryDiv = document.getElementById('splitSummary');
            const pageCountVal = document.getElementById('splitPageCount');
            const fileSizeVal = document.getElementById('splitFileSize');
            
            displayFileNames('pdfFile', 'singleFileList');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // 拡張子チェック
                if (!file.name.toLowerCase().endsWith('.pdf') && file.type !== 'application/pdf') {
                    showClientError(errorContainer, ['PDFファイル以外の形式は選択できません。']);
                    submitBtn.disabled = true;
                    summaryDiv.style.display = 'none';
                    return;
                }
                
                // サイズチェック
                if (file.size > MAX_SINGLE_FILE_SIZE) {
                    showClientError(errorContainer, [
                        `ファイル「${file.name}」のサイズ（${formatFileSize(file.size)}）が上限（100MB）を超えています。100MB以下のファイルを選択してください。`
                    ]);
                    submitBtn.disabled = true;
                    summaryDiv.style.display = 'none';
                    return;
                }
                
                submitBtn.disabled = false;
                summaryDiv.style.display = 'flex';
                fileSizeVal.textContent = formatFileSize(file.size);
                pageCountVal.textContent = '解析中...';
                
                const pageCount = await getPageCount(file);
                if (pageCount !== null) {
                    pageCountVal.textContent = pageCount + 'ページ';
                    const fileItem = document.querySelector('#singleFileList .file-item');
                    if (fileItem) {
                        const pageBadge = document.createElement('span');
                        pageBadge.className = 'file-page-count';
                        pageBadge.textContent = pageCount + 'ページ';
                        fileItem.insertBefore(pageBadge, fileItem.querySelector('.file-size'));
                    }
                } else {
                    pageCountVal.textContent = '解析不可';
                }
            } else {
                summaryDiv.style.display = 'none';
                submitBtn.disabled = false;
            }
        }

        // 単一ファイル選択時にファイル名を表示する関数
        function displayFileNames(inputId, listId) {
            const input = document.getElementById(inputId);
            const fileList = document.getElementById(listId);
            fileList.innerHTML = '';
            
            if (input.files && input.files.length > 0) {
                for (let i = 0; i < input.files.length; i++) {
                    const file = input.files[i];
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    
                    const fileName = document.createElement('span');
                    fileName.className = 'file-name';
                    fileName.textContent = file.name;
                    
                    const fileSize = document.createElement('span');
                    fileSize.className = 'file-size';
                    fileSize.textContent = formatFileSize(file.size);
                    
                    fileItem.appendChild(fileName);
                    fileItem.appendChild(fileSize);
                    fileList.appendChild(fileItem);
                }
            }
        }
        
        // 結合用のファイル管理用配列
        let mergeFiles = [];
        
        // ドラッグ＆ドロップ機能の初期化
        document.addEventListener('DOMContentLoaded', function() {
            const dropZone = document.getElementById('dropZone');
            
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }, false);
            });
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, function() {
                    dropZone.classList.add('drag-over');
                }, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, function() {
                    dropZone.classList.remove('drag-over');
                }, false);
            });
            
            dropZone.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                addFiles(files);
            }, false);
        });
        
        // ファイルを追加する関数
        async function addFiles(files) {
            if (!files || files.length === 0) return;
            
            const errorMessages = [];
            let addedCount = 0;
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                // 拡張子チェック
                if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                    errorMessages.push(`ファイル「${file.name}」: PDFファイル以外の形式は結合できません。`);
                    continue;
                }
                
                // 単一ファイルサイズチェック（100MB）
                if (file.size > MAX_SINGLE_FILE_SIZE) {
                    errorMessages.push(`ファイル「${file.name}」: サイズ（${formatFileSize(file.size)}）が上限（100MB）を超えているため除外しました。`);
                    continue;
                }
                
                // 全ての有効なPDFファイルを追加（100個を超えても切り捨てず、全数を保持して超過状態を表示）
                file.pageCount = null;
                mergeFiles.push(file);
                addedCount++;
                
                // キューに追加して安全に非同期解析（最大4並列）
                enqueuePageCount(file);
            }
            
            // input[type=file]をクリアして同じファイルを再度選択可能にする
            const fileInput = document.getElementById('pdfFileAdd');
            if (fileInput) fileInput.value = '';
            
            if (mergeFiles.length > MAX_FILES) {
                const excess = mergeFiles.length - MAX_FILES;
                errorMessages.push(`選択されたファイル数（${mergeFiles.length}個）が上限（${MAX_FILES}個）を超えています。${excess}個のファイルを削除して${MAX_FILES}個以下にしてください。`);
            }
            
            showClientError('mergeClientError', errorMessages);
            updateFileList();
            updateMergeSummary();
        }
        
        // 結合の集計表示を更新する関数
        function updateMergeSummary() {
            const summaryDiv = document.getElementById('mergeSummary');
            const fileCountVal = document.getElementById('mergeFileCount');
            const pageCountVal = document.getElementById('mergePageCount');
            const totalSizeVal = document.getElementById('mergeTotalSize');
            const warningText = document.getElementById('mergeWarningText');
            const submitBtn = document.getElementById('mergeSubmitBtn');
            
            if (mergeFiles.length > 0) {
                summaryDiv.style.display = 'flex';
                
                let totalPages = 0;
                let totalBytes = 0;
                let loading = false;
                
                for (let file of mergeFiles) {
                    totalBytes += file.size;
                    if (file.pageCount === null) {
                        loading = true;
                    } else {
                        totalPages += file.pageCount;
                    }
                }
                
                pageCountVal.textContent = loading ? '解析中...' : totalPages + 'ページ';
                
                // 上限チェック
                let warnings = [];
                let isExceeded = false;
                
                if (mergeFiles.length > MAX_FILES) {
                    const excess = mergeFiles.length - MAX_FILES;
                    fileCountVal.innerHTML = `${mergeFiles.length} / ${MAX_FILES}個 <span class="excess-badge">${excess}個超過</span>`;
                    warnings.push(`⚠️ 選択されたファイル数（${mergeFiles.length}個）が上限（${MAX_FILES}個）を【${excess}個超過】しています。100個以下になるよう不要なファイルを削除してください。`);
                    isExceeded = true;
                } else {
                    fileCountVal.textContent = `${mergeFiles.length} / ${MAX_FILES}個`;
                }
                
                if (totalBytes > MAX_TOTAL_SIZE) {
                    totalSizeVal.innerHTML = `${formatFileSize(totalBytes)} / 200MB <span class="excess-badge">容量超過</span>`;
                    warnings.push(`⚠️ 合計サイズ（${formatFileSize(totalBytes)}）が上限（200MB）を超えています。ファイル数を減らすかサイズを小さくしてください。`);
                    isExceeded = true;
                } else {
                    totalSizeVal.textContent = `${formatFileSize(totalBytes)} / 200MB`;
                }
                
                if (isExceeded) {
                    summaryDiv.classList.add('limit-exceeded');
                    warningText.innerHTML = warnings.join('<br>');
                    warningText.style.display = 'block';
                    submitBtn.disabled = true;
                    submitBtn.textContent = '上限を超えているため結合できません';
                } else {
                    summaryDiv.classList.remove('limit-exceeded');
                    warningText.innerHTML = '';
                    warningText.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'PDFを結合';
                    
                    // ファイル数が上限以内になったら、ファイル数超過のエラー表示もクリア
                    const clientError = document.getElementById('mergeClientError');
                    if (clientError && clientError.textContent.includes('ファイル数')) {
                        showClientError('mergeClientError', []);
                    }
                }
            } else {
                summaryDiv.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.textContent = 'PDFを結合';
                showClientError('mergeClientError', []);
            }
        }

        // ファイルリストを更新する関数
        function updateFileList() {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            if (mergeFiles.length > 0) {
                for (let i = 0; i < mergeFiles.length; i++) {
                    const file = mergeFiles[i];
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.dataset.index = i;
                    
                    const handle = document.createElement('span');
                    handle.className = 'handle';
                    handle.innerHTML = '&#8597;';
                    fileItem.appendChild(handle);
                    
                    const fileName = document.createElement('span');
                    fileName.className = 'file-name';
                    fileName.textContent = file.name;
                    fileItem.appendChild(fileName);
                    
                    const pageBadge = document.createElement('span');
                    pageBadge.className = 'file-page-count';
                    pageBadge.textContent = file.pageCount !== null ? file.pageCount + 'ページ' : '解析中...';
                    fileItem.appendChild(pageBadge);
                    
                    const fileSize = document.createElement('span');
                    fileSize.className = 'file-size';
                    fileSize.textContent = formatFileSize(file.size);
                    fileItem.appendChild(fileSize);
                    
                    const removeBtn = document.createElement('span');
                    removeBtn.className = 'remove';
                    removeBtn.textContent = '\u00D7';
                    removeBtn.onclick = function(e) {
                        e.stopPropagation();
                        removeFile(i);
                    };
                    fileItem.appendChild(removeBtn);
                    
                    fileList.appendChild(fileItem);
                }
                
                initSortable();
            }
        }
        
        // ファイルを削除する関数
        function removeFile(index) {
            mergeFiles.splice(index, 1);
            updateFileList();
            updateMergeSummary();
        }
        
        // ソータブルを初期化する関数
        function initSortable() {
            const fileList = document.getElementById('fileList');
            let draggedItem = null;
            
            const items = fileList.querySelectorAll('.file-item');
            items.forEach(item => {
                item.draggable = true;
                
                item.addEventListener('dragstart', function() {
                    draggedItem = this;
                    setTimeout(() => this.classList.add('dragging'), 0);
                });
                
                item.addEventListener('dragend', function() {
                    draggedItem = null;
                    this.classList.remove('dragging');
                });
                
                item.addEventListener('dragover', function(e) {
                    e.preventDefault();
                });
                
                item.addEventListener('dragenter', function(e) {
                    e.preventDefault();
                    if (this !== draggedItem) {
                        this.classList.add('drag-over');
                    }
                });
                
                item.addEventListener('dragleave', function() {
                    this.classList.remove('drag-over');
                });
                
                item.addEventListener('drop', function(e) {
                    e.preventDefault();
                    if (this !== draggedItem) {
                        const draggedIndex = parseInt(draggedItem.dataset.index);
                        const targetIndex = parseInt(this.dataset.index);
                        
                        const temp = mergeFiles[draggedIndex];
                        mergeFiles[draggedIndex] = mergeFiles[targetIndex];
                        mergeFiles[targetIndex] = temp;
                        
                        updateFileList();
                        updateMergeSummary();
                    }
                    this.classList.remove('drag-over');
                });
            });
        }
        
        // フォーム送信時にファイルを追加して送信する
        function submitMergeForm() {
            const errors = [];
            
            if (mergeFiles.length === 0) {
                errors.push('結合するPDFファイルを少なくとも1つ選択してください。');
            }
            
            if (mergeFiles.length > MAX_FILES) {
                errors.push(`一度に結合できるファイル数は最大${MAX_FILES}個までです。（現在: ${mergeFiles.length}個）`);
            }
            
            let totalBytes = 0;
            for (let f of mergeFiles) {
                if (f.size > MAX_SINGLE_FILE_SIZE) {
                    errors.push(`ファイル「${f.name}」のサイズが上限（100MB）を超えています。`);
                }
                totalBytes += f.size;
            }
            
            if (totalBytes > MAX_TOTAL_SIZE) {
                errors.push(`選択されたファイルの合計サイズ（${formatFileSize(totalBytes)}）が上限（200MB）を超えています。ファイル数を減らしてください。`);
            }
            
            if (errors.length > 0) {
                showClientError('mergeClientError', errors);
                return;
            }
            
            showClientError('mergeClientError', []);
            
            const form = document.getElementById('mergeForm');
            const container = document.getElementById('fileInputsContainer');
            container.innerHTML = '';
            
            // ファイルをFormDataに追加
            for (let i = 0; i < mergeFiles.length; i++) {
                const input = document.createElement('input');
                input.type = 'file';
                input.name = 'pdfFiles[]';
                input.style.display = 'none';
                
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(mergeFiles[i]);
                input.files = dataTransfer.files;
                
                container.appendChild(input);
            }
            
            // 結合ボタンフラグと送信予定ファイル数を追加
            const mergeButton = document.createElement('input');
            mergeButton.type = 'hidden';
            mergeButton.name = 'merge';
            mergeButton.value = '1';
            container.appendChild(mergeButton);
            
            const countInput = document.createElement('input');
            countInput.type = 'hidden';
            countInput.name = 'expected_count';
            countInput.value = mergeFiles.length;
            container.appendChild(countInput);
            
            // 送信ボタンの二重送信防止
            const submitBtn = document.getElementById('mergeSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = '結合処理中...';
            
            form.submit();
        }
        
        // ファイルサイズを読みやすい形式に変換する関数
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>
