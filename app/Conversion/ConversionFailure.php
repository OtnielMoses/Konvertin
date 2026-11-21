<?php

namespace App\Conversion;

/**
 * Penyebab kegagalan proses. Setiap penyebab memiliki varian MSG-06.
 */
enum ConversionFailure: string
{
    case Generic = 'generic';
    case Timeout = 'timeout';
    case Encrypted = 'encrypted';
    case NoTables = 'no_tables';
    case NoText = 'no_text';
    case PdfaNotCompliant = 'pdfa_not_compliant';
    case Unreachable = 'unreachable';
    case EngineUnavailable = 'engine_unavailable';
    case ServiceUnreachable = 'service_unreachable';
    case NothingToOcr = 'nothing_to_ocr';
    case WrongPassword = 'wrong_password';
    case NotProtected = 'not_protected';
    case InvalidPages = 'invalid_pages';
    case Unrecoverable = 'unrecoverable';
    case TooManyPages = 'too_many_pages';
    case NoFields = 'no_fields';
    case NothingFound = 'nothing_found';
    case AiRefused = 'ai_refused';
    case AiFailed = 'ai_failed';
    case WordUnavailable = 'word_unavailable';
    case ExportTooLarge = 'export_too_large';
    case InvalidResult = 'invalid_result';

    public function message(): string
    {
        return $this === self::Generic
            ? __('konvertin.msg06')
            : __('konvertin.failure.'.$this->value);
    }

    /**
     * Kode keluar mesin Python (resources/engines/konvertin_engine.py).
     */
    public static function fromEngineExitCode(int $code): self
    {
        return match ($code) {
            3 => self::NoTables,
            4 => self::Encrypted,
            5 => self::NoText,
            6 => self::NothingToOcr,
            7 => self::WrongPassword,
            8 => self::NotProtected,
            9 => self::InvalidPages,
            10 => self::Unrecoverable,
            11 => self::TooManyPages,
            12 => self::NoFields,
            13 => self::NothingFound,
            14 => self::ExportTooLarge,
            default => self::Generic,
        };
    }
}
