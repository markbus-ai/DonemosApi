<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Links the hemocentro's WhatsApp from the app. The WhatsPlay bridge runs in
 * the background (scripts/whatsplay_turnos.py vincular) and writes its state
 * and login QR to a private storage directory that only this service reads;
 * WhatsPlay's own public QR server is disabled in the bridge.
 */
class WhatsappVinculacion
{
    private const ACTIVOS = ['iniciando', 'esperando_qr'];

    /**
     * @return array{estado: string, qr: ?string, detalle: ?string, actualizado: ?int}
     */
    public function estado(): array
    {
        $path = $this->dir().'/estado.json';

        if (! File::exists($path)) {
            return ['estado' => 'sin_verificar', 'qr' => null, 'detalle' => null, 'actualizado' => null];
        }

        $estado = json_decode(File::get($path), true) ?: [];
        $estado = [
            'estado' => (string) ($estado['estado'] ?? 'error'),
            'qr' => $estado['qr'] ?? null,
            'detalle' => $estado['detalle'] ?? null,
            'actualizado' => isset($estado['actualizado']) ? (int) $estado['actualizado'] : null,
        ];

        // The bridge died without reporting: never show a stale QR.
        if (in_array($estado['estado'], self::ACTIVOS, true) && ! $this->corriendo()) {
            return ['estado' => 'error', 'qr' => null, 'detalle' => 'El proceso de vinculación se detuvo. Genere un nuevo QR.', 'actualizado' => $estado['actualizado']];
        }

        return $estado;
    }

    public function enCurso(): bool
    {
        return in_array($this->estado()['estado'], self::ACTIVOS, true);
    }

    /**
     * Start the bridge detached from the request and remember its pid.
     *
     * @return array{estado: string, qr: ?string, detalle: ?string, actualizado: ?int}
     */
    public function iniciar(): array
    {
        File::ensureDirectoryExists($this->dir(), 0700);
        File::put($this->dir().'/estado.json', json_encode(['estado' => 'iniciando', 'qr' => null, 'detalle' => null, 'actualizado' => time()]));

        $command = implode(' ', array_map('escapeshellarg', WhatsPlaySender::command('vincular', [
            '--estado-dir', $this->dir(),
            '--timeout', (string) config('whatsapp.vinculacion_timeout'),
        ])));

        $dir = config('whatsapp.whatsplay.dir');

        if (! is_dir($dir)) {
            return $this->fallar("No se encontró WhatsPlay en {$dir}; configure WHATSPLAY_DIR.");
        }

        $result = Process::path($dir)
            ->run(['sh', '-c', 'nohup '.$command.' > '.escapeshellarg($this->dir().'/vincular.log').' 2>&1 & echo $!']);

        if (! $result->successful() || ! ctype_digit(trim($result->output()))) {
            return $this->fallar('No se pudo iniciar la vinculación de WhatsApp.');
        }

        File::put($this->dir().'/pid', trim($result->output()));

        return ['estado' => 'iniciando', 'qr' => null, 'detalle' => null, 'actualizado' => time()];
    }

    /**
     * @return array{estado: string, qr: ?string, detalle: ?string, actualizado: ?int}
     */
    private function fallar(string $detalle): array
    {
        $estado = ['estado' => 'error', 'qr' => null, 'detalle' => $detalle, 'actualizado' => time()];
        File::put($this->dir().'/estado.json', json_encode($estado));

        return $estado;
    }

    private function corriendo(): bool
    {
        $pidFile = $this->dir().'/pid';
        $pid = File::exists($pidFile) ? (int) trim(File::get($pidFile)) : 0;

        return $pid > 0 && (function_exists('posix_kill') ? posix_kill($pid, 0) : File::exists("/proc/{$pid}"));
    }

    private function dir(): string
    {
        return config('whatsapp.estado_dir');
    }
}
