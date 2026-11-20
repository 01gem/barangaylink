# BarangayLink on Laragon

How BarangayLink runs on Laragon (Windows), what the working setup on the development PC looks
like, and how to set it up again on another PC. The generic setup is in `README.md` → Setup;
this file maps it onto Laragon.

Last verified: 2026-11-20.

---

## 1. Working setup (development PC)

| Item | Setting |
|---|---|
| Project folder | `C:\laragon\www\barangaylink` (the only project in `www`) |
| URL | `http://localhost/barangaylink/` (Auto virtual hosts off; the app uses relative URLs) |
| Apache vhost | `C:\laragon\etc\apache2\sites-enabled\00-default.conf`: `AllowOverride All` on `C:/laragon/www`, so `.htaccess` is enforced |
| PHP | 8.3.30 (`C:\laragon\etc\apache2\mod_php.conf`) |
| PHP extensions | `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `mysqli`, `openssl`, `zip`; `curl.cainfo="C:\laragon\etc\ssl\cacert.pem"` |
| PHP time zone | `date.timezone = Asia/Manila` |
| PHP error log | `C:\laragon\tmp\php_errors.log` |
| MySQL | 8.4.3, port 3306, user `root`, empty password |
| Database | `barangay_system_test_environment` |
| LibreOffice | `C:\Program Files\LibreOffice\program\soffice.exe` (default; `LIBREOFFICE_PATH` not needed) |
| Complaint photos | `C:\laragon\private\barangaylink\complaint_attachments` (outside `www`) |
| Pickup SMS dispatcher | Task Scheduler task **BarangayLink Pickup Dispatcher**, every minute, runs `C:\laragon\var\BarangayLink\Capstone-System-COPY\Run-PickupDispatcher.ps1` |
| Dispatcher config | `C:\laragon\var\BarangayLink\Capstone-System-COPY\dispatcher-config.json` (`appRoot: C:/laragon/www/barangaylink`) |
| Dispatcher log | `C:\laragon\var\BarangayLink\Capstone-System-COPY\logs\dispatcher-runs.jsonl` |
| Backups | `C:\laragon\backups\` (database dumps, old `Capstone-System` copy) |

The `C:\laragon\var\BarangayLink\Capstone-System-COPY\` folder keeps its old name on purpose:
the scheduled task points at the script inside it.

---

## 2. `.env` (project root)

Copy `.env.example` to `.env`. Keys used on Laragon:

```
# Database: optional, these are the defaults and match stock Laragon
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=barangay_system_test_environment

# SMS (httpSMS)
HTTPSMS_API_KEY=...
HTTPSMS_FROM_NUMBER=+639XXXXXXXXX
HTTPSMS_SIM_SLOT=SIM1

# AI (Gemini)
GEMINI_API_KEY=...

# Password reset: random, at least 32 characters
PASSWORD_RESET_HINT_KEY=...

# Complaint photos: absolute path OUTSIDE C:\laragon\www
COMPLAINT_ATTACHMENT_STORAGE_DIR=C:/laragon/private/barangaylink/complaint_attachments

