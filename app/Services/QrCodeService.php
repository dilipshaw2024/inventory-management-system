<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class QrCodeService
{
    public function pngDataUri(string $value): ?string
    {
        $process = new Process([config('erp.qr_code_binary', 'qrencode'), '-o', '-', '-t', 'png', '-s', '5', '-m', '2', $value]);
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful() || $process->getOutput() === '') return null;
        return 'data:image/png;base64,'.base64_encode($process->getOutput());
    }
}
