<?php

namespace App\Services;

class SseStreamService
{
    public function setHeaders(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
    }

    public function sendKeepAlive(): void
    {
        echo ": ping\n\n";
        flush();
    }
}
