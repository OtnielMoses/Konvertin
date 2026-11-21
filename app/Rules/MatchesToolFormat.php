<?php

namespace App\Rules;

use App\Conversion\FileSniffer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Memastikan isi berkas sesuai format yang diterima (NFR-SEC-2, REQ-3).
 */
class MatchesToolFormat implements ValidationRule
{
    /**
     * @param  list<string>  $formats  Kode format hasil FileSniffer, misalnya ['pdf'].
     * @param  int|null  $imageMaxBytes  Batas khusus foto (JPG/PNG), atau null bila tidak ada.
     */
    public function __construct(
        private array $formats,
        private FileSniffer $sniffer = new FileSniffer,
        private ?int $imageMaxBytes = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('konvertin.msg01'));

            return;
        }

        $format = $this->sniffer->detect((string) $value->getRealPath());

        if ($format === null || ! in_array($format, $this->formats, true)) {
            $fail(__('konvertin.msg01'));

            return;
        }

        if ($this->imageMaxBytes !== null && in_array($format, ['jpg', 'png'], true) && (int) $value->getSize() > $this->imageMaxBytes) {
            $fail(__('konvertin.image_too_large', ['limit' => round($this->imageMaxBytes / 1024 / 1024, 1).' MB']));
        }
    }
}
