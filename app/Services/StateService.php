<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Port of the business logic in Flutter lib/src/providers/app_state.dart.
 *
 * State that lived in the ChangeNotifier (current user, cart, customer,
 * opening cash) is hydrated from the RPC args on every call and returned back
 * to the client in `result.state` by ApiController. This keeps HTTP requests
 * stateless while preserving the original semantics.
 */
class StateService
{
    public const DEFAULT_CUSTOMER_ID = 'cust_default';
    public const DEFAULT_CUSTOMER_NAME = 'Cash Customer';

    private ?array $_currentUser = null;
    private ?int $_loginAt = null;
    private array $_currentCustomer = ['id' => self::DEFAULT_CUSTOMER_ID, 'name' => self::DEFAULT_CUSTOMER_NAME];
    /** @var array<int, array> */
    private array $_cart = [];
    private float $_openingCash = 0.0;

    public function __construct(private readonly DbService $db)
    {
    }

    private static function nowMs(): int
    {
        return DbService::nowMs();
    }

    private static function uuid(): string
    {
        return DbService::uuid();
    }

    private static function fmt2(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private static function nonempty(mixed $v): bool
    {
        return $v !== null && trim((string) $v) !== '';
    }

    private static function fail(string $message): never
    {
        throw new RuntimeException($message);
    }

    // --------------------------------------------------------- state envelope
    public function hydrate(array $args): void
    {
        $this->_currentUser = (isset($args['user']) && is_array($args['user'])) ? $args['user'] : null;
        $this->_loginAt = ! empty($args['loginAt'])
            ? (int) $args['loginAt']
            : ($this->_currentUser !== null ? self::nowMs() : null);
        $this->_currentCustomer = (isset($args['customer']) && is_array($args['customer']))
            ? $args['customer']
            : ['id' => self::DEFAULT_CUSTOMER_ID, 'name' => self::DEFAULT_CUSTOMER_NAME];
        $this->_openingCash = (float) ($args['openingCash'] ?? 0);

        $this->_cart = [];
        foreach (($args['cart'] ?? []) as $line) {
            if (is_array($line)) {
                $this->_cart[] = $this->normalizeCartLine($line);
            }
        }
    }

    public function snapshot(): array
    {
        return [
            'user' => $this->_currentUser,
            'loginAt' => $this->_loginAt,
            'customer' => $this->_currentCustomer,
            'cart' => array_map(fn (array $i): array => $this->cartLineToMap($i), $this->_cart),
            'openingCash' => $this->_openingCash,
            'isLoggedIn' => $this->isLoggedIn(),
            'isSessionExpired' => $this->isSessionExpired(),
            'isAdmin' => $this->isAdmin(),
            'cartCount' => count($this->_cart),
            'cartTotal' => $this->cartTotal(),
        ];
    }

    private function normalizeCartLine(array $src): array
    {
        $numbers = $src['numbers'] ?? [];
        if (is_string($numbers)) {
            $numbers = array_map('trim', explode(',', $numbers));
        }
        $numbers = array_values(array_filter(array_map('strval', (array) $numbers), static fn ($x) => $x !== ''));

        return [
            'id' => (string) ($src['id'] ?? self::uuid()),
            'gameId' => (string) ($src['gameId'] ?? ''),
            'gameName' => (string) ($src['gameName'] ?? ''),
            'drawId' => $src['drawId'] ?? null,
            'drawName' => $src['drawName'] ?? null,
            'periodId' => $src['periodId'] ?? null,
            'drawType' => $src['drawType'] ?? null,
            'numbers' => $numbers,
            'betType' => (string) ($src['betType'] ?? 'Straight'),
            'amount' => (float) ($src['amount'] ?? 0),
            'isQuickPick' => ! empty($src['isQuickPick']),
        ];
    }

    private function cartLineToMap(array $i): array
    {
        return [
            'id' => $i['id'],
            'gameId' => $i['gameId'],
            'gameName' => $i['gameName'],
            'drawId' => $i['drawId'],
            'drawName' => $i['drawName'],
            'periodId' => $i['periodId'],
            'drawType' => $i['drawType'],
            'numbers' => implode(',', $i['numbers']),
            'betType' => $i['betType'],
            'amount' => $i['amount'],
            'isQuickPick' => $i['isQuickPick'] ? 1 : 0,
        ];
    }

    // ----------------------------------------------------------- basic state
    public function currentCustomer(): array
    {
        return $this->_currentCustomer;
    }

    public function currentUser(): ?array
    {
        return $this->_currentUser;
    }

    public function isLoggedIn(): bool
    {
        return $this->_currentUser !== null;
    }

    public function isSessionExpired(): bool
    {
        if ($this->_loginAt === null) {
            return true;
        }

        return (self::nowMs() - $this->_loginAt) >= 8 * 60 * 60 * 1000;
    }

    public function isAdmin(): bool
    {
        return ($this->_currentUser['role'] ?? null) === 'admin';
    }

    public function cart(): array
    {
        return array_map(fn (array $i): array => $this->cartLineToMap($i), $this->_cart);
    }

    public function cartCount(): int
    {
        return count($this->_cart);
    }

    public function cartTotal(): float
    {
        return array_sum(array_column($this->_cart, 'amount'));
    }

    public function isCartEmpty(): bool
    {
        return $this->_cart === [];
    }

    public function openingCash(): float
    {
        return $this->_openingCash;
    }

    public function login(string $username, string $password): bool
    {
        $acc = $this->db->findAccount(trim($username), $password);
        if ($acc === null || ($acc['status'] ?? null) !== 'active') {
            return false;
        }
        $this->_currentUser = $acc;
        $this->_loginAt = self::nowMs();

        return true;
    }

    /**
     * Let the signed-in user change their own username and password.
     * Requires the current password and a unique new username.
     */
    public function changeCredentials(
        string $currentPassword,
        string $newUsername,
        string $newPassword,
    ): array {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();

        $id = (string) ($this->_currentUser['id'] ?? '');
        $account = null;
        foreach ($this->db->getAccounts() as $a) {
            if ((string) ($a['id'] ?? '') === $id) {
                $account = $a;
                break;
            }
        }
        if ($account === null) {
            self::fail('Account not found');
        }
        if ((string) ($account['password'] ?? '') !== $currentPassword) {
            self::fail('Current password is incorrect');
        }

        $newUsername = trim($newUsername);
        if ($newUsername === '') {
            self::fail('New username is required');
        }
        if ($newPassword === '') {
            self::fail('New password is required');
        }
        foreach ($this->db->getAccounts() as $a) {
            if ((string) ($a['id'] ?? '') !== $id
                && (string) ($a['username'] ?? '') === $newUsername) {
                self::fail('Username already taken');
            }
        }

        $this->db->updateAccountCredentials($id, $newUsername, $newPassword);
        $this->_currentUser['username'] = $newUsername;
        $this->_currentUser['password'] = $newPassword;

        return $this->snapshot();
    }

    public function logout(): bool
    {
        $this->_currentUser = null;
        $this->_loginAt = null;
        $this->_cart = [];
        $this->_currentCustomer = ['id' => self::DEFAULT_CUSTOMER_ID, 'name' => self::DEFAULT_CUSTOMER_NAME];

        return true;
    }

    private function ensureLoggedIn(): void
    {
        if ($this->_currentUser === null) {
            self::fail('Not logged in');
        }
    }

    private function ensureNotExpired(): void
    {
        if ($this->isSessionExpired()) {
            self::fail('Session expired — please login again');
        }
    }

    private function configMap(): array
    {
        $cfg = [];
        foreach ($this->db->getAppConfig() as $c) {
            $cfg[(string) $c['key']] = (string) $c['value'];
        }

        return $cfg;
    }

    private function timezone(): string
    {
        return $this->configMap()['draw_timezone'] ?? TimezoneService::DEFAULT_TIMEZONE;
    }

    // ------------------------------------------------------ bet/period helpers
    private function normBet(string $s): string
    {
        return str_replace(['-', '_', ' '], ['/', '/', ''], strtolower(trim($s)));
    }

    private function isComboBet(string $norm): bool
    {
        return str_contains($norm, 'combo');
    }

    private function isStraightBoxBet(string $norm): bool
    {
        return in_array($norm, [
            'all', 'all/box', 'all/straight', 'straightbox', 'straight/box',
            'str/box', 'strbox', 'st/box', 'stbox',
        ], true)
            || (str_contains($norm, 'straight') && str_contains($norm, 'box'));
    }

    private function periodIdForDate(\DateTimeImmutable $date, string $drawType): string
    {
        return $date->format('Y-m-d') . '-' . strtoupper($drawType);
    }

    private function normalizeDrawType(?string $raw): string
    {
        $s = strtoupper(trim((string) $raw));
        if ($s === '') {
            return $s;
        }
        if ($s === 'EVENING' || str_contains($s, 'EVENING') || str_contains($s, 'EVE')
            || str_contains($s, 'مساء') || str_contains($s, 'مسائ')) {
            return 'EVENING';
        }
        if ($s === 'MORNING' || $s === 'MIDDAY' || str_contains($s, 'MIDDAY')
            || str_contains($s, 'MORNING') || str_contains($s, 'MID') || str_contains($s, 'صباح')) {
            return 'MIDDAY';
        }

        return $s;
    }

    private function drawTypeFromName(?string $name): string
    {
        $n = strtolower((string) $name);
        if (str_contains($n, 'evening') || str_contains($n, 'مساء') || str_contains($n, 'مسائ')) {
            return 'EVENING';
        }
        if (str_contains($n, 'midday') || str_contains($n, 'morning') || str_contains($n, 'صباح')) {
            return 'MIDDAY';
        }

        return '';
    }

    private function wallDateFromDraw(array $draw): \DateTimeImmutable
    {
        return TimezoneService::wallTimeFromEpoch((int) $draw['drawTime'], $this->timezone());
    }

    private function drawTypeFromDraw(array $d): string
    {
        $fromName = $this->drawTypeFromName($d['name'] ?? null);
        if ($fromName === 'MIDDAY' || $fromName === 'EVENING') {
            return $fromName;
        }
        $id = strtolower((string) ($d['id'] ?? ''));
        if (str_contains($id, 'evening')) {
            return 'EVENING';
        }
        if (str_contains($id, 'midday') || str_contains($id, 'morning')) {
            return 'MIDDAY';
        }

        return (int) $this->wallDateFromDraw($d)->format('H') >= 18 ? 'EVENING' : 'MIDDAY';
    }

    private function drawTypeFromPeriodId(?string $periodId): string
    {
        if ($periodId === null) {
            return '';
        }
        $u = strtoupper(trim(str_replace('/', '-', $periodId)));
        if (str_ends_with($u, 'EVENING')) {
            return 'EVENING';
        }
        if (str_ends_with($u, 'MIDDAY') || str_ends_with($u, 'MORNING')) {
            return 'MIDDAY';
        }

        return '';
    }

    private function drawTypeLabel(string $drawType): string
    {
        return $this->normalizeDrawType($drawType) === 'EVENING' ? 'مسائي' : 'صباحي';
    }

    private function periodHasResult(array $p): bool
    {
        return self::nonempty($p['result'] ?? null)
            || self::nonempty($p['result3'] ?? null)
            || self::nonempty($p['result4'] ?? null);
    }

    private function periodBlocked(array $p): bool
    {
        $s = strtoupper((string) ($p['status'] ?? ''));

        return in_array($s, ['CANCELLED', 'POSTPONED', 'SUSPENDED'], true);
    }

    private function drawMetaForPeriod(string $gameId, array $period): array
    {
        $periodId = (string) $period['id'];
        $periodDrawType = $this->normalizeDrawType($period['drawType'] ?? null);
        $date = (string) ($period['drawDate'] ?? '');
        $fallbackName = ($periodDrawType === 'EVENING' ? 'Evening' : 'Midday') . ' ' . $date;

        foreach ($this->db->getDrawsByGame($gameId) as $d) {
            if ($this->drawTypeFromDraw($d) !== $periodDrawType) {
                continue;
            }
            if ($this->periodIdForDate($this->wallDateFromDraw($d), $periodDrawType) === $periodId) {
                return [$d['id'], $d['name']];
            }
        }

        return [null, $fallbackName];
    }

    private function periodInSalesWindow(array $p): bool
    {
        if ($this->periodBlocked($p) || $this->periodHasResult($p)) {
            return false;
        }
        $nowMs = self::nowMs();

        return $nowMs >= (int) ($p['salesOpenTime'] ?? 0) && $nowMs < (int) ($p['salesCloseTime'] ?? 0);
    }

    private function resolveNextSellablePeriod(string $drawType): array
    {
        $normalized = $this->normalizeDrawType($drawType);
        if ($normalized !== 'MIDDAY' && $normalized !== 'EVENING') {
            self::fail('فترة غير معروفة: ' . $drawType);
        }
        $this->db->seedDrawPeriods();
        $today = (new \DateTimeImmutable())->setTime(0, 0);
        for ($i = 0; $i < 3; $i++) {
            $this->db->ensurePeriodExists($this->periodIdForDate($today->modify('+' . $i . ' days'), $normalized));
        }
        $this->db->refreshPeriodStatuses();

        $ofType = [];
        foreach ($this->db->getAllPeriods() as $p) {
            if ($this->normalizeDrawType($p['drawType'] ?? null) === $normalized) {
                $ofType[] = $p;
            }
        }
        usort($ofType, static fn ($a, $b) => ((int) $a['drawTime']) <=> ((int) $b['drawTime']));

        foreach ($ofType as $p) {
            if ($this->periodInSalesWindow($p)) {
                return $p;
            }
        }
        self::fail('السحب ' . $this->drawTypeLabel($normalized) . ' مغلق أو انتهى — لا يمكن اللعب');
    }

    public function isDrawTypeSellableNow(string $drawType): bool
    {
        try {
            $this->resolveNextSellablePeriod($drawType);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function validatePeriodOpen(string $periodId): void
    {
        $periodId = strtoupper(trim(str_replace('/', '-', $periodId)));
        $this->db->seedDrawPeriods();
        $this->db->refreshPeriodStatuses();
        $p = $this->db->getPeriodById($periodId);
        if ($p === null) {
            $this->db->ensurePeriodExists($periodId);
            $p = $this->db->getPeriodById($periodId);
        }
        if ($p === null) {
            $p = $this->db->getPeriodById(strtolower($periodId));
        }
        if ($p === null) {
            $this->db->ensurePeriodExists(strtoupper($periodId));
            $p = $this->db->getPeriodById(strtoupper($periodId));
        }
        if ($p === null) {
            self::fail('Period ' . $periodId . ' not found — schedule not generated');
        }
        if ($this->periodBlocked($p)) {
            self::fail('الفترة ' . $periodId . ' ملغاة أو موقوفة');
        }
        if ($this->periodHasResult($p)) {
            self::fail('تم نشر نتيجة ' . $periodId . ' — انسخها لفترة تالية (صباحي أو مسائي)');
        }
        $now = self::nowMs();
        $salesOpen = (int) $p['salesOpenTime'];
        $salesClose = (int) $p['salesCloseTime'];
        $drawTime = (int) ($p['drawTime'] ?? $salesClose);
        if ($now < $salesOpen) {
            self::fail('السحب ' . $periodId . ' لم يُفتح بعد');
        }
        if ($now >= $salesClose || $now >= $drawTime) {
            self::fail('السحب ' . $periodId . ' مغلق أو انتهى — لا يمكن اللعب');
        }
        if (($p['status'] ?? null) !== 'OPEN' && $now >= $salesOpen && $now < $salesClose) {
            try {
                $this->db->updatePeriodStatus($periodId, 'OPEN');
            } catch (Throwable $e) {
            }
        }
    }

    // --------------------------------------------------- per-number limits
    private function betGroup(string $norm): string
    {
        if ($this->isComboBet($norm)) {
            return 'combo';
        }
        if ($this->isStraightBoxBet($norm)) {
            return 'strbox';
        }
        if ($norm === 'box') {
            return 'box';
        }

        return 'straight';
    }

    private function canonicalKey(array $digits, string $group): string
    {
        if (in_array($group, ['box', 'combo', 'strbox'], true)) {
            $sorted = array_map('strval', $digits);
            sort($sorted, SORT_STRING);

            return implode('', $sorted);
        }

        return implode('', $digits);
    }

    private function getLimitFor(string $group, int $nDigits): float
    {
        $fallbacks = [
            'straight|3' => 100, 'straight|4' => 10,
            'box|3' => 50, 'box|4' => 50,
            'combo|3' => 100, 'combo|4' => 10,
            'strbox|3' => 50, 'strbox|4' => 50,
        ];
        $fallback = (float) ($fallbacks[$group . '|' . $nDigits] ?? 10);
        try {
            $key = 'limit_' . $group . '_' . $nDigits;
            $value = $this->db->getAppConfig();
            foreach ($value as $row) {
                if ($row['key'] === $key && is_numeric($row['value'])) {
                    return (float) $row['value'];
                }
            }
            if (in_array($group, ['straight', 'box'], true)) {
                $altKey = 'limit_' . $group;
                foreach ($value as $row) {
                    if ($row['key'] === $altKey && is_numeric($row['value'])) {
                        return (float) $row['value'];
                    }
                }
            }
        } catch (Throwable $e) {
        }

        return $fallback;
    }

    private function floorToStep(float $v, float $step = 0.5): float
    {
        return floor($v / $step) * $step;
    }

    private function replayPlayKey(array $l): string
    {
        return $l['gameId'] . '|' . $l['numbers'] . '|' . $l['betType'] . '|' . $l['amount'];
    }

    private function dedupeLinesForSinglePeriod(array $source): array
    {
        $seen = [];
        $result = [];
        foreach ($source as $l) {
            $key = $this->replayPlayKey($l);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $l;
            }
        }

        return $result;
    }

    public function comboCount(array $digits): int
    {
        $n = count($digits);
        $freq = [];
        foreach ($digits as $d) {
            $freq[$d] = ($freq[$d] ?? 0) + 1;
        }
        $denom = 1;
        foreach ($freq as $c) {
            $denom *= $this->factorial($c);
        }

        return intdiv($this->factorial($n), $denom);
    }

    private function factorial(int $n): int
    {
        return $n <= 1 ? 1 : $n * $this->factorial($n - 1);
    }

    private function maxInputFor(array $numbers, string $betType): float
    {
        $bt = $this->normBet($betType);
        if ($this->isComboBet($bt)) {
            return $this->comboCount($numbers) * 10.0;
        }
        if ($this->isStraightBoxBet($bt) || $bt === 'box') {
            return 50.0;
        }

        return count($numbers) === 3 ? 100.0 : 10.0;
    }

    private function remainingFor(string $periodId, string $gameId, array $numbers, string $betType): array
    {
        $norm = $this->normBet($betType);
        $group = $this->betGroup($norm);
        $n = count($numbers);
        $limit = $this->getLimitFor($group, $n);
        if ($limit <= 0) {
            return [
                'remaining' => INF, 'effectiveLimit' => INF, 'limit' => $limit,
                'combos' => null, 'existingSum' => 0.0, 'cartSum' => 0.0,
            ];
        }
        $canonical = $this->canonicalKey($numbers, $group);
        $pidNorm = strtoupper(trim(str_replace('/', '-', $periodId)));
        $existingSum = 0.0;
        $cartSum = 0.0;
        try {
            foreach ($this->db->getTicketLinesByPeriod($pidNorm) as $l) {
                if (($l['periodStatus'] ?? null) === 'cancelled') {
                    continue;
                }
                if (($l['gameId'] ?? null) !== $gameId) {
                    continue;
                }
                $lGroup = $this->betGroup($this->normBet((string) ($l['betType'] ?? 'Straight')));
                if ($lGroup !== $group) {
                    continue;
                }
                $lNums = $this->digitsFromString((string) ($l['numbers'] ?? ''));
                if (count($lNums) !== $n || $this->canonicalKey($lNums, $lGroup) !== $canonical) {
                    continue;
                }
                $existingSum += (float) $l['amount'];
            }
        } catch (Throwable $e) {
        }
        foreach ($this->_cart as $c) {
            if ($c['periodId'] === null
                || strtoupper(trim(str_replace('/', '-', $c['periodId']))) !== $pidNorm
                || $c['gameId'] !== $gameId) {
                continue;
            }
            $cGroup = $this->betGroup($this->normBet($c['betType']));
            if ($cGroup !== $group || count($c['numbers']) !== $n
                || $this->canonicalKey($c['numbers'], $cGroup) !== $canonical) {
                continue;
            }
            $cartSum += $c['amount'];
        }
        $effectiveLimit = $limit;
        $combos = null;
        if ($group === 'combo') {
            $combos = $this->comboCount($numbers);
            if ($combos > 1) {
                $effectiveLimit = $limit * $combos;
            }
        }
        $remaining = max(0.0, min($effectiveLimit, $effectiveLimit - $existingSum - $cartSum));

        return [
            'remaining' => $remaining,
            'effectiveLimit' => $effectiveLimit,
            'limit' => $limit,
            'combos' => $combos,
            'existingSum' => $existingSum,
            'cartSum' => $cartSum,
        ];
    }

    private function digitsFromString(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($x) => $x !== ''));
    }