# Optional: only if LibreOffice is not in the default folder
LIBREOFFICE_PATH=
```

Rules:

- Every line needs `KEY=value`. A line without a name is silently ignored.
- Generate the reset key in Laragon → Terminal: `php -r "echo bin2hex(random_bytes(32));"`
- `.env` is read on every request; no restart is needed after editing it.
- Never commit or share `.env` (it is in `.gitignore`).

---

## 3. PHP settings

Laragon → Menu → PHP → php.ini opens the `php.ini` of the active version.

1. Time zone: `date.timezone = Asia/Manila` (remove the leading `;`).
2. Extensions on: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `mysqli`, `openssl`, `zip`.
3. `curl.cainfo="C:\laragon\etc\ssl\cacert.pem"` (needed for Gemini and httpSMS over HTTPS).
4. Upload limits: the defaults `upload_max_filesize=4M` / `post_max_size=8M` cover templates
   (2 MB cap). Raise to `10M` / `20M` if complaint photo uploads fail.
5. Laragon → **Stop** → **Start All** after any change.

Check: `php -r "echo date_default_timezone_get();"` prints `Asia/Manila`.

**Switching PHP version:** Laragon then loads that version's own `php.ini`. On the development PC,
8.3.33's `php.ini` loads only `mysqli` and `openssl`, and 8.3.36 has none. Copy the settings above
into the new version's `php.ini` first, and update `phpExe` in `dispatcher-config.json`.

---

## 4. Database

Use Laragon → Terminal (`mysql` and `mysqldump` are on its PATH). In plain PowerShell use the full
path `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\` and wrap redirects in `cmd /c "..."`
(PowerShell `>` writes UTF-16).

Back up before any change:

```
cmd /c "mysqldump -uroot barangay_system_test_environment > C:\laragon\backups\barangaylink-YYYYMMDD.sql"
```

**Existing database:** never re-import `database.sql`. Apply only missing scripts from
`project-support\migrations\`, oldest first:

```
cd C:\laragon\www\barangaylink
cmd /c "mysql -uroot barangay_system_test_environment < project-support\migrations\YYYYMMDD_name.sql"
```

**Fresh install:**

```
cd C:\laragon\www\barangaylink
mysql -uroot -e "CREATE DATABASE barangay_system_test_environment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uroot --default-character-set=utf8mb4 barangay_system_test_environment -e "source database/database.sql"
```

Demo households and residents (test databases only):

```
mysql -uroot --default-character-set=utf8mb4 barangay_system_test_environment -e "source database/test-resident-data(for demonstration).sql"
```

It deletes households 1-35 and residents 1001-1100 before inserting. Never run it on a database
with real residents in those ids.

Default admin from `database.sql`: username `choogi`; set your own bcrypt hash before importing
(see the comment above the INSERT).

HeidiSQL (Laragon → Database): host `127.0.0.1`, user `root`, no password.

Test runs create scratch databases named `capstone_*`. Drop leftovers after a backup:

```
mysql -uroot -e "DROP DATABASE capstone_xxx"
```

---

## 5. Complaint photo folder

1. Create `C:\laragon\private\barangaylink\complaint_attachments` (outside `www`).
2. Set `COMPLAINT_ATTACHMENT_STORAGE_DIR` in `.env` to that path.
3. To move it later: Laragon → **Stop**, `Move-Item` the `complaint_attachments` folder to a
   destination that does **not** exist yet (otherwise Windows nests it inside), update `.env`,
   **Start All**, open a complaint with photos.

The database stores photo paths relative to this setting, so moving the folder and updating `.env`
keeps existing photos working.

---

## 6. Pickup SMS dispatcher (Task Scheduler)

Retries failed pickup SMS (5 / 15 / 60 / 180 minutes). It needs Windows logged in and Laragon's
MySQL running.

`dispatcher-config.json`:

```json
{
  "mode": "live",
  "appRoot": "C:/laragon/www/barangaylink",
  "phpExe": "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe",
  "logFile": "C:\\laragon\\var\\BarangayLink\\Capstone-System-COPY\\logs\\dispatcher-runs.jsonl",
  "pythonExe": "C:\\laragon\\bin\\python\\python-3.13\\python.exe"
}
```

Set `"mode": "paused"` to stop sending without removing the task.

Create the task (Windows PowerShell, no admin needed):

```powershell
$script = 'C:\laragon\var\BarangayLink\Capstone-System-COPY\Run-PickupDispatcher.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$script`""
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName 'BarangayLink Pickup Dispatcher' -Action $action -Trigger $trigger -Settings $settings -Description 'Retries failed pickup SMS for BarangayLink'
```

Run once now: `Start-ScheduledTask -TaskName 'BarangayLink Pickup Dispatcher'`
Turn off: `Disable-ScheduledTask -TaskName 'BarangayLink Pickup Dispatcher'`

Check the last line of `dispatcher-runs.jsonl`. `"exitCode":0` alone is not enough: the `output`
must not contain `Database connection failed.` An empty `output` means it ran and nothing was due.

The wrapper's source copy is `project-support\scripts\run-pickup-dispatcher.ps1`.

---

## 7. Troubleshooting

| Symptom | Where to look | Usual cause |
|---|---|---|
| Password reset code never arrives | `C:\laragon\tmp\php_errors.log` | `no account`: wrong account type, username or number, or resident inactive. The screen looks the same either way on purpose. |
| No masked phone hint on reset | same log: `hint key missing or short` | `PASSWORD_RESET_HINT_KEY` missing, unnamed or under 32 characters |
| Console SMS not sent | `SELECT * FROM sms_log ORDER BY id DESC LIMIT 10;` | `reason`: `pref_off`, `no_contact`, `invalid_contact`, `daily_cap`, `sms_not_configured`, or httpSMS error (sending phone offline, no load) |
| "LibreOffice executable is unavailable" | — | LibreOffice not in the default folder; set `LIBREOFFICE_PATH` |
| Complaint photos missing | `.env` | `COMPLAINT_ATTACHMENT_STORAGE_DIR` points at the wrong or a nested folder |
| `.env` or `README.md` opens in the browser | `00-default.conf` | `AllowOverride` is not `All`, so `.htaccess` is ignored |
| Times off by 8 hours | `php.ini` | `date.timezone` not set |
| Folder will not move ("access rights") | — | Open in VS Code, GitHub Desktop, a terminal or Explorer; close it or restart Windows |

---

## 8. Check after setup

1. Laragon → **Start All** (Apache and MySQL running).
2. `http://localhost/barangaylink/` loads.
3. `http://localhost/barangaylink/.env` and `/README.md` return **403**.
4. Official → Requests → generate a document → the PDF opens.
5. A complaint with photos shows its photos.
6. Forgot password with a real resident username → the code arrives and a masked hint shows.
7. `php -r "echo date_default_timezone_get();"` → `Asia/Manila`.
8. Latest dispatcher log line has empty `output` (or sent counts), no connection error.
