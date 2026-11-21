{{-- Scan to PDF: kamera perangkat dan penyesuaian setiap foto. --}}
<div class="camera" data-camera>
    <div class="toolbar-row">
        <button type="button" class="btn btn-secondary" data-camera-start><x-icon name="camera" size="18" />Buka kamera</button>
        <button type="button" class="btn btn-primary" data-camera-capture hidden><x-icon name="camera" size="22" />Ambil foto</button>
        <button type="button" class="btn btn-ghost" data-camera-stop hidden>Tutup kamera</button>
    </div>
    <p class="field-hint" data-camera-status aria-live="polite">Di ponsel, tombol "Pilih berkas" juga dapat langsung membuka kamera.</p>
    <video data-camera-video playsinline muted hidden></video>
</div>
<div class="scan-grid" data-scan-grid></div>