    private function enforcePerPeriodNumberLimit(
        string $periodId,
        string $gameId,
        array $numbers,
        string $betType,
        float $amount,
    ): void {
        $group = $this->betGroup($this->normBet($betType));
        $n = count($numbers);
        $limit = $this->getLimitFor($group, $n);
        if ($limit <= 0) {
            return;
        }
        $canonical = $this->canonicalKey($numbers, $group);
        $pidNorm = strtoupper(trim(str_replace('/', '-', $periodId)));
        $existingSum = 0.0;
        $cartSum = 0.0;
        try {
            foreach ($this->db->getTicketLinesByPeriod($pidNorm) as $l) {
                if (($l['periodStatus'] ?? null) === 'cancelled'
                    || ($l['gameId'] ?? null) !== $gameId) {
                    continue;
                }
                $lGroup = $this->betGroup($this->normBet((string) ($l['betType'] ?? 'Straight')));
                $lNums = $this->digitsFromString((string) ($l['numbers'] ?? ''));
                if ($lGroup !== $group || count($lNums) !== $n
                    || $this->canonicalKey($lNums, $lGroup) !== $canonical) {
                    continue;
                }
                $existingSum += (float) $l['amount'];
            }
        } catch (Throwable $e) {
        }
        foreach ($this->_cart as $c) {
            if ($c['periodId'] === null
                || strtoupper(trim(str_replace('/', '-', $c['periodId']))) !== $pidNorm
                || $c['gameId'] !== $gameId) {
                continue;
            }
            $cGroup = $this->betGroup($this->normBet($c['betType']));
            if ($cGroup !== $group || count($c['numbers']) !== $n
                || $this->canonicalKey($c['numbers'], $cGroup) !== $canonical) {
                continue;
            }
            $cartSum += $c['amount'];
        }
        $effectiveLimit = $limit;
        $combos = null;
        if ($group === 'combo') {
            $combos = $this->comboCount($numbers);
            if ($combos > 1) {
                $effectiveLimit = $limit * $combos;
            }
        }
        $total = $existingSum + $cartSum + $amount;
        if ($total > $effectiveLimit + 1e-9) {
            $remaining = max(0.0, min($effectiveLimit, $effectiveLimit - $existingSum - $cartSum));
            $isBoxGroup = in_array($group, ['box', 'combo', 'strbox'], true);
            $example = $isBoxGroup ? ' (حتى لو تشقلبت الأرقام مثل 1123 = 2113)' : '';
            $digitLabel = $n === 3 ? '3' : '4';
            $groupLabel = match ($group) {
                'straight' => 'Straight ' . $digitLabel,
                'box' => 'Box ' . $digitLabel,
                'combo' => 'Combo ' . $digitLabel,
                default => 'ST/BOX ' . $digitLabel,
            };
            $limitDetail = ($group === 'combo' && $combos !== null && $combos > 1)
                ? sprintf('$%s × %d = $%s إجمالي ($%s لكل تركيبة)', self::fmt2($limit), $combos, self::fmt2($effectiveLimit), self::fmt2($limit))
                : '$' . self::fmt2($effectiveLimit);
            if ($group === 'combo' && $combos !== null && $combos > 1) {
                $perComboInfo = sprintf(' — المطلوب: $%s إجمالي = $%s لكل تركيبة × %d', self::fmt2($amount), self::fmt2($amount / $combos), $combos);
            } else {
                $perComboInfo = ' — المطلوب: $' . self::fmt2($amount);
            }
            self::fail(sprintf(
                'الرقم %s (%s%s) وصل للحد الأقصى %s في فترة %s — المباع حالياً: $%s + السلة: $%s — المتبقي المسموح: $%s%s — الإجمالي سيصبح $%s > $%s',
                implode('', $numbers), $groupLabel, $example, $limitDetail, $pidNorm,
                self::fmt2($existingSum), self::fmt2($cartSum), self::fmt2($remaining),
                $perComboInfo, self::fmt2($total), self::fmt2($effectiveLimit),
            ));
        }
    }

