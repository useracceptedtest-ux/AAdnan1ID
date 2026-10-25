<?php

declare(strict_types=1);

/**
 * AppConfig - Single-file security and infrastructure core for this application.
 *
 * Essential developer note:
 * - This file is intentionally centralized to prevent secret values and operational logic
 *   from being scattered across multiple files.
 * - The file must remain the single source of truth for database access, validation,
 *   token lifecycle, and encryption helpers.
 * - Never store production credentials, encryption keys, or operational tokens in the repo.
 * - Secrets must live on the server filesystem outside the document root, with restrictive permissions.
 * - Future refactors must preserve method contracts and keep all logic inside this class.
 */
final class AppConfig
{
    private static ?PDO $pdo = null;

    /**
     * Initialize the application security layout.
     *
     * This ensures the key directory exists before calling any secret-dependent method.
     * In production, keys should be placed under /etc/myapp/keys with chmod 600.
     */
    public static function init(): void
    {
        $keyDir = self::keyDirectory();
        if (!is_dir($keyDir)) {
            if (!mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
                throw new RuntimeException('Unable to create key directory: ' . $keyDir);
            }
        }
    }

    /**
     * Secure database access layer.
     *
     * Use application-scoped database credentials only.
     * Do not use root/admin credentials in application code.
     */
    public static function dbConnect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = self::env('DB_HOST', '127.0.0.1');
        $name = self::env('DB_NAME', 'app_db');
        $user = self::env('DB_USER', 'app_user');
        $pass = self::env('DB_PASS', '');
        $port = self::env('DB_PORT', '3306');
        $charset = self::env('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $pdo = new PDO(
            $dsn,
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $charset,
            ]
        );

        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        self::$pdo = $pdo;

        return self::$pdo;
    }

    /**
     * Fetch a single row using a prepared statement.
     *
     * Use this for read operations that return at most one record.
     * Example: user profile lookup, single configuration row, single record retrieval.
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
     * Fetch multiple rows using a prepared statement.
     *
     * This method automatically applies limit protection if the query does not contain LIMIT.
     * Keep queries indexed and avoid SELECT * for large tables.
     */
    public static function fetchAll(string $sql, array $params = [], int $limit = 1000): array
    {
        $query = trim($sql);
        if (stripos($query, 'LIMIT') === false) {
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
     * Insert a record only after credential validation.
     *
     * credentialKey is a server-side validation token used to block unauthorized write operations.
     * This must not be generated or trusted from the browser.
     */
    public static function insert(string $sql, array $params, string $credentialKey, string $scope = 'write'): int
    {
        self::assertCredentialKey($credentialKey, $scope);

        $stmt = self::dbConnect()->prepare($sql);
        self::bindParams($stmt, $params);
        $stmt->execute();

        return (int) self::dbConnect()->lastInsertId();
    }

    /**
     * Update records only when the caller presents a valid credential key for the scope.
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
     * Delete records only after validation-of-authority is complete.
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
     * Generic write executor.
     *
     * Use this for statements that do not return row counts but must still honor the credential key.
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
     * Generate a login token for authenticated user sessions.
     *
     * This token is intended for user login/session usage and should be different from
     * operational tokens used for data mutation commands.
     */
    public static function generateLoginToken(array $user): string
    {
        if (empty($user['id'])) {
            throw new InvalidArgumentException('User id is required for login token.');
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
     * Generate a one-time operation token for write or delete commands.
     *
     * Important:
     * - This token is distinct from login tokens.
     * - It is meant to authorize a single business action only.
     * - The token should be invalidated after use.
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
     * Validate a single-use operation token.
     *
     * A valid token must:
     * - have a valid signature
     * - not be expired
     * - not be previously used
     */
    public static function validateOperationToken(string $token, string $scope = 'write'): bool
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

        $decoded = self::base64UrlDecode($payloadB64);
        $data = json_decode($decoded, true);

        if (!is_array($data)) {
            return false;
        }

        if (($data['exp'] ?? 0) < time()) {
            return false;
        }

        if (($data['action'] ?? '') === '') {
            return false;
        }

        $used = self::tokenExists($token);
        if ($used) {
            return false;
        }

        self::markTokenUsed($token, true);

        return true;
    }

    /**
     * Encrypt a single string value.
     *
     * The key is loaded from the server filesystem, not from the repository source code.
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
            throw new RuntimeException('Single-value encryption failed.');
        }

        return self::base64UrlEncode($iv . $tag . $cipher);
    }

    /**
     * Decrypt a single string value.
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
                throw new RuntimeException('Single-value decryption failed.');
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
            throw new RuntimeException('Single-value decryption failed.');
        }

        return $plain;
    }

    /**
     * Encrypt a bulk payload: array/object converted to JSON and then encrypted.
     */
    public static function encryptBulk(array $payload, string $keyName): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::encryptSingle($json, $keyName);
    }

    /**
     * Decrypt a bulk payload back into an array.
     */
    public static function decryptBulk(string $cipherText, string $keyName): array
    {
        $json = self::decryptSingle($cipherText, $keyName);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Bulk payload is invalid or corrupted.');
        }

        return $decoded;
    }

    /**
     * Bind parameter values safely to a PDO statement.
     *
     * This keeps query generation consistent and prevents SQL injection by ensuring every value
     * is passed as a bound placeholder instead of string concatenation.
     */
    private static function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = PDO::PARAM_STR;

            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }

            $parameterName = $key;
            if (!str_starts_with((string) $parameterName, ':')) {
                $parameterName = ':' . $parameterName;
            }

            $stmt->bindValue($parameterName, $value, $type);
        }
    }

    /**
     * Validation gate for every write command.
     *
     * The credential key must be issued by the server and tied to a valid scope.
     */
    private static function assertCredentialKey(string $credentialKey, string $scope): void
    {
        if (!self::verifyCredentialKey($credentialKey, $scope)) {
            throw new RuntimeException('Invalid credential key for scope: ' . $scope);
        }
    }

    /**
     * Verify if the supplied credential key matches the expected server-generated value.
     */
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
     * Resolve the secure key location.
     *
     * This is intentionally outside the public web root to avoid accidental exposure via HTTP.
     */
    private static function keyDirectory(): string
    {
        $preferred = '/etc/myapp/keys';
        if (is_dir($preferred)) {
            return $preferred;
        }

        $fallback = __DIR__ . DIRECTORY_SEPARATOR . 'keys';
        if (!is_dir($fallback)) {
            return $preferred;
        }

        return $fallback;
    }

    /**
     * Build the full path to a secret file.
     */
    private static function secretPath(string $name): string
    {
        return self::keyDirectory() . DIRECTORY_SEPARATOR . $name . '.key';
    }

    /**
     * Load a secret from the filesystem.
     *
     * Keep permissions strict:
     * - file mode: 600
     * - owner: application/user only
     * - path: outside public web directory
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
     * Safe environment variable retrieval.
     *
     * Values should be supplied via environment, not hardcoded into the repository.
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
     * Token status management.
     *
     * A token should be one-time-only for operational actions.
     */
    private static function markTokenUsed(string $token, bool $used): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'app_cfg_tokens';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
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
     * Check whether a token has already been consumed.
     */
    private static function tokenExists(string $token): bool
    {
        $hash = hash('sha256', $token);
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'app_cfg_tokens' . DIRECTORY_SEPARATOR . $hash . '.token';

        return is_file($path);
    }
}

AppConfig::init();
