<?php
/**
 * =====================================================================
 *  COFFEE SHOP WEB SYSTEM  -  Config/db.php
 *  Project  : Forest & Cream Coffee (Web System and Cloud Computing - Finals)
 *  Database : coffeeshop   (admin/database/schema.sql)
 *  Engine   : MySQL 5.7+/8.x or MariaDB 10.4+ (XAMPP)  |  InnoDB, utf8mb4
 * =====================================================================
 *
 *  WHAT THIS FILE DOES
 *    1. Holds the database settings (XAMPP defaults, overridable with
 *       environment variables so the same code works on a cloud host).
 *    2. Opens ONE shared PDO connection (lazy - only when first needed).
 *    3. Provides small helper functions used by the Models/ files:
 *         db()                      -> the PDO connection
 *         db_query / db_all / db_row / db_val / db_exec / db_insert
 *         db_transaction($fn)       -> BEGIN ... COMMIT with automatic
 *                                      ROLLBACK, deadlock retry and
 *                                      nested (SAVEPOINT) support
 *         db_in_placeholders($arr)  -> "?,?,?" for IN (...) clauses
 *         db_like_escape($text)     -> safe text for LIKE searches
 *         db_is_duplicate_key($e)   -> detect UNIQUE-key collisions
 *                                      (idempotency tokens, coupon codes...)
 *         db_is_deadlock($e)
 *
 *  HOW TO USE (from any other file - note the capital "C" in Config)
 *      require_once __DIR__ . '/../Config/db.php';     // admin/, auth/, Cart/, Catalog/, Checkout/, Customer/, Homepage/, Models/, Emails/
 *
 *      $products = db_all('SELECT id, name FROM products WHERE status = ?', ['active']);
 *      $user     = db_row('SELECT * FROM users WHERE email = ?', [$email]);
 *      $count    = db_val('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$uid]);
 *
 *      // Stock / points / balance changes MUST be in a transaction
 *      // (schema.sql has no triggers or stored procedures):
 *      $orderId = db_transaction(function (PDO $pdo) use ($data) {
 *          $stock = db_val('SELECT quantity FROM branch_product_stock
 *                           WHERE branch_id = ? AND variant_id = ? FOR UPDATE',
 *                          [$data['branch_id'], $data['variant_id']]);
 *          // ... validate, INSERT order, UPDATE stock, INSERT inventory_transactions ...
 *          return db_insert('INSERT INTO orders (...) VALUES (...)', [...]);
 *      });
 *
 *  SECURITY NOTES
 *    * Always pass user input as parameters (the ? placeholders) - never
 *      concatenate it into the SQL string.
 *    * Real prepared statements are used (ATTR_EMULATE_PREPARES = false).
 *    * Database errors are written to the PHP error log and never shown
 *      to visitors (unless DB_DEBUG is turned on for local development).
 *    * This file cannot be run directly from the browser.
 * =====================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 0. Block direct browser access (http://localhost/CoffeeShop/Config/db.php)
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// 1. Settings  (XAMPP defaults: user "root", empty password)
//    To override without editing this file, set environment variables:
//    COFFEESHOP_DB_HOST, _PORT, _NAME, _USER, _PASS, _DEBUG
// ---------------------------------------------------------------------
if (!function_exists('db_env')) {
    /** Read an environment variable, falling back to a default. */
    function db_env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : (string) $value;
    }
}

if (!defined('DB_HOST'))     define('DB_HOST',     db_env('COFFEESHOP_DB_HOST', '127.0.0.1'));
if (!defined('DB_PORT'))     define('DB_PORT',     db_env('COFFEESHOP_DB_PORT', '3306'));   // change to 3307 if XAMPP MySQL uses another port
if (!defined('DB_NAME'))     define('DB_NAME',     db_env('COFFEESHOP_DB_NAME', 'coffeeshop'));
if (!defined('DB_USER'))     define('DB_USER',     db_env('COFFEESHOP_DB_USER', 'root'));
if (!defined('DB_PASS'))     define('DB_PASS',     db_env('COFFEESHOP_DB_PASS', ''));       // XAMPP root has no password by default
if (!defined('DB_CHARSET'))  define('DB_CHARSET',  'utf8mb4');
if (!defined('DB_COLLATION'))define('DB_COLLATION','utf8mb4_unicode_ci');                   // matches schema.sql
if (!defined('DB_TIMEZONE')) define('DB_TIMEZONE', '+08:00');                               // matches schema.sql (Philippines)
if (!defined('APP_TIMEZONE'))define('APP_TIMEZONE','Asia/Manila');