    public function validateCartItem(
        string $gameId,
        array $numbers,
        string $betType,
        float $amount,
        ?string $drawId = null,
    ): bool {
        foreach ($numbers as $d) {
            if (preg_match('/^[0-9]$/', (string) $d) !== 1) {
                self::fail('Digits must be 0-9');
            }
        }
        if ($gameId === 'g_numbers') {
            if (count($numbers) !== 3 && count($numbers) !== 4) {
                self::fail('Numbers requires 3 or 4 digits');
            }
        } elseif ($gameId === 'g_winz') {
            if (count($numbers) !== 4) {
                self::fail('Win 4 requires exactly 4 digits');
            }
        }
        $norm = $this->normBet($betType);
        $isCombo = $this->isComboBet($norm);
        $isStrBox = $this->isStraightBoxBet($norm);
        $isBox = $norm === 'box';
        $allSame = $numbers !== [] && count(array_unique($numbers)) === 1;
        if ($allSame && ($isBox || $isStrBox || $isCombo)) {
            self::fail('الأرقام المتطابقة مثل ' . implode('', $numbers) . ' مسموحة فقط في Straight — غير مسموحة في Box / ST/BOX / Combo');
        }
        if ($isCombo) {
            $combos = $this->comboCount($numbers);
            if ($combos <= 1) {
                self::fail('Combo not valid for repeating digits like ' . implode('', $numbers) . ' — need distinct permutations');
            }
            if ($amount < 0.5 - 1e-9) {
                self::fail('Combo min $0.50');
            }
            if ($amount > $combos * 10 + 1e-9) {
                self::fail(sprintf('Combo max $%s (%d × $10)', self::fmt2($combos * 10), $combos));
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps — e.g. $0.50, $1.00, $1.50');
            }
        } elseif ($isStrBox) {
            if ($amount < 0.5 - 1e-9) {
                self::fail('Straight/Box (All) min $0.50');
            }
            if ($amount > 50.0 + 1e-9) {
                self::fail('Amount $0.50 - $50.0 for Straight/Box (All)');
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps — e.g. $0.50, $1.00, $1.50');
            }
        } elseif ($isBox) {
            if ($amount < 0.5 || $amount > 50.0) {
                self::fail('Amount $0.50 - $50.00 for Box (limit per number per period $50)');
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps');
            }
        } else {
            $maxStraight = count($numbers) === 3 ? 100.0 : 10.0;
            if ($amount < 0.5 || $amount > $maxStraight) {
                self::fail('Amount $0.50 - $' . self::fmt2($maxStraight) . ' for Straight ' . count($numbers) . 'D (limit $' . self::fmt2($maxStraight) . ')');
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps');
            }
        }

        return true;
    }

    // ------------------------------------------------------------- cart
    public function addToCart(
        string $gameId,
        string $gameName,
        ?string $drawId = null,
        ?string $drawName = null,
        ?string $periodId = null,
        ?string $drawType = null,
        array $numbers = [],
        string $betType = 'Straight',
        float $amount = 0.0,
        bool $isQuickPick = false,
    ): array {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        $this->validateCartItem($gameId, $numbers, $betType, $amount, $drawId);

        $resolvedPeriodId = $periodId;
        $resolvedDrawType = $drawType !== null ? $this->normalizeDrawType($drawType) : $drawType;
        $resolvedDrawId = $drawId;
        $resolvedDrawName = $drawName;

        if ($resolvedDrawType === 'MIDDAY' || $resolvedDrawType === 'EVENING') {
            $target = $this->resolveNextSellablePeriod($resolvedDrawType);
            $resolvedPeriodId = (string) $target['id'];
            $resolvedDrawType = $this->normalizeDrawType($target['drawType'] ?? null);
            [$resolvedDrawId, $resolvedDrawName] = $this->drawMetaForPeriod($gameId, $target);
        } elseif ($resolvedPeriodId === null && $drawId !== null) {
            $draw = $this->db->getDrawById($drawId);
            if ($draw !== null) {
                $name = (string) $draw['name'];
                $inferred = (str_contains($name, 'Midday') || str_contains($name, 'Morning'))
                    ? 'MIDDAY' : (str_contains($name, 'Evening') ? 'EVENING' : 'MIDDAY');
                $resolvedDrawType = $inferred;
                $resolvedPeriodId = $this->periodIdForDate($this->wallDateFromDraw($draw), $inferred);
            }
        }
        if ($resolvedPeriodId !== null) {
            $this->validatePeriodOpen($resolvedPeriodId);
            $this->enforcePerPeriodNumberLimit($resolvedPeriodId, $gameId, $numbers, $betType, $amount);
        }
        if ($resolvedDrawId !== null) {
            $draw = $this->db->getDrawById($resolvedDrawId);
            if ($draw !== null) {
                if (($draw['status'] ?? null) === 'closed' && $resolvedPeriodId === null) {
                    self::fail('Draw is closed — sales blocked');
                }
                $drawTime = (int) $draw['drawTime'];
                if (self::nowMs() >= $drawTime - 5 * 60 * 1000 && $resolvedPeriodId === null) {
                    $win = $draw['winningNumbers'] ?? null;
                    if (! self::nonempty($win)) {
                        self::fail('Sales closed — draw cutoff (5 min before)');
                    }
                }
            }
        }
        if ($resolvedPeriodId === null && $resolvedDrawId === null) {
            self::fail('لا توجد فترة سحب مفتوحة الآن — لا يمكن إصدار تذكرة');
        }

        $this->_cart[] = $this->normalizeCartLine([
            'id' => self::uuid(),
            'gameId' => $gameId, 'gameName' => $gameName,
            'drawId' => $resolvedDrawId, 'drawName' => $resolvedDrawName,
            'periodId' => $resolvedPeriodId, 'drawType' => $resolvedDrawType,
            'numbers' => $numbers, 'betType' => $betType,
            'amount' => $amount, 'isQuickPick' => $isQuickPick,
        ]);

        return $this->cart();
    }

    public function addBothToCart(
        string $gameId,
        string $gameName,
        array $numbers = [],
        string $betType = 'Straight',
        float $amountPerDraw = 0.0,
        bool $isQuickPick = false,
        ?string $forDate = null,
    ): array {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        $this->validateCartItem($gameId, $numbers, $betType, $amountPerDraw);

        $midday = null;
        $evening = null;
        try {
            $midday = $this->resolveNextSellablePeriod('MIDDAY');
        } catch (Throwable $e) {
        }
        try {
            $evening = $this->resolveNextSellablePeriod('EVENING');
        } catch (Throwable $e) {
        }
        if ($midday === null && $evening === null) {
            self::fail('لا توجد فترة مفتوحة للبيع الآن');
        }
        if ($midday === null) {
            self::fail('الصباحي مغلق أو انتهى — Both غير متاح. العب المسائي فقط');
        }
        if ($evening === null) {
            self::fail('المسائي مغلق أو انتهى — Both غير متاح. العب الصباحي فقط');
        }
        $targetPeriods = [$midday, $evening];
        usort($targetPeriods, static fn ($a, $b) => ((int) $a['drawTime']) <=> ((int) $b['drawTime']));
        $firstId = (string) $targetPeriods[0]['id'];
        $secondId = (string) $targetPeriods[1]['id'];
        $this->validatePeriodOpen($firstId);
        $this->validatePeriodOpen($secondId);
        $this->enforcePerPeriodNumberLimit($firstId, $gameId, $numbers, $betType, $amountPerDraw);
        $this->enforcePerPeriodNumberLimit($secondId, $gameId, $numbers, $betType, $amountPerDraw);

        $firstDrawId = $secondDrawId = $firstDrawName = $secondDrawName = null;
        foreach ($this->db->getDrawsByGame($gameId) as $d) {
            $dtStr = $this->wallDateFromDraw($d)->format('Y-m-d');
            foreach ($targetPeriods as $index => $tp) {
                if ($dtStr !== (string) $tp['drawDate']
                    || $this->drawTypeFromDraw($d) !== $this->normalizeDrawType($tp['drawType'] ?? null)) {
                    continue;
                }
                if ($index === 0) {
                    $firstDrawId = $d['id'];
                    $firstDrawName = $d['name'];
                } else {
                    $secondDrawId = $d['id'];
                    $secondDrawName = $d['name'];
                }
            }
        }

        foreach ([0, 1] as $index) {
            $period = $targetPeriods[$index];
            $this->_cart[] = $this->normalizeCartLine([
                'id' => self::uuid(),
                'gameId' => $gameId, 'gameName' => $gameName,
                'drawId' => $index === 0 ? $firstDrawId : $secondDrawId,
                'drawName' => ($index === 0 ? $firstDrawName : $secondDrawName)
                    ?? ($period['drawType'] . ' ' . $period['drawDate']),
                'periodId' => $index === 0 ? $firstId : $secondId,
                'drawType' => $period['drawType'],
                'numbers' => $numbers, 'betType' => $betType,
                'amount' => $amountPerDraw, 'isQuickPick' => $isQuickPick,
            ]);
        }

        return $this->cart();
    }

    public function removeFromCart(string $cartId): array
    {
        $this->_cart = array_values(array_filter($this->_cart, static fn ($e) => $e['id'] !== $cartId));

        return $this->cart();
    }

    public function clearCart(): array
    {
        $this->_cart = [];

        return $this->cart();
    }

    public function commitSale(): array
    {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        if ($this->_cart === []) {
            self::fail('Cart is empty — add numbers first');
        }

        foreach ($this->_cart as $item) {
            if ($item['periodId'] === null && $item['drawId'] === null) {
                self::fail('تذكرة بدون سحب — لا يمكن بيعها');
            }
            if ($item['periodId'] !== null) {
                $this->validatePeriodOpen((string) $item['periodId']);
            }
            if ($item['drawId'] !== null && $item['periodId'] === null) {
                $draw = $this->db->getDrawById((string) $item['drawId']);
                if ($draw !== null) {
                    if (($draw['status'] ?? null) === 'closed') {
                        self::fail('Draw ' . $item['drawName'] . ' is closed');
                    }
                    $drawTime = (int) $draw['drawTime'];
                    if (self::nowMs() >= $drawTime - 5 * 60 * 1000) {
                        $win = $draw['winningNumbers'] ?? null;
                        if (! self::nonempty($win)) {
                            self::fail('Sales closed — cutoff for ' . $item['drawName']);
                        }
                    }
                }
            }
        }

        $cartAgg = [];
        $aggMeta = [];
        foreach ($this->_cart as $item) {
            if ($item['periodId'] === null) {
                continue;
            }
            $group = $this->betGroup($this->normBet($item['betType']));
            $canon = $this->canonicalKey($item['numbers'], $group);
            $pidNorm = strtoupper(trim(str_replace('/', '-', $item['periodId'])));
            $key = $pidNorm . '|' . $item['gameId'] . '|' . $group . '|' . $canon . '|' . count($item['numbers']);
            $cartAgg[$key] = ($cartAgg[$key] ?? 0) + $item['amount'];
            $aggMeta[$key] = [
                'periodId' => $pidNorm, 'gameId' => $item['gameId'], 'group' => $group,
                'canon' => $canon, 'n' => count($item['numbers']),
                'sampleNumbers' => $item['numbers'], 'betType' => $item['betType'],
            ];
        }
        foreach ($cartAgg as $key => $cartTotalForKey) {
            $meta = $aggMeta[$key];
            $periodId = $meta['periodId'];
            $gameId = $meta['gameId'];
            $group = $meta['group'];
            $canon = $meta['canon'];
            $n = $meta['n'];
            $sampleNumbers = $meta['sampleNumbers'];
            $limit = $this->getLimitFor($group, $n);
            if ($limit <= 0) {
                continue;
            }
            $existingSum = 0.0;
            try {
                foreach ($this->db->getTicketLinesByPeriod($periodId) as $l) {
                    if (($l['periodStatus'] ?? null) === 'cancelled' || ($l['gameId'] ?? null) !== $gameId) {
                        continue;
                    }
                    $lGroup = $this->betGroup($this->normBet((string) ($l['betType'] ?? 'Straight')));
                    $lNums = $this->digitsFromString((string) ($l['numbers'] ?? ''));
                    if ($lGroup !== $group || count($lNums) !== $n
                        || $this->canonicalKey($lNums, $lGroup) !== $canon) {
                        continue;
                    }
                    $existingSum += (float) $l['amount'];
                }
            } catch (Throwable $e) {
            }
            $effectiveLimit = $limit;
            $combos = null;
            if ($group === 'combo') {
                $combos = $this->comboCount($sampleNumbers);
                if ($combos > 1) {
                    $effectiveLimit = $limit * $combos;
                }
            }
            $total = $existingSum + $cartTotalForKey;
            if ($total > $effectiveLimit + 1e-9) {
                $remaining = max(0.0, min($effectiveLimit, $effectiveLimit - $existingSum));
                $isBoxGroup = in_array($group, ['box', 'combo', 'strbox'], true);
                $example = $isBoxGroup ? ' (حتى لو تشقلبت الأرقام مثل 1123 = 2113)' : '';
                $digitLabel = $n === 3 ? '3' : '4';
                $groupLabel = match ($group) {
                    'straight' => 'Straight ' . $digitLabel,
                    'box' => 'Box ' . $digitLabel,
                    'combo' => 'Combo ' . $digitLabel,
                    default => 'ST/BOX ' . $digitLabel,
                };
                $limitDetail = ($group === 'combo' && $combos !== null && $combos > 1)
                    ? sprintf('$%s × %d = $%s إجمالي', self::fmt2($limit), $combos, self::fmt2($effectiveLimit))
                    : '$' . self::fmt2($effectiveLimit);
                self::fail(sprintf(
                    'الرقم %s (%s%s) سيتجاوز الحد في فترة %s — المباع سابقاً: $%s + سلة الشراء لهذا الرقم: $%s = $%s > حد %s — المتبقي المسموح: $%s',
                    implode('', $sampleNumbers), $groupLabel, $example, $periodId,
                    self::fmt2($existingSum), self::fmt2($cartTotalForKey), self::fmt2($total),
                    $limitDetail, self::fmt2($remaining),
                ));
            }
        }

        $id = $this->db->nextTicketId();
        $serial = $this->db->nextSerialNumber();
        $barcode = $id;
        $now = self::nowMs();
        $total = $this->cartTotal();

        $distinctGames = array_unique(array_column($this->_cart, 'gameId'));
        $distinctDraws = array_unique(array_map(static fn ($e) => $e['drawId'], $this->_cart));
        $headerGameId = count($distinctGames) === 1 ? $this->_cart[0]['gameId'] : 'multi';
        $headerGameName = count($distinctGames) === 1 ? $this->_cart[0]['gameName'] : 'Mixed';
        $headerDrawId = $this->_cart[0]['drawId'];
        $headerDrawName = count($distinctDraws) === 1
            ? $this->_cart[0]['drawName']
            : ($this->_cart[0]['drawName'] !== null
                ? $this->_cart[0]['drawName'] . ' +' . (count($this->_cart) - 1)
                : null);
        $headerBetType = count($this->_cart) === 1 ? $this->_cart[0]['betType'] : 'Multi';
        $headerNumbers = implode(' | ', array_map(
            static fn ($e) => implode('', $e['numbers']) . '(' . $e['betType'] . ')',
            $this->_cart,
        ));
        $headerNumbersCol = strlen($headerNumbers) > 400
            ? implode(',', $this->_cart[0]['numbers']) . ' +' . (count($this->_cart) - 1) . ' more'
            : implode(';', array_map(static fn ($e) => implode(',', $e['numbers']), $this->_cart));

        $ticket = [
            'id' => $id, 'serialNumber' => $serial,
            'gameId' => $headerGameId, 'gameName' => $headerGameName,
            'drawId' => $headerDrawId, 'drawName' => $headerDrawName,
            'numbers' => $headerNumbersCol, 'betType' => $headerBetType,
            'amount' => $total, 'total' => $total,
            'operatorId' => $this->_currentUser['id'],
            'operatorName' => $this->_currentUser['username'],
            'createdAt' => $now, 'status' => 'active', 'barcode' => $barcode,
            'prizeAmount' => null, 'paidAt' => null, 'paidBy' => null,
            'isQuickPick' => count(array_filter($this->_cart, static fn ($e) => $e['isQuickPick'])) > 0 ? 1 : 0,
            'customerId' => $this->_currentCustomer['id'],
            'customerName' => $this->_currentCustomer['name'],
        ];

        $lines = [];
        foreach ($this->_cart as $e) {
            $lines[] = [
                'id' => self::uuid(), 'ticketId' => $id,
                'gameId' => $e['gameId'], 'gameName' => $e['gameName'],
                'drawId' => $e['drawId'], 'drawName' => $e['drawName'],
                'periodId' => $e['periodId'], 'periodStatus' => 'active',
                'winningStatus' => null, 'prizeAmount' => null,
                'numbers' => implode(',', $e['numbers']), 'betType' => $e['betType'],
                'amount' => $e['amount'], 'isQuickPick' => $e['isQuickPick'] ? 1 : 0,
            ];
        }

        DB::transaction(function () use ($ticket, $lines, $id, $total, $now, $headerNumbers): void {
            $this->db->insertTicketWithLines($ticket, $lines);
            $this->db->insertCashLedger([
                'id' => self::uuid(), 'type' => 'sale', 'amount' => $total, 'direction' => 'in',
                'description' => 'Sale ' . $id . ' — ' . count($lines) . ' plays — ' . $headerNumbers,
                'operatorId' => $this->_currentUser['id'], 'createdAt' => $now, 'ticketId' => $id,
            ]);
            $this->db->insertBalanceMovement([
                'id' => self::uuid(), 'type' => 'sale', 'amount' => $total, 'direction' => 'credit',
                'description' => 'Sale ' . $id,
                'operatorId' => $this->_currentUser['id'], 'createdAt' => $now, 'ticketId' => $id,
            ]);
        });

        $this->_cart = [];
        $result = $ticket;
        $result['lines'] = $lines;
        $result['linesCount'] = count($lines);

        return $result;
    }

