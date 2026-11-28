<?php
declare(strict_types=1);

namespace {
    final class RateLimitFakeDb
    {
        /** @var array<string, array<string, mixed>> */
        public array $rows = [];
        private int $affected = 0;

        /** @return array<int, array<string, mixed>> */
        public function request(array $criteria): array
        {
            $name = (string) ($criteria['WHERE']['lock_name'] ?? '');
            return isset($this->rows[$name]) ? [$this->rows[$name]] : [];
        }

        public function insert(string $table, array $values): bool
        {
            $name = (string) ($values['lock_name'] ?? '');
            if ($name === '' || isset($this->rows[$name])) {
                $this->affected = 0;
                return false;
            }
            $this->rows[$name] = $values + ['id' => count($this->rows) + 1];
            $this->affected = 1;
            return true;
        }

        public function update(string $table, array $values, array $where): bool
        {
            $name = (string) ($where['lock_name'] ?? '');
            if (!isset($this->rows[$name])) {
                $this->affected = 0;
                return false;
            }
            $this->rows[$name] = array_merge($this->rows[$name], $values);
            $this->affected = 1;
            return true;
        }

        public function delete(string $table, array $where): bool
        {
            $name = (string) ($where['lock_name'] ?? '');
            if (!isset($this->rows[$name])) {
                $this->affected = 0;
                return false;
            }
            if (isset($where['lock_token']) && $this->rows[$name]['lock_token'] !== $where['lock_token']) {
                $this->affected = 0;
                return false;
            }
            unset($this->rows[$name]);
            $this->affected = 1;
            return true;
        }

        public function affectedRows(): int
        {
            return $this->affected;
        }
    }

    require_once __DIR__ . '/../src/Utils.php';
    require_once __DIR__ . '/../src/API.php';

