<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reminder delivery job.
 *
 * This is a deliberate no-op behind a config gate: no provider is imported and
 * no HTTP call is performed. It exists so scheduling can be observed
 * (Queue::fake) while the real integration stays out of this slice. When
 * `delivery_enabled` is false the job returns immediately.
 */
class EnviarRecordatorioWhatsApp implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $recordatorioId
    ) {}

    public function handle(): void
    {
        if (! config('recordatorio_predonacion.delivery_enabled', false)) {
            return;
        }

        // Intentionally left without a provider integration. Enabling delivery
        // is a separate, reviewed change; this job never sends in this slice.
        Log::info('Recordatorio delivery requested', [
            'recordatorio_id' => $this->recordatorioId,
        ]);
    }
}
