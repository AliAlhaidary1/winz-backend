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

            // Resolve the caller from the server-side session. The client-sent
            // `user` envelope is no longer trusted for identity or role.
            $isLogin = $scope === 'state' && $method === 'login';
            $sessionToken = (string) $request->header('X-Session-Token', '');
            $user = $sessionToken !== ''
                ? $this->dbService->userForSession($sessionToken)
                : null;

            if (! $isLogin) {
                if ($user === null) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'SESSION_REQUIRED',
                    ], 401);
                }
                $args['user'] = $user;
                if (! $this->isAllowed($user, $scope, $method, $args)) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'FORBIDDEN',
                    ], 403);
                }
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

            if ($isLogin && ($result['value'] ?? false) === true) {
                $newToken = bin2hex(random_bytes(32));
                $account = $result['state']['user'] ?? null;
                if (is_array($account) && isset($account['id'])) {
                    $this->dbService->createSession($newToken, (string) $account['id']);
                    $result['token'] = $newToken;
                }
            }
            if ($scope === 'state' && $method === 'logout' && $sessionToken !== '') {
                $this->dbService->deleteSession($sessionToken);
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
            $sessionToken = (string) $request->header('X-Session-Token', '');
            $user = $sessionToken !== ''
                ? $this->dbService->userForSession($sessionToken)
                : null;
            if ($user === null) {
                return response()->json(['ok' => false, 'error' => 'SESSION_REQUIRED'], 401);
            }
            if ((self::ROLE_LEVEL[$user['role'] ?? 'cashier'] ?? 1) < 3) {
                return response()->json(['ok' => false, 'error' => 'FORBIDDEN'], 403);
            }

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
                        // ConvertEmptyStringsToNull rewrites "" to null even
                        // inside the nested backup payload. Restore "" for
                        // NOT NULL columns so rows such as an empty
                        // app_config.ticket_footer can be inserted again.
                        $notNull = [];
                        foreach (DB::select(
                            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
                            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? "
                            . "AND IS_NULLABLE = 'NO'",
                            [$table],
                        ) as $column) {
                            $notNull[$column->COLUMN_NAME] = true;
                        }
                        foreach (array_chunk($rows, 500) as $chunk) {
                            $clean = [];
                            foreach ($chunk as $row) {
                                if (! is_array($row)) {
                                    continue;
                                }
                                $cleanRow = array_intersect_key($row, $columns);
                                foreach ($notNull as $columnName => $_) {
                                    if (array_key_exists($columnName, $cleanRow)
                                        && $cleanRow[$columnName] === null) {
                                        $cleanRow[$columnName] = '';
                                    }
                                }
                                $clean[] = $cleanRow;
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

    private const ROLE_LEVEL = [
        'cashier' => 1,
        'supervisor' => 2,
        'manager' => 3,
        'owner' => 4,
    ];

    /** Minimum role level required for each operation (default: 1 = cashier). */
    private function requiredLevel(string $scope, string $method): int
    {
        $state = [
            'processPeriodResult' => 3,
            'processDrawResult' => 3,
            'payWinningTicket' => 2,
            'reversePayout' => 2,
            'cancelWholeTicket' => 2,
            'cancelTicketLine' => 2,
            'openCashSession' => 2,
            'closeCashSession' => 2,
            'loadOpeningCash' => 2,
            'computeCashBalance' => 2,
            'computeGeneralBalance' => 2,
            'computeCashBalanceGlobal' => 2,
            'computeGeneralBalanceGlobal' => 2,
            'addCustomer' => 2,
            'updateCustomer' => 2,
            'deleteCustomer' => 2,
            'saveCartAsCustomerPlays' => 2,
            'playCustomerUsual' => 2,
        ];
        $db = [
            'upsertConfig' => 3,
            'setClientCommissionRate' => 3,
            'insertAccount' => 3,
            'updateAccountCommission' => 3,
            'deactivateAccount' => 3,
            'insertPeriod' => 3,
            'updatePeriodStatus' => 3,
            'updatePeriodTimes' => 3,
            'publishPeriodResult' => 3,
            'publishPeriodResult3' => 3,
            'publishPeriodResult4' => 3,
            'clearPeriodResult3' => 3,
            'clearPeriodResult4' => 3,
            'resetPeriodTickets' => 3,
            'resetDrawTickets' => 3,
            'updateDrawResult' => 3,
            'insertAuditLog' => 2,
            'insertCustomer' => 2,
            'updateCustomer' => 2,
            'deleteCustomer' => 2,
            'insertCashLedger' => 2,
            'insertBalanceMovement' => 2,
            'insertCashSession' => 2,
            'closeCashSession' => 2,
            'insertPayoutLedger' => 2,
            'releasePayoutLockForTicket' => 2,
            'voidTicketLine' => 2,
            'recalcTicketTotals' => 2,
            'reassignCustomerTickets' => 2,
        ];

        return $scope === 'state'
            ? ($state[$method] ?? 1)
            : ($db[$method] ?? 1);
    }

    private function isAllowed(array $user, string $scope, string $method, array $args): bool
    {
        $role = (string) ($user['role'] ?? 'cashier');
        $level = self::ROLE_LEVEL[$role] ?? 1;
        if ($level < $this->requiredLevel($scope, $method)) {
            return false;
        }

        // Nobody may deactivate themselves; only owner may deactivate another owner.
        if ($scope === 'db' && $method === 'deactivateAccount') {
            $targetId = (string) ($args['id'] ?? '');
            if ($targetId === (string) ($user['id'] ?? '')) {
                return false;
            }
            $target = $this->dbService->getAccountById($targetId);
            if ($target !== null
                && ($target['role'] ?? '') === 'owner'
                && $role !== 'owner') {
                return false;
            }
        }

        return true;
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
                $value = $args[$name];
                // Laravel's global ConvertEmptyStringsToNull middleware turns a
                // JSON "" into null before this controller runs. Restore the
                // empty string for non-nullable string parameters so legitimate
                // empty config values (e.g. ticket_footer) can be saved, while
                // nullable parameters keep their explicit null.
                $type = $parameter->getType();
                if ($value === null
                    && $type instanceof \ReflectionNamedType
                    && $type->getName() === 'string'
                    && ! $type->allowsNull()) {
                    $value = '';
                }
                $callArgs[$name] = $value;
            } elseif (! $parameter->isOptional()) {
                throw new \InvalidArgumentException("Missing required argument: {$name}");
            }
        }

        return $callArgs;
    }
}
