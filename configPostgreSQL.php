<?php

declare(strict_types=1);

/**
 * AppConfigPostgreSQL - Single-file PostgreSQL-ready configuration and application core.
 *
 * Essential developer note:
 * - This file is intentionally centralized so database access, credentials, token workflow,
 *   and encryption logic remain in one place.
 * - This is designed for PostgreSQL-first usage, but it can still support MySQL by setting
 *   DB_DRIVER=mysql if needed.
 * - Production secrets must never be stored in the repository. Keep them in server-side files
 *   outside the public document root or in environment variables.
 * - Future refactors should keep all logic here to avoid scattered hardcoded values.
 * - Large dataset workloads should prefer chunked batches and streaming, not full in-memory loads.
 */
final class AppConfigPostgreSQL
{
    private static ?PDO $pdo = null;

    /**
     * Initialize the secret storage directory.
     */
    public static function init(): void
    {
        $keyDir = self::keyDirectory();
        if (!is_dir($keyDir)) {
            if (!mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
                throw new RuntimeException('Unable to create key directory: ' . $keyDir);
            }
        }

        chmod($keyDir, 0700);
    }

    /**
     * Create or return a PostgreSQL database connection.
     *
     * Supports both PostgreSQL and MySQL via DB_DRIVER.
     */
    public static function dbConnect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower((string) self::env('DB_DRIVER', 'pgsql'));
        $host = (string) self::env('DB_HOST', '127.0.0.1');
        $port = (string) self::env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
        $name = (string) self::env('DB_NAME', 'app_db');
        $user = (string) self::env('DB_USER', 'app_user');
        $pass = (string) self::env('DB_PASS', '');
        $charset = (string) self::env('DB_CHARSET', 'utf8mb4');

