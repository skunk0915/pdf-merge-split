<?php
// エラー表示設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

// セッション開始
session_start();

// API: PDFページ数取得用のエンドポイント (非同期リクエスト用)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_page_count') {
    header('Content-Type: application/json');
    if (!empty($_FILES['pdfFile']['tmp_name'])) {
        require_once('vendor/autoload.php');
        try {
            $tempPdf = new \setasign\Fpdi\Fpdi();
            $pageCount = $tempPdf->setSourceFile($_FILES['pdfFile']['tmp_name']);
            echo json_encode(['success' => true, 'pageCount' => $pageCount]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ファイルがアップロードされていません。']);
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

// フォーム送信時の処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PDFの結合処理
    if (isset($_POST['merge']) && !empty($_FILES['pdfFiles']['name'][0])) {
        $result = mergePdfs($_FILES['pdfFiles']);
        if ($result['success']) {
            $message = '結合が完了しました。（結合ファイル数: ' . $result['fileCount'] . '、合計ページ数: ' . $result['pageCount'] . 'ページ）';
            $downloadLink = $result['file'];
        } else {
            $message = 'エラー: ' . $result['message'];
        }
    }
    
    // PDFの分割処理
    if (isset($_POST['split']) && !empty($_FILES['pdfFile']['name'])) {
        $pages = isset($_POST['pages']) ? $_POST['pages'] : '';
        $result = splitPdf($_FILES['pdfFile'], $pages);
        if ($result['success']) {
            $message = '分割が完了しました。（分割ファイル数: ' . $result['fileCount'] . '、合計ページ数: ' . $result['pageCount'] . 'ページ）';
            $downloadLink = $result['file'];
        } else {
            $message = 'エラー: ' . $result['message'];
        }
    }
}

/**
 * 複数のPDFファイルを結合する
 * 
 * @param array $files アップロードされたファイル情報
 * @return array 処理結果
 */
function mergePdfs($files) {
    global $uploadDir, $outputDir;
    
    // アップロードされたファイルの検証
    $uploadedFiles = [];
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $tmpName = $files['tmp_name'][$i];
            $name = $files['name'][$i];
            
            // PDFファイルかどうかを確認
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                return ['success' => false, 'message' => 'PDFファイルのみアップロードできます。'];
            }
            
            // ファイルを一時ディレクトリに移動
            $uploadPath = $uploadDir . uniqid() . '_' . $name;
            if (move_uploaded_file($tmpName, $uploadPath)) {
                $uploadedFiles[] = $uploadPath;
            } else {
                return ['success' => false, 'message' => 'ファイルのアップロードに失敗しました。'];
            }
        } else {
            return ['success' => false, 'message' => 'ファイルのアップロードエラー（コード: ' . $files['error'][$i] . '）'];
        }
    }
    
    if (count($uploadedFiles) === 0) {
        return ['success' => false, 'message' => 'アップロードされたファイルがありません。'];
    }
    
    // TCPDF-FPDIを使用してPDFを結合
    require_once('vendor/autoload.php');
    
    try {
        // 標準 of FPDI
        $pdf = new \setasign\Fpdi\Fpdi();
        
        $totalPageCount = 0;
        // 各PDFファイルを処理
        foreach ($uploadedFiles as $file) {
            $pageCount = $pdf->setSourceFile($file);
            $totalPageCount += $pageCount;
            
            // 各ページを新しいPDFに追加
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $template = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($template);
                
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }
        
        // 結合したPDFを保存
        // 最初のファイル名を取得
        $firstFileName = pathinfo($files['name'][0], PATHINFO_FILENAME);
        $outputFile = $outputDir . $firstFileName . '_merged.pdf';
        $pdf->Output('F', $outputFile);
        
        // アップロードされた元ファイルを削除
        foreach ($uploadedFiles as $file) {
            @unlink($file);
        }
        
        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => count($uploadedFiles),
            'pageCount' => $totalPageCount
        ];
    } catch (Exception $e) {
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
    
    // アップロードされたファイルの検証
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        $name = $file['name'];
        
        // PDFファイルかどうかを確認
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            return ['success' => false, 'message' => 'PDFファイルのみアップロードできます。'];
        }
        
        // ファイルを一時ディレクトリに移動
        $uploadPath = $uploadDir . uniqid() . '_' . $name;
        if (!move_uploaded_file($tmpName, $uploadPath)) {
            return ['success' => false, 'message' => 'ファイルのアップロードに失敗しました。'];
        }
    } else {
        return ['success' => false, 'message' => 'ファイルのアップロードエラー（コード: ' . $file['error'] . '）'];
    }
    
    // FPDIを使用してPDFを分割
    require_once('vendor/autoload.php');
    
    try {
        // ページ番号の解析
        $pageNumbers = [];
        if (!empty($pages)) {
            $parts = explode(',', $pages);
            foreach ($parts as $part) {
                if (strpos($part, '-') !== false) {
                    list($start, $end) = explode('-', $part);
                    for ($i = (int)$start; $i <= (int)$end; $i++) {
                        $pageNumbers[] = $i;
                    }
                } else {
                    $pageNumbers[] = (int)$part;
                }
            }
        }
        
        // 元のPDFファイルを読み込む
        $sourceFile = $uploadPath;
        $tempPdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $tempPdf->setSourceFile($sourceFile);
        
        // ページ番号が指定されていない場合は全ページを対象にする
        if (empty($pageNumbers)) {
            for ($i = 1; $i <= $pageCount; $i++) {
                $pageNumbers[] = $i;
            }
        }
        
        // 分割したファイルのリスト
        $splitFiles = [];
        $originalFilename = pathinfo($name, PATHINFO_FILENAME);
        
        // 分割モードの判定
        $singlePageMode = count($pageNumbers) > 1; // 複数ページが指定されている場合は各ページを個別のファイルに
        $actualPageCount = 0;
        
        if ($singlePageMode) {
            // 各ページを個別のPDFファイルとして保存
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
                return ['success' => false, 'message' => '指定されたページが元のPDFに存在しません。'];
            }
            
            // 分割したファイルをZIPにまとめる
            $zipFile = $outputDir . $originalFilename . '_split.zip';
            $zip = new ZipArchive();
            
            if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
                foreach ($splitFiles as $file) {
                    $zip->addFile($file, basename($file));
                }
                $zip->close();
                
                // 個別のPDFファイルを削除
                foreach ($splitFiles as $file) {
                    @unlink($file);
                }
                
                $outputFile = $zipFile;
            } else {
                // ZIP作成に失敗した場合は最初のファイルを返す
                $outputFile = $splitFiles[0];
            }
        } else {
            // 単一ページまたはページ指定がない場合は抽出モード
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
                return ['success' => false, 'message' => '指定されたページが元のPDFに存在しません。'];
            }
            
            $outputFile = $outputDir . $originalFilename . '_extracted.pdf';
            $pdf->Output('F', $outputFile);
        }
        
        // アップロードされた元ファイルを削除
        @unlink($uploadPath);
        
        return [
            'success' => true,
            'file' => $outputFile,
            'fileCount' => $singlePageMode ? count($splitFiles) : 1,
            'pageCount' => $actualPageCount
        ];
    } catch (Exception $e) {
        @unlink($uploadPath);
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
            if ($now - filemtime($file) >= 86400) { // 24時間 = 86400秒
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
    <link rel="stylesheet" href="css/style.css">
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
                <label for="pdfFileAdd">PDFファイルを選択（複数可）:</label>
                <input type="file" name="pdfFileAdd" id="pdfFileAdd" accept=".pdf" onchange="addFiles(this.files)">
                <button type="button" class="add-button" onclick="document.getElementById('pdfFileAdd').click()">ファイルを追加</button>
                
                <div id="dropZone" class="drop-zone">
                    <div class="drop-zone-text">ここにPDFファイルをドラッグ＆ドロップ</div>
                    <div id="fileList" class="file-list sortable"></div>
                </div>
                <p class="note">※ ファイルはドラッグ＆ドロップで順序を変更できます。「×」をクリックするとファイルを削除できます。</p>
                
                <!-- リアルタイムサマリー表示領域 -->
                <div id="mergeSummary" class="summary-container" style="display: none;">
                    <div class="summary-item">
                        <span class="summary-label">選択されたファイル数:</span>
                        <span id="mergeFileCount" class="summary-value">0</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">合計ページ数:</span>
                        <span id="mergePageCount" class="summary-value">0</span>
                    </div>
                </div>

                <button type="button" onclick="submitMergeForm()" class="submit-button">PDFを結合</button>
                <div id="fileInputsContainer" style="display: none;"></div>
            </form>
        </div>
        
        <!-- PDF分割タブ -->
        <div id="split" class="tabcontent">
            <form action="" method="post" enctype="multipart/form-data">
                <label for="pdfFile">PDFファイルを選択:</label>
                <input type="file" name="pdfFile" id="pdfFile" accept=".pdf" required onchange="onSplitFileChange(this)">
                <div id="singleFileList" class="file-list"></div>
                
                <!-- リアルタイムサマリー表示領域 -->
                <div id="splitSummary" class="summary-container" style="display: none;">
                    <div class="summary-item">
                        <span class="summary-label">選択されたファイル数:</span>
                        <span class="summary-value">1</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">合計ページ数:</span>
                        <span id="splitPageCount" class="summary-value">0</span>
                    </div>
                </div>

                <label for="pages">ページ指定（オプション）:</label>
                <input type="text" name="pages" id="pages" placeholder="例: 1,3-5,7">
                <p class="note">※ 空白の場合は全ページが対象になります。<br>
                   ※ カンマ区切りでページ番号を指定できます（例: 1,3,5）<br>
                   ※ ハイフンで範囲指定もできます（例: 1-5,7-9）</p>
                
                <button type="submit" name="split">PDFを分割</button>
            </form>
        </div>
    </div>
    
    <script>
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            
            // タブコンテンツを全て非表示にする
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            
            // タブボタンのアクティブクラスを全て削除
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            
            // クリックされたタブを表示し、ボタンをアクティブにする
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
                }
            } catch (e) {
                console.error('Error fetching page count:', e);
            }
            return null;
        }

        // 単一ファイル（分割）選択時の処理
        async function onSplitFileChange(input) {
            displayFileNames('pdfFile', 'singleFileList');
            
            const summaryDiv = document.getElementById('splitSummary');
            const pageCountVal = document.getElementById('splitPageCount');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                summaryDiv.style.display = 'flex';
                pageCountVal.textContent = '解析中...';
                
                const pageCount = await getPageCount(file);
                if (pageCount !== null) {
                    pageCountVal.textContent = pageCount + 'ページ';
                    // ファイル表示にもページ数を追加
                    const fileItem = document.querySelector('#singleFileList .file-item');
                    if (fileItem) {
                        const pageBadge = document.createElement('span');
                        pageBadge.className = 'file-page-count';
                        pageBadge.textContent = pageCount + 'ページ';
                        fileItem.insertBefore(pageBadge, fileItem.querySelector('.file-size'));
                    }
                } else {
                    pageCountVal.textContent = 'エラー';
                }
            } else {
                summaryDiv.style.display = 'none';
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
                    
                    // ファイル名とサイズを表示
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
            
            // ドラッグイベントの設定
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            // ドラッグオーバー時のスタイル変更
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, function() {
                    dropZone.classList.add('drag-over');
                }, false);
            });
            
            // ドラッグリーブ時のスタイル変更
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, function() {
                    dropZone.classList.remove('drag-over');
                }, false);
            });
            
            // ドロップ時の処理
            dropZone.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                addFiles(files);
            }, false);
        });
        
        // ファイルを追加する関数
        async function addFiles(files) {
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                // PDFファイルかチェック
                if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                    file.pageCount = null;
                    mergeFiles.push(file);
                    
                    // 非同期でページ数を取得して反映
                    getPageCount(file).then(pageCount => {
                        file.pageCount = pageCount;
                        updateFileList();
                        updateMergeSummary();
                    });
                }
            }
            updateFileList();
            updateMergeSummary();
        }
        
        // 結合の集計表示を更新する関数
        function updateMergeSummary() {
            const summaryDiv = document.getElementById('mergeSummary');
            const fileCountVal = document.getElementById('mergeFileCount');
            const pageCountVal = document.getElementById('mergePageCount');
            
            if (mergeFiles.length > 0) {
                summaryDiv.style.display = 'flex';
                fileCountVal.textContent = mergeFiles.length;
                
                // ページ数の合計を計算
                let totalPages = 0;
                let loading = false;
                for (let file of mergeFiles) {
                    if (file.pageCount === null) {
                        loading = true;
                    } else {
                        totalPages += file.pageCount;
                    }
                }
                
                pageCountVal.textContent = loading ? '解析中...' : totalPages + 'ページ';
            } else {
                summaryDiv.style.display = 'none';
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
                    
                    // ドラッグハンドル
                    const handle = document.createElement('span');
                    handle.className = 'handle';
                    handle.innerHTML = '&#8597;';
                    fileItem.appendChild(handle);
                    
                    // ファイル名を表示
                    const fileName = document.createElement('span');
                    fileName.className = 'file-name';
                    fileName.textContent = file.name;
                    fileItem.appendChild(fileName);
                    
                    // ページ数表示を追加
                    const pageBadge = document.createElement('span');
                    pageBadge.className = 'file-page-count';
                    pageBadge.textContent = file.pageCount !== null ? file.pageCount + 'ページ' : '解析中...';
                    fileItem.appendChild(pageBadge);
                    
                    // サイズ表示
                    const fileSize = document.createElement('span');
                    fileSize.className = 'file-size';
                    fileSize.textContent = formatFileSize(file.size);
                    fileItem.appendChild(fileSize);
                    
                    // 削除ボタン
                    const removeBtn = document.createElement('span');
                    removeBtn.className = 'remove';
                    removeBtn.textContent = '\u00D7';
                    removeBtn.onclick = function() {
                        removeFile(i);
                    };
                    fileItem.appendChild(removeBtn);
                    
                    fileList.appendChild(fileItem);
                }
                
                // ソータブルを初期化
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
            
            // ドラッグイベントを設定
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
                        
                        // ファイル配列の順序を入れ替え
                        const temp = mergeFiles[draggedIndex];
                        mergeFiles[draggedIndex] = mergeFiles[targetIndex];
                        mergeFiles[targetIndex] = temp;
                        
                        // リストを更新
                        updateFileList();
                        updateMergeSummary();
                    }
                    this.classList.remove('drag-over');
                });
            });
        }
        
        // フォーム送信時にファイルを追加する
        function submitMergeForm() {
            if (mergeFiles.length === 0) {
                alert('PDFファイルを少なくとも1つ選択してください。');
                return;
            }
            
            const form = document.getElementById('mergeForm');
            const container = document.getElementById('fileInputsContainer');
            container.innerHTML = '';
            
            // ファイルをFormDataに追加
            for (let i = 0; i < mergeFiles.length; i++) {
                const input = document.createElement('input');
                input.type = 'file';
                input.name = 'pdfFiles[]';
                input.style.display = 'none';
                
                // FileListオブジェクトを作成
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(mergeFiles[i]);
                input.files = dataTransfer.files;
                
                container.appendChild(input);
            }
            
            // 結合ボタンを追加
            const mergeButton = document.createElement('input');
            mergeButton.type = 'hidden';
            mergeButton.name = 'merge';
            mergeButton.value = '1';
            container.appendChild(mergeButton);
            
            // フォームを送信
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
    
    <?php if (!empty($message)): ?>
        <div class="message <?php echo strpos($message, 'エラー') !== false ? 'error' : 'success'; ?>">
            <?php echo $message; ?>
            <?php if (!empty($downloadLink)): ?>
                <br>
                <a href="<?php echo $downloadLink; ?>" class="download-link" download>ダウンロード</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>
