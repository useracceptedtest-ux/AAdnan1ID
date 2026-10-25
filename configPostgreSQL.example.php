<?php

declare(strict_types=1);

/**
 * Example usage for configPostgreSQL.php
 *
 * Panduan singkat untuk developer baru:
 * 1. Pastikan file configPostgreSQL.php sudah ada di folder yang sama.
 * 2. Buat secret key pada server filesystem di luar document root.
 *    Contoh aman:
 *      /etc/myapp/keys/       (Linux)
 *      C:/myapp/keys/         (Windows)
 *
 *    File yang biasa dipakai:
 *      - login_secret.key
 *      - operation_secret.key
 *      - credential_write.key
 *      - credential_read.key
 *      - user_secret.key
 *
 * 3. Set permission file dengan chmod 600 (Linux) atau gunakan ACL / file permission terbatas pada Windows.
 * 4. Atur environment variable berikut di server atau .env:
 *      DB_DRIVER=pgsql
 *      DB_HOST=127.0.0.1
 *      DB_PORT=5432
 *      DB_NAME=app_db
 *      DB_USER=app_user
 *      DB_PASS=your_secure_password
 *      DB_CHARSET=utf8mb4
 *
 * 5. Pastikan user database punya hak akses minimal, bukan superuser.
 * 6. Untuk data besar, gunakan streamRows() dan bulkInsert() agar tidak overload memory.
 *
 * Catatan penting:
 * - Jangan simpan secret di repo Git.
 * - Jangan menaruh password di kode PHP.
 * - Gunakan credential key yang di-generate dari server untuk operasi write.
 * - Login token dan operasional token harus dipisah.
 *
 * ---------------------------------------------------------------------------
 * WINDOWS VERSION GUIDE
 * ---------------------------------------------------------------------------
 * Jika programmer bekerja di Windows, biasanya langkahnya seperti ini:
 *
 * 1) Buat folder secret manual di folder server atau folder project yang tidak dipublish:
 *      C:\myapp\keys
 *
 * 2) Simpan file berikut di folder tersebut:
 *      login_secret.key
 *      operation_secret.key
 *      credential_write.key
 *      credential_read.key
 *      user_secret.key
 *
 * 3) Isi file-file tersebut dengan string acak yang panjang dan aman.
 *    Contoh isi file:
 *      login_secret.key       => "4f5e9d72fd3a...randomvalue..."
 *      operation_secret.key   => "76ac1299ee91...randomvalue..."
 *
 * 4) Set environment variable di Windows PowerShell:
 *      $env:DB_DRIVER = "pgsql"
 *      $env:DB_HOST = "127.0.0.1"
 *      $env:DB_PORT = "5432"
 *      $env:DB_NAME = "app_db"
 *      $env:DB_USER = "app_user"
 *      $env:DB_PASS = "StrongPassword123"
 *      $env:DB_CHARSET = "utf8mb4"
 *
 *   Atau via Command Prompt:
 *      set DB_DRIVER=pgsql
 *      set DB_HOST=127.0.0.1
 *      set DB_PORT=5432
 *      set DB_NAME=app_db
 *      set DB_USER=app_user
 *      set DB_PASS=StrongPassword123
 *      set DB_CHARSET=utf8mb4
 *
 * 5) Jika proyek Anda memakai .env, buat file .env seperti ini:
 *      DB_DRIVER=pgsql
 *      DB_HOST=127.0.0.1
 *      DB_PORT=5432
 *      DB_NAME=app_db
 *      DB_USER=app_user
 *      DB_PASS=StrongPassword123
 *      DB_CHARSET=utf8mb4
 *
 * 6) Ketika PHP berjalan di Windows, file configPostgreSQL.php akan membaca env dari:
 *      getenv('DB_HOST')
 *      $_ENV['DB_HOST']
 *
 * 7) Jika server Windows Anda memakai IIS atau XAMPP, pastikan:
 *      - PDO PostgreSQL extension aktif
 *      - pgsql extension sudah terinstall
 *      - service PostgreSQL aktif
 *      - port 5432 terbuka
 *
 * 8) Untuk programmer yang masih pemula, langkah paling aman adalah:
 *      - gunakan folder secret di luar project
 *      - jangan menaruh file key di folder public_html / wwwroot
 *      - jangan commit ke Git
 *
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/configPostgreSQL.php';

/**
 * ---------------------------------------------------------------------------
 * STEP 1: Siapkan secret key secara manual di server.
 * ---------------------------------------------------------------------------
 * Contoh di server Linux:
 *   sudo mkdir -p /etc/myapp/keys
 *   sudo touch /etc/myapp/keys/login_secret.key
 *   sudo touch /etc/myapp/keys/operation_secret.key
 *   sudo touch /etc/myapp/keys/credential_write.key
 *   sudo touch /etc/myapp/keys/credential_read.key
 *   sudo touch /etc/myapp/keys/user_secret.key
 *
 *   sudo chown www-data:www-data /etc/myapp/keys
 *   sudo chmod 700 /etc/myapp/keys
 *   sudo chmod 600 /etc/myapp/keys/*.key
 *
 * Contoh di Windows:
 *   mkdir C:\myapp\keys
 *   notepad C:\myapp\keys\login_secret.key
 *   notepad C:\myapp\keys\operation_secret.key
 *   notepad C:\myapp\keys\credential_write.key
 *   notepad C:\myapp\keys\credential_read.key
 *   notepad C:\myapp\keys\user_secret.key
 *
 * Isi isi file-filenya dengan value random seperti:
 *   login_secret.key        => string acak panjang 32+ karakter
 *   operation_secret.key    => string acak panjang 32+ karakter
 *   credential_write.key    => string acak panjang 32+ karakter
 *   credential_read.key     => string acak panjang 32+ karakter
 *   user_secret.key         => string acak panjang 32+ karakter
 *
 * ---------------------------------------------------------------------------
 * STEP 2: Set environment variable server.
 * ---------------------------------------------------------------------------
 * Contoh di Linux /etc/environment atau .env:
 *   DB_DRIVER=pgsql
 *   DB_HOST=127.0.0.1
 *   DB_PORT=5432
 *   DB_NAME=app_db
 *   DB_USER=app_user
 *   DB_PASS=veryStrongPassword123
 *
 * Contoh di Windows PowerShell:
 *   $env:DB_DRIVER = "pgsql"
 *   $env:DB_HOST = "127.0.0.1"
 *   $env:DB_PORT = "5432"
 *   $env:DB_NAME = "app_db"
 *   $env:DB_USER = "app_user"
 *   $env:DB_PASS = "StrongPassword123"
 *   $env:DB_CHARSET = "utf8mb4"
 *
 * ---------------------------------------------------------------------------
 * STEP 3: Gunakan fungsi yang tersedia di AppConfigPostgreSQL.
 * ---------------------------------------------------------------------------
 */

