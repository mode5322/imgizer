(function () {
    'use strict';

    var SIG_PLAIN = new TextEncoder().encode('IMGIZER:');
    var MAGIC_BYTES = SIG_PLAIN;
    var ENC_MAGIC = new TextEncoder().encode('IMGAES1:');
    var PBKDF2_ITERS = 600000;
    var MAX_PASSWORD_LENGTH = 1024;
    var MIN_KDF_ITERATIONS = 100000;
    var MAX_KDF_ITERATIONS = 1000000;

    var $ = function (sel) { return document.querySelector(sel); };

    function showError(el, msg) {
        if (!el) return;
        el.textContent = msg;
        el.hidden = false;
    }
    function clearError(el) {
        if (!el) return;
        el.hidden = true;
        el.textContent = '';
    }

    function humanBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function isImageFile(file) {
        return !!file && (/^image\//.test(file.type) || /\.(jpe?g|png|gif|bmp|webp|tiff?|heic|avif|ico|jp2|jxl)$/i.test(file.name || ''));
    }

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                try {
                    var width = img.naturalWidth || img.width;
                    var height = img.naturalHeight || img.height;
                    if (!width || !height) {
                        URL.revokeObjectURL(url);
                        reject(new Error('Could not determine image dimensions.'));
                        return;
                    }
                    if (width > 8192 || height > 8192) {
                        URL.revokeObjectURL(url);
                        reject(new Error('Image dimensions are too large (max 8192×8192).'));
                        return;
                    }
                    if (width * height > 25000000) {
                        URL.revokeObjectURL(url);
                        reject(new Error('Image contains too many pixels (max 25,000,000).'));
                        return;
                    }
                    var canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    var ctx = canvas.getContext('2d', { willReadFrequently: true });
                    ctx.drawImage(img, 0, 0);
                    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    URL.revokeObjectURL(url);
                    resolve({ canvas: canvas, ctx: ctx, imageData: imageData, width: width, height: height });
                } catch (e) {
                    URL.revokeObjectURL(url);
                    reject(new Error('Could not read image pixels.'));
                }
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('Could not load the selected image.'));
            };
            img.src = url;
        });
    }

    function setupDropzone(dropzone, input, onFile) {
        if (!dropzone || !input) return;
        dropzone.addEventListener('click', function () { input.click(); });
        dropzone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        input.addEventListener('change', function (e) {
            if (e.target.files && e.target.files[0]) onFile(e.target.files[0]);
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            dropzone.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dropzone.classList.add('dragover'); });
        });
        ['dragleave', 'dragend', 'drop'].forEach(function (ev) {
            dropzone.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('dragover'); });
        });
        dropzone.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) onFile(f);
        });
    }

    function showFileSelected(nameEl, sizeEl, file) {
        if (nameEl) nameEl.textContent = file.name;
        if (sizeEl) sizeEl.textContent = humanBytes(file.size);
    }

    // ---------- Encode ----------
    var encFile = null;
    var encDropzone = $('#enc-dropzone');
    var encInput = $('#enc-file-input');
    var encInner = $('#enc-dropzone-inner');
    var encPreview = $('#enc-preview');
    var encSelected = $('#enc-file-selected');
    var encName = $('#enc-selected-name');
    var encSize = $('#enc-selected-size');
    var encText = $('#enc-text');
    var encEncrypt = $('#enc-encrypt');
    var encPasswordBlock = $('#enc-password-block');
    var encPassword = $('#enc-password');
    var encPasswordConfirm = $('#enc-password-confirm');
    var encTogglePassword = $('#enc-toggle-password');
    var decPassword = $('#dec-password');
    var decTogglePassword = $('#dec-toggle-password');
    var encBtn = $('#enc-btn');
    var encError = $('#enc-error');
    var encCapacity = $('#enc-capacity');
    var encOutput = $('#enc-output');
    var encResultPreview = $('#enc-result-preview');
    var encDownload = $('#enc-download');
    var encLoading = $('#enc-loading');

    var encObjectUrl = null;

    function onEncFile(file) {
        clearError(encError);
        if (!isImageFile(file)) { showError(encError, 'Please choose a valid image file.'); return; }
        // size check done server-side limit unknown; keep 25MB
        if (file.size > 25 * 1024 * 1024) { showError(encError, 'Image exceeds the size limit.'); return; }
        encFile = file;
        var url = URL.createObjectURL(file);
        encPreview.src = url;
        encPreview.hidden = false;
        encInner.hidden = true;
        encSelected.hidden = false;
        showFileSelected(encName, encSize, file);
        encBtn.disabled = false;
        encOutput.hidden = true;
        if (encObjectUrl) URL.revokeObjectURL(encObjectUrl);
        encObjectUrl = url;
        updateCapacity();
    }

    async function updateCapacity() {
        if (!encFile) return;
        try {
            var loaded = await loadImage(encFile);
            var pixels = loaded.width * loaded.height;
            var rawBytes = Math.floor((pixels * 3) / 8);
            var encrypt = encEncrypt && encEncrypt.checked;
            var overhead = encrypt ? 68 : 12;
            var capacityBytes = rawBytes - overhead;
            if (capacityBytes < 0) capacityBytes = 0;
            encCapacity.textContent = 'Capacity: up to ' + (encrypt ? (capacityBytes + ' bytes plaintext' ) : (capacityBytes + ' bytes text')) + ' (image ' + loaded.width + '×' + loaded.height + ')';
        } catch (e) {
            encCapacity.textContent = '';
        }
    }

    async function deriveKey(password, salt, iterations) {
        if (!crypto || !crypto.subtle) throw new Error('Web Crypto API is not available in this browser/context.');
        var pwBytes = new TextEncoder().encode(password);
        var baseKey = await crypto.subtle.importKey('raw', pwBytes, 'PBKDF2', false, ['deriveKey']);
        if (iterations < MIN_KDF_ITERATIONS || iterations > MAX_KDF_ITERATIONS) throw new Error('Invalid KDF iteration count.');
        return crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt: salt, iterations: iterations, hash: 'SHA-256' },
            baseKey,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );
    }

    async function encryptMessage(plaintext, password) {
        if (password.length === 0) throw new Error('A password is required for encryption.');
        if (password.length > MAX_PASSWORD_LENGTH) throw new Error('Password is too long.');
        var salt = crypto.getRandomValues(new Uint8Array(16));
        var iv = crypto.getRandomValues(new Uint8Array(12));
        var key = await deriveKey(password, salt, PBKDF2_ITERS);
        var ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, plaintext));
        // Frame: version(1) + kdf(1) + iterations(4) + saltLen(1)+salt + ivLen(1)+iv + cipherLen(4)+ciphertext(tag included)
        var payload = new Uint8Array(1 + 1 + 4 + 1 + salt.length + 1 + iv.length + 4 + ct.length);
        var o = 0;
        payload[o++] = 1; // version
        payload[o++] = 1; // KDF id PBKDF2-SHA-256
        payload[o++] = (PBKDF2_ITERS >>> 24) & 0xFF;
        payload[o++] = (PBKDF2_ITERS >>> 16) & 0xFF;
        payload[o++] = (PBKDF2_ITERS >>> 8) & 0xFF;
        payload[o++] = PBKDF2_ITERS & 0xFF;
        payload[o++] = salt.length;
        payload.set(salt, o); o += salt.length;
        payload[o++] = iv.length;
        payload.set(iv, o); o += iv.length;
        payload[o++] = (ct.length >>> 24) & 0xFF;
        payload[o++] = (ct.length >>> 16) & 0xFF;
        payload[o++] = (ct.length >>> 8) & 0xFF;
        payload[o++] = ct.length & 0xFF;
        payload.set(ct, o);
        return payload;
    }

    function parseEncryptedPayload(payload) {
        // returns {version, iterations, salt, iv, ciphertext}
        if (payload.length < 1 + 1 + 4 + 1 + 16 + 1 + 12 + 4 + 16) throw new Error('Invalid encrypted payload (truncated).');
        var o = 0;
        var version = payload[o++];
        if (version !== 1) throw new Error('Unsupported encrypted payload version.');
        var kdf = payload[o++];
        if (kdf !== 1) throw new Error('Unsupported key derivation method.');
        var iterations = ((payload[o] << 24) >>> 0) + (payload[o + 1] << 16) + (payload[o + 2] << 8) + payload[o + 3];
        // safer:
        iterations = (payload[o] * 0x1000000) + (payload[o+1]*0x10000) + (payload[o+2]*0x100) + payload[o+3];
        o += 4;
        if (iterations < MIN_KDF_ITERATIONS || iterations > MAX_KDF_ITERATIONS) throw new Error('Invalid KDF parameters.');
        var saltLen = payload[o++];
        if (saltLen < 8 || saltLen > 64) throw new Error('Invalid salt length.');
        if (o + saltLen + 1 > payload.length) throw new Error('Invalid encrypted payload (truncated).');
        var salt = payload.slice(o, o + saltLen); o += saltLen;
        var ivLen = payload[o++];
        if (ivLen !== 12) throw new Error('Invalid initialization vector length.');
        if (o + ivLen + 4 > payload.length) throw new Error('Invalid encrypted payload (truncated).');
        var iv = payload.slice(o, o + ivLen); o += ivLen;
        var ctLen = ((payload[o] * 0x1000000) + (payload[o+1]*0x10000) + (payload[o+2]*0x100) + payload[o+3]);
        o += 4;
        if (ctLen < 16 || ctLen > payload.length - o) throw new Error('Invalid ciphertext length.');
        var ct = payload.slice(o, o + ctLen);
        if (o + ctLen !== payload.length) throw new Error('Invalid encrypted payload size.');
        return { salt: salt, iv: iv, ciphertext: ct, iterations: iterations };
    }

    async function decryptMessage(payload, password) {
        if (!crypto || !crypto.subtle) throw new Error('Web Crypto API is not available in this browser/context.');
        if (password.length === 0) throw new Error('A password is required to decrypt this image.');
        if (password.length > MAX_PASSWORD_LENGTH) throw new Error('Password is too long.');
        var parsed = parseEncryptedPayload(payload);
        var key = await deriveKey(password, parsed.salt, parsed.iterations);
        var pt;
        try {
            pt = new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: parsed.iv }, key, parsed.ciphertext));
        } catch (e) {
            throw new Error('Unable to decrypt this message. The password may be incorrect or the encrypted data may be damaged.');
        }
        return pt;
    }
    function encode() {
        clearError(encError);
        if (!encFile) { showError(encError, 'Please select an image first.'); return; }
        var text = encText.value;
        encBtn.disabled = true;
        if (encLoading) encLoading.hidden = false;
        encOutput.hidden = true;

        (async function () {
            try {
                var loaded = await loadImage(encFile);
                var data = loaded.imageData;
                var encrypt = encEncrypt && encEncrypt.checked;
                var sig = encrypt ? ENC_MAGIC : MAGIC_BYTES;
                var password = encPassword ? encPassword.value : '';
                var confirmPw = encPasswordConfirm ? encPasswordConfirm.value : '';
                if (encrypt) {
                    if (!crypto || !crypto.subtle) throw new Error('Web Crypto API is not available in this browser/context.');
                    if (password.length === 0) throw new Error('A password is required when encryption is enabled.');
                    if (password !== confirmPw) throw new Error('Password confirmation does not match.');
                    if (password.length > MAX_PASSWORD_LENGTH) throw new Error('Password is too long.');
                }
                var bytes = new TextEncoder().encode(text);
                var plaintextBytes = bytes;
                var innerPayload;
                if (encrypt) {
                    innerPayload = await encryptMessage(plaintextBytes, password);
                } else {
                    innerPayload = plaintextBytes;
                }
                var totalPayloadBytes = sig.length + 4 + innerPayload.length;
                var capacityBits = loaded.width * loaded.height * 3;
                var neededBits = totalPayloadBytes * 8;
                if (neededBits > capacityBits) {
                    throw new Error('Text too large for this image. Need ' + neededBits + ' bits, capacity ' + capacityBits + ' bits.');
                }
                var payload = new Uint8Array(totalPayloadBytes);
                payload.set(sig, 0);
                var len = innerPayload.length;
                payload[sig.length] = (len >>> 24) & 0xFF;
                payload[sig.length + 1] = (len >>> 16) & 0xFF;
                payload[sig.length + 2] = (len >>> 8) & 0xFF;
                payload[sig.length + 3] = len & 0xFF;
                payload.set(innerPayload, sig.length + 4);

                var pixels = loaded.width * loaded.height;
                var d = data.data;
                var bitIndex = 0;
                var totalBits = payload.length * 8;
                for (var p = 0; p < pixels && bitIndex < totalBits; p++) {
                    var idx = p * 4;
                    for (var c = 0; c < 3 && bitIndex < totalBits; c++) {
                        var bytePos = bitIndex >>> 3;
                        var shift = 7 - (bitIndex & 7);
                        var bit = (payload[bytePos] >> shift) & 1;
                        d[idx + c] = (d[idx + c] & 0xFE) | bit;
                        bitIndex++;
                    }
                }

                loaded.ctx.putImageData(data, 0, 0);
                loaded.canvas.toBlob(function (blob) {
                    if (!blob) { showError(encError, 'Failed to generate PNG image.'); if (encLoading) encLoading.hidden = true; encBtn.disabled = false; return; }
                    if (encObjectUrl) URL.revokeObjectURL(encObjectUrl);
                    var url = URL.createObjectURL(blob);
                    encObjectUrl = url;
                    encResultPreview.src = url;
                    encOutput.hidden = false;
                    if (encPassword) encPassword.value = '';
                    if (encPasswordConfirm) encPasswordConfirm.value = '';
                    encDownload.href = url;
                    encDownload.setAttribute('download', 'encoded.png');
                    if (encLoading) encLoading.hidden = true;
                    encBtn.disabled = false;
                }, 'image/png');
            } catch (e) {
                showError(encError, e && e.message ? e.message : 'Encoding failed.');
                if (encLoading) encLoading.hidden = true;
                encBtn.disabled = false;
            }
        })();
    }

    // ---------- Decode ----------
    var decFile = null;
    var decDropzone = $('#dec-dropzone');
    var decInput = $('#dec-file-input');
    var decInner = $('#dec-dropzone-inner');
    var decPreview = $('#dec-preview');
    var decSelected = $('#dec-file-selected');
    var decName = $('#dec-selected-name');
    var decSize = $('#dec-selected-size');
    var decBtn = $('#dec-btn');
    var decError = $('#dec-error');
    var decText = $('#dec-text');
    var decCopy = $('#dec-copy');
    var decLoading = $('#dec-loading');

    function onDecFile(file) {
        clearError(decError);
        if (!isImageFile(file)) { showError(decError, 'Please choose a valid image file.'); return; }
        if (file.size > 25 * 1024 * 1024) { showError(decError, 'Image exceeds the size limit.'); return; }
        decFile = file;
        var url = URL.createObjectURL(file);
        decPreview.src = url;
        decPreview.hidden = false;
        decInner.hidden = true;
        decSelected.hidden = false;
        showFileSelected(decName, decSize, file);
        decBtn.disabled = false;
        decText.value = '';
        if (decCopy) decCopy.disabled = true;
    }

    function extractBits(imageData, countBits) {
        var d = imageData.data;
        var pixels = d.length / 4;
        var neededPixels = Math.ceil(countBits / 3);
        if (neededPixels > pixels) throw new Error('Image too small or no hidden data (not enough pixels).');
        var out = new Uint8Array(Math.ceil(countBits / 8));
        var bitPos = 0;
        for (var p = 0; p < pixels && bitPos < countBits; p++) {
            var idx = p * 4;
            for (var c = 0; c < 3 && bitPos < countBits; c++) {
                var bit = d[idx + c] & 1;
                out[bitPos >>> 3] = (out[bitPos >>> 3] << 1) | bit;
                bitPos++;
            }
        }
        return out;
    }

    function decode() {
        clearError(decError);
        if (!decFile) { showError(decError, 'Please select an image first.'); return; }
        decBtn.disabled = true;
        if (decLoading) decLoading.hidden = false;
        decText.value = '';
        if (decCopy) decCopy.disabled = true;

        (async function () {
            try {
                var loaded = await loadImage(decFile);
                var pixels = loaded.width * loaded.height;
                var totalBits = pixels * 3;

                var outerSigBits = ENC_MAGIC.length * 8;
                var headerBits = ENC_MAGIC.length * 8 + 32;
                if (totalBits < headerBits) throw new Error('Image too small or contains no hidden data.');

                var sigBits = extractBits(loaded.imageData, ENC_MAGIC.length * 8);
                var isPlain = true, isEnc = true;
                for (var si = 0; si < SIG_PLAIN.length; si++) { if (sigBits[si] !== SIG_PLAIN[si]) isPlain = false; }
                for (var ei = 0; ei < ENC_MAGIC.length; ei++) { if (sigBits[ei] !== ENC_MAGIC[ei]) isEnc = false; }
                if (!isPlain && !isEnc) throw new Error('No detectable hidden text or the data is corrupted.');

                var lenBytes = extractBits(loaded.imageData, headerBits);
                var len = 0;
                var off = SIG_PLAIN.length;
                for (var li = 0; li < 4; li++) {
                    len = (len << 8) | lenBytes[off + li];
                }
                len = len >>> 0;
                if (len > 10 * 1024 * 1024) throw new Error('Invalid message length (corrupted data).');
                var availableBytes = Math.floor((totalBits - headerBits) / 8);
                if (len > availableBytes) throw new Error('Message length exceeds available data (corrupted data).');

                var msgBits = headerBits + len * 8;
                var allBytes = extractBits(loaded.imageData, msgBits);
                var inner = allBytes.slice(SIG_PLAIN.length + 4, SIG_PLAIN.length + 4 + len);

                var decoder = new TextDecoder('utf-8', { fatal: true });
                var text;
                if (isPlain) {
                    text = decoder.decode(inner);
                } else {
                    var pt = await decryptMessage(inner, decPassword ? decPassword.value : '');
                    text = decoder.decode(pt);
                }
                decText.value = text;
                if (isEnc && decPassword) decPassword.value = '';
                if (decCopy) decCopy.disabled = (text.length === 0);
            } catch (e) {
                showError(decError, e && e.message ? e.message : 'Decoding failed.');
                decText.value = '';
                if (decCopy) decCopy.disabled = true;
            } finally {
                if (decLoading) decLoading.hidden = true;
                decBtn.disabled = false;
            }
        })();
    }

    // ---------- Encryption UI wiring ----------
    if (encEncrypt) {
        encEncrypt.addEventListener('change', function () {
            if (encPasswordBlock) encPasswordBlock.hidden = !encEncrypt.checked;
            updateCapacity();
        });
    }
    if (!window.crypto || !crypto.subtle) {
        if (encEncrypt) { encEncrypt.disabled = true; encEncrypt.title = 'Web Crypto API unavailable (secure context required).'; }
    }
    function wirePasswordToggle(input, btn) {
        if (btn && input) {
            btn.addEventListener('click', function () {
                if (input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
                else { input.type = 'password'; btn.textContent = 'Show'; }
            });
        }
    }
    wirePasswordToggle(encPassword, encTogglePassword);
    wirePasswordToggle(decPassword, decTogglePassword);

    // ---------- Tabs ----------
    var tabs = document.querySelectorAll('.tab');
    function switchTab(name) {
        tabs.forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === name);
        });
        var enc = $('#tab-encode');
        var dec = $('#tab-decode');
        if (enc) enc.hidden = (name !== 'encode');
        if (dec) dec.hidden = (name !== 'decode');
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function () { switchTab(t.getAttribute('data-tab')); });
    });

    // ---------- Wire up ----------
    setupDropzone(encDropzone, encInput, onEncFile);
    setupDropzone(decDropzone, decInput, onDecFile);

    if (encBtn) encBtn.addEventListener('click', encode);
    if (decBtn) decBtn.addEventListener('click', decode);

    if (decCopy) {
        decCopy.addEventListener('click', function () {
            navigator.clipboard.writeText(decText.value).then(function () {
                decCopy.textContent = 'Copied';
                setTimeout(function () { decCopy.textContent = 'Copy to clipboard'; }, 1500);
            }).catch(function () {
                // fallback
                decText.select();
                try { document.execCommand('copy'); decCopy.textContent = 'Copied'; setTimeout(function () { decCopy.textContent = 'Copy to clipboard'; }, 1500); } catch (e) { }
            });
        });
    }

    switchTab('encode');
})();
