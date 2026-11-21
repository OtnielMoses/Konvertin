<?php

namespace App\Conversion;

enum ToolCategory: string
{
    case Convert = 'convert';
    case Organize = 'organize';
    case Optimize = 'optimize';
    case Edit = 'edit';
    case Security = 'security';
    case Forms = 'forms';
    case Translate = 'translate';

    public function label(): string
    {
        return match ($this) {
            self::Convert => 'Convert PDF',
            self::Organize => 'Organize PDF',
            self::Optimize => 'Optimize PDF',
            self::Edit => 'Edit PDF',
            self::Security => 'PDF Security',
            self::Forms => 'PDF Forms',
            self::Translate => 'Translate PDF',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Convert => 'Ubah dokumen kuliah ke PDF, atau PDF ke format yang bisa disunting.',
            self::Organize => 'Susun paket tugas, gabung, pisah, urutkan, putar, dan potong halaman.',
            self::Optimize => 'Perkecil ukuran sampai target, perbaiki berkas rusak, dan jadikan hasil pindai bisa dicari.',
            self::Edit => 'Tambahkan teks, gambar, anotasi, nomor halaman, dan watermark.',
            self::Security => 'Kunci, buka, tanda tangani, sensor, dan bandingkan dokumen.',
            self::Forms => 'Isi formulir PDF dan tambahkan field baru.',
            self::Translate => 'Terjemahkan isi PDF ke bahasa lain dengan tata letak dipertahankan.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Convert => 'convert',
            self::Organize => 'organize',
            self::Optimize => 'compress',
            self::Edit => 'edit',
            self::Security => 'lock',
            self::Forms => 'form',
            self::Translate => 'translate',
        };
    }
}
