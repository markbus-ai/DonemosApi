<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Process;

/**
 * Sends through WhatsPlay (whatsplay-automation skill) using the bridge in
 * scripts/whatsplay_turnos.py, run with the skill's uv environment and its
 * only working session. The bridge prints "RESULTADO OK" on success.
 */
class WhatsPlaySender implements WhatsappSender
{
    public function send(string $numero, string $mensaje): ?string
    {
        $result = Process::path(config('whatsapp.whatsplay.dir'))
            ->timeout(config('whatsapp.whatsplay.timeout'))
            ->run(self::command('enviar', ['--to', $numero, '--message', $mensaje]));

        if ($result->successful() && str_contains($result->output(), 'RESULTADO OK')) {
            return null;
        }

        $detalle = trim($result->output()) ?: trim($result->errorOutput());

        return 'WhatsPlay no confirmó el envío: '.mb_substr($detalle, -500);
    }

    /**
     * @param  array<int, string>  $args
     * @return array<int, string>
     */
    public static function command(string $modo, array $args = []): array
    {
        $dir = config('whatsapp.whatsplay.dir');

        return [
            'uv', 'run', '--project', $dir, 'python', base_path('scripts/whatsplay_turnos.py'),
            $modo, '--session', $dir.'/whatsapp_session', ...$args,
        ];
    }
}
