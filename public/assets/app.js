(function () {
    'use strict';

    var configEl = document.getElementById('app-config');
    var CFG = JSON.parse(configEl.textContent || '{}');

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var state = { file: null, data: null };

    function h(tag, props) {
        var el = document.createElement(tag);
        var kids = Array.prototype.slice.call(arguments, 2);
        if (props) {
            Object.keys(props).forEach(function (k) {
                var v = props[k];
                if (v == null) return;
                if (k === 'class') el.className = v;
                else if (k === 'text') el.textContent = v;
                else if (k === 'dataset') Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; });
                else if (k.indexOf('on') === 0 && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
                else el.setAttribute(k, v);
            });
        }
        kids.forEach(function (kid) {
            if (kid == null) return;
            if (Array.isArray(kid)) { kid.forEach(function (k) { if (k != null) el.append(k); }); }
            else if (typeof kid === 'string' || typeof kid === 'number') el.append(document.createTextNode(String(kid)));
            else el.append(kid);
        });
        return el;
    }

    function section(title, note) {
        var kids = Array.prototype.slice.call(arguments, 2);
        return h('div', { class: 'section' },
            title ? h('h3', {}, title) : null,
            note ? h('p', { class: 'note', text: note }) : null,
            kids
        );
    }

    function statusMessage(text, kind) {
        return h('p', { class: 'status-' + (kind || 'missing'), text: text });
    }

    function field(label, value) {
        return h('div', { class: 'kv' }, h('span', { class: 'k', text: label }), h('span', { class: 'v', text: value }));
    }

    function fact(label, value) {
        return h('div', { class: 'fact' }, h('span', { class: 'k', text: label }), h('span', { class: 'v', text: value }));
    }

    function finding(level, title, detail) {
        return h('div', { class: 'finding ' + level }, h('div', {},
            title ? h('div', { class: 'f-title', text: title }) : null,
            detail ? h('div', { class: 'f-detail', text: detail }) : null));
    }

    function chips(items) {
        return (items || []).map(function (s) { return h('span', { class: 'chip', text: s }); });
    }

    function copyButton(getText) {
        return h('button', {
            class: 'ghost copy-btn', type: 'button', text: 'Copy',
            onclick: function () {
                navigator.clipboard.writeText(getText()).then(function () {
                    this.textContent = 'Copied';
                }.bind(this));
            }
        });
    }

    function download(name, text) {
        var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    function tool(id) {
        return (state.data.tools || []).find(function (t) { return t.id === id; });
    }

    function mapped(t, fn) {
        if (t && t.status === 'ok' && t.data) return fn(t.data);
        return null;
    }

    /* ---------------- Uploader ---------------- */

    var dropzone = $('#dropzone');
    var fileInput = $('#file-input');
    var localPreview = $('#local-preview');
    var dropzoneInner = $('#dropzone-inner');
    var submitBtn = $('#submit-btn');
    var selected = $('#file-selected');
    var errorBox = $('#error-box');

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.hidden = false;
    }

    function clearError() { errorBox.hidden = true; }

    function setFile(file) {
        if (!file) return;
        if (!/^image\//.test(file.type) && !/\.(jpe?g|png|gif|bmp|webp|tiff?|heic|avif|ico|jp2|jxl)$/i.test(file.name)) {
            showError('Please choose a valid image file.');
            return;
        }
        if (file.size > CFG.maxUploadBytes) {
            showError('Image exceeds the size limit (' + CFG.maxUploadHuman + ').');
            return;
        }
        state.file = file;
        clearError();
        localPreview.src = URL.createObjectURL(file);
        localPreview.hidden = false;
        dropzoneInner.hidden = true;
        $('#selected-name').textContent = file.name;
        $('#selected-size').textContent = humanBytes(file.size);
        selected.hidden = false;
        submitBtn.disabled = false;
    }

    function humanBytes(n) {
        var u = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
    }

    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });
    fileInput.addEventListener('change', function () { if (fileInput.files[0]) setFile(fileInput.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('dragover'); });
    });
    dropzone.addEventListener('drop', function (e) {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    });
    document.addEventListener('paste', function (e) {
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) { setFile(items[i].getAsFile()); break; }
        }
    });

    /* ---------------- Submit ---------------- */

    var loading = $('#loading');
    var loadingTimer = $('#loading-timer');
    var results = $('#results');
    var timerId = null;

    $('#analyze-form').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!state.file) { showError('No image selected.'); return; }

        var fd = new FormData();
        fd.append('image', state.file, state.file.name);
        fd.append('csrf', CFG.csrf);
        if (CFG.requireCaptcha) fd.append('captcha', $('#captcha') ? $('#captcha').value : '');
        if (CFG.requirePassword) fd.append('password', $('#password') ? $('#password').value : '');

        clearError();
        submitBtn.disabled = true;
        results.hidden = true;
        loading.hidden = false;
        var t0 = performance.now();
        loadingTimer.textContent = '0.0 s';
        timerId = setInterval(function () {
            loadingTimer.textContent = ((performance.now() - t0) / 1000).toFixed(1) + ' s';
        }, 100);

        fetch('analyze.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.body.ok) throw new Error(res.body.error || ('Analysis failed (' + res.status + ')'));
                state.data = res.body;
                render(res.body);
                results.hidden = false;
                results.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function (err) { showError(err.message || 'Unexpected error.'); })
            .finally(function () {
                clearInterval(timerId);
                loading.hidden = true;
                submitBtn.disabled = false;
            });
    });

    /* ---------------- Consolidated report ---------------- */

    function render(data) {
        var box = $('#report');
        renderSummary(data);
        box.textContent = '';
        box.append(renderReport(data));
    }

    function renderSummary(data) {
        var box = $('#summary');
        box.textContent = '';
        var f = data.file;
        var verdict = data.integrity && data.integrity.verdict ? data.integrity.verdict : { level: 'ok', title: 'No verdict' };

        box.append(
            h('div', { class: 'summary-preview' }, h('img', { src: f.preview_url, alt: f.name })),
            h('div', {},
                h('div', { class: 'summary-head' },
                    h('h2', { text: f.name }),
                    h('span', { class: 'verdict ' + verdict.level, text: verdict.title })
                ),
                h('div', { class: 'facts' },
                    fact('Size', f.size_human),
                    fact('Type', f.mime),
                    fact('Dimensions', (f.width || '?') + ' × ' + (f.height || '?')),
                    fact('Analysis time', (data.meta.elapsed_ms / 1000).toFixed(2) + ' s')
                ),
                h('div', { class: 'toolbar', style: 'margin-top:14px' },
                    h('button', { class: 'ghost', type: 'button', text: 'Download full results (JSON)', onclick: function () { download(f.name + '.analysis.json', JSON.stringify(data, null, 2)); } })
                )
            )
        );
    }

    function renderReport(data) {
        var frag = document.createDocumentFragment();
        var hl = data.highlights || {};
        var integ = data.integrity || {};
        var ent = data.entropy || {};
        var lsb = data.lsb || {};

        /* 1 — Location (GPS) */
        if (hl.gps && hl.gps.available) {
            frag.append(section('Location (GPS)', hl.gps.position,
                h('div', { class: 'facts' },
                    fact('Latitude', hl.gps.lat),
                    fact('Longitude', hl.gps.lng),
                    fact('Altitude', hl.gps.altitude || '—'),
                    fact('Heading', hl.gps.direction || '—')
                ),
                h('div', { class: 'toolbar' },
                    copyButton(function () { return hl.gps.lat + ', ' + hl.gps.lng; }),
                    h('a', { class: 'ghost link-btn', href: hl.gps.links.google, target: '_blank', rel: 'noopener', text: 'Open in Google Maps' }),
                    h('a', { class: 'ghost link-btn', href: hl.gps.links.osm, target: '_blank', rel: 'noopener', text: 'OpenStreetMap' }),
                    h('a', { class: 'ghost link-btn', href: hl.gps.links.bing, target: '_blank', rel: 'noopener', text: 'Bing Maps' })
                )
            ));
        }

        /* 2 — Capture data */
        if (hl.camera && hl.camera.length) {
            frag.append(section('Capture data', 'Key fields read from the image',
                h('div', { class: 'kv-grid' }, hl.camera.map(function (c) { return field(c.label, c.value); }))
            ));
        } else {
            frag.append(section('Capture data', null, statusMessage('No camera/EXIF data found in this image.')));
        }

        /* 3 — File dates */
        var dates = integ.dates || [];
        if (dates.length) {
            frag.append(section('File dates', null,
                h('div', { class: 'kv-grid' }, dates.map(function (d) { return field(d.label, d.value); }))
            ));
        }

        /* 4 — Privacy-sensitive data */
        if (hl.privacy && hl.privacy.length) {
            frag.append(section('Privacy-sensitive data', 'Fields that may expose information about you',
                h('div', { class: 'kv-grid' }, hl.privacy.map(function (p) { return field(p.label, p.value); }))
            ));
        }

        /* 5 — Editing indicators */
        if (hl.editing && hl.editing.length) {
            frag.append(section('Editing indicators', null,
                h('div', { class: 'kv-grid' }, hl.editing.map(function (p) { return field(p.label, p.value); }))
            ));
        }

        /* 6 — Integrity & tamper */
        var verdict = integ.verdict || { level: 'ok', title: 'No verdict' };
        var findings = integ.findings || [];
        frag.append(section('Integrity & tamper', null,
            h('span', { class: 'verdict ' + verdict.level, text: verdict.title }),
            h('div', { style: 'margin-top:12px' }, findings.map(function (f) { return finding(f.level, f.title, f.detail); }))
        ));

        /* 7 — Hidden data & signals */
        var hidden = [];

        var entItem = null;
        if (typeof ent.overall === 'number') {
            entItem = h('div', {},
                h('div', { class: 'f-title', text: 'Entropy: ' + ent.overall.toFixed(2) + ' / 8' }),
                h('div', { class: 'f-detail', text: ent.interpretation || '' })
            );
        }

        var lsbItem = null;
        if (lsb.available && lsb.channels) {
            var lsbLines = Object.keys(lsb.channels).map(function (name) {
                var ch = lsb.channels[name];
                var runs = (ch.printable_runs || []).map(function (r) { return h('span', { class: 'chip', text: r.text }); });
                var row = h('div', {},
                    h('div', { class: 'f-title', text: 'Channel ' + name + ' — ' + (ch.ones_ratio * 100).toFixed(1) + '% of 1-bits' }),
                    runs.length ? runs : null
                );
                return row;
            });
            lsbItem = h('div', {}, h('div', { class: 'f-title', text: 'Pixel bit analysis (LSB)' }), lsbLines);
        }

        var bw = tool('binwalk');
        var bwItem = null;
        if (bw && bw.status === 'ok') {
            var entries = (bw.data && bw.data.entries) || [];
            bwItem = h('div', {},
                h('div', { class: 'f-title', text: 'Embedded files / signatures' }),
                entries.length ? entries.map(function (en) {
                    return h('div', { class: 'f-detail', text: '0x' + en.offset_hex + ' — ' + en.description });
                }) : h('div', { class: 'f-detail', text: 'No known signatures inside the file.' })
            );
        } else if (bw) {
            bwItem = h('div', {}, h('div', { class: 'f-title', text: 'Embedded files / signatures' }), h('div', { class: 'f-detail', text: bw.note || 'Not available.' }));
        }

        var st = tool('stego');
        var zgItem = null;
        if (st && st.status === 'ok' && st.data) {
            var z = st.data.zsteg || {};
            if (z.available) {
                var zf = z.findings || [];
                zgItem = h('div', {},
                    h('div', { class: 'f-title', text: 'Steganography scan' }),
                    zf.length ? zf.map(function (f) { return h('div', { class: 'f-detail', text: (f.label || '') + (f.value ? ': ' + f.value : '') + (f.note ? ' — ' + f.note : '') }); })
                        : h('div', { class: 'f-detail', text: 'No hidden data detected.' })
                );
            } else {
                zgItem = h('div', {}, h('div', { class: 'f-title', text: 'Steganography scan' }), h('div', { class: 'f-detail', text: z.note || 'Not available.' }));
            }
            var sh = st.data.steghide || {};
            if (sh.available) {
                var shLevel = sh.embedded ? 'danger' : 'ok';
                hidden.push(finding(shLevel, 'Steganography (JPEG/BMP)', sh.note || ''));
            }
        }

        var hasHidden = entItem || lsbItem || bwItem || zgItem || hidden.length;
        if (hasHidden) {
            frag.append(section('Hidden data & signals', 'Entropy, bit content, embedded files and steganography results',
                entItem ? h('div', { class: 'finding info' }, entItem) : null,
                lsbItem ? h('div', { class: 'finding info' }, lsbItem) : null,
                bwItem ? h('div', { class: 'finding info' }, bwItem) : null,
                zgItem ? h('div', { class: 'finding info' }, zgItem) : null,
                hidden
            ));
        }

        /* 8 — Links & emails */
        var stt = tool('strings');
        var linksData = mapped(stt, function (d) { return d; });
        if (linksData) {
            var urls = linksData.urls || [];
            var emails = linksData.emails || [];
            var interesting = linksData.interesting || [];
            if (urls.length || emails.length || interesting.length) {
                frag.append(section('Links & text inside the file', null,
                    urls.length ? h('div', {}, h('div', { class: 'f-title', text: 'URLs' }),
                        urls.map(function (u) { return h('a', { class: 'link-line', href: u.text, target: '_blank', rel: 'noopener', text: u.text }); })) : null,
                    emails.length ? h('div', {}, h('div', { class: 'f-title', text: 'Emails' }), chips(emails.map(function (e) { return e.text; }))) : null,
                    interesting.length ? h('div', {}, h('div', { class: 'f-title', text: 'Notable strings' }),
                        interesting.slice(0, 12).map(function (s) { return h('div', { class: 'f-detail', text: '[' + s.offset + '] ' + s.text }); })) : null
                ));
            }
        }

        /* 9 — Image properties */
        var im = tool('imagemagick');
        var imProps = mapped(im, function (d) { return d.highlights || {}; });
        if (imProps && Object.keys(imProps).length) {
            var keep = ['Colorspace', 'Type', 'Depth', 'Quality', 'Resolution', 'Units', 'Interlace', 'Frames', 'Compression'];
            var grid = keep.map(function (k) { return imProps[k] ? field(k, imProps[k]) : null; })
                .filter(function (x) { return !!x; });
            if (!grid.length) {
                grid = Object.keys(imProps).slice(0, 8).map(function (k) { return field(k, imProps[k]); });
            }
            frag.append(section('Image properties', null, h('div', { class: 'kv-grid' }, grid)));
        }

        /* 10 — Fingerprints */
        var hashes = data.file.hashes;
        if (hashes) {
            frag.append(section('Fingerprints', null, h('div', { class: 'kv-grid' },
                field('MD5', hashes.md5),
                field('SHA-1', hashes.sha1),
                field('SHA-256', hashes.sha256)
            )));
        }

        return frag;
    }
})();