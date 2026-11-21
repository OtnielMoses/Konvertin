@php
    $palettes = [
        'edit-pdf' => [
            'select' => ['cursor', 'Pilih'],
            'text' => ['text', 'Teks'],
            'image' => ['image', 'Gambar'],
            'rect' => ['square', 'Kotak'],
            'ellipse' => ['circle', 'Elips'],
            'line' => ['line', 'Garis'],
            'ink' => ['pen', 'Coretan'],
            'highlight' => ['highlight', 'Highlight'],
            'note' => ['note', 'Komentar'],
        ],
        'sign-pdf' => ['select' => ['cursor', 'Pilih & geser']],
        'redact-pdf' => ['select' => ['cursor', 'Pilih'], 'redact' => ['square', 'Area redaksi']],
        'crop-pdf' => ['crop' => ['crop', 'Area potong']],
        'pdf-forms' => ['select' => ['cursor', 'Pilih'], 'field-text' => ['text', 'Field teks'], 'field-checkbox' => ['form', 'Checkbox']],
    ];
    $palette = $palettes[$tool->slug] ?? ['select' => ['cursor', 'Pilih']];
@endphp
<div class="preview-stage editor" data-editor data-editor-tool="{{ $tool->slug }}" hidden>
    <div class="editor-toolbar" role="toolbar" aria-label="Alat penyunting">
        @foreach ($palette as $key => [$icon, $label])
            <button type="button" class="tool-btn" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" data-editor-mode="{{ $key }}">
                <x-icon :name="$icon" size="18" />{{ $label }}
            </button>
        @endforeach
        @if ($tool->slug === 'edit-pdf')
            <label for="editor-image" class="visually-hidden">Pilih gambar untuk ditambahkan</label>
            <input type="file" id="editor-image" accept="image/png,image/jpeg" hidden data-editor-image>
        @endif
    </div>
    <div class="page-nav">
        <button type="button" class="btn btn-ghost btn-icon" data-page-prev aria-label="Halaman sebelumnya"><x-icon name="chevron-left" /></button>
        <span data-page-indicator aria-live="polite">Halaman 1</span>
        <button type="button" class="btn btn-ghost btn-icon" data-page-next aria-label="Halaman berikutnya"><x-icon name="chevron-right" /></button>
    </div>
    <div class="editor-stage" data-editor-stage>
        <div class="page-wrap" data-page-wrap>
            <canvas data-page-canvas></canvas>
            <div class="overlay" data-overlay aria-label="Halaman PDF. Pilih alat lalu klik atau seret di sini." role="application"></div>
        </div>
    </div>
    <p class="field-hint">Objek terpilih dapat digeser dengan mouse atau tombol panah, diubah ukurannya lewat titik biru, dan dihapus dengan tombol Delete.</p>
    @if ($tool->slug === 'pdf-forms')
        <div class="form-fields" data-form-fields>
            <h2 class="panel-title">Field yang terdeteksi</h2>
            <p class="field-hint" data-form-status>Memeriksa field formulir...</p>
        </div>
    @endif
</div>
