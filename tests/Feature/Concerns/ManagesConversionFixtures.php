<?php

namespace Tests\Feature\Concerns;

use App\Conversion\EngineLocator;
use App\Conversion\WorkspaceManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;

trait ManagesConversionFixtures
{
    protected string $workspaceRoot;

    protected function useTemporaryWorkspaceRoot(): void
    {
        $this->workspaceRoot = storage_path('framework/testing/konvertin-'.bin2hex(random_bytes(4)));
        config(['konvertin.workspace_path' => $this->workspaceRoot]);
        $this->app->forgetInstance(WorkspaceManager::class);

        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($this->workspaceRoot));
    }

    /**
     * Membuat PDF satu halaman yang sah, lengkap dengan tabel xref.
     */
    protected function pdfBytes(string $text = 'Halaman uji'): string
    {
        $stream = 'BT /F1 18 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    protected function pdfUpload(string $name = 'laporan.pdf', string $text = 'Halaman uji'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfBytes($text));
    }

    /**
     * Berkas uji berisi foto acak yang sulit dikompres, dibuat dengan pustaka mesin Python.
     * Jenis "pdf" menghasilkan PDF berisi foto per halaman; jenis "jpg"/"png" menghasilkan gambar.
     */
    protected function photoUpload(string $name, string $kind = 'pdf', int $pages = 3, int $width = 1200, int $height = 900): UploadedFile
    {
        $script = <<<'PY'
import io, sys, numpy, pymupdf
from PIL import Image
kind, pages, width, height = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), int(sys.argv[4])
rng = numpy.random.default_rng(7)
def photo():
    pixels = (rng.random((height, width, 3)) * 90 + numpy.linspace(0, 160, width)[None, :, None]).astype('uint8')
    buffer = io.BytesIO()
    Image.fromarray(pixels).save(buffer, 'JPEG' if kind != 'png' else 'PNG', quality=95)
    return buffer.getvalue()
if kind == 'pdf':
    doc = pymupdf.open()
    for number in range(pages):
        page = doc.new_page()
        page.insert_image(page.rect, stream=photo())
        page.insert_text((72, 72), 'Halaman foto %d' % (number + 1), fontsize=18)
    sys.stdout.buffer.write(doc.tobytes())
else:
    sys.stdout.buffer.write(photo())
PY;

        $locator = app(EngineLocator::class);
        $process = new Process(
            [$locator->python(), '-c', $script, $kind, (string) $pages, (string) $width, (string) $height],
            null,
            $locator->environment(),
        );
        $process->mustRun();

        return UploadedFile::fake()->createWithContent($name, $process->getOutput());
    }

    /**
     * Menjalankan skrip Python pendek dengan pustaka mesin dan mengembalikan isi stdout,
     * untuk membuat berkas uji yang tidak bisa dibuat dengan PHP saja.
     *
     * @param  list<string>  $arguments
     */
    protected function pythonFixture(string $script, array $arguments = []): string
    {
        $locator = app(EngineLocator::class);
        $process = new Process([$locator->python(), '-c', $script, ...$arguments], null, $locator->environment());
        $process->mustRun();

        return $process->getOutput();
    }

    protected function workspaceDirectories(): array
    {
        return is_dir($this->workspaceRoot) ? (new Filesystem)->directories($this->workspaceRoot) : [];
    }
}
