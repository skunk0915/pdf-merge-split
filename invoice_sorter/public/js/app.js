/**
 * PDF Invoice Sorter - Client Application Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM要素
    const dropZone = document.getElementById('dropZone');
    const pdfFileInput = document.getElementById('pdfFileInput');
    const jobsTableBody = document.getElementById('jobsTableBody');
    const announcementBanner = document.getElementById('announcementBanner');
    const bannerJobCount = document.getElementById('bannerJobCount');
    const statTotal = document.getElementById('statTotal');
    const statProcessing = document.getElementById('statProcessing');
    const statQueued = document.getElementById('statQueued');
    const statCompleted = document.getElementById('statCompleted');
    const namingPatternSelect = document.getElementById('namingPatternSelect');
    const folderPatternSelect = document.getElementById('folderPatternSelect');
    const previewModal = document.getElementById('previewModal');
    const previewFrame = document.getElementById('previewFrame');
    const previewTitle = document.getElementById('previewTitle');
    const previewCloseBtn = document.getElementById('previewCloseBtn');

    let currentJobs = [];
    let isPolling = true;
    let pollTimer = null;

    // ==========================================
    // トースト通知表示
    // ==========================================
    function showToast(message, type = 'success') {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.remove();
        }, 3500);
    }

    // ==========================================
    // 設定の読み込み & 更新
    // ==========================================
    async function loadSettings() {
        try {
            const res = await fetch('api.php?action=get_settings');
            const data = await res.json();
            if (data.success && data.settings) {
                if (namingPatternSelect && data.settings.naming_pattern) {
                    namingPatternSelect.value = data.settings.naming_pattern;
                }
                if (folderPatternSelect && data.settings.folder_pattern) {
                    folderPatternSelect.value = data.settings.folder_pattern;
                }
            }
        } catch (e) {
            console.error('Failed to load settings:', e);
        }
    }

    async function saveSettings() {
        try {
            const res = await fetch('api.php?action=update_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    naming_pattern: namingPatternSelect.value,
                    folder_pattern: folderPatternSelect.value
                })
            });
            const data = await res.json();
            if (data.success) {
                showToast('設定を保存しました。新規仕分けおよび再配置時に適用されます。');
            }
        } catch (e) {
            showToast('設定の保存に失敗しました。', 'error');
        }
    }

    if (namingPatternSelect) namingPatternSelect.addEventListener('change', saveSettings);
    if (folderPatternSelect) folderPatternSelect.addEventListener('change', saveSettings);

    // ==========================================
    // ジョブ一覧・ステータスポーリング
    // ==========================================
    async function fetchJobs() {
        try {
            const res = await fetch('api.php?action=list');
            const data = await res.json();
            if (data.success) {
                currentJobs = data.jobs || [];
                renderSummary(data.summary);
                renderTable(currentJobs);
            }
        } catch (e) {
            console.error('Failed to fetch jobs:', e);
        } finally {
            if (isPolling) {
                clearTimeout(pollTimer);
                pollTimer = setTimeout(fetchJobs, 3000); // 3秒ごとに定期更新
            }
        }
    }

    // サマリー・リアルタイムアナウンスの反映
    function renderSummary(summary) {
        if (!summary) return;
        if (statTotal) statTotal.textContent = summary.total;
        if (statProcessing) statProcessing.textContent = summary.processing;
        if (statQueued) statQueued.textContent = summary.queued;
        if (statCompleted) statCompleted.textContent = summary.completed;

        const activeCount = (summary.processing || 0) + (summary.queued || 0);
        if (activeCount > 0) {
            if (announcementBanner) {
                announcementBanner.style.display = 'block';
                if (bannerJobCount) {
                    bannerJobCount.textContent = `現在 ${activeCount} 件のPDFをAIが自動仕分け中です（処理中: ${summary.processing}件、待機中: ${summary.queued}件）。画面を閉じても安全に作業は継続されます。`;
                }
            }
        } else {
            if (announcementBanner) announcementBanner.style.display = 'none';
        }
    }

    // テーブル描画
    function renderTable(jobs) {
        if (!jobsTableBody) return;
        if (jobs.length === 0) {
            jobsTableBody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 36px; color: #94a3b8;">
                        スキャンPDFがまだアップロードされていません。上のドロップゾーンから投入してください。
                    </td>
                </tr>
            `;
            return;
        }

        // 既存の入力中フォーカス状態を保持するため、フォーカス中のIDを退避
        const activeEl = document.activeElement;
        const focusedJobId = activeEl ? activeEl.getAttribute('data-job-id') : null;
        const focusedField = activeEl ? activeEl.getAttribute('data-field') : null;

        const html = jobs.map(job => {
            const isProcessing = (job.status === 'processing');
            const isQueued = (job.status === 'queued');
            const isLocked = isProcessing; // AI処理中は操作をロックしてバグを防ぐ

            let statusBadge = '';
            if (job.status === 'queued') {
                statusBadge = '<span class="status-badge status-queued">⏳ 待機中</span>';
            } else if (job.status === 'processing') {
                statusBadge = '<span class="status-badge status-processing">🔄 AI解析中...</span>';
            } else if (job.status === 'completed') {
                statusBadge = '<span class="status-badge status-completed">✅ 仕分け完了</span>';
            } else if (job.status === 'needs_review') {
                statusBadge = '<span class="status-badge status-needs_review">⚠️ 要確認</span>';
            } else if (job.status === 'error') {
                statusBadge = '<span class="status-badge status-error" title="' + escapeHtml(job.error_message || '') + '">❌ エラー</span>';
            }

            const clientVal = escapeHtml(job.client_name || '');
            const invDateVal = escapeHtml(job.invoice_date || '');
            const payDateVal = escapeHtml(job.payment_date || '');
            const sortedName = escapeHtml(job.sorted_filename || (isProcessing ? 'AI解析中...' : '未仕分け'));
            const folderStr = escapeHtml(job.folder_structure || '');

            return `
                <tr class="${isProcessing ? 'is-processing' : ''}" id="job-row-${job.id}">
                    <td>
                        <div style="font-weight: 600; color: #1e293b;">${escapeHtml(job.original_filename)}</div>
                        <div style="font-size: 11px; color: #64748b;">ID: #${job.id} / ${escapeHtml(job.created_at)}</div>
                    </td>
                    <td>${statusBadge}</td>
                    <td>
                        <input type="text" class="inline-input" value="${clientVal}" 
                               data-job-id="${job.id}" data-field="client_name" 
                               placeholder="クライアント名" ${isLocked ? 'disabled' : ''}>
                    </td>
                    <td>
                        <input type="text" class="inline-input" value="${invDateVal}" 
                               data-job-id="${job.id}" data-field="invoice_date" 
                               placeholder="YYYY-MM-DD" style="max-width: 120px;" ${isLocked ? 'disabled' : ''}>
                    </td>
                    <td>
                        <input type="text" class="inline-input" value="${payDateVal}" 
                               data-job-id="${job.id}" data-field="payment_date" 
                               placeholder="YYYY-MM-DD" style="max-width: 120px;" ${isLocked ? 'disabled' : ''}>
                    </td>
                    <td>
                        <div style="font-weight: 600; font-size: 12px; color: #1e293b;">${sortedName}</div>
                        ${folderStr ? `<div style="font-size: 11px; color: #2563eb;">📁 ${folderStr}</div>` : ''}
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button type="button" class="btn btn-secondary" onclick="openPreview(${job.id}, '${escapeHtml(job.original_filename)}')" title="PDFプレビュー">
                                👁️ プレビュー
                            </button>
                            <button type="button" class="btn btn-success" onclick="saveJobEdit(${job.id})" ${isLocked ? 'disabled' : ''} title="手動編集内容を確定して再配置">
                                💾 保存
                            </button>
                            <button type="button" class="btn btn-primary" onclick="requeueJob(${job.id})" ${isLocked ? 'disabled' : ''} title="AIで再度仕分け・解析">
                                🔄 再仕分け
                            </button>
                            <a href="download.php?id=${job.id}&type=sorted" class="btn btn-secondary" download title="仕分け後PDFをダウンロード" ${isProcessing ? 'style="pointer-events:none; opacity:0.5;"' : ''}>
                                ⬇️
                            </a>
                            <button type="button" class="btn btn-danger" onclick="deleteJob(${job.id})" ${isLocked ? 'disabled' : ''} title="削除">
                                🗑️
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        jobsTableBody.innerHTML = html;

        // フォーカス復元
        if (focusedJobId && focusedField) {
            const target = document.querySelector(`input[data-job-id="${focusedJobId}"][data-field="${focusedField}"]`);
            if (target) {
                target.focus();
            }
        }
    }

    // ==========================================
    // ファイルアップロード (D&D & クリック)
    // ==========================================
    if (dropZone && pdfFileInput) {
        dropZone.addEventListener('click', () => {
            pdfFileInput.click();
        });

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                uploadFiles(e.dataTransfer.files);
            }
        });

        pdfFileInput.addEventListener('change', () => {
            if (pdfFileInput.files && pdfFileInput.files.length > 0) {
                uploadFiles(pdfFileInput.files);
                pdfFileInput.value = ''; // リセット
            }
        });
    }

    async function uploadFiles(files) {
        const formData = new FormData();
        let pdfCount = 0;
        for (let i = 0; i < files.length; i++) {
            if (files[i].type === 'application/pdf' || files[i].name.toLowerCase().endsWith('.pdf')) {
                formData.append('pdfFiles[]', files[i]);
                pdfCount++;
            }
        }

        if (pdfCount === 0) {
            showToast('PDFファイルを選択してください。', 'error');
            return;
        }

        showToast(`${pdfCount} 件のPDFをアップロード中...`);
        try {
            const res = await fetch('api.php?action=upload', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                fetchJobs(); // 即座に再取得
            } else {
                showToast(data.error || 'アップロードに失敗しました。', 'error');
            }
        } catch (e) {
            showToast('アップロード通信エラーが発生しました。', 'error');
        }
    }

    // ==========================================
    // ジョブ編集保存 (手動修正)
    // ==========================================
    window.saveJobEdit = async function(jobId) {
        const row = document.getElementById(`job-row-${jobId}`);
        if (!row) return;

        const clientInput = row.querySelector('input[data-field="client_name"]');
        const invDateInput = row.querySelector('input[data-field="invoice_date"]');
        const payDateInput = row.querySelector('input[data-field="payment_date"]');

        const payload = {
            id: jobId,
            client_name: clientInput ? clientInput.value : '',
            invoice_date: invDateInput ? invDateInput.value : '',
            payment_date: payDateInput ? payDateInput.value : ''
        };

        try {
            const res = await fetch('api.php?action=update_job', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
                fetchJobs();
            } else {
                showToast(data.error || '保存に失敗しました。', 'error');
            }
        } catch (e) {
            showToast('保存エラーが発生しました。', 'error');
        }
    };

    // ==========================================
    // AI再仕分け (キューに戻す)
    // ==========================================
    window.requeueJob = async function(jobId) {
        try {
            const formData = new FormData();
            formData.append('id', jobId);
            const res = await fetch('api.php?action=requeue', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                showToast('AI再仕分けキューに投入しました。');
                fetchJobs();
            } else {
                showToast(data.error || '再仕分けの要求に失敗しました。', 'error');
            }
        } catch (e) {
            showToast('通信エラーが発生しました。', 'error');
        }
    };

    // ==========================================
    // ジョブ削除
    // ==========================================
    window.deleteJob = async function(jobId) {
        if (!confirm('このジョブおよび保存ファイルを削除してもよろしいですか？')) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('id', jobId);
            const res = await fetch('api.php?action=delete_job', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
                fetchJobs();
            } else {
                showToast(data.error || '削除に失敗しました。', 'error');
            }
        } catch (e) {
            showToast('削除エラーが発生しました。', 'error');
        }
    };

    // ==========================================
    // プレビューモーダル
    // ==========================================
    window.openPreview = function(jobId, filename) {
        if (previewTitle) previewTitle.textContent = `PDFプレビュー: ${filename}`;
        if (previewFrame) {
            previewFrame.src = `download.php?id=${jobId}&type=sorted&mode=inline`;
        }
        if (previewModal) previewModal.classList.add('is-active');
    };

    function closePreview() {
        if (previewModal) previewModal.classList.remove('is-active');
        if (previewFrame) previewFrame.src = 'about:blank';
    }

    if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreview);
    if (previewModal) {
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) closePreview();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && previewModal && previewModal.classList.contains('is-active')) {
            closePreview();
        }
    });

    // 文字列エスケープ
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // 初回初期化
    loadSettings();
    fetchJobs();
});
