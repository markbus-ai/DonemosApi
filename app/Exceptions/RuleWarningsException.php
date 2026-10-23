<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A booking or donation stopped by rule warnings (overridable with `forzar`)
 * or by a hard booking conflict. Renders the 409 envelope the clients parse:
 * turnos answer {warning, code, message, warnings} with the first warning as
 * headline; donaciones answer {warnings}.
 */
final class RuleWarningsException extends RuntimeException
{
    /**
     * @param  array<int, array{code: string, message: string}>  $warnings
     */
    private function __construct(public readonly array $warnings, private readonly bool $headline)
    {
        parent::__construct($warnings[0]['message'] ?? 'Conflicto de reglas.');
    }

    /** @param  array<int, array{code: string, message: string}>  $warnings */
    public static function turno(array $warnings): self
    {
        return new self($warnings, true);
    }

    /** @param  array<int, array{code: string, message: string}>  $warnings */
    public static function donacion(array $warnings): self
    {
        return new self($warnings, false);
    }

    /** Single warning that is not overridable (taken slot, capacity). */
    public static function turnoBloqueado(string $code, string $message): self
    {
        return self::turno([['code' => $code, 'message' => $message]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        if (! $this->headline) {
            return ['warnings' => $this->warnings];
        }

        return [
            'warning' => true,
            'code' => $this->warnings[0]['code'],
            'message' => $this->warnings[0]['message'],
            'warnings' => $this->warnings,
        ];
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload(), 409);
    }
}
