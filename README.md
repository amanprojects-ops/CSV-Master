# CSV Master - High Speed Bulk Data Cleaner & JSON Extractor

A high-performance, **Zero-RAM PHP Streaming Engine** designed to extract, clean, validate, and organize **Millions of Email IDs and Mobile Numbers** from messy database backups, even on low-end laptops.

---

## 📁 Directory Structure

```text
csv_master/
├── input/                 # Put your raw files here (.csv, .xlsx, .xls, .txt, .sql, .log)
├── output/                # Cleaned, categorized results organized by Date & Country
│   └── YYYY-MM-DD/
│       ├── emails/
│       │   ├── India/
│       │   │   └── India_emails_part1.json
│       │   └── USA_Canada/
│       ├── phones/
│       │   ├── India/
│       │   │   └── India_phones_part1.json
│       │   └── UAE/
│       └── collection_summary.json
├── web_client/            # Browser UI files (Glassmorphism Web Dashboard)
├── cleaner.php            # Core PHP Zero-RAM Extraction & Processing Engine
├── run.bat                # 1-Click Double-Click Launcher for Windows
└── README.md
```

---

## 🚀 How to Use (1-Click)

1. Put your raw files (`.csv`, `.txt`, `.xlsx`, `.xls`, `.sql`) inside the `input/` folder.
2. Double-click **`run.bat`** (or run `php cleaner.php` in terminal).
3. Check your clean, deduplicated JSON collections in the `output/` folder!

---

## 📦 JSON Collection Schema

### Emails (`emails/<Country>/<Country>_emails_partX.json`)
```json
[
  {
    "email": "user@gmail.com",
    "domain": "gmail.com",
    "country": "India",
    "input_file_name": "backup.xls",
    "extracted_date": "2026-10-15 19:15:30"
  }
]
```

### Phones (`phones/<Country>/<Country>_phones_partX.json`)
```json
[
  {
    "phone": "+919876543210",
    "country": "India",
    "country_code": "+91",
    "input_file_name": "backup.xls",
    "extracted_date": "2026-10-15 19:15:30"
  }
]
```

---

## ⚡ Key Highlights
* **Zero-RAM Streaming:** Consistently stays under 15MB - 30MB RAM even with 50+ Million rows.
* **Native BIFF8 `.xls` & `.xlsx` Support:** Directly parses binary Excel files and OpenXML streams without third-party bloatware.
* **Country-wise Categorization:** Automatically groups by dial code and ccTLDs.
* **Deduplication:** Automatic disk-based SQLite indexing drops duplicates on the fly.
