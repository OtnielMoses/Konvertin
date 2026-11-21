/*
 * Scan to PDF: capture with the device camera or upload photos, then adjust each
 * one (rotate, four corners for perspective correction, order) before building the PDF.
 * Corner handles are draggable and also movable with arrow keys.
 */

import { button, el } from '../lib/dom.js';

const DEFAULT_CORNERS = [[0.03, 0.03], [0.97, 0.03], [0.97, 0.97], [0.03, 0.97]];
const CORNER_NAMES = ['kiri atas', 'kanan atas', 'kanan bawah', 'kiri bawah'];

export default function initScan(wb) {
    const { form } = wb;
    const grid = form.querySelector('[data-scan-grid]');
    const output = form.querySelector('[data-scan-output]');
    const startButton = form.querySelector('[data-camera-start]');
    const captureButton = form.querySelector('[data-camera-capture]');
    const stopButton = form.querySelector('[data-camera-stop]');
    const video = form.querySelector('[data-camera-video]');
    const status = form.querySelector('[data-camera-status]');
    let stream = null;
    let shots = 0;

    function stopCamera() {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.hidden = true;
        captureButton.hidden = true;
        stopButton.hidden = true;
        startButton.hidden = false;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        startButton.hidden = true;
        status.textContent = 'Peramban ini tidak menyediakan akses kamera. Unggah foto hasil pindai lewat tombol Pilih berkas.';
    }

    startButton.addEventListener('click', async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 2480 } }, audio: false });
            video.srcObject = stream;
            await video.play();
            video.hidden = false;
            captureButton.hidden = false;
            stopButton.hidden = false;
            startButton.hidden = true;
            status.textContent = 'Arahkan kamera ke dokumen, lalu tekan Ambil foto.';
            captureButton.focus();
        } catch (error) {
            status.textContent = 'Kamera tidak dapat dibuka. Izinkan akses kamera di peramban, atau unggah foto yang sudah ada.';
        }
    });

    captureButton.addEventListener('click', () => {
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        canvas.toBlob((blob) => {
            shots += 1;
            wb.addFiles([new File([blob], `scan-${String(shots).padStart(2, '0')}.jpg`, { type: 'image/jpeg' })]);
            status.textContent = `Foto ${shots} ditambahkan.`;
        }, 'image/jpeg', 0.9);
    });

    stopButton.addEventListener('click', () => {
        stopCamera();
        startButton.focus();
    });
    window.addEventListener('pagehide', stopCamera);

    function writeOutput() {
        output.value = JSON.stringify(wb.files.map((entry) => ({ rotate: entry.rotate || 0, corners: entry.corners || DEFAULT_CORNERS })));
    }

    function drawOutline(svg, corners) {
        svg.replaceChildren();
        const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        polygon.setAttribute('points', corners.map(([x, y]) => `${x * 100},${y * 100}`).join(' '));
        polygon.setAttribute('fill', 'rgba(217,72,50,0.12)');
        polygon.setAttribute('stroke', '#d94832');
        polygon.setAttribute('stroke-width', '0.8');
        svg.append(polygon);
    }

    function render() {
        grid.replaceChildren();

        wb.files.forEach((entry, index) => {
            entry.corners ??= DEFAULT_CORNERS.map((corner) => corner.slice());
            entry.rotate ??= 0;
            entry.url ??= URL.createObjectURL(entry.file);

            const image = el('img', { src: entry.url, alt: `Foto ${index + 1}` });
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 100 100');
            svg.setAttribute('preserveAspectRatio', 'none');
            const frame = el('div', { className: 'scan-frame' }, [image, svg]);

            entry.corners.forEach((corner, cornerIndex) => {
                const handle = el('span', {
                    className: 'corner-handle',
                    tabindex: '0',
                    role: 'slider',
                    'aria-label': `Sudut ${CORNER_NAMES[cornerIndex]} foto ${index + 1}. Gunakan tombol panah untuk menggeser.`,
                    'aria-valuetext': `${Math.round(corner[0] * 100)}%, ${Math.round(corner[1] * 100)}%`,
                    style: `left:${corner[0] * 100}%;top:${corner[1] * 100}%`,
                });
                const place = () => {
                    handle.style.left = `${corner[0] * 100}%`;
                    handle.style.top = `${corner[1] * 100}%`;
                    handle.setAttribute('aria-valuetext', `${Math.round(corner[0] * 100)}%, ${Math.round(corner[1] * 100)}%`);
                    drawOutline(svg, entry.corners);
                    writeOutput();
                };
                handle.addEventListener('pointerdown', (event) => {
                    handle.setPointerCapture(event.pointerId);
                    const move = (moveEvent) => {
                        const box = frame.getBoundingClientRect();
                        corner[0] = Math.min(1, Math.max(0, (moveEvent.clientX - box.left) / box.width));
                        corner[1] = Math.min(1, Math.max(0, (moveEvent.clientY - box.top) / box.height));
                        place();
                    };
                    handle.addEventListener('pointermove', move);
                    handle.addEventListener('pointerup', () => handle.removeEventListener('pointermove', move), { once: true });
                });
                handle.addEventListener('keydown', (event) => {
                    const step = event.shiftKey ? 0.05 : 0.01;
                    const delta = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[event.key];
                    if (delta) {
                        event.preventDefault();
                        corner[0] = Math.min(1, Math.max(0, corner[0] + delta[0]));
                        corner[1] = Math.min(1, Math.max(0, corner[1] + delta[1]));
                        place();
                    }
                });
                frame.append(handle);
            });
            drawOutline(svg, entry.corners);

            const rotation = el('span', { className: 'file-meta', text: entry.rotate ? `Diputar ${entry.rotate}°` : 'Tanpa rotasi' });
            const actions = el('div', { className: 'toolbar-row' }, [
                button('Putar', {
                    iconName: 'rotate', accessibleName: `Putar foto ${index + 1} 90 derajat`, className: 'btn btn-secondary btn-sm',
                    onClick: () => {
                        entry.rotate = (entry.rotate + 90) % 360;
                        rotation.textContent = `Diputar ${entry.rotate}°`;
                        writeOutput();
                    },
                }),
                button('Naik', { iconName: 'arrow-up', iconOnly: true, accessibleName: `Pindahkan foto ${index + 1} lebih awal`, disabled: index === 0, onClick: () => move(index, index - 1) }),
                button('Turun', { iconName: 'arrow-down', iconOnly: true, accessibleName: `Pindahkan foto ${index + 1} lebih akhir`, disabled: index === wb.files.length - 1, onClick: () => move(index, index + 1) }),
                button('Hapus', { iconName: 'trash', iconOnly: true, className: 'btn btn-danger-ghost btn-sm', accessibleName: `Hapus foto ${index + 1}`, onClick: () => removeAt(index) }),
            ]);

            grid.append(el('div', { className: 'scan-item' }, [
                el('div', { className: 'page-label' }, [el('span', { text: `Halaman ${index + 1}` }), rotation]),
                frame,
                el('p', { className: 'field-hint', text: 'Geser titik sudut ke tepi kertas.' }),
                actions,
            ]));
        });

        writeOutput();
    }

    function move(from, to) {
        const files = wb.files.slice();
        const [moved] = files.splice(from, 1);
        files.splice(to, 0, moved);
        wb.setFiles(files);
    }

    function removeAt(index) {
        const files = wb.files.slice();
        const [removed] = files.splice(index, 1);
        if (removed?.url) {
            URL.revokeObjectURL(removed.url);
        }
        wb.setFiles(files);
    }

    wb.on('files', render);
    wb.on('collect', writeOutput);
    wb.on('reset', stopCamera);
}
