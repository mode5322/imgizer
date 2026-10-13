# Imgizer

Imgizer is a PHP web application for image forensics and metadata analysis. It generates a consolidated report covering EXIF metadata, file structure, entropy, integrity signals, hidden-data indicators, privacy-related GPS data, and local image steganography.

## Features

### Image metadata and forensics

- Full EXIF metadata via ExifTool
- IPTC/XMP/EXIF inspection via Exiv2
- Image properties via ImageMagick
- Embedded-file and signature detection via Binwalk
- String extraction via Binutils
- File entropy, integrity, and tamper detection
- Privacy-related metadata extraction, including GPS coordinates

### Image steganography

- Hide text in images using LSB encoding
- Extract hidden text from encoded PNG images
- Unicode support, including Arabic, English, emoji, and mixed-direction text
- Optional password-based AES-256-GCM encryption with PBKDF2-SHA-256
- PNG output to preserve hidden data
- Client-side processing; steganography images and text are not uploaded

## Technology Stack

- PHP 8.0 or newer
- JavaScript, HTML, and CSS
- External command-line tools where available: ExifTool, Exiv2, ImageMagick, Binwalk, Binutils
- Optional tools: Ruby `zsteg` and `steghide`

## Architecture

```text
imgizer/
├── config/config.php
├── public/
│   ├── index.php
│   ├── analyze.php
│   ├── preview.php
│   ├── stego.php
│   ├── steganography.php
│   └── assets/
│       ├── app.js
│       ├── stego.js
│       └── style.css
├── src/
│   ├── Analyzer.php
│   ├── Runner.php
│   ├── Security.php
│   ├── Uploader.php
│   ├── Structure.php
│   ├── Entropy.php
│   ├── Highlights.php
│   ├── Integrity.php
│   └── Tools/
├── storage/
│   ├── tmp/
│   └── uploads/
├── deploy/nginx.conf
└── install.sh
```

PHP handles validation and forensic analysis server-side. JavaScript handles the steganography workflow in the browser.

## Installation

### Requirements

- PHP 8.0 or newer with `fileinfo` and `exif`
- ExifTool
- ImageMagick (`identify`/`magick`)
- Exiv2 and Binwalk recommended
- Binutils (`strings`) recommended
- zsteg and steghide optional

### Built-in PHP server

```bash
cd D:/htdocs/imgizer/public
php -S 0.0.0.0:8080
```

Open:

```text
http://localhost:8080/index.php
```

### XAMPP

Place the project under the web root, for example:

```text
C:/xampp/htdocs/imgizer
```

Start Apache, then open:

```text
http://localhost/imgizer/public/index.php
```

### Configuration

Settings are in `config/config.php`. Create `config/config.local.php` for local overrides without committing secrets:

```php
<?php
return [
    'max_upload_bytes' => 25 * 1024 * 1024,
    'access_password' => '',
];
```

## Usage

### Metadata analyzer

1. Open `index.php`.
2. Select or drag and drop an image.
3. Complete the password and CAPTCHA fields if enabled.
4. Click **Analyze image**.

### Image steganography

1. Open `stego.php` or `steganography.php`.
2. Select or drag and drop an image.
3. Enter the message.
4. Optionally enable encryption and enter a matching password.
5. Click **Encode**, then download the PNG.
6. In the **Decode Image** tab, upload the PNG.
7. Enter the password if encrypted.
8. Click **Decode**, then copy the recovered text.

Legacy plaintext images remain supported.

## Security and Limitations

- Steganography hides data but does not inherently encrypt it.
- Password encryption is optional and browser-based.
- A lost password normally prevents recovery.
- JPEG compression, resizing, cropping, or other transformations may destroy hidden data.
- Web Crypto requires a secure context, such as HTTPS or localhost.
- Capacity depends on image dimensions and encoded payload overhead.

## Testing

No automated test suite is included. Basic syntax checks can be run with:

```bash
php -l public/index.php
php -l public/stego.php
php -l public/steganography.php
node --check public/assets/stego.js
```

## License

No license file is included. All rights remain with the repository owner unless stated otherwise.