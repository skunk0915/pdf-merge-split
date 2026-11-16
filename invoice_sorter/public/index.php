<?php
/**
 * PDF Invoice Sorter - Main Dashboard
 */
require_once __DIR__ . '/../config/settings.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>スキャンPDF自動仕分け・請求書管理</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo file_exists(__DIR__ . '/css/style.css') ? filemtime(__DIR__ . '/css/style.css') : time(); ?>">
</head>
<body>
    <div class="container">
        <!-- ヘッダー -->
        <header class="header-area">
            <div class="header-title-box">
                <h1>📄 スキャンPDF自動仕分け・請求書管理</h1>
                <p class="header-sub">紙スキャン後のPDFを自動解析し、クライアント別・年月別フォルダへ整理＆命名規則に基づき保存します。</p>
            </div>
            <div class="header-badges">
                <span class="badge-secure">🔒 IP制限・Basic認証 保護中</span>
                <span class="badge-status">
                    <span class="pulse-dot"></span>
                    <span>AIワーカー常時待機中</span>
                </span>
            </div>
        </header>

        <!-- リアルタイム・アナウンスバー (AI作業中のリアルタイムバナー) -->
        <div id="announcementBanner" class="announcement-banner">
            <div class="banner-inner">
                <div class="banner-content">
                    <span class="banner-icon">⚙️</span>
                    <div class="banner-text-box">
                        <div class="banner-title">AI自動仕分けワーカーが処理中です</div>
                        <div class="banner-desc" id="bannerJobCount">現在AIがバックグラウンドで解析・仕分けを行っています。画面を閉じても安全に作業は継続されます。</div>
                    </div>
                </div>
                <div class="banner-badge">非同期バックグラウンド実行中</div>
            </div>
        </div>

        <!-- 命名規則・フォルダ設定カード -->
        <div class="card">
            <div class="card-header">
                <h2>⚙️ ファイル命名規則 ＆ フォルダ階層設定</h2>
            </div>
            <div class="settings-grid">
                <div class="form-group">
                    <label for="namingPatternSelect">ファイル名の命名規則（順序・構成）</label>
                    <select id="namingPatternSelect" class="form-select">
                        <option value="invoice_date,client_name,payment_date">請求日_クライアント名_支払日.pdf</option>
                        <option value="client_name,invoice_date,payment_date">クライアント名_請求日_支払日.pdf</option>
                        <option value="invoice_date,client_name">請求日_クライアント名.pdf</option>
                        <option value="client_name,invoice_date">クライアント名_請求日.pdf</option>
                        <option value="payment_date,client_name,invoice_date">支払日_クライアント名_請求日.pdf</option>
                    </select>
                    <span class="form-help">※ AI解析時および手動編集時のファイル名に即時反映されます。</span>
                </div>
                <div class="form-group">
                    <label for="folderPatternSelect">仕分け先フォルダ構成</label>
                    <select id="folderPatternSelect" class="form-select">
                        <option value="client_then_month">【取引先別】 クライアント名 / 年月 (YYYY-MM) /</option>
                        <option value="month_then_client">【年月別】 年月 (YYYY-MM) / クライアント名 /</option>
                    </select>
                    <span class="form-help">※ xserver-vps-ow / Google Drive / GCP の保存階層に共通適用されます。</span>
                </div>
            </div>
        </div>

        <!-- スキャンPDFアップロードカード -->
        <div class="card">
            <div class="card-header">
                <h2>📥 スキャンPDFの投入（手動アップロード / 複数可）</h2>
            </div>
            
            <input type="file" id="pdfFileInput" accept=".pdf" multiple style="display: none;">
            <div id="dropZone" class="drop-zone">
                <div class="drop-icon">📂</div>
                <div class="drop-title">ここにスキャンPDFをドラッグ＆ドロップ、またはクリックして選択</div>
                <div class="drop-sub">複数のPDFファイル（請求書・領収書等）を一括で投入できます。投入後、自動でAI仕分けキューに登録されます。</div>
            </div>

            <div class="upload-stats-bar">
                <div>
                    <span>登録状況サマリー: </span>
                    <span class="stat-pill">全件: <b id="statTotal">0</b></span>
                    <span class="stat-pill" style="color: #0284c7;">処理中: <b id="statProcessing">0</b></span>
                    <span class="stat-pill" style="color: #475569;">待機中: <b id="statQueued">0</b></span>
                    <span class="stat-pill" style="color: #16a34a;">完了: <b id="statCompleted">0</b></span>
                </div>
                <div style="font-size: 12px; color: #64748b;">
                    ※ スキャナーからGoogle Drive「未処理」フォルダへ投入されたPDFも自動検知されます
                </div>
            </div>
        </div>

        <!-- 仕分け一覧・管理テーブル -->
        <div class="card">
            <div class="card-header">
                <h2>📋 仕分け一覧・確認・再編成</h2>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>原本ファイル</th>
                            <th>ステータス</th>
                            <th>クライアント名（取引先）</th>
                            <th>請求日付</th>
                            <th>支払期日 / 支払日</th>
                            <th>仕分けファイル名 ＆ 保存先</th>
                            <th style="text-align: center;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="jobsTableBody">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 24px; color: #94a3b8;">
                                読み込み中...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PDFプレビューモーダル -->
    <div id="previewModal" class="modal-overlay">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 id="previewTitle">PDFプレビュー</h3>
                <button type="button" id="previewCloseBtn" class="modal-close-btn" title="閉じる (Esc)">&times;</button>
            </div>
            <div class="modal-body">
                <iframe id="previewFrame" src="about:blank"></iframe>
            </div>
        </div>
    </div>

    <!-- トースト通知コンテナ -->
    <div id="toastContainer" class="toast-container"></div>

    <script src="js/app.js?v=<?php echo file_exists(__DIR__ . '/js/app.js') ? filemtime(__DIR__ . '/js/app.js') : time(); ?>"></script>
</body>
</html>
