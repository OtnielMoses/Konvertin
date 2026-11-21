<?php

use Illuminate\Support\Facades\Schedule;

// 4.14/REQ-4: pembersihan berkala berkas sementara yang tidak diunduh (TBD-07).
Schedule::command('konvertin:purge')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
