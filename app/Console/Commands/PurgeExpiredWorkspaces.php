<?php

namespace App\Console\Commands;

use App\Conversion\WorkspaceManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('konvertin:purge')]
#[Description('Menghapus berkas sementara yang melewati batas waktu penyimpanan (UC-14 AP-2)')]
class PurgeExpiredWorkspaces extends Command
{
    public function handle(WorkspaceManager $workspaces): int
    {
        ['deleted' => $deleted, 'failed' => $failed] = $workspaces->purgeExpired();

        $this->info("Dihapus: {$deleted}. Gagal (diulang pada siklus berikutnya): {$failed}.");

        return self::SUCCESS;
    }
}
