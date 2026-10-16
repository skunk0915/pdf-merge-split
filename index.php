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
define('FONT_DIR', __DIR__ . '/fonts/');
if (!defined('_SYSTEM_TTFONTS')) {
    define('_SYSTEM_TTFONTS', FONT_DIR);
}
define('ALLOWED_MERGE_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt']);

// ログディレクトリの作成
if (!file_exists(LOG_DIR)) {
    @mkdir(LOG_DIR, 0777, true);
}
if (!file_exists(FONT_DIR)) {
    @mkdir(FONT_DIR, 0777, true);
}

/**
 * 画像ファイルをPDFに変換する
 * 
 * @param string $imagePath 元画像パス
 * @param string $outputPath 出力先PDFパス
 * @return bool
 */
function convertImageToPdf($imagePath, $outputPath) {
    $info = @getimagesize($imagePath);
    if (!$info) {
        throw new Exception("画像ファイルとして認識できません。");
    }
    
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    
    $tempPng = null;
    $actualFile = $imagePath;
    
    // WebP や GIF は GD で一時的に PNG に変換
    if ($mime === 'image/webp' || $mime === 'image/gif') {
        $tempPng = $outputPath . '_temp.png';
        if ($mime === 'image/webp') {
            if (!function_exists('imagecreatefromwebp')) {
                throw new Exception("WebP形式の変換に対応していません。");
            }
            $img = @imagecreatefromwebp($imagePath);
        } else {
            $img = @imagecreatefromgif($imagePath);
        }
        if (!$img) {
            throw new Exception("画像のデコードに失敗しました。");
        }
        imagepng($img, $tempPng);
        $actualFile = $tempPng;
    }
    
    require_once('vendor/autoload.php');
    $pdf = new \setasign\Fpdi\Fpdi();
    $orientation = ($width > $height) ? 'L' : 'P';
    
    // A4 用紙サイズ (mm)
    $pageW = ($orientation === 'L') ? 297 : 210;
    $pageH = ($orientation === 'L') ? 210 : 297;
    $margin = 10;
    $maxW = $pageW - ($margin * 2);
    $maxH = $pageH - ($margin * 2);
    
    $scale = min($maxW / $width, $maxH / $height);
    $w = $width * $scale;
    $h = $height * $scale;
    $x = ($pageW - $w) / 2;
    $y = ($pageH - $h) / 2;
    
    $pdf->AddPage($orientation, 'A4');
    $pdf->Image($actualFile, $x, $y, $w, $h);
    $pdf->Output('F', $outputPath);
    
    if ($tempPng && file_exists($tempPng)) {
        @unlink($tempPng);
    }
    return true;
}

/**
 * テキストファイルをPDFに変換する
 * 
 * @param string $textPath 元テキストファイルパス
 * @param string $outputPath 出力先PDFパス
 * @return int ページ数
 */
function convertTextToPdf($textPath, $outputPath) {
    $content = @file_get_contents($textPath);
    if ($content === false) {
        throw new Exception("テキストファイルの読み込みに失敗しました。");
    }
    
    // 文字コードをUTF-8に正規化
    $encoding = mb_detect_encoding($content, ['UTF-8', 'SJIS-win', 'eucJP-win', 'SJIS', 'EUC-JP', 'ASCII'], true);
    if ($encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding ?: 'auto');
    }
    
    // 改行コード正規化
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    
    require_once('vendor/autoload.php');
    
    $pdf = new \tFPDF();
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage('P', 'A4');
    
    $fontFile = 'ipag.ttf';
    if (!file_exists(FONT_DIR . $fontFile) && file_exists('/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf')) {
        @copy('/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf', FONT_DIR . $fontFile);
    }
    
    // フォントキャッシュが別環境の古いパスを指している場合は自動削除
    $unifontDir = __DIR__ . '/vendor/setasign/tfpdf/font/unifont/';
    $mtxFile = $unifontDir . 'ipag.mtx.php';
    if (file_exists($mtxFile)) {
        $mtxContent = @file_get_contents($mtxFile);
        if ($mtxContent && preg_match('/\$ttffile=\'(.*?)\';/', $mtxContent, $matches)) {
            if (!file_exists($matches[1])) {
                @unlink($mtxFile);
                @unlink($unifontDir . 'ipag.cw.dat');
                @unlink($unifontDir . 'ipag.cw127.php');
            }
        }
    }
    
    $pdf->AddFont('IPAGothic', '', $fontFile, true);
    $pdf->SetFont('IPAGothic', '', 10.5);
    
    // MultiCellで自動改行描画
    $pdf->MultiCell(0, 5.5, $content);
    
    $pdf->Output('F', $outputPath);
    
    return $pdf->PageNo();
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

// API: ページ数取得用のエンドポイント (非同期リクエスト用)
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

        $fileName = $file['name'] ?? '';
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // 画像ファイルは1ページとして即時返却
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            echo json_encode(['success' => true, 'pageCount' => 1]);
            exit;
        }

        // テキストファイルはページ数を変換して取得
        if ($ext === 'txt') {
            try {
                $tempPdfPath = sys_get_temp_dir() . '/' . uniqid('txt_cnt_') . '.pdf';
                $pageCount = convertTextToPdf($file['tmp_name'], $tempPdfPath);
                @unlink($tempPdfPath);
                echo json_encode(['success' => true, 'pageCount' => $pageCount]);
            } catch (Exception $e) {
                writeLog("get_page_count (text) failed for {$fileName}: " . $e->getMessage(), "WARN");
                echo json_encode(['success' => false, 'message' => 'テキスト解析エラー: ' . $e->getMessage()]);
            }
            exit;
        }

        // PDFファイル
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
    // PDFの結合処理（ページ単位回転対応）
    if (isset($_POST['merge'])) {
        if (!empty($_FILES['pdfFiles']['name'][0])) {
            $expectedCount = isset($_POST['expected_count']) ? (int)$_POST['expected_count'] : null;
            $fileRotations = isset($_POST['file_rotations']) ? $_POST['file_rotations'] : null;
            $result = mergePdfs($_FILES['pdfFiles'], $expectedCount, $fileRotations);
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
    
    // PDFの分割処理（ページ単位回転対応）
    if (isset($_POST['split'])) {
        if (!empty($_FILES['pdfFile']['name'])) {
            $pages = isset($_POST['pages']) ? trim($_POST['pages']) : '';
            $splitRotations = isset($_POST['split_rotations']) ? $_POST['split_rotations'] : null;
            $result = splitPdf($_FILES['pdfFile'], $pages, $splitRotations);
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

    // PDF・画像の回転処理
    if (isset($_POST['rotate'])) {
        if (!empty($_FILES['pdfFileRotate']['name'])) {
            $rotations = isset($_POST['rotations']) ? $_POST['rotations'] : '';
            $result = rotatePdf($_FILES['pdfFileRotate'], $rotations);
            if ($result['success']) {
                $message = '回転が完了しました。（対象ファイル: 1、合計ページ数: ' . $result['pageCount'] . 'ページ、回転ページ数: ' . $result['rotatedPageCount'] . 'ページ）';
                $downloadLink = $result['file'];
            } else {
                $message = 'エラー: ' . $result['message'];
            }
        } else {
            $message = 'エラー: 回転するファイルが選択されていないか、送信データが破損しています。';
            writeLog("Rotate request failed: no file found in \$_FILES", "WARN");
        }
    }
}

/**
 * 複数のPDFファイルを結合する（ページ単位回転対応）
 * 
 * @param array $files アップロードされたファイル情報
 * @param int|null $expectedCount 送信予定ファイル数
 * @param string|array|null $fileRotationsJson ファイルごとのページ回転情報
 * @return array 処理結果
 */
function mergePdfs($files, $expectedCount = null, $fileRotationsJson = null) {
    global $uploadDir, $outputDir;
    
    $uploadedCount = isset($files['name']) ? count($files['name']) : 0;
    
    // 各ファイルのページごとの回転情報（JSON）をパース
    $fileRotations = [];
    if (!empty($fileRotationsJson)) {
        if (is_array($fileRotationsJson)) {
            $fileRotations = $fileRotationsJson;
        } else {
            $decoded = json_decode($fileRotationsJson, true);
            if (is_array($decoded)) {
                $fileRotations = $decoded;
            }
        }
    }
    
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
        
        // 拡張子チェック（PDF, JPG, PNG, GIF, WebP, TXT）
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_MERGE_EXTENSIONS, true)) {
            $errors[] = "ファイル「{$name}」: 対応していないファイル形式です（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）。";
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
            $uploadedFiles[] = [
                'path' => $uploadPath,
                'name' => $name,
                'ext' => strtolower(pathinfo($name, PATHINFO_EXTENSION))
            ];
        } else {
            foreach ($uploadedFiles as $f) { @unlink($f['path']); }
            writeLog("Merge failed: failed to move uploaded file {$name}", "ERROR");
            return ['success' => false, 'message' => "ファイル「{$name}」の一時保存に失敗しました。"];
        }
    }
    
    require_once('vendor/autoload.php');
    
    $tempConvertedPdfs = [];
    try {
        $pdf = new \setasign\Fpdi\Fpdi();
        $totalPageCount = 0;
        
        foreach ($uploadedFiles as $index => $item) {
            $filePath = $item['path'];
            $ext = $item['ext'];
            $sourcePdf = $filePath;
            
            // 画像形式の場合: PDFに変換
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $tempPdfPath = $uploadDir . uniqid('img_') . '.pdf';
                convertImageToPdf($filePath, $tempPdfPath);
                $tempConvertedPdfs[] = $tempPdfPath;
                $sourcePdf = $tempPdfPath;
            }
            // テキスト形式の場合: PDFに変換
            elseif ($ext === 'txt') {
                $tempPdfPath = $uploadDir . uniqid('txt_') . '.pdf';
                convertTextToPdf($filePath, $tempPdfPath);
                $tempConvertedPdfs[] = $tempPdfPath;
                $sourcePdf = $tempPdfPath;
            }
            
            $pageCount = $pdf->setSourceFile($sourcePdf);
            $totalPageCount += $pageCount;
            
            $itemRotations = $fileRotations[$index] ?? [];
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $template = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($template);
                
                $angle = 0;
                if (isset($itemRotations[$pageNo])) {
                    $angle = (int)$itemRotations[$pageNo];
                } elseif (isset($itemRotations[(string)$pageNo])) {
                    $angle = (int)$itemRotations[(string)$pageNo];
                }
                $angle = (($angle % 360) + 360) % 360;
                $angle = (int)(round($angle / 90) * 90) % 360;
                
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']], $angle);
                $pdf->useTemplate($template);
            }
        }
        
        $firstFileName = pathinfo($files['name'][0], PATHINFO_FILENAME);
        $suffix = (count($uploadedFiles) === 1 && $uploadedFiles[0]['ext'] !== 'pdf') ? '_converted.pdf' : '_merged.pdf';
        $outputFile = $outputDir . $firstFileName . $suffix;
        $pdf->Output('F', $outputFile);
        
        // クリーンアップ
        foreach ($uploadedFiles as $item) {
            @unlink($item['path']);
        }
        foreach ($tempConvertedPdfs as $tempPdf) {
            @unlink($tempPdf);
        }
        
        writeLog("Merge/Convert successful: {$uploadedCount} files, {$totalPageCount} pages -> {$outputFile}", "INFO");
        
        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => count($uploadedFiles),
            'pageCount' => $totalPageCount
        ];
    } catch (Exception $e) {
        foreach ($uploadedFiles as $item) {
            @unlink($item['path']);
        }
        foreach ($tempConvertedPdfs as $tempPdf) {
            @unlink($tempPdf);
        }
        writeLog("Merge exception: " . $e->getMessage(), "ERROR");
        return ['success' => false, 'message' => 'PDF処理エラー: ' . $e->getMessage()];
    }
}