    $DB = new RateLimitFakeDb();
    $checks = 0;
    $expect = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    $invokePrivate = static function (string $method, array $arguments): mixed {
        $reflection = new \ReflectionMethod(\GlpiPlugin\Gdmsintegration\API::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    };

    $expect(
        $invokePrivate('isGdmsEndpoint', ['https://www.gdms.cloud/oapi/v1.0.0/device/list']) === true,
        'GDMS UC OAPI host should be recognized.'
    );
    $expect(
        $invokePrivate('isGdmsEndpoint', ['https://eu.gdms.cloud/oauth/token']) === true,
        'GDMS regional OAuth host should be recognized.'
    );
    $expect(
        $invokePrivate('isGdmsEndpoint', ['https://www.gwn.cloud/oapi/v1.0.0/device/list']) === false,
        'GWN Networking calls must not be paused by GDMS cooldown.'
    );
    $expect(
        $invokePrivate('isGdmsEndpoint', ['https://notgdms.cloud/oapi/v1.0.0/device/list']) === false,
        'A host that merely ends in gdms.cloud without a DNS label boundary must not be recognized.'
    );
    $expect(
        $invokePrivate('isGdmsRateLimitMessage', ['Too many requests from this IP. Please try again later.']) === true,
        'The exact GDMS IP rate-limit message must be recognized.'
    );
    $expect(
        $invokePrivate('isGdmsRateLimitMessage', ['Request frequency exceeded']) === true,
        'Request-frequency variants must be recognized.'
    );
    $expect(
        $invokePrivate('isGdmsRateLimitMessage', ['Invalid client secret']) === false,
        'Authentication errors must not be mistaken for rate limits.'
    );
    $expect(
        $invokePrivate('gdmsResponseMessage', [['retMsg' => 'Too many requests from this IP']]) === 'Too many requests from this IP',
        'GDMS response message extraction should preserve the diagnostic text.'
    );

    $expect(
        $invokePrivate('parseRetryAfter', ["Retry-After: 45\r\nContent-Type: application/json\r\n"]) === 45,
        'Retry-After delta-seconds should be parsed.'
    );
    $referenceTime = time();
    $httpDate = gmdate('D, d M Y H:i:s', $referenceTime + 30) . ' GMT';
    $dateDelay = $invokePrivate('parseRetryAfter', ["HTTP/1.1 429 Too Many Requests\r\nRetry-After: {$httpDate}\r\n", $referenceTime]);
    $expect($dateDelay === 30, 'Retry-After HTTP-date should use the same fixed reference timestamp.');
    $expect(
        $invokePrivate('parseRetryAfter', ['Content-Type: application/json' . "\r\n"]) === 0,
        'Missing Retry-After should return zero and use the configured fallback.'
    );

    \GlpiPlugin\Gdmsintegration\Utils::setProviderCooldown('gdms', 30);
    $remaining = \GlpiPlugin\Gdmsintegration\Utils::getProviderCooldownRemaining('gdms');
    $expect($remaining > 0 && $remaining <= 30, 'GDMS cooldown should be persisted and returned.');

    $extensionRequestedAt = time();
    \GlpiPlugin\Gdmsintegration\Utils::setProviderCooldown('gdms', 120);
    $extended = \GlpiPlugin\Gdmsintegration\Utils::getProviderCooldownRemaining('gdms');
    $storedExpiry = (int) substr((string) ($DB->rows['gdmsinteg_cooldown_gdms']['lock_token'] ?? ''), strlen('cooldown:'));
    $expect($extended > 30 && $extended <= 120, 'A longer cooldown must extend, not shorten, the current window.');
    $expect($storedExpiry >= $extensionRequestedAt + 120, 'The stored absolute expiry must cover the full requested extension.');
    \GlpiPlugin\Gdmsintegration\Utils::setProviderCooldown('gdms', 30);
    $expect((int) substr((string) ($DB->rows['gdmsinteg_cooldown_gdms']['lock_token'] ?? ''), strlen('cooldown:')) === $storedExpiry, 'A shorter subsequent cooldown must never reduce the stored GDMS expiry.');

    $gdmsExpiryBeforeGwn = $storedExpiry;
    $expect(\GlpiPlugin\Gdmsintegration\Utils::getProviderCooldownRemaining('gwn') === 0, 'A GDMS cooldown must not activate the GWN provider.');
    \GlpiPlugin\Gdmsintegration\Utils::setProviderCooldown('gwn', 30);
    $expect(\GlpiPlugin\Gdmsintegration\Utils::getProviderCooldownRemaining('gwn') > 0, 'Provider cooldowns should be isolated by provider.');
    $expect((int) substr((string) ($DB->rows['gdmsinteg_cooldown_gdms']['lock_token'] ?? ''), strlen('cooldown:')) === $gdmsExpiryBeforeGwn, 'Writing a GWN cooldown must not change the GDMS expiry.');


    $expiredName = 'gdmsinteg_cooldown_expired';
    $DB->rows[$expiredName] = [
        'lock_name' => $expiredName,
        'lock_token' => 'cooldown:' . (time() - 5),
        'acquired_at' => gmdate('Y-m-d H:i:s'),
    ];
    $expect(\GlpiPlugin\Gdmsintegration\Utils::getProviderCooldownRemaining('expired') === 0, 'Expired cooldown should return zero.');
    $expect(isset($DB->rows[$expiredName]), 'Expired cooldown rows remain for a serialized writer to extend safely.');

    $expect(
        strlen((string) ($DB->rows['gdmsinteg_cooldown_gdms']['lock_token'] ?? '')) <= 32,
        'Cooldown token must fit the existing lock_token CHAR(32) column.'
    );

    \GlpiPlugin\Gdmsintegration\Utils::setProviderCooldown('gdms', 90000);
    $bounded = \GlpiPlugin\Gdmsintegration\Utils::getProviderCooldownRemaining('gdms');
    $expect($bounded <= 86400 && $bounded > 0, 'Persisted cooldown must be capped at one day.');

    echo "GDMS rate-limit regression: {$checks} assertions passed.\n";
}
