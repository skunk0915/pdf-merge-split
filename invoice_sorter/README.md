# スキャンPDF自動仕分け・請求書管理システム

スキャンした紙の請求書・領収書PDFをAI（Antigravity CLI: `agy`）で自動解析し、クライアント別・年月別フォルダへ自動仕分け・リネームして保存するシステムです。

---

## 1. システム構成・URL

- **WebダッシュボードURL**: `https://invoice-sort.162.43.39.238.sslip.io/`
- **セキュリティ設定**:
  - **Basic認証**:
    - ユーザー名: `outward`
    - パスワード: `infoito15`
  - **IPアドレス制限**: `122.213.201.14` およびローカル環境（`127.0.0.1`）からのみアクセス許可
  - **機密データ保護**: アップロード原本および仕分け後PDFはWeb公開ディレクトリ（DocumentRoot）外の `storage/` 配下に格納され、認証済みPHP経由でのみプレビュー・ダウンロードが可能（URL直打ちでの漏洩を完全防止）。
- **デプロイ先サーバー**: `xserver-vps-ow`（`162.43.39.238`）
  - ※既存のカゴヤ・PDF結合分割サービス（`pdf-merge-split.162.43.39.238.sslip.io`）とはディレクトリ（`/var/www/pdf-invoice-sorter`）もNginx設定・SSL証明書も完全に独立しており、一切影響しません。

---

## 2. 運用フロー（人がやるのは紙スキャンだけ）

```
[紙の請求書・領収書]
       ↓ (スキャナーで読み取り)
[PDF投入]
   ・Web画面から直接ドラッグ＆ドロップ（一括投入可能）
   ・またはスキャナーからGoogle Driveの「未処理」フォルダへ自動送信
       ↓
[ローカルAIワーカー (~/.local/bin/agy)]
   ・待機中ジョブを自動検知して取り込み
   ・Gemini Flash最新モデル (--model flash-high) で高精度解析
   ・「クライアント名（発行元/取引先）」「請求日付」「支払期日」をJSON抽出
       ↓
[自動仕分け ＆ リネーム保存]
   ・xserver-vps-ow の指定フォルダへ自動格納
   ・Google Drive / GCP Cloud Storage へも自動同期
       ↓
[Web画面で確認・編集]
   ・仕分け結果の一覧表示、高解像度プレビュー
   ・手動修正（クライアント名や日付の変更）によるフォルダ再配置
   ・「AI再仕分け」ボタンによるワンクリック再解析
```

---

## 3. ローカルAIワーカーの起動

AI解析は手元のMac上の Antigravity CLI サブスクリプション（`~/.local/bin/agy`）を利用して行います（API従量課金ゼロ）。

```bash
cd /Volumes/8TB/pj外部/pdf-merge-split/invoice_sorter
./start_worker.sh
```

- 起動すると、VPS上のキューを5秒周期で自動監視します。
- スキャンPDFが投入されると自動で解析・仕分けを実行し、VPSへ即時反映します。
- 作業中にブラウザを閉じても、バックグラウンドですべて自動進行します。
- ログは `storage/logs/worker.log` に自動記録されます（10MBローテーション対応）。

---

## 4. 命名規則・フォルダ構成の仕様

画面上の設定パネルから即座に切り替え可能です：

### ファイル名命名順序
1. `請求日_クライアント名_支払日.pdf`（例: `2026-11-12_株式会社グローバルパートナーズ_2026-11-30.pdf`）
2. `クライアント名_請求日_支払日.pdf`
3. `請求日_クライアント名.pdf`
4. `クライアント名_請求日.pdf`
5. `支払日_クライアント名_請求日.pdf`

### フォルダ階層
- **取引先別（標準）**: `{クライアント名}/{年月(YYYY-MM)}/`
- **年月別**: `{年月(YYYY-MM)}/{クライアント名}/`

※手動でクライアント名や日付を修正して「💾 保存」を押すと、旧フォルダのファイルは自動クリーンアップされ、新しい命名・フォルダへ瞬時に再配置されます。

---

## 5. Google Drive / GCP 連携設定

`.env` に認証情報を設定することで、仕分け完了後のPDFをGoogle DriveやGCP Cloud Storage（GCS）へ自動同期できます。

```ini
# Google Drive設定
GDRIVE_SERVICE_ACCOUNT_JSON=/path/to/service_account.json
GDRIVE_INBOX_FOLDER_ID=your_inbox_folder_id
GDRIVE_SORTED_FOLDER_ID=your_sorted_folder_id

# GCP Cloud Storage設定
GCP_PROJECT_ID=your_project_id
GCP_STORAGE_BUCKET=your_bucket_name
GCP_CREDENTIALS_JSON=/path/to/gcp_credentials.json
```

---

## 6. 再デプロイ方法

画面UIやAPIを修正した場合は、以下のスクリプトでVPS（`/var/www/pdf-invoice-sorter`）へワンコマンドで安全にデプロイできます：

```bash
./invoice_sorter/deploy.sh
```
