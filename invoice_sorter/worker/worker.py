#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PDF Invoice Sorter - Local Background AI Worker
Runs locally on macOS, monitors VPS jobs, analyzes invoices via Antigravity CLI (agy),
and syncs sorted files back to VPS and Cloud Storage.
"""

import os
import sys
import time
import json
import base64
import re
import shutil
import tempfile
import subprocess
import logging
from logging.handlers import RotatingFileHandler
from pathlib import Path
import requests

# パス定義
BASE_DIR = Path(__file__).resolve().parent.parent
LOGS_DIR = BASE_DIR / "storage" / "logs"
ENV_FILE = BASE_DIR / ".env"

# ログディレクトリ確保
LOGS_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOGS_DIR / "worker.log"

# ロガー設定（10MBローテーション対応、最大3世代バックアップ）
logger = logging.getLogger("InvoiceWorker")
logger.setLevel(logging.INFO)
formatter = logging.Formatter("[%(asctime)s] [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")

file_handler = RotatingFileHandler(
    LOG_FILE,
    maxBytes=10 * 1024 * 1024, # 10MB
    backupCount=3,
    encoding="utf-8"
)
file_handler.setFormatter(formatter)
logger.addHandler(file_handler)

stream_handler = logging.StreamHandler(sys.stdout)
stream_handler.setFormatter(formatter)
logger.addHandler(stream_handler)


def load_env():
    """環境変数（.env）を読み込む"""
    env_vars = {}
    if ENV_FILE.exists():
        with open(ENV_FILE, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    key, val = line.split("=", 1)
                    env_vars[key.strip()] = val.strip()
    return env_vars

ENV = load_env()

# 設定値
AGY_MODEL = ENV.get("AGY_MODEL", "flash-high")
VPS_API_URL = ENV.get("VPS_API_URL", "https://invoice-sort.162.43.39.238.sslip.io/api.php")
VPS_BASIC_USER = ENV.get("VPS_BASIC_USER", "outward")
VPS_BASIC_PASS = ENV.get("VPS_BASIC_PASS", "infoito15")
AGY_BIN = Path(os.path.expanduser("~/.local/bin/agy"))

# 1件あたりの最大試行回数（往復・無限ループ防止）
MAX_RETRIES_PER_JOB = 3
job_retry_counts = {}


def get_vps_auth():
    """Basic認証オブジェクト"""
    if VPS_BASIC_USER and VPS_BASIC_PASS:
        return (VPS_BASIC_USER, VPS_BASIC_PASS)
    return None


def extract_pdf_content(pdf_path: Path):
    """
    PDFからテキストまたは画像を抽出する
    """
    text_content = ""
    try:
        from pypdf import PdfReader
        reader = PdfReader(str(pdf_path))
        if len(reader.pages) > 0:
            for page in reader.pages[:3]: # 先頭最大3ページ
                txt = page.extract_text() or ""
                text_content += txt + "\n"
    except Exception as e:
        logger.warning(f"pypdf extraction failed or partial: {e}")

    # テキストが一定量取得できた場合はテキストを返す
    clean_text = text_content.strip()
    if len(clean_text) >= 40:
        return {"mode": "text", "content": clean_text}

    # スキャン画像PDFの場合: qlmanageでPNG画像を生成
    temp_dir = Path(tempfile.mkdtemp(prefix="pdf_thumb_"))
    try:
        cmd = ["qlmanage", "-t", "-s", "1400", "-o", str(temp_dir), str(pdf_path)]
        subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        # 生成された画像
        images = list(temp_dir.glob("*.png"))
        if images:
            return {"mode": "image", "image_path": images[0], "temp_dir": temp_dir}
    except Exception as e:
        logger.warning(f"qlmanage thumbnail generation failed: {e}")

    return {"mode": "text", "content": clean_text}


def run_agy_analysis(content_info: dict, filename: str) -> dict:
    """
    Antigravity CLI (agy) を呼び出して請求書情報を抽出する
    モデル名は必ず .env の AGY_MODEL を使用する（ルール遵守）
    """
    if not AGY_BIN.exists():
        raise FileNotFoundError(f"Antigravity CLI not found at {AGY_BIN}")

    prompt = (
        "あなたは日本のビジネス請求書・領収書を正確に解析するAIアシスタントです。\n"
        f"対象ファイル: {filename}\n"
    )

    image_temp_dir = None
    if content_info["mode"] == "image":
        img_path = content_info["image_path"]
        image_temp_dir = content_info.get("temp_dir")
        prompt += (
            f"画像ファイル: {img_path}\n"
            "上記の請求書画像を視覚的に詳細確認し、記載された情報を読み取ってください。\n"
        )
    else:
        prompt += (
            "【抽出テキスト】\n"
            f"{content_info['content'][:3000]}\n\n"
        )

    prompt += (
        "以下の4つの項目を正確に抽出し、指定のJSONフォーマットのみを出力してください。\n"
        "1. client_name: 取引先・クライアント企業名/屋号（※請求書の発行元会社名、または請求書に記載された主要な取引先企業名。株式会社や合同会社等の法人格も含めて正式名で抽出してください）\n"
        "2. invoice_date: 請求日付または発行日（YYYY-MM-DD形式。和暦の場合は西暦に変換。例: 令和8年11月10日 -> 2026-11-10）\n"
        "3. payment_date: 支払期日・お支払期限・振込期日（YYYY-MM-DD形式。不明または記載なしの場合は null）\n"
        "4. confidence: 抽出の確信度（0.0〜1.0の数値）\n\n"
        "【出力ルール】\n"
        "・余計な挨拶、説明文、Markdown記法（```json など）は絶対に含めず、純粋な1つのJSONオブジェクトのみを出力してください。\n"
        '{"client_name": "株式会社サンプル", "invoice_date": "2026-11-10", "payment_date": "2026-11-30", "confidence": 0.95}'
    )

    # agy コマンド構築
    # agy -p <prompt> --model <AGY_MODEL> --disable-slash-commands
    cmd = [
        str(AGY_BIN),
        "-p", prompt,
        "--model", AGY_MODEL,
        "--disable-slash-commands"
    ]

    # 画像がある場合はディレクトリの信頼を追加
    if image_temp_dir:
        cmd.extend(["--add-dir", str(image_temp_dir)])

    logger.info(f"Calling agy with model '{AGY_MODEL}' for {filename}...")
    start_time = time.time()
    
    proc = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=120
    )
    elapsed = time.time() - start_time

    if proc.returncode != 0:
        logger.error(f"agy execution failed (code {proc.returncode}): {proc.stderr}")
        raise RuntimeError(f"agy error: {proc.stderr.strip()}")

    raw_output = proc.stdout.strip()
    logger.info(f"agy completed in {elapsed:.1f}s. Raw output: {raw_output[:200]}...")

    # 一時画像ディレクトリ掃除
    if image_temp_dir and os.path.exists(image_temp_dir):
        shutil.rmtree(image_temp_dir, ignore_errors=True)

    # JSONパース
    # マークダウンコードブロックが万一含まれていた場合の除去
    cleaned_json_text = re.sub(r"^```(?:json)?\s*", "", raw_output, flags=re.MULTILINE)
    cleaned_json_text = re.sub(r"\s*```$", "", cleaned_json_text, flags=re.MULTILINE).strip()

    # JSONオブジェクト部分を抽出
    match = re.search(r"\{.*\}", cleaned_json_text, re.DOTALL)
    if not match:
        raise ValueError(f"Could not parse valid JSON from agy response: {raw_output}")

    parsed = json.loads(match.group(0))
    parsed["raw_output"] = raw_output
    return parsed


def poll_vps():
    """
    VPSから未処理（queued）ジョブを1件取得する
    """
    url = f"{VPS_API_URL}?action=worker_poll"
    try:
        resp = requests.get(url, auth=get_vps_auth(), timeout=15)
        if resp.status_code == 200:
            data = resp.json()
            if data.get("success") and data.get("has_job"):
                return data["job"], data.get("pdf_base64")
    except Exception as e:
        logger.error(f"Error polling VPS: {e}")
    return None, None


def complete_vps_job(job_id: int, success: bool, payload: dict):
    """
    VPSへ解析結果を報告する
    """
    url = f"{VPS_API_URL}?action=worker_complete"
    payload["id"] = job_id
    payload["success"] = success

    try:
        resp = requests.post(
            url,
            auth=get_vps_auth(),
            json=payload,
            headers={"Content-Type": "application/json"},
            timeout=20
        )
        if resp.status_code == 200:
            data = resp.json()
            logger.info(f"VPS job #{job_id} completion reported: {data.get('message')}")
            return True
        else:
            logger.error(f"VPS complete failed with status {resp.status_code}: {resp.text}")
    except Exception as e:
        logger.error(f"Error notifying VPS completion: {e}")
    return False


def process_single_job(job: dict, pdf_base64: str):
    """
    1件のジョブを処理する
    """
    job_id = job["id"]
    filename = job["original_filename"]
    logger.info(f"===> Processing Job #{job_id}: {filename}")

    # 試行回数チェック（無限往復の防止）
    job_retry_counts[job_id] = job_retry_counts.get(job_id, 0) + 1
    if job_retry_counts[job_id] > MAX_RETRIES_PER_JOB:
        err_msg = f"最大試行回数（{MAX_RETRIES_PER_JOB}回）を超えたため、手動確認に切り替えました。"
        logger.warning(f"Job #{job_id} exceeded max retries. Escalating to needs_review.")
        complete_vps_job(job_id, success=False, payload={"error_message": err_msg})
        return

    # PDFの準備
    temp_pdf = Path(tempfile.gettempdir()) / f"invoice_job_{job_id}.pdf"
    try:
        if pdf_base64:
            with open(temp_pdf, "wb") as f:
                f.write(base64.b64decode(pdf_base64))
        elif os.path.exists(job.get("inbox_path", "")):
            shutil.copy(job["inbox_path"], temp_pdf)
        else:
            raise FileNotFoundError("PDF data is empty and inbox_path not found locally.")

        # 内容抽出
        content_info = extract_pdf_content(temp_pdf)

        # agy 解析
        ai_res = run_agy_analysis(content_info, filename)

        # 結果をVPSに送信
        complete_vps_job(job_id, success=True, payload={
            "client_name": ai_res.get("client_name", "不明取引先"),
            "invoice_date": ai_res.get("invoice_date", ""),
            "payment_date": ai_res.get("payment_date", "") or "",
            "confidence": ai_res.get("confidence", 0.9),
            "raw_output": ai_res.get("raw_output", "")
        })
        logger.info(f"===> Job #{job_id} successfully processed!")

    except Exception as e:
        logger.error(f"Failed to process Job #{job_id}: {e}", exc_info=True)
        complete_vps_job(job_id, success=False, payload={"error_message": str(e)})
    finally:
        if temp_pdf.exists():
            temp_pdf.unlink()


def main():
    logger.info("==================================================")
    logger.info("PDF Invoice Sorter Worker Started")
    logger.info(f"Target VPS: {VPS_API_URL}")
    logger.info(f"AGY Model: {AGY_MODEL}")
    logger.info(f"AGY Binary: {AGY_BIN}")
    logger.info("==================================================")

    idle_count = 0
    while True:
        try:
            job, pdf_b64 = poll_vps()
            if job:
                idle_count = 0
                process_single_job(job, pdf_b64)
            else:
                idle_count += 1
                if idle_count % 12 == 1: # 約1分に1回ログ出力
                    logger.info("Polling for jobs... (Queue is currently empty)")
                time.sleep(5) # 5秒間隔でポーリング
        except KeyboardInterrupt:
            logger.info("Worker stopped by user.")
            break
        except Exception as e:
            logger.error(f"Worker main loop error: {e}", exc_info=True)
            time.sleep(10)


if __name__ == "__main__":
    main()
