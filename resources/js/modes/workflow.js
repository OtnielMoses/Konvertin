/*
 * Create Workflow: build an ordered list of steps. Each step reuses the settings
 * partial of its own tool (cloned from a <template>), and the whole list is sent
 * as options[steps]. Presets are kept in this browser only, without passwords.
 */

import { bindCompressionOptions } from '../lib/compression.js';
import { bindShowWhen, button, el, storage, uid } from '../lib/dom.js';

const PRESET_KEY = 'konvertin-workflows';
const SECRET_FIELDS = ['password', 'password_confirmation', 'owner_password'];

export default function initWorkflow(wb) {
    const { form } = wb;
    const root = form.querySelector('[data-workflow]');
    const list = root.querySelector('[data-workflow-steps]');
    const empty = root.querySelector('[data-workflow-empty]');
    const count = root.querySelector('[data-workflow-count]');
    const addSelect = root.querySelector('[data-workflow-add-select]');
    const output = form.querySelector('[data-workflow-output]');
    const presetSelect = form.querySelector('[data-preset-select]');
    const presetName = form.querySelector('[data-preset-name]');
    const presetStatus = form.querySelector('[data-preset-status]');
    const store = storage();
    const names = {};

    root.querySelectorAll('[data-workflow-template]').forEach((template) => {
        names[template.dataset.workflowTemplate] = template.dataset.workflowName;
    });

    const operationNames = {
        merge: 'merge-pdf', unlock: 'unlock-pdf', repair: 'repair-pdf', rotate: 'rotate-pdf', crop: 'crop-pdf',
        ocr: 'ocr-pdf', compress: 'compress-pdf', pagenumbers: 'page-numbers', watermark: 'watermark-pdf', protect: 'protect-pdf',
        preflight: 'preflight-pdf',
    };
    wb.workflowLabel = (operation) => names[operationNames[operation]] ?? operation;

    let steps = [];

    function readOptions(node) {
        const options = {};
        node.querySelectorAll('[data-field]').forEach((input) => {
            const field = input.dataset.field;
            if (input.type === 'radio') {
                if (input.checked) {
                    options[field] = input.value;
                }
            } else if (input.type === 'checkbox') {
                if (input.dataset.multiple) {
                    options[field] ??= [];
                    if (input.checked) {
                        options[field].push(input.value);
                    }
                } else if (input.checked) {
                    options[field] = input.value;
                }
            } else if (input.type !== 'hidden' || input.value !== '') {
                options[field] = input.value;
            }
        });
        return options;
    }

    function applyOptions(node, options) {
        node.querySelectorAll('[data-field]').forEach((input) => {
            const value = options[input.dataset.field];
            if (value === undefined) {
                return;
            }
            if (input.type === 'radio') {
                input.checked = input.value === value;
            } else if (input.type === 'checkbox') {
                input.checked = Array.isArray(value) ? value.includes(input.value) : String(value) === input.value;
            } else {
                input.value = value;
            }
        });
    }

    function createStep(tool, options = {}) {
        const template = root.querySelector(`[data-workflow-template="${tool}"]`);
        const key = uid();
        const body = el('div', { className: 'settings-body' });
        body.innerHTML = template.innerHTML.replaceAll('__ID__', `wf-${key}`);

        // Give every input a unique name so radio groups of two steps never clash.
        body.querySelectorAll('[name]').forEach((input) => {
            const raw = input.getAttribute('name');
            const multiple = raw.endsWith('[]');
            input.dataset.field = multiple ? raw.slice(0, -2) : raw;
            if (multiple) {
                input.dataset.multiple = '1';
            }
            input.setAttribute('name', `wf_${key}_${raw}`);
        });

        // Crop inside a workflow has no page preview, so only margins are offered.
        if (tool === 'crop-pdf') {
            body.querySelector('[data-field="mode"][value="margins"]')?.click();
            body.querySelector('[data-field="mode"][value="box"]')?.closest('label')?.remove();
        }

        if (tool === 'compress-pdf') {
            bindCompressionOptions(body);
        }

        // Inside a workflow, Preflight only cleans pages; the report belongs to the standalone tool.
        if (tool === 'preflight-pdf') {
            body.querySelector('[data-preflight-target]')?.remove();
            body.querySelector('[data-preflight-intro]')?.remove();
            if (!Object.keys(options).length) {
                body.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                    input.checked = true;
                });
            }
        }

        return { key, tool, body, options };
    }

    function render() {
        list.replaceChildren();
        empty.hidden = steps.length > 0;
        count.textContent = steps.length ? `${steps.length} langkah` : '';

        steps.forEach((step, index) => {
            const node = el('li', { className: 'wf-step', 'data-step': step.key });
            const head = el('div', { className: 'wf-step-head' }, [
                el('span', { className: 'num', text: String(index + 1) }),
                el('strong', { text: names[step.tool] }),
                button('Naik', { iconName: 'arrow-up', iconOnly: true, accessibleName: `Naikkan langkah ${index + 1}`, disabled: index === 0, onClick: () => move(index, index - 1) }),
                button('Turun', { iconName: 'arrow-down', iconOnly: true, accessibleName: `Turunkan langkah ${index + 1}`, disabled: index === steps.length - 1, onClick: () => move(index, index + 1) }),
                button('Hapus', { iconName: 'trash', iconOnly: true, className: 'btn btn-danger-ghost btn-sm', accessibleName: `Hapus langkah ${index + 1}`, onClick: () => removeAt(index) }),
            ]);
            node.append(head);
            if (step.body.childElementCount) {
                node.append(step.body);
            } else {
                node.append(el('p', { className: 'field-hint', text: 'Langkah ini tidak memiliki pengaturan.' }));
            }
            list.append(node);
            applyOptions(step.body, step.options);
            if (!step.bound) {
                bindShowWhen(node);
                step.bound = true;
            }
        });

        writeOutput();
    }

    function snapshot() {
        steps.forEach((step) => {
            step.options = readOptions(step.body);
        });
    }

    function move(from, to) {
        snapshot();
        const [moved] = steps.splice(from, 1);
        steps.splice(to, 0, moved);
        steps.forEach((step) => {
            step.bound = false;
        });
        render();
        list.children[to]?.querySelector('button')?.focus();
    }

    function removeAt(index) {
        snapshot();
        steps.splice(index, 1);
        render();
        addSelect.focus();
    }

    function writeOutput() {
        output.value = JSON.stringify(steps.map((step) => ({ tool: step.tool, options: readOptions(step.body) })));
    }

    root.querySelector('[data-workflow-add]').addEventListener('click', () => {
        snapshot();
        steps.push(createStep(addSelect.value));
        render();
        list.lastElementChild?.querySelector('input, select')?.focus();
    });

    // ------------------------------------------------------------- presets

    function presets() {
        try {
            return JSON.parse(store?.getItem(PRESET_KEY) || '{}');
        } catch (error) {
            return {};
        }
    }

    function refreshPresets() {
        const saved = presets();
        presetSelect.replaceChildren(el('option', { value: '', text: Object.keys(saved).length ? 'Pilih preset' : 'Belum ada preset' }));
        Object.keys(saved).forEach((name) => presetSelect.append(el('option', { value: name, text: name })));
    }

    form.querySelector('[data-preset-save]').addEventListener('click', () => {
        const name = presetName.value.trim();
        if (!store) {
            presetStatus.textContent = 'Penyimpanan peramban tidak tersedia, jadi preset tidak dapat disimpan.';
            return;
        }
        if (!name || !steps.length) {
            presetStatus.textContent = 'Tambahkan langkah dan beri nama preset terlebih dahulu.';
            return;
        }
        const saved = presets();
        saved[name] = steps.map((step) => {
            const options = readOptions(step.body);
            SECRET_FIELDS.forEach((field) => delete options[field]);
            return { tool: step.tool, options };
        });
        try {
            store.setItem(PRESET_KEY, JSON.stringify(saved));
            presetStatus.textContent = `Preset "${name}" disimpan di peramban ini. Kata sandi tidak ikut disimpan.`;
            refreshPresets();
        } catch (error) {
            presetStatus.textContent = 'Preset tidak dapat disimpan.';
        }
    });

    form.querySelector('[data-preset-load]').addEventListener('click', () => {
        const saved = presets()[presetSelect.value];
        if (!saved) {
            presetStatus.textContent = 'Pilih preset terlebih dahulu.';
            return;
        }
        steps = saved.filter((step) => names[step.tool]).map((step) => createStep(step.tool, step.options));
        render();
        presetStatus.textContent = `Preset "${presetSelect.value}" dimuat. Isi kembali kata sandi bila ada langkah Protect atau Unlock.`;
    });

    wb.on('collect', writeOutput);
    wb.on('validate', () => {
        if (!steps.length) {
            return 'Tambahkan minimal satu langkah workflow.';
        }
        const first = steps[0].tool;
        if (first === 'merge-pdf' && wb.files.length < 2) {
            return 'Langkah Merge PDF membutuhkan minimal dua PDF.';
        }
        if (first !== 'merge-pdf' && wb.files.length > 1) {
            return 'Tanpa langkah Merge PDF di awal, pilih satu PDF saja.';
        }
        return null;
    });

    refreshPresets();
    render();
}
