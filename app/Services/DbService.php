<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Port of Flutter lib/src/data/database.dart (AppDatabase).
 *
 * All SQL uses the same camelCase column names as the Flutter schema. Rows are
 * returned as associative arrays so they are wire-compatible with Dart's
 * Map<String, dynamic>.
 */
class DbService
{
    public const DEFAULT_CLIENT_COMMISSION_RATE = 20.0;

    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }

    /** @param mixed $rows */
    private function arr($rows): array
    {
        $items = is_array($rows) ? $rows : $rows->all();

        return array_map(static fn ($r) => (array) $r, $items);
    }

    private function scalar(string $table, string $column, string $keyColumn, mixed $value): mixed
    {
        return DB::table($table)->where($keyColumn, $value)->value($column);
    }

    /** INSERT OR REPLACE (SQLite) -> REPLACE INTO (MySQL). */
    private function replaceInto(string $table, array $row): void
    {
        $quote = static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`';
        $columns = implode(', ', array_map($quote, array_keys($row)));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        DB::statement("REPLACE INTO `{$table}` ({$columns}) VALUES ({$marks})", array_values($row));
    }

    // ---------------------------------------------------------------- helpers
    public function getAccounts(): array
    {
        return $this->arr(DB::table('accounts')->orderBy('username')->get());
    }

    public function findAccount(string $username, string $password): ?array
    {
        $row = DB::table('accounts')
            ->where('username', $username)
            ->where('password', $password)
            ->first();

        return $row === null ? null : (array) $row;
    }

    public function getAccountById(string $id): ?array
    {
        $row = DB::table('accounts')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function insertAccount(array $a): void
    {
        DB::table('accounts')->insert($a);
    }

    public function createSession(string $token, string $accountId): void
    {
        $now = self::nowMs();
        DB::table('winz_sessions')->insert([
            'token' => $token,
            'account_id' => $accountId,
            'created_at' => $now,
            'expires_at' => $now + 8 * 60 * 60 * 1000,
        ]);
    }

    public function userForSession(string $token): ?array
    {
        $session = DB::table('winz_sessions')->where('token', $token)->first();
        if ($session === null || (int) $session->expires_at < self::nowMs()) {
            return null;
        }
        $row = DB::table('accounts')->where('id', $session->account_id)->first();
        if ($row === null || ($row->status ?? null) !== 'active') {
            return null;
        }

        return (array) $row;
    }

    public function deleteSession(string $token): void
    {
        DB::table('winz_sessions')->where('token', $token)->delete();
    }

    public function updateAccountCommission(string $id, float $commission): void
    {
        DB::table('accounts')->where('id', $id)->update(['commission' => $commission]);
    }

    public function updateAccountCredentials(string $id, string $username, string $password): void
    {
        DB::table('accounts')->where('id', $id)->update([
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function deactivateAccount(string $id): void
    {
        DB::table('accounts')->where('id', $id)->update(['status' => 'inactive']);
    }

    public function getGames(): array
    {
        return $this->arr(DB::table('games')->get());
    }

    public function getDrawsByGame(string $gameId): array
    {
        return $this->arr(DB::table('draws')->where('gameId', $gameId)->orderBy('drawTime')->get());
    }

    public function getAllDraws(): array
    {
        return $this->arr(DB::table('draws')->orderBy('drawTime', 'desc')->get());
    }

    public function getDrawById(string $id): ?array
    {
        $row = DB::table('draws')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function updateDrawResult(string $id, string $winningNumbers): void
    {
        DB::table('draws')->where('id', $id)->update([
            'winningNumbers' => $winningNumbers,
            'status' => 'closed',
        ]);
    }

    // ----------------------------------------------------------------- tickets
    public function insertTicket(array $t): void
    {
        DB::table('tickets')->insert([
            'id' => $t['id'],
            'serialNumber' => $t['serialNumber'] ?? null,
            'gameId' => $t['gameId'],
            'gameName' => $t['gameName'],
            'drawId' => $t['drawId'] ?? null,
            'drawName' => $t['drawName'] ?? null,
            'numbers' => $t['numbers'],
            'betType' => $t['betType'],
            'amount' => $t['amount'],
            'total' => $t['total'],
            'operatorId' => $t['operatorId'],
            'operatorName' => $t['operatorName'],
            'createdAt' => $t['createdAt'],
            'status' => $t['status'],
            'barcode' => $t['barcode'] ?? null,
            'prizeAmount' => $t['prizeAmount'] ?? null,
            'paidAt' => $t['paidAt'] ?? null,
            'paidBy' => $t['paidBy'] ?? null,
            'isQuickPick' => $t['isQuickPick'] ?? 0,
            'customerId' => $t['customerId'] ?? null,
            'customerName' => $t['customerName'] ?? null,
        ]);
    }

    public function insertTicketLine(array $l): void
    {
        $this->replaceInto('ticket_lines', [
            'id' => $l['id'],
            'ticketId' => $l['ticketId'],
            'gameId' => $l['gameId'],
            'gameName' => $l['gameName'],
            'drawId' => $l['drawId'] ?? null,
            'drawName' => $l['drawName'] ?? null,
            'numbers' => $l['numbers'],
            'betType' => $l['betType'],
            'amount' => $l['amount'],
            'isQuickPick' => $l['isQuickPick'] ?? 0,
            'periodId' => $l['periodId'] ?? null,
            'periodStatus' => $l['periodStatus'] ?? null,
            'winningStatus' => $l['winningStatus'] ?? null,
            'prizeAmount' => $l['prizeAmount'] ?? null,
        ]);
    }

    /** Flutter orders by SQLite rowid; MySQL uses the id primary key for a stable order. */
    public function getTicketLines(string $ticketId): array
    {
        return $this->arr(DB::table('ticket_lines')->where('ticketId', $ticketId)->orderBy('id')->get());
    }

    public function getTicketLinesCount(string $ticketId): int
    {
        return (int) DB::table('ticket_lines')->where('ticketId', $ticketId)->count();
    }

    public function getAllTicketLines(): array
    {
        return $this->arr(DB::table('ticket_lines')->orderBy('ticketId')->get());
    }

    public function getTicketLinesByPeriod(string $periodId): array
    {
        return $this->arr(DB::table('ticket_lines')->where('periodId', $periodId)->get());
    }

    public function sumTicketLinesAmountByPeriod(string $periodId): float
    {
        $row = DB::table('ticket_lines')
            ->selectRaw('COALESCE(SUM(`amount`), 0) as s')
            ->where('periodId', $periodId)
            ->first();

        return (float) ($row->s ?? 0);
    }

    public function insertTicketWithLines(array $ticket, array $lines): void
    {
        $this->insertTicket($ticket);
        foreach ($lines as $line) {
            $this->insertTicketLine($line);
        }
    }

    public function getTickets(?string $status = null): array
    {
        $query = DB::table('tickets');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $this->arr($query->orderBy('createdAt', 'desc')->get());
    }

    public function getTicketById(string $id): ?array
    {
        $row = DB::table('tickets')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    /** Deterministic lookup priority, identical to Flutter getTicketByBarcode. */
    public function getTicketByBarcode(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $one = static function (string $column, string $value): ?array {
            $row = DB::table('tickets')
                ->where($column, $value)
                ->orderBy('createdAt', 'desc')
                ->first();

            return $row === null ? null : (array) $row;
        };

        $lookup = static function (string $value) use ($one): ?array {
            return $one('barcode', $value) ?? $one('id', $value) ?? $one('serialNumber', $value);
        };

        $exact = $lookup($barcode);
        if ($exact !== null) {
            return $exact;
        }

        if (preg_match('/^\d+$/', $barcode) === 1) {
            $variants = [
                str_pad($barcode, 6, '0', STR_PAD_LEFT),
                str_pad($barcode, 8, '0', STR_PAD_LEFT),
            ];
            $stripped = ltrim($barcode, '0');
            if ($stripped !== '') {
                $variants[] = $stripped;
            }
            $variants = array_values(array_unique($variants));
            foreach ($variants as $variant) {
                if ($variant === $barcode) {
                    continue;
                }
                $hit = $lookup($variant);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    public function updateTicketStatus(
        string $id,
        string $status,
        ?float $prizeAmount = null,
        ?int $paidAt = null,
        ?string $paidBy = null,
    ): void {
        $data = ['status' => $status];
        // Flutter uses COALESCE(?, column): null means "keep the existing value".
        if ($prizeAmount !== null) {
            $data['prizeAmount'] = $prizeAmount;
        }
        if ($paidAt !== null) {
            $data['paidAt'] = $paidAt;
        }
        if ($paidBy !== null) {
            $data['paidBy'] = $paidBy;
        }
        DB::table('tickets')->where('id', $id)->update($data);
    }

    /** Atomically reserve the single payout allowed for a ticket. */
    public function insertPayoutLedger(
        string $ticketId,
        float $amount,
        string $operatorId,
        int $createdAt,
    ): bool {
        try {
            return DB::table('cash_ledger')->insertOrIgnore([
                'id' => 'payout_' . $ticketId,
                'type' => 'payout',
                'amount' => $amount,
                'direction' => 'out',
                'description' => 'Prize payout for ' . $ticketId,
                'operatorId' => $operatorId,
                'createdAt' => $createdAt,
                'ticketId' => $ticketId,
            ]) > 0;
        } catch (QueryException $e) {
            return false;
        }
    }

    public function getPayoutAmountForTicket(string $ticketId): float
    {
        $value = $this->scalar('cash_ledger', 'amount', 'id', 'payout_' . $ticketId);

        return $value === null ? 0.0 : (float) $value;
    }

    /** Free the payout lock while keeping the original ledger row as history. */
    public function releasePayoutLockForTicket(string $ticketId, int $at): void
    {
        DB::table('cash_ledger')
            ->where('id', 'payout_' . $ticketId)
            ->update(['id' => 'payout_' . $ticketId . '_reversed_' . $at]);
    }

    public function getTicketLineById(string $id): ?array
    {
        $row = DB::table('ticket_lines')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function voidTicketLine(string $lineId): void
    {
        DB::table('ticket_lines')->where('id', $lineId)->update([
            'periodStatus' => 'cancelled',
            'winningStatus' => 'void',
            'prizeAmount' => 0,
        ]);
    }

    public function recalcTicketTotals(string $ticketId): void
    {
        $row = DB::table('ticket_lines')
            ->selectRaw('COALESCE(SUM(`amount`), 0) as s, COUNT(*) as c')
            ->where('ticketId', $ticketId)
            ->where('periodStatus', '!=', 'cancelled')
            ->first();
        $sum = (float) ($row->s ?? 0);
        $count = (int) ($row->c ?? 0);

        if ($count === 0) {
            DB::table('tickets')->where('id', $ticketId)->update([
                'total' => 0,
                'amount' => 0,
                'status' => 'cancelled',
            ]);

            return;
        }

        $lines = $this->arr(DB::table('ticket_lines')
            ->select(['numbers', 'betType'])
            ->where('ticketId', $ticketId)
            ->where('periodStatus', '!=', 'cancelled')
            ->orderBy('id')
            ->get());

        if (count($lines) === 1) {
            $headerNums = (string) $lines[0]['numbers'];
            $headerBet = (string) $lines[0]['betType'];
        } else {
            $headerNums = implode(';', array_map(static fn ($r) => (string) $r['numbers'], $lines));
            $headerBet = 'Multi';
        }

        DB::table('tickets')->where('id', $ticketId)->update([
            'total' => $sum,
            'amount' => $sum,
            'numbers' => $headerNums,
            'betType' => $headerBet,
        ]);
        DB::table('tickets')
            ->where('id', $ticketId)
            ->where('status', 'cancelled')
            ->update(['status' => 'active']);
    }

    // -------------------------------------------------------------- ledgers
    public function insertCashLedger(array $e): void
    {
        DB::table('cash_ledger')->insert([
            'id' => $e['id'],
            'type' => $e['type'],
            'amount' => $e['amount'],
            'direction' => $e['direction'],
            'description' => self::fitDescription($e['description'] ?? null),
            'operatorId' => $e['operatorId'],
            'createdAt' => $e['createdAt'],
            'ticketId' => $e['ticketId'] ?? null,
        ]);
    }

    /** MySQL ledger descriptions are VARCHAR(255); SQLite used TEXT. */
    private static function fitDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return mb_substr((string) $description, 0, 255);
    }

    public function insertBalanceMovement(array $e): void
    {
        DB::table('balance_movements')->insert([
            'id' => $e['id'],
            'type' => $e['type'],
            'amount' => $e['amount'],
            'direction' => $e['direction'],
            'description' => self::fitDescription($e['description'] ?? null),
            'operatorId' => $e['operatorId'],
            'createdAt' => $e['createdAt'],
            'ticketId' => $e['ticketId'] ?? null,
        ]);
    }

    public function getCashLedger(): array
    {
        return $this->arr(DB::table('cash_ledger')->orderBy('createdAt', 'desc')->get());
    }

    public function getBalanceMovements(): array
    {
        return $this->arr(DB::table('balance_movements')->orderBy('createdAt', 'desc')->get());
    }

    public function getOpenCashSession(string $operatorId): ?array
    {
        $row = DB::table('cash_sessions')
            ->where('operatorId', $operatorId)
            ->where('status', 'open')
            ->first();

        return $row === null ? null : (array) $row;
    }

    public function insertCashSession(array $s): void
    {
        DB::table('cash_sessions')->insert([
            'id' => $s['id'],
            'operatorId' => $s['operatorId'],
            'openingAmount' => $s['openingAmount'],
            'closingAmount' => $s['closingAmount'] ?? null,
            'openedAt' => $s['openedAt'],
            'closedAt' => $s['closedAt'] ?? null,
            'status' => $s['status'],
        ]);
    }

    public function closeCashSession(string $id, float $closingAmount, int $closedAt): void
    {
        DB::table('cash_sessions')->where('id', $id)->update([
            'closingAmount' => $closingAmount,
            'closedAt' => $closedAt,
            'status' => 'closed',
        ]);
    }

    public function getCashSessions(): array
    {
        return $this->arr(DB::table('cash_sessions')->orderBy('openedAt', 'desc')->get());
    }

    // --------------------------------------------------------------- config
    public function getAppConfig(): array
    {
        return $this->arr(DB::table('app_config')->get());
    }

    public function upsertConfig(string $key, string $value, string $scope): void
    {
        DB::table('app_config')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'scope' => $scope, 'updatedAt' => self::nowMs()],
        );
    }

    public function getClientCommissionRate(): float
    {
        try {
            $value = $this->scalar('app_config', 'value', 'key', 'client_commission_rate');
            if ($value === null) {
                return self::DEFAULT_CLIENT_COMMISSION_RATE;
            }
            $v = (float) $value;
            if (! is_numeric($value) || $v < 0) {
                return self::DEFAULT_CLIENT_COMMISSION_RATE;
            }

            return $v;
        } catch (QueryException $e) {
            return self::DEFAULT_CLIENT_COMMISSION_RATE;
        }
    }

    public function setClientCommissionRate(float $rate): void
    {
        $v = $rate < 0 ? 0.0 : $rate;
        $text = $v == round($v) ? sprintf('%.0f', $v) : (string) $v;
        $this->upsertConfig('client_commission_rate', $text, 'local');
    }

    public function nextTicketId(): string
    {
        return DB::transaction(function (): string {
            $row = DB::table('app_config')->where('key', 'ticket_seq')->lockForUpdate()->first();
            if ($row !== null) {
                $seq = (int) $row->value;
            } else {
                $max = DB::table('tickets')
                    ->selectRaw('MAX(CAST(`id` AS UNSIGNED)) as m')
                    ->whereRaw('`id` REGEXP \'^[0-9]\'')
                    ->first();
                $seq = ($max && $max->m !== null) ? (int) $max->m : 0;
            }
            $seq++;
            DB::table('app_config')->updateOrInsert(
                ['key' => 'ticket_seq'],
                ['value' => (string) $seq, 'scope' => 'local', 'updatedAt' => self::nowMs()],
            );

            return str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        });
    }

    public function nextSerialNumber(): string
    {
        return DB::transaction(function (): string {
            $row = DB::table('app_config')->where('key', 'serial_seq')->lockForUpdate()->first();
            $seq = $row !== null ? (int) $row->value : 0;
            $seq++;
            DB::table('app_config')->updateOrInsert(
                ['key' => 'serial_seq'],
                ['value' => (string) $seq, 'scope' => 'local', 'updatedAt' => self::nowMs()],
            );

            return str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
        });
    }

    public function sumCash(string $direction, ?string $type = null, ?string $operatorId = null): float
    {
        $query = DB::table('cash_ledger')->selectRaw('COALESCE(SUM(`amount`), 0) as s')->where('direction', $direction);
        if ($type !== null) {
            $query->where('type', $type);
        }
        if ($operatorId !== null) {
            $query->where('operatorId', $operatorId);
        }
        $row = $query->first();

        return (float) ($row->s ?? 0);
    }

    public function sumCashSince(string $direction, int $since, ?string $operatorId = null): float
    {
        $query = DB::table('cash_ledger')
            ->selectRaw('COALESCE(SUM(`amount`), 0) as s')
            ->where('direction', $direction)
            ->where('createdAt', '>', $since);
        if ($operatorId !== null) {
            $query->where('operatorId', $operatorId);
        }
        $row = $query->first();

        return (float) ($row->s ?? 0);
    }

    public function getLastClosedCashSession(string $operatorId): ?array
    {
        $row = DB::table('cash_sessions')
            ->where('operatorId', $operatorId)
            ->where('status', 'closed')
            ->orderBy('closedAt', 'desc')
            ->first();

        return $row === null ? null : (array) $row;
    }

    public function getCashLedgerByTicket(string $ticketId): array
    {
        return $this->arr(DB::table('cash_ledger')->where('ticketId', $ticketId)->orderBy('createdAt')->get());
    }

    public function resetDrawTickets(string $drawId): void
    {
        DB::table('tickets')
            ->where('drawId', $drawId)
            ->whereIn('status', ['winning', 'losing'])
            ->update(['status' => 'active', 'prizeAmount' => null]);
    }

    public function getCashLedgerByOperator(string $operatorId): array
    {
        return $this->arr(DB::table('cash_ledger')->where('operatorId', $operatorId)->orderBy('createdAt', 'desc')->get());
    }

    public function getBalanceMovementsByOperator(string $operatorId): array
    {
        return $this->arr(DB::table('balance_movements')->where('operatorId', $operatorId)->orderBy('createdAt', 'desc')->get());
    }

    // ---------------------------------------------------------- draw periods
    public function insertPeriod(array $p): void
    {
        // Flutter uses INSERT OR REPLACE with exactly these 12 columns.
        $this->replaceInto('draw_periods', [
            'id' => $p['id'],
            'drawType' => $p['drawType'],
            'drawDate' => $p['drawDate'],
            'salesOpenTime' => $p['salesOpenTime'],
            'salesCloseTime' => $p['salesCloseTime'],
            'drawTime' => $p['drawTime'],
            'status' => $p['status'],
            'result' => $p['result'] ?? null,
            'resultPublishedAt' => $p['resultPublishedAt'] ?? null,
            'publishedBy' => $p['publishedBy'] ?? null,
            'resultSource' => $p['resultSource'] ?? null,
            'timezone' => $p['timezone'] ?? null,
        ]);
    }

    public function getPeriodById(string $id): ?array
    {
        $row = DB::table('draw_periods')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function getAllPeriods(): array
    {
        return $this->arr(DB::table('draw_periods')->orderBy('drawTime', 'desc')->get());
    }

    public function getPeriodsByDate(string $date): array
    {
        return $this->arr(DB::table('draw_periods')->where('drawDate', $date)->orderBy('drawType')->get());
    }

    public function getOpenPeriods(): array
    {
        return $this->arr(DB::table('draw_periods')->where('status', 'OPEN')->orderBy('drawTime')->get());
    }

    public function updatePeriodStatus(string $id, string $status): void
    {
        DB::table('draw_periods')->where('id', $id)->update(['status' => $status]);
    }

    public function updatePeriodTimes(string $id, int $salesOpen, int $salesClose, int $drawTime): void
    {
        DB::table('draw_periods')->where('id', $id)->update([
            'salesOpenTime' => $salesOpen,
            'salesCloseTime' => $salesClose,
            'drawTime' => $drawTime,
        ]);
    }

    public function publishPeriodResult(
        string $id,
        string $result,
        int $publishedAt,
        string $publishedBy,
        string $source,
    ): void {
        DB::table('draw_periods')->where('id', $id)->update([
            'result' => $result,
            'resultPublishedAt' => $publishedAt,
            'publishedBy' => $publishedBy,
            'resultSource' => $source,
            'status' => 'RESULT_PUBLISHED',
        ]);
    }

    public function publishPeriodResult3(
        string $id,
        string $result,
        int $publishedAt,
        string $publishedBy,
        string $source,
    ): void {
        $period = $this->getPeriodById($id);
        $data = [
            'result3' => $result,
            'result3PublishedAt' => $publishedAt,
            'result3PublishedBy' => $publishedBy,
            'result3Source' => $source,
            'status' => 'RESULT_PUBLISHED',
        ];
        if ($period === null || ($period['result'] ?? null) === null || $period['result'] === '') {
            $data['result'] = $result;
        }
        DB::table('draw_periods')->where('id', $id)->update($data);
    }

    public function publishPeriodResult4(
        string $id,
        string $result,
        int $publishedAt,
        string $publishedBy,
        string $source,
    ): void {
        $period = $this->getPeriodById($id);
        $data = [
            'result4' => $result,
            'result4PublishedAt' => $publishedAt,
            'result4PublishedBy' => $publishedBy,
            'result4Source' => $source,
            'status' => 'RESULT_PUBLISHED',
        ];
        if ($period === null || ($period['result'] ?? null) === null || $period['result'] === '') {
            $data['result'] = $result;
        }
        DB::table('draw_periods')->where('id', $id)->update($data);
    }

    public function clearPeriodResult3(string $id): void
    {
        DB::table('draw_periods')->where('id', $id)->update([
            'result3' => null,
            'result3PublishedAt' => null,
            'result3PublishedBy' => null,
            'result3Source' => null,
        ]);
    }

    public function clearPeriodResult4(string $id): void
    {
        DB::table('draw_periods')->where('id', $id)->update([
            'result4' => null,
            'result4PublishedAt' => null,
            'result4PublishedBy' => null,
            'result4Source' => null,
        ]);
    }

    public function resetPeriodTickets(string $periodId): void
    {
        $ticketIds = DB::table('ticket_lines')->where('periodId', $periodId)->distinct()->pluck('ticketId');
        foreach ($ticketIds as $ticketId) {
            DB::table('tickets')
                ->where('id', $ticketId)
                ->whereIn('status', ['winning', 'losing'])
                ->update(['status' => 'active', 'prizeAmount' => null]);
        }

        // Voided/cancelled lines must keep their marker.
        DB::table('ticket_lines')
            ->where('periodId', $periodId)
            ->where('periodStatus', '!=', 'cancelled')
            ->where(static function ($q): void {
                $q->whereNull('winningStatus')->orWhere('winningStatus', '!=', 'void');
            })
            ->update(['winningStatus' => null, 'prizeAmount' => null]);
    }

    // ------------------------------------------------------------ audit logs
    public function insertAuditLog(array $a): void
    {
        $this->replaceInto('audit_logs', [
            'id' => $a['id'],
            'user' => $a['user'],
            'action' => $a['action'],
            'periodId' => $a['periodId'] ?? null,
            'oldValue' => $a['oldValue'] ?? null,
            'newValue' => $a['newValue'] ?? null,
            'timestamp' => $a['timestamp'],
            'reason' => $a['reason'] ?? null,
        ]);
    }

    public function getAuditLogs(?string $periodId = null): array
    {
        $query = DB::table('audit_logs');
        if ($periodId !== null) {
            $query->where('periodId', $periodId);
        }

        return $this->arr($query->orderBy('timestamp', 'desc')->get());
    }

    // ------------------------------------------------------------- customers
    public function getCustomers(): array
    {
        return $this->arr(DB::table('customers')
            ->orderByDesc('isDefault')
            ->orderByRaw('LOWER(`name`) ASC')
            ->get());
    }

    public function getCustomerById(string $id): ?array
    {
        $row = DB::table('customers')->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function insertCustomer(array $c): void
    {
        DB::table('customers')->insert([
            'id' => $c['id'],
            'name' => $c['name'],
            'phone' => $c['phone'] ?? '',
            'notes' => $c['notes'] ?? '',
            'isDefault' => $c['isDefault'] ?? 0,
            'createdAt' => $c['createdAt'],
        ]);
    }

    public function updateCustomer(string $id, string $name, string $phone, string $notes): void
    {
        DB::table('customers')
            ->where('id', $id)
            ->where('isDefault', 0)
            ->update(['name' => $name, 'phone' => $phone, 'notes' => $notes]);
    }

    public function deleteCustomer(string $id): void
    {
        DB::table('customer_plays')->where('customerId', $id)->delete();
        DB::table('customers')->where('id', $id)->where('isDefault', 0)->delete();
    }

    public function getCustomerPlays(string $customerId): array
    {
        return $this->arr(DB::table('customer_plays')->where('customerId', $customerId)->orderBy('createdAt')->get());
    }

    public function insertCustomerPlay(array $play): void
    {
        DB::table('customer_plays')->insert([
            'id' => $play['id'],
            'customerId' => $play['customerId'],
            'gameId' => $play['gameId'],
            'gameName' => $play['gameName'],
            'numbers' => $play['numbers'],
            'betType' => $play['betType'],
            'amount' => $play['amount'],
            'drawType' => $play['drawType'] ?? null,
            'isQuickPick' => $play['isQuickPick'] ?? 0,
            'createdAt' => $play['createdAt'],
        ]);
    }

    public function deleteCustomerPlay(string $id): void
    {
        DB::table('customer_plays')->where('id', $id)->delete();
    }

    public function reassignCustomerTickets(string $fromId, string $toId, string $toName): void
    {
        DB::table('tickets')
            ->where('customerId', $fromId)
            ->update(['customerId' => $toId, 'customerName' => $toName]);
    }

    // ------------------------------------------------- period seeding/status
    public function seedDrawPeriods(): void
    {
        $this->doSeedDrawPeriods();
    }

    public function refreshPeriodStatuses(): void
    {
        try {
            $nowMs = self::nowMs();
            foreach ($this->getAllPeriods() as $p) {
                $status = (string) ($p['status'] ?? '');
                if (in_array($status, ['CANCELLED', 'POSTPONED', 'SUSPENDED'], true)) {
                    continue;
                }

                $hasResult = false;
                foreach (['result', 'result3', 'result4'] as $key) {
                    if (trim((string) ($p[$key] ?? '')) !== '') {
                        $hasResult = true;
                    }
                }

                if ($hasResult) {
                    if (in_array($status, ['RESULT_PENDING', 'SALES_CLOSED', 'OPEN', 'SCHEDULED', 'DRAWING'], true)) {
                        DB::table('draw_periods')->where('id', $p['id'])->update(['status' => 'RESULT_PUBLISHED']);
                    } elseif ($status === 'RESULT_PUBLISHED') {
                        DB::table('draw_periods')->where('id', $p['id'])->update(['status' => 'COMPLETED']);
                    }
                    continue;
                }

                $salesOpen = (int) ($p['salesOpenTime'] ?? 0);
                $salesClose = (int) ($p['salesCloseTime'] ?? 0);
                $drawTime = (int) ($p['drawTime'] ?? 0);
                if ($nowMs < $salesOpen) {
                    $desired = 'SCHEDULED';
                } elseif ($nowMs < $salesClose) {
                    $desired = 'OPEN';
                } elseif ($nowMs < $drawTime) {
                    $desired = 'SALES_CLOSED';
                } else {
                    $desired = 'RESULT_PENDING';
                }
                if ($desired !== $status) {
                    DB::table('draw_periods')->where('id', $p['id'])->update(['status' => $desired]);
                }
            }
        } catch (QueryException $e) {
            // Best effort, same as Flutter.
        }
    }

    public function ensurePeriodExists(string $periodId): void
    {
        try {
            if ($this->getPeriodById($periodId) !== null) {
                return;
            }
            $parts = explode('-', $periodId);
            if (count($parts) < 4) {
                return;
            }
            $drawType = (string) end($parts);
            $dateStr = implode('-', array_slice($parts, 0, 3));
            $cfg = [];
            foreach (DB::table('app_config')
                ->whereIn('key', [
                    'midday_sales_open', 'midday_sales_close', 'midday_draw_time',
                    'evening_sales_open', 'evening_sales_close', 'evening_draw_time',
                    'draw_timezone',
                ])->get() as $row) {
                $cfg[$row->key] = (string) $row->value;
            }
            $tz = $cfg['draw_timezone'] ?? TimezoneService::DEFAULT_TIMEZONE;
            if ($drawType === 'MIDDAY') {
                $openStr = $cfg['midday_sales_open'] ?? '00:00';
                $closeStr = $cfg['midday_sales_close'] ?? '14:15';
                $drawStr = $cfg['midday_draw_time'] ?? '14:30';
            } else {
                $openStr = $cfg['evening_sales_open'] ?? '00:00';
                $closeStr = $cfg['evening_sales_close'] ?? '22:20';
                $drawStr = $cfg['evening_draw_time'] ?? '22:30';
            }
            $parse = static function (string $s): array {
                $p = explode(':', $s);

                return [(int) ($p[0] ?? 0), (int) ($p[1] ?? 0)];
            };
            [$y, $m, $d] = array_map('intval', explode('-', $dateStr));
            [$ch, $cm] = $parse($closeStr);
            [$dh, $dm] = $parse($drawStr);
            $baseDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
            $salesOpen = TimezoneService::epochForWallTime($baseDate, 0, 0, $tz);
            $salesClose = TimezoneService::epochForWallTime($baseDate, $ch, $cm, $tz);
            $drawTime = TimezoneService::epochForWallTime($baseDate, $dh, $dm, $tz);
            DB::table('draw_periods')->insertOrIgnore([
                'id' => $periodId,
                'drawType' => $drawType,
                'drawDate' => $dateStr,
                'salesOpenTime' => $salesOpen,
                'salesCloseTime' => $salesClose,
                'drawTime' => $drawTime,
                'status' => 'SCHEDULED',
                'timezone' => $tz,
            ]);
            $this->refreshPeriodStatuses();
        } catch (QueryException $e) {
            // Best effort, same as Flutter.
        }
    }

    private function doSeedDrawPeriods(): void
    {
        try {
            $cfg = [];
            foreach (DB::table('app_config')
                ->whereIn('key', [
                    'midday_sales_close', 'midday_draw_time', 'midday_enabled',
                    'evening_sales_close', 'evening_draw_time', 'evening_enabled', 'draw_timezone',
                ])->get() as $row) {
                $cfg[$row->key] = (string) $row->value;
            }
            $middayClose = $cfg['midday_sales_close'] ?? '14:15';
            $middayDraw = $cfg['midday_draw_time'] ?? '14:30';
            $eveningClose = $cfg['evening_sales_close'] ?? '22:20';
            $eveningDraw = $cfg['evening_draw_time'] ?? '22:30';
            $middayEnabled = ($cfg['midday_enabled'] ?? '1') === '1';
            $eveningEnabled = ($cfg['evening_enabled'] ?? '1') === '1';
            $tz = $cfg['draw_timezone'] ?? TimezoneService::DEFAULT_TIMEZONE;
            $parse = static function (string $s): array {
                $p = explode(':', trim($s));

                return [(int) ($p[0] ?? 0), (int) ($p[1] ?? 0)];
            };

            $today = (new \DateTimeImmutable())->setTime(0, 0);
            for ($i = 0; $i < 14; $i++) {
                $date = $today->modify('+' . $i . ' days');
                $dateStr = $date->format('Y-m-d');
                $periods = [];
                if ($middayEnabled) {
                    $periods[] = ['MIDDAY', $parse($middayClose), $parse($middayDraw)];
                }
                if ($eveningEnabled) {
                    $periods[] = ['EVENING', $parse($eveningClose), $parse($eveningDraw)];
                }
                foreach ($periods as [$type, $closeHm, $drawHm]) {
                    $salesClose = TimezoneService::epochForWallTime($date, $closeHm[0], $closeHm[1], $tz);
                    $drawTime = TimezoneService::epochForWallTime($date, $drawHm[0], $drawHm[1], $tz);
                    $salesOpen = TimezoneService::epochForWallTime($date, 0, 0, $tz);
                    $id = $dateStr . '-' . $type;
                    $existing = DB::table('draw_periods')->where('id', $id)->first();
                    if ($existing === null) {
                        DB::table('draw_periods')->insertOrIgnore([
                            'id' => $id,
                            'drawType' => $type,
                            'drawDate' => $dateStr,
                            'salesOpenTime' => $salesOpen,
                            'salesCloseTime' => $salesClose,
                            'drawTime' => $drawTime,
                            'status' => 'SCHEDULED',
                            'timezone' => $tz,
                        ]);
                    } else {
                        // Schedule config must take effect on periods that have not
                        // been manually locked. insertOrIgnore alone left seeded
                        // periods stuck on the old times after an admin changed
                        // midday_sales_close / midday_draw_time / draw_timezone.
                        $status = strtoupper((string) ($existing->status ?? ''));
                        $locked = in_array($status, [
                            'CANCELLED', 'POSTPONED', 'SUSPENDED',
                            'RESULT_PUBLISHED', 'COMPLETED', 'DRAWING',
                        ], true);
                        foreach (['result', 'result3', 'result4'] as $resultKey) {
                            if (trim((string) ($existing->{$resultKey} ?? '')) !== '') {
                                $locked = true;
                            }
                        }
                        if (! $locked) {
                            DB::table('draw_periods')->where('id', $id)->update([
                                'salesOpenTime' => $salesOpen,
                                'salesCloseTime' => $salesClose,
                                'drawTime' => $drawTime,
                                'timezone' => $tz,
                            ]);
                        }
                    }
                }
            }
            $this->refreshPeriodStatuses();
        } catch (QueryException $e) {
            // Best effort, same as Flutter.
        }
    }

    // -------------------------------------------------------------- bootstrap
    /** Idempotent seed: default config, accounts, games, draws and periods. */
    public function bootstrap(): void
    {
        try {
            if (DB::table('app_config')->where('key', 'api_bootstrap_v1')->exists()) {
                return;
            }
        } catch (QueryException $e) {
            // Tables may not be migrated yet; continue and let the caller see the error.
        }

        $now = self::nowMs();

        DB::table('customers')->insertOrIgnore([
            'id' => 'cust_default',
            'name' => 'Cash Customer',
            'phone' => '',
            'notes' => '',
            'isDefault' => 1,
            'createdAt' => $now,
        ]);

        // Four-level role model: cashier < supervisor < manager < owner.
        try {
            DB::table('accounts')->whereIn('role', ['agent', 'cashier', 'worker'])->update(['role' => 'cashier']);
            DB::table('accounts')->where('role', 'admin')->update(['role' => 'owner']);
        } catch (QueryException $e) {
        }
        $this->ensureAccount('acc_admin', 'admin', 'admin123', 'owner', 0, $now);
        $this->ensureAccount('acc_manager', 'manager', 'manager123', 'manager', 0, $now);
        $this->ensureAccount('acc_supervisor', 'supervisor', 'supervisor123', 'supervisor', 5.0, $now);
        $this->ensureAccount('acc_worker1', 'worker1', 'worker123', 'cashier', 5.0, $now);
        DB::table('accounts')->where('username', 'admin')->update([
            'password' => 'admin123', 'status' => 'active', 'role' => 'owner',
        ]);
        DB::table('accounts')->where('username', 'manager')->update([
            'password' => 'manager123', 'status' => 'active', 'role' => 'manager',
        ]);
        DB::table('accounts')->where('username', 'supervisor')->update([
            'password' => 'supervisor123', 'status' => 'active', 'role' => 'supervisor',
        ]);
        DB::table('accounts')->where('username', 'worker1')->update([
            'password' => 'worker123', 'status' => 'active', 'role' => 'cashier',
        ]);
        DB::table('accounts')->where('role', 'cashier')->whereNull('commission')->update(['commission' => 5.0]);
        DB::table('accounts')->where('role', 'cashier')->where('commission', 0)->update(['commission' => 5.0]);

        // Games.
        if (DB::table('games')->count() === 0) {
            DB::table('games')->insert([
                [
                    'id' => 'g_numbers', 'name' => 'Numbers', 'type' => 'numbers',
                    'description' => 'NY Lottery Pick 3 (0-9) — Straight/Box/Combo/StrBox/Pairs',
                    'minAmount' => 0.5, 'maxAmount' => 10, 'maxSelections' => 5,
                    'numberRangeMin' => 0, 'numberRangeMax' => 9,
                ],
                [
                    'id' => 'g_winz', 'name' => 'Win 4', 'type' => 'winz',
                    'description' => 'NY Lottery Win 4 (0-9) — 4 digits Straight/Box/Combo',
                    'minAmount' => 0.5, 'maxAmount' => 10, 'maxSelections' => 4,
                    'numberRangeMin' => 0, 'numberRangeMax' => 9,
                ],
            ]);
        }
        DB::table('games')->where('id', 'g_winz')->update([
            'name' => 'Win 4', 'type' => 'winz', 'maxSelections' => 4,
            'minAmount' => 0.5, 'maxAmount' => 10, 'numberRangeMin' => 0, 'numberRangeMax' => 9,
            'description' => 'NY Lottery Win 4 (0-9) — 4 digits Straight/Box/Combo',
        ]);
        DB::table('games')->where('id', 'g_numbers')->update([
            'name' => 'Numbers', 'maxSelections' => 5, 'minAmount' => 0.5, 'maxAmount' => 10,
            'numberRangeMin' => 0, 'numberRangeMax' => 9,
            'description' => 'NY Lottery Pick 3 (0-9) — Straight/Box/Combo/StrBox/Pairs',
        ]);

        // Draws: 14 days, Midday 14:30 + Evening 22:30 for both games.
        $tz = TimezoneService::DEFAULT_TIMEZONE;
        $today = (new \DateTimeImmutable())->setTime(0, 0);
        for ($i = 0; $i < 14; $i++) {
            $base = $today->modify('+' . $i . ' days');
            foreach ([['Midday', 14, 30], ['Evening', 22, 30]] as [$label, $hh, $mm]) {
                $dt = TimezoneService::epochForWallTime($base, $hh, $mm, $tz);
                $datePart = $base->format('Ymd');
                $timePart = sprintf('%02d/%02d %02d:%02d', (int) $base->format('m'), (int) $base->format('d'), $hh, $mm);
                foreach ([
                    ['g_numbers', 'Numbers', 'draw_numbers_'],
                    ['g_winz', 'Win 4', 'draw_win4_'],
                ] as [$gameId, $gameName, $prefix]) {
                    DB::table('draws')->insertOrIgnore([
                        'id' => $prefix . strtolower($label) . '_' . $datePart,
                        'gameId' => $gameId,
                        'name' => $gameName . ' ' . $label . ' - ' . $timePart,
                        'drawTime' => $dt,
                        'status' => 'open',
                    ]);
                }
            }
        }
        // Legacy migrations.
        DB::table('draws')->where('name', 'like', '%Morning%')->update([
            'drawTime' => DB::raw('`drawTime` + (4*3600+30*60)*1000'),
        ]);
        DB::table('draws')->where('name', 'like', '%Morning%')->update([
            'name' => DB::raw("REPLACE(`name`, 'Morning', 'Midday')"),
        ]);
        DB::table('draws')->where('name', 'like', '%Night%')->update(['status' => 'closed']);

        $defaults = [
            // draw schedule
            'midday_sales_close' => '14:15', 'midday_draw_time' => '14:30', 'midday_enabled' => '1',
            'evening_sales_close' => '22:20', 'evening_draw_time' => '22:30', 'evening_enabled' => '1',
            'draw_timezone' => TimezoneService::DEFAULT_TIMEZONE,
            'midday_result_mode' => 'manual', 'evening_result_mode' => 'manual',
            'auto_open_next' => '1', 'winning_time_restricted' => '1',
            // risk limits
            'limit_straight_3' => '100', 'limit_straight_4' => '10',
            'limit_box_3' => '50', 'limit_box_4' => '50',
            'limit_combo_3' => '100', 'limit_combo_4' => '10',
            'limit_strbox_3' => '50', 'limit_strbox_4' => '50',
            // multipliers
            'straight_multiplier' => '500', 'box_multiplier' => '80', 'combo_multiplier' => '500',
            'straight_3d_multiplier' => '500', 'straight_4d_multiplier' => '5000',
            'box_3d_multiplier' => '160', 'box_4d_multiplier' => '400',
            'winz_std_multiplier' => '10', 'winz_jackpot_multiplier' => '100', 'winz_instant_multiplier' => '5',
            'numbers_straight' => '500', 'numbers_box_3way' => '160', 'numbers_box_6way' => '80',
            'numbers_front_pair' => '50', 'numbers_back_pair' => '50',
            'win4_straight' => '5000', 'win4_box_4way' => '1200', 'win4_box_6way' => '800',
            'win4_box_12way' => '400', 'win4_box_24way' => '200',
            // general / backup / printing
            'currency' => 'USD', 'language' => 'en-US', 'company_name' => 'NY Lottery POS',
            'ticket_footer' => '', 'backup_enabled' => '1', 'backup_interval' => 'daily',
            'backup_retention' => '10', 'client_commission_rate' => '20',
            'printer_type' => 'thermal_58mm', 'printer_connection' => 'bluetooth',
        ];
        foreach ($defaults as $key => $value) {
            DB::table('app_config')->insertOrIgnore([
                'key' => $key, 'value' => (string) $value, 'scope' => 'local', 'updatedAt' => $now,
            ]);
        }
        DB::table('app_config')->where('key', 'win4_box_4way')->where('value', '1198')->update(['value' => '1200']);
        DB::table('app_config')->where('key', 'ticket_footer')
            ->where('value', 'Good luck! Please play responsibly.')
            ->update(['value' => '']);

        $this->doSeedDrawPeriods();
        $this->refreshPeriodStatuses();

        DB::table('app_config')->updateOrInsert(
            ['key' => 'api_bootstrap_v1'],
            ['value' => '1', 'scope' => 'local', 'updatedAt' => self::nowMs()],
        );
    }

    private function ensureAccount(
        string $id,
        string $username,
        string $password,
        string $role,
        float $commission,
        int $now,
    ): void {
        if (DB::table('accounts')->where('username', $username)->exists()) {
            return;
        }
        DB::table('accounts')->insertOrIgnore([
            'id' => $id,
            'username' => $username,
            'password' => $password,
            'role' => $role,
            'status' => 'active',
            'createdAt' => $now,
            'commission' => $commission,
        ]);
    }
}
