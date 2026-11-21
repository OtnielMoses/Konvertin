{{-- Bookmark PDF: daftar bookmark bertingkat dan pratinjau halaman tujuan. --}}
<div class="preview-stage bookmark-stage" data-bookmarks hidden>
    <div class="file-list-head">
        <h2 id="judul-bookmark">Bookmark</h2>
        <span class="muted" data-bookmark-status aria-live="polite"></span>
    </div>
    <div class="bookmark-layout">
        <div class="bookmark-list-panel">
            <ol class="bookmark-list" aria-labelledby="judul-bookmark" data-bookmark-list></ol>
            <p class="empty-files" data-bookmark-empty hidden>Belum ada bookmark. Tambahkan bookmark pertama untuk halaman yang sedang ditampilkan.</p>
            <button type="button" class="btn btn-secondary" data-bookmark-add><x-icon name="plus" size="16" />Tambah bookmark</button>
        </div>
        <figure class="bookmark-preview">
            <div class="bookmark-canvas" data-bookmark-canvas></div>
            <figcaption>
                <button type="button" class="btn btn-ghost btn-sm btn-icon" data-bookmark-prev aria-label="Halaman sebelumnya"><x-icon name="chevron-left" size="16" /></button>
                <span data-bookmark-page aria-live="polite"></span>
                <button type="button" class="btn btn-ghost btn-sm btn-icon" data-bookmark-next aria-label="Halaman berikutnya"><x-icon name="chevron-right" size="16" /></button>
            </figcaption>
        </figure>
    </div>
    <p class="visually-hidden" aria-live="polite" data-bookmark-announce></p>
</div>
