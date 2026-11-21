@props(['size' => 30])
{{--
    Logo Konvertin: buku terbuka di atas ubin Electric Iris (Desain2.md). Warna rata
    tanpa gradien; pita pembatas kuning mengikuti lapisan bentuk dekoratif.
--}}
<svg {{ $attributes->merge(['class' => 'logo-mark']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <rect width="32" height="32" rx="10" fill="#2727e6" />
    <path d="M16 11.4c-2.6-1.9-5.7-2.5-8.7-2.2a.8.8 0 0 0-.7.8v11.6c0 .5.4.8.9.8 2.9-.2 5.9.4 8.5 2.3z" fill="#fff" />
    <path d="M16 11.4c2.6-1.9 5.7-2.5 8.7-2.2.4 0 .7.4.7.8v11.6c0 .5-.4.8-.9.8-2.9-.2-5.9.4-8.5 2.3z" fill="#e1edff" />
    <path d="M9.4 13.4c1.6-.1 3.1.2 4.3.8M9.4 16.3c1.6-.1 3.1.2 4.3.8M9.4 19.2c1.2 0 2.3.2 3.2.5" fill="none" stroke="#9a9af5" stroke-width="1.1" stroke-linecap="round" />
    <path d="M20.6 9.7v5.6l1.5-1.1 1.5 1.1V9.5c-1-.1-2 0-3 .2z" fill="#ffda00" />
</svg>
