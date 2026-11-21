/*
 * Smart Compression controls and result view. Shared by the Smart Compression tool,
 * the Optimize step of Assignment Pack, and Workflow's compress step, so there is
 * one set of settings and one result display for the one compression engine.
 */

import { el, formatBytes, icon } from './dom.js';

const MB = 1024 * 1024;

export const STRATEGY_LABELS = { quality: 'Utamakan kualitas', balanced: 'Seimbang', size: 'Utamakan ukuran' };

export function targetBytes(scope) {
    const value = Number(scope.querySelector('[data-target-value]')?.value);
    const unit = scope.querySelector('[data-target-unit]')?.value === 'KB' ? 1024 : MB;
    return Number.isFinite(value) && value > 0 ? Math.round(value * unit) : null;
}

/** Rounds a byte count up to a friendly target (0.1 MB or 10 KB steps). */
export function friendlyTarget(bytes) {
    if (bytes >= MB) {
        return { value: Math.ceil((bytes / MB) * 10) / 10, unit: 'MB' };
    }
    return { value: Math.max(10, Math.ceil(bytes / 1024 / 10) * 10), unit: 'KB' };
}

export function bindCompressionOptions(scope, { onChange = () => {} } = {}) {
    const value = scope.querySelector('[data-target-value]');
    const unit = scope.querySelector('[data-target-unit]');
    const sliderBox = scope.querySelector('[data-target-slider]');
    const range = scope.querySelector('[data-target-range]');
    const reference = scope.querySelector('[data-target-reference]');
    let original = null;
    let touched = false;

    function setTarget(bytes) {
        const target = friendlyTarget(bytes);
        value.value = String(target.value);
        unit.value = target.unit;
    }

    function syncSlider() {
        if (!original || !range) {
            return;
        }
        const percent = Math.round(((targetBytes(scope) ?? 0) / original) * 100);
        range.value = String(Math.min(95, Math.max(5, percent)));
        reference.textContent = percent >= 100
            ? `Target sama dengan atau lebih besar dari ukuran asli (${formatBytes(original)}). Berkas tetap dioptimalkan tanpa menurunkan kualitas.`
            : `Sekitar ${percent}% dari ukuran asli ${formatBytes(original)}.`;
    }

    function changed() {
        syncSlider();
        onChange(targetBytes(scope));
    }

    value.addEventListener('input', () => {
        touched = true;
        changed();
    });
    unit.addEventListener('change', () => {
        touched = true;
        changed();
    });
    range?.addEventListener('input', () => {
        touched = true;
        setTarget((original * Number(range.value)) / 100);
        changed();
    });
    scope.querySelectorAll('[data-target-preset]').forEach((chip) => {
        chip.addEventListener('click', () => {
            const [presetValue, presetUnit] = chip.dataset.targetPreset.split('|');
            value.value = presetValue;
            unit.value = presetUnit;
            touched = true;
            changed();
        });
    });

    return {
        /** Shows the slider relative to the document's real size; proposes half of it until the user picks a target. */
        setOriginal(bytes) {
            original = bytes || null;
            if (sliderBox) {
                sliderBox.hidden = !original;
            }
            if (original && !touched) {
                setTarget(original * 0.5);
            }
            changed();
        },
        target: () => targetBytes(scope),
    };
}

function suggestionText(bytes) {
    const target = friendlyTarget(bytes);
    return `${String(target.value).replace('.', ',')} ${target.unit}`;
}

