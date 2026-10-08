# imgizer

A **PHP** web page that accepts an image and returns an advanced analysis using a professional toolset:
full EXIF metadata, file structure, embedded/hidden files, steganography analysis,
entropy, and tampering/editing indicators.

---

## Features

- **Full EXIF** via ExifTool, with tag grouping, filtering and search.
- **Exiv2** to read IPTC/XMP/EXIF from a different angle.
- **ImageMagick** for image properties (color space, depth, compression, frame count...).
- **Binwalk** to detect signatures and embedded or appended files.
- **Strings** to extract links, emails and interesting text.
- **Steganography**: zsteg (PNG/BMP) + steghide (JPEG/BMP) + Least Significant Bit (LSB) analysis.
- **File entropy** with an interactive chart to spot encrypted/hidden content.
- **Integrity & tamper detection**: data appended after the end of the image, CRC errors,
  timestamp inconsistencies, stripped thumbnails, editing software and XMP history, stripped EXIF.
- **Privacy**: GPS coordinate extraction with map links, plus sensitive field monitoring.
- Dark English UI, drag & drop, local preview, results download, and session/password protection.

---

## Requirements

| Component | Purpose | Required |
|---|---|---|
| PHP ≥ 8.0 (with `fileinfo` and `exif`) | Runs the app | Yes |
| ExifTool | Read metadata | Yes (core) |
| ImageMagick (`identify`/`magick`) | Image properties and LSB analysis | Yes |
| Exiv2 | Read IPTC/XMP | Recommended |
| Binwalk | Signatures and embedded files | Recommended |
| Binutils (`strings`) | Extract text | Recommended |
| zsteg (Ruby) | PNG/BMP stego | Optional |
| steghide | JPEG/BMP stego | Optional |

> The app still works when optional tools are missing, and shows their status at the top of the page.

---

## Quick Install

```bash
# 1) Clone the project from GitHub on the VPS
git clone https://github.com/<username>/<repo>.git
cd <repo>

# 2) Install dependencies and set up Nginx (Debian/Ubuntu/Kali)
sudo ./install.sh --nginx --domain your-domain.com

# Or without Nginx (testing only)
sudo ./install.sh
php -S 0.0.0.0:8080 -t public
```

Then open your domain in a browser.

---

## Deploying via GitHub

```bash
# On your local machine: push the project
git init
git add .
git commit -m "Initial commit: image metadata analyzer"
git branch -M main
git remote add origin https://github.com/<username>/<repo>.git
git push -u origin main
```

```bash
# On the VPS: fetch the project
git clone https://github.com/<username>/<repo>.git
cd <repo>
sudo ./install.sh --nginx --domain your-domain.com

# For later updates
git pull && sudo systemctl reload php*-fpm nginx
```

---

## Configuration

All settings live in `config/config.php`. To override them without touching the repo,
create `config/config.local.php` (git-ignored) that returns an array of overrides:

```php
<?php
return [
    // 'access_password' => 'a-strong-password',
    'max_upload_bytes' => 25 * 1024 * 1024,
    'captcha_question' => true,
    'retention_hours' => 6,
];
```

| Setting | Description |
|---|---|
| `max_upload_bytes` | Maximum image size |
| `access_password` | Optional password protecting the page (empty = disabled) |
| `captcha_question` | Simple arithmetic check against bots |
| `command_timeout` | Tool timeout in seconds |
| `rate_limit` | Analyses allowed per session within a time window |
| `retention_hours` | How long uploaded files last before auto-delete |
| `tool_paths` | Custom tool paths (empty = auto-detection) |

---

## Project Structure

```
.
├── config/config.php        # Settings
├── public/                  # Web root
│   ├── index.php            # The page
│   ├── analyze.php          # Analysis endpoint
│   ├── preview.php          # Serve the uploaded image
│   └── assets/              # CSS/JS
├── src/                     # App logic (PSR-like, no Composer)
│   ├── Analyzer.php         # Overall orchestrator
│   ├── Runner.php           # Runs commands safely
│   ├── Security.php         # Validation and upload protection
│   ├── Uploader.php         # Temporary file storage
│   ├── Structure.php        # JPEG/PNG/GIF structure analysis
│   ├── Entropy.php          # Entropy and LSB analysis
│   ├── Highlights.php       # Key tags
│   ├── Integrity.php        # Tamper detection
│   └── Tools/               # Tool wrappers
├── storage/                 # Temporary files (git-ignored)
├── deploy/nginx.conf        # Reference Nginx config
└── install.sh               # Installer script
```

---

## Security Notes

- Uploaded files are never executed and are stored under a random name outside the web root.
- All system commands run as an argument array (`proc_open` without shell) to prevent command injection.
- Double file validation: extension + `finfo` + image sanity check.
- Security headers (CSP, X-Frame-Options, nosniff), CSRF protection and rate limiting.
- Files are deleted automatically according to `retention_hours`.
- **Recommended for production:** serve over HTTPS and enable `access_password`.

---

## Troubleshooting

- **Large uploads fail:** check `upload_max_filesize` and `post_max_size` in PHP,
  and `client_max_body_size` in Nginx.
- **binwalk: Entropy Exception (numba):** binwalk's entropy module is broken on some systems;
  the app computes entropy internally and is unaffected.
- **zsteg not detected:** make sure `gem install zsteg` was run and `zsteg` is on `PATH`,
  or set its path in `tool_paths`.
- **No EXIF shown:** the image may have had its metadata stripped (which is itself a meaningful signal).