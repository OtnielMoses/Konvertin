<div class="field">
    <label for="{{ $id('password') }}">Kata sandi</label>
    <input type="password" class="input" id="{{ $id('password') }}" name="{{ $name('password') }}" minlength="6" maxlength="128" autocomplete="new-password" required>
    <p class="field-hint">Minimal 6 karakter. Kata sandi tidak disimpan dan tidak dapat dipulihkan.</p>
</div>
<div class="field">
    <label for="{{ $id('password_confirmation') }}">Ulangi kata sandi</label>
    <input type="password" class="input" id="{{ $id('password_confirmation') }}" name="{{ $name('password_confirmation') }}" maxlength="128" autocomplete="new-password" required>
</div>
<div class="field">
    <label for="{{ $id('owner_password') }}">Kata sandi pemilik <span class="muted">(opsional)</span></label>
    <input type="password" class="input" id="{{ $id('owner_password') }}" name="{{ $name('owner_password') }}" maxlength="128" autocomplete="new-password">
    <p class="field-hint">Dipakai untuk mengubah izin di kemudian hari. Bila kosong, Konvertin membuat kata sandi pemilik acak yang tidak disimpan.</p>
</div>
<fieldset class="field" style="border: 0; padding: 0; margin: 0;">
    <legend class="field-label" style="margin-bottom: 6px;">Izinkan pembaca untuk</legend>
    @foreach (['print' => 'Mencetak', 'copy' => 'Menyalin teks', 'modify' => 'Mengubah dan menyusun halaman', 'annotate' => 'Memberi komentar dan mengisi formulir'] as $value => $label)
        <label class="check"><input type="checkbox" name="{{ $name('permissions') }}[]" value="{{ $value }}" @checked(in_array($value, ['print', 'copy'], true))>{{ $label }}</label>
    @endforeach
</fieldset>