export function renderCompressionReport(report) {
    const before = report.before;
    const after = report.after;
    const target = report.target;
    const widest = Math.max(before, target ?? 0, 1);
    const percent = (bytes) => `${Math.max(1.5, (bytes / widest) * 100)}%`;
    const nodes = [];

    if (report.already_optimal) {
        nodes.push(el('div', { className: 'alert alert-info' }, [icon('alert'), el('p', {
            text: `Berkas ini tidak dapat diperkecil lagi tanpa merusak isinya, jadi Anda menerima berkas asli (${formatBytes(before)}).${target && !report.target_met ? ` Target ${formatBytes(target)} tidak tercapai.` : ''}`,
        })]));
    } else if (report.target_met) {
        nodes.push(el('div', { className: 'alert alert-success' }, [icon('check-circle'), el('p', {
            text: target ? `Target tercapai: hasil ${formatBytes(after)}, di bawah target ${formatBytes(target)}.` : `Ukuran berkurang menjadi ${formatBytes(after)}.`,
        })]));
    } else {
        const recommendations = {
            strategy: `Pilih strategi "Utamakan ukuran" untuk kompresi yang lebih kuat, atau naikkan target menjadi sekitar ${suggestionText(report.suggested_target)}.`,
            few_images: `Gambar hanya sekitar ${String(report.sources?.image_share ?? 0).replace('.', ',')}% dari ukuran berkas. Sisanya berupa teks, font, atau grafik vektor yang tidak dapat diperkecil lebih jauh tanpa merusak dokumen. Naikkan target menjadi sekitar ${suggestionText(report.suggested_target)}.`,
            limit: `Ini batas kompresi yang aman untuk dokumen ini. Naikkan target menjadi sekitar ${suggestionText(report.suggested_target)}, atau bagi dokumen dengan Split PDF.`,
        };
        nodes.push(el('div', { className: 'alert alert-warning' }, [icon('alert'), el('div', {}, [
            el('strong', { text: `Target ${formatBytes(target)} tidak tercapai.` }),
            el('p', { text: `Hasil terkecil yang benar-benar dicapai adalah ${formatBytes(after)}. ${recommendations[report.hint] ?? ''}` }),
        ])]));
    }

    const bars = el('div', { className: 'size-compare', role: 'img', 'aria-label': `Ukuran asli ${formatBytes(before)}, hasil ${formatBytes(after)}${target ? `, target ${formatBytes(target)}` : ''}.` }, [
        el('div', { className: 'size-bar' }, [
            el('span', { className: 'size-bar-label', text: 'Asli' }),
            el('span', { className: 'size-bar-track' }, [el('span', { className: 'size-bar-fill is-original', style: `width:${percent(before)}` })]),
            el('span', { className: 'size-bar-value', text: formatBytes(before) }),
        ]),
        el('div', { className: 'size-bar' }, [
            el('span', { className: 'size-bar-label', text: 'Hasil' }),
            el('span', { className: 'size-bar-track' }, [
                el('span', { className: `size-bar-fill ${report.target_met ? 'is-good' : 'is-result'}`, style: `--to:${percent(after)}` }),
                target ? el('span', { className: 'size-bar-target', style: `left:${percent(target)}`, title: `Target ${formatBytes(target)}` }) : null,
            ]),
            el('span', { className: 'size-bar-value', text: formatBytes(after) }),
        ]),
    ]);
    nodes.push(bars);

    const stats = el('dl', { className: 'report' }, [
        ['Ukuran asli', formatBytes(before)],
        ['Hasil', formatBytes(after), report.target_met && !report.already_optimal],
        ['Target', target ? formatBytes(target) : 'Tidak ditentukan'],
        ['Hemat', formatBytes(Math.max(0, report.saved)), report.saved > 0],
        ['Berkurang', `${String(report.reduction).replace('.', ',')}%`, report.reduction > 0],
    ].map(([label, text, good]) => el('div', { className: `stat${good ? ' is-good' : ''}` }, [el('dt', { text: label }), el('dd', { text })])));
    nodes.push(stats);

    const how = report.images_changed
        ? `gambar diturunkan sampai ${report.image_dpi ? `${report.image_dpi} DPI` : 'resolusi aslinya'} dengan kualitas JPEG ${report.image_quality}`
        : 'gambar tidak diubah, hanya struktur berkas yang dioptimalkan';
    nodes.push(el('p', { className: 'field-hint', text: `Strategi ${STRATEGY_LABELS[report.strategy] ?? report.strategy}: ${how}. ${report.passes} percobaan kompresi dijalankan.` }));

    return el('div', { className: 'compress-result' }, nodes);
}
