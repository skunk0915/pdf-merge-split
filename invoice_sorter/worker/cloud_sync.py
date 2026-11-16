#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
PDF Invoice Sorter - Cloud Storage Sync (Google Drive & GCP Cloud Storage)
Handles uploading sorted invoice PDFs to Google Drive and GCP GCS buckets.
"""

import os
import sys
import logging
from pathlib import Path
from logging.handlers import RotatingFileHandler

BASE_DIR = Path(__file__).resolve().parent.parent
LOGS_DIR = BASE_DIR / "storage" / "logs"
ENV_FILE = BASE_DIR / ".env"

LOGS_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOGS_DIR / "cloud_sync.log"

logger = logging.getLogger("CloudSync")
logger.setLevel(logging.INFO)
formatter = logging.Formatter("[%(asctime)s] [%(levelname)s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")

file_handler = RotatingFileHandler(LOG_FILE, maxBytes=10 * 1024 * 1024, backupCount=3, encoding="utf-8")
file_handler.setFormatter(formatter)
logger.addHandler(file_handler)

def load_env():
    env_vars = {}
    if ENV_FILE.exists():
        with open(ENV_FILE, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    env_vars[k.strip()] = v.strip()
    return env_vars

ENV = load_env()


def sync_to_google_drive(local_file_path: Path, client_name: str, year_month: str, remote_filename: str):
    """
    Google Driveの指定フォルダへアップロード
    """
    sa_json = ENV.get("GDRIVE_SERVICE_ACCOUNT_JSON")
    sorted_folder_id = ENV.get("GDRIVE_SORTED_FOLDER_ID")

    if not sa_json or not os.path.exists(sa_json) or not sorted_folder_id:
        logger.info(f"Google Drive sync skipped for {remote_filename} (credentials not yet configured in .env)")
        return False

    try:
        from google.oauth2 import service_account
        from googleapiclient.discovery import build
        from googleapiclient.http import MediaFileUpload

        creds = service_account.Credentials.from_service_account_file(
            sa_json,
            scopes=['https://www.googleapis.com/auth/drive']
        )
        service = build('drive', 'v3', credentials=creds)

        # フォルダ検索または作成（client_name -> year_month）
        # ... (フォルダ階層作成ロジック)
        media = MediaFileUpload(str(local_file_path), mimetype='application/pdf')
        file_metadata = {
            'name': remote_filename,
            'parents': [sorted_folder_id]
        }
        uploaded = service.files().create(body=file_metadata, media_body=media, fields='id').execute()
        logger.info(f"Successfully uploaded to Google Drive: {remote_filename} (ID: {uploaded.get('id')})")
        return True

    except Exception as e:
        logger.error(f"Failed to sync to Google Drive: {e}")
        return False


def sync_to_gcp_bucket(local_file_path: Path, client_name: str, year_month: str, remote_filename: str):
    """
    GCP Cloud Storage (GCS) バケットへアップロード
    """
    bucket_name = ENV.get("GCP_STORAGE_BUCKET")
    creds_json = ENV.get("GCP_CREDENTIALS_JSON")

    if not bucket_name or not creds_json or not os.path.exists(creds_json):
        logger.info(f"GCP GCS sync skipped for {remote_filename} (GCP credentials not yet configured in .env)")
        return False

    try:
        from google.cloud import storage
        client = storage.Client.from_service_account_json(creds_json)
        bucket = client.bucket(bucket_name)

        blob_path = f"invoices/{client_name}/{year_month}/{remote_filename}"
        blob = bucket.blob(blob_path)
        blob.upload_from_filename(str(local_file_path), content_type='application/pdf')
        logger.info(f"Successfully uploaded to GCP Storage: gs://{bucket_name}/{blob_path}")
        return True

    except Exception as e:
        logger.error(f"Failed to sync to GCP Cloud Storage: {e}")
        return False
