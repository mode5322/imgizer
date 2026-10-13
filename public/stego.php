<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$maxUpload = (int) $config['max_upload_bytes'];
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Image Steganography — Hide Text in Images</title>
<link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body>
<header class="site-header">
    <div class="brand">
        <span class="brand-mark">STEGO</span>
        <div>
            <h1>Image Steganography</h1>
            <p>Hide text inside images and extract it later using LSB encoding. Processing stays in your browser.</p>
        </div>
    </div>
    <nav class="tool-badges">
        <a class="badge" href="index.php">Metadata Analyzer</a>
    </nav>
</header>

<main>
    <p class="muted" style="margin:8px 0 16px;">All encoding and decoding run locally in your browser. Images and messages are never uploaded. Use PNG output only — JPEG and other lossy formats can destroy hidden data.</p>

    <div class="tabs" role="tablist">
        <button class="tab active" data-tab="encode" type="button">Encode Image</button>
        <button class="tab" data-tab="decode" type="button">Decode Image</button>
    </div>

    <div class="tab-content" id="tab-encode">
        <section class="panel uploader" id="encode-panel">
            <div class="dropzone" id="enc-dropzone" tabindex="0">
                <input type="file" id="enc-file-input" accept="image/*" hidden>
                <div class="dropzone-inner" id="enc-dropzone-inner">
                    <p class="dz-title">Drag &amp; drop an image here</p>
                    <p class="dz-sub">Click to browse. This image will carry the hidden text.</p>
                    <p class="dz-limits">Max size: <?= htmlspecialchars(number_format($maxUpload / (1024*1024), 1) . ' MB', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <img id="enc-preview" alt="preview" hidden style="display:block;margin:12px auto 0;max-width:100%;max-height:320px;border-radius:6px;border:1px solid var(--line);">
            </div>

            <div class="file-selected" id="enc-file-selected" hidden>
                <span id="enc-selected-name"></span>
                <span id="enc-selected-size" class="muted"></span>
            </div>

            <label class="field" style="margin-top:16px;">
                <span>Text to hide</span>
                <textarea id="enc-text" rows="6" style="background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:10px 12px;color:var(--ink);font-size:15px;font-family:inherit;width:100%;resize:vertical;" placeholder="Enter secret text (English, Arabic, or mixed Unicode)..."></textarea>
            </label>

            <label class="field" style="margin-top:16px; display:flex; align-items:center; gap:8px;">
                <input type="checkbox" id="enc-encrypt" style="width:auto; min-width:auto;">
                <span>Encrypt message with password (optional)</span>
            </label>
            <p class="muted" style="margin:4px 0 0;">Encryption uses AES-GCM and PBKDF2 in your browser. The password is never stored or transmitted.</p>

            <div id="enc-password-block" hidden style="margin-top:14px;">
                <label class="field">
                    <span>Password</span>
                    <div style="position:relative;">
                        <input type="password" id="enc-password" autocomplete="new-password" maxlength="1024" style="width:100%;">
                        <button type="button" class="ghost" id="enc-toggle-password" style="position:absolute; right:8px; top:50%; transform:translateY(-50%);">Show</button>
                    </div>
                </label>
                <label class="field" style="margin-top:12px;">
                    <span>Confirm password</span>
                    <input type="password" id="enc-password-confirm" autocomplete="new-password" maxlength="1024" style="width:100%;">
                </label>
            </div>

            <div class="form-row">
                    <button type="button" id="enc-btn" disabled>Encode</button>
                    <span id="enc-capacity" class="muted"></span>
                </div>

            <div class="alert" id="enc-error" hidden role="alert"></div>

            <div id="enc-output" hidden style="margin-top:18px;">
                <h3 class="section" style="margin-bottom:10px;">Encoded Image</h3>
                <img id="enc-result-preview" alt="encoded preview" style="max-width:100%;border-radius:6px;border:1px solid var(--line);">
                <div class="form-row">
                    <a id="enc-download" class="link-btn" href="#" download="encoded.png">Download PNG</a>
                    <span class="muted">Download as PNG to keep the hidden data intact.</span>
                </div>
            </div>

            <div class="loading" id="enc-loading" hidden>
                <div class="spinner"></div>
                <p>Encoding image...</p>
            </div>
        </section>
    </div>

    <div class="tab-content" id="tab-decode" hidden>
        <section class="panel uploader" id="decode-panel">
            <div class="dropzone" id="dec-dropzone" tabindex="0">
                <input type="file" id="dec-file-input" accept="image/*" hidden>
                <div class="dropzone-inner" id="dec-dropzone-inner">
                    <p class="dz-title">Drag &amp; drop an encoded image here</p>
                    <p class="dz-sub">Click to browse. PNG produced by this app works best.</p>
                    <p class="dz-limits">Max size: <?= htmlspecialchars(number_format($maxUpload / (1024*1024), 1) . ' MB', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <img id="dec-preview" alt="preview" hidden style="display:block;margin:12px auto 0;max-width:100%;max-height:320px;border-radius:6px;border:1px solid var(--line);">
            </div>

            <div class="file-selected" id="dec-file-selected" hidden>
                <span id="dec-selected-name"></span>
                <span id="dec-selected-size" class="muted"></span>
            </div>

            <label class="field" style="margin-top:16px;">
                <span>Password (required only for encrypted images)</span>
                <div style="position:relative;">
                    <input type="password" id="dec-password" autocomplete="current-password" maxlength="1024" style="width:100%;">
                    <button type="button" class="ghost" id="dec-toggle-password" style="position:absolute; right:8px; top:50%; transform:translateY(-50%);">Show</button>
                </div>
            </label>

            <div class="form-row">
                <button type="button" id="dec-btn" disabled>Decode</button>
            </div>

            <div class="alert" id="dec-error" hidden role="alert"></div>

            <label class="field" style="margin-top:16px;">
                <span>Extracted text</span>
                <textarea id="dec-text" rows="8" readonly style="background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:10px 12px;color:var(--ink);font-size:15px;font-family:inherit;width:100%;resize:vertical;"></textarea>
            </label>

            <div class="form-row">
                <button type="button" id="dec-copy" class="ghost" disabled>Copy to clipboard</button>
            </div>

            <div class="loading" id="dec-loading" hidden>
                <div class="spinner"></div>
                <p>Decoding image...</p>
            </div>
        </section>
    </div>
</main>

<footer class="site-footer">
    <span>Local LSB steganography — PNG output recommended.</span>
</footer>

<script src="assets/stego.js?v=1"></script>
</body>
</html>
