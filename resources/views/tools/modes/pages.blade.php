{{-- Organize PDF: susun halaman lewat thumbnail. --}}
<input type="hidden" name="options[spec]" data-pages-output>
<div class="preview-stage" data-pages hidden>
    <div class="file-list-head">
        <h2>Halaman</h2>
        <span class="muted" data-pages-status aria-live="polite"></span>
    </div>
    <div class="toolbar-row" role="toolbar" aria-label="Aksi halaman terpilih">
        <button type="button" class="btn btn-secondary btn-sm" data-pages-action="select-all">Pilih semua</button>
        <button type="button" class="btn btn-secondary btn-sm" data-pages-action="rotate" disabled>Putar terpilih</button>
        <button type="button" class="btn btn-secondary btn-sm" data-pages-action="delete" disabled>Hapus terpilih</button>
        <button type="button" class="btn btn-secondary btn-sm" data-pages-action="extract" disabled>Ambil terpilih saja</button>
        <button type="button" class="btn btn-secondary btn-sm" data-pages-action="blank">Sisipkan halaman kosong</button>
        <span class="spacer"></span>
        <button type="button" class="btn btn-ghost btn-sm" data-pages-action="reset">Kembalikan susunan awal</button>
    </div>
    <p class="field-hint" style="margin-top: 8px;">Seret thumbnail untuk mengubah urutan, atau pakai tombol panah. Halaman kosong disisipkan setelah halaman terpilih terakhir.</p>
    <div class="page-grid" data-page-grid></div>
</div>