    /** Inline validation used by the legacy createTicket() path. */
    private function validateLegacyTicketItem(
        string $gameId,
        array $numbers,
        string $betType,
        float $amount,
    ): void {
        foreach ($numbers as $d) {
            if (preg_match('/^[0-9]$/', (string) $d) !== 1) {
                self::fail('Digits must be 0-9');
            }
        }
        if ($gameId === 'g_numbers' && count($numbers) !== 3 && count($numbers) !== 4) {
            self::fail('Numbers requires 3 or 4 digits');
        } elseif ($gameId === 'g_winz' && count($numbers) !== 4) {
            self::fail('Win 4 requires exactly 4 digits');
        }
        $btNorm = $this->normBet($betType);
        $allSame = $numbers !== [] && count(array_unique($numbers)) === 1;
        $isBoxNorm = $btNorm === 'box';
        if ($allSame && ($isBoxNorm || $this->isStraightBoxBet($btNorm) || $this->isComboBet($btNorm))) {
            self::fail('الأرقام المتطابقة مثل ' . implode('', $numbers) . ' مسموحة فقط في Straight — غير مسموحة في Box / ST/BOX / Combo');
        }
        if ($this->isComboBet($btNorm)) {
            $combos = $this->comboCount($numbers);
            if ($combos <= 1) {
                self::fail('Combo not valid for repeating digits like ' . implode('', $numbers));
            }
            if ($amount < 0.5 - 1e-9) {
                self::fail('Combo min $0.50');
            }
            if ($amount > $combos * 10 + 1e-9) {
                self::fail(sprintf('Combo max $%s', self::fmt2($combos * 10)));
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps — e.g. $0.50, $1.00, $1.50');
            }
        } elseif ($this->isStraightBoxBet($btNorm)) {
            if ($amount < 0.5 - 1e-9) {
                self::fail('Straight/Box (All) min $0.50');
            }
            $maxSb = count($numbers) === 3 ? 50 : 25;
            if ($amount > $maxSb + 1e-9) {
                self::fail('Amount $0.50 - $' . self::fmt2($maxSb) . ' for Straight/Box');
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps');
            }
        } elseif ($isBoxNorm) {
            if ($amount < 0.5 || $amount > 50) {
                self::fail('Amount $0.50 - $50.00 for Box');
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps');
            }
        } else {
            $maxStraight = count($numbers) === 3 ? 100.0 : 10.0;
            if ($amount < 0.5 || $amount > $maxStraight) {
                self::fail('Amount $0.50 - $' . self::fmt2($maxStraight) . ' for Straight');
            }
            if ((int) round($amount * 100) % 50 !== 0) {
                self::fail('Amount must be $0.50 steps');
            }
        }
    }

    public function createTicket(
        string $gameId,
        string $gameName,
        ?string $drawId = null,
        ?string $drawName = null,
        array $numbers = [],
        string $betType = 'Straight',
        float $amount = 0.0,
        bool $isQuickPick = false,
    ): array {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        if ($drawId !== null) {
            $draw = $this->db->getDrawById($drawId);
            if ($draw !== null) {
                if (($draw['status'] ?? null) === 'closed') {
                    self::fail('Draw is closed — sales blocked');
                }
                $drawTime = (int) $draw['drawTime'];
                $now = self::nowMs();
                if ($now > $drawTime - 5 * 60 * 1000 && $now < $drawTime + 60 * 60 * 1000) {
                    if (! self::nonempty($draw['winningNumbers'] ?? null) && $now >= $drawTime - 5 * 60 * 1000) {
                        self::fail('Sales closed — draw cutoff (5 min before)');
                    }
                }
                $this->validateLegacyTicketItem($gameId, $numbers, $betType, $amount);
            }
        }
        $id = $this->db->nextTicketId();
        $serial = $this->db->nextSerialNumber();
        $total = $amount;
        $barcode = $id;
        $now = self::nowMs();
        $ticket = [
            'id' => $id, 'serialNumber' => $serial,
            'gameId' => $gameId, 'gameName' => $gameName,
            'drawId' => $drawId, 'drawName' => $drawName,
            'numbers' => implode(',', $numbers), 'betType' => $betType,
            'amount' => $amount, 'total' => $total,
            'operatorId' => $this->_currentUser['id'],
            'operatorName' => $this->_currentUser['username'],
            'createdAt' => $now, 'status' => 'active', 'barcode' => $barcode,
            'prizeAmount' => null, 'paidAt' => null, 'paidBy' => null,
            'isQuickPick' => $isQuickPick ? 1 : 0,
            'customerId' => $this->_currentCustomer['id'],
            'customerName' => $this->_currentCustomer['name'],
        ];
        $this->db->insertTicket($ticket);
        $this->db->insertTicketLine([
            'id' => self::uuid(), 'ticketId' => $id,
            'gameId' => $gameId, 'gameName' => $gameName,
            'drawId' => $drawId, 'drawName' => $drawName,
            'numbers' => implode(',', $numbers), 'betType' => $betType,
            'amount' => $amount, 'isQuickPick' => $isQuickPick ? 1 : 0,
        ]);
        $this->db->insertCashLedger([
            'id' => self::uuid(), 'type' => 'sale', 'amount' => $total, 'direction' => 'in',
            'description' => $gameName . ' - ' . $betType . ' - ' . implode(',', $numbers),
            'operatorId' => $this->_currentUser['id'], 'createdAt' => $now, 'ticketId' => $id,
        ]);
        $this->db->insertBalanceMovement([
            'id' => self::uuid(), 'type' => 'sale', 'amount' => $total, 'direction' => 'credit',
            'description' => 'Sale ' . $id,
            'operatorId' => $this->_currentUser['id'], 'createdAt' => $now, 'ticketId' => $id,
        ]);

        return $ticket;
    }

    // ------------------------------------------------------------- replay
    public function replayTicketToCart(string $ticketId): array
    {
        return $this->replayTicketToCartWithChoice($ticketId, 'AUTO');
    }

    public function replayTicketToCartWithChoice(string $ticketId, string $drawChoice): array
    {
        $ticketId = trim($ticketId);
        $drawChoice = strtoupper(trim($drawChoice));
        $lines = $this->db->getTicketLines($ticketId);
        if ($lines === []) {
            $t = $this->db->getTicketById($ticketId) ?? $this->db->getTicketByBarcode($ticketId);
            if ($t === null) {
                self::fail('Ticket not found: ' . $ticketId);
            }
            $source = [$t];
        } else {
            $source = $lines;
        }

        $skipped = [];
        $trimmed = [];

        if ($drawChoice === 'BOTH') {
            $midday = null;
            $evening = null;
            try {
                $midday = $this->resolveNextSellablePeriod('MIDDAY');
            } catch (Throwable $e) {
            }
            try {
                $evening = $this->resolveNextSellablePeriod('EVENING');
            } catch (Throwable $e) {
            }
            if ($midday === null && $evening === null) {
                self::fail('لا توجد فترة مفتوحة للبيع الآن');
            }
            if ($midday === null) {
                self::fail('الصباحي مغلق أو انتهى — Both غير متاح. العب المسائي فقط');
            }
            if ($evening === null) {
                self::fail('المسائي مغلق أو انتهى — Both غير متاح. العب الصباحي فقط');
            }
            $targets = [$midday, $evening];
            usort($targets, static fn ($a, $b) => ((int) $a['drawTime']) <=> ((int) $b['drawTime']));
            $added = 0;
            foreach ($source as $l) {
                foreach ($targets as $tp) {
                    if ($this->tryAddOne($tp, (string) $l['gameId'], (string) $l['gameName'],
                        $this->digitsFromString((string) $l['numbers']), (string) $l['betType'],
                        (float) $l['amount'], (int) ($l['isQuickPick'] ?? 0) === 1, $skipped, $trimmed)) {
                        $added++;
                    }
                }
            }
            if ($added === 0) {
                self::fail('كل الأرقام في الفاتورة وصلت للحد الأقصى — لا شيء يمكن نسخه. المحذوف: ' . implode(' | ', $skipped));
            }

            return $this->replayResult($added, $skipped, $trimmed);
        }

        if ($drawChoice === 'MIDDAY' || $drawChoice === 'EVENING' || $drawChoice === 'MORNING') {
            $target = $this->resolveNextSellablePeriod($drawChoice);
            $added = 0;
            foreach ($this->dedupeLinesForSinglePeriod($source) as $l) {
                if ($this->tryAddOne($target, (string) $l['gameId'], (string) $l['gameName'],
                    $this->digitsFromString((string) $l['numbers']), (string) $l['betType'],
                    (float) $l['amount'], (int) ($l['isQuickPick'] ?? 0) === 1, $skipped, $trimmed)) {
                    $added++;
                }
            }
            if ($added === 0) {
                self::fail('كل الأرقام في الفاتورة وصلت للحد الأقصى في ' . $target['id'] . ' — لا شيء يمكن نسخه. المحذوف: ' . implode(' | ', $skipped));
            }

            return $this->replayResult($added, $skipped, $trimmed);
        }

        // AUTO ("The same"): keep each line's ORIGINAL period type, only move
        // it to the next sellable period of that same type. This preserves a
        // mixed ticket exactly (some numbers Midday, some Evening, some both)
        // without forcing every number into one draw or duplicating lines.
        $added = 0;
        foreach ($source as $l) {
            $numbers = $this->digitsFromString((string) $l['numbers']);
            $inferred = $this->normalizeDrawType($l['drawType'] ?? null);
            if ($inferred !== 'MIDDAY' && $inferred !== 'EVENING') {
                $inferred = $this->drawTypeFromPeriodId($l['periodId'] ?? null);
            }
            if ($inferred !== 'MIDDAY' && $inferred !== 'EVENING') {
                $name = (string) ($l['drawName'] ?? '');
                if (str_contains($name, 'Evening') || str_contains($name, 'مسائ')) {
                    $inferred = 'EVENING';
                } elseif (str_contains($name, 'Midday') || str_contains($name, 'Morning') || str_contains($name, 'صباح')) {
                    $inferred = 'MIDDAY';
                }
            }
            if ($inferred !== 'MIDDAY' && $inferred !== 'EVENING') {
                $inferred = 'MIDDAY';
            }
            $target = $this->resolveNextSellablePeriod($inferred);
            if ($this->tryAddOne($target, (string) $l['gameId'], (string) $l['gameName'], $numbers,
                (string) $l['betType'], (float) $l['amount'], (int) ($l['isQuickPick'] ?? 0) === 1, $skipped, $trimmed)) {
                $added++;
            }
        }
        if ($added === 0) {
            self::fail('كل الأرقام في الفاتورة وصلت للحد الأقصى — لا شيء يمكن نسخه. المحذوف: ' . implode(' | ', $skipped));
        }

        return $this->replayResult($added, $skipped, $trimmed);
    }

