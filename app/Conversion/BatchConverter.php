<?php

namespace App\Conversion;

use App\Conversion\Converters\Converter;
use App\Conversion\Converters\PythonConverter;
use ZipArchive;

/**
 * Banyak berkas untuk alat yang mesinnya mengubah satu berkas sekali jalan
 * (Word, PowerPoint, Excel ke PDF, Smart Compression). Setiap berkas diproses
 * bergantian dengan mesin yang sama, lalu hasilnya digabung menjadi satu PDF
 * (satu bookmark per berkas) atau dikemas terpisah dalam ZIP beserta laporan
 * tiap berkas.
 */
class BatchConverter
{
    public function __construct(
        private PythonConverter $python,
        private ProgressReporter $progress,
    ) {}

    /**
     * @throws ConversionFailed
     */
    public function convert(Converter $converter, Tool $tool, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $outputs = [];

        $count = count($input->files);

        foreach ($input->files as $index => $path) {
            $name = $input->names[$index] ?? $input->baseName;
            $result = $this->progress->within(
                $index / $count * 0.85,
                ($index + 1) / $count * 0.85,
                function () use ($converter, $tool, $path, $name, $workspace) {
                    $this->progress->report(0, 'Memulai konversi');

                    return $converter->convert($tool, new ConversionInput(files: [$path], baseName: $name), $workspace);
                },
                'Berkas '.($index + 1).' dari '.$count.' · ',
            );
            $extension = pathinfo($result->path, PATHINFO_EXTENSION) ?: 'pdf';
            $target = $workspace->path.DIRECTORY_SEPARATOR.sprintf('batch-%02d.%s', $index + 1, $extension);

            if (! rename($result->path, $target)) {
                throw ConversionFailed::because(ConversionFailure::Generic, 'could not stage batch result');
            }

            $outputs[] = [
                'path' => $target,
                'name' => $name,
                'download' => $result->downloadName !== '' ? $result->downloadName : $name.'.'.$extension,
                'report' => $result->report,
            ];
        }

        $separate = ! $tool->batchMerge || ($input->options['merge'] ?? 'one') === 'separate';

        return $this->progress->within(0.85, 1.0, fn () => $separate
            ? $this->zip($outputs, $input, $workspace, $tool->batchMerge ? '-pdf' : '-hasil')
            : $this->merge($outputs, $input, $workspace));
    }

    /**
     * @param  list<array{path: string, name: string, download: string, report: array<string, mixed>}>  $pdfs
     *
     * @throws ConversionFailed
     */
    private function merge(array $pdfs, ConversionInput $input, Workspace $workspace): ConversionResult
    {
        $payload = $this->python->runOperation('merge', $input, $workspace, [
            'bookmarks' => true,
            '_titles' => array_column($pdfs, 'name'),
        ], array_column($pdfs, 'path'));

        return new ConversionResult(
            path: $payload['file'],
            downloadName: $input->baseName.'.pdf',
            mimeType: 'application/pdf',
            report: ['files' => count($pdfs), 'pages' => $payload['report']['pages'] ?? null],
        );
    }

    /**
     * @param  list<array{path: string, name: string, download: string, report: array<string, mixed>}>  $outputs
     *
     * @throws ConversionFailed
     */
    private function zip(array $outputs, ConversionInput $input, Workspace $workspace, string $suffix): ConversionResult
    {
        $this->progress->report(0, 'Mengemas hasil ke dalam ZIP');
        $target = $workspace->outputDirectory().DIRECTORY_SEPARATOR.'result.zip';
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ConversionFailed::because(ConversionFailure::Generic, 'could not create zip');
        }

        $used = [];
        $items = [];

        foreach ($outputs as $output) {
            // Nama sama (misalnya dua "laporan.docx") diberi nomor agar tidak saling menimpa.
            $stem = pathinfo($output['download'], PATHINFO_FILENAME);
            $extension = pathinfo($output['download'], PATHINFO_EXTENSION);
            $name = $stem;
            $number = 1;

            while (isset($used[mb_strtolower($name)])) {
                $number++;
                $name = $stem.' ('.$number.')';
            }

            $used[mb_strtolower($name)] = true;
            $zip->addFile($output['path'], $name.'.'.$extension);
            $items[] = ['name' => $name.'.'.$extension, 'report' => $output['report']];
        }

        $zip->close();

        return new ConversionResult(
            path: $target,
            downloadName: $input->baseName.$suffix.'.zip',
            mimeType: 'application/zip',
            report: ['files' => count($outputs), 'items' => $items],
        );
    }
}
