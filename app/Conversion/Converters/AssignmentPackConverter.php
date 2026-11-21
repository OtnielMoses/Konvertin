<?php

namespace App\Conversion\Converters;

use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use App\Conversion\ConversionInput;
use App\Conversion\ConversionResult;
use App\Conversion\ConversionService;
use App\Conversion\EngineLocator;
use App\Conversion\Tool;
use App\Conversion\ToolCatalog;
use App\Conversion\Workspace;

/**
 * Assignment Pack Builder. Dokumen Word diubah ke PDF lebih dulu dengan
 * mesin yang sama seperti alat Word to PDF; penyusunan, penomoran akademik,
 * dan kompresi opsional dikerjakan operasi "pack" pada mesin Python.
 * Kompresi memakai Smart Compression yang sama, bukan mesin kedua.
 */
class AssignmentPackConverter implements Converter
{
    private const WORD_FORMATS = ['doc', 'docx'];

    private const IMAGE_FORMATS = ['jpg', 'png'];

    private const COMPRESSION_FIELDS = [
        'target_value', 'target_unit', 'strategy', 'downsample', 'min_dpi', 'min_quality',
        'strip_metadata', 'optimize_resources', 'subset_fonts',
    ];

    public function __construct(
        private EngineLocator $engines,
        private PythonConverter $python,
        private ConversionService $conversions,
        private ToolCatalog $catalog,
    ) {}

    public function isAvailable(Tool $tool): bool
    {
        return $this->engines->pythonReady();
    }

    public function processingNote(Tool $tool): ?string
    {
        if (! $this->wordIsAvailable()) {
            return 'Dokumen Word belum dapat diproses saat ini. Masukkan PDF dan gambar, atau simpan dokumen Word sebagai PDF terlebih dahulu.';
        }

        return $this->conversions->processingNote($this->wordTool());
    }

    public function wordIsAvailable(): bool
    {
        return $this->conversions->isAvailable($this->wordTool());
    }

    public function convert(Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $sources = [];
        $kinds = [];

        foreach ($input->files as $index => $path) {
            $format = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($format, self::WORD_FORMATS, true)) {
                $sources[] = $this->wordToPdf($path, $index, $input, $workspace);
                $kinds[] = 'pdf';

                continue;
            }

            $sources[] = $path;
            $kinds[] = in_array($format, self::IMAGE_FORMATS, true) ? 'image' : 'pdf';
        }

        $payload = $this->python->runOperation('pack', $input, $workspace, $this->engineOptions($input, $kinds), $sources);

        return $this->python->resultFrom($tool, $input, $payload, $workspace);
    }

    /**
     * @throws ConversionFailed
     */
    private function wordToPdf(string $path, int $index, ConversionInput $input, Workspace $workspace): string
    {
        $wordTool = $this->wordTool();
        $converter = $this->conversions->converterFor($wordTool);

        if (! $converter->isAvailable($wordTool)) {
            throw ConversionFailed::because(ConversionFailure::WordUnavailable, 'no word engine for pack');
        }

        $result = $converter->convert($wordTool, new ConversionInput(files: [$path], baseName: $input->baseName), $workspace);
        $target = $workspace->path.DIRECTORY_SEPARATOR.sprintf('pack-word-%02d.pdf', $index + 1);

        if (! rename($result->path, $target)) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'could not stage converted word document');
        }

        return $target;
    }

    /**
     * @param  list<string>  $kinds
     * @return array<string, mixed>
     */
    private function engineOptions(ConversionInput $input, array $kinds): array
    {
        $options = $input->options;
        $compression = ['enabled' => filter_var($options['compress'] ?? false, FILTER_VALIDATE_BOOLEAN)];

        foreach (self::COMPRESSION_FIELDS as $field) {
            if (array_key_exists($field, $options)) {
                $compression[$field] = $options[$field];
                unset($options[$field]);
            }
        }

        unset($options['compress']);

        return [
            ...$options,
            'sections' => json_decode((string) ($options['sections'] ?? '[]'), true) ?: [],
            'numbering' => json_decode((string) ($options['numbering'] ?? '{}'), true) ?: [],
            'compression' => $compression,
            '_titles' => $input->names,
            '_kinds' => $kinds,
            '_time_budget' => $this->python->timeBudget(),
        ];
    }

    private function wordTool(): Tool
    {
        return $this->catalog->find('word-to-pdf');
    }
}