// Show detailed database errors in the browser? Keep 0 for the final demo/production.
if (!defined('DB_DEBUG'))    define('DB_DEBUG', db_env('COFFEESHOP_DB_DEBUG', '0') === '1');

// PHP and MySQL must agree on "now" (carts, coupons, promos, token expiry use dates).
date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// 2. The shared PDO connection
// ---------------------------------------------------------------------
if (!function_exists('db')) {
    /**
     * Returns the single shared PDO connection (created on first call).
     */
    function db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw PDOException on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows as associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Session settings (kept as plain SQL so they work on every PHP 8.x
            // version, MySQL 5.7/8.x and MariaDB 10.4+).
            //  - utf8mb4 + the same collation as schema.sql
            //  - Philippine time so NOW()/CURRENT_TIMESTAMP match PHP's clock
            //  - strict mode so bad data is rejected instead of silently cut
            $pdo->exec(
                "SET NAMES " . DB_CHARSET . " COLLATE " . DB_COLLATION . ", "
                . "time_zone = '" . DB_TIMEZONE . "', "
                . "sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
                . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
            );
        } catch (PDOException $e) {
            error_log('[CoffeeShop][DB] Connection failed: ' . $e->getMessage());

            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
            }

            if (DB_DEBUG) {
                exit('Database connection failed: ' . $e->getMessage()
                    . "\nCheck that MySQL is running in the XAMPP Control Panel and that the "
                    . "'" . DB_NAME . "' database was imported from admin/database/schema.sql.");
            }

            exit('The service is temporarily unavailable. Please try again later.');
        }

        return $pdo;
    }
}

