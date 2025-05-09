<?php
// エラー表示設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

// セッション開始
session_start();

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
            $message = '結合が完了しました。';
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
            $message = '分割が完了しました。';
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
        // 標準のFPDIを使用
        $pdf = new \setasign\Fpdi\Fpdi();
        
        // 各PDFファイルを処理
        foreach ($uploadedFiles as $file) {
            $pageCount = $pdf->setSourceFile($file);
            
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
        
        return ['success' => true, 'file' => $outputFile];
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
                }
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
                }
            }
            
            $outputFile = $outputDir . $originalFilename . '_extracted.pdf';
            $pdf->Output('F', $outputFile);
        }
        
        // アップロードされた元ファイルを削除
        @unlink($uploadPath);
        
        return ['success' => true, 'file' => $outputFile];
    } catch (Exception $e) {
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
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        h1 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        .tab {
            overflow: hidden;
            border: 1px solid #ccc;
            background-color: #f1f1f1;
            border-radius: 5px 5px 0 0;
        }
        .tab button {
            background-color: inherit;
            float: left;
            border: none;
            outline: none;
            cursor: pointer;
            padding: 14px 16px;
            transition: 0.3s;
            font-size: 16px;
            font-weight: bold;
        }
        .tab button:hover {
            background-color: #ddd;
        }
        .tab button.active {
            background-color: #3498db;
            color: white;
        }
        .tabcontent {
            display: none;
            padding: 20px;
            border: 1px solid #ccc;
            border-top: none;
            border-radius: 0 0 5px 5px;
            animation: fadeEffect 1s;
        }
        @keyframes fadeEffect {
            from {opacity: 0;}
            to {opacity: 1;}
        }
        label {
            display: block;
            margin: 15px 0 5px;
            font-weight: bold;
        }
        input[type="file"], input[type="text"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button[type="submit"] {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            display: block;
            margin: 20px 0;
            transition: background-color 0.3s;
        }
        button[type="submit"]:hover {
            background-color: #2980b9;
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .download-link {
            display: inline-block;
            background-color: #28a745;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 4px;
            margin-top: 10px;
            transition: background-color 0.3s;
        }
        .download-link:hover {
            background-color: #218838;
        }
        .note {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .drop-zone {
            margin: 10px 0;
            border: 2px dashed #3498db;
            border-radius: 4px;
            background-color: #f9f9f9;
            position: relative;
            min-height: 100px;
            transition: all 0.3s;
        }
        .drop-zone.drag-over {
            background-color: #e8f4fc;
            border-color: #2980b9;
        }
        .drop-zone-text {
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
            font-size: 16px;
        }
        .file-list {
            padding: 10px;
            max-height: 250px;
            overflow-y: auto;
        }
        .dragging {
            opacity: 0.5;
        }
        .drag-over {
            border-top: 2px solid #3498db;
        }
        .file-list:empty {
            display: none;
        }
        .file-item {
            padding: 5px;
            margin-bottom: 5px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            cursor: move;
        }
        .file-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .file-item .handle {
            margin-right: 10px;
            color: #999;
            cursor: move;
        }
        .file-item .remove {
            margin-left: auto;
            color: #e74c3c;
            cursor: pointer;
            font-weight: bold;
            padding: 0 5px;
        }
        .file-item .remove:hover {
            color: #c0392b;
        }
        .add-button, .submit-button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin: 10px 0;
            transition: background-color 0.3s;
        }
        .add-button:hover, .submit-button:hover {
            background-color: #2980b9;
        }
        input[name="pdfFileAdd"] {
            display: none;
        }
        .file-name {
            font-weight: bold;
        }
        .file-size {
            color: #666;
            font-size: 12px;
            margin-left: 10px;
        }
        .aaa{
            display: block;
        }
    </style>
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
                
                <button type="button" onclick="submitMergeForm()" class="submit-button">PDFを結合</button>
                <div id="fileInputsContainer" style="display: none;"></div>
            </form>
        </div>
        
        <!-- PDF分割タブ -->
        <div id="split" class="tabcontent">
            <form action="" method="post" enctype="multipart/form-data">
                <label for="pdfFile">PDFファイルを選択:</label>
                <input type="file" name="pdfFile" id="pdfFile" accept=".pdf" required onchange="displayFileNames('pdfFile', 'singleFileList')">
                <div id="singleFileList" class="file-list"></div>
                
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
        function addFiles(files) {
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                // PDFファイルかチェック
                if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                    mergeFiles.push(file);
                }
            }
            updateFileList();
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
                    
                    // ファイル名とサイズを表示
                    const fileName = document.createElement('span');
                    fileName.className = 'file-name';
                    fileName.textContent = file.name;
                    
                    const fileSize = document.createElement('span');
                    fileSize.className = 'file-size';
                    fileSize.textContent = formatFileSize(file.size);
                    
                    // 削除ボタン
                    const removeBtn = document.createElement('span');
                    removeBtn.className = 'remove';
                    removeBtn.textContent = '\u00D7'; // × is the multiplication sign (×)
                    removeBtn.onclick = function() {
                        removeFile(i);
                    };
                    
                    fileItem.appendChild(fileName);
                    fileItem.appendChild(fileSize);
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
