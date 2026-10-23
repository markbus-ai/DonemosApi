<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * only working session. A whole batch shares one browser cold start; the
 * bridge prints "RESULTADOS {id: null|error}".
 */
class WhatsPlaySender implements WhatsappSender
{
    public function sendLote(array $mensajes): array
    {
        if ($mensajes === []) {
            return [];
        }

        $input = tempnam(sys_get_temp_dir(), 'whatsplay_');
        File::put($input, json_encode(array_map(
            fn ($id, $m) => ['id' => $id, 'to' => $m['numero'], 'message' => $m['mensaje']],
            array_keys($mensajes),
            $mensajes,
        )));

        // Start-up plus a few seconds per message; the bridge stops itself
        // slightly earlier so it can still report per-message results.
        $timeout = config('whatsapp.whatsplay.timeout') + 15 * count($mensajes);

        try {
            $result = Process::path(config('whatsapp.whatsplay.dir'))
                ->timeout($timeout + 30)
                ->run(self::command('lote', ['--input', $input, '--timeout', (string) $timeout]));
        } finally {
            File::delete($input);
        }

        $resultados = preg_match('/^RESULTADOS (.+)$/m', $result->output(), $m) ? json_decode($m[1], true) : null;
        $fallo = 'WhatsPlay no informó el resultado: '.mb_substr(trim($result->errorOutput() ?: $result->output()), -300);

        $salida = [];
        foreach (array_keys($mensajes) as $id) {
            $salida[$id] = is_array($resultados) && array_key_exists((string) $id, $resultados)
                ? $resultados[(string) $id]
                : $fallo;
        }

        return $salida;
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