    private function replayResult(int $added, array $skipped, array $trimmed): array
    {
        return [
            'added' => $added,
            'skipped' => $skipped,
            'trimmed' => $trimmed,
            'skippedCount' => count($skipped),
            'trimmedCount' => count($trimmed),
            'cart' => $this->cart(),
        ];
    }

    private function tryAddOne(
        array $period,
        string $gameId,
        string $gameName,
        array $numbers,
        string $betType,
        float $requested,
        bool $isQuickPick,
        array &$skipped,
        array &$trimmed,
    ): bool {
        $periodId = (string) $period['id'];
        $periodDrawType = $this->normalizeDrawType($period['drawType'] ?? null);
        $this->validatePeriodOpen($periodId);
        try {
            $this->validateCartItem($gameId, $numbers, $betType, $requested);
        } catch (Throwable $e) {
            $skipped[] = implode('', $numbers) . ' (' . $betType . ') — غير صالح: ' . $e->getMessage();
            return false;
        }
        $info = $this->remainingFor($periodId, $gameId, $numbers, $betType);
        $remaining = $info['remaining'];
        $vMax = $this->maxInputFor($numbers, $betType);
        if ($remaining < 0.5 - 1e-9) {
            $skipped[] = implode('', $numbers) . ' (' . $betType . ') — Sold Out في ' . $periodId
                . ' (الحد $' . self::fmt2($info['effectiveLimit']) . ' ممتلئ)';
            return false;
        }
        if ($requested <= $remaining + 1e-9 && $requested <= $vMax + 1e-9) {
            $amountToUse = $requested;
        } else {
            $allowed = min($remaining, $vMax);
            $floored = $this->floorToStep($allowed, 0.5);
            if ($floored < 0.5 - 1e-9) {
                $skipped[] = implode('', $numbers) . ' (' . $betType . ') — المتبقي $'
                    . self::fmt2($remaining) . ' أقل من الحد الأدنى $0.50 في ' . $periodId;
                return false;
            }
            try {
                $this->validateCartItem($gameId, $numbers, $betType, $floored);
            } catch (Throwable $e) {
                $skipped[] = implode('', $numbers) . ' (' . $betType . ') — لا يمكن تقليله: ' . $e->getMessage();
                return false;
            }
            $check = $this->remainingFor($periodId, $gameId, $numbers, $betType);
            if ($floored > $check['remaining'] + 1e-9) {
                $floored = $this->floorToStep($check['remaining'], 0.5);
                if ($floored < 0.5 - 1e-9) {
                    $skipped[] = implode('', $numbers) . ' (' . $betType . ') — Sold Out';
                    return false;
                }
            }
            $amountToUse = $floored;
            if (abs($amountToUse - $requested) > 1e-9) {
                $trimmed[] = implode('', $numbers) . ' (' . $betType . ') في ' . $periodId . ': $'
                    . self::fmt2($requested) . ' → $' . self::fmt2($amountToUse)
                    . ' (المتبقي $' . self::fmt2($remaining) . ')';
            }
        }
        [$drawId, $drawName] = $this->drawMetaForPeriod($gameId, $period);
        $this->_cart[] = $this->normalizeCartLine([
            'id' => self::uuid(), 'gameId' => $gameId, 'gameName' => $gameName,
            'drawId' => $drawId, 'drawName' => $drawName,
            'periodId' => $periodId, 'drawType' => $periodDrawType,
            'numbers' => $numbers, 'betType' => $betType,
            'amount' => $amountToUse, 'isQuickPick' => $isQuickPick,
        ]);

        return true;
    }

    public function remapCartToDrawType(string $drawType): array
    {
        $normalized = $this->normalizeDrawType($drawType);
        if ($normalized !== 'MIDDAY' && $normalized !== 'EVENING') {
            return $this->cart();
        }
        if ($this->_cart === []) {
            return $this->cart();
        }
        $target = $this->resolveNextSellablePeriod($normalized);
        $periodId = (string) $target['id'];
        $this->validatePeriodOpen($periodId);
        $old = $this->_cart;
        $this->_cart = [];
        try {
            foreach ($old as $e) {
                $this->enforcePerPeriodNumberLimit($periodId, $e['gameId'], $e['numbers'], $e['betType'], $e['amount']);
                [$drawId, $drawName] = $this->drawMetaForPeriod($e['gameId'], $target);
                $this->_cart[] = $this->normalizeCartLine([
                    'id' => $e['id'], 'gameId' => $e['gameId'], 'gameName' => $e['gameName'],
                    'drawId' => $drawId, 'drawName' => $drawName,
                    'periodId' => $periodId, 'drawType' => $normalized,
                    'numbers' => $e['numbers'], 'betType' => $e['betType'],
                    'amount' => $e['amount'], 'isQuickPick' => $e['isQuickPick'],
                ]);
            }
        } catch (Throwable $e) {
            $this->_cart = $old;
            throw $e;
        }

        return $this->cart();
    }

    // --------------------------------------------------------------- cash
    public function loadOpeningCash(): float
    {
        if ($this->_currentUser === null) {
            return $this->_openingCash;
        }
        $sess = $this->db->getOpenCashSession((string) $this->_currentUser['id']);
        $this->_openingCash = $sess !== null ? (float) $sess['openingAmount'] : 0.0;

        return $this->_openingCash;
    }

    public function openCashSession(float $amount): array
    {
        if ($this->_currentUser === null) {
            self::fail('Not logged in');
        }
        if ($this->db->getOpenCashSession((string) $this->_currentUser['id']) !== null) {
            return $this->snapshot();
        }
        $id = self::uuid();
        $now = self::nowMs();
        $this->db->insertCashSession([
            'id' => $id, 'operatorId' => $this->_currentUser['id'],
            'openingAmount' => $amount, 'closingAmount' => null,
            'openedAt' => $now, 'closedAt' => null, 'status' => 'open',
        ]);
        $this->db->insertCashLedger([
            'id' => self::uuid(), 'type' => 'opening', 'amount' => $amount, 'direction' => 'in',
            'description' => 'Opening cash', 'operatorId' => $this->_currentUser['id'],
            'createdAt' => $now, 'ticketId' => null,
        ]);
        $this->_openingCash = $amount;

        return $this->snapshot();
    }

    public function closeCashSession(): float
    {
        if ($this->_currentUser === null) {
            return 0.0;
        }
        $sess = $this->db->getOpenCashSession((string) $this->_currentUser['id']);
        if ($sess === null) {
            return 0.0;
        }
        $cashNow = $this->computeCashBalance();
        $this->db->closeCashSession((string) $sess['id'], $cashNow, self::nowMs());
        $this->_openingCash = 0.0;

        return $cashNow;
    }

    public function computeCashBalance(?string $operatorId = null): float
    {
        $op = $operatorId ?? ($this->_currentUser['id'] ?? null);
        $session = $op !== null ? $this->db->getOpenCashSession($op) : null;
        $lastClosed = ($session === null && $op !== null) ? $this->db->getLastClosedCashSession($op) : null;
        $base = (float) ($session['openingAmount'] ?? $lastClosed['closingAmount'] ?? 0);
        $since = (int) ($session['openedAt'] ?? $lastClosed['closedAt'] ?? 0);
        $inSum = $this->db->sumCashSince('in', $since, $op);
        $outSum = $this->db->sumCashSince('out', $since, $op);

        return $base + $inSum - $outSum;
    }

    public function computeGeneralBalance(?string $operatorId = null): float
    {
        $op = $operatorId ?? ($this->_currentUser['id'] ?? null);
        $rows = $op !== null ? $this->db->getBalanceMovementsByOperator($op) : $this->db->getBalanceMovements();
        $bal = 0.0;
        foreach ($rows as $r) {
            $amt = (float) $r['amount'];
            $bal += in_array($r['direction'], ['credit', 'in'], true) ? $amt : -$amt;
        }
        if ($op !== null && $rows === []) {
            $gbal = 0.0;
            foreach ($this->db->getBalanceMovements() as $r) {
                if (($r['operatorId'] ?? null) !== $op) {
                    continue;
                }
                $amt = (float) $r['amount'];
                $gbal += in_array($r['direction'], ['credit', 'in'], true) ? $amt : -$amt;
            }

            return $gbal;
        }

        return $bal;
    }

    public function computeCashBalanceGlobal(): float
    {
        return $this->db->sumCash('in') - $this->db->sumCash('out');
    }

    public function computeGeneralBalanceGlobal(): float
    {
        $bal = 0.0;
        foreach ($this->db->getBalanceMovements() as $r) {
            $amt = (float) $r['amount'];
            $bal += in_array($r['direction'], ['credit', 'in'], true) ? $amt : -$amt;
        }

        return $bal;
    }

    // -------------------------------------------------------------- payouts
    public function payWinningTicket(string $ticketId, float $prize): bool
    {
        if ($this->_currentUser === null) {
            return false;
        }
        $this->ensureNotExpired();
        $t = $this->db->getTicketById($ticketId);
        if ($t === null || in_array($t['status'] ?? null, ['paid', 'cancelled'], true)) {
            return false;
        }
        $amount = (float) ($t['prizeAmount'] ?? $prize);
        if ($amount <= 0) {
            return false;
        }
        $now = self::nowMs();
        if (! $this->db->insertPayoutLedger($ticketId, $amount, (string) $this->_currentUser['id'], $now)) {
            return false;
        }
        DB::transaction(function () use ($ticketId, $amount, $now): void {
            $this->db->updateTicketStatus($ticketId, 'paid', $amount, $now, (string) $this->_currentUser['username']);
            $this->db->insertBalanceMovement([
                'id' => self::uuid(), 'type' => 'payout', 'amount' => $amount, 'direction' => 'debit',
                'description' => 'Payout ' . $ticketId, 'operatorId' => $this->_currentUser['id'],
                'createdAt' => $now, 'ticketId' => $ticketId,
            ]);
        });
        try {
            $this->db->insertAuditLog([
                'id' => 'audit_' . (int) round(microtime(true) * 1000000),
                'user' => $this->_currentUser['username'], 'action' => 'PRIZE_PAID',
                'periodId' => null, 'oldValue' => (string) ($t['status'] ?? ''),
                'newValue' => 'paid ' . $amount, 'timestamp' => $now,
                'reason' => 'Prize payout for ticket ' . $ticketId,
            ]);
        } catch (Throwable $e) {
        }

        return true;
    }

