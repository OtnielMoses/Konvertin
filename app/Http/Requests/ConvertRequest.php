<?php

namespace App\Http\Requests;

use App\Conversion\Tool;
use App\Conversion\ToolCatalog;
use App\Conversion\UploadLimits;
use App\Rules\MatchesToolFormat;
use App\Rules\PublicWebAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;

class ConvertRequest extends FormRequest
{
    /**
     * Alat bersifat publik tanpa registrasi (4.1/REQ-5).
     */
    public function authorize(): bool
    {
        return true;
    }

    public function tool(): Tool
    {
        return app(ToolCatalog::class)->find((string) $this->route('tool'))
            ?? abort(404);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tool = $this->tool();

        if ($tool->usesUrl) {
            return [
                'url' => ['bail', 'required', 'string', 'max:2048', app(PublicWebAddress::class)],
            ];
        }

        $maxFiles = $tool->maxFiles ?? ($tool->acceptsMultiple ? (int) config('konvertin.max_files') : 1);
        $maxKb = (int) config('konvertin.max_file_kb');
        $imageBytes = app(UploadLimits::class)->imageBytes();

        $rules = [
            'files' => ['bail', 'required', 'array', 'min:'.$tool->minFiles, 'max:'.$maxFiles],
            'files.*' => ['bail', 'file', 'max:'.$maxKb, new MatchesToolFormat($tool->inputFormats, imageMaxBytes: $imageBytes)],
        ];

        foreach ($tool->auxFiles as $key => $aux) {
            $required = $key === 'image' ? 'required_if:options.type,image' : 'nullable';
            $rules['aux.'.$key] = ['bail', $required, 'file', 'max:'.$maxKb, new MatchesToolFormat($aux['formats'], imageMaxBytes: $imageBytes)];
        }

        foreach ($tool->optionRules as $field => $fieldRules) {
            $rules['options.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Pesan standar Tabel 3.2.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tool = $this->tool();

        return [
            'files.required' => __('konvertin.msg03'),
            'files.array' => __('konvertin.msg03'),
            'files.min' => $tool->isMerge()
                ? __('konvertin.merge_minimum')
                : __('konvertin.files_minimum', ['min' => $tool->minFiles]),
            'files.max' => $tool->maxFiles !== null && $tool->maxFiles === $tool->minFiles
                ? __('konvertin.files_exact', ['count' => $tool->maxFiles])
                : __('konvertin.msg02'),
            'files.*.file' => __('konvertin.msg02'),
            'files.*.uploaded' => __('konvertin.msg02'),
            'files.*.max' => __('konvertin.msg02'),
            'aux.*.max' => __('konvertin.msg02'),
            'aux.image.required_if' => __('konvertin.aux_required', ['label' => 'gambar watermark']),
            'options.password.confirmed' => __('konvertin.password_confirmation'),
            'options.password.min' => __('konvertin.password_short'),
            'options.terms.required_without' => __('konvertin.redact_empty'),
            'url.required' => __('konvertin.url_required'),
            'url.string' => __('konvertin.url_invalid'),
            'url.max' => __('konvertin.url_invalid'),
        ];
    }

    /**
     * Workflow: setiap langkah divalidasi dengan aturan alatnya sendiri.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return match ($this->tool()->slug) {
            'create-workflow' => [fn (Validator $validator) => $this->validateWorkflow($validator)],
            'assignment-pack' => [fn (Validator $validator) => $this->validatePack($validator)],
            'bookmark-pdf' => [fn (Validator $validator) => $this->validateOutline($validator)],
            default => [],
        };
    }

    /**
     * Bookmark PDF: daftar datar berlevel. Level pertama 1, setiap bookmark paling
     * banyak satu level lebih dalam dari sebelumnya. Halaman diperiksa mesin.
     */
    private function validateOutline(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $fail = fn (string $reason) => $validator->errors()->add('options.outline', __('konvertin.outline_invalid', ['reason' => $reason]));
        $items = json_decode((string) $this->input('options.outline'), true);

        if (! is_array($items) || ! array_is_list($items) || count($items) > 1000) {
            $fail('maksimal 1.000 bookmark dalam satu daftar.');

            return;
        }

        $previous = 0;

        foreach ($items as $index => $item) {
            $number = $index + 1;
            $level = is_array($item) ? filter_var($item['level'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]) : false;
            $page = is_array($item) ? filter_var($item['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $title = is_array($item) && is_string($item['title'] ?? null) ? trim($item['title']) : '';

            if ($title === '' || mb_strlen($title) > 200) {
                $fail("bookmark {$number} perlu judul (maksimal 200 karakter).");

                return;
            }

            if ($page === false) {
                $fail("halaman tujuan bookmark {$number} tidak valid.");

                return;
            }

            if ($level === false || $level > $previous + 1) {
                $fail("bookmark {$number} hanya boleh satu tingkat lebih dalam dari bookmark di atasnya.");

                return;
            }

            $previous = $level;
        }
    }

    /**
     * Assignment Pack: satu bagian untuk setiap berkas, dan aturan penomoran per bagian.
     */
    private function validatePack(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $sectionKeys = ['cover', 'preliminary', 'main', 'appendix'];
        $styles = ['none', 'arabic', 'roman_lower', 'roman_upper'];
        $fail = fn (string $reason) => $validator->errors()->add('options.sections', __('konvertin.pack_invalid', ['reason' => $reason]));

        $sections = json_decode((string) $this->input('options.sections'), true);

        if (! is_array($sections) || ! array_is_list($sections) || count($sections) !== count($this->uploads())) {
            $fail('Setiap dokumen harus berada di satu bagian.');

            return;
        }

        foreach ($sections as $section) {
            if (! in_array($section, $sectionKeys, true)) {
                $fail('Bagian dokumen tidak dikenal.');

                return;
            }
        }

        $numbering = json_decode((string) $this->input('options.numbering'), true);

        if (! is_array($numbering)) {
            $fail('Pengaturan nomor halaman tidak terbaca.');

            return;
        }

        foreach ($numbering as $section => $rule) {
            $start = is_array($rule) ? ($rule['start'] ?? 1) : null;

            if (! in_array($section, $sectionKeys, true)
                || ! in_array($rule['style'] ?? null, $styles, true)
                || filter_var($start, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999]]) === false) {
                $fail('Aturan nomor halaman tidak valid.');

                return;
            }
        }
    }

    private function validateWorkflow(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $catalog = app(ToolCatalog::class);
        $allowed = $catalog->workflowSteps();
        $steps = json_decode((string) $this->input('options.steps'), true);
        $fail = fn (string $reason) => $validator->errors()->add('options.steps', __('konvertin.workflow_invalid', ['reason' => $reason]));

        if (! is_array($steps) || $steps === [] || count($steps) > 8) {
            $fail('Tambahkan satu sampai delapan langkah.');

            return;
        }

        $fileCount = count($this->uploads());

        foreach (array_values($steps) as $index => $step) {
            $slug = is_array($step) ? ($step['tool'] ?? null) : null;
            $number = $index + 1;

            if (! is_string($slug) || ! isset($allowed[$slug])) {
                $fail("Langkah {$number} tidak dikenal.");

                return;
            }

            $tool = $catalog->find($slug);
            $isFirst = $index === 0;
            $isLast = $index === count($steps) - 1;

            if (in_array($slug, ['merge-pdf', 'unlock-pdf'], true) && ! $isFirst) {
                $fail("{$tool->name} hanya bisa menjadi langkah pertama.");

                return;
            }

            if ($slug === 'protect-pdf' && ! $isLast) {
                $fail('Protect PDF hanya bisa menjadi langkah terakhir.');

                return;
            }

            $options = is_array($step['options'] ?? null) ? $step['options'] : [];
            $rules = [];

            foreach ($tool->optionRules as $field => $fieldRules) {
                $rules['options.'.$field] = $fieldRules;
            }

            $check = ValidatorFactory::make(['options' => $options], $rules, $this->messages(), (array) trans('validation.attributes'));

            if ($check->fails()) {
                $fail("Langkah {$number} ({$tool->name}): ".$check->errors()->first());

                return;
            }
        }

        $firstIsMerge = ($steps[0]['tool'] ?? null) === 'merge-pdf';

        if ($firstIsMerge && $fileCount < 2) {
            $validator->errors()->add('files', __('konvertin.merge_minimum'));
        }

        if (! $firstIsMerge && $fileCount > 1) {
            $fail('Tanpa langkah Merge PDF di awal, pilih satu PDF saja.');
        }
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        return array_values(array_filter(
            (array) $this->file('files', []),
            fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }

    /**
     * @return array<string, UploadedFile>
     */
    public function auxUploads(): array
    {
        return array_filter(
            (array) $this->file('aux', []),
            fn (mixed $file): bool => $file instanceof UploadedFile,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toolOptions(): array
    {
        $options = (array) $this->validated('options', []);

        unset($options['password_confirmation']);

        if ($this->tool()->slug === 'create-workflow') {
            $options['steps'] = (string) $this->input('options.steps');
        }

        return $options;
    }
}
