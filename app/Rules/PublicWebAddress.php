<?php

namespace App\Rules;

use App\Conversion\UrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Alamat harus berskema http/https dan tidak mengarah ke jaringan internal
 * (4.6/REQ-3, 4.6/REQ-4, NFR-SEC-6). Host yang tidak dapat diresolusi lolos
 * di sini dan dilaporkan sebagai halaman tidak dapat diakses (UC-06 EP-1).
 */
class PublicWebAddress implements ValidationRule
{
    public function __construct(private UrlGuard $guard) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->guard->hasValidShape($value)) {
            $fail(__('konvertin.url_invalid'));

            return;
        }

        if ($this->guard->pointsToInternalNetwork($value)) {
            $fail(__('konvertin.url_invalid'));
        }
    }
}