/**
 * PDFファイルを指定されたページで分割する（ページ単位回転・画像/テキスト対応）
 * 
 * @param array $file アップロードされたファイル情報
 * @param string $pages 分割するページ番号（例: "1,3-5,7"）
 * @param string|array|null $rotationsJson ページごとの回転角度情報
 * @return array 処理結果
 */
function splitPdf($file, $pages, $rotationsJson = null) {
    global $uploadDir, $outputDir;
    
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $name = $file['name'] ?? 'ファイル';
        writeLog("Split upload error: code {$errorCode} for {$name}", "WARN");
        return ['success' => false, 'message' => getUploadErrorMessage($errorCode, $name)];
    }
    
    $tmpName = $file['tmp_name'];
    $name = $file['name'];
    $size = $file['size'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    
    if (!in_array($ext, ALLOWED_MERGE_EXTENSIONS, true)) {
        return ['success' => false, 'message' => '対応していないファイル形式です（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）。'];
    }
    
    if ($size > MAX_SINGLE_FILE_SIZE || filesize($tmpName) > MAX_SINGLE_FILE_SIZE) {
        writeLog("Split failed: file {$name} exceeds 100MB", "WARN");
        return ['success' => false, 'message' => 'ファイルサイズが上限（100MB）を超えています。'];
    }
    
    // 回転情報のパース
    $rotations = [];
    if (!empty($rotationsJson)) {
        if (is_array($rotationsJson)) {
            $rotations = $rotationsJson;
        } else {
            $decoded = json_decode($rotationsJson, true);
            if (is_array($decoded)) {
                $rotations = $decoded;
            }
        }
    }
    
    $uploadPath = $uploadDir . uniqid() . '_' . $name;
    if (!move_uploaded_file($tmpName, $uploadPath)) {
        writeLog("Split failed: failed to move uploaded file {$name}", "ERROR");
        return ['success' => false, 'message' => 'ファイルのアップロードに失敗しました。'];
    }
    
    require_once('vendor/autoload.php');
    
    $tempConvertedPdf = null;
    try {
        $sourceFile = $uploadPath;
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $tempConvertedPdf = $uploadDir . uniqid('img_split_') . '.pdf';
            convertImageToPdf($uploadPath, $tempConvertedPdf);
            $sourceFile = $tempConvertedPdf;
        } elseif ($ext === 'txt') {
            $tempConvertedPdf = $uploadDir . uniqid('txt_split_') . '.pdf';
            convertTextToPdf($uploadPath, $tempConvertedPdf);
            $sourceFile = $tempConvertedPdf;
        }
        
        $tempPdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $tempPdf->setSourceFile($sourceFile);
        
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
                        if ($tempConvertedPdf) { @unlink($tempConvertedPdf); }
                        return ['success' => false, 'message' => "ページ範囲指定が無効です: 「{$part}」"];
                    }
                } else {
                    $num = (int)$part;
                    if ($num > 0) {
                        $pageNumbers[] = $num;
                    } else {
                        @unlink($uploadPath);
                        if ($tempConvertedPdf) { @unlink($tempConvertedPdf); }
                        return ['success' => false, 'message' => "ページ番号が無効です: 「{$part}」"];
                    }
                }
            }
        }
        
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
                    
                    $angle = 0;
                    if (isset($rotations[$pageNo])) {
                        $angle = (int)$rotations[$pageNo];
                    } elseif (isset($rotations[(string)$pageNo])) {
                        $angle = (int)$rotations[(string)$pageNo];
                    }
                    $angle = (($angle % 360) + 360) % 360;
                    $angle = (int)(round($angle / 90) * 90) % 360;
                    
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']], $angle);
                    $pdf->useTemplate($template);
                    
                    $outputFile = $outputDir . $originalFilename . '_page' . $pageNo . '.pdf';
                    $pdf->Output('F', $outputFile);
                    $splitFiles[] = $outputFile;
                    $actualPageCount++;
                }
            }
            
            if ($actualPageCount === 0) {
                @unlink($uploadPath);
                if ($tempConvertedPdf) { @unlink($tempConvertedPdf); }
                return ['success' => false, 'message' => "指定されたページ（" . implode(',', $pageNumbers) . "）が元のファイル（全{$pageCount}ページ）に存在しません。"];
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
                    
                    $angle = 0;
                    if (isset($rotations[$pageNo])) {
                        $angle = (int)$rotations[$pageNo];
                    } elseif (isset($rotations[(string)$pageNo])) {
                        $angle = (int)$rotations[(string)$pageNo];
                    }
                    $angle = (($angle % 360) + 360) % 360;
                    $angle = (int)(round($angle / 90) * 90) % 360;
                    
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']], $angle);
                    $pdf->useTemplate($template);
                    $actualPageCount++;
                }
            }
            
            if ($actualPageCount === 0) {
                @unlink($uploadPath);
                if ($tempConvertedPdf) { @unlink($tempConvertedPdf); }
                return ['success' => false, 'message' => "指定されたページが元のファイル（全{$pageCount}ページ）に存在しません。"];
            }
            
            $outputFile = $outputDir . $originalFilename . '_extracted.pdf';
            $pdf->Output('F', $outputFile);
        }
        
        @unlink($uploadPath);
        if ($tempConvertedPdf) {
            @unlink($tempConvertedPdf);
        }
        writeLog("Split successful: {$name} -> {$actualPageCount} pages", "INFO");
        
        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => $singlePageMode ? count($splitFiles) : 1,
            'pageCount' => $actualPageCount
        ];
    } catch (Exception $e) {
        @unlink($uploadPath);
        if ($tempConvertedPdf) {
            @unlink($tempConvertedPdf);
        }
        writeLog("Split exception: " . $e->getMessage(), "ERROR");
        return ['success' => false, 'message' => '分割処理エラー: ' . $e->getMessage()];
    }
}

/**
 * PDF・画像・テキストファイルの各ページを90度単位で回転する
 * 
 * @param array $file アップロードされたファイル情報
 * @param string|array $rotationsJson ページごとの回転角度情報 (JSON文字列)
 * @return array 処理結果
 */
