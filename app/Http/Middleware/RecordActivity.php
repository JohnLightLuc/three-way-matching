<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Journalise dans activity_logs toute requête API MUTANTE (POST/PUT/PATCH/DELETE)
 * ainsi que les tentatives de login (succès et échec). Les GET ne sont pas tracés.
 *
 * Middleware terminable : on écrit APRÈS la réponse pour capturer le status_code
 * et l'utilisateur résolu. Toute erreur d'écriture est avalée — le journal ne doit
 * jamais casser une réponse.
 */
class RecordActivity
{
    /** Clés du corps de requête à ne jamais faire transiter dans le digest. */
    private const REDACTED = ['password', 'password_confirmation', 'current_password', 'token'];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldRecord($request)) {
            return;
        }

        try {
            [$targetType, $targetId] = $this->resolveTarget($request);

            ActivityLog::create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'method' => $request->getMethod(),
                'route' => $request->route()?->getName() ?: $request->path(),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'ip' => $request->ip(),
                'payload_digest' => $this->payloadDigest($request),
                'status_code' => $response->getStatusCode(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('RecordActivity: échec journalisation', ['exception' => $e]);
        }
    }

    private function shouldRecord(Request $request): bool
    {
        return in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** @return array{0: ?string, 1: ?int} */
    private function resolveTarget(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return [class_basename($parameter), (int) $parameter->getKey()];
            }
        }

        return [null, null];
    }

    private function payloadDigest(Request $request): ?string
    {
        $data = $request->except(self::REDACTED);

        if ($data === []) {
            return null;
        }

        ksort($data);

        return hash('sha256', (string) json_encode($data));
    }
}
