<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DbService;
use App\Services\StateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionException;
use ReflectionMethod;
use Throwable;

class ApiController extends Controller
{
    /** Tables restored by POST /restore, in parent-first insertion order. */
    private const RESTORE_TABLES = [
        'accounts',
        'games',
        'draws',
        'customers',
        'draw_periods',
        'tickets',
        'ticket_lines',
        'customer_plays',
        'cash_ledger',
        'balance_movements',
        'cash_sessions',
        'app_config',
        'audit_logs',
    ];

    private const REQUIRED_RESTORE_TABLES = ['app_config', 'tickets', 'cash_ledger'];

    public function __construct(
        private readonly DbService $dbService,
        private readonly StateService $stateService,
    ) {
    }

    /**
     * Generic RPC entry point. The Flutter client sends:
     * { "scope": "db" | "state", "method": "...", "args": { ... } }
     */
    public function call(Request $request): JsonResponse
    {
        try {
            $this->dbService->bootstrap();

            $scope = $request->input('scope');
            $method = $request->input('method');
            $args = $request->input('args', []);

            if (! is_string($method) || $method === '') {
                throw new \InvalidArgumentException('Missing method');
            }
            if (! is_array($args)) {
                throw new \InvalidArgumentException('args must be an object');
            }
            if ($scope !== 'db' && $scope !== 'state') {
                throw new \InvalidArgumentException('scope must be "db" or "state"');
            }

            $service = $scope === 'db' ? $this->dbService : $this->stateService;

            if (! method_exists($service, $method) || ! is_callable([$service, $method])) {
                throw new \RuntimeException("Unknown method: {$scope}.{$method}");
            }

            if ($scope === 'state') {
                $this->stateService->hydrate($args);
            }

            $result = $service->{$method}(...$this->callArguments($service, $method, $args));

            if ($scope === 'state') {
                $snapshot = $this->stateService->snapshot();
                if (is_array($result)) {
                    $result['state'] = $snapshot;
                } else {
                    $result = ['value' => $result, 'state' => $snapshot];
                }
            }

            return response()->json(['ok' => true, 'result' => $result]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Full database restore. Mirrors the Flutter BackupService.executeRestore()
     * POST to {baseUrl}/restore. The payload is { "backup": { tableName: [rows] } }.
     */
    public function restore(Request $request): JsonResponse
    {
        try {
            $backup = $request->input('backup');
            if ($backup === null && is_array($request->input('args'))) {
                $backup = $request->input('args.backup');
            }
            if (! is_array($backup)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Missing backup payload: expected a map of table names to row arrays',
                ], 400);
            }

            foreach (self::REQUIRED_RESTORE_TABLES as $table) {
                if (! array_key_exists($table, $backup) || ! is_array($backup[$table])) {
                    return response()->json([
                        'ok' => false,
                        'error' => "Invalid backup payload: missing required table '{$table}'",
                    ], 400);
                }
            }

            DB::transaction(function () use ($backup): void {
                // Disable FK checks so rows can be reloaded in any order.
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                try {
                    foreach (array_reverse(self::RESTORE_TABLES) as $table) {
                        DB::table($table)->delete();
                    }

                    foreach (self::RESTORE_TABLES as $table) {
                        $rows = $backup[$table] ?? [];
                        if (! is_array($rows) || $rows === []) {
                            continue;
                        }

                        $columns = array_flip(Schema::getColumnListing($table));
                        foreach (array_chunk($rows, 500) as $chunk) {
                            $clean = [];
                            foreach ($chunk as $row) {
                                if (! is_array($row)) {
                                    continue;
                                }
                                $clean[] = array_intersect_key($row, $columns);
                            }
                            if ($clean !== []) {
                                DB::table($table)->insert($clean);
                            }
                        }
                    }
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });

            return response()->json(['ok' => true, 'result' => ['restored' => true]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Map incoming args onto the declared parameters of the target method so
     * extra state keys (cart, user, ...) never trigger "unknown parameter".
     */
    private function callArguments(object $service, string $method, array $args): array
    {
        try {
            $reflection = new ReflectionMethod($service, $method);
        } catch (ReflectionException $e) {
            throw new \RuntimeException("Unknown method: {$method}");
        }

        $callArgs = [];
        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $args)) {
                $callArgs[$name] = $args[$name];
            } elseif (! $parameter->isOptional()) {
                throw new \InvalidArgumentException("Missing required argument: {$name}");
            }
        }

        return $callArgs;
    }
}
