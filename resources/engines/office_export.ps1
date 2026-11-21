<#
    Ekspor dokumen Office ke PDF memakai Microsoft Office yang terpasang
    (otomasi COM). Dipanggil oleh App\Conversion\Converters\MicrosoftOfficeConverter.

    Kode keluar: 0 berhasil, 2 galat umum, 4 dokumen diproteksi kata sandi.

    Pengamanan:
    - AutomationSecurity = 3 (msoAutomationSecurityForceDisable): makro tidak dijalankan.
    - Dokumen dibuka read-only, tanpa masuk daftar "Recent", tanpa memperbarui tautan.
    - Kata sandi palsu dikirim saat membuka, sehingga dokumen terproteksi
      langsung gagal alih-alih memunculkan dialog yang menggantung.
    - Aplikasi hanya ditutup bila tidak ada dokumen lain yang terbuka,
      agar jendela Office milik pengguna tidak ikut tertutup.
#>
param(
    [Parameter(Mandatory = $true)][ValidateSet('word', 'excel', 'powerpoint')][string]$Kind,
    [Parameter(Mandatory = $true)][string]$Source,
    [Parameter(Mandatory = $true)][string]$Target
)

$ErrorActionPreference = 'Stop'
$app = $null
$document = $null
$exitCode = 0
$dummyPassword = 'konvertin-no-password'
$missing = [Reflection.Missing]::Value

try {
    switch ($Kind) {
        'word' {
            $app = New-Object -ComObject Word.Application
            $app.Visible = $false
            $app.DisplayAlerts = 0
            $app.AutomationSecurity = 3
            # Open(FileName, ConfirmConversions, ReadOnly, AddToRecentFiles, PasswordDocument)
            $document = $app.Documents.Open($Source, $false, $true, $false, $dummyPassword)
            # 17 = wdExportFormatPDF
            $document.ExportAsFixedFormat($Target, 17)
        }
        'excel' {
            $app = New-Object -ComObject Excel.Application
            $app.Visible = $false
            $app.DisplayAlerts = $false
            $app.AskToUpdateLinks = $false
            $app.AutomationSecurity = 3
            # Open(Filename, UpdateLinks, ReadOnly, Format, Password)
            $document = $app.Workbooks.Open($Source, 0, $true, $missing, $dummyPassword)
            # 0 = xlTypePDF
            $document.ExportAsFixedFormat(0, $Target)
        }
        'powerpoint' {
            $app = New-Object -ComObject PowerPoint.Application
            $app.AutomationSecurity = 3
            $app.DisplayAlerts = 1
            # Open(FileName, ReadOnly = msoTrue, Untitled = msoFalse, WithWindow = msoFalse)
            $document = $app.Presentations.Open($Source, -1, 0, 0)
            # 32 = ppSaveAsPDF
            $document.SaveAs($Target, 32)
        }
    }
}
catch {
    $message = $_.Exception.Message
    if ($message -match 'password|sandi') {
        $exitCode = 4
    }
    else {
        $exitCode = 2
    }
    # Hanya jenis galat; tidak memuat nama atau isi berkas.
    [Console]::Error.WriteLine($_.Exception.GetType().Name)
}
finally {
    if ($null -ne $document) {
        try {
            switch ($Kind) {
                'word' { $document.Close(0) }
                'excel' { $document.Close($false) }
                'powerpoint' { $document.Close() }
            }
        }
        catch { }
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($document)
    }

    if ($null -ne $app) {
        try {
            $open = switch ($Kind) {
                'word' { $app.Documents.Count }
                'excel' { $app.Workbooks.Count }
                'powerpoint' { $app.Presentations.Count }
            }
            if ($open -eq 0) {
                $app.Quit()
            }
        }
        catch { }
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($app)
    }

    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}

if ($exitCode -eq 0 -and -not (Test-Path -LiteralPath $Target)) {
    $exitCode = 2
}

exit $exitCode