    public function reversePayout(string $ticketId, string $reason): bool
    {
        $amount = $this->db->getPayoutAmountForTicket($ticketId);
        if ($amount <= 0) {
            return false;
        }
        $now = self::nowMs();
        $t = $this->db->getTicketById($ticketId);
        $this->db->releasePayoutLockForTicket($ticketId, $now);
        $this->db->insertCashLedger([
            'id' => 'reversal_' . $ticketId . '_' . $now, 'type' => 'payout_reversal',
            'amount' => $amount, 'direction' => 'in',
            'description' => 'Reversal of payout for ' . $ticketId . ' — ' . $reason,
            'operatorId' => $this->_currentUser['id'] ?? 'system',
            'createdAt' => $now, 'ticketId' => $ticketId,
        ]);
        $this->db->insertBalanceMovement([
            'id' => 'reversal_' . $ticketId . '_' . $now . '_b', 'type' => 'payout_reversal',
            'amount' => $amount, 'direction' => 'credit',
            'description' => 'Reversal of payout for ' . $ticketId . ' — ' . $reason,
            'operatorId' => $this->_currentUser['id'] ?? 'system',
            'createdAt' => $now, 'ticketId' => $ticketId,
        ]);
        if ($t !== null) {
            $this->db->updateTicketStatus($ticketId, 'active', 0);
        }
        try {
            $this->db->insertAuditLog([
                'id' => 'audit_' . (int) round(microtime(true) * 1000000),
                'user' => $this->_currentUser['username'] ?? 'system',
                'action' => 'PAYOUT_REVERSED', 'periodId' => null,
                'oldValue' => (string) ($t['status'] ?? ''), 'newValue' => 'active',
                'timestamp' => $now, 'reason' => 'Reversal for ticket ' . $ticketId . ': ' . $reason,
            ]);
        } catch (Throwable $e) {
        }

        return true;
    }

    public function cancelWholeTicket(string $ticketId): float
    {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        $ticket = $this->db->getTicketById($ticketId);
        if ($ticket === null) {
            self::fail('Ticket not found');
        }
        $status = $ticket['status'] ?? null;
        if ($status === 'paid') {
            self::fail('Already paid');
        }
        if ($status === 'cancelled') {
            self::fail('Already cancelled');
        }
        if ($status === 'winning') {
            self::fail('Winning ticket cannot be cancelled');
        }
        $activeLines = [];
        foreach ($this->db->getTicketLines($ticketId) as $l) {
            if (($l['periodStatus'] ?? null) !== 'cancelled' && ($l['winningStatus'] ?? null) !== 'void') {
                $activeLines[] = $l;
            }
        }
        if ($activeLines === []) {
            self::fail('No active plays to cancel');
        }
        foreach ($activeLines as $l) {
            $periodId = $l['periodId'] ?? null;
            if ($periodId !== null) {
                $p = $this->db->getPeriodById((string) $periodId);
                if ($p !== null && self::nonempty($p['result'] ?? null)) {
                    self::fail('Cannot cancel — result already published for ' . $periodId);
                }
                if ($p !== null && self::nowMs() >= (int) $p['salesCloseTime']) {
                    self::fail('Sales closed for ' . $periodId);
                }
            }
            $drawId = $l['drawId'] ?? null;
            if ($drawId !== null && $periodId === null) {
                $d = $this->db->getDrawById((string) $drawId);
                if ($d !== null && self::nonempty($d['winningNumbers'] ?? null)) {
                    self::fail('Winning number already set');
                }
            }
        }
        $totalRefund = 0.0;
        foreach ($activeLines as $l) {
            $totalRefund += (float) $l['amount'];
            $this->db->voidTicketLine((string) $l['id']);
        }
        $this->db->recalcTicketTotals($ticketId);
        $nowMs = self::nowMs();
        $this->db->insertCashLedger([
            'id' => self::uuid(), 'type' => 'cancel', 'amount' => $totalRefund, 'direction' => 'out',
            'description' => 'Cancel ticket ' . $ticketId . ' — ' . count($activeLines) . ' plays voided',
            'operatorId' => $this->_currentUser['id'], 'createdAt' => $nowMs, 'ticketId' => $ticketId,
        ]);
        $this->db->insertBalanceMovement([
            'id' => self::uuid(), 'type' => 'cancel', 'amount' => $totalRefund, 'direction' => 'debit',
            'description' => 'Cancel ticket ' . $ticketId,
            'operatorId' => $this->_currentUser['id'], 'createdAt' => $nowMs, 'ticketId' => $ticketId,
        ]);

        return $totalRefund;
    }

    public function cancelTicketLine(string $ticketId, string $lineId): float
    {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        $ticket = $this->db->getTicketById($ticketId);
        if ($ticket === null) {
            self::fail('Ticket not found');
        }
        $status = $ticket['status'] ?? null;
        if ($status === 'paid') {
            self::fail('Ticket already paid — cannot void');
        }
        if ($status === 'cancelled') {
            self::fail('Ticket already cancelled');
        }
        if ($status === 'winning') {
            self::fail('Winning ticket — cannot void lines after result');
        }
        $line = $this->db->getTicketLineById($lineId);
        if ($line === null) {
            self::fail('Play not found');
        }
        if (($line['ticketId'] ?? null) !== $ticketId) {
            self::fail('Line does not belong to ticket');
        }
        if (($line['periodStatus'] ?? null) === 'cancelled' || ($line['winningStatus'] ?? null) === 'void') {
            self::fail('Play already cancelled');
        }
        $periodId = $line['periodId'] ?? null;
        $drawId = $line['drawId'] ?? null;
        if ($periodId !== null) {
            $p = $this->db->getPeriodById((string) $periodId);
            if ($p !== null) {
                if (self::nonempty($p['result'] ?? null)) {
                    self::fail('Cannot void — result already published for ' . $periodId);
                }
                $now = self::nowMs();
                if ($now >= (int) $p['salesCloseTime']) {
                    self::fail('Sales closed for ' . $periodId . ' — cannot void after cutoff');
                }
                if (in_array($p['status'] ?? null, ['RESULT_PUBLISHED', 'COMPLETED'], true)) {
                    self::fail('Draw ' . $periodId . ' already closed');
                }
            }
        }
        if ($drawId !== null) {
            $d = $this->db->getDrawById((string) $drawId);
            if ($d !== null) {
                if (self::nonempty($d['winningNumbers'] ?? null)) {
                    self::fail('Cannot void — winning number already set');
                }
                if (self::nowMs() >= (int) $d['drawTime'] - 5 * 60 * 1000 && $periodId === null) {
                    self::fail('Cutoff — cannot void');
                }
            }
        }
        $refund = (float) $line['amount'];
        $this->db->voidTicketLine($lineId);
        $this->db->recalcTicketTotals($ticketId);
        $nowMs = self::nowMs();
        $this->db->insertCashLedger([
            'id' => self::uuid(), 'type' => 'cancel', 'amount' => $refund, 'direction' => 'out',
            'description' => 'Void play ' . $line['numbers'] . ' ' . $line['betType'] . ' — ticket ' . $ticketId,
            'operatorId' => $this->_currentUser['id'], 'createdAt' => $nowMs, 'ticketId' => $ticketId,
        ]);
        $this->db->insertBalanceMovement([
            'id' => self::uuid(), 'type' => 'cancel', 'amount' => $refund, 'direction' => 'debit',
            'description' => 'Void play ' . $line['numbers'] . ' — ticket ' . $ticketId,
            'operatorId' => $this->_currentUser['id'], 'createdAt' => $nowMs, 'ticketId' => $ticketId,
        ]);
        try {
            $this->db->insertAuditLog([
                'id' => 'audit_' . (int) round(microtime(true) * 1000000),
                'user' => $this->_currentUser['username'], 'action' => 'LINE_VOIDED',
                'periodId' => $periodId, 'oldValue' => (string) $line['numbers'],
                'newValue' => 'void ' . $line['id'] . ' refund ' . $refund,
                'timestamp' => $nowMs, 'reason' => 'Void line ' . $lineId . ' from ticket ' . $ticketId,
            ]);
        } catch (Throwable $e) {
        }

        return $refund;
    }

    // ----------------------------------------------------- prize calculation
    private function mult(array $cfg, string $key, float $default): float
    {
        if (! isset($cfg[$key])) {
            return $default;
        }

        return is_numeric($cfg[$key]) ? (float) $cfg[$key] : $default;
    }

    private function boxType3(array $digits): string
    {
        $uniq = count(array_unique($digits));

        return $uniq === 1 ? 'triple' : ($uniq === 2 ? '3way' : '6way');
    }

    private function boxType4(array $digits): string
    {
        $freq = [];
        foreach ($digits as $d) {
            $freq[$d] = ($freq[$d] ?? 0) + 1;
        }
        $vals = array_values($freq);
        sort($vals);
        if (count($vals) === 1) {
            return '4ofKind';
        }
        if (count($vals) === 2) {
            return in_array(3, $vals, true) ? '4way' : '6way';
        }

        return count($vals) === 3 ? '12way' : '24way';
    }

    public function calculatePrize(
        array $ticketDigits,
        array $winningDigits,
        string $betType,
        float $amount,
        ?array $cfg = null,
    ): float {
        $cfg ??= $this->configMap();
        if ($ticketDigits === [] || $winningDigits === [] || count($ticketDigits) !== count($winningDigits)) {
            return 0.0;
        }
        $n = count($ticketDigits);
        $bt = $this->normBet($betType);

        $numbersStraight = $this->mult($cfg, 'numbers_straight', 500);
        $numbersBox3Way = $this->mult($cfg, 'numbers_box_3way', 160);
        $numbersBox6Way = $this->mult($cfg, 'numbers_box_6way', 80);
        $win4Straight = $this->mult($cfg, 'win4_straight', 5000);
        $win4Box4Way = $this->mult($cfg, 'win4_box_4way', 1200);
        $win4Box6Way = $this->mult($cfg, 'win4_box_6way', 800);
        $win4Box12Way = $this->mult($cfg, 'win4_box_12way', 400);
        $win4Box24Way = $this->mult($cfg, 'win4_box_24way', 200);
        $winzStd = $this->mult($cfg, 'winz_std_multiplier', 10);
        $winzJack = $this->mult($cfg, 'winz_jackpot_multiplier', 100);
        $winzInstant = $this->mult($cfg, 'winz_instant_multiplier', 5);

        $ticketStr = implode('', $ticketDigits);
        $winStr = implode('', $winningDigits);
        $isExact = $ticketStr === $winStr;
        $sortedTicket = array_map('strval', $ticketDigits);
        $sortedWin = array_map('strval', $winningDigits);
        sort($sortedTicket, SORT_STRING);
        sort($sortedWin, SORT_STRING);
        $isSortedEqual = implode('', $sortedTicket) === implode('', $sortedWin);

        if ($bt === 'straight') {
            if (! $isExact) {
                return 0.0;
            }

            return $amount * ($n === 3 ? $numbersStraight : ($n === 4 ? $win4Straight : ($n <= 3 ? $numbersStraight : $win4Straight)));
        }
        if ($bt === 'box') {
            if (! $isSortedEqual) {
                return 0.0;
            }
            if ($n === 3) {
                $t = $this->boxType3($ticketDigits);
                if ($t === 'triple') {
                    return 0.0;
                }

                return $amount * ($t === '3way' ? $numbersBox3Way : $numbersBox6Way);
            }
            if ($n === 4) {
                $t = $this->boxType4($ticketDigits);
                if ($t === '4ofKind') {
                    return 0.0;
                }

                return $amount * match ($t) {
                    '4way' => $win4Box4Way,
                    '6way' => $win4Box6Way,
                    '12way' => $win4Box12Way,
                    default => $win4Box24Way,
                };
            }

            return $amount * $numbersBox3Way;
        }
        if (str_contains($bt, 'combo')) {
            if (! $isSortedEqual) {
                return 0.0;
            }
            $combos = $this->comboCount($ticketDigits);
            if ($combos <= 1) {
                return 0.0;
            }
            $wagerPerCombo = $amount / $combos;

            return $wagerPerCombo * ($n === 3 ? $numbersStraight : ($n === 4 ? $win4Straight : $numbersStraight));
        }
        if (in_array($bt, [
            'straight/box', 'straightbox', 'str/box', 'strbox', 'st/box', 'stbox',
            'all', 'all/box', 'all/straight',
        ], true) || (str_contains($bt, 'straight') && str_contains($bt, 'box'))) {
            if ($n === 3) {
                $t = $this->boxType3($ticketDigits);
                if ($t === 'triple') {
                    return $isExact ? $amount * $numbersStraight * 0.5 : 0.0;
                }
                $boxPrizePerDollar = $t === '3way' ? $numbersBox3Way : $numbersBox6Way;
                if ($isExact) {
                    return $amount * 0.5 * $numbersStraight + $amount * 0.5 * $boxPrizePerDollar;
                }
                if ($isSortedEqual) {
                    return $amount * 0.5 * $boxPrizePerDollar;
                }

                return 0.0;
            }
            if ($n === 4) {
                $t = $this->boxType4($ticketDigits);
                if ($t === '4ofKind') {
                    return $isExact ? $amount * $win4Straight * 0.5 : 0.0;
                }
                $boxPerDollar = match ($t) {
                    '4way' => $win4Box4Way,
                    '6way' => $win4Box6Way,
                    '12way' => $win4Box12Way,
                    default => $win4Box24Way,
                };
                if ($isExact) {
                    return $amount * 0.5 * $win4Straight + $amount * 0.5 * $boxPerDollar;
                }
                if ($isSortedEqual) {
                    return $amount * 0.5 * $boxPerDollar;
                }

                return 0.0;
            }
        }
        if (in_array($bt, ['frontpair', 'front/pair', 'pairfront'], true)) {
            if ($n !== 3) {
                return 0.0;
            }
            if ($ticketDigits[0] === $winningDigits[0] && $ticketDigits[1] === $winningDigits[1]) {
                return $amount * $this->mult($cfg, 'numbers_front_pair', 50);
            }

            return 0.0;
        }
        if (in_array($bt, ['backpair', 'back/pair', 'pairback'], true)) {
            if ($n !== 3) {
                return 0.0;
            }
            if ($ticketDigits[1] === $winningDigits[1] && $ticketDigits[2] === $winningDigits[2]) {
                return $amount * $this->mult($cfg, 'numbers_back_pair', 50);
            }

            return 0.0;
        }
        if (in_array($bt, ['winz/std', 'winzstd'], true)) {
            return $isExact ? $amount * $winzStd : 0.0;
        }
        if (in_array($bt, ['winz/jackpot', 'winzjackpot'], true)) {
            return $isExact ? $amount * $winzJack : 0.0;
        }
        if (in_array($bt, ['winz/instant', 'winzinstant'], true)) {
            return $isExact ? $amount * $winzInstant : 0.0;
        }
        if ($n === 3) {
            return $isExact ? $amount * $numbersStraight : 0.0;
        }
        if ($n === 4) {
            return $isExact ? $amount * $win4Straight : 0.0;
        }

        return $isExact ? $amount * $numbersStraight : 0.0;
    }