function rotatePdf($file, $rotationsJson) {
    global $uploadDir, $outputDir;

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $name = $file['name'] ?? 'ファイル';
        writeLog("Rotate upload error: code {$errorCode} for {$name}", "WARN");
        return ['success' => false, 'message' => getUploadErrorMessage($errorCode, $name)];
    }

    $tmpName = $file['tmp_name'];
    $name = $file['name'];
    $size = $file['size'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_MERGE_EXTENSIONS, true)) {
        return ['success' => false, 'message' => '対応していないファイル形式です（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）。'];
    }

    if ($size > MAX_SINGLE_FILE_SIZE || filesize($tmpName) > MAX_SINGLE_FILE_SIZE) {
        writeLog("Rotate failed: file {$name} exceeds 100MB", "WARN");
        return ['success' => false, 'message' => 'ファイルサイズが上限（100MB）を超えています。'];
    }

    // 回転情報のパース（各ページ番号 => 回転角度）
    $rotations = [];
    if (!empty($rotationsJson)) {
        if (is_array($rotationsJson)) {
            $rotations = $rotationsJson;
        } else {
            $decoded = json_decode($rotationsJson, true);
            if (is_array($decoded)) {
                $rotations = $decoded;
            }
        }
    }

    $uploadPath = $uploadDir . uniqid() . '_' . $name;
    if (!move_uploaded_file($tmpName, $uploadPath)) {
        writeLog("Rotate failed: failed to move uploaded file {$name}", "ERROR");
        return ['success' => false, 'message' => 'ファイルのアップロードに失敗しました。'];
    }

    require_once('vendor/autoload.php');

    $tempConvertedPdf = null;
    try {
        $sourceFile = $uploadPath;
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $tempConvertedPdf = $uploadDir . uniqid('img_rot_') . '.pdf';
            convertImageToPdf($uploadPath, $tempConvertedPdf);
            $sourceFile = $tempConvertedPdf;
        } elseif ($ext === 'txt') {
            $tempConvertedPdf = $uploadDir . uniqid('txt_rot_') . '.pdf';
            convertTextToPdf($uploadPath, $tempConvertedPdf);
            $sourceFile = $tempConvertedPdf;
        }

        $pdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $pdf->setSourceFile($sourceFile);

        if ($pageCount === 0) {
            @unlink($uploadPath);
            if ($tempConvertedPdf) { @unlink($tempConvertedPdf); }
            return ['success' => false, 'message' => 'ページの解析に失敗しました。'];
        }

        $rotatedPageCount = 0;
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $template = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($template);

            // 指定された角度（デフォルト0度）
            $angle = 0;
            if (isset($rotations[$pageNo])) {
                $angle = (int)$rotations[$pageNo];
            } elseif (isset($rotations[(string)$pageNo])) {
                $angle = (int)$rotations[(string)$pageNo];
            }

            // 0, 90, 180, 270 に正規化
            $angle = (($angle % 360) + 360) % 360;
            $angle = (int)(round($angle / 90) * 90) % 360;

            if ($angle !== 0) {
                $rotatedPageCount++;
            }

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']], $angle);
            $pdf->useTemplate($template);
        }

        $originalFilename = pathinfo($name, PATHINFO_FILENAME);
        $outputFile = $outputDir . $originalFilename . '_rotated.pdf';
        $pdf->Output('F', $outputFile);

        @unlink($uploadPath);
        if ($tempConvertedPdf) {
            @unlink($tempConvertedPdf);
        }
        writeLog("Rotate successful: {$name} ({$pageCount} pages, {$rotatedPageCount} rotated) -> {$outputFile}", "INFO");

        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => 1,
            'pageCount' => $pageCount,
            'rotatedPageCount' => $rotatedPageCount
        ];
    } catch (Exception $e) {
        @unlink($uploadPath);
        if ($tempConvertedPdf) {
            @unlink($tempConvertedPdf);
        }
        writeLog("Rotate exception: " . $e->getMessage(), "ERROR");
        return ['success' => false, 'message' => '回転処理エラー: ' . $e->getMessage()];
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
    <title>PDFツール - 結合・分割・回転</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo file_exists('css/style.css') ? filemtime('css/style.css') : time(); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
</head>
<body>
    <h1>PDFツール - 結合・分割・回転</h1>
    
    <div class="container">
        <div class="tab">
            <button class="tablinks active" onclick="openTab(event, 'merge')">PDF結合・変換</button>
            <button class="tablinks" onclick="openTab(event, 'split')">PDF分割・抽出</button>
            <button class="tablinks" onclick="openTab(event, 'rotate')">PDF回転</button>
        </div>
        
        <!-- PDF結合タブ -->
        <div id="merge" class="tabcontent" style="display: block;">
            <form action="" method="post" enctype="multipart/form-data" id="mergeForm">
                <div class="limit-badge">上限: 最大100ファイル / 合計200MB / 1ファイル100MB（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）</div>
                <label for="pdfFileAdd">ファイルを選択（PDF・画像・テキスト / 複数可）:</label>
                <input type="file" name="pdfFileAdd" id="pdfFileAdd" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.txt" multiple onchange="addFiles(this.files)">
                <button type="button" class="add-button" onclick="document.getElementById('pdfFileAdd').click()">ファイルを追加</button>
                
                <!-- クライアントサイドのエラー通知領域 -->
                <div id="mergeClientError" class="client-error-box"></div>
                
                <div id="dropZone" class="drop-zone">
                    <div class="drop-zone-text">ここにPDF・画像・テキストファイルをドラッグ＆ドロップ（またはファイルを追加ボタン）</div>
                    <div id="fileList" class="file-list sortable"></div>
                </div>
                <p class="note">※ ファイルはドラッグ＆ドロップで順序を変更できます。「×」をクリックするとファイルを削除できます。<br>
                   ※ 各ファイル右側の「🔄 回転・プレビュー」でページを展開し、ページ単位で90度回転を設定できます。<br>
                   ※ 画像（JPG, PNG, GIF, WebP）やテキスト（TXT）も自動でPDFページに変換され、PDFとの混在結合や単体変換・回転が可能です。<br>
                   ※ 一度に処理できるファイル数は最大100個、合計サイズは最大200MBまでです。</p>
                
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

                <input type="hidden" name="file_rotations" id="fileRotationsInput" value="[]">
                <button type="button" id="mergeSubmitBtn" onclick="submitMergeForm()" class="submit-button">PDFを結合</button>
                <div id="fileInputsContainer" style="display: none;"></div>
            </form>
        </div>
        
        <!-- PDF分割タブ -->
        <div id="split" class="tabcontent">
            <form action="" method="post" enctype="multipart/form-data" id="splitForm">
                <div class="limit-badge">上限: 1ファイル最大100MB（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）</div>
                <label for="pdfFile">ファイルを選択（PDF・画像・テキスト）:</label>
                <input type="file" name="pdfFile" id="pdfFile" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.txt" required onchange="onSplitFileChange(this)">
                
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
                            <span class="summary-label">抽出ページ数:</span>
                            <span id="splitSelectedCount" class="summary-value">0ページ</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">ファイルサイズ:</span>
                            <span id="splitFileSize" class="summary-value">0 MB</span>
                        </div>
                    </div>
                </div>

                <!-- 分割プレビュー＆回転セクション -->
                <div id="splitPreviewSection" class="split-preview-section" style="display: none;">
                    <div class="split-toolbar">
                        <span class="split-toolbar-title" id="splitToolbarTitle">ページ選択 ＆ 回転（サムネイルをクリックして90度回転できます）</span>
                        <div class="split-toolbar-group">
                            <button type="button" class="tool-btn-sm" onclick="selectAllSplitPages(true)">すべて選択</button>
                            <button type="button" class="tool-btn-sm" onclick="selectAllSplitPages(false)">選択解除</button>
                        </div>
                        <div class="split-toolbar-group" id="splitRotateToolbarGroup">
                            <button type="button" class="tool-btn-sm" onclick="rotateAllSplitPages(90)">一括 右90° ↻</button>
                            <button type="button" class="tool-btn-sm" onclick="rotateAllSplitPages(-90)">一括 左90° ↺</button>
                            <button type="button" class="tool-btn-sm reset" onclick="resetAllSplitRotations()">回転リセット</button>
                        </div>
                    </div>
                    <div id="splitPagesGrid" class="split-pages-grid"></div>
                </div>

                <label for="pages">ページ指定（オプション）:</label>
                <input type="text" name="pages" id="pages" placeholder="例: 1,3-5,7" oninput="onSplitPagesTextInput(this.value)">
                <p class="note">※ 上のプレビュー一覧でチェックボックスをクリックして選択するか、直接ページ番号を指定できます。<br>
                   ※ 空白の場合は全ページが対象になります。<br>
                   ※ カンマ区切りでページ番号を指定できます（例: 1,3,5）<br>
                   ※ ハイフンで範囲指定もできます（例: 1-5,7-9）<br>
                   ※ 1ファイルあたり最大100MBまでアップロード可能です。</p>
                
                <input type="hidden" name="split_rotations" id="splitRotationsInput" value="{}">
                <button type="submit" name="split" id="splitSubmitBtn" class="submit-button">PDFを分割</button>
            </form>
        </div>
        
        <!-- PDF回転タブ -->
        <div id="rotate" class="tabcontent">
            <form action="" method="post" enctype="multipart/form-data" id="rotateForm">
                <div class="limit-badge">上限: 1ファイル最大100MB（対応形式: PDF, JPG, PNG, GIF, WebP）</div>
                <label for="pdfFileRotateInput">ファイルを選択（PDF・画像）:</label>
                <input type="file" id="pdfFileRotateInput" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" onchange="onRotateFileChange(this)">
                <button type="button" class="add-button" onclick="document.getElementById('pdfFileRotateInput').click()">ファイルを選択</button>
                
                <!-- クライアントサイドのエラー通知領域 -->
                <div id="rotateClientError" class="client-error-box"></div>
                
                <div id="rotateDropZone" class="drop-zone">
                    <div id="rotateDropZoneText" class="drop-zone-text">ここにPDFまたは画像ファイルをドラッグ＆ドロップ（またはファイルを選択ボタン）</div>
                    <div id="rotateSingleFileList" class="file-list"></div>
                </div>
                <p class="note">※ 「PDF結合・変換」画面や「PDF分割・抽出」画面でも、ファイルをアップロードしたまま各ページを直接回転できます。</p>

                <!-- リアルタイムサマリー表示領域 -->
                <div id="rotateSummary" class="summary-container" style="display: none;">
                    <div class="summary-stats">
                        <div class="summary-item">
                            <span class="summary-label">ファイル名:</span>
                            <span id="rotateFileName" class="summary-value filename-value">-</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">合計ページ数:</span>
                            <span id="rotatePageCount" class="summary-value">0ページ</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">回転設定ページ:</span>
                            <span id="rotateTargetCount" class="summary-value">0 / 0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">ファイルサイズ:</span>
                            <span id="rotateFileSize" class="summary-value">0 MB</span>
                        </div>
                    </div>
                </div>

                <!-- 一括操作ツールバー -->
                <div id="rotateToolbar" class="rotate-toolbar-container" style="display: none;">
                    <div class="rotate-toolbar-header">
                        <span class="rotate-toolbar-title">一括回転</span>
                        <span class="rotate-toolbar-note">一括または指定ページをまとめて回転できます</span>
                    </div>
                    <div class="rotate-toolbar-actions">
                        <button type="button" class="tool-btn" onclick="rotateAllPages(90)">一括 右90° ↻</button>
                        <button type="button" class="tool-btn" onclick="rotateAllPages(-90)">一括 左90° ↺</button>
                        <button type="button" class="tool-btn" onclick="rotateAllPages(180)">一括 180°</button>
                        <button type="button" class="tool-btn reset-btn" onclick="resetAllRotations()">すべてリセット</button>
                    </div>
                    <div class="rotate-range-box">
                        <span class="range-label">ページ指定で回転:</span>
                        <div class="range-inputs">
                            <input type="text" id="rotateRangeInput" placeholder="例: 1, 3-5, 8" class="range-text-input">
                            <button type="button" class="tool-btn-sm" onclick="rotateRangePages(90)">右90° ↻</button>
                            <button type="button" class="tool-btn-sm" onclick="rotateRangePages(-90)">左90° ↺</button>
                        </div>
                    </div>
                </div>

                <!-- 各ページグリッド -->
                <div id="rotatePagesContainer" class="rotate-pages-container" style="display: none;">
                    <div class="pages-grid-header">
                        <span class="pages-grid-title">ページ一覧（サムネイルやボタンをクリックして個別に90度回転できます）</span>
                    </div>
                    <div id="rotatePagesGrid" class="rotate-pages-grid"></div>
                </div>

                <!-- 隠しフィールド -->
                <input type="hidden" name="rotations" id="rotationsJsonInput" value="{}">
                <input type="hidden" name="rotate" value="1">
                <input type="file" name="pdfFileRotate" id="actualRotateFileInput" style="display: none;">

                <button type="button" id="rotateSubmitBtn" onclick="submitRotateForm()" class="submit-button" style="display: none;">回転したファイルを保存</button>
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
        // ==========================================
        // 定数・基本設定
        // ==========================================
        const MAX_FILES = 100;
        const MAX_SINGLE_FILE_SIZE = 100 * 1024 * 1024; // 100MB
        const MAX_TOTAL_SIZE = 200 * 1024 * 1024;       // 200MB
        const ALLOWED_MERGE_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'];

        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        // ==========================================
        // タブ切り替え
        // ==========================================
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

        // ==========================================
        // ユーティリティ関数
        // ==========================================
        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, function(m) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m];
            });
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function getFileExtension(filename) {
            return filename.slice((filename.lastIndexOf(".") - 1 >>> 0) + 2).toLowerCase();
        }

        function getFileTypeInfo(ext) {
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                return { label: '画像', type: 'image' };
            }
            if (ext === 'txt') {
                return { label: 'テキスト', type: 'text' };
            }
            return { label: 'PDF', type: 'pdf' };
        }

        function showClientError(containerId, messages) {
            const container = document.getElementById(containerId);
            if (!container) return;
            if (!messages || messages.length === 0) {
                container.style.display = 'none';
                container.innerHTML = '';
                return;
            }
            let html = '<div class="error-title">⚠️ 入力エラー・上限エラー</div><ul>';
            for (const msg of messages) {
                html += '<li>' + escapeHtml(msg) + '</li>';
            }
            html += '</ul>';
            container.innerHTML = html;
            container.style.display = 'block';
        }

        // ページ数取得 (API)
        async function getPageCount(file) {
            const ext = getFileExtension(file.name);
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                return 1;
            }

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

        // ページ数解析キュー
        const pageCountQueue = [];
        let activePageCountWorkers = 0;
        const MAX_CONCURRENT_PAGE_COUNT = 4;

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
                if (count && count > 0) {
                    for (let p = 1; p <= count; p++) {
                        if (file.rotations[p] === undefined) {
                            file.rotations[p] = 0;
                        }
                    }
                }
            } catch (e) {
                file.pageCount = null;
            } finally {
                activePageCountWorkers--;
                updateFileList();
                updateMergeSummary();
                processPageCountQueue();
            }
        }

        // PDFサムネイル描画ヘルパー
        async function renderThumbnailToCanvas(pdfDoc, pageNo, canvas, targetWidth = 80) {
            if (!pdfDoc || !canvas) return;
            try {
                const page = await pdfDoc.getPage(pageNo);
                const baseViewport = page.getViewport({ scale: 1.0 });
                const scale = targetWidth / baseViewport.width;
                const viewport = page.getViewport({ scale: scale });
                const context = canvas.getContext('2d');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                await page.render({ canvasContext: context, viewport: viewport }).promise;
            } catch (err) {
                console.warn(`renderThumbnailToCanvas page ${pageNo} error:`, err);
            }
        }

        // ページ番号配列から範囲文字列（例: "1-3, 5, 7-9"）を生成
        function formatPageNumbersToRange(pageNums) {
            if (!pageNums || pageNums.length === 0) return '';
            const sorted = Array.from(new Set(pageNums)).sort((a, b) => a - b);
            const ranges = [];
            let start = sorted[0];
            let end = sorted[0];

            for (let i = 1; i < sorted.length; i++) {
                if (sorted[i] === end + 1) {
                    end = sorted[i];
                } else {
                    ranges.push(start === end ? `${start}` : `${start}-${end}`);
                    start = sorted[i];
                    end = sorted[i];
                }
            }
            ranges.push(start === end ? `${start}` : `${start}-${end}`);
            return ranges.join(', ');
        }

        // 範囲文字列からページ番号配列をパース
        function parsePageRange(rangeStr, maxPage) {
            const pages = new Set();
            if (!rangeStr) return [];
            const parts = rangeStr.split(',');
            for (let part of parts) {
                part = part.trim();
                if (part.includes('-')) {
                    const rangeParts = part.split('-');
                    const s = parseInt(rangeParts[0].trim(), 10);
                    const e = parseInt(rangeParts[1].trim(), 10);
                    if (!isNaN(s) && !isNaN(e) && s > 0 && e >= s) {
                        for (let i = s; i <= Math.min(e, maxPage); i++) {
                            pages.add(i);
                        }
                    }
                } else {
                    const num = parseInt(part, 10);
                    if (!isNaN(num) && num > 0 && num <= maxPage) {
                        pages.add(num);
                    }
                }
            }
            return Array.from(pages).sort((a, b) => a - b);
        }

        // ==========================================
        // 結合タブ用スクリプト
        // ==========================================
        let mergeFiles = [];

        async function addFiles(files) {
            if (!files || files.length === 0) return;

            const errorMessages = [];
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const ext = getFileExtension(file.name);

                if (!ALLOWED_MERGE_EXTS.includes(ext)) {
                    errorMessages.push(`ファイル「${file.name}」: 対応していない形式です（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）。`);
                    continue;
                }

                if (file.size > MAX_SINGLE_FILE_SIZE) {
                    errorMessages.push(`ファイル「${file.name}」: サイズ（${formatFileSize(file.size)}）が上限（100MB）を超えているため除外しました。`);
                    continue;
                }

                // カスタムプロパティの初期化
                file.uid = 'mf_' + Math.random().toString(36).substr(2, 9);
                file.rotations = {};
                file.isExpanded = (mergeFiles.length < 3); // 最初の数個は自動で展開表示
                file.pdfDoc = null;
                file.previewUrl = null;
                file.textContent = null;
                file.isTextExpanded = false;

                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                    file.pageCount = 1;
                    file.rotations[1] = 0;
                    file.previewUrl = URL.createObjectURL(file);
                } else if (ext === 'txt') {
                    file.pageCount = null;
                    file.rotations[1] = 0;
                    enqueuePageCount(file);
                    // テキスト内容を非同期に読み込み
                    file.text().then(text => {
                        file.textContent = text;
                        updateTextPreviewBox(file);
                    }).catch(err => {
                        console.warn('テキスト読み込み失敗:', err);
                        file.textContent = '(テキストの読み込みに失敗しました)';
                        updateTextPreviewBox(file);
                    });
                } else {
                    file.pageCount = null;
                    enqueuePageCount(file);
                }

                mergeFiles.push(file);
            }

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

                const isSingleConvert = (mergeFiles.length === 1 && getFileTypeInfo(getFileExtension(mergeFiles[0].name)).type !== 'pdf');
                const defaultBtnText = isSingleConvert ? 'PDFに変換・保存' : 'PDFを結合';

                if (isExceeded) {
                    summaryDiv.classList.add('limit-exceeded');
                    warningText.innerHTML = warnings.join('<br>');
                    warningText.style.display = 'block';
                    submitBtn.disabled = true;
                    submitBtn.textContent = '上限を超えているため処理できません';
                } else {
                    summaryDiv.classList.remove('limit-exceeded');
                    warningText.innerHTML = '';
                    warningText.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.textContent = defaultBtnText;
                }
            } else {
                summaryDiv.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.textContent = 'PDFを結合';
                showClientError('mergeClientError', []);
            }
        }

        function updateFileList() {
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';

            if (mergeFiles.length > 0) {
                for (let i = 0; i < mergeFiles.length; i++) {
                    const file = mergeFiles[i];
                    const ext = getFileExtension(file.name);
                    const typeInfo = getFileTypeInfo(ext);

                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.dataset.index = i;

                    // 1. ヘッダー行
                    const header = document.createElement('div');
                    header.className = 'file-item-header';

                    const handle = document.createElement('span');
                    handle.className = 'handle';
                    handle.innerHTML = '&#8597;';
                    header.appendChild(handle);

                    const typeBadge = document.createElement('span');
                    typeBadge.className = `file-type-badge type-${typeInfo.type}`;
                    typeBadge.textContent = typeInfo.label;
                    header.appendChild(typeBadge);

                    const fileName = document.createElement('span');
                    fileName.className = 'file-name';
                    fileName.textContent = file.name;
                    header.appendChild(fileName);

                    const pageBadge = document.createElement('span');
                    pageBadge.className = 'file-page-count';
                    pageBadge.textContent = file.pageCount !== null ? file.pageCount + 'ページ' : '解析中...';
                    header.appendChild(pageBadge);

                    const fileSize = document.createElement('span');
                    fileSize.className = 'file-size';
                    fileSize.textContent = formatFileSize(file.size);
                    header.appendChild(fileSize);

                    // プレビュー・回転トグルボタン
                    const rotateBtn = document.createElement('button');
                    rotateBtn.type = 'button';
                    if (ext === 'txt') {
                        rotateBtn.className = `rotate-toggle-btn text-toggle-btn ${file.isExpanded ? 'active' : ''}`;
                        rotateBtn.innerHTML = `📄 テキスト表示 ${file.isExpanded ? '▲' : '▼'}`;
                        rotateBtn.title = 'テキスト内容のプレビューを表示/非表示';
                    } else {
                        rotateBtn.className = `rotate-toggle-btn ${file.isExpanded ? 'active' : ''}`;
                        rotateBtn.innerHTML = `🔄 ページ回転・プレビュー ${file.isExpanded ? '▲' : '▼'}`;
                        rotateBtn.title = '各ページの回転とプレビューを表示/非表示';
                    }
                    rotateBtn.onclick = (e) => {
                        e.stopPropagation();
                        toggleFilePages(i);
                    };
                    header.appendChild(rotateBtn);

                    const removeBtn = document.createElement('span');
                    removeBtn.className = 'remove';
                    removeBtn.textContent = '\u00D7';
                    removeBtn.onclick = function(e) {
                        e.stopPropagation();
                        removeFile(i);
                    };
                    header.appendChild(removeBtn);

                    fileItem.appendChild(header);

                    // 2. プレビュー・回転セクション（展開時）
                    if (file.isExpanded) {
                        if (ext === 'txt') {
                            // テキストファイルプレビュー領域（回転機能なし・冒頭3行表示＋スライドトグル）
                            const textSection = document.createElement('div');
                            textSection.className = 'file-item-pages text-preview-wrapper';

                            const container = document.createElement('div');
                            container.className = 'text-preview-container';

                            const txtHeader = document.createElement('div');
                            txtHeader.className = 'text-preview-header';
                            const txtTitle = document.createElement('span');
                            txtTitle.className = 'text-preview-title';
                            txtTitle.textContent = '📄 テキスト内容プレビュー';
                            const txtInfo = document.createElement('span');
                            txtInfo.className = 'text-preview-info';
                            txtInfo.id = `text_info_${file.uid}`;

                            txtHeader.appendChild(txtTitle);
                            txtHeader.appendChild(txtInfo);
                            container.appendChild(txtHeader);

                            const contentBox = document.createElement('div');
                            contentBox.className = `text-content-box ${file.isTextExpanded ? 'expanded' : 'collapsed'}`;
                            contentBox.id = `text_box_${file.uid}`;

                            const toggleBtn = document.createElement('button');
                            toggleBtn.type = 'button';
                            toggleBtn.className = 'text-slide-toggle-btn';
                            toggleBtn.id = `text_toggle_${file.uid}`;
                            toggleBtn.innerHTML = file.isTextExpanded ? '閉じる ▲' : '全文を表示 ▼';
                            toggleBtn.onclick = (e) => {
                                e.stopPropagation();
                                toggleTextSlide(i);
                            };

                            if (file.textContent !== null && file.textContent !== undefined) {
                                contentBox.textContent = file.textContent;
                                const lineCount = file.textContent.split('\n').length;
                                txtInfo.textContent = `${file.textContent.length.toLocaleString()}文字 / ${lineCount}行`;
                                if (lineCount <= 3 && file.textContent.length < 150) {
                                    toggleBtn.style.display = 'none';
                                }
                            } else {
                                contentBox.textContent = 'テキストを読み込み中...';
                                txtInfo.textContent = '読み込み中...';
                            }

                            container.appendChild(contentBox);
                            container.appendChild(toggleBtn);
                            textSection.appendChild(container);
                            fileItem.appendChild(textSection);
                        } else {
                            // PDF / 画像ファイル用 ページ回転・サムネイルセクション
                            const pagesSection = document.createElement('div');
                            pagesSection.className = 'file-item-pages';

                            // ツールバー
                            const toolbar = document.createElement('div');
                            toolbar.className = 'file-pages-toolbar';

                            const tbTitle = document.createElement('span');
                            tbTitle.className = 'toolbar-title';
                            tbTitle.textContent = file.pageCount !== null ? `このファイルのページ回転（${file.pageCount}ページ）` : 'ページ解析中...';
                            toolbar.appendChild(tbTitle);

                            const tbActions = document.createElement('div');
                            tbActions.className = 'toolbar-actions';
                            tbActions.innerHTML = `
                                <button type="button" class="mini-tool-btn" onclick="rotateFileAllPages(${i}, 90)">一括 ↻ 90°</button>
                                <button type="button" class="mini-tool-btn" onclick="rotateFileAllPages(${i}, -90)">一括 ↺ 90°</button>
                                <button type="button" class="mini-tool-btn reset" onclick="resetFileRotations(${i})">リセット</button>
                            `;
                            toolbar.appendChild(tbActions);
                            pagesSection.appendChild(toolbar);

                            // サムネイルコンテナ
                            const container = document.createElement('div');
                            container.className = 'mini-pages-container';
                            container.id = `mcontainer_${file.uid}`;

                            const pageCount = file.pageCount || 1;
                            for (let p = 1; p <= pageCount; p++) {
                                const angle = file.rotations[p] || 0;
                                const card = document.createElement('div');
                                card.className = `mini-page-card ${angle !== 0 ? 'is-rotated' : ''}`;
                                card.id = `mcard_${file.uid}_${p}`;

                                card.innerHTML = `
                                    <div class="card-top">
                                        <span class="page-num">P.${p}</span>
                                        <span class="mini-angle-badge ${angle !== 0 ? 'rotated' : ''}" id="mbadge_${file.uid}_${p}">${angle}°</span>
                                    </div>
                                    <div class="mini-thumb-wrap" onclick="rotateFilePage(${i}, ${p}, 90)" title="クリックで右に90度回転">
                                        <div class="canvas-holder" id="mholder_${file.uid}_${p}" style="transform: rotate(${angle}deg);">
                                            ${['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)
                                                ? `<img src="${file.previewUrl || ''}" alt="P.${p}">`
                                                : `<canvas id="mcanvas_${file.uid}_${p}"></canvas>`
                                            }
                                        </div>
                                    </div>
                                    <div class="card-actions">
                                        <button type="button" class="mini-rot-btn" onclick="rotateFilePage(${i}, ${p}, -90)" title="左90°回転">↺</button>
                                        <button type="button" class="mini-rot-btn" onclick="rotateFilePage(${i}, ${p}, 90)" title="右90°回転">↻</button>
                                    </div>
                                `;
                                container.appendChild(card);
                            }

                            pagesSection.appendChild(container);
                            fileItem.appendChild(pagesSection);

                            // PDFサムネイル非同期描画
                            if (ext === 'pdf' && window.pdfjsLib) {
                                renderFilePdfThumbnails(file, i);
                            }
                        }
                    }

                    fileList.appendChild(fileItem);
                }

                initSortable();
            }
        }

        async function renderFilePdfThumbnails(file, index) {
            try {
                if (!file.pdfDoc) {
                    const arrayBuffer = await file.arrayBuffer();
                    file.pdfDoc = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                    if (!file.pageCount) {
                        file.pageCount = file.pdfDoc.numPages;
                    }
                }

                const pageCount = file.pageCount || file.pdfDoc.numPages;
                for (let p = 1; p <= pageCount; p++) {
                    const canvas = document.getElementById(`mcanvas_${file.uid}_${p}`);
                    if (canvas && !canvas.dataset.rendered) {
                        canvas.dataset.rendered = 'true';
                        renderThumbnailToCanvas(file.pdfDoc, p, canvas, 74);
                    }
                }
            } catch (err) {
                console.warn(`renderFilePdfThumbnails failed for ${file.name}:`, err);
            }
        }

        function updateTextPreviewBox(file) {
            const box = document.getElementById(`text_box_${file.uid}`);
            const info = document.getElementById(`text_info_${file.uid}`);
            const btn = document.getElementById(`text_toggle_${file.uid}`);
            if (box && file.textContent !== null) {
                box.textContent = file.textContent;
                const lineCount = file.textContent.split('\n').length;
                if (info) {
                    info.textContent = `${file.textContent.length.toLocaleString()}文字 / ${lineCount}行`;
                }
                if (btn) {
                    if (lineCount <= 3 && file.textContent.length < 150) {
                        btn.style.display = 'none';
                    } else {
                        btn.style.display = 'inline-flex';
                    }
                }
            }
        }

        function toggleTextSlide(fileIndex) {
            const file = mergeFiles[fileIndex];
            if (!file) return;
            file.isTextExpanded = !file.isTextExpanded;

            const box = document.getElementById(`text_box_${file.uid}`);
            const btn = document.getElementById(`text_toggle_${file.uid}`);

            if (box) {
                if (file.isTextExpanded) {
                    box.classList.remove('collapsed');
                    box.classList.add('expanded');
                } else {
                    box.classList.remove('expanded');
                    box.classList.add('collapsed');
                }
            }
            if (btn) {
                btn.innerHTML = file.isTextExpanded ? '閉じる ▲' : '全文を表示 ▼';
            }
        }

        function toggleFilePages(index) {
            if (mergeFiles[index]) {
                mergeFiles[index].isExpanded = !mergeFiles[index].isExpanded;
                updateFileList();
            }
        }

        function rotateFilePage(fileIndex, pageNo, delta) {
            const file = mergeFiles[fileIndex];
            if (!file) return;

            const current = file.rotations[pageNo] || 0;
            const next = ((current + delta) % 360 + 360) % 360;
            file.rotations[pageNo] = next;

            // DOMを即座に更新
            const holder = document.getElementById(`mholder_${file.uid}_${pageNo}`);
            const badge = document.getElementById(`mbadge_${file.uid}_${pageNo}`);
            const card = document.getElementById(`mcard_${file.uid}_${pageNo}`);

            if (holder) holder.style.transform = `rotate(${next}deg)`;
            if (badge) {
                badge.textContent = `${next}°`;
                if (next !== 0) badge.classList.add('rotated');
                else badge.classList.remove('rotated');
            }
            if (card) {
                if (next !== 0) card.classList.add('is-rotated');
                else card.classList.remove('is-rotated');
            }
        }

        function rotateFileAllPages(fileIndex, delta) {
            const file = mergeFiles[fileIndex];
            if (!file) return;
            const pageCount = file.pageCount || 1;
            for (let p = 1; p <= pageCount; p++) {
                rotateFilePage(fileIndex, p, delta);
            }
        }

        function resetFileRotations(fileIndex) {
            const file = mergeFiles[fileIndex];
            if (!file) return;
            const pageCount = file.pageCount || 1;
            for (let p = 1; p <= pageCount; p++) {
                const current = file.rotations[p] || 0;
                rotateFilePage(fileIndex, p, -current);
            }
        }

        function removeFile(index) {
            mergeFiles.splice(index, 1);
            updateFileList();
            updateMergeSummary();
        }

        function initSortable() {
            const fileList = document.getElementById('fileList');
            let draggedItem = null;

            const items = fileList.querySelectorAll('.file-item');
            items.forEach(item => {
                item.draggable = true;

                item.addEventListener('dragstart', function(e) {
                    // 入力欄やボタンのドラッグは防止
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON') {
                        e.preventDefault();
                        return;
                    }
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
                        const draggedIndex = parseInt(draggedItem.dataset.index, 10);
                        const targetIndex = parseInt(this.dataset.index, 10);

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

        function submitMergeForm() {
            const errors = [];

            if (mergeFiles.length === 0) {
                errors.push('結合・変換するファイルを少なくとも1つ選択してください。');
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

            // 各ファイルの回転情報をJSON文字列としてhidden inputに格納
            const fileRotationsArray = mergeFiles.map(f => f.rotations || {});
            document.getElementById('fileRotationsInput').value = JSON.stringify(fileRotationsArray);

            const form = document.getElementById('mergeForm');
            const container = document.getElementById('fileInputsContainer');
            container.innerHTML = '';

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

            const submitBtn = document.getElementById('mergeSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = '結合・変換処理中...';

            form.submit();
        }

        // ==========================================
        // 分割タブ用スクリプト（プレビュー＆ページ回転対応）
        // ==========================================
        let splitFile = null;
        let splitPdfDoc = null;
        let splitPageCount = 0;
        let splitRotations = {};       // { 1: 0, 2: 90, ... }
        let splitSelectedPages = new Set(); // 選択されたページ番号のSet

        async function onSplitFileChange(input) {
            const errorContainer = 'splitClientError';
            showClientError(errorContainer, []);

            const submitBtn = document.getElementById('splitSubmitBtn');
            const summaryDiv = document.getElementById('splitSummary');
            const pageCountVal = document.getElementById('splitPageCount');
            const selectedCountVal = document.getElementById('splitSelectedCount');
            const fileSizeVal = document.getElementById('splitFileSize');
            const previewSection = document.getElementById('splitPreviewSection');

            displayFileNames('pdfFile', 'singleFileList');

            if (input.files && input.files[0]) {
                const file = input.files[0];
                const ext = getFileExtension(file.name);

                if (!ALLOWED_MERGE_EXTS.includes(ext)) {
                    showClientError(errorContainer, ['対応していないファイル形式です（対応形式: PDF, JPG, PNG, GIF, WebP, TXT）。']);
                    submitBtn.disabled = true;
                    summaryDiv.style.display = 'none';
                    previewSection.style.display = 'none';
                    return;
                }

                if (file.size > MAX_SINGLE_FILE_SIZE) {
                    showClientError(errorContainer, [
                        `ファイル「${file.name}」のサイズ（${formatFileSize(file.size)}）が上限（100MB）を超えています。100MB以下のファイルを選択してください。`
                    ]);
                    submitBtn.disabled = true;
                    summaryDiv.style.display = 'none';
                    previewSection.style.display = 'none';
                    return;
                }

                splitFile = file;
                splitRotations = {};
                splitSelectedPages = new Set();
                submitBtn.disabled = false;
                summaryDiv.style.display = 'flex';
                fileSizeVal.textContent = formatFileSize(file.size);
                pageCountVal.textContent = '解析中...';
                selectedCountVal.textContent = '-';

                try {
                    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                        splitPageCount = 1;
                        splitPdfDoc = null;
                        splitRotations[1] = 0;
                        splitSelectedPages.add(1);
                    } else if (ext === 'txt') {
                        splitPageCount = await getPageCount(file) || 1;
                        splitPdfDoc = null;
                        splitFile.textContent = await file.text().catch(() => '');
                        for (let i = 1; i <= splitPageCount; i++) {
                            splitRotations[i] = 0;
                            splitSelectedPages.add(i);
                        }
                    } else {
                        // PDF
                        const arrayBuffer = await file.arrayBuffer();
                        if (window.pdfjsLib) {
                            splitPdfDoc = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                            splitPageCount = splitPdfDoc.numPages;
                        } else {
                            splitPageCount = await getPageCount(file) || 0;
                        }
                        for (let i = 1; i <= splitPageCount; i++) {
                            splitRotations[i] = 0;
                            splitSelectedPages.add(i);
                        }
                    }

                    pageCountVal.textContent = splitPageCount + 'ページ';
                    selectedCountVal.textContent = splitSelectedPages.size + 'ページ';

                    // ページ指定入力欄の同期
                    updateSplitPagesInputFromSelection();

                    // グリッド描画
                    previewSection.style.display = 'block';
                    renderSplitPagesGrid();

                } catch (err) {
                    console.error('Error loading file for split:', err);
                    showClientError(errorContainer, ['ファイルの解析に失敗しました: ' + (err.message || '不明なエラー')]);
                }
            } else {
                summaryDiv.style.display = 'none';
                previewSection.style.display = 'none';
                submitBtn.disabled = false;
            }
        }

        function renderSplitPagesGrid() {
            const grid = document.getElementById('splitPagesGrid');
            if (!grid) return;
            grid.innerHTML = '';

            const ext = splitFile ? getFileExtension(splitFile.name) : 'pdf';
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
            const isText = (ext === 'txt');
            const imgUrl = isImage ? URL.createObjectURL(splitFile) : '';

            // ツールバーの表示制御（テキストファイルは回転不要）
            const rotToolbarGroup = document.getElementById('splitRotateToolbarGroup');
            const toolbarTitle = document.getElementById('splitToolbarTitle');
            if (rotToolbarGroup) {
                rotToolbarGroup.style.display = isText ? 'none' : 'flex';
            }
            if (toolbarTitle) {
                toolbarTitle.textContent = isText 
                    ? 'ページ選択' 
                    : 'ページ選択 ＆ 回転（サムネイルをクリックして90度回転できます）';
            }

            for (let pageNo = 1; pageNo <= splitPageCount; pageNo++) {
                const isSelected = splitSelectedPages.has(pageNo);
                const angle = isText ? 0 : (splitRotations[pageNo] || 0);

                const card = document.createElement('div');
                card.className = `split-page-card ${isSelected ? 'is-selected' : ''} ${angle !== 0 ? 'is-rotated' : ''} ${isText ? 'is-text-card' : ''}`;
                card.id = `splitCard_${pageNo}`;

                card.innerHTML = `
                    <div class="card-header-row">
                        <label class="page-check-label" onclick="event.stopPropagation();">
                            <input type="checkbox" id="splitCheck_${pageNo}" ${isSelected ? 'checked' : ''} onchange="toggleSplitPageSelection(${pageNo}, this.checked)">
                            P.${pageNo}
                        </label>
                        ${isText ? '' : `<span class="angle-badge ${angle !== 0 ? 'rotated' : ''}" id="splitAngleBadge_${pageNo}">${angle}°</span>`}
                    </div>
                    <div class="split-thumb-wrap ${isText ? 'no-click' : ''}" ${isText ? '' : `onclick="rotateSplitPage(${pageNo}, 90)" title="クリックで右に90度回転"`}>
                        <div class="canvas-wrap" id="splitCanvasWrap_${pageNo}" style="transform: rotate(${angle}deg);">
                            ${isText
                                ? `<div class="split-text-box">${escapeHtml(splitFile.textContent || '')}</div>`
                                : (isImage
                                    ? `<img src="${imgUrl}" alt="P.${pageNo}">`
                                    : `<canvas id="splitCanvas_${pageNo}"></canvas>`
                                )
                            }
                        </div>
                    </div>
                    ${isText ? '' : `
                    <div class="card-actions-row">
                        <button type="button" class="split-rot-btn" onclick="rotateSplitPage(${pageNo}, -90)" title="左90°回転">↺ 90°</button>
                        <button type="button" class="split-rot-btn" onclick="rotateSplitPage(${pageNo}, 90)" title="右90°回転">↻ 90°</button>
                    </div>
                    `}
                `;

                grid.appendChild(card);

                if (!isImage && !isText && splitPdfDoc) {
                    const canvas = document.getElementById(`splitCanvas_${pageNo}`);
                    if (canvas) {
                        renderThumbnailToCanvas(splitPdfDoc, pageNo, canvas, 92);
                    }
                }
            }
        }

        function toggleSplitPageSelection(pageNo, isChecked) {
            if (isChecked === undefined) {
                if (splitSelectedPages.has(pageNo)) {
                    splitSelectedPages.delete(pageNo);
                } else {
                    splitSelectedPages.add(pageNo);
                }
            } else {
                if (isChecked) {
                    splitSelectedPages.add(pageNo);
                } else {
                    splitSelectedPages.delete(pageNo);
                }
            }

            const card = document.getElementById(`splitCard_${pageNo}`);
            const check = document.getElementById(`splitCheck_${pageNo}`);
            const nowSelected = splitSelectedPages.has(pageNo);

            if (card) {
                if (nowSelected) card.classList.add('is-selected');
                else card.classList.remove('is-selected');
            }
            if (check) {
                check.checked = nowSelected;
            }

            updateSplitPagesInputFromSelection();
            updateSplitSelectedCountUI();
        }

        function rotateSplitPage(pageNo, delta) {
            const current = splitRotations[pageNo] || 0;
            const next = ((current + delta) % 360 + 360) % 360;
            splitRotations[pageNo] = next;

            const wrap = document.getElementById(`splitCanvasWrap_${pageNo}`);
            const badge = document.getElementById(`splitAngleBadge_${pageNo}`);
            const card = document.getElementById(`splitCard_${pageNo}`);

            if (wrap) wrap.style.transform = `rotate(${next}deg)`;
            if (badge) {
                badge.textContent = `${next}°`;
                if (next !== 0) badge.classList.add('rotated');
                else badge.classList.remove('rotated');
            }
            if (card) {
                if (next !== 0) card.classList.add('is-rotated');
                else card.classList.remove('is-rotated');
            }
        }

        function rotateAllSplitPages(delta) {
            for (let p = 1; p <= splitPageCount; p++) {
                rotateSplitPage(p, delta);
            }
        }

        function resetAllSplitRotations() {
            for (let p = 1; p <= splitPageCount; p++) {
                const current = splitRotations[p] || 0;
                rotateSplitPage(p, -current);
            }
        }

        function selectAllSplitPages(selectAll) {
            splitSelectedPages.clear();
            if (selectAll) {
                for (let p = 1; p <= splitPageCount; p++) {
                    splitSelectedPages.add(p);
                }
            }

            for (let p = 1; p <= splitPageCount; p++) {
                const card = document.getElementById(`splitCard_${p}`);
                const check = document.getElementById(`splitCheck_${p}`);
                if (card) {
                    if (selectAll) card.classList.add('is-selected');
                    else card.classList.remove('is-selected');
                }
                if (check) check.checked = selectAll;
            }

            updateSplitPagesInputFromSelection();
            updateSplitSelectedCountUI();
        }

        function updateSplitPagesInputFromSelection() {
            const pagesInput = document.getElementById('pages');
            if (!pagesInput) return;
            if (splitSelectedPages.size === splitPageCount) {
                pagesInput.value = ''; // 全ページの場合は空欄でOK
            } else {
                pagesInput.value = formatPageNumbersToRange(Array.from(splitSelectedPages));
            }
        }

        function onSplitPagesTextInput(val) {
            if (!val || val.trim() === '') {
                // 空欄 = 全ページ選択
                for (let p = 1; p <= splitPageCount; p++) {
                    splitSelectedPages.add(p);
                }
            } else {
                const parsed = parsePageRange(val, splitPageCount);
                splitSelectedPages = new Set(parsed);
            }

            for (let p = 1; p <= splitPageCount; p++) {
                const isSel = splitSelectedPages.has(p);
                const card = document.getElementById(`splitCard_${p}`);
                const check = document.getElementById(`splitCheck_${p}`);
                if (card) {
                    if (isSel) card.classList.add('is-selected');
                    else card.classList.remove('is-selected');
                }
                if (check) check.checked = isSel;
            }

            updateSplitSelectedCountUI();
        }

        function updateSplitSelectedCountUI() {
            const countVal = document.getElementById('splitSelectedCount');
            if (countVal) {
                countVal.textContent = splitSelectedPages.size + 'ページ';
            }
        }

        // 分割フォーム送信前フック
        document.getElementById('splitForm')?.addEventListener('submit', function(e) {
            if (splitPageCount > 0 && splitSelectedPages.size === 0) {
                e.preventDefault();
                showClientError('splitClientError', ['抽出するページを少なくとも1つ選択してください。']);
                return;
            }
            // 回転情報をJSONセット
            document.getElementById('splitRotationsInput').value = JSON.stringify(splitRotations);
            const submitBtn = document.getElementById('splitSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = '分割・抽出処理中...';
            }
        });

        // 単一ファイル選択表示
        function displayFileNames(inputId, listId) {
            const input = document.getElementById(inputId);
            const fileList = document.getElementById(listId);
            if (!fileList) return;
            fileList.innerHTML = '';

            if (input && input.files && input.files.length > 0) {
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

        // ==========================================
        // PDF回転タブ用スクリプト（画像・テキストにも対応）
        // ==========================================
        let rotateFile = null;
        let rotatePdfDoc = null;
        let rotatePageCount = 0;
        let pageRotations = {};

        async function onRotateFileChange(input) {
            if (input.files && input.files[0]) {
                await setRotateFile(input.files[0]);
            }
        }

        async function setRotateFile(file) {
            const errorContainer = 'rotateClientError';
            showClientError(errorContainer, []);

            const ext = getFileExtension(file.name);
            const ALLOWED_ROTATE_EXTS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!ALLOWED_ROTATE_EXTS.includes(ext)) {
                if (ext === 'txt') {
                    showClientError(errorContainer, ['テキストファイル（.txt）は回転機能に対応していません。PDFまたは画像ファイルを選択してください。']);
                } else {
                    showClientError(errorContainer, ['対応していないファイル形式です（対応形式: PDF, JPG, PNG, GIF, WebP）。']);
                }
                return;
            }

            if (file.size > MAX_SINGLE_FILE_SIZE) {
                showClientError(errorContainer, [
                    `ファイル「${file.name}」のサイズ（${formatFileSize(file.size)}）が上限（100MB）を超えています。100MB以下のファイルを選択してください。`
                ]);
                return;
            }

            rotateFile = file;
            pageRotations = {};

            const dropText = document.getElementById('rotateDropZoneText');
            if (dropText) dropText.style.display = 'none';

            const singleList = document.getElementById('rotateSingleFileList');
            singleList.innerHTML = `
                <div class="file-item">
                    <span class="file-name">${escapeHtml(file.name)}</span>
                    <span class="file-size">${formatFileSize(file.size)}</span>
                    <span class="remove" onclick="clearRotateFile(event)">&times;</span>
                </div>
            `;

            const summary = document.getElementById('rotateSummary');
            summary.style.display = 'flex';
            document.getElementById('rotateFileName').textContent = file.name;
            document.getElementById('rotateFileSize').textContent = formatFileSize(file.size);
            document.getElementById('rotatePageCount').textContent = '解析中...';
            document.getElementById('rotateTargetCount').textContent = '0 / -';

            try {
                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                    rotatePageCount = 1;
                    rotatePdfDoc = null;
                    pageRotations[1] = 0;
                } else {
                    const arrayBuffer = await file.arrayBuffer();
                    if (window.pdfjsLib) {
                        rotatePdfDoc = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                        rotatePageCount = rotatePdfDoc.numPages;
                    } else {
                        rotatePageCount = await getPageCount(file) || 0;
                    }
                    for (let i = 1; i <= rotatePageCount; i++) pageRotations[i] = 0;
                }

                document.getElementById('rotatePageCount').textContent = rotatePageCount + 'ページ';
                updateRotateSummaryCounts();

                document.getElementById('rotateToolbar').style.display = 'block';
                document.getElementById('rotatePagesContainer').style.display = 'block';
                document.getElementById('rotateSubmitBtn').style.display = 'inline-block';

                renderRotateGrid();
            } catch (err) {
                console.error('Error loading file for rotate:', err);
                showClientError(errorContainer, ['ファイルの解析に失敗しました: ' + (err.message || '不明なエラー')]);
            }
        }

        function clearRotateFile(e) {
            if (e) e.stopPropagation();
            rotateFile = null;
            rotatePdfDoc = null;
            rotatePageCount = 0;
            pageRotations = {};

            const fileInput = document.getElementById('pdfFileRotateInput');
            if (fileInput) fileInput.value = '';

            const dropText = document.getElementById('rotateDropZoneText');
            if (dropText) dropText.style.display = 'block';

            document.getElementById('rotateSingleFileList').innerHTML = '';
            document.getElementById('rotateSummary').style.display = 'none';
            document.getElementById('rotateToolbar').style.display = 'none';
            document.getElementById('rotatePagesContainer').style.display = 'none';
            document.getElementById('rotateSubmitBtn').style.display = 'none';
            document.getElementById('rotatePagesGrid').innerHTML = '';
            showClientError('rotateClientError', []);
        }

        function renderRotateGrid() {
            const grid = document.getElementById('rotatePagesGrid');
            grid.innerHTML = '';

            const ext = rotateFile ? getFileExtension(rotateFile.name) : 'pdf';
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
            const imgUrl = isImage ? URL.createObjectURL(rotateFile) : '';

            for (let pageNo = 1; pageNo <= rotatePageCount; pageNo++) {
                const card = document.createElement('div');
                card.className = 'rotate-page-card';
                card.id = `rotateCard_${pageNo}`;

                card.innerHTML = `
                    <div class="card-header">
                        <span class="page-num">P.${pageNo}</span>
                        <span class="angle-badge" id="angleBadge_${pageNo}">0°</span>
                    </div>
                    <div class="thumb-container" onclick="rotatePage(${pageNo}, 90)" title="クリックで時計回りに90度回転">
                        <div class="canvas-wrap" id="canvasWrap_${pageNo}">
                            ${isImage
                                ? `<img src="${imgUrl}" alt="P.${pageNo}" style="max-width: 130px; max-height: 170px; object-fit: contain;">`
                                : `<canvas id="thumbCanvas_${pageNo}"></canvas>`
                            }
                        </div>
                    </div>
                    <div class="card-actions">
                        <button type="button" class="card-btn" onclick="rotatePage(${pageNo}, -90)" title="左90°回転">↺ 90°</button>
                        <button type="button" class="card-btn" onclick="rotatePage(${pageNo}, 90)" title="右90°回転">↻ 90°</button>
                        <button type="button" class="card-btn-reset" onclick="setPageAngle(${pageNo}, 0)" title="リセット">0°</button>
                    </div>
                `;
                grid.appendChild(card);

                if (!isImage && rotatePdfDoc) {
                    renderPageThumbnail(pageNo);
                }
            }
        }

        async function renderPageThumbnail(pageNo) {
            try {
                const canvas = document.getElementById(`thumbCanvas_${pageNo}`);
                if (!canvas || !rotatePdfDoc) return;
                await renderThumbnailToCanvas(rotatePdfDoc, pageNo, canvas, 140);
            } catch (err) {
                console.warn(`Thumbnail render failed for page ${pageNo}:`, err);
            }
        }

        function rotatePage(pageNo, delta) {
            const current = pageRotations[pageNo] || 0;
            const next = ((current + delta) % 360 + 360) % 360;
            setPageAngle(pageNo, next);
        }

        function setPageAngle(pageNo, angle) {
            angle = ((angle % 360) + 360) % 360;
            pageRotations[pageNo] = angle;
            updatePageCardUI(pageNo, angle);
            updateRotateSummaryCounts();
        }

        function updatePageCardUI(pageNo, angle) {
            const wrap = document.getElementById(`canvasWrap_${pageNo}`);
            const badge = document.getElementById(`angleBadge_${pageNo}`);
            const card = document.getElementById(`rotateCard_${pageNo}`);

            if (wrap) wrap.style.transform = `rotate(${angle}deg)`;
            if (badge) {
                badge.textContent = `${angle}°`;
                if (angle !== 0) badge.classList.add('rotated');
                else badge.classList.remove('rotated');
            }
            if (card) {
                if (angle !== 0) card.classList.add('is-rotated');
                else card.classList.remove('is-rotated');
            }
        }

        function rotateAllPages(delta) {
            for (let i = 1; i <= rotatePageCount; i++) {
                rotatePage(i, delta);
            }
        }

        function resetAllRotations() {
            for (let i = 1; i <= rotatePageCount; i++) {
                setPageAngle(i, 0);
            }
        }

        function rotateRangePages(delta) {
            const input = document.getElementById('rotateRangeInput');
            const rangeStr = input ? input.value.trim() : '';
            if (!rangeStr) {
                alert('回転するページ番号を入力してください。（例: 1, 3-5, 8）');
                return;
            }

            const pageNumbers = parsePageRange(rangeStr, rotatePageCount);
            if (pageNumbers.length === 0) {
                alert('有効なページ番号が見つかりませんでした。範囲をご確認ください。');
                return;
            }

            for (const p of pageNumbers) {
                rotatePage(p, delta);
            }
        }

        function updateRotateSummaryCounts() {
            let rotatedCount = 0;
            for (let i = 1; i <= rotatePageCount; i++) {
                if ((pageRotations[i] || 0) !== 0) {
                    rotatedCount++;
                }
            }
            const targetVal = document.getElementById('rotateTargetCount');
            if (targetVal) {
                targetVal.textContent = `${rotatedCount} / ${rotatePageCount}ページ`;
            }
        }

        function submitRotateForm() {
            if (!rotateFile) {
                showClientError('rotateClientError', ['回転するファイルを選択してください。']);
                return;
            }

            const jsonInput = document.getElementById('rotationsJsonInput');
            jsonInput.value = JSON.stringify(pageRotations);

            const actualInput = document.getElementById('actualRotateFileInput');
            const dt = new DataTransfer();
            dt.items.add(rotateFile);
            actualInput.files = dt.files;

            const submitBtn = document.getElementById('rotateSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = '回転処理中...';

            document.getElementById('rotateForm').submit();
        }

        // ==========================================
        // ドラッグ＆ドロップ初期化
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            const dropZone = document.getElementById('dropZone');
            if (dropZone) {
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
                    if (dt && dt.files) addFiles(dt.files);
                }, false);
            }

            const rotateDropZone = document.getElementById('rotateDropZone');
            if (rotateDropZone) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    rotateDropZone.addEventListener(eventName, function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                    }, false);
                });
                ['dragenter', 'dragover'].forEach(eventName => {
                    rotateDropZone.addEventListener(eventName, function() {
                        rotateDropZone.classList.add('drag-over');
                    }, false);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    rotateDropZone.addEventListener(eventName, function() {
                        rotateDropZone.classList.remove('drag-over');
                    }, false);
                });
                rotateDropZone.addEventListener('drop', function(e) {
                    const dt = e.dataTransfer;
                    if (dt && dt.files && dt.files[0]) {
                        setRotateFile(dt.files[0]);
                    }
                }, false);
            }
        });
    </script>
</body>
</html>
