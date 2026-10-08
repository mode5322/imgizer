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

    function copyButton(getText) {
        return h('button', {
            class: 'ghost copy-btn', type: 'button', text: 'نسخ',
            onclick: function () {
                navigator.clipboard.writeText(getText()).then(function () {
                    this.textContent = 'تم النسخ';
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
            showError('الرجاء اختيار ملف صورة صالح.');
            return;
        }
        if (file.size > CFG.maxUploadBytes) {
            showError('حجم الصورة يتجاوز الحد المسموح (' + CFG.maxUploadHuman + ').');
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
        var u = ['بايت', 'ك.ب', 'م.ب', 'غ.ب'], i = 0;
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
        if (!state.file) { showError('لم يتم اختيار صورة.'); return; }

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
        loadingTimer.textContent = '0.0 ث';
        timerId = setInterval(function () {
            loadingTimer.textContent = ((performance.now() - t0) / 1000).toFixed(1) + ' ث';
        }, 100);

        fetch('analyze.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
            .then(function (res) {
                if (!res.ok || !res.body.ok) throw new Error(res.body.error || ('فشل التحليل (' + res.status + ')'));
                state.data = res.body;
                render(res.body);
                results.hidden = false;
                results.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function (err) { showError(err.message || 'حدث خطأ غير متوقع.'); })
            .finally(function () {
                clearInterval(timerId);
                loading.hidden = true;
                submitBtn.disabled = false;
            });
    });

    /* ---------------- Render ---------------- */

    var TABS = [
        { id: 'overview', label: 'نظرة عامة', render: renderOverview },
        { id: 'exif', label: 'EXIF', render: renderExif },
        { id: 'exiv2', label: 'Exiv2', render: renderExiv2 },
        { id: 'imagemagick', label: 'ImageMagick', render: renderImageMagick },
        { id: 'structure', label: 'البنية والملفات', render: renderStructure },
        { id: 'strings', label: 'نصوص وروابط', render: renderStrings },
        { id: 'stego', label: 'التخفي والإنتروبيا', render: renderStego },
        { id: 'integrity', label: 'السلامة والتعديلات', render: renderIntegrity },
        { id: 'raw', label: 'الخرج الخام', render: renderRaw }
    ];

    function render(data) {
        renderSummary(data);
        var tabsNav = $('#tabs');
        var content = $('#tab-content');
        tabsNav.textContent = '';
        content.textContent = '';

        TABS.forEach(function (tab, i) {
            var btn = h('button', {
                class: 'tab' + (i === 0 ? ' active' : ''), type: 'button', text: tab.label,
                onclick: function () {
                    Array.prototype.forEach.call(tabsNav.children, function (c) { c.classList.remove('active'); });
                    btn.classList.add('active');
                    content.textContent = '';
                    content.append(tab.render(data));
                }
            });
            tabsNav.append(btn);
        });
        content.append(TABS[0].render(data));
    }

    function renderSummary(data) {
        var box = $('#summary');
        box.textContent = '';
        var f = data.file;
        var verdict = data.integrity && data.integrity.verdict ? data.integrity.verdict : { level: 'ok', title: '—' };

        box.append(
            h('div', { class: 'summary-preview' }, h('img', { src: f.preview_url, alt: f.name })),
            h('div', {},
                h('div', { class: 'summary-head' },
                    h('h2', { text: f.name }),
                    h('span', { class: 'verdict ' + verdict.level, text: verdict.title })
                ),
                h('div', { class: 'facts' },
                    fact('الحجم', f.size_human),
                    fact('النوع', f.mime),
                    fact('الأبعاد', (f.width || '?') + ' × ' + (f.height || '?')),
                    fact('الامتداد', f.ext),
                    fact('زمن التحليل', (data.meta.elapsed_ms / 1000).toFixed(2) + ' ث')
                ),
                h('div', { class: 'toolbar', style: 'margin-top:14px' },
                    h('button', { class: 'ghost', type: 'button', text: 'تنزيل كل النتائج (JSON)', onclick: function () { download(f.name + '.analysis.json', JSON.stringify(data, null, 2)); } })
                )
            )
        );
    }

    function renderOverview(data) {
        var frag = document.createDocumentFragment();
        var hl = data.highlights || {};

        if (hl.camera && hl.camera.length) {
            frag.append(section('بيانات الكاميرا والتقنية', 'مستخرجة من وسوم EXIF',
                h('div', { class: 'kv-grid' }, hl.camera.map(function (c) { return field(c.label, c.value); }))
            ));
        } else {
            frag.append(section('بيانات الكاميرا والتقنية', null, statusMessage('لا توجد بيانات كاميرا/EXIF في هذه الصورة.')));
        }

        if (hl.gps && hl.gps.available) {
            var links = h('div', { class: 'link-list' },
                h('a', { href: hl.gps.links.google, target: '_blank', rel: 'noopener', text: 'فتح في خرائط Google' }),
                h('a', { href: hl.gps.links.osm, target: '_blank', rel: 'noopener', text: 'OpenStreetMap' }),
                h('a', { href: hl.gps.links.bing, target: '_blank', rel: 'noopener', text: 'Bing Maps' }),
                h('a', { href: hl.gps.links.yandex, target: '_blank', rel: 'noopener', text: 'Yandex Maps' })
            );
            frag.append(section('الموقع الجغرافي', hl.gps.position,
                h('div', { class: 'facts' },
                    fact('خط العرض', hl.gps.lat),
                    fact('خط الطول', hl.gps.lng),
                    fact('الارتفاع', hl.gps.altitude || '—'),
                    fact('الاتجاه', hl.gps.direction || '—')
                ),
                links
            ));
        }

        if (hl.privacy && hl.privacy.length) {
            frag.append(section('بيانات حساسة / خصوصية', 'حقول قد تكشف معلومات عنك',
                h('div', { class: 'kv-grid' }, hl.privacy.map(function (p) { return field(p.label, p.value); }))
            ));
        }

        if (hl.editing && hl.editing.length) {
            frag.append(section('مؤشرات التعديل', null,
                h('div', { class: 'kv-grid' }, hl.editing.map(function (p) { return field(p.label, p.value); }))
            ));
        }

        var hashes = data.file.hashes;
        frag.append(section('البصمات (Hash)', null,
            h('div', { class: 'kv-grid' },
                field('MD5', hashes.md5),
                field('SHA-1', hashes.sha1),
                field('SHA-256', hashes.sha256)
            )
        ));

        return frag;
    }

    function dataTable(columns, rows) {
        var thead = h('thead', {}, h('tr', {}, columns.map(function (c) { return h('th', { text: c.label }); })));
        var tbody = h('tbody', {}, rows.map(function (row) {
            return h('tr', {}, columns.map(function (c) {
                return h('td', { class: c.class || '', text: c.get(row) });
            }));
        }));
        return h('div', { class: 'table-scroll' }, h('table', { class: 'data' }, thead, tbody));
    }

    function renderExif(data) {
        var t = tool('exiftool');
        var frag = document.createDocumentFragment();
        if (!t || t.status !== 'ok' || !t.data) {
            frag.append(section('ExifTool', null, statusMessage((t && t.note) || 'البيانات غير متوفرة.', t ? t.status : 'missing')));
            return frag;
        }
        var items = t.data.items || [];
        var tableBody = h('tbody', {}, items.map(function (it) {
            var row = h('tr', {},
                h('td', {}, h('span', { class: 'tag-group', text: it.group })),
                h('td', { text: it.tag }),
                h('td', { class: 'value', text: it.value })
            );
            row.dataset.search = (it.group + ' ' + it.tag + ' ' + it.value).toLowerCase();
            return row;
        }));
        var table = h('div', { class: 'table-scroll' }, h('table', { class: 'data' },
            h('thead', {}, h('tr', {}, h('th', { text: 'المجموعة' }), h('th', { text: 'الوسم' }), h('th', { text: 'القيمة' }))),
            tableBody
        ));
        var filter = h('input', { class: 'filter', type: 'search', placeholder: 'تصفية الوسوم...', oninput: function () {
            var q = this.value.toLowerCase();
            Array.prototype.forEach.call(tableBody.children, function (tr) {
                tr.style.display = (!q || tr.dataset.search.indexOf(q) !== -1) ? '' : 'none';
            });
        } });
        frag.append(section('ExifTool — ' + t.note, null, filter, table));
        if (t.data.warnings && t.data.warnings.length) {
            frag.append(section('تحذيرات ExifTool', null, t.data.warnings.map(function (w) {
                return h('div', { class: 'finding warn' }, h('span', { class: 'dot' }), h('div', {}, h('div', { class: 'f-detail', text: w })));
            })));
        }
        return frag;
    }

    function renderExiv2(data) {
        var t = tool('exiv2');
        var frag = document.createDocumentFragment();
        if (!t || t.status !== 'ok' || !t.data) {
            frag.append(section('Exiv2', null, statusMessage((t && t.note) || 'البيانات غير متوفرة.', t ? t.status : 'missing')));
            return frag;
        }
        var items = t.data.items || [];
        var tableBody = h('tbody', {}, items.map(function (it) {
            var row = h('tr', {}, h('td', { class: 'value', text: it.key }), h('td', { text: it.type }), h('td', { class: 'value', text: it.value }));
            row.dataset.search = (it.key + ' ' + it.value).toLowerCase();
            return row;
        }));
        var filter = h('input', { class: 'filter', type: 'search', placeholder: 'تصفية...', oninput: function () {
            var q = this.value.toLowerCase();
            Array.prototype.forEach.call(tableBody.children, function (tr) { tr.style.display = (!q || tr.dataset.search.indexOf(q) !== -1) ? '' : 'none'; });
        } });
        frag.append(section('Exiv2 — ' + t.note, null, filter,
            h('div', { class: 'table-scroll' }, h('table', { class: 'data' },
                h('thead', {}, h('tr', {}, h('th', { text: 'المفتاح' }), h('th', { text: 'النوع' }), h('th', { text: 'القيمة' }))),
                tableBody))));
        return frag;
    }

    function renderImageMagick(data) {
        var t = tool('imagemagick');
        var frag = document.createDocumentFragment();
        if (!t || t.status !== 'ok' || !t.data) {
            frag.append(section('ImageMagick', null, statusMessage((t && t.note) || 'البيانات غير المتوفرة.', t ? t.status : 'missing')));
            return frag;
        }
        var highlights = t.data.highlights || {};
        var grid = Object.keys(highlights).map(function (k) { return field(k, highlights[k]); });
        if (grid.length) frag.append(section('خصائص الصورة', t.note, h('div', { class: 'kv-grid' }, grid)));
        var props = t.data.properties || {};
        var propFields = Object.keys(props).slice(0, 200).map(function (k) { return field(k, props[k]); });
        if (propFields.length) frag.append(section('خصائص إضافية', null, h('div', { class: 'kv-grid' }, propFields)));
        return frag;
    }

    function renderStructure(data) {
        var frag = document.createDocumentFragment();
        var s = data.structure || {};
        frag.append(section('بنية الملف', 'النوع المكتشف: ' + (s.format || 'غير معروف'),
            h('div', { class: 'facts' },
                fact('الصيغة', s.format || '—'),
                fact('بيانات ملحقة', (s.trailing_bytes || 0) + ' بايت')
            )
        ));

        if (s.notes && s.notes.length) {
            frag.append(section('ملاحظات البنية', null, s.notes.map(function (n) {
                return h('div', { class: 'finding ' + n.level }, h('span', { class: 'dot' }), h('div', {}, h('div', { class: 'f-detail', text: n.text })));
            })));
        }

        if (s.segments && s.segments.length) {
            frag.append(section('مقاطع JPEG', null, dataTable([
                { label: '#', get: function (r, i) { return r.offset; } },
                { label: 'الماركر', get: function (r) { return r.marker; } },
                { label: 'الاسم', get: function (r) { return r.name; } },
                { label: 'الحجم', get: function (r) { return r.size; } },
                { label: 'ملاحظة', get: function (r) { return r.info || '—'; } }
            ], s.segments)));
        }

        if (s.chunks && s.chunks.length) {
            frag.append(section('مقاطع PNG', (s.crc_errors && s.crc_errors.length) ? 'يوجد أخطاء CRC!' : null, dataTable([
                { label: 'النوع', get: function (r) { return r.type; } },
                { label: 'الحجم', get: function (r) { return r.size; } },
                { label: 'CRC', get: function (r) { return r.crc_ok ? 'سليم' : 'تالف'; } },
                { label: 'ملاحظة', get: function (r) { return r.info || '—'; } }
            ], s.chunks)));
        }

        if (s.comments && s.comments.length) {
            frag.append(section('التعليقات', null, s.comments.map(function (c) { return h('span', { class: 'chip', text: c }); })));
        }

        var bw = tool('binwalk');
        if (bw && bw.status === 'ok' && bw.data) {
            var entries = bw.data.entries || [];
            frag.append(section('Binwalk — الملفات المضمّنة/التوقيعات', bw.note,
                entries.length ? dataTable([
                    { label: 'الإزاحة', get: function (r) { return r.offset; } },
                    { label: 'Hex', get: function (r) { return r.offset_hex; } },
                    { label: 'الوصف', get: function (r) { return r.description; } }
                ], entries) : statusMessage('لم يُعثر على توقيعات معروفة داخل الملف.', 'ok')
            ));
        } else if (bw) {
            frag.append(section('Binwalk', null, statusMessage(bw.note || 'غير متوفر.', bw.status)));
        }

        return frag;
    }

    function renderStrings(data) {
        var t = tool('strings');
        var frag = document.createDocumentFragment();
        if (!t || t.status !== 'ok' || !t.data) {
            frag.append(section('Strings', null, statusMessage((t && t.note) || 'غير متوفر.', t ? t.status : 'missing')));
            return frag;
        }
        frag.append(section('Strings', t.note, h('div', { class: 'facts' },
            fact('إجمالي النصوص الممسوحة', t.data.total),
            fact('روابط', (t.data.urls || []).length),
            fact('بُرد إلكترونية', (t.data.emails || []).length)
        )));

        if (t.data.urls && t.data.urls.length) {
            frag.append(section('الروابط المستخرجة', null, t.data.urls.map(function (u) {
                return h('div', {}, h('a', { href: u.text, target: '_blank', rel: 'noopener', text: u.text }), h('span', { class: 'muted', text: ' @ ' + u.offset }));
            })));
        }
        if (t.data.emails && t.data.emails.length) {
            frag.append(section('البُرد الإلكترونية', null, t.data.emails.map(function (e) { return h('span', { class: 'chip', text: e.text }); })));
        }
        if (t.data.interesting && t.data.interesting.length) {
            frag.append(section('نصوص مهمة', null, t.data.interesting.map(function (s) {
                return h('div', { class: 'finding info' }, h('span', { class: 'dot' }),
                    h('div', {}, h('div', { class: 'f-title', text: 'تطابق: ' + s.match }),
                        h('div', { class: 'f-detail', text: '[' + s.offset + '] ' + s.text })));
            })));
        }
        if (t.data.lines && t.data.lines.length) {
            frag.append(section('مقتطف النصوص الخام', 'أول ' + t.data.lines.length + ' سطر', tableScroll(t.data.lines.map(function (l) { return '[' + l.offset + '] ' + l.text; }).join('\n'))));
        }
        return frag;
    }

    function tableScroll(text) {
        return h('pre', { class: 'raw', text: text });
    }

    function renderStego(data) {
        var frag = document.createDocumentFragment();

        var ent = data.entropy || {};
        var chart = h('canvas', { class: 'chart' });
        frag.append(section('إنتروبيا الملف', ent.interpretation || '', h('div', { class: 'facts' },
            fact('الإنتروبيا الكلية', ent.overall + ' / 8'),
            fact('أعلى قيمة', ent.max),
            fact('أدنى قيمة', ent.min)
        ), chart));
        setTimeout(function () { drawEntropy(chart, ent.points || []); }, 0);

        var lsb = data.lsb || {};
        if (lsb.available) {
            var chanWrap = h('div', { class: 'grid-2' }, Object.keys(lsb.channels || {}).map(function (name) {
                var ch = lsb.channels[name];
                var runs = (ch.printable_runs || []).map(function (r) { return h('span', { class: 'chip', text: r.text }); });
                return h('div', {},
                    h('div', { class: 'f-title', text: 'القناة ' + name }),
                    h('div', { class: 'muted', text: 'نسبة البتات = 1: ' + (ch.ones_ratio * 100).toFixed(2) + '%' }),
                    h('div', { class: 'bar' }, h('span', { style: 'width:' + (ch.ones_ratio * 100).toFixed(2) + '%' })),
                    h('div', { class: 'muted', text: 'نسبة النص القابل للطباعة: ' + ch.printable_ratio + '%' }),
                    runs.length ? h('div', {}, runs) : null
                );
            }));
            frag.append(section('تحليل البتات الأقل أهمية (LSB)', lsb.note, chanWrap));
        } else {
            frag.append(section('تحليل البتات الأقل أهمية (LSB)', null, statusMessage(lsb.note || 'غير متوفر.', 'missing')));
        }

        var st = tool('stego');
        if (st && st.status === 'ok' && st.data) {
            var z = st.data.zsteg || {};
            if (z.available) {
                var zf = z.findings || [];
                frag.append(section('zsteg (PNG/BMP)', z.note, zf.length ? dataTable([
                    { label: 'الطريقة', get: function (r) { return r.label; } },
                    { label: 'النتيجة', get: function (r) { return r.value; } },
                    { label: 'ملاحظة', get: function (r) { return r.note || '—'; } }
                ], zf) : statusMessage('لم يكتشف zsteg أي بيانات مخفية.', 'ok')));
            } else {
                frag.append(section('zsteg (PNG/BMP)', null, statusMessage(z.note || 'غير متوفر.', 'missing')));
            }

            var sh = st.data.steghide || {};
            frag.append(section('steghide', null,
                sh.available ? h('div', { class: 'finding ' + (sh.embedded ? 'danger' : 'ok') }, h('span', { class: 'dot' }),
                    h('div', {}, h('div', { class: 'f-detail', text: sh.note })))
                : statusMessage(sh.note || 'غير متوفر.', 'missing')
            ));
        }
        return frag;
    }

    function drawEntropy(canvas, points) {
        var rect = canvas.getBoundingClientRect();
        var dpr = window.devicePixelRatio || 1;
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        var ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        var w = rect.width, hgt = rect.height, pad = 24;

        ctx.clearRect(0, 0, w, hgt);
        ctx.strokeStyle = '#2a3444';
        ctx.fillStyle = '#8b98a9';
        ctx.font = '11px monospace';
        [0, 2, 4, 6, 8].forEach(function (val) {
            var y = hgt - pad - (val / 8) * (hgt - pad * 2);
            ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(w - 6, y); ctx.stroke();
            ctx.fillText(val.toFixed(1), 2, y + 3);
        });
        if (!points.length) return;
        ctx.strokeStyle = '#2f81f7';
        ctx.lineWidth = 1.4;
        ctx.beginPath();
        points.forEach(function (p, i) {
            var x = pad + (i / (points.length - 1 || 1)) * (w - pad - 6);
            var y = hgt - pad - (Math.min(8, p.value) / 8) * (hgt - pad * 2);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.stroke();
    }

    function renderIntegrity(data) {
        var frag = document.createDocumentFragment();
        var integ = data.integrity || {};
        var verdict = integ.verdict || { level: 'ok', title: '—' };
        frag.append(section('الحكم العام', null, h('span', { class: 'verdict ' + verdict.level, text: verdict.title })));

        if (integ.findings && integ.findings.length) {
            frag.append(section('النتائج', null, integ.findings.map(function (f) {
                return h('div', { class: 'finding ' + f.level }, h('span', { class: 'dot' }),
                    h('div', {}, h('div', { class: 'f-title', text: f.title }), h('div', { class: 'f-detail', text: f.detail })));
            })));
        }

        if (integ.dates && integ.dates.length) {
            frag.append(section('التواريخ', null, dataTable([
                { label: 'الحقل', get: function (r) { return r.label; } },
                { label: 'القيمة', get: function (r) { return r.value; } }
            ], integ.dates)));
        }
        return frag;
    }

    function renderRaw(data) {
        var frag = document.createDocumentFragment();
        (data.tools || []).forEach(function (t) {
            var hasRaw = typeof t.raw === 'string' && t.raw.trim() !== '';
            frag.append(section(t.icon + ' ' + t.name, t.note || (t.status === 'ok' ? null : t.status),
                h('div', { class: 'toolbar' },
                    hasRaw ? h('button', { class: 'ghost', type: 'button', text: 'تنزيل', onclick: function () { download(t.id + '.txt', t.raw); } }) : null,
                    hasRaw ? copyButton(function () { return t.raw; }) : null,
                    h('span', { class: 'pill', text: 'المدة: ' + t.duration_ms + ' م.ث' })
                ),
                hasRaw ? h('pre', { class: 'raw', text: t.raw }) : statusMessage('لا يوجد خرج خام.', 'missing')
            ));
        });

        frag.append(section('قراءة الصورة', null,
            h('button', { class: 'ghost', type: 'button', text: 'تنزيل كل النتائج (JSON)', onclick: function () { download(data.file.name + '.analysis.json', JSON.stringify(data, null, 2)); } })
        ));
        return frag;
    }
})();