    // ------------------------------------------------------------ validation
    public function validateTicket(string $code): array
    {
        $code = trim(preg_replace('/[\r\n]/', '', $code));
        if (str_contains($code, ' ') || str_contains($code, ':') || str_contains($code, '|')) {
            $parts = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $code), static fn ($p) => $p !== ''));
            $numeric = array_values(array_filter($parts, static fn ($p) => preg_match('/^\d{4,10}$/', $p) === 1));
            if ($numeric !== []) {
                $code = end($numeric);
            } elseif ($parts !== []) {
                $code = end($parts);
            }
        }
        $t = $this->db->getTicketByBarcode($code);
        if ($t === null) {
            return ['found' => false];
        }
        if (($t['status'] ?? null) === 'cancelled') {
            return [
                'found' => true, 'ticket' => $t, 'isWinning' => false,
                'prize' => 0, 'cancelled' => true,
            ];
        }

        $lines = $this->db->getTicketLines((string) $t['id']);
        if ($lines !== []) {
            $cfg = $this->configMap();
            $totalPrize = 0.0;
            $hasPending = false;
            $lineDetails = [];
            foreach ($lines as $line) {
                if (($line['periodStatus'] ?? null) === 'cancelled' || ($line['winningStatus'] ?? null) === 'void') {
                    $lineDetails[] = ['line' => $line, 'prize' => 0, 'isWinning' => false, 'void' => true];
                    continue;
                }
                $ticketDigits = $this->digitsFromString(str_replace(' ', '', (string) $line['numbers']));
                $periodId = $line['periodId'] ?? null;
                $drawId = $line['drawId'] ?? null;
                $winStr = $this->resolveWinning($periodId, $drawId, count($ticketDigits));
                if ($winStr === null) {
                    $statusInfo = 'pending';
                    if ($periodId !== null) {
                        $p = $this->db->getPeriodById((string) $periodId);
                        if ($p !== null) {
                            $statusInfo = $p['status'];
                        }
                    } elseif ($drawId !== null) {
                        $d = $this->db->getDrawById((string) $drawId);
                        if ($d !== null) {
                            $statusInfo = $d['status'];
                        }
                    }
                    $hasPending = true;
                    $lineDetails[] = ['line' => $line, 'pending' => true, 'drawStatus' => $statusInfo];
                    continue;
                }
                $winningDigits = array_values(array_filter(
                    str_split(str_replace([' ', ','], '', $winStr)),
                    static fn ($x) => $x !== '',
                ));
                if ($winningDigits === []) {
                    $hasPending = true;
                    $lineDetails[] = ['line' => $line, 'pending' => true];
                    continue;
                }
                $amount = (float) $line['amount'];
                $betType = (string) ($line['betType'] ?? 'Straight');
                $prize = $this->calculatePrize($ticketDigits, $winningDigits, $betType, $amount, $cfg);
                if ($prize > 0) {
                    $totalPrize += $prize;
                }
                $lineDetails[] = ['line' => $line, 'prize' => $prize, 'isWinning' => $prize > 0];
            }

            $isWinning = $totalPrize > 0;
            if ($isWinning) {
                if (($t['status'] ?? null) === 'paid') {
                    return [
                        'found' => true, 'ticket' => $t, 'lines' => $lines, 'lineDetails' => $lineDetails,
                        'isWinning' => true,
                        'prize' => (float) ($t['prizeAmount'] ?? $totalPrize),
                        'alreadyPaid' => true, 'totalPrize' => $totalPrize,
                    ];
                }
                if (in_array($t['status'] ?? null, ['active', 'losing'], true)
                    || ($t['status'] ?? null) === 'winning') {
                    $this->db->updateTicketStatus((string) $t['id'], 'winning', $totalPrize);
                    $t['status'] = 'winning';
                    $t['prizeAmount'] = $totalPrize;
                }
                $pendingFlag = $hasPending;

                return [
                    'found' => true, 'ticket' => $t, 'lines' => $lines, 'lineDetails' => $lineDetails,
                    'isWinning' => true, 'prize' => $totalPrize, 'alreadyPaid' => false,
                    'pending' => $pendingFlag, 'totalPrize' => $totalPrize,
                ];
            }
            if ($hasPending) {
                return [
                    'found' => true, 'ticket' => $t, 'lines' => $lines, 'lineDetails' => $lineDetails,
                    'isWinning' => false, 'prize' => 0, 'pending' => true, 'totalPrize' => 0,
                ];
            }
            if (($t['status'] ?? null) === 'winning') {
                $this->db->updateTicketStatus((string) $t['id'], 'losing', 0.0);
                $t['status'] = 'losing';
                $t['prizeAmount'] = 0;
            } elseif (($t['status'] ?? null) === 'active') {
                $this->db->updateTicketStatus((string) $t['id'], 'losing');
                $t['status'] = 'losing';
            }

            return [
                'found' => true, 'ticket' => $t, 'lines' => $lines, 'lineDetails' => $lineDetails,
                'isWinning' => false, 'prize' => 0, 'totalPrize' => 0,
            ];
        }

        // Legacy single-line ticket.
        $drawId = $t['drawId'] ?? null;
        if ($drawId === null) {
            return [
                'found' => true, 'ticket' => $t, 'isWinning' => false,
                'prize' => 0, 'pending' => true, 'reason' => 'No draw assigned',
            ];
        }
        $draw = $this->db->getDrawById((string) $drawId);
        if ($draw === null) {
            return [
                'found' => true, 'ticket' => $t, 'isWinning' => false,
                'prize' => 0, 'pending' => true, 'reason' => 'Draw not found',
            ];
        }
        $winStr = $draw['winningNumbers'] ?? null;
        if (! self::nonempty($winStr)) {
            return [
                'found' => true, 'ticket' => $t, 'isWinning' => false, 'prize' => 0,
                'pending' => true, 'drawStatus' => $draw['status'],
            ];
        }
        $ticketDigits = $this->digitsFromString(str_replace(' ', '', (string) $t['numbers']));
        $winningDigits = $this->digitsFromString(str_replace(' ', '', (string) $winStr));
        $cfg = $this->configMap();
        $prize = $this->calculatePrize($ticketDigits, $winningDigits, (string) ($t['betType'] ?? 'Straight'), (float) $t['amount'], $cfg);
        $isWinning = $prize > 0;
        if ($isWinning) {
            if (($t['status'] ?? null) === 'paid') {
                return [
                    'found' => true, 'ticket' => $t, 'isWinning' => true,
                    'prize' => (float) ($t['prizeAmount'] ?? $prize), 'alreadyPaid' => true,
                ];
            }
            if (in_array($t['status'] ?? null, ['active', 'losing', 'winning'], true)) {
                $this->db->updateTicketStatus((string) $t['id'], 'winning', $prize);
                $t['status'] = 'winning';
                $t['prizeAmount'] = $prize;
            }

            return ['found' => true, 'ticket' => $t, 'isWinning' => true, 'prize' => $prize, 'alreadyPaid' => false];
        }
        if (($t['status'] ?? null) === 'winning') {
            $this->db->updateTicketStatus((string) $t['id'], 'losing', 0.0);
            $t['status'] = 'losing';
            $t['prizeAmount'] = 0;
        } elseif (($t['status'] ?? null) === 'active') {
            $this->db->updateTicketStatus((string) $t['id'], 'losing');
            $t['status'] = 'losing';
        }

        return ['found' => true, 'ticket' => $t, 'isWinning' => false, 'prize' => 0];
    }

    private function resolveWinning(?string $periodId, ?string $drawId, ?int $nDigits): ?string
    {
        if ($periodId !== null) {
            $p = $this->db->getPeriodById($periodId);
            if ($p !== null) {
                if ($nDigits === 3 && self::nonempty($p['result3'] ?? null)) {
                    return (string) $p['result3'];
                }
                if ($nDigits === 4 && self::nonempty($p['result4'] ?? null)) {
                    return (string) $p['result4'];
                }
                if (self::nonempty($p['result'] ?? null)) {
                    $digits = str_replace(',', '', trim((string) $p['result']));
                    if ($nDigits === null || strlen($digits) === $nDigits) {
                        return (string) $p['result'];
                    }
                }
            }
        }
        if ($drawId !== null) {
            $draw = $this->db->getDrawById($drawId);
            $win = $draw['winningNumbers'] ?? null;
            if (self::nonempty($win)) {
                $digits = str_replace(',', '', trim((string) $win));
                if ($nDigits === null || strlen($digits) === $nDigits) {
                    return (string) $win;
                }
            }
        }

        return null;
    }

    // --------------------------------------------------------------- results
    public function processPeriodResult(
        string $periodId,
        array $winningNumbers = [],
        bool $autoPay = false,
        string $resultSource = 'manual',
        bool $isCorrection = false,
    ): array {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        if (! $this->isAdmin()) {
            self::fail('Only admin can set winning numbers');
        }
        $winStr = implode('', $winningNumbers);
        if ($winStr === '') {
            self::fail('winningNumbers empty');
        }
        foreach ($winningNumbers as $d) {
            if (preg_match('/^[0-9]$/', (string) $d) !== 1) {
                self::fail('Winning digits must be 0-9');
            }
        }
        if (strlen($winStr) < 3 || strlen($winStr) > 4) {
            self::fail('Result must be 3 or 4 digits');
        }
        $pBefore = $this->db->getPeriodById($periodId);
        if ($pBefore === null) {
            self::fail('Period not found: ' . $periodId);
        }
        $is3 = strlen($winStr) === 3;
        $is4 = strlen($winStr) === 4;
        if ($is3 && self::nonempty($pBefore['result3'] ?? null) && ! $isCorrection) {
            self::fail('Result 3-digit already published for ' . $periodId . ': ' . $pBefore['result3'] . ' — use correction');
        }
        if ($is4 && self::nonempty($pBefore['result4'] ?? null) && ! $isCorrection) {
            self::fail('Result 4-digit already published for ' . $periodId . ': ' . $pBefore['result4'] . ' — use correction');
        }
        if ($isCorrection) {
            $affected = [];
            foreach ($this->db->getTicketLinesByPeriod($periodId) as $l) {
                if (count($this->digitsFromString((string) $l['numbers'])) === strlen($winStr)) {
                    $affected[$l['ticketId']] = true;
                }
            }
            foreach (array_keys($affected) as $tid) {
                $this->reversePayout((string) $tid, 'correction for ' . $periodId);
            }
        }
        $this->db->resetPeriodTickets($periodId);
        $nowMs = self::nowMs();
        $user = (string) $this->_currentUser['username'];
        if ($is3) {
            $this->db->publishPeriodResult3($periodId, $winStr, $nowMs, $user, $resultSource);
        } elseif ($is4) {
            $this->db->publishPeriodResult4($periodId, $winStr, $nowMs, $user, $resultSource);
        } else {
            $this->db->publishPeriodResult($periodId, $winStr, $nowMs, $user, $resultSource);
        }
        $this->db->insertAuditLog([
            'id' => 'audit_' . (int) round(microtime(true) * 1000000),
            'user' => $user,
            'action' => $isCorrection ? 'RESULT_CORRECTED' : 'RESULT_PUBLISHED',
            'periodId' => $periodId,
            'oldValue' => $is3 ? ($pBefore['result3'] ?? null) : ($is4 ? ($pBefore['result4'] ?? null) : ($pBefore['result'] ?? null)),
            'newValue' => $winStr, 'timestamp' => $nowMs,
            'reason' => $isCorrection ? 'Correction ' . $periodId . ' (' . $winStr . ')' : 'Publish ' . $periodId . ' (' . $winStr . ')',
        ]);

        $periodLines = array_values(array_filter(
            $this->db->getAllTicketLines(),
            static fn ($l) => ($l['periodId'] ?? null) === $periodId,
        ));
        $ticketIds = array_unique(array_map(static fn ($l) => (string) $l['ticketId'], $periodLines));
        $tickets = $this->db->getTickets();
        $winningCount = 0;
        $losingCount = 0;
        $totalPrize = 0.0;
        $totalSales = 0.0;
        foreach ($periodLines as $l) {
            if (($l['periodStatus'] ?? null) === 'cancelled') {
                continue;
            }
            $totalSales += (float) $l['amount'];
        }
        foreach ($ticketIds as $tid) {
            $ticket = null;
            foreach ($tickets as $candidate) {
                if ($candidate['id'] === $tid) {
                    $ticket = $candidate;
                    break;
                }
            }
            if ($ticket === null || in_array($ticket['status'] ?? null, ['paid', 'cancelled'], true)) {
                continue;
            }
            $res = $this->validateTicket((string) ($ticket['barcode'] ?? $ticket['id']));
            $isWinForPeriod = false;
            $prizeForPeriod = 0.0;
            foreach (($res['lineDetails'] ?? []) as $d) {
                $line = $d['line'];
                if (($line['periodId'] ?? null) === $periodId && ($d['isWinning'] ?? false) === true) {
                    $isWinForPeriod = true;
                    $prizeForPeriod += (float) $d['prize'];
                }
            }
            if ($isWinForPeriod) {
                $winningCount++;
                $totalPrize += $prizeForPeriod;
                if ($autoPay && ($res['alreadyPaid'] ?? false) !== true && ($res['pending'] ?? false) !== true) {
                    $this->payWinningTicket((string) $tid, $prizeForPeriod);
                }
            } elseif (($res['pending'] ?? false) !== true) {
                $has = false;
                foreach (($res['lineDetails'] ?? []) as $d) {
                    if ((($d['line'] ?? [])['periodId'] ?? null) === $periodId) {
                        $has = true;
                    }
                }
                if ($has) {
                    $losingCount++;
                }
            }
        }
        $pAfter = $this->db->getPeriodById($periodId);
        if ($pAfter !== null && ($pAfter['status'] ?? null) === 'RESULT_PUBLISHED') {
            $this->db->updatePeriodStatus($periodId, 'COMPLETED');
        }

        return [
            'winningCount' => $winningCount, 'losingCount' => $losingCount,
            'totalPrize' => $totalPrize, 'totalSales' => $totalSales,
            'net' => $totalSales - $totalPrize, 'totalTickets' => count($ticketIds),
        ];
    }

    public function processDrawResult(
        string $drawId,
        array $winningNumbers = [],
        bool $autoPay = false,
    ): array {
        $this->ensureLoggedIn();
        $this->ensureNotExpired();
        if (! $this->isAdmin()) {
            self::fail('Only admin can set winning numbers');
        }
        $winStr = implode(',', array_values(array_filter(array_map('trim', $winningNumbers), static fn ($x) => $x !== '')));
        if ($winStr === '') {
            self::fail('winningNumbers empty');
        }
        foreach ($winningNumbers as $d) {
            if (preg_match('/^[0-9]$/', (string) $d) !== 1) {
                self::fail('Winning digits must be 0-9');
            }
        }
        $priorDraw = $this->db->getDrawById($drawId);
        $previous = (string) ($priorDraw['winningNumbers'] ?? '');
        if (trim($previous) !== '') {
            $affected = [];
            foreach ($this->db->getAllTicketLines() as $l) {
                if (($l['drawId'] ?? null) === $drawId
                    && count($this->digitsFromString((string) $l['numbers'])) === count($winningNumbers)) {
                    $affected[$l['ticketId']] = true;
                }
            }
            foreach ($this->db->getTickets() as $t) {
                if (($t['drawId'] ?? null) === $drawId
                    && count($this->digitsFromString((string) $t['numbers'])) === count($winningNumbers)) {
                    $affected[$t['id']] = true;
                }
            }
            foreach (array_keys($affected) as $tid) {
                $this->reversePayout((string) $tid, 'correction for draw ' . $drawId);
            }
        }
        $this->db->resetDrawTickets($drawId);
        $this->db->updateDrawResult($drawId, $winStr);

        try {
            $draw = $this->db->getDrawById($drawId);
            if ($draw !== null) {
                $wall = $this->wallDateFromDraw($draw);
                $name = strtolower((string) $draw['name']);
                $pType = str_contains($name, 'evening') ? 'EVENING'
                    : (str_contains($name, 'midday') || str_contains($name, 'morning') ? 'MIDDAY' : 'MIDDAY');
                $pid = $this->periodIdForDate($wall, $pType);
                if ($this->db->getPeriodById($pid) !== null) {
                    $plain = implode('', $winningNumbers);
                    $this->db->resetPeriodTickets($pid);
                    $nowMs = self::nowMs();
                    $user = (string) $this->_currentUser['username'];
                    if (strlen($plain) === 3) {
                        $this->db->publishPeriodResult3($pid, $plain, $nowMs, $user, 'manual');
                    } else {
                        $this->db->publishPeriodResult4($pid, $plain, $nowMs, $user, 'manual');
                    }
                }
            }
        } catch (Throwable $e) {
        }

        $tickets = $this->db->getTickets();
        $allLines = $this->db->getAllTicketLines();
        $ticketIdsForDraw = [];
        foreach ($allLines as $l) {
            if (($l['drawId'] ?? null) === $drawId) {
                $ticketIdsForDraw[$l['ticketId']] = true;
            }
        }
        foreach ($tickets as $t) {
            if (($t['drawId'] ?? null) === $drawId && ! isset($ticketIdsForDraw[$t['id']])) {
                $ticketIdsForDraw[$t['id']] = true;
            }
        }
        $drawTickets = array_values(array_filter(
            $tickets,
            static fn ($t) => isset($ticketIdsForDraw[$t['id']]),
        ));

        $winningCount = 0;
        $losingCount = 0;
        $totalPrize = 0.0;
        $totalSales = 0.0;
        foreach (array_keys($ticketIdsForDraw) as $tid) {
            $lines = array_values(array_filter($allLines, static fn ($l) => ($l['ticketId'] ?? null) === $tid));
            if ($lines !== []) {
                foreach ($lines as $l) {
                    if (($l['drawId'] ?? null) === $drawId && ($l['periodStatus'] ?? null) !== 'cancelled') {
                        $totalSales += (float) $l['amount'];
                    }
                }
            } else {
                foreach ($tickets as $t) {
                    if ($t['id'] === $tid) {
                        $totalSales += (float) $t['amount'];
                        break;
                    }
                }
            }
        }
        foreach ($drawTickets as $ticket) {
            if (in_array($ticket['status'] ?? null, ['paid', 'cancelled'], true)) {
                continue;
            }
            $res = $this->validateTicket((string) ($ticket['barcode'] ?? $ticket['id']));
            $isWinForThisDraw = false;
            $prizeForThisDraw = 0.0;
            if (isset($res['lineDetails'])) {
                foreach ($res['lineDetails'] as $d) {
                    $line = $d['line'];
                    if (($line['drawId'] ?? null) === $drawId && ($d['isWinning'] ?? false) === true) {
                        $isWinForThisDraw = true;
                        $prizeForThisDraw += (float) $d['prize'];
                    }
                }
                if ($isWinForThisDraw) {
                    $winningCount++;
                    $totalPrize += $prizeForThisDraw;
                    if ($autoPay && ($res['alreadyPaid'] ?? false) !== true && ($res['pending'] ?? false) !== true) {
                        $this->payWinningTicket((string) $ticket['id'], $prizeForThisDraw);
                    }
                } elseif (($res['pending'] ?? false) !== true) {
                    $hasLinesForDraw = false;
                    foreach ($res['lineDetails'] as $d) {
                        if ((($d['line'] ?? [])['drawId'] ?? null) === $drawId) {
                            $hasLinesForDraw = true;
                        }
                    }
                    if ($hasLinesForDraw) {
                        $losingCount++;
                    }
                }
            } elseif (($res['found'] ?? false) === true && ($res['isWinning'] ?? false) === true) {
                $winningCount++;
                $totalPrize += (float) $res['prize'];
                if ($autoPay && ($res['alreadyPaid'] ?? false) !== true) {
                    $this->payWinningTicket((string) $ticket['id'], (float) $res['prize']);
                }
            } elseif (($res['found'] ?? false) === true && ($res['isWinning'] ?? false) === false && ($res['pending'] ?? false) !== true) {
                $losingCount++;
            }
        }

        return [
            'winningCount' => $winningCount, 'losingCount' => $losingCount,
            'totalPrize' => $totalPrize, 'totalSales' => $totalSales,
            'net' => $totalSales - $totalPrize, 'totalTickets' => count($drawTickets),
        ];
    }

    // ------------------------------------------------------------- customers
    public function useDefaultCustomer(): array
    {
        $c = $this->db->getCustomerById(self::DEFAULT_CUSTOMER_ID);
        $this->_currentCustomer = $c ?? ['id' => self::DEFAULT_CUSTOMER_ID, 'name' => self::DEFAULT_CUSTOMER_NAME];

        return $this->_currentCustomer;
    }

    public function selectCustomer(string $customerId): array
    {
        $c = $this->db->getCustomerById($customerId);
        if ($c === null) {
            self::fail('Customer not found');
        }
        $this->_currentCustomer = $c;

        return $this->_currentCustomer;
    }

    public function getCustomers(): array
    {
        return $this->db->getCustomers();
    }

    public function addCustomer(string $name, ?string $phone = null, ?string $notes = null): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            self::fail('Customer name is required');
        }
        $id = 'cust_' . self::uuid();
        $this->db->insertCustomer([
            'id' => $id, 'name' => $trimmed,
            'phone' => trim((string) ($phone ?? '')), 'notes' => trim((string) ($notes ?? '')),
            'isDefault' => 0, 'createdAt' => self::nowMs(),
        ]);

        return $id;
    }

    public function updateCustomer(string $id, string $name, ?string $phone = null, ?string $notes = null): bool
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            self::fail('Customer name is required');
        }
        $this->db->updateCustomer($id, $trimmed, trim((string) ($phone ?? '')), trim((string) ($notes ?? '')));
        if (($this->_currentCustomer['id'] ?? null) === $id) {
            $this->selectCustomer($id);
        }

        return true;
    }

    public function deleteCustomer(string $id): bool
    {
        if ($id === self::DEFAULT_CUSTOMER_ID) {
            self::fail('Cannot delete the default customer');
        }
        $this->db->reassignCustomerTickets($id, self::DEFAULT_CUSTOMER_ID, self::DEFAULT_CUSTOMER_NAME);
        $this->db->deleteCustomer($id);
        if (($this->_currentCustomer['id'] ?? null) === $id) {
            $this->useDefaultCustomer();
        }

        return true;
    }

    public function getCustomerPlays(string $customerId): array
    {
        return $this->db->getCustomerPlays($customerId);
    }

    public function saveCartAsCustomerPlays(string $customerId, ?string $drawType = null): int
    {
        if ($this->_cart === []) {
            self::fail('Cart is empty');
        }
        if ($this->db->getCustomerById($customerId) === null) {
            self::fail('Customer not found');
        }
        $now = self::nowMs();
        foreach ($this->_cart as $item) {
            $this->db->insertCustomerPlay([
                'id' => self::uuid(), 'customerId' => $customerId,
                'gameId' => $item['gameId'], 'gameName' => $item['gameName'],
                'numbers' => implode(',', $item['numbers']), 'betType' => $item['betType'],
                'amount' => $item['amount'], 'drawType' => $drawType ?? $item['drawType'],
                'isQuickPick' => $item['isQuickPick'] ? 1 : 0, 'createdAt' => $now,
            ]);
        }

        return count($this->_cart);
    }

    public function playCustomerUsual(string $customerId): array
    {
        if ($this->db->getCustomerById($customerId) === null) {
            self::fail('Customer not found');
        }
        $plays = $this->db->getCustomerPlays($customerId);
        if ($plays === []) {
            self::fail('No saved plays for this customer');
        }
        $skipped = [];
        $added = 0;
        foreach ($plays as $play) {
            try {
                $this->addToCart(
                    (string) $play['gameId'],
                    (string) $play['gameName'],
                    null,
                    null,
                    null,
                    $play['drawType'] ?? null,
                    $this->digitsFromString((string) $play['numbers']),
                    (string) $play['betType'],
                    (float) $play['amount'],
                    (int) ($play['isQuickPick'] ?? 0) === 1,
                );
                $added++;
            } catch (Throwable $e) {
                $skipped[] = $play['numbers'] . ': ' . str_replace('Exception: ', '', $e->getMessage());
            }
        }
        $this->selectCustomer($customerId);

        return ['added' => $added, 'skipped' => $skipped];
    }
}