try {
    // 1. Koneksi database aman.
    $pdo = AppConfigPostgreSQL::dbConnect();

    // 2. Contoh query membaca satu data.
    $user = AppConfigPostgreSQL::fetchOne(
        'SELECT * FROM users WHERE id = :id',
        ['id' => 1]
    );

    echo "User data hasil query single:\n";
    print_r($user);

    // 3. Contoh query membaca banyak data dengan batas aman.
    $users = AppConfigPostgreSQL::fetchAll(
        'SELECT * FROM users WHERE status = :status ORDER BY id ASC',
        ['status' => 'active'],
        100
    );

    echo "\nData user hasil query bulk:\n";
    foreach ($users as $row) {
        echo $row['id'] . ' - ' . ($row['name'] ?? 'No Name') . PHP_EOL;
    }

    // 4. Contoh generate credential key untuk operasional write.
    $writeCredentialKey = AppConfigPostgreSQL::generateCredentialKey('write');
    echo "\nCredential key (write): " . $writeCredentialKey . PHP_EOL;

    // 5. Contoh generate login token.
    $loginToken = AppConfigPostgreSQL::generateLoginToken([
        'id' => 1,
        'role' => 'admin',
    ]);

    echo "\nLogin token: " . $loginToken . PHP_EOL;

    // 6. Contoh generate operation token satu kali pakai.
    $operationToken = AppConfigPostgreSQL::generateOperationToken(
        'create_user',
        [
            'name' => 'Ahmad',
            'email' => 'ahmad@example.com',
            'status' => 'active',
        ],
        $writeCredentialKey,
        'write'
    );

    echo "\nOperation token: " . $operationToken . PHP_EOL;

    // 7. Contoh validasi operation token.
    $isValidOperationToken = AppConfigPostgreSQL::validateOperationToken($operationToken);
    echo "\nValidasi operation token: " . ($isValidOperationToken ? 'TRUE' : 'FALSE') . PHP_EOL;

    // 8. Contoh insert data dengan credential key.
    $newUserId = AppConfigPostgreSQL::insert(
        'INSERT INTO users (name, email, status) VALUES (:name, :email, :status)',
        [
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'status' => 'active',
        ],
        $writeCredentialKey,
        'write'
    );

    echo "\nID user baru hasil insert: " . $newUserId . PHP_EOL;

    // 9. Contoh update data.
    $affectedRows = AppConfigPostgreSQL::update(
        'UPDATE users SET status = :status WHERE id = :id',
        [
            'status' => 'inactive',
            'id' => $newUserId,
        ],
        $writeCredentialKey,
        'write'
    );

    echo "\nJumlah row ter-update: " . $affectedRows . PHP_EOL;

    // 10. Contoh delete data.
    $deletedRows = AppConfigPostgreSQL::delete(
        'DELETE FROM users WHERE id = :id',
        ['id' => $newUserId],
        $writeCredentialKey,
        'write'
    );

    echo "\nJumlah row ter-delete: " . $deletedRows . PHP_EOL;

    // 11. Contoh enkripsi data tunggal.
    $plainText = 'data-sensitif-123';
    $encryptedText = AppConfigPostgreSQL::encryptSingle($plainText, 'user_secret');
    echo "\nEncrypted single data: " . $encryptedText . PHP_EOL;

    $decryptedText = AppConfigPostgreSQL::decryptSingle($encryptedText, 'user_secret');
    echo "Decrypted single data: " . $decryptedText . PHP_EOL;

    // 12. Contoh enkripsi data bulk.
    $bulkData = [
        'user_id' => 7,
        'name' => 'Hana',
        'email' => 'hana@example.com',
        'roles' => ['admin', 'editor'],
    ];

    $encryptedBulk = AppConfigPostgreSQL::encryptBulk($bulkData, 'user_secret');
    $decryptedBulk = AppConfigPostgreSQL::decryptBulk($encryptedBulk, 'user_secret');

    echo "\nBulk data hasil decrypt: ";
    print_r($decryptedBulk);

    // 13. Contoh streaming data besar.
    echo "\nStreaming data besar demo:\n";
    foreach (AppConfigPostgreSQL::streamRows('SELECT id, name FROM users ORDER BY id ASC LIMIT 10') as $row) {
        echo $row['id'] . ' - ' . $row['name'] . PHP_EOL;
    }

    // 14. Contoh transaction multi-step.
    AppConfigPostgreSQL::transaction(function (PDO $pdo) {
        $stmt1 = $pdo->prepare('INSERT INTO users (name, email, status) VALUES (:name, :email, :status)');
        $stmt1->execute([
            'name' => 'Transaction User',
            'email' => 'transaction.user@example.com',
            'status' => 'active',
        ]);

        $stmt2 = $pdo->prepare('UPDATE users SET status = :status WHERE email = :email');
        $stmt2->execute([
            'status' => 'verified',
            'email' => 'transaction.user@example.com',
        ]);
    });

    echo "\nTransaction selesai dengan sukses." . PHP_EOL;
} catch (Throwable $e) {
    echo "\nTerjadi error: " . $e->getMessage() . PHP_EOL;
    echo "Stack trace:\n" . $e->getTraceAsString() . PHP_EOL;
}

/**
 * ---------------------------------------------------------------------------
 * CATATAN PENTING UNTUK DEVELOPER BARU
 * ---------------------------------------------------------------------------
 * - Fungsi fetchOne() dan fetchAll() aman karena memakai prepared statement.
 * - Gunakan transaction() saat pekerjaan harus all-or-nothing.
 * - Gunakan streamRows() untuk dataset yang besar agar memory tetap aman.
 * - Gunakan bulkInsert() untuk import data massal dengan chunking.
 * - Jangan pernah mengirim credential key dari browser langsung tanpa server-side validation.
 * - Jangan pernah hardcode password, secret, atau token di repository.
 * - Di Windows, pastikan PostgreSQL extension dan PDO PostgreSQL aktif.
 * - Di Windows, simpan secret di folder yang tidak dipubikasikan seperti C:\myapp\keys.
 * ---------------------------------------------------------------------------
 */