// ---------------------------------------------------------------------
// 3. Query helpers (all use prepared statements)
// ---------------------------------------------------------------------
if (!function_exists('db_query')) {
    /**
     * Prepare + execute a statement and return the PDOStatement.
     *
     * @param array<int|string,mixed> $params positional (?) or named (:name) values
     */
    function db_query(string $sql, array $params = []): PDOStatement
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

if (!function_exists('db_all')) {
    /** All rows as an array of associative arrays (empty array if none). */
    function db_all(string $sql, array $params = []): array
    {
        return db_query($sql, $params)->fetchAll();
    }
}

if (!function_exists('db_row')) {
    /** First row as an associative array, or null if there is none. */
    function db_row(string $sql, array $params = []): ?array
    {
        $row = db_query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('db_val')) {
    /** First column of the first row (COUNT, SUM, a single field...), or null. */
    function db_val(string $sql, array $params = [])
    {
        $value = db_query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }
}

if (!function_exists('db_exec')) {
    /** Run INSERT/UPDATE/DELETE and return the number of affected rows. */
    function db_exec(string $sql, array $params = []): int
    {
        return db_query($sql, $params)->rowCount();
    }
}

if (!function_exists('db_insert')) {
    /** Run an INSERT and return the new AUTO_INCREMENT id. */
    function db_insert(string $sql, array $params = []): int
    {
        db_query($sql, $params);
        return (int) db()->lastInsertId();
    }
}

if (!function_exists('db_in_placeholders')) {
    /**
     * Build "?,?,?" for an IN (...) list.
     *   $ids = [3, 5, 9];
     *   db_all('SELECT * FROM products WHERE id IN (' . db_in_placeholders($ids) . ')', $ids);
     * Guard against an empty array before calling (IN () is invalid SQL).
     */
    function db_in_placeholders(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('db_in_placeholders() requires a non-empty array.');
        }
        return implode(',', array_fill(0, count($values), '?'));
    }
}

if (!function_exists('db_like_escape')) {
    /**
     * Escape %, _ and \ so user keywords match literally in LIKE searches.
     *   db_all('SELECT * FROM products WHERE name LIKE ?', ['%' . db_like_escape($q) . '%']);
     */
    function db_like_escape(string $text): string
    {
        return addcslashes($text, "\\%_");
    }
}

// ---------------------------------------------------------------------
// 4. Transactions
//    schema.sql has NO triggers/stored procedures, so every stock, points,
//    gift-card and wallet change must be wrapped in db_transaction() and
//    must also insert a row into the matching *_transactions table.
// ---------------------------------------------------------------------
if (!function_exists('db_transaction')) {
    /**
     * Run $callback(PDO $pdo) inside a transaction.
     *  - Commits when the callback returns, and returns its return value.
     *  - Rolls back and re-throws when the callback throws.
     *  - Retries the WHOLE callback (up to $maxAttempts) on a deadlock or
     *    lock-wait timeout, so keep callbacks free of side effects such as
     *    sending emails (send those AFTER db_transaction() returns).
     *  - If a transaction is already open, a SAVEPOINT is used so nested
     *    calls roll back only their own work.
     *
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    function db_transaction(callable $callback, int $maxAttempts = 3)
    {
        $pdo = db();

        // Nested call -> savepoint
        if ($pdo->inTransaction()) {
            static $savepointCounter = 0;
            $savepoint = 'sp_' . (++$savepointCounter);
            $pdo->exec('SAVEPOINT ' . $savepoint);
            try {
                $result = $callback($pdo);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                return $result;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                throw $e;
            }
        }

        // Top-level call -> real transaction with deadlock retry
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $pdo->beginTransaction();
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof PDOException && db_is_deadlock($e) && $attempt < max(1, $maxAttempts)) {
                    usleep(50000 * $attempt);   // back off 50 ms, 100 ms, ...
                    continue;
                }
                throw $e;
            }
        }
    }
}

// ---------------------------------------------------------------------
// 5. Error classification helpers
// ---------------------------------------------------------------------
if (!function_exists('db_error_code')) {
    /** The driver-specific (MySQL) error number of a PDOException, or 0. */
    function db_error_code(PDOException $e): int
    {
        return isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    }
}

if (!function_exists('db_is_duplicate_key')) {
    /**
     * True when the exception is a UNIQUE/PRIMARY key collision (MySQL 1062).
     * Use it for: duplicate email on registration, repeated order
     * idempotency_token, duplicate coupon code, repeated gift-card / wallet
     * `reference`, repeated notification `dedupe_key`, duplicate refund
     * idempotency_key, ...
     */
    function db_is_duplicate_key(PDOException $e): bool
    {
        return db_error_code($e) === 1062;
    }
}

if (!function_exists('db_is_deadlock')) {
    /** True for deadlock (1213) and lock-wait timeout (1205) errors. */
    function db_is_deadlock(PDOException $e): bool
    {
        return in_array(db_error_code($e), [1213, 1205], true);
    }
}

if (!function_exists('db_is_foreign_key_error')) {
    /** True for a foreign-key violation (1451 delete/update parent, 1452 insert child). */
    function db_is_foreign_key_error(PDOException $e): bool
    {
        return in_array(db_error_code($e), [1451, 1452], true);
    }
}

// ---------------------------------------------------------------------
// 6. Optional connectivity check (handy for a quick setup test)
//    Usage:  var_dump(db_ping());
// ---------------------------------------------------------------------
if (!function_exists('db_ping')) {
    /** True when the connection works and the schema's `users` table exists. */
    function db_ping(): bool
    {
        try {
            db_val('SELECT 1 FROM users LIMIT 1');
            return true;
        } catch (Throwable $e) {
            error_log('[CoffeeShop][DB] Ping failed: ' . $e->getMessage());
            return false;
        }
    }
}