        $dsn = self::buildDsn($driver, $host, $port, $name, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 30,
        ];

        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $charset;
        }

        self::$pdo = new PDO($dsn, $user, $pass, $options);

        if ($driver === 'pgsql') {
            self::$pdo->exec("SET TIME ZONE 'UTC'");
            self::$pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        if ($driver === 'mysql') {
            self::$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return self::$pdo;
    }

    /**
     * Build the correct PDO DSN based on the database driver.
     */
    private static function buildDsn(string $driver, string $host, string $port, string $name, string $charset): string
    {
        if ($driver === 'pgsql') {
            return sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
        }

        if ($driver === 'mysql') {
            return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
        }

        throw new InvalidArgumentException('Unsupported DB driver: ' . $driver . '. Use pgsql or mysql.');
    }

    /**
     * Fetch a single row safely.
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Fetch multiple rows while maintaining a safe default limit.
     */
    public static function fetchAll(string $sql, array $params = [], int $limit = 1000): array
    {
        $query = trim($sql);
        if (!preg_match('/\bLIMIT\b/i', $query)) {
            $query .= ' LIMIT :limit_val';
        }

        $stmt = self::dbConnect()->prepare($query);
        self::bindParams($stmt, $params);
        $stmt->bindValue(':limit_val', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * Stream rows to avoid loading all huge results into memory.
     */
    public static function streamRows(string $sql, array $params = []): Generator
    {
        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield $row;
        }
    }

    /**
     * Execute a callback inside a transaction.
     *
     * This is especially helpful for large multi-step processing and data consistency.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::dbConnect();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Insert a row with credential validation.
     */
    public static function insert(string $sql, array $params, string $credentialKey, string $scope = 'write'): int
    {
        self::assertCredentialKey($credentialKey, $scope);

        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        $driver = strtolower((string) self::env('DB_DRIVER', 'pgsql'));
        if ($driver === 'pgsql') {
            $id = self::dbConnect()->lastInsertId();
            return is_numeric($id) ? (int) $id : 0;
        }

        return (int) self::dbConnect()->lastInsertId();
    }

    /**
     * Update rows with credential validation.
     */
    public static function update(string $sql, array $params, string $credentialKey, string $scope = 'write'): int
    {
        self::assertCredentialKey($credentialKey, $scope);

        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Delete rows with credential validation.
     */
    public static function delete(string $sql, array $params, string $credentialKey, string $scope = 'write'): int
    {
        self::assertCredentialKey($credentialKey, $scope);

        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Execute a generic write statement.
     */
    public static function executeWrite(string $sql, array $params, string $credentialKey, string $scope = 'write'): bool
    {
        self::assertCredentialKey($credentialKey, $scope);

        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        return true;
    }

    /**
     * Batch insert rows in chunks for large dataset processing.
     *
     * This is useful when handling huge payloads while protecting memory usage.
     */
    public static function bulkInsert(string $table, array $rows, array $columns, string $credentialKey, string $scope = 'write', int $batchSize = 500): int
    {
        self::assertCredentialKey($credentialKey, $scope);

        if ($rows === []) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $placeholders = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            }

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $stmt = self::dbConnect()->prepare($sql);
            $position = 1;

            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $stmt->bindValue($position++, $value, self::resolveParamType($value));
                }
            }

            $stmt->execute();
            $total += count($chunk);
        }

        return $total;
    }

    /**
     * Generate a login token for a user session.
     */
    public static function generateLoginToken(array $user): string
    {
        if (empty($user['id'])) {
            throw new InvalidArgumentException('User id is required for login token generation.');
        }

        $payload = [
            'sub' => (string) $user['id'],
            'role' => $user['role'] ?? 'user',
            'iat' => time(),
            'exp' => time() + 3600,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $secret = self::readSecret('login_secret');
        $signature = self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $body, $secret, true));

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * Generate a one-time operation token for database write operations.
     */
    public static function generateOperationToken(string $action, array $payload, string $credentialKey, string $scope = 'write'): string
    {
        self::assertCredentialKey($credentialKey, $scope);

        $data = [
            'action' => $action,
            'payload' => $payload,
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret = self::readSecret('operation_secret');
        $payloadB64 = self::base64UrlEncode($json);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $secret, true));

        $token = $payloadB64 . '.' . $signature;
        self::markTokenUsed($token, false);

        return $token;
    }

    /**
     * Validate an operation token and ensure it can only be used once.
     */
    public static function validateOperationToken(string $token): bool
    {
        if ($token === '' || !str_contains($token, '.')) {
            return false;
        }

        [$payloadB64, $signatureB64] = explode('.', $token, 2);
        if ($payloadB64 === '' || $signatureB64 === '') {
            return false;
        }

        $secret = self::readSecret('operation_secret');
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
        if (!hash_equals($expected, $signatureB64)) {
            return false;
        }

        try {
            $data = json_decode(self::base64UrlDecode($payloadB64), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return false;
        }

        if (($data['action'] ?? '') === '') {
            return false;
        }

        if (self::tokenExists($token)) {
            return false;
        }

        self::markTokenUsed($token, true);
        return true;
    }

    /**
     * Generate a credential key tied to a specific scope.
     */
    public static function generateCredentialKey(string $scope): string
    {
        $secret = self::readSecret('credential_' . $scope);
        return hash_hmac('sha256', $scope, $secret);
    }

    /**
     * Encrypt a single value using a secret key stored on the server filesystem.
     */
    public static function encryptSingle(string $plainText, string $keyName): string
    {
        $key = self::readSecret($keyName);

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plainText, $nonce, $key);
            return self::base64UrlEncode($nonce . $cipher);
        }

        $iv = random_bytes(16);
        $tag = '';
        $cipher = openssl_encrypt($plainText, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new RuntimeException('Encrypting single value failed.');
        }

        return self::base64UrlEncode($iv . $tag . $cipher);
    }

    /**
     * Decrypt a single value using a secret key stored on the server filesystem.
     */
    public static function decryptSingle(string $cipherText, string $keyName): string
    {
        $key = self::readSecret($keyName);
        $data = self::base64UrlDecode($cipherText);

        if (function_exists('sodium_crypto_secretbox')) {
            $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $nonce = substr($data, 0, $nonceSize);
            $cipher = substr($data, $nonceSize);
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

            if ($plain === false) {
                throw new RuntimeException('Decrypting single value failed.');
            }

            return $plain;
        }

        $ivLength = 16;
        $tagLength = 16;
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $cipher = substr($data, $ivLength + $tagLength);

        $plain = openssl_decrypt($cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decrypting single value failed.');
        }

        return $plain;
    }

    /**
     * Encrypt a bulk payload (list or associative array) into a single string.
     */
    public static function encryptBulk(array $data, string $keyName): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::encryptSingle($json, $keyName);
    }

    /**
     * Decrypt a bulk payload back into an array.
     */
    public static function decryptBulk(string $cipherText, string $keyName): array
    {
        $json = self::decryptSingle($cipherText, $keyName);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Bulk payload is invalid or corrupted.');
        }

        return $decoded;
    }

    /**
     * Bind parameter values to a PDO statement.
     */
    private static function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $actualKey = is_string($key) ? $key : ':' . $key;

            if (is_string($key) && !str_starts_with($key, ':')) {
                $actualKey = ':' . $key;
            }

            $stmt->bindValue($actualKey, $value, self::resolveParamType($value));
        }
    }

    /**
     * Resolve PDO parameter type.
     */
    private static function resolveParamType(mixed $value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }

    /**
     * Validate credential key for a specific scope.
     */
    private static function assertCredentialKey(string $credentialKey, string $scope): void
    {
        if (!self::verifyCredentialKey($credentialKey, $scope)) {
            throw new RuntimeException('Invalid credential key for scope: ' . $scope);
        }
    }

    private static function verifyCredentialKey(string $key, string $scope): bool
    {
        if ($key === '') {
            return false;
        }

        $secret = self::readSecret('credential_' . $scope);
        $expected = hash_hmac('sha256', $scope, $secret);

        return hash_equals($expected, $key);
    }

    /**
     * Resolve secret storage directory.
     */
    private static function keyDirectory(): string
    {
        $preferred = '/etc/myapp/keys';
        if (is_dir($preferred)) {
            return $preferred;
        }

        $fallback = __DIR__ . DIRECTORY_SEPARATOR . 'keys';
        return is_dir($fallback) ? $fallback : $preferred;
    }

    /**
     * Build full path to a secret file.
     */
    private static function secretPath(string $name): string
    {
        return self::keyDirectory() . DIRECTORY_SEPARATOR . $name . '.key';
    }

    /**
     * Read a secret from a file on the filesystem.
     */
    private static function readSecret(string $name): string
    {
        $path = self::secretPath($name);

        if (!is_file($path)) {
            throw new RuntimeException('Secret file not found: ' . $path);
        }

        $content = trim((string) file_get_contents($path));
        if ($content === '') {
            throw new RuntimeException('Secret file is empty: ' . $path);
        }

        return $content;
    }

    /**
     * Read environment variables safely with default fallback values.
     */
    private static function env(string $name, mixed $default = null): mixed
    {
        $value = $_ENV[$name] ?? getenv($name);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * URL-safe base64 encoding.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decoding.
     */
    private static function base64UrlDecode(string $data): string
    {
        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * Mark token as used or unused.
     */
    private static function markTokenUsed(string $token, bool $used): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'app_cfg_tokens';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                return;
            }
        }

        $hash = hash('sha256', $token);
        $path = $dir . DIRECTORY_SEPARATOR . $hash . '.token';

        if ($used) {
            file_put_contents($path, (string) time() . PHP_EOL, LOCK_EX);
            return;
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Check whether a token was already used.
     */
    private static function tokenExists(string $token): bool
    {
        $hash = hash('sha256', $token);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'app_cfg_tokens' . DIRECTORY_SEPARATOR . $hash . '.token';
        return is_file($path);
    }
}

AppConfigPostgreSQL::init();
