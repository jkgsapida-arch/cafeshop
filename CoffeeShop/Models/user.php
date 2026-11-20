<?php
/**
 * =====================================================================
 *  COFFEE SHOP WEB SYSTEM  -  Models/user.php
 *  Project  : Forest & Cream Coffee (Web System and Cloud Computing - Finals)
 *  Database : coffeeshop   (admin/database/schema.sql)
 *  Needs    : PHP 7.4+ (works on PHP 8.x) | Config/db.php | no Composer packages
 * =====================================================================
 *
 *  WHAT THIS FILE DOES  (features 4, 5, 6, 38, 39, 40, 41-support, 51, 52, 53, 54)
 *    The single model for everything about PEOPLE: customers, administrators,
 *    staff and fulfillment employees.
 *
 *    1.  Validation + security helpers   user_valid_email, user_password_errors, user_csrf_*
 *    2.  Registration                    user_register (creates user + preferences + wallet +
 *                                        loyalty account + signup bonus in ONE transaction)
 *    3.  E-mail verification             user_create_verification_token, user_verify_email
 *    4.  Password reset / change         user_request_password_reset, user_reset_password,
 *                                        user_change_password
 *    5.  Login, lockout, sessions        user_authenticate, user_login, user_login_session,
 *                                        user_logout, user_current, user_require_login/role/permission
 *    6.  Roles and permissions           user_can, user_permission_codes, user_set_permission_override
 *    7.  Profile                         user_update_profile, user_change_email, user_save_profile_image
 *    8.  Account status                  user_set_status, user_unlock_account, user_self_deactivate
 *    9.  Saved addresses                 user_addresses, user_save_address, user_default_address ...
 *    10. Saved payment methods (masked)  user_add_payment_method ...
 *    11. Preferences + notification prefs user_save_preferences, user_save_notification_preferences
 *    12. Favorites + saved drinks        user_favorite_toggle, user_save_customization ...
 *    13. Dashboard readers               user_dashboard_summary, user_available_coupons,
 *                                        user_loyalty_summary, user_available_rewards,
 *                                        user_redeem_reward, user_wallet, user_notifications
 *    14. Admin: customers                user_search_customers, user_customer_detail
 *    15. Admin: employees                user_create_employee, user_update_employee,
 *                                        user_change_employee_role, user_search_employees ...
 *    16. Audit log writer                user_audit
 *
 *  HOW TO USE (note the capital "M" in Models and "C" in Config)
 *      require_once __DIR__ . '/../Models/user.php';      // also loads Config/db.php
 *
 *      // ---- auth/register.php
 *      user_csrf_require();
 *      $r = user_register($_POST);                          // validates, creates, e-mails
 *      if (!$r['ok']) { $errors = $r['errors']; }           // ['email' => '...', 'password' => '...']
 *
 *      // ---- auth/login.php
 *      user_csrf_require();
 *      $r = user_login($_POST['email'] ?? '', $_POST['password'] ?? '');
 *      if ($r['ok']) {
 *          // Merge the guest cart: the guest cart row still carries the OLD session id
 *          // (session ids are regenerated on login to stop fixation). Pass it to Models/cart.php:
 *          //     cart_merge_guest($r['user']['id'], $r['previous_session_id']);
 *          user_redirect(user_safe_next($_POST['next'] ?? null) ?? $r['redirect']);
 *      }
 *
 *      // ---- any protected page
 *      $me = user_require_login();                          // redirects to /auth/login.php?next=...
 *      user_require_role(['admin', 'staff']);
 *      user_require_permission('products.edit');            // 403 page / JSON when missing
 *
 *  RETURN STYLE
 *      Every action function returns an array and never lets a validation problem
 *      throw:   ['ok' => bool, 'errors' => [field => message], 'error' => first message|null, ...extra]
 *      Unexpected database failures still throw PDOException (let your page handle them).
 *
 *  SECURITY NOTES
 *    * Passwords: bcrypt (cost 12) through password_hash(); never stored or logged raw.
 *    * Verification / reset tokens: only the SHA-256 hash is stored (schema design rule).
 *    * Login: per-account lockout (users.locked_until) + per-IP throttle + uniform error
 *      messages (no account enumeration) + constant-time dummy hash for unknown e-mails.
 *    * Sessions: HttpOnly + SameSite=Lax cookie, id regenerated on login, idle + absolute
 *      timeouts, user-agent fingerprint, and the account is re-read from the database on
 *      every request so a deactivated/suspended account loses access immediately.
 *    * Cards: only brand + last4 + expiry + holder are saved. user_card_info() reads a card
 *      number only to derive brand/last4 and Luhn-validate it; the number is never stored.
 *    * Admin mutations check the ACTING employee's permission inside the model (defence in
 *      depth) and write to audit_logs / employee_account_history.
 *    * This file cannot be run from the browser.
 *
 *  OPTIONAL system_settings KEYS READ BY THIS FILE (defaults apply when a key is missing,
 *  so NO schema change is needed; add the rows later in Admin > Settings if you want them)
 *      password_min_length (8)          max_login_attempts (5)        login_lockout_minutes (15)
 *      email_verification_expiry_hours (24)   password_reset_expiry_minutes (60)
 *      loyalty_enabled (1)              max_image_upload_mb (2)       allowed_image_types (jpg,jpeg,png,webp)
 *      require_email_verification (0)   session_idle_minutes (120)    session_max_hours (12)
 * =====================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 0. Block direct browser access (http://localhost/CoffeeShop/Models/user.php)
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../Config/db.php';   // db_* helpers + Asia/Manila timezone

// ---------------------------------------------------------------------
// 1. Constants
// ---------------------------------------------------------------------
if (!defined('USER_ALL_ROLES'))       define('USER_ALL_ROLES',       ['customer', 'admin', 'staff', 'fulfillment']);
if (!defined('USER_EMPLOYEE_ROLES'))  define('USER_EMPLOYEE_ROLES',  ['admin', 'staff', 'fulfillment']);
if (!defined('USER_STATUSES'))        define('USER_STATUSES',        ['active', 'inactive', 'suspended']);
if (!defined('USER_SESSION_KEY'))     define('USER_SESSION_KEY',     'cs_auth');             // $_SESSION key for the logged-in user
if (!defined('USER_CSRF_KEY'))        define('USER_CSRF_KEY',        'cs_csrf');             // $_SESSION key for the CSRF token
if (!defined('USER_PREV_SESSION_KEY'))define('USER_PREV_SESSION_KEY','cs_prev_session_id');  // guest session id before login (cart merge)
if (!defined('USER_MAX_ADDRESSES'))   define('USER_MAX_ADDRESSES',   20);
if (!defined('USER_MAX_PAYMENT_METHODS')) define('USER_MAX_PAYMENT_METHODS', 10);
if (!defined('USER_MAX_SAVED_DRINKS'))define('USER_MAX_SAVED_DRINKS',30);
// A valid bcrypt hash (of the demo password) used ONLY to burn the same CPU time when a login
// e-mail does not exist, so response time does not reveal which e-mails are registered.
if (!defined('USER_DUMMY_HASH'))      define('USER_DUMMY_HASH',      '$2y$12$T5zr2V2hz8ca9wE5hs.gr.IGW4Jk0IVjRDy5BujsYqR1xX5FbtFMG');

// ---------------------------------------------------------------------
// 2. Small helpers: results, text, settings
// ---------------------------------------------------------------------
if (!function_exists('user_ok')) {
    /** Success result. Extra keys are merged in: user_ok(['user_id' => 5]). */
    function user_ok(array $extra = []): array
    {
        return ['ok' => true, 'errors' => [], 'error' => null] + $extra;
    }
}

if (!function_exists('user_fail')) {
    /** Failure result. $errors = 'message' or ['field' => 'message', ...]. */
    function user_fail($errors, array $extra = []): array
    {
        if (is_string($errors)) {
            $errors = ['_' => $errors];
        }
        $errors = (array) $errors;
        $first  = $errors === [] ? 'The request could not be completed.' : (string) reset($errors);
        return ['ok' => false, 'errors' => $errors, 'error' => $first] + $extra;
    }
}

if (!function_exists('user_e')) {
    /** HTML-escape text for output. */
    function user_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('user_cut')) {
    /** Cut a string to $max characters (UTF-8 safe). */
    function user_cut(string $value, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }
}

if (!function_exists('user_strlen')) {
    function user_strlen(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

if (!function_exists('user_clean')) {
    /** Trim and strip control characters (keeps normal UTF-8 text such as "Dasmariñas"). */
    function user_clean($value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value);
        return trim($value);
    }
}

if (!function_exists('user_now')) {
    function user_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('user_setting')) {
    /** Value from system_settings, or $default when the key is missing/empty. */
    function user_setting(string $key, string $default = ''): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (db_all('SELECT setting_key, setting_value FROM system_settings') as $row) {
                    $cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
                }
            } catch (Throwable $e) {
                error_log('[CoffeeShop][USER] Could not read system_settings: ' . $e->getMessage());
            }
        }
        return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
    }
}

if (!function_exists('user_setting_int')) {
    function user_setting_int(string $key, int $default): int
    {
        return (int) user_setting($key, (string) $default);
    }
}

// ---------------------------------------------------------------------
// 3. Validation helpers
// ---------------------------------------------------------------------
if (!function_exists('user_normalize_email')) {
    function user_normalize_email(string $email): string
    {
        return strtolower(user_clean($email));
    }
}

if (!function_exists('user_valid_email')) {
    function user_valid_email(string $email): bool
    {
        return $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('user_normalize_phone')) {
    /** "0917 123-4567" -> "09171234567";  "+63 917 123 4567" -> "+639171234567". */
    function user_normalize_phone(string $phone): string
    {
        $phone = user_clean($phone);
        $plus  = substr($phone, 0, 1) === '+';
        $digits = (string) preg_replace('/\D+/', '', $phone);
        return $digits === '' ? '' : ($plus ? '+' . $digits : $digits);
    }
}

if (!function_exists('user_valid_phone')) {
    /** 7-15 digits, optional leading +. Pass the NORMALIZED number. */
    function user_valid_phone(string $phone): bool
    {
        return preg_match('/^\+?[0-9]{7,15}$/', $phone) === 1;
    }
}

if (!function_exists('user_valid_date')) {
    /** Y-m-d, a real calendar date. */
    function user_valid_date(string $date): bool
    {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        $e = DateTime::getLastErrors();
        return $d !== false && $d->format('Y-m-d') === $date
            && ($e === false || ($e['warning_count'] === 0 && $e['error_count'] === 0));
    }
}

if (!function_exists('user_validate_name')) {
    /** Validate a person's name. Returns the cleaned value; adds a message to $errors[$key] when bad. */
    function user_validate_name(string $value, string $label, array &$errors, string $key, int $max = 80): string
    {
        $value = user_clean($value);
        if ($value === '') {
            $errors[$key] = $label . ' is required.';
        } elseif (user_strlen($value) > $max) {
            $errors[$key] = $label . ' must be ' . $max . ' characters or fewer.';
        } elseif (preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]*$/u', $value) !== 1) {
            $errors[$key] = $label . ' contains invalid characters.';
        }
        return $value;
    }
}

if (!function_exists('user_password_errors')) {
    /** List of password-rule violations (empty array = password is acceptable). */
    function user_password_errors(string $password, string $email = ''): array
    {
        $errors = [];
        $min = max(8, user_setting_int('password_min_length', 8));
        if (strlen($password) < $min) {
            $errors[] = 'Password must be at least ' . $min . ' characters.';
        }
        if (strlen($password) > 72) {   // bcrypt only uses the first 72 bytes
            $errors[] = 'Password must be 72 characters or fewer.';
        }
        if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must include an uppercase letter, a lowercase letter and a number.';
        }
        $lower = strtolower($password);
        if (($email !== '' && $lower === strtolower($email))
            || in_array($lower, ['password1', 'password123', 'qwerty123', '12345678', 'admin12345', 'letmein123'], true)) {
            $errors[] = 'Please choose a password that is harder to guess.';
        }
        return $errors;
    }
}

if (!function_exists('user_hash_password')) {
    function user_hash_password(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);   // matches the cost used by the seed data
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed.');
        }
        return $hash;
    }
}

if (!function_exists('user_generate_token')) {
    /** 64-character hex token (URL-safe, accepted by mail_valid_token()). */
    function user_generate_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('user_token_hash')) {
    function user_token_hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}

if (!function_exists('user_valid_token_format')) {
    function user_valid_token_format(string $token): bool
    {
        return $token !== '' && strlen($token) <= 255 && preg_match('/^[A-Za-z0-9_\-]+$/', $token) === 1;
    }
}

// ---------------------------------------------------------------------
// 4. Request helpers: URLs, redirects, IP, CSRF
// ---------------------------------------------------------------------
if (!function_exists('user_trust_proxy')) {
    /** Set environment variable COFFEESHOP_TRUST_PROXY=1 only when the site is behind a trusted reverse proxy / load balancer. */
    function user_trust_proxy(): bool
    {
        return getenv('COFFEESHOP_TRUST_PROXY') === '1';
    }
}

if (!function_exists('user_client_ip')) {
    function user_client_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (user_trust_proxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $first = trim((string) $parts[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                $ip = $first;
            }
        }
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}

if (!function_exists('user_is_https')) {
    function user_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return user_trust_proxy() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}

if (!function_exists('user_base_path')) {
    /**
     * Web path of the project root, e.g. "/CoffeeShop" under XAMPP, "" when deployed at a domain root.
     * Override with define('USER_BASE_PATH', '/shop') or the COFFEESHOP_BASE_PATH environment variable.
     */
    function user_base_path(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }
        $override = defined('USER_BASE_PATH') ? (string) USER_BASE_PATH : (string) getenv('COFFEESHOP_BASE_PATH');
        if ($override !== '') {
            $trimmed = trim($override, '/');
            return $base = ($trimmed === '' ? '' : '/' . $trimmed);
        }
        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $root    = realpath(dirname(__DIR__));
        if ($docRoot !== false && $root !== false) {
            $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
            $root    = str_replace('\\', '/', $root);
            if ($docRoot !== '' && strpos($root, $docRoot) === 0) {
                return $base = rtrim(substr($root, strlen($docRoot)), '/');
            }
        }
        return $base = '/CoffeeShop';
    }
}

if (!function_exists('user_url')) {
    /** Site-relative URL: user_url('/auth/login.php'). */
    function user_url(string $path = ''): string
    {
        return user_base_path() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('user_safe_next')) {
    /** Only allow redirects to a local path (blocks open redirects such as //evil.com or https://evil.com). */
    function user_safe_next(?string $next): ?string
    {
        $next = trim((string) $next);
        if ($next === '' || $next[0] !== '/' || substr($next, 0, 2) === '//' || strpos($next, '\\') !== false
            || preg_match('/[\x00-\x1F]/', $next) === 1 || preg_match('#^/[A-Za-z][A-Za-z0-9+.\-]*:#', $next) === 1) {
            return null;
        }
        return $next;
    }
}

if (!function_exists('user_login_url')) {
    function user_login_url(?string $next = null): string
    {
        $url  = user_url('/auth/login.php');
        $next = user_safe_next($next);
        return $next !== null ? $url . '?next=' . rawurlencode($next) : $url;
    }
}

if (!function_exists('user_home_url')) {
    /** Where a freshly logged-in user of this role should land. */
    function user_home_url(string $role): string
    {
        switch ($role) {
            case 'admin':
            case 'staff':
                return user_url('/admin/index.php');
            case 'fulfillment':
                return user_url('/admin/orders.php');   // fulfillment has no dashboard.view permission
            default:
                return user_url('/Customer/dashboard.php');
        }
    }
}

if (!function_exists('user_redirect')) {
    function user_redirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . str_replace(["\r", "\n"], '', $url));
        }
        exit;
    }
}

if (!function_exists('user_wants_json')) {
    function user_wants_json(): bool
    {
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        return $xhr || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    }
}

if (!function_exists('user_deny')) {
    /** Stop the request with 401/403 (JSON for AJAX calls, a small page otherwise). */
    function user_deny(int $status = 403, string $message = 'You do not have permission to access this page.'): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . (user_wants_json() ? 'application/json' : 'text/html') . '; charset=utf-8');
        }
        if (user_wants_json()) {
            echo json_encode(['ok' => false, 'error' => $message]);
        } else {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Access denied</title></head>'
                . '<body style="font-family:Arial,sans-serif;background:#F6F0E4;color:#2B2B2B;text-align:center;padding:64px 16px;">'
                . '<h1 style="color:#2F4F3A;">' . ($status === 401 ? 'Please log in' : 'Access denied') . '</h1>'
                . '<p>' . user_e($message) . '</p><p><a href="' . user_e(user_url('/')) . '" style="color:#2F4F3A;">Back to home</a></p>'
                . '</body></html>';
        }
        exit;
    }
}

// --- CSRF (feature 52) ------------------------------------------------
if (!function_exists('user_csrf_token')) {
    function user_csrf_token(): string
    {
        user_session_start();
        if (empty($_SESSION[USER_CSRF_KEY]) || !is_string($_SESSION[USER_CSRF_KEY])) {
            $_SESSION[USER_CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[USER_CSRF_KEY];
    }
}

if (!function_exists('user_csrf_field')) {
    /** Hidden input to place inside every state-changing <form method="post">. */
    function user_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . user_e(user_csrf_token()) . '">';
    }
}

if (!function_exists('user_csrf_verify')) {
    /** Checks $_POST['csrf_token'] (or the X-CSRF-Token header for AJAX) against the session token. */
    function user_csrf_verify(?string $token = null): bool
    {
        user_session_start();
        $expected = (string) ($_SESSION[USER_CSRF_KEY] ?? '');
        $given    = $token ?? (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }
}

if (!function_exists('user_csrf_require')) {
    /** Call at the top of every POST handler. Stops with 403 when the token is missing or wrong. */
    function user_csrf_require(): void
    {
        if (!user_csrf_verify()) {
            user_deny(403, 'Your form expired or is invalid. Please go back, refresh the page and try again.');
        }
    }
}

// ---------------------------------------------------------------------
// 5. Reading users
// ---------------------------------------------------------------------
if (!function_exists('user_columns')) {
    /** Safe column list (everything except password_hash). */
    function user_columns(string $alias = 'u'): string
    {
        $cols = ['id', 'role', 'first_name', 'last_name', 'email', 'phone', 'status', 'email_verified_at',
                 'date_of_birth', 'profile_image', 'failed_login_count', 'locked_until', 'last_login_at',
                 'last_login_ip', 'deactivated_at', 'created_at', 'updated_at'];
        return implode(', ', array_map(static function (string $c) use ($alias): string {
            return $alias . '.' . $c;
        }, $cols));
    }
}

if (!function_exists('user_decorate')) {
    /** Remove secrets and add convenience keys: full_name, is_employee, is_verified. */
    function user_decorate(array $row): array
    {
        unset($row['password_hash'], $row['is_locked'], $row['lock_minutes']);
        $row['id']          = (int) ($row['id'] ?? 0);
        $row['full_name']   = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $row['is_employee'] = in_array((string) ($row['role'] ?? ''), USER_EMPLOYEE_ROLES, true);
        $row['is_verified'] = !empty($row['email_verified_at']);
        return $row;
    }
}

if (!function_exists('user_find')) {
    function user_find(int $userId): ?array
    {
        $row = db_row('SELECT ' . user_columns() . ' FROM users u WHERE u.id = ?', [$userId]);
        return $row === null ? null : user_decorate($row);
    }
}

if (!function_exists('user_find_by_email')) {
    function user_find_by_email(string $email): ?array
    {
        $row = db_row('SELECT ' . user_columns() . ' FROM users u WHERE u.email = ?', [user_normalize_email($email)]);
        return $row === null ? null : user_decorate($row);
    }
}

if (!function_exists('user_email_available')) {
    function user_email_available(string $email, ?int $exceptUserId = null): bool
    {
        $email = user_normalize_email($email);
        $id = db_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) $exceptUserId]);
        return $id === null;
    }
}

if (!function_exists('user_branch_active')) {
    function user_branch_active(int $branchId): bool
    {
        return db_val('SELECT 1 FROM branches WHERE id = ? AND is_active = 1 AND archived_at IS NULL', [$branchId]) !== null;
    }
}

// ---------------------------------------------------------------------
// 6. Audit + history writers (feature 53 / 39)
// ---------------------------------------------------------------------
if (!function_exists('user_scrub')) {
    /** Remove secrets before anything is written to audit_logs. */
    function user_scrub($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/pass|token|secret|cvv|cvc|card_number/i', $k) === 1) {
                $value[$k] = '[hidden]';
            } else {
                $value[$k] = user_scrub($v);
            }
        }
        return $value;
    }
}

if (!function_exists('user_actor_id')) {
    /** The employee/admin performing an action: explicit id, else the logged-in user, else null (CLI/system). */
    function user_actor_id(?int $actorId = null): ?int
    {
        if ($actorId !== null) {
            return $actorId > 0 ? $actorId : null;
        }
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            return user_id();
        }
        return null;
    }
}

if (!function_exists('user_audit')) {
    /** Write one audit_logs row. Never throws. */
    function user_audit(string $action, string $entityType, ?int $entityId, $old = null, $new = null, ?int $actorId = null, ?int $orderId = null): void
    {
        try {
            $json = static function ($v): ?string {
                return $v === null ? null : (string) json_encode(user_scrub($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            };
            db_insert(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, order_id, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    user_actor_id($actorId),
                    user_cut($action, 80),
                    user_cut($entityType, 60),
                    $entityId,
                    $json($old),
                    $json($new),
                    $orderId,
                    PHP_SAPI === 'cli' ? null : user_client_ip(),
                    PHP_SAPI === 'cli' ? null : user_cut((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
                ]
            );
        } catch (Throwable $e) {
            error_log('[CoffeeShop][USER] Audit log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('user_employee_history_add')) {
    /** @param string $type created|updated|role_changed|branch_changed|activated|deactivated|password_reset */
    function user_employee_history_add(int $employeeUserId, string $type, ?int $changedBy, ?string $old = null, ?string $new = null, ?string $notes = null): void
    {
        try {
            db_insert(
                'INSERT INTO employee_account_history (employee_user_id, changed_by, change_type, old_value, new_value, notes)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$employeeUserId, user_actor_id($changedBy), $type,
                 $old !== null ? user_cut($old, 255) : null,
                 $new !== null ? user_cut($new, 255) : null,
                 $notes !== null ? user_cut($notes, 255) : null]
            );
        } catch (Throwable $e) {
            error_log('[CoffeeShop][USER] Employee history failed: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// 7. Mail bridge (Config/mail.php is loaded only when an e-mail is actually sent)
//    E-mails are ALWAYS sent after the database work has committed.
// ---------------------------------------------------------------------
if (!function_exists('user_mail')) {
    /** Call a mail_send_* function from Config/mail.php. Never throws; returns the mailer's result array. */
    function user_mail(string $function, ...$args): array
    {
        try {
            if (!function_exists($function)) {
                $file = __DIR__ . '/../Config/mail.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
            if (!function_exists($function)) {
                return ['ok' => false, 'status' => 'failed', 'id' => null, 'error' => 'Mailer function ' . $function . ' is not available.'];
            }
            return $function(...$args);
        } catch (Throwable $e) {
            error_log('[CoffeeShop][USER] Mail call ' . $function . ' failed: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'failed', 'id' => null, 'error' => $e->getMessage()];
        }
    }
}

// ---------------------------------------------------------------------
// 8. Customer starter records + registration  (features 4, 14, 18, 54)
// ---------------------------------------------------------------------
if (!function_exists('user_ensure_customer_records')) {
    /**
     * Make sure a customer has customer_preferences, a wallet and (when loyalty is enabled) a
     * loyalty account. Safe to call repeatedly. The signup bonus is awarded only when the
     * loyalty account is created by THIS call. Run it inside db_transaction() when possible.
     *
     * @param array $opts marketing_consent (bool)
     * @return array{preferences:bool,wallet:bool,loyalty:bool,bonus_transaction_ids:int[]}
     */
    function user_ensure_customer_records(int $userId, array $opts = []): array
    {
        $created = ['preferences' => false, 'wallet' => false, 'loyalty' => false, 'bonus_transaction_ids' => []];

        if (db_val('SELECT 1 FROM customer_preferences WHERE user_id = ?', [$userId]) === null) {
            $consent = !empty($opts['marketing_consent']) ? 1 : 0;
            $created['preferences'] = db_exec(
                'INSERT IGNORE INTO customer_preferences (user_id, marketing_consent, marketing_consent_at) VALUES (?, ?, ?)',
                [$userId, $consent, $consent === 1 ? user_now() : null]
            ) > 0;
        }

        if (db_val('SELECT 1 FROM wallets WHERE user_id = ?', [$userId]) === null) {
            $created['wallet'] = db_exec('INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)', [$userId]) > 0;
        }

        if (user_setting('loyalty_enabled', '1') === '1' && db_val('SELECT 1 FROM loyalty_accounts WHERE user_id = ?', [$userId]) === null) {
            $tierId = db_val('SELECT id FROM loyalty_tiers WHERE is_active = 1 ORDER BY min_lifetime_points ASC, id ASC LIMIT 1');
            $accountId = db_insert(
                'INSERT INTO loyalty_accounts (user_id, tier_id, points_balance, lifetime_points_earned) VALUES (?, ?, 0, 0)',
                [$userId, $tierId !== null ? (int) $tierId : null]
            );
            if ($accountId > 0) {
                $created['loyalty'] = true;
                $rules = db_all(
                    "SELECT id, name, points_awarded, points_expire_days FROM loyalty_rules
                     WHERE rule_type = 'signup_bonus' AND is_active = 1
                       AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
                     ORDER BY id"
                );
                $balance = 0;
                foreach ($rules as $rule) {
                    $points = (int) $rule['points_awarded'];
                    if ($points <= 0) {
                        continue;
                    }
                    $balance += $points;
                    $days = $rule['points_expire_days'] !== null ? max(1, (int) $rule['points_expire_days']) : null;
                    $created['bonus_transaction_ids'][] = db_insert(
                        'INSERT INTO loyalty_transactions
                            (loyalty_account_id, transaction_type, points, balance_after, rule_id, description, expires_at)
                         VALUES (?, \'bonus\', ?, ?, ?, ?, ' . ($days !== null ? 'NOW() + INTERVAL ' . $days . ' DAY' : 'NULL') . ')',
                        [$accountId, $points, $balance, (int) $rule['id'], user_cut((string) $rule['name'], 255)]
                    );
                }
                if ($balance > 0) {
                    db_exec(
                        'UPDATE loyalty_accounts SET points_balance = ?, lifetime_points_earned = ? WHERE id = ?',
                        [$balance, $balance, $accountId]
                    );
                }
            }
        }

        return $created;
    }
}

if (!function_exists('user_validate_registration')) {
    /**
     * @param array $options phone_required (bool, default true)
     * @return array{errors:array,clean:array}
     */
    function user_validate_registration(array $data, array $options = []): array
    {
        $errors = [];
        $first = user_validate_name((string) ($data['first_name'] ?? ''), 'First name', $errors, 'first_name');
        $last  = user_validate_name((string) ($data['last_name'] ?? ''), 'Last name', $errors, 'last_name');

        $email = user_normalize_email((string) ($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!user_valid_email($email)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        $phone = user_normalize_phone((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            if (($options['phone_required'] ?? true) !== false) {
                $errors['phone'] = 'Contact number is required.';
            }
        } elseif (!user_valid_phone($phone)) {
            $errors['phone'] = 'Please enter a valid contact number (7-15 digits).';
        }

        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } else {
            $problems = user_password_errors($password, $email);
            if ($problems !== []) {
                $errors['password'] = $problems[0];
            }
        }
        if (array_key_exists('password_confirm', $data) && (string) $data['password_confirm'] !== $password) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        $dob = user_clean((string) ($data['date_of_birth'] ?? ''));
        if ($dob !== '') {
            if (!user_valid_date($dob) || $dob > date('Y-m-d') || $dob < '1900-01-01') {
                $errors['date_of_birth'] = 'Please enter a valid date of birth.';
            }
        }

        return ['errors' => $errors, 'clean' => [
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'phone' => $phone !== '' ? $phone : null, 'password' => $password,
            'date_of_birth' => $dob !== '' ? $dob : null,
            'marketing_consent' => !empty($data['marketing_consent']),
        ]];
    }
}

if (!function_exists('user_register')) {
    /**
     * Register a CUSTOMER account (feature 4).
     *
     * $data: first_name, last_name, email, phone, password, password_confirm,
     *        date_of_birth (optional), marketing_consent (optional checkbox)
     * $options: send_emails (bool, default true: welcome + verification e-mail after commit),
     *           phone_required (bool, default true), auto_verify (bool, default false)
     *
     * @return array ok, errors, user_id, user, verification_token (raw, for resending manually), mail
     */
    function user_register(array $data, array $options = []): array
    {
        $check = user_validate_registration($data, $options);
        if ($check['errors'] !== []) {
            return user_fail($check['errors']);
        }
        $c = $check['clean'];

        try {
            $userId = db_transaction(static function (PDO $pdo) use ($c, $options): int {
                $id = db_insert(
                    "INSERT INTO users (role, first_name, last_name, email, phone, password_hash, status, email_verified_at, date_of_birth)
                     VALUES ('customer', ?, ?, ?, ?, ?, 'active', ?, ?)",
                    [$c['first_name'], $c['last_name'], $c['email'], $c['phone'], user_hash_password($c['password']),
                     !empty($options['auto_verify']) ? user_now() : null, $c['date_of_birth']]
                );
                user_ensure_customer_records($id, ['marketing_consent' => $c['marketing_consent']]);
                return $id;
            });
        } catch (PDOException $e) {
            if (db_is_duplicate_key($e)) {
                return user_fail(['email' => 'An account with this email address already exists.']);
            }
            throw $e;
        }

        user_audit('customer.register', 'users', $userId, null, ['email' => $c['email']], $userId);

        $token = null;
        $mail  = [];
        if (empty($options['auto_verify'])) {
            $token = user_create_verification_token($userId);
        }
        if (($options['send_emails'] ?? true) !== false) {
            $mail['welcome'] = user_mail('mail_send_welcome', $userId);
            if ($token !== null) {
                $mail['verification'] = user_mail('mail_send_verification', $userId, $token);
            }
        }

        return user_ok(['user_id' => $userId, 'user' => user_find($userId), 'verification_token' => $token, 'mail' => $mail]);
    }
}

// ---------------------------------------------------------------------
// 9. E-mail verification  (features 4, 12, 51)
// ---------------------------------------------------------------------
if (!function_exists('user_create_verification_token')) {
    /** Create a fresh verification token (older unverified ones are removed). Returns the RAW token. */
    function user_create_verification_token(int $userId): string
    {
        $raw   = user_generate_token();
        $hours = max(1, user_setting_int('email_verification_expiry_hours', 24));
        db_transaction(static function (PDO $pdo) use ($userId, $raw, $hours): void {
            db_exec('DELETE FROM email_verifications WHERE user_id = ? AND verified_at IS NULL', [$userId]);
            db_insert(
                'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL ' . $hours . ' HOUR)',
                [$userId, user_token_hash($raw)]
            );
        });
        return $raw;
    }
}

if (!function_exists('user_resend_verification')) {
    /** "Resend verification e-mail" button. 60-second cooldown per account. */
    function user_resend_verification(int $userId, bool $sendEmail = true): array
    {
        $user = user_find($userId);
        if ($user === null || $user['status'] !== 'active') {
            return user_fail('Account not found.');
        }
        if ($user['is_verified']) {
            return user_ok(['already_verified' => true]);
        }
        $recent = db_val('SELECT 1 FROM email_verifications WHERE user_id = ? AND verified_at IS NULL AND created_at > (NOW() - INTERVAL 60 SECOND)', [$userId]);
        if ($recent !== null) {
            return user_fail('A verification e-mail was just sent. Please wait a minute before requesting another.', ['throttled' => true]);
        }
        $token = user_create_verification_token($userId);
        $mail  = $sendEmail ? user_mail('mail_send_verification', $userId, $token) : null;
        return user_ok(['verification_token' => $token, 'mail' => $mail]);
    }
}

if (!function_exists('user_verify_email')) {
    /** Handle the link in the verification e-mail (auth/verify-email.php?token=...). */
    function user_verify_email(string $rawToken): array
    {
        $invalid = 'This verification link is invalid or has expired. Please request a new one.';
        if (!user_valid_token_format($rawToken)) {
            return user_fail($invalid);
        }
        $hash = user_token_hash($rawToken);

        $result = db_transaction(static function (PDO $pdo) use ($hash): array {
            $row = db_row(
                'SELECT id, user_id, verified_at, (expires_at < NOW()) AS expired FROM email_verifications WHERE token_hash = ? FOR UPDATE',
                [$hash]
            );
            if ($row === null) {
                return ['status' => 'invalid'];
            }
            if ($row['verified_at'] !== null) {
                return ['status' => 'used', 'user_id' => (int) $row['user_id']];
            }
            if ((int) $row['expired'] === 1) {
                return ['status' => 'expired'];
            }
            db_exec('UPDATE email_verifications SET verified_at = NOW() WHERE id = ?', [(int) $row['id']]);
            db_exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [(int) $row['user_id']]);
            return ['status' => 'ok', 'user_id' => (int) $row['user_id']];
        });

        if ($result['status'] === 'ok') {
            user_audit('customer.email_verified', 'users', (int) $result['user_id'], null, null, (int) $result['user_id']);
            return user_ok(['user_id' => (int) $result['user_id']]);
        }
        if ($result['status'] === 'used') {
            return user_ok(['user_id' => (int) $result['user_id'], 'already_verified' => true]);
        }
        return user_fail($invalid);
    }
}

// ---------------------------------------------------------------------
// 10. Password reset and change  (features 4, 12, 51)
// ---------------------------------------------------------------------
if (!function_exists('user_create_reset_token')) {
    /** Revoke older unused reset tokens and create a new one. Returns the RAW token. */
    function user_create_reset_token(int $userId, ?string $ip = null, ?int $minutes = null): string
    {
        $raw     = user_generate_token();
        $minutes = max(5, $minutes ?? user_setting_int('password_reset_expiry_minutes', 60));
        db_transaction(static function (PDO $pdo) use ($userId, $raw, $minutes, $ip): void {
            db_exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
            db_insert(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (?, ?, NOW() + INTERVAL ' . $minutes . ' MINUTE, ?)',
                [$userId, user_token_hash($raw), $ip !== null ? user_cut($ip, 45) : null]
            );
        });
        return $raw;
    }
}

if (!function_exists('user_request_password_reset')) {
    /**
     * "Forgot password" form. ALWAYS returns ok = true with the same shape so the page can show
     * one neutral message ("If that e-mail is registered, we sent a link") and nobody can probe
     * which e-mails exist. At most 5 requests per account per hour.
     *
     * @param array $opts send_email (bool, default true), ip (string)
     */
    function user_request_password_reset(string $email, array $opts = []): array
    {
        $result = user_ok(['user' => null, 'token' => null, 'throttled' => false, 'mail' => null]);
        $email  = user_normalize_email($email);
        if (!user_valid_email($email)) {
            return $result;
        }
        $row = db_row("SELECT id FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($row === null) {
            return $result;
        }
        $userId = (int) $row['id'];
        $recent = (int) db_val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$userId]);
        if ($recent >= 5) {
            $result['throttled'] = true;
            return $result;
        }
        $ip    = (string) ($opts['ip'] ?? (PHP_SAPI === 'cli' ? '' : user_client_ip()));
        $token = user_create_reset_token($userId, $ip !== '' ? $ip : null);
        $result['user']  = user_find($userId);
        $result['token'] = $token;
        if (($opts['send_email'] ?? true) !== false) {
            $result['mail'] = user_mail('mail_send_password_reset', $userId, $token);
        }
        return $result;
    }
}

if (!function_exists('user_validate_reset_token')) {
    /** For auth/reset-password.php GET: is the link still usable? Returns the user row or null. */
    function user_validate_reset_token(string $rawToken): ?array
    {
        if (!user_valid_token_format($rawToken)) {
            return null;
        }
        $row = db_row(
            "SELECT pr.user_id FROM password_resets pr JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = 'active'",
            [user_token_hash($rawToken)]
        );
        return $row === null ? null : user_find((int) $row['user_id']);
    }
}

if (!function_exists('user_reset_password')) {
    /** Complete a password reset: single-use, expiring token (feature 4). */
    function user_reset_password(string $rawToken, string $newPassword, string $confirm): array
    {
        $invalid = 'This password reset link is invalid or has expired. Please request a new one.';
        if (!user_valid_token_format($rawToken)) {
            return user_fail($invalid);
        }
        $hash = user_token_hash($rawToken);

        return db_transaction(static function (PDO $pdo) use ($hash, $newPassword, $confirm, $invalid): array {
            $row = db_row(
                'SELECT pr.id, pr.user_id, pr.used_at, (pr.expires_at < NOW()) AS expired, u.email, u.status, u.role
                 FROM password_resets pr JOIN users u ON u.id = pr.user_id
                 WHERE pr.token_hash = ? FOR UPDATE',
                [$hash]
            );
            if ($row === null || $row['used_at'] !== null || (int) $row['expired'] === 1 || $row['status'] !== 'active') {
                return user_fail($invalid);
            }
            $problems = user_password_errors($newPassword, (string) $row['email']);
            if ($problems !== []) {
                return user_fail(['password' => $problems[0]]);
            }
            if ($newPassword !== $confirm) {
                return user_fail(['password_confirm' => 'Passwords do not match.']);
            }
            $userId = (int) $row['user_id'];
            db_exec('UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?', [user_hash_password($newPassword), $userId]);
            db_exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
            if (in_array((string) $row['role'], USER_EMPLOYEE_ROLES, true)) {
                user_employee_history_add($userId, 'password_reset', null, null, null, 'Password reset by the employee through the e-mail link');
            }
            user_audit('user.password_reset', 'users', $userId, null, null, $userId);
            return user_ok(['user_id' => $userId]);
        });
    }
}

if (!function_exists('user_change_password')) {
    /** Logged-in password change from the profile page. */
    function user_change_password(int $userId, string $currentPassword, string $newPassword, string $confirm): array
    {
        $row = db_row('SELECT email, password_hash FROM users WHERE id = ?', [$userId]);
        if ($row === null) {
            return user_fail('Account not found.');
        }
        if (!password_verify($currentPassword, (string) $row['password_hash'])) {
            return user_fail(['current_password' => 'Your current password is incorrect.']);
        }
        $problems = user_password_errors($newPassword, (string) $row['email']);
        if ($problems !== []) {
            return user_fail(['password' => $problems[0]]);
        }
        if ($newPassword !== $confirm) {
            return user_fail(['password_confirm' => 'Passwords do not match.']);
        }
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [user_hash_password($newPassword), $userId]);
        db_exec('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
        user_audit('user.password_change', 'users', $userId, null, null, $userId);
        if (session_status() === PHP_SESSION_ACTIVE && user_id() === $userId) {
            session_regenerate_id(true);
        }
        return user_ok();
    }
}

// ---------------------------------------------------------------------
// 11. Login, lockout and sessions  (features 4, 51)
// ---------------------------------------------------------------------
if (!function_exists('user_log_login_attempt')) {
    function user_log_login_attempt(?int $userId, string $email, string $ip, ?string $userAgent, bool $success, ?string $reason): void
    {
        try {
            db_insert(
                'INSERT INTO login_attempts (user_id, email, ip_address, user_agent, was_successful, failure_reason) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, user_cut($email, 190), user_cut($ip, 45), $userAgent !== null ? user_cut($userAgent, 255) : null,
                 $success ? 1 : 0, $reason !== null ? user_cut($reason, 100) : null]
            );
        } catch (Throwable $e) {
            error_log('[CoffeeShop][USER] Could not log login attempt: ' . $e->getMessage());
        }
    }
}

if (!function_exists('user_lock_message')) {
    function user_lock_message(int $minutes): string
    {
        $minutes = max(1, $minutes);
        return 'Too many failed login attempts. Please try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
    }
}

if (!function_exists('user_authenticate')) {
    /**
     * Check e-mail + password. Does NOT start a session (see user_login()).
     *
     * @param array $opts allowed_roles (string[]|null: only these roles may sign in here),
     *                    require_verified (bool: default = system_settings require_email_verification),
     *                    ip, user_agent
     * @return array ok, errors, error, user (on success), needs_verification (bool)
     */
    function user_authenticate(string $email, string $password, array $opts = []): array
    {
        $ip    = (string) ($opts['ip'] ?? user_client_ip());
        $ua    = user_cut((string) ($opts['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 255);
        $email = user_normalize_email($email);
        $max   = max(1, user_setting_int('max_login_attempts', 5));
        $mins  = max(1, user_setting_int('login_lockout_minutes', 15));
        $generic = 'Incorrect email or password.';

        $fail = static function (string $reason, string $message, ?int $userId = null, array $extra = []) use ($email, $ip, $ua): array {
            user_log_login_attempt($userId, $email, $ip, $ua, false, $reason);
            return user_fail($message, $extra);
        };

        if ($email === '' || $password === '') {
            return user_fail('Please enter your email and password.');
        }
        if (!user_valid_email($email) || strlen($password) > 4096) {
            return $fail('invalid_input', $generic);
        }

        // Per-IP throttle (slows credential stuffing across many accounts).
        $ipFails = (int) db_val(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND was_successful = 0 AND (failure_reason IS NULL OR failure_reason <> 'ip_throttled')
               AND attempted_at > (NOW() - INTERVAL {$mins} MINUTE)",
            [$ip]
        );
        if ($ipFails >= $max * 4) {
            return $fail('ip_throttled', 'Too many failed login attempts from your network. Please try again in a few minutes.');
        }

        $user = db_row(
            'SELECT u.*, (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked,
                    GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, NOW(), u.locked_until) / 60)) AS lock_minutes
             FROM users u WHERE u.email = ?',
            [$email]
        );

        // Unknown e-mail: same timing, same message, same lockout behaviour as a real account.
        if ($user === null) {
            password_verify($password, USER_DUMMY_HASH);
            $recent = (int) db_val(
                "SELECT COUNT(*) FROM login_attempts WHERE email = ? AND failure_reason = 'unknown_email'
                 AND attempted_at > (NOW() - INTERVAL {$mins} MINUTE)",
                [$email]
            );
            return $fail('unknown_email', $recent >= $max ? user_lock_message($mins) : $generic);
        }

        $userId = (int) $user['id'];

        if ((int) $user['is_locked'] === 1) {
            password_verify($password, USER_DUMMY_HASH);
            return $fail('account_locked', user_lock_message((int) $user['lock_minutes']), $userId);
        }
        if ($user['locked_until'] !== null || (int) $user['failed_login_count'] >= $max) {
            // Lockout period is over: start counting from zero again.
            db_exec('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
            $user['failed_login_count'] = 0;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $count = (int) $user['failed_login_count'] + 1;
            if ($count >= $max) {
                db_exec("UPDATE users SET failed_login_count = ?, locked_until = NOW() + INTERVAL {$mins} MINUTE WHERE id = ?", [$count, $userId]);
                return $fail('bad_password_locked', user_lock_message($mins), $userId);
            }
            db_exec('UPDATE users SET failed_login_count = ? WHERE id = ?', [$count, $userId]);
            return $fail('bad_password', $generic, $userId);
        }

        // ---- password is correct; now account-level checks ----
        if ($user['status'] === 'inactive') {
            return $fail('account_inactive', 'This account has been deactivated. Please contact support to reactivate it.', $userId);
        }
        if ($user['status'] === 'suspended') {
            return $fail('account_suspended', 'This account is suspended. Please contact support.', $userId);
        }
        if (in_array((string) $user['role'], USER_EMPLOYEE_ROLES, true)) {
            $employment = db_val('SELECT employment_status FROM employee_profiles WHERE user_id = ?', [$userId]);
            if ($employment === 'terminated') {
                return $fail('employee_terminated', 'This employee account is no longer active.', $userId);
            }
        }
        $allowed = $opts['allowed_roles'] ?? null;
        if (is_array($allowed) && !in_array((string) $user['role'], $allowed, true)) {
            return $fail('wrong_portal', 'This account cannot sign in here.', $userId);
        }
        $requireVerified = array_key_exists('require_verified', $opts)
            ? (bool) $opts['require_verified'] : user_setting('require_email_verification', '0') === '1';
        if ($requireVerified && $user['role'] === 'customer' && $user['email_verified_at'] === null) {
            return $fail('email_not_verified', 'Please verify your email address before logging in. Check your inbox for the verification link.', $userId,
                ['needs_verification' => true, 'user_id' => $userId]);
        }

        // ---- success ----
        db_exec('UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [user_cut($ip, 45), $userId]);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [user_hash_password($password), $userId]);
        }
        user_log_login_attempt($userId, $email, $ip, $ua, true, null);

        $clean = user_decorate($user);
        return user_ok(['user' => $clean, 'needs_verification' => !$clean['is_verified']]);
    }
}

if (!function_exists('user_session_start')) {
    function user_session_start(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return false;
        }
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => user_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return session_start();
    }
}

if (!function_exists('user_fingerprint')) {
    function user_fingerprint(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}

if (!function_exists('user_login_session')) {
    /**
     * Put an authenticated user into the session. Regenerates the session id (session fixation
     * protection). Returns the PREVIOUS (guest) session id because carts.session_id still holds it:
     * hand it to Models/cart.php to merge the guest cart into the user's cart.
     *
     * @param array $user a row returned by user_authenticate()
     */
    function user_login_session(array $user): string
    {
        user_session_start();
        $previous = (string) session_id();
        session_regenerate_id(true);
        $_SESSION[USER_SESSION_KEY] = [
            'id'            => (int) $user['id'],
            'role'          => (string) $user['role'],
            'login_at'      => time(),
            'last_activity' => time(),
            'fp'            => user_fingerprint(),
        ];
        $_SESSION[USER_PREV_SESSION_KEY] = $previous;
        unset($_SESSION[USER_CSRF_KEY]);   // new CSRF token for the new session
        user_current(true);
        return $previous;
    }
}

if (!function_exists('user_login')) {
    /**
     * One call for auth/login.php: authenticate + start the session.
     * On success the result also holds previous_session_id (cart merge) and redirect (role home page).
     */
    function user_login(string $email, string $password, array $opts = []): array
    {
        $result = user_authenticate($email, $password, $opts);
        if ($result['ok']) {
            $result['previous_session_id'] = user_login_session($result['user']);
            $result['redirect']            = user_home_url((string) $result['user']['role']);
        }
        return $result;
    }
}

if (!function_exists('user_logout')) {
    function user_logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !user_session_start()) {
            return;
        }
        $_SESSION = [];
        user_current(true);   // clears the per-request cache
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => (bool) $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }
}

if (!function_exists('user_current')) {
    /**
     * The logged-in user (row from user_find) or null. Re-checks the database once per request,
     * so a deactivated account or changed role takes effect immediately.
     */
    function user_current(bool $refresh = false): ?array
    {
        static $cache = false;
        if ($refresh) {
            $cache = false;
        }
        if ($cache !== false) {
            return $cache;
        }
        $cache = null;
        if (!user_session_start()) {
            return null;
        }
        $s = $_SESSION[USER_SESSION_KEY] ?? null;
        if (!is_array($s) || empty($s['id'])) {
            return null;
        }

        $idle = max(5, user_setting_int('session_idle_minutes', 120)) * 60;
        $life = max(1, user_setting_int('session_max_hours', 12)) * 3600;
        $expired = (time() - (int) ($s['last_activity'] ?? 0) > $idle)
            || (time() - (int) ($s['login_at'] ?? 0) > $life)
            || !hash_equals((string) ($s['fp'] ?? ''), user_fingerprint());
        if ($expired) {
            unset($_SESSION[USER_SESSION_KEY]);
            return null;
        }

        $user = user_find((int) $s['id']);
        if ($user === null || $user['status'] !== 'active') {
            unset($_SESSION[USER_SESSION_KEY]);
            return null;
        }
        $_SESSION[USER_SESSION_KEY]['last_activity'] = time();
        $_SESSION[USER_SESSION_KEY]['role']          = $user['role'];
        return $cache = $user;
    }
}

if (!function_exists('user_id')) {
    function user_id(): ?int
    {
        $u = user_current();
        return $u === null ? null : (int) $u['id'];
    }
}

if (!function_exists('user_role')) {
    function user_role(): ?string
    {
        $u = user_current();
        return $u === null ? null : (string) $u['role'];
    }
}

if (!function_exists('user_is_logged_in')) {
    function user_is_logged_in(): bool
    {
        return user_current() !== null;
    }
}

if (!function_exists('user_require_login')) {
    /** Page guard: returns the user, or redirects to the login page (401 JSON for AJAX). */
    function user_require_login(): array
    {
        $user = user_current();
        if ($user !== null) {
            return $user;
        }
        if (user_wants_json()) {
            user_deny(401, 'Please log in to continue.');
        }
        $next = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') ? (string) ($_SERVER['REQUEST_URI'] ?? '') : '';
        user_redirect(user_login_url($next));
        return [];   // unreachable (user_redirect exits)
    }
}

if (!function_exists('user_require_role')) {
    /** @param string|string[] $roles */
    function user_require_role($roles): array
    {
        $user = user_require_login();
        if (!in_array((string) $user['role'], (array) $roles, true)) {
            user_deny(403);
        }
        return $user;
    }
}

if (!function_exists('user_require_customer')) {
    function user_require_customer(): array
    {
        return user_require_role('customer');
    }
}

if (!function_exists('user_require_staff')) {
    /** Any employee role (admin, staff, fulfillment): the admin portal. */
    function user_require_staff(): array
    {
        return user_require_role(USER_EMPLOYEE_ROLES);
    }
}

if (!function_exists('user_require_permission')) {
    function user_require_permission(string $code): array
    {
        $user = user_require_login();
        if (!user_can($code, (int) $user['id'])) {
            user_deny(403);
        }
        return $user;
    }
}

// ---------------------------------------------------------------------
// 12. Roles and permissions  (feature 40)
//     effective permission = per-user override (user_permissions) when present,
//     otherwise the role's default (role_permissions). Admins have every permission
//     unless a user_permissions row explicitly revokes one.
// ---------------------------------------------------------------------
if (!function_exists('user_permission_codes')) {
    /** @return string[] permission codes the user currently holds */
    function user_permission_codes(int $userId, bool $refresh = false): array
    {
        static $cache = [];
        if ($refresh) {
            unset($cache[$userId]);
        }
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        $user = db_row('SELECT role, status FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] !== 'active') {
            return $cache[$userId] = [];
        }
        $role = (string) $user['role'];
        $rows = db_all(
            'SELECT p.code FROM permissions p
             LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.role = ?
             LEFT JOIN user_permissions up ON up.permission_id = p.id AND up.user_id = ?
             WHERE COALESCE(up.is_granted, CASE WHEN ? = 1 THEN 1 WHEN rp.permission_id IS NOT NULL THEN 1 ELSE 0 END) = 1
             ORDER BY p.code',
            [$role, $userId, $role === 'admin' ? 1 : 0]
        );
        return $cache[$userId] = array_map(static function (array $r): string {
            return (string) $r['code'];
        }, $rows);
    }
}

if (!function_exists('user_can')) {
    /** Does this user (default: the logged-in one) hold a permission? e.g. user_can('orders.update_status'). */
    function user_can(string $code, ?int $userId = null): bool
    {
        $userId = $userId ?? user_id();
        if ($userId === null) {
            return false;
        }
        return in_array($code, user_permission_codes($userId), true);
    }
}

if (!function_exists('user_actor_denied')) {
    /**
     * Defence in depth for admin mutations. Returns a failure array when the acting user lacks the
     * permission, or null when allowed. A null actor is allowed only from the command line.
     */
    function user_actor_denied(?int $actorId, string $permission): ?array
    {
        if ($actorId === null) {
            return PHP_SAPI === 'cli' ? null : user_fail('You must be logged in to do that.');
        }
        return user_can($permission, $actorId) ? null : user_fail('You do not have permission to do that.');
    }
}

if (!function_exists('user_all_permissions')) {
    /** Every permission grouped by module (for the admin permission editor). */
    function user_all_permissions(): array
    {
        $grouped = [];
        foreach (db_all('SELECT id, code, name, module, description FROM permissions ORDER BY module, code') as $p) {
            $grouped[(string) $p['module']][] = $p;
        }
        return $grouped;
    }
}

if (!function_exists('user_permission_overrides')) {
    /** @return array<string,bool> code => granted (1) or revoked (0) for one user */
    function user_permission_overrides(int $userId): array
    {
        $out = [];
        foreach (db_all('SELECT p.code, up.is_granted FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ? ORDER BY p.code', [$userId]) as $r) {
            $out[(string) $r['code']] = (int) $r['is_granted'] === 1;
        }
        return $out;
    }
}

if (!function_exists('user_set_permission_override')) {
    /** $granted: true = add, false = revoke, null = remove the override (back to the role default). */
    function user_set_permission_override(int $userId, string $code, ?bool $granted, ?int $actorId = null): array
    {
        $actorId = user_actor_id($actorId);
        if (($denied = user_actor_denied($actorId, 'employees.manage')) !== null) {
            return $denied;
        }
        $target = db_row('SELECT role FROM users WHERE id = ?', [$userId]);
        $permId = db_val('SELECT id FROM permissions WHERE code = ?', [$code]);
        if ($target === null || $permId === null) {
            return user_fail('Employee or permission not found.');
        }
        if ($target['role'] === 'customer') {
            return user_fail('Permissions can only be set for employees.');
        }
        $old = user_permission_overrides($userId);
        if ($granted === null) {
            db_exec('DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?', [$userId, (int) $permId]);
        } else {
            db_exec(
                'INSERT INTO user_permissions (user_id, permission_id, is_granted, granted_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted), granted_by = VALUES(granted_by)',
                [$userId, (int) $permId, $granted ? 1 : 0, $actorId]
            );
        }
        user_permission_codes($userId, true);
        user_audit('employee.permission_override', 'users', $userId, $old, user_permission_overrides($userId), $actorId);
        return user_ok();
    }
}

if (!function_exists('user_role_permission_codes')) {
    /** @return string[] default permission codes of a role */
    function user_role_permission_codes(string $role): array
    {
        return array_map(static function (array $r): string {
            return (string) $r['code'];
        }, db_all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role = ? ORDER BY p.code', [$role]));
    }
}

if (!function_exists('user_set_role_permissions')) {
    /** Replace the default permission set of staff / fulfillment (admin always keeps everything). */
    function user_set_role_permissions(string $role, array $codes, ?int $actorId = null): array
    {
        $actorId = user_actor_id($actorId);
        if (($denied = user_actor_denied($actorId, 'employees.manage')) !== null) {
            return $denied;
        }
        if (!in_array($role, ['staff', 'fulfillment'], true)) {
            return user_fail('Only the staff and fulfillment roles can be customised.');
        }
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $ids = [];
        if ($codes !== []) {
            $ids = array_map('intval', array_column(db_all('SELECT id FROM permissions WHERE code IN (' . db_in_placeholders($codes) . ')', $codes), 'id'));
            if (count($ids) !== count($codes)) {
                return user_fail('One or more permissions do not exist.');
            }
        }
        $old = user_role_permission_codes($role);
        db_transaction(static function (PDO $pdo) use ($role, $ids): void {
            db_exec('DELETE FROM role_permissions WHERE role = ?', [$role]);
            foreach ($ids as $id) {
                db_exec('INSERT INTO role_permissions (role, permission_id) VALUES (?, ?)', [$role, $id]);
            }
        });
        // Permission caches are per request; nothing else to clear.
        user_audit('role.permissions_update', 'role_permissions', null, ['role' => $role, 'codes' => $old], ['role' => $role, 'codes' => $codes], $actorId);
        return user_ok();
    }
}

// ---------------------------------------------------------------------
// 13. Profile  (feature 5)
// ---------------------------------------------------------------------
if (!function_exists('user_actor_can_manage')) {
    /** When an admin edits ANOTHER account: customers.manage for customers, employees.manage for employees. */
    function user_actor_can_manage(?int $actorId, int $targetUserId): ?array
    {
        $role = (string) db_val('SELECT role FROM users WHERE id = ?', [$targetUserId]);
        return user_actor_denied($actorId, $role === 'customer' ? 'customers.manage' : 'employees.manage');
    }
}

if (!function_exists('user_update_profile')) {
    /**
     * Update first/last name, phone and date of birth. E-mail has its own function.
     * When $actorId is another user (an admin editing a customer) the permission is checked and
     * the change is audited.
     */
    function user_update_profile(int $userId, array $data, ?int $actorId = null): array
    {
        $before = db_row('SELECT first_name, last_name, phone, date_of_birth FROM users WHERE id = ?', [$userId]);
        if ($before === null) {
            return user_fail('Account not found.');
        }
        $actor = user_actor_id($actorId);
        $byAdmin = $actor !== null && $actor !== $userId;
        if ($byAdmin && ($denied = user_actor_can_manage($actor, $userId)) !== null) {
            return $denied;
        }

        $errors = [];
        $first = user_validate_name((string) ($data['first_name'] ?? $before['first_name']), 'First name', $errors, 'first_name');
        $last  = user_validate_name((string) ($data['last_name'] ?? $before['last_name']), 'Last name', $errors, 'last_name');

        $phone = array_key_exists('phone', $data) ? user_normalize_phone((string) $data['phone']) : (string) ($before['phone'] ?? '');
        if ($phone !== '' && !user_valid_phone($phone)) {
            $errors['phone'] = 'Please enter a valid contact number (7-15 digits).';
        }
        $dob = array_key_exists('date_of_birth', $data) ? user_clean((string) $data['date_of_birth']) : (string) ($before['date_of_birth'] ?? '');
        if ($dob !== '' && (!user_valid_date($dob) || $dob > date('Y-m-d') || $dob < '1900-01-01')) {
            $errors['date_of_birth'] = 'Please enter a valid date of birth.';
        }
        if ($errors !== []) {
            return user_fail($errors);
        }

        db_exec('UPDATE users SET first_name = ?, last_name = ?, phone = ?, date_of_birth = ? WHERE id = ?',
            [$first, $last, $phone !== '' ? $phone : null, $dob !== '' ? $dob : null, $userId]);
        if ($byAdmin) {
            user_audit('customer.update', 'users', $userId, $before,
                ['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'date_of_birth' => $dob], $actor);
        }
        return user_ok(['user' => user_find($userId)]);
    }
}

if (!function_exists('user_change_email')) {
    /**
     * Customer changes their e-mail (needs the current password). The new address becomes
     * unverified and a verification e-mail is sent to it. Returns the raw token.
     */
    function user_change_email(int $userId, string $newEmail, string $currentPassword, bool $sendEmail = true): array
    {
        $row = db_row('SELECT email, password_hash FROM users WHERE id = ?', [$userId]);
        if ($row === null) {
            return user_fail('Account not found.');
        }
        if (!password_verify($currentPassword, (string) $row['password_hash'])) {
            return user_fail(['current_password' => 'Your current password is incorrect.']);
        }
        $new = user_normalize_email($newEmail);
        if (!user_valid_email($new)) {
            return user_fail(['email' => 'Please enter a valid email address.']);
        }
        if ($new === strtolower((string) $row['email'])) {
            return user_ok(['changed' => false]);
        }
        try {
            db_exec('UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?', [$new, $userId]);
        } catch (PDOException $e) {
            if (db_is_duplicate_key($e)) {
                return user_fail(['email' => 'That email address is already used by another account.']);
            }
            throw $e;
        }
        $token = user_create_verification_token($userId);
        user_audit('user.email_change', 'users', $userId, ['email' => $row['email']], ['email' => $new], $userId);
        $mail = $sendEmail ? user_mail('mail_send_verification', $userId, $token) : null;
        return user_ok(['changed' => true, 'verification_token' => $token, 'mail' => $mail]);
    }
}

if (!function_exists('user_save_profile_image')) {
    /**
     * Save an uploaded avatar from $_FILES['profile_image'] to assets/img/avatars/ and store the
     * path (relative to assets/img/) in users.profile_image. Validates real file type and size.
     */
    function user_save_profile_image(int $userId, array $file): array
    {
        if (!isset($file['error'], $file['tmp_name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return user_fail(['profile_image' => 'Please choose an image to upload.']);
        }
        $maxBytes = max(1, user_setting_int('max_image_upload_mb', 2)) * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxBytes) {
            return user_fail(['profile_image' => 'The image must be ' . ($maxBytes / 1048576) . ' MB or smaller.']);
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            return user_fail(['profile_image' => 'Invalid upload.']);
        }
        $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = '';
        if (class_exists('finfo')) {
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        }
        if (!isset($mimeToExt[$mime]) || @getimagesize((string) $file['tmp_name']) === false) {
            return user_fail(['profile_image' => 'Only JPG, PNG or WEBP images are allowed.']);
        }
        $ext = $mimeToExt[$mime];
        $allowed = array_map('trim', explode(',', strtolower(user_setting('allowed_image_types', 'jpg,jpeg,png,webp'))));
        if (!in_array($ext, $allowed, true) && !($ext === 'jpg' && in_array('jpeg', $allowed, true))) {
            return user_fail(['profile_image' => 'This image type is not allowed.']);
        }

        $dir = dirname(__DIR__) . '/assets/img/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return user_fail(['profile_image' => 'The upload folder could not be created.']);
        }
        if (!is_file($dir . '/.htaccess')) {   // never execute scripts from the upload folder
            @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|pl|cgi)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
        $name = 'u' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            return user_fail(['profile_image' => 'The image could not be saved.']);
        }
        $old = db_val('SELECT profile_image FROM users WHERE id = ?', [$userId]);
        db_exec('UPDATE users SET profile_image = ? WHERE id = ?', ['avatars/' . $name, $userId]);
        user_delete_avatar_file($old !== null ? (string) $old : null);
        return user_ok(['profile_image' => 'avatars/' . $name]);
    }
}

if (!function_exists('user_delete_avatar_file')) {
    function user_delete_avatar_file(?string $relative): void
    {
        if ($relative === null || strpos($relative, 'avatars/') !== 0) {
            return;
        }
        $path = dirname(__DIR__) . '/assets/img/avatars/' . basename($relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

if (!function_exists('user_remove_profile_image')) {
    function user_remove_profile_image(int $userId): array
    {
        $old = db_val('SELECT profile_image FROM users WHERE id = ?', [$userId]);
        db_exec('UPDATE users SET profile_image = NULL WHERE id = ?', [$userId]);
        user_delete_avatar_file($old !== null ? (string) $old : null);
        return user_ok();
    }
}

// ---------------------------------------------------------------------
// 14. Account status  (features 4, 38, 39, 51)
// ---------------------------------------------------------------------
if (!function_exists('user_active_admin_count')) {
    function user_active_admin_count(int $exceptUserId = 0): int
    {
        return (int) db_val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?", [$exceptUserId]);
    }
}

if (!function_exists('user_set_status')) {
    /**
     * Activate / deactivate / suspend an account (admin action).
     * Needs customers.manage (customers) or employees.manage (employees).
     * Protects the last active administrator and the acting user's own account.
     */
    function user_set_status(int $userId, string $status, ?int $actorId = null, string $reason = ''): array
    {
        if (!in_array($status, USER_STATUSES, true)) {
            return user_fail('Invalid account status.');
        }
        $actor = user_actor_id($actorId);
        if (($denied = user_actor_can_manage($actor, $userId)) !== null) {
            return $denied;
        }
        $user = db_row('SELECT id, role, status FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return user_fail('Account not found.');
        }
        if ($user['status'] === $status) {
            return user_ok(['unchanged' => true]);
        }
        if ($status !== 'active') {
            if ($actor === $userId) {
                return user_fail('You cannot deactivate your own account here.');
            }
            if ($user['role'] === 'admin' && user_active_admin_count($userId) === 0) {
                return user_fail('At least one active administrator is required.');
            }
        }

        db_transaction(static function (PDO $pdo) use ($userId, $status): void {
            if ($status === 'active') {
                db_exec('UPDATE users SET status = ?, deactivated_at = NULL, failed_login_count = 0, locked_until = NULL WHERE id = ?', [$status, $userId]);
            } else {
                db_exec('UPDATE users SET status = ?, deactivated_at = NOW() WHERE id = ?', [$status, $userId]);
            }
        });
        user_permission_codes($userId, true);

        if (in_array((string) $user['role'], USER_EMPLOYEE_ROLES, true)) {
            user_employee_history_add($userId, $status === 'active' ? 'activated' : 'deactivated', $actor, (string) $user['status'], $status, $reason !== '' ? $reason : null);
        }
        user_audit(($user['role'] === 'customer' ? 'customer' : 'employee') . '.status_change', 'users', $userId,
            ['status' => $user['status']], ['status' => $status, 'reason' => $reason], $actor);
        return user_ok();
    }
}

if (!function_exists('user_unlock_account')) {
    /** Clear a brute-force lockout early. */
    function user_unlock_account(int $userId, ?int $actorId = null): array
    {
        $actor = user_actor_id($actorId);
        if (($denied = user_actor_can_manage($actor, $userId)) !== null) {
            return $denied;
        }
        $n = db_exec('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
        user_audit('user.unlock', 'users', $userId, null, null, $actor);
        return $n >= 0 ? user_ok() : user_fail('Account not found.');
    }
}

if (!function_exists('user_self_deactivate')) {
    /** A customer closes their own account (needs the password). Employees must ask an administrator. */
    function user_self_deactivate(int $userId, string $password): array
    {
        $row = db_row('SELECT role, status, password_hash FROM users WHERE id = ?', [$userId]);
        if ($row === null || $row['role'] !== 'customer') {
            return user_fail('Only customer accounts can be closed here.');
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return user_fail(['password' => 'Your password is incorrect.']);
        }
        db_exec("UPDATE users SET status = 'inactive', deactivated_at = NOW() WHERE id = ?", [$userId]);
        user_audit('customer.self_deactivate', 'users', $userId, ['status' => $row['status']], ['status' => 'inactive'], $userId);
        return user_ok();
    }
}

// ---------------------------------------------------------------------
// 15. Saved addresses  (features 5, 7)
// ---------------------------------------------------------------------
if (!function_exists('user_addresses')) {
    function user_addresses(int $userId): array
    {
        return db_all(
            'SELECT * FROM customer_addresses WHERE user_id = ?
             ORDER BY is_default_shipping DESC, is_default_billing DESC, id DESC',
            [$userId]
        );
    }
}

if (!function_exists('user_address')) {
    /** One address, only if it belongs to this user (ownership check for checkout). */
    function user_address(int $userId, int $addressId): ?array
    {
        return db_row('SELECT * FROM customer_addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]);
    }
}

if (!function_exists('user_default_address')) {
    /** @param string $kind shipping|billing */
    function user_default_address(int $userId, string $kind = 'shipping'): ?array
    {
        $col = $kind === 'billing' ? 'is_default_billing' : 'is_default_shipping';
        return db_row("SELECT * FROM customer_addresses WHERE user_id = ? AND {$col} = 1 ORDER BY id DESC LIMIT 1", [$userId]);
    }
}

if (!function_exists('user_validate_address')) {
    /** @return array{errors:array,clean:array} */
    function user_validate_address(array $d): array
    {
        $errors = [];
        $clean  = [];
        $clean['label'] = user_clean($d['label'] ?? '');
        if (user_strlen($clean['label']) > 60) {
            $errors['label'] = 'Label must be 60 characters or fewer.';
        }
        $type = (string) ($d['address_type'] ?? 'both');
        $clean['address_type'] = in_array($type, ['billing', 'shipping', 'both'], true) ? $type : 'both';

        $req = [
            'recipient_name' => ['Recipient name', 150], 'address_line1' => ['Address', 255],
            'city' => ['City', 100], 'province' => ['Province', 100],
        ];
        foreach ($req as $key => [$label, $max]) {
            $v = user_clean($d[$key] ?? '');
            if ($v === '') {
                $errors[$key] = $label . ' is required.';
            } elseif (user_strlen($v) > $max) {
                $errors[$key] = $label . ' must be ' . $max . ' characters or fewer.';
            }
            $clean[$key] = $v;
        }
        $clean['address_line2'] = user_clean($d['address_line2'] ?? '');
        if (user_strlen($clean['address_line2']) > 255) {
            $errors['address_line2'] = 'Address line 2 must be 255 characters or fewer.';
        }

        $phone = user_normalize_phone((string) ($d['phone'] ?? ''));
        if ($phone === '') {
            $errors['phone'] = 'Contact number is required.';
        } elseif (!user_valid_phone($phone)) {
            $errors['phone'] = 'Please enter a valid contact number (7-15 digits).';
        }
        $clean['phone'] = $phone;

        $email = user_normalize_email((string) ($d['email'] ?? ''));
        if ($email !== '' && !user_valid_email($email)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        $clean['email'] = $email;

        $postal = user_clean($d['postal_code'] ?? '');
        if ($postal === '') {
            $errors['postal_code'] = 'Postal code is required.';
        } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,18}[A-Za-z0-9]$/', $postal) !== 1) {
            $errors['postal_code'] = 'Please enter a valid postal code.';
        }
        $clean['postal_code'] = $postal;

        $country = user_clean($d['country'] ?? '');
        $clean['country'] = $country !== '' ? user_cut($country, 100) : 'Philippines';

        $clean['is_default_shipping'] = !empty($d['is_default_shipping']);
        $clean['is_default_billing']  = !empty($d['is_default_billing']);
        return ['errors' => $errors, 'clean' => $clean];
    }
}

if (!function_exists('user_repair_address_defaults')) {
    /** Make sure each user has one default shipping and one default billing address when eligible addresses exist. */
    function user_repair_address_defaults(int $userId): void
    {
        $kinds = [
            'is_default_shipping' => "('shipping','both')",
            'is_default_billing'  => "('billing','both')",
        ];
        foreach ($kinds as $col => $types) {
            // an address may not be default for a purpose its type excludes
            db_exec("UPDATE customer_addresses SET {$col} = 0 WHERE user_id = ? AND {$col} = 1 AND address_type NOT IN {$types}", [$userId]);
            if (db_val("SELECT 1 FROM customer_addresses WHERE user_id = ? AND {$col} = 1 LIMIT 1", [$userId]) === null) {
                $next = db_val("SELECT id FROM customer_addresses WHERE user_id = ? AND address_type IN {$types} ORDER BY id DESC LIMIT 1", [$userId]);
                if ($next !== null) {
                    db_exec("UPDATE customer_addresses SET {$col} = 1 WHERE id = ?", [(int) $next]);
                }
            }
        }
    }
}

if (!function_exists('user_save_address')) {
    /** Create (no $addressId) or update an address. The first address becomes the default automatically. */
    function user_save_address(int $userId, array $data, ?int $addressId = null): array
    {
        $check = user_validate_address($data);
        if ($check['errors'] !== []) {
            return user_fail($check['errors']);
        }
        $c = $check['clean'];

        return db_transaction(static function (PDO $pdo) use ($userId, $c, $addressId): array {
            if (db_val('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]) === null) {   // serialise per user
                return user_fail('Account not found.');
            }
            if ($addressId !== null) {
                if (db_val('SELECT id FROM customer_addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]) === null) {
                    return user_fail('Address not found.');
                }
            } elseif ((int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE user_id = ?', [$userId]) >= USER_MAX_ADDRESSES) {
                return user_fail('You can save up to ' . USER_MAX_ADDRESSES . ' addresses. Please delete one first.');
            }

            $values = [$c['label'] !== '' ? $c['label'] : null, $c['address_type'], $c['recipient_name'], $c['phone'],
                       $c['email'] !== '' ? $c['email'] : null, $c['address_line1'],
                       $c['address_line2'] !== '' ? $c['address_line2'] : null,
                       $c['city'], $c['province'], $c['postal_code'], $c['country']];
            if ($addressId === null) {
                $addressId = db_insert(
                    'INSERT INTO customer_addresses (label, address_type, recipient_name, phone, email, address_line1, address_line2, city, province, postal_code, country, user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge($values, [$userId])
                );
            } else {
                db_exec(
                    'UPDATE customer_addresses SET label = ?, address_type = ?, recipient_name = ?, phone = ?, email = ?, address_line1 = ?,
                            address_line2 = ?, city = ?, province = ?, postal_code = ?, country = ? WHERE id = ? AND user_id = ?',
                    array_merge($values, [$addressId, $userId])
                );
            }

            $type = $c['address_type'];
            if ($c['is_default_shipping'] && in_array($type, ['shipping', 'both'], true)) {
                db_exec('UPDATE customer_addresses SET is_default_shipping = 0 WHERE user_id = ?', [$userId]);
                db_exec('UPDATE customer_addresses SET is_default_shipping = 1 WHERE id = ?', [$addressId]);
            }
            if ($c['is_default_billing'] && in_array($type, ['billing', 'both'], true)) {
                db_exec('UPDATE customer_addresses SET is_default_billing = 0 WHERE user_id = ?', [$userId]);
                db_exec('UPDATE customer_addresses SET is_default_billing = 1 WHERE id = ?', [$addressId]);
            }
            user_repair_address_defaults($userId);
            return user_ok(['address_id' => (int) $addressId, 'address' => user_address($userId, (int) $addressId)]);
        });
    }
}

if (!function_exists('user_delete_address')) {
    function user_delete_address(int $userId, int $addressId): array
    {
        return db_transaction(static function (PDO $pdo) use ($userId, $addressId): array {
            $n = db_exec('DELETE FROM customer_addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]);
            if ($n === 0) {
                return user_fail('Address not found.');
            }
            user_repair_address_defaults($userId);
            return user_ok();
        });
    }
}

if (!function_exists('user_set_default_address')) {
    /** @param string $kind shipping|billing|both */
    function user_set_default_address(int $userId, int $addressId, string $kind = 'both'): array
    {
        if (!in_array($kind, ['shipping', 'billing', 'both'], true)) {
            return user_fail('Invalid default type.');
        }
        return db_transaction(static function (PDO $pdo) use ($userId, $addressId, $kind): array {
            $addr = db_row('SELECT id, address_type FROM customer_addresses WHERE id = ? AND user_id = ? FOR UPDATE', [$addressId, $userId]);
            if ($addr === null) {
                return user_fail('Address not found.');
            }
            if (in_array($kind, ['shipping', 'both'], true) && in_array($addr['address_type'], ['shipping', 'both'], true)) {
                db_exec('UPDATE customer_addresses SET is_default_shipping = 0 WHERE user_id = ?', [$userId]);
                db_exec('UPDATE customer_addresses SET is_default_shipping = 1 WHERE id = ?', [$addressId]);
            } elseif ($kind === 'shipping') {
                return user_fail('This address is marked as billing only.');
            }
            if (in_array($kind, ['billing', 'both'], true) && in_array($addr['address_type'], ['billing', 'both'], true)) {
                db_exec('UPDATE customer_addresses SET is_default_billing = 0 WHERE user_id = ?', [$userId]);
                db_exec('UPDATE customer_addresses SET is_default_billing = 1 WHERE id = ?', [$addressId]);
            } elseif ($kind === 'billing') {
                return user_fail('This address is marked as shipping only.');
            }
            return user_ok();
        });
    }
}

// ---------------------------------------------------------------------
// 16. Saved payment methods - masked data only  (features 5, 9)
// ---------------------------------------------------------------------
if (!function_exists('user_card_info')) {
    /**
     * Read a card number ONLY to derive brand + last4 and run the Luhn check, then forget it.
     * Never store the number or CVV anywhere.
     * @return array{valid:bool,brand:?string,last4:?string}
     */
    function user_card_info(string $number): array
    {
        $digits = (string) preg_replace('/\D+/', '', $number);
        $none = ['valid' => false, 'brand' => null, 'last4' => null];
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return $none;
        }
        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        if ($sum % 10 !== 0) {
            return $none;
        }
        if (preg_match('/^4/', $digits)) {
            $brand = 'Visa';
        } elseif (preg_match('/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/', $digits)) {
            $brand = 'Mastercard';
        } elseif (preg_match('/^3[47]/', $digits)) {
            $brand = 'American Express';
        } elseif (preg_match('/^35/', $digits)) {
            $brand = 'JCB';
        } elseif (preg_match('/^(6011|65)/', $digits)) {
            $brand = 'Discover';
        } else {
            $brand = 'Card';
        }
        return ['valid' => true, 'brand' => $brand, 'last4' => substr($digits, -4)];
    }
}

if (!function_exists('user_payment_methods')) {
    function user_payment_methods(int $userId): array
    {
        return db_all(
            'SELECT id, method_type, provider, card_brand, card_last4, expiry_month, expiry_year, holder_name, is_default, created_at
             FROM customer_payment_methods WHERE user_id = ? ORDER BY is_default DESC, id DESC',
            [$userId]
        );
    }
}

if (!function_exists('user_add_payment_method')) {
    /**
     * Save a masked payment method.
     * Cards: method_type credit_card|debit_card, holder_name, expiry_month, expiry_year and EITHER
     *        card_number (only brand+last4 are kept) OR card_brand + card_last4. CVV is never read.
     * GCash / PayPal: method_type gcash|paypal, optional holder_name (account label).
     * Optional: provider (default 'mock'), provider_token (gateway token for future real payments), is_default.
     */
    function user_add_payment_method(int $userId, array $data): array
    {
        $type = (string) ($data['method_type'] ?? '');
        if (!in_array($type, ['credit_card', 'debit_card', 'gcash', 'paypal'], true)) {
            return user_fail(['method_type' => 'Please choose a payment method.']);
        }
        $errors = [];
        $brand = $last4 = $holder = null;
        $month = $year = null;
        $holderRaw = user_clean($data['holder_name'] ?? '');
        if (user_strlen($holderRaw) > 150) {
            $errors['holder_name'] = 'Name must be 150 characters or fewer.';
        }

        if ($type === 'credit_card' || $type === 'debit_card') {
            if (!empty($data['card_number'])) {
                $info = user_card_info((string) $data['card_number']);
                if (!$info['valid']) {
                    $errors['card_number'] = 'Please enter a valid card number.';
                } else {
                    $brand = $info['brand'];
                    $last4 = $info['last4'];
                }
            } else {
                $brand = user_cut(user_clean($data['card_brand'] ?? ''), 30);
                $last4 = user_clean($data['card_last4'] ?? '');
                if ($brand === '' || preg_match('/^\d{4}$/', $last4) !== 1) {
                    $errors['card_number'] = 'Card brand and the last 4 digits are required.';
                }
            }
            if ($holderRaw === '') {
                $errors['holder_name'] = 'Name on card is required.';
            }
            $month = (int) ($data['expiry_month'] ?? 0);
            $year  = (int) ($data['expiry_year'] ?? 0);
            if ($year > 0 && $year < 100) {
                $year += 2000;
            }
            if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
                $errors['expiry_month'] = 'Please enter a valid expiry date.';
            } elseif ($year * 12 + $month < (int) date('Y') * 12 + (int) date('n')) {
                $errors['expiry_month'] = 'This card has expired.';
            }
        }
        if ($errors !== []) {
            return user_fail($errors);
        }
        $holder = $holderRaw !== '' ? $holderRaw : null;
        $provider = user_cut(user_clean($data['provider'] ?? 'mock'), 50) ?: 'mock';
        $token = isset($data['provider_token']) && $data['provider_token'] !== '' ? user_cut(user_clean($data['provider_token']), 255) : null;
        $wantDefault = !empty($data['is_default']);

        return db_transaction(static function (PDO $pdo) use ($userId, $type, $provider, $token, $brand, $last4, $month, $year, $holder, $wantDefault): array {
            if (db_val('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]) === null) {
                return user_fail('Account not found.');
            }
            if ((int) db_val('SELECT COUNT(*) FROM customer_payment_methods WHERE user_id = ?', [$userId]) >= USER_MAX_PAYMENT_METHODS) {
                return user_fail('You can save up to ' . USER_MAX_PAYMENT_METHODS . ' payment methods.');
            }
            if ($last4 !== null && db_val(
                'SELECT 1 FROM customer_payment_methods WHERE user_id = ? AND method_type = ? AND card_last4 = ? AND expiry_month = ? AND expiry_year = ?',
                [$userId, $type, $last4, $month, $year]) !== null) {
                return user_fail('This card is already saved.');
            }
            $isFirst = db_val('SELECT 1 FROM customer_payment_methods WHERE user_id = ?', [$userId]) === null;
            $makeDefault = $wantDefault || $isFirst;
            if ($makeDefault) {
                db_exec('UPDATE customer_payment_methods SET is_default = 0 WHERE user_id = ?', [$userId]);
            }
            $id = db_insert(
                'INSERT INTO customer_payment_methods (user_id, method_type, provider, provider_token, card_brand, card_last4, expiry_month, expiry_year, holder_name, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $type, $provider, $token, $brand, $last4, $month, $year, $holder, $makeDefault ? 1 : 0]
            );
            return user_ok(['payment_method_id' => $id]);
        });
    }
}

if (!function_exists('user_delete_payment_method')) {
    function user_delete_payment_method(int $userId, int $methodId): array
    {
        return db_transaction(static function (PDO $pdo) use ($userId, $methodId): array {
            $row = db_row('SELECT is_default FROM customer_payment_methods WHERE id = ? AND user_id = ? FOR UPDATE', [$methodId, $userId]);
            if ($row === null) {
                return user_fail('Payment method not found.');
            }
            db_exec('DELETE FROM customer_payment_methods WHERE id = ?', [$methodId]);
            if ((int) $row['is_default'] === 1) {
                $next = db_val('SELECT id FROM customer_payment_methods WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
                if ($next !== null) {
                    db_exec('UPDATE customer_payment_methods SET is_default = 1 WHERE id = ?', [(int) $next]);
                }
            }
            return user_ok();
        });
    }
}

if (!function_exists('user_set_default_payment_method')) {
    function user_set_default_payment_method(int $userId, int $methodId): array
    {
        return db_transaction(static function (PDO $pdo) use ($userId, $methodId): array {
            if (db_val('SELECT id FROM customer_payment_methods WHERE id = ? AND user_id = ? FOR UPDATE', [$methodId, $userId]) === null) {
                return user_fail('Payment method not found.');
            }
            db_exec('UPDATE customer_payment_methods SET is_default = 0 WHERE user_id = ?', [$userId]);
            db_exec('UPDATE customer_payment_methods SET is_default = 1 WHERE id = ?', [$methodId]);
            return user_ok();
        });
    }
}

// ---------------------------------------------------------------------
// 17. Preferences and notification preferences  (features 5, 12, 54)
// ---------------------------------------------------------------------
if (!function_exists('user_get_preferences')) {
    /** Preferences with safe defaults, plus preferred_option_ids (frequent add-ons). */
    function user_get_preferences(int $userId): array
    {
        $row = db_row('SELECT * FROM customer_preferences WHERE user_id = ?', [$userId]) ?? [
            'user_id' => $userId, 'preferred_branch_id' => null, 'preferred_size_label' => null,
            'preferred_milk_option_id' => null, 'preferred_sweetness' => null, 'preferred_temperature' => null,
            'default_order_type' => null, 'notify_email' => 1, 'notify_sms' => 0, 'notify_push' => 0,
            'notify_in_app' => 1, 'marketing_consent' => 0, 'marketing_consent_at' => null,
        ];
        $row['preferred_option_ids'] = array_map('intval', array_column(
            db_all('SELECT option_id FROM customer_preferred_options WHERE user_id = ? ORDER BY option_id', [$userId]), 'option_id'
        ));
        return $row;
    }
}

if (!function_exists('user_save_preferences')) {
    /**
     * Save ordering preferences. Only the keys present in $data are changed.
     * Keys: preferred_branch_id, preferred_size_label, preferred_milk_option_id,
     *       preferred_sweetness (0/25/50/75/100), preferred_temperature (hot|iced),
     *       default_order_type, preferred_option_ids (int[])
     * Notification switches live in user_save_notification_preferences().
     */
    function user_save_preferences(int $userId, array $data): array
    {
        if (db_val('SELECT 1 FROM users WHERE id = ?', [$userId]) === null) {
            return user_fail('Account not found.');
        }
        $errors = [];
        $set = [];

        if (array_key_exists('preferred_branch_id', $data)) {
            $v = (int) $data['preferred_branch_id'];
            if ($v === 0) {
                $set['preferred_branch_id'] = null;
            } elseif (!user_branch_active($v)) {
                $errors['preferred_branch_id'] = 'Please choose an available branch.';
            } else {
                $set['preferred_branch_id'] = $v;
            }
        }
        if (array_key_exists('preferred_size_label', $data)) {
            $v = user_clean($data['preferred_size_label']);
            if ($v === '') {
                $set['preferred_size_label'] = null;
            } elseif (user_strlen($v) > 30) {
                $errors['preferred_size_label'] = 'Size label is too long.';
            } else {
                $set['preferred_size_label'] = $v;
            }
        }
        if (array_key_exists('preferred_milk_option_id', $data)) {
            $v = (int) $data['preferred_milk_option_id'];
            if ($v === 0) {
                $set['preferred_milk_option_id'] = null;
            } elseif (db_val("SELECT o.id FROM customization_options o JOIN customization_groups g ON g.id = o.group_id
                              WHERE o.id = ? AND g.group_type = 'milk' AND o.is_active = 1", [$v]) === null) {
                $errors['preferred_milk_option_id'] = 'Please choose a valid milk option.';
            } else {
                $set['preferred_milk_option_id'] = $v;
            }
        }
        if (array_key_exists('preferred_sweetness', $data)) {
            if ($data['preferred_sweetness'] === '' || $data['preferred_sweetness'] === null) {
                $set['preferred_sweetness'] = null;
            } elseif (!in_array((int) $data['preferred_sweetness'], [0, 25, 50, 75, 100], true)) {
                $errors['preferred_sweetness'] = 'Sweetness must be 0, 25, 50, 75 or 100.';
            } else {
                $set['preferred_sweetness'] = (int) $data['preferred_sweetness'];
            }
        }
        if (array_key_exists('preferred_temperature', $data)) {
            $v = (string) $data['preferred_temperature'];
            if ($v === '') {
                $set['preferred_temperature'] = null;
            } elseif (!in_array($v, ['hot', 'iced'], true)) {
                $errors['preferred_temperature'] = 'Temperature must be hot or iced.';
            } else {
                $set['preferred_temperature'] = $v;
            }
        }
        if (array_key_exists('default_order_type', $data)) {
            $v = (string) $data['default_order_type'];
            if ($v === '') {
                $set['default_order_type'] = null;
            } elseif (!in_array($v, ['delivery', 'pickup', 'takeaway', 'dine_in'], true)) {
                $errors['default_order_type'] = 'Please choose a valid order type.';
            } else {
                $set['default_order_type'] = $v;
            }
        }
        $optionIds = null;
        if (array_key_exists('preferred_option_ids', $data)) {
            $optionIds = array_values(array_unique(array_filter(array_map('intval', (array) $data['preferred_option_ids']))));
            if (count($optionIds) > 20) {
                $errors['preferred_option_ids'] = 'You can save up to 20 favourite add-ons.';
            } elseif ($optionIds !== []) {
                $found = (int) db_val(
                    "SELECT COUNT(*) FROM customization_options o JOIN customization_groups g ON g.id = o.group_id
                     WHERE o.id IN (" . db_in_placeholders($optionIds) . ") AND o.is_active = 1 AND g.group_type IN ('syrup','topping','espresso','other')",
                    $optionIds
                );
                if ($found !== count($optionIds)) {
                    $errors['preferred_option_ids'] = 'One or more add-ons are not available.';
                }
            }
        }
        if ($errors !== []) {
            return user_fail($errors);
        }

        db_transaction(static function (PDO $pdo) use ($userId, $set, $optionIds): void {
            db_exec('INSERT IGNORE INTO customer_preferences (user_id) VALUES (?)', [$userId]);
            if ($set !== []) {
                $assign = implode(', ', array_map(static function (string $c): string {
                    return $c . ' = ?';
                }, array_keys($set)));
                db_exec('UPDATE customer_preferences SET ' . $assign . ' WHERE user_id = ?', array_merge(array_values($set), [$userId]));
            }
            if ($optionIds !== null) {
                db_exec('DELETE FROM customer_preferred_options WHERE user_id = ?', [$userId]);
                foreach ($optionIds as $id) {
                    db_exec('INSERT INTO customer_preferred_options (user_id, option_id) VALUES (?, ?)', [$userId, $id]);
                }
            }
        });
        return user_ok(['preferences' => user_get_preferences($userId)]);
    }
}

if (!function_exists('user_save_notification_preferences')) {
    /**
     * Notification switches from the dashboard form. Checkboxes: a missing key means OFF.
     * Keys: notify_email, notify_sms, notify_push, notify_in_app, marketing_consent.
     */
    function user_save_notification_preferences(int $userId, array $data): array
    {
        if (db_val('SELECT 1 FROM users WHERE id = ?', [$userId]) === null) {
            return user_fail('Account not found.');
        }
        $prefs = user_get_preferences($userId);
        $new = [];
        foreach (['notify_email', 'notify_sms', 'notify_push', 'notify_in_app', 'marketing_consent'] as $k) {
            $new[$k] = !empty($data[$k]) ? 1 : 0;
        }
        $consentChanged = (int) $prefs['marketing_consent'] !== $new['marketing_consent'];
        db_transaction(static function (PDO $pdo) use ($userId, $new, $consentChanged): void {
            db_exec('INSERT IGNORE INTO customer_preferences (user_id) VALUES (?)', [$userId]);
            db_exec(
                'UPDATE customer_preferences SET notify_email = ?, notify_sms = ?, notify_push = ?, notify_in_app = ?,
                        marketing_consent = ?' . ($consentChanged ? ', marketing_consent_at = NOW()' : '') . ' WHERE user_id = ?',
                [$new['notify_email'], $new['notify_sms'], $new['notify_push'], $new['notify_in_app'], $new['marketing_consent'], $userId]
            );
        });
        return user_ok(['preferences' => user_get_preferences($userId)]);
    }
}

// ---------------------------------------------------------------------
// 18. Favorites and saved personalised drinks  (features 5, 6, 54)
// ---------------------------------------------------------------------
if (!function_exists('user_favorite_ids')) {
    /** @return int[] product ids (use it to colour the heart icons on the catalog). */
    function user_favorite_ids(int $userId): array
    {
        return array_map('intval', array_column(db_all('SELECT product_id FROM favorites WHERE user_id = ?', [$userId]), 'product_id'));
    }
}

if (!function_exists('user_is_favorite')) {
    function user_is_favorite(int $userId, int $productId): bool
    {
        return db_val('SELECT 1 FROM favorites WHERE user_id = ? AND product_id = ?', [$userId, $productId]) !== null;
    }
}

if (!function_exists('user_favorites')) {
    /** Favorites list with basic product data and the primary image (effective prices come from Models/product.php). */
    function user_favorites(int $userId): array
    {
        return db_all(
            "SELECT p.id, p.name, p.slug, p.short_description, p.product_type, p.base_price, p.promo_price,
                    p.is_available, p.status, f.created_at AS favorited_at,
                    (SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.display_order, pi.id LIMIT 1) AS image_path
             FROM favorites f JOIN products p ON p.id = f.product_id
             WHERE f.user_id = ? AND p.status <> 'archived'
             ORDER BY f.created_at DESC",
            [$userId]
        );
    }
}

if (!function_exists('user_favorite_add')) {
    function user_favorite_add(int $userId, int $productId): array
    {
        if (db_val("SELECT id FROM products WHERE id = ? AND status = 'active'", [$productId]) === null) {
            return user_fail('This product is not available.');
        }
        try {
            db_exec('INSERT INTO favorites (user_id, product_id) VALUES (?, ?)', [$userId, $productId]);
        } catch (PDOException $e) {
            if (!db_is_duplicate_key($e)) {
                throw $e;
            }
        }
        return user_ok(['is_favorite' => true]);
    }
}

if (!function_exists('user_favorite_remove')) {
    function user_favorite_remove(int $userId, int $productId): array
    {
        db_exec('DELETE FROM favorites WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
        return user_ok(['is_favorite' => false]);
    }
}

if (!function_exists('user_favorite_toggle')) {
    function user_favorite_toggle(int $userId, int $productId): array
    {
        return user_is_favorite($userId, $productId)
            ? user_favorite_remove($userId, $productId)
            : user_favorite_add($userId, $productId);
    }
}

if (!function_exists('user_normalize_customization')) {
    /**
     * Validate a drink customization against the catalog rules and fill in defaults for required groups.
     * $selection: [option_id => quantity] or a list of option ids (quantity 1 each).
     * @return array{errors:array,options:array<int,int>}  options = [option_id => quantity]
     */
    function user_normalize_customization(int $productId, int $variantId, array $selection): array
    {
        $errors = [];
        $product = db_row("SELECT id, is_customizable, is_available FROM products WHERE id = ? AND status = 'active'", [$productId]);
        if ($product === null) {
            return ['errors' => ['product_id' => 'This product is not available.'], 'options' => []];
        }
        if (db_val('SELECT id FROM product_variants WHERE id = ? AND product_id = ? AND is_active = 1', [$variantId, $productId]) === null) {
            return ['errors' => ['variant_id' => 'Please choose a valid size.'], 'options' => []];
        }

        // normalise to [id => qty]
        $wanted = [];
        $isList = $selection !== [] && array_keys($selection) === range(0, count($selection) - 1);
        foreach ($selection as $k => $v) {
            if ($isList) {
                $wanted[(int) $v] = 1;            // [3, 7, 12] = list of option ids
            } else {
                $wanted[(int) $k] = (int) $v;     // [3 => 1, 12 => 2] = option id => quantity
            }
        }
        unset($wanted[0]);

        // options the product offers (and the variant allows)
        $restricted = (int) db_val('SELECT COUNT(*) FROM variant_customization_options WHERE variant_id = ?', [$variantId]) > 0;
        $offered = db_all(
            'SELECT o.id, o.group_id, o.name, o.max_quantity, o.is_default AS option_default, pco.is_default AS product_default,
                    g.name AS group_name, g.selection_type, g.max_select, g.min_select, g.is_required
             FROM product_customization_options pco
             JOIN customization_options o ON o.id = pco.option_id AND o.is_active = 1
             JOIN customization_groups g ON g.id = o.group_id AND g.is_active = 1
             WHERE pco.product_id = ? AND pco.is_available = 1'
            . ($restricted ? ' AND o.id IN (SELECT option_id FROM variant_customization_options WHERE variant_id = ' . (int) $variantId . ')' : '')
            . ' ORDER BY g.display_order, o.display_order',
            [$productId]
        );
        $byId = [];
        foreach ($offered as $o) {
            $byId[(int) $o['id']] = $o;
        }

        $chosen = [];
        foreach ($wanted as $id => $qty) {
            if ($qty < 1) {
                continue;
            }
            if (!isset($byId[$id]) || (int) $product['is_customizable'] !== 1) {
                $errors['options'] = 'One of the selected options is not available for this drink.';
                continue;
            }
            if ($qty > (int) $byId[$id]['max_quantity']) {
                $errors['options'] = $byId[$id]['name'] . ' allows at most ' . (int) $byId[$id]['max_quantity'] . '.';
                continue;
            }
            $chosen[$id] = $qty;
        }

        // group rules: single = one option; multiple = up to max_select distinct options; required groups get their default
        $perGroup = [];
        foreach ($chosen as $id => $qty) {
            $perGroup[(int) $byId[$id]['group_id']][] = $id;
        }
        $groups = [];
        foreach ($offered as $o) {
            $groups[(int) $o['group_id']] = $o;
        }
        foreach ($groups as $gid => $g) {
            $count = isset($perGroup[$gid]) ? count($perGroup[$gid]) : 0;
            $limit = $g['selection_type'] === 'single' ? 1 : max(1, (int) $g['max_select']);
            if ($count > $limit) {
                $errors['options'] = 'Too many choices selected for ' . $g['group_name'] . '.';
            }
            if ($count === 0 && ((int) $g['is_required'] === 1 || (int) $g['min_select'] > 0)) {
                $default = null;
                foreach ($offered as $o) {
                    if ((int) $o['group_id'] === $gid && ((int) $o['product_default'] === 1 || (int) $o['option_default'] === 1)) {
                        $default = (int) $o['id'];
                        break;
                    }
                }
                if ($default === null) {
                    $errors['options'] = 'Please choose an option for ' . $g['group_name'] . '.';
                } else {
                    $chosen[$default] = 1;
                }
            }
        }
        return ['errors' => $errors, 'options' => $errors === [] ? $chosen : []];
    }
}

if (!function_exists('user_save_customization')) {
    /** Save a personalised drink ("My morning latte"). Options: [option_id => quantity] or a list of ids. */
    function user_save_customization(int $userId, int $productId, int $variantId, string $name, array $options = [], string $notes = ''): array
    {
        $errors = [];
        $name = user_clean($name);
        if ($name === '') {
            $errors['name'] = 'Please give your drink a name.';
        } elseif (user_strlen($name) > 100) {
            $errors['name'] = 'The name must be 100 characters or fewer.';
        }
        $notes = user_clean($notes);
        if (user_strlen($notes) > 255) {
            $errors['notes'] = 'Notes must be 255 characters or fewer.';
        }
        $check = user_normalize_customization($productId, $variantId, $options);
        $errors += $check['errors'];
        if ($errors !== []) {
            return user_fail($errors);
        }

        return db_transaction(static function (PDO $pdo) use ($userId, $productId, $variantId, $name, $notes, $check): array {
            if (db_val('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]) === null) {
                return user_fail('Account not found.');
            }
            if ((int) db_val('SELECT COUNT(*) FROM saved_customizations WHERE user_id = ?', [$userId]) >= USER_MAX_SAVED_DRINKS) {
                return user_fail('You can save up to ' . USER_MAX_SAVED_DRINKS . ' drinks. Please delete one first.');
            }
            $id = db_insert(
                'INSERT INTO saved_customizations (user_id, product_id, variant_id, name, notes) VALUES (?, ?, ?, ?, ?)',
                [$userId, $productId, $variantId, $name, $notes !== '' ? $notes : null]
            );
            foreach ($check['options'] as $optionId => $qty) {
                db_exec('INSERT INTO saved_customization_options (saved_customization_id, option_id, quantity) VALUES (?, ?, ?)', [$id, (int) $optionId, (int) $qty]);
            }
            return user_ok(['saved_customization_id' => $id]);
        });
    }
}

if (!function_exists('user_saved_customizations')) {
    /**
     * Saved drinks with their options and an `issues` list (empty = can be reordered as-is).
     * Show the issues to the customer instead of silently dropping options (feature 6).
     */
    function user_saved_customizations(int $userId): array
    {
        $rows = db_all(
            'SELECT sc.id, sc.product_id, sc.variant_id, sc.name, sc.notes, sc.last_used_at, sc.created_at,
                    p.name AS product_name, p.slug AS product_slug, p.status AS product_status, p.is_available AS product_available,
                    v.name AS variant_name, v.is_active AS variant_active
             FROM saved_customizations sc
             JOIN products p ON p.id = sc.product_id
             JOIN product_variants v ON v.id = sc.variant_id
             WHERE sc.user_id = ?
             ORDER BY COALESCE(sc.last_used_at, sc.created_at) DESC, sc.id DESC',
            [$userId]
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows);
        $opts = db_all(
            'SELECT so.saved_customization_id, so.option_id, so.quantity, o.name AS option_name, o.additional_price,
                    o.is_active AS option_active, g.name AS group_name, pco.is_available AS option_offered
             FROM saved_customization_options so
             JOIN saved_customizations sc ON sc.id = so.saved_customization_id
             JOIN customization_options o ON o.id = so.option_id
             JOIN customization_groups g ON g.id = o.group_id
             LEFT JOIN product_customization_options pco ON pco.option_id = o.id AND pco.product_id = sc.product_id
             WHERE so.saved_customization_id IN (' . db_in_placeholders($ids) . ')
             ORDER BY g.display_order, o.display_order',
            $ids
        );
        $byDrink = [];
        foreach ($opts as $o) {
            $byDrink[(int) $o['saved_customization_id']][] = $o;
        }
        foreach ($rows as &$r) {
            $r['options'] = $byDrink[(int) $r['id']] ?? [];
            $issues = [];
            if ($r['product_status'] !== 'active' || (int) $r['product_available'] !== 1) {
                $issues[] = $r['product_name'] . ' is currently unavailable.';
            }
            if ((int) $r['variant_active'] !== 1) {
                $issues[] = 'The ' . $r['variant_name'] . ' size is no longer offered.';
            }
            foreach ($r['options'] as $o) {
                if ((int) $o['option_active'] !== 1 || $o['option_offered'] === null || (int) $o['option_offered'] !== 1) {
                    $issues[] = $o['option_name'] . ' is no longer available.';
                }
            }
            $r['issues'] = $issues;
            $r['is_orderable'] = $issues === [];
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('user_rename_saved_customization')) {
    function user_rename_saved_customization(int $userId, int $savedId, string $name, string $notes = ''): array
    {
        $name = user_clean($name);
        if ($name === '' || user_strlen($name) > 100) {
            return user_fail(['name' => 'Please enter a name of up to 100 characters.']);
        }
        $notes = user_clean($notes);
        if (user_strlen($notes) > 255) {
            return user_fail(['notes' => 'Notes must be 255 characters or fewer.']);
        }
        if (db_val('SELECT id FROM saved_customizations WHERE id = ? AND user_id = ?', [$savedId, $userId]) === null) {
            return user_fail('Saved drink not found.');
        }
        db_exec('UPDATE saved_customizations SET name = ?, notes = ? WHERE id = ? AND user_id = ?', [$name, $notes !== '' ? $notes : null, $savedId, $userId]);
        return user_ok();
    }
}

if (!function_exists('user_delete_saved_customization')) {
    function user_delete_saved_customization(int $userId, int $savedId): array
    {
        return db_exec('DELETE FROM saved_customizations WHERE id = ? AND user_id = ?', [$savedId, $userId]) > 0
            ? user_ok() : user_fail('Saved drink not found.');
    }
}

if (!function_exists('user_touch_saved_customization')) {
    /** Call when a saved drink is added to the cart (keeps "recently used" sorting right). */
    function user_touch_saved_customization(int $userId, int $savedId): void
    {
        db_exec('UPDATE saved_customizations SET last_used_at = NOW() WHERE id = ? AND user_id = ?', [$savedId, $userId]);
    }
}

// ---------------------------------------------------------------------
// 19. Dashboard readers: coupons, loyalty, rewards, wallet, notifications  (features 5, 12, 14-18)
// ---------------------------------------------------------------------
if (!function_exists('user_available_coupons')) {
    /** Coupons the customer can still use right now (public + assigned, not expired/used up). */
    function user_available_coupons(int $userId): array
    {
        return db_all(
            'SELECT c.id, c.code, c.description, c.discount_type, c.discount_value, c.max_discount_amount, c.min_purchase_amount,
                    c.applies_to, c.is_customer_specific, c.expires_at, c.per_customer_limit,
                    (SELECT COUNT(*) FROM coupon_usages cu WHERE cu.coupon_id = c.id AND cu.user_id = ?) AS times_used
             FROM coupons c
             LEFT JOIN customer_coupons cc ON cc.coupon_id = c.id AND cc.user_id = ?
             LEFT JOIN promotions pr ON pr.id = c.promotion_id
             WHERE c.is_active = 1
               AND (c.starts_at IS NULL OR c.starts_at <= NOW())
               AND (c.expires_at IS NULL OR c.expires_at >= NOW())
               AND (c.usage_limit IS NULL OR c.used_count < c.usage_limit)
               AND (c.promotion_id IS NULL OR (pr.is_active = 1
                    AND (pr.starts_at IS NULL OR pr.starts_at <= NOW()) AND (pr.ends_at IS NULL OR pr.ends_at >= NOW())))
               AND (c.is_customer_specific = 0 OR (cc.user_id IS NOT NULL AND cc.used_at IS NULL))
             HAVING c.per_customer_limit IS NULL OR times_used < c.per_customer_limit
             ORDER BY c.expires_at IS NULL, c.expires_at, c.id',
            [$userId, $userId]
        );
    }
}

if (!function_exists('user_active_promotions')) {
    /** Running promotional campaigns (banners for the dashboard / homepage). */
    function user_active_promotions(): array
    {
        return db_all(
            'SELECT id, name, description, banner_path, starts_at, ends_at FROM promotions
             WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY id DESC'
        );
    }
}

if (!function_exists('user_loyalty_summary')) {
    /**
     * Loyalty account + tier + next tier + recent transactions, or null when not enrolled.
     * @return array|null account fields, tier_name, tier_multiplier, next_tier (array|null), points_to_next_tier, transactions[]
     */
    function user_loyalty_summary(int $userId, int $transactionLimit = 20): ?array
    {
        $acct = db_row(
            'SELECT a.*, t.name AS tier_name, t.points_multiplier AS tier_multiplier, t.benefits AS tier_benefits
             FROM loyalty_accounts a LEFT JOIN loyalty_tiers t ON t.id = a.tier_id WHERE a.user_id = ?',
            [$userId]
        );
        if ($acct === null) {
            return null;
        }
        $next = db_row(
            'SELECT id, name, min_lifetime_points FROM loyalty_tiers WHERE is_active = 1 AND min_lifetime_points > ? ORDER BY min_lifetime_points ASC LIMIT 1',
            [(int) $acct['lifetime_points_earned']]
        );
        $acct['next_tier'] = $next;
        $acct['points_to_next_tier'] = $next !== null ? max(0, (int) $next['min_lifetime_points'] - (int) $acct['lifetime_points_earned']) : null;
        $limit = max(1, min(100, $transactionLimit));
        $acct['transactions'] = db_all(
            "SELECT id, transaction_type, points, balance_after, order_id, description, expires_at, created_at
             FROM loyalty_transactions WHERE loyalty_account_id = ? ORDER BY id DESC LIMIT {$limit}",
            [(int) $acct['id']]
        );
        return $acct;
    }
}

if (!function_exists('user_available_rewards')) {
    /** Rewards catalog with a can_redeem flag for this customer's balance. */
    function user_available_rewards(int $userId): array
    {
        $balance = (int) db_val("SELECT points_balance FROM loyalty_accounts WHERE user_id = ? AND status = 'active'", [$userId]);
        $rows = db_all(
            'SELECT r.id, r.name, r.description, r.points_required, r.reward_type, r.product_id, r.discount_value,
                    r.validity_days, r.expires_at, p.name AS product_name
             FROM rewards r LEFT JOIN products p ON p.id = r.product_id
             WHERE r.is_active = 1 AND (r.available_from IS NULL OR r.available_from <= NOW())
               AND (r.expires_at IS NULL OR r.expires_at > NOW())
               AND (r.max_redemptions IS NULL OR r.redeemed_count < r.max_redemptions)
             ORDER BY r.points_required, r.id'
        );
        foreach ($rows as &$r) {
            $r['can_redeem']    = $balance >= (int) $r['points_required'];
            $r['points_needed'] = max(0, (int) $r['points_required'] - $balance);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('user_redeem_reward')) {
    /**
     * Spend points on a reward -> creates a voucher (reward_redemptions, status 'available') that can be
     * applied at checkout. One transaction: row locks on the account and the reward prevent double spending.
     */
    function user_redeem_reward(int $userId, int $rewardId): array
    {
        if (user_setting('loyalty_enabled', '1') !== '1') {
            return user_fail('The rewards program is currently unavailable.');
        }
        return db_transaction(static function (PDO $pdo) use ($userId, $rewardId): array {
            $acct = db_row("SELECT id, points_balance, status FROM loyalty_accounts WHERE user_id = ? FOR UPDATE", [$userId]);
            if ($acct === null || $acct['status'] !== 'active') {
                return user_fail('You are not enrolled in the rewards program.');
            }
            $reward = db_row(
                'SELECT *, (available_from IS NULL OR available_from <= NOW()) AS started,
                        (expires_at IS NULL OR expires_at > NOW()) AS not_expired
                 FROM rewards WHERE id = ? FOR UPDATE',
                [$rewardId]
            );
            if ($reward === null || (int) $reward['is_active'] !== 1 || (int) $reward['started'] !== 1 || (int) $reward['not_expired'] !== 1) {
                return user_fail('This reward is not available.');
            }
            if ($reward['max_redemptions'] !== null && (int) $reward['redeemed_count'] >= (int) $reward['max_redemptions']) {
                return user_fail('This reward has been fully redeemed.');
            }
            $cost = (int) $reward['points_required'];
            if ((int) $acct['points_balance'] < $cost) {
                return user_fail('You need ' . ($cost - (int) $acct['points_balance']) . ' more points to redeem this reward.');
            }

            $newBalance = (int) $acct['points_balance'] - $cost;
            $days = max(1, (int) $reward['validity_days']);
            $redemptionId = 0;
            $code = '';
            for ($i = 0; $i < 5 && $redemptionId === 0; $i++) {
                $code = 'RWD-' . strtoupper(bin2hex(random_bytes(5)));
                try {
                    $redemptionId = db_insert(
                        "INSERT INTO reward_redemptions (user_id, reward_id, loyalty_account_id, points_spent, redemption_code, status, expires_at)
                         VALUES (?, ?, ?, ?, ?, 'available', NOW() + INTERVAL {$days} DAY)",
                        [$userId, $rewardId, (int) $acct['id'], $cost, $code]
                    );
                } catch (PDOException $e) {
                    if (!db_is_duplicate_key($e)) {
                        throw $e;
                    }
                }
            }
            if ($redemptionId === 0) {
                return user_fail('Could not create the reward voucher. Please try again.');
            }
            db_insert(
                "INSERT INTO loyalty_transactions (loyalty_account_id, transaction_type, points, balance_after, reward_redemption_id, description)
                 VALUES (?, 'redeem', ?, ?, ?, ?)",
                [(int) $acct['id'], -$cost, $newBalance, $redemptionId, user_cut('Redeemed: ' . (string) $reward['name'], 255)]
            );
            db_exec('UPDATE loyalty_accounts SET points_balance = ?, lifetime_points_redeemed = lifetime_points_redeemed + ? WHERE id = ?', [$newBalance, $cost, (int) $acct['id']]);
            db_exec('UPDATE rewards SET redeemed_count = redeemed_count + 1 WHERE id = ?', [$rewardId]);
            return user_ok(['redemption_id' => $redemptionId, 'redemption_code' => $code, 'points_balance' => $newBalance]);
        });
    }
}

if (!function_exists('user_my_redemptions')) {
    /** Reward vouchers of this customer (available ones can be applied at checkout). */
    function user_my_redemptions(int $userId): array
    {
        return db_all(
            "SELECT rr.id, rr.redemption_code, rr.status, rr.points_spent, rr.redeemed_at, rr.expires_at, rr.used_at, rr.order_id,
                    r.name AS reward_name, r.reward_type, r.discount_value, r.product_id,
                    (rr.status = 'available' AND (rr.expires_at IS NULL OR rr.expires_at > NOW())) AS is_usable
             FROM reward_redemptions rr JOIN rewards r ON r.id = rr.reward_id
             WHERE rr.user_id = ? ORDER BY rr.id DESC",
            [$userId]
        );
    }
}

if (!function_exists('user_wallet')) {
    /** Wallet balance + recent transactions (null when the customer has no wallet yet). */
    function user_wallet(int $userId, int $transactionLimit = 20): ?array
    {
        $wallet = db_row('SELECT id, balance, currency, status FROM wallets WHERE user_id = ?', [$userId]);
        if ($wallet === null) {
            return null;
        }
        $limit = max(1, min(100, $transactionLimit));
        $wallet['transactions'] = db_all(
            "SELECT id, transaction_type, amount, balance_after, order_id, description, created_at
             FROM wallet_transactions WHERE wallet_id = ? ORDER BY id DESC LIMIT {$limit}",
            [(int) $wallet['id']]
        );
        return $wallet;
    }
}

if (!function_exists('user_notifications')) {
    /** The customer's in-app notification inbox. */
    function user_notifications(int $userId, int $limit = 30, bool $unreadOnly = false): array
    {
        $limit = max(1, min(100, $limit));
        return db_all(
            "SELECT id, event_type, subject, message, order_id, related_type, related_id, status, read_at, created_at
             FROM notifications
             WHERE user_id = ? AND audience = 'customer' AND channel = 'in_app' AND status IN ('sent','delivered','read')"
            . ($unreadOnly ? " AND status <> 'read'" : '') . " ORDER BY id DESC LIMIT {$limit}",
            [$userId]
        );
    }
}

if (!function_exists('user_unread_notification_count')) {
    function user_unread_notification_count(int $userId): int
    {
        return (int) db_val(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND audience = 'customer' AND channel = 'in_app' AND status IN ('sent','delivered')",
            [$userId]
        );
    }
}

if (!function_exists('user_mark_notifications_read')) {
    /** @param int[]|null $ids null = mark everything read */
    function user_mark_notifications_read(int $userId, ?array $ids = null): int
    {
        $sql = "UPDATE notifications SET status = 'read', read_at = NOW()
                WHERE user_id = ? AND audience = 'customer' AND channel = 'in_app' AND status IN ('sent','delivered')";
        $params = [$userId];
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids === []) {
                return 0;
            }
            $sql .= ' AND id IN (' . db_in_placeholders($ids) . ')';
            $params = array_merge($params, $ids);
        }
        return db_exec($sql, $params);
    }
}

if (!function_exists('user_dashboard_summary')) {
    /** Counters for the top of Customer/dashboard.php (one call). */
    function user_dashboard_summary(int $userId): array
    {
        $orders = db_row(
            "SELECT COUNT(*) AS total_orders,
                    COALESCE(SUM(status NOT IN ('delivered','picked_up','cancelled')), 0) AS active_orders
             FROM orders WHERE user_id = ?",
            [$userId]
        ) ?? ['total_orders' => 0, 'active_orders' => 0];
        $loyalty = db_row("SELECT points_balance, tier_id FROM loyalty_accounts WHERE user_id = ? AND status = 'active'", [$userId]);
        return [
            'total_orders'        => (int) $orders['total_orders'],
            'active_orders'       => (int) $orders['active_orders'],
            'open_requests'       => (int) db_val("SELECT COUNT(*) FROM return_requests WHERE user_id = ? AND status IN ('pending','approved','item_received')", [$userId]),
            'points_balance'      => $loyalty !== null ? (int) $loyalty['points_balance'] : 0,
            'wallet_balance'      => (float) db_val('SELECT balance FROM wallets WHERE user_id = ?', [$userId]),
            'favorites'           => (int) db_val('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$userId]),
            'saved_drinks'        => (int) db_val('SELECT COUNT(*) FROM saved_customizations WHERE user_id = ?', [$userId]),
            'available_coupons'   => count(user_available_coupons($userId)),
            'unread_notifications'=> user_unread_notification_count($userId),
        ];
    }
}

// ---------------------------------------------------------------------
// 20. Admin: customer management  (feature 38)
// ---------------------------------------------------------------------
if (!function_exists('user_search_customers')) {
    /**
     * Paged customer list for admin/index.php.
     * $filters: q (name/e-mail/phone), status, verified ('yes'|'no'), date_from, date_to (Y-m-d),
     *           sort (created|name|email|orders|spent|last_order|last_login), dir (asc|desc), page, per_page (<= 100)
     * @return array{rows:array,total:int,page:int,per_page:int,pages:int}
     */
    function user_search_customers(array $filters = []): array
    {
        $where  = ["u.role = 'customer'"];
        $params = [];
        $q = user_clean($filters['q'] ?? '');
        if ($q !== '') {
            $like = '%' . db_like_escape($q) . '%';
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        if (in_array((string) ($filters['status'] ?? ''), USER_STATUSES, true)) {
            $where[] = 'u.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (($filters['verified'] ?? '') === 'yes') {
            $where[] = 'u.email_verified_at IS NOT NULL';
        } elseif (($filters['verified'] ?? '') === 'no') {
            $where[] = 'u.email_verified_at IS NULL';
        }
        if (!empty($filters['date_from']) && user_valid_date((string) $filters['date_from'])) {
            $where[] = 'u.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to']) && user_valid_date((string) $filters['date_to'])) {
            $where[] = 'u.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        $sorts = ['created' => 'u.created_at', 'name' => 'u.last_name', 'email' => 'u.email', 'orders' => 's.order_count',
                  'spent' => 's.total_spent', 'last_order' => 's.last_order_at', 'last_login' => 'u.last_login_at'];
        $sort = $sorts[(string) ($filters['sort'] ?? 'created')] ?? 'u.created_at';
        $dir  = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $from = ' FROM users u JOIN v_customer_order_summary s ON s.user_id = u.id WHERE ' . implode(' AND ', $where);

        $total = (int) db_val('SELECT COUNT(*)' . $from, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $rows = db_all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.email_verified_at, u.created_at, u.last_login_at,
                    s.order_count, s.total_spent, s.last_order_at, s.total_refunded' . $from
            . " ORDER BY {$sort} {$dir}, u.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
    }
}

if (!function_exists('user_customer_detail')) {
    /** Everything the admin needs on one customer page, or null when the id is not a customer. */
    function user_customer_detail(int $userId): ?array
    {
        $user = user_find($userId);
        if ($user === null || $user['role'] !== 'customer') {
            return null;
        }
        return [
            'user'          => $user,
            'preferences'   => user_get_preferences($userId),
            'addresses'     => user_addresses($userId),
            'payment_methods' => user_payment_methods($userId),
            'summary'       => db_row('SELECT order_count, total_spent, last_order_at, total_refunded FROM v_customer_order_summary WHERE user_id = ?', [$userId]),
            'orders'        => db_all('SELECT id, order_number, status, payment_status, order_type, grand_total, refunded_total, placed_at FROM orders WHERE user_id = ? ORDER BY placed_at DESC LIMIT 25', [$userId]),
            'loyalty'       => user_loyalty_summary($userId, 20),
            'redemptions'   => user_my_redemptions($userId),
            'wallet'        => user_wallet($userId, 20),
            'return_requests' => db_all('SELECT id, request_number, order_id, request_type, status, reason_text, created_at FROM return_requests WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$userId]),
            'refunds'       => db_all('SELECT id, refund_reference, order_id, refund_type, refund_method, amount, status, created_at FROM refunds WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$userId]),
            'support_tickets' => db_all('SELECT id, ticket_number, subject, category, priority, status, created_at FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 25', [$userId]),
            'recent_logins' => db_all('SELECT ip_address, was_successful, failure_reason, attempted_at FROM login_attempts WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$userId]),
        ];
    }
}

// ---------------------------------------------------------------------
// 21. Admin: employee management  (feature 39) and role changes (feature 40)
// ---------------------------------------------------------------------
if (!function_exists('user_next_employee_code')) {
    function user_next_employee_code(): string
    {
        $max = (int) db_val("SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) FROM employee_profiles WHERE employee_code REGEXP '^EMP-[0-9]+$'");
        return 'EMP-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('user_validate_employee')) {
    /**
     * @param bool $isNew true when creating (requires e-mail/role); false for partial updates
     * @return array{errors:array,clean:array}
     */
    function user_validate_employee(array $d, bool $isNew): array
    {
        $errors = [];
        $clean  = [];

        if ($isNew || array_key_exists('first_name', $d)) {
            $clean['first_name'] = user_validate_name((string) ($d['first_name'] ?? ''), 'First name', $errors, 'first_name');
        }
        if ($isNew || array_key_exists('last_name', $d)) {
            $clean['last_name'] = user_validate_name((string) ($d['last_name'] ?? ''), 'Last name', $errors, 'last_name');
        }
        if ($isNew) {
            $email = user_normalize_email((string) ($d['email'] ?? ''));
            if (!user_valid_email($email)) {
                $errors['email'] = 'Please enter a valid email address.';
            }
            $clean['email'] = $email;
            $role = (string) ($d['role'] ?? '');
            if (!in_array($role, USER_EMPLOYEE_ROLES, true)) {
                $errors['role'] = 'Please choose a role.';
            }
            $clean['role'] = $role;
        }
        if (array_key_exists('phone', $d)) {
            $phone = user_normalize_phone((string) $d['phone']);
            if ($phone !== '' && !user_valid_phone($phone)) {
                $errors['phone'] = 'Please enter a valid contact number (7-15 digits).';
            }
            $clean['phone'] = $phone !== '' ? $phone : null;
        }
        if (array_key_exists('branch_id', $d)) {
            $branch = (int) $d['branch_id'];
            if ($branch !== 0 && !user_branch_active($branch)) {
                $errors['branch_id'] = 'Please choose an available branch.';
            }
            $clean['branch_id'] = $branch !== 0 ? $branch : null;
        }
        foreach (['job_title' => 100, 'responsibilities' => 2000] as $key => $max) {
            if (array_key_exists($key, $d)) {
                $v = user_clean($d[$key]);
                if (user_strlen($v) > $max) {
                    $errors[$key] = ucfirst(str_replace('_', ' ', $key)) . ' is too long.';
                }
                $clean[$key] = $v !== '' ? $v : null;
            }
        }
        if (array_key_exists('hire_date', $d)) {
            $v = user_clean($d['hire_date']);
            if ($v !== '' && !user_valid_date($v)) {
                $errors['hire_date'] = 'Please enter a valid hire date.';
            }
            $clean['hire_date'] = $v !== '' ? $v : null;
        }
        if (array_key_exists('employment_status', $d)) {
            $v = (string) $d['employment_status'];
            if (!in_array($v, ['active', 'on_leave', 'terminated'], true)) {
                $errors['employment_status'] = 'Invalid employment status.';
            }
            $clean['employment_status'] = $v;
        }
        if (array_key_exists('employee_code', $d)) {
            $v = strtoupper(user_clean($d['employee_code']));
            if ($v !== '' && preg_match('/^[A-Z0-9\-]{3,30}$/', $v) !== 1) {
                $errors['employee_code'] = 'Employee code may contain letters, numbers and dashes (3-30 characters).';
            }
            $clean['employee_code'] = $v;
        }
        return ['errors' => $errors, 'clean' => $clean];
    }
}

if (!function_exists('user_create_employee')) {
    /**
     * Create an admin / staff / fulfillment account (needs employees.manage).
     *
     * $data: role, first_name, last_name, email, phone, branch_id (required for staff & fulfillment),
     *        job_title, responsibilities, hire_date, employee_code (blank = EMP-0001 style), password (optional)
     * Without a password the employee gets an invitation: the result holds invite_token (raw, 24 h) and
     * (unless $options['send_email'] === false) the standard "reset your password" e-mail is sent.
     *
     * @return array ok, errors, user_id, employee_code, invite_token, mail
     */
    function user_create_employee(array $data, ?int $actorId = null, array $options = []): array
    {
        $actor = user_actor_id($actorId);
        if (($denied = user_actor_denied($actor, 'employees.manage')) !== null) {
            return $denied;
        }
        $check = user_validate_employee($data, true);
        $errors = $check['errors'];
        $c = $check['clean'];
        if (!isset($errors['role']) && $c['role'] !== 'admin' && empty($c['branch_id'])) {
            $errors['branch_id'] = 'Staff and fulfillment employees must be assigned to a branch.';
        }
        $password = (string) ($data['password'] ?? '');
        if ($password !== '') {
            $problems = user_password_errors($password, (string) ($c['email'] ?? ''));
            if ($problems !== []) {
                $errors['password'] = $problems[0];
            }
        }
        if ($errors !== []) {
            return user_fail($errors);
        }

        $hash = user_hash_password($password !== '' ? $password : bin2hex(random_bytes(16)));   // random = unusable until the invite is accepted
        $result = null;
        for ($attempt = 0; $attempt < 3 && $result === null; $attempt++) {
            $code = ($c['employee_code'] ?? '') !== '' ? $c['employee_code'] : null;
            try {
                $result = db_transaction(static function (PDO $pdo) use ($c, $hash, $code): array {
                    $employeeCode = $code ?? user_next_employee_code();
                    $userId = db_insert(
                        "INSERT INTO users (role, first_name, last_name, email, phone, password_hash, status, email_verified_at)
                         VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())",
                        [$c['role'], $c['first_name'], $c['last_name'], $c['email'], $c['phone'] ?? null, $hash]
                    );
                    db_insert(
                        'INSERT INTO employee_profiles (user_id, employee_code, branch_id, job_title, responsibilities, hire_date)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$userId, $employeeCode, $c['branch_id'] ?? null, $c['job_title'] ?? null, $c['responsibilities'] ?? null,
                         $c['hire_date'] ?? date('Y-m-d')]
                    );
                    return ['user_id' => $userId, 'employee_code' => $employeeCode];
                });
            } catch (PDOException $e) {
                if (!db_is_duplicate_key($e)) {
                    throw $e;
                }
                if (!user_email_available($c['email'])) {
                    return user_fail(['email' => 'An account with this email address already exists.']);
                }
                if ($code !== null) {
                    return user_fail(['employee_code' => 'This employee code is already in use.']);
                }
                // otherwise: two employees created at once picked the same auto code -> retry
            }
        }
        if ($result === null) {
            return user_fail('The employee could not be created. Please try again.');
        }

        $userId = (int) $result['user_id'];
        user_employee_history_add($userId, 'created', $actor, null, $c['role'], 'Account created');
        user_audit('employee.create', 'users', $userId, null,
            ['role' => $c['role'], 'email' => $c['email'], 'branch_id' => $c['branch_id'] ?? null, 'employee_code' => $result['employee_code']], $actor);

        $invite = null;
        $mail = null;
        if ($password === '') {
            $invite = user_create_reset_token($userId, null, 24 * 60);
            if (($options['send_email'] ?? true) !== false) {
                $mail = user_mail('mail_send_password_reset', $userId, $invite);
            }
        }
        return user_ok(['user_id' => $userId, 'employee_code' => $result['employee_code'], 'invite_token' => $invite, 'mail' => $mail]);
    }
}

if (!function_exists('user_update_employee')) {
    /**
     * Edit an employee (needs employees.manage). Partial update: only keys present in $data change.
     * Keys: first_name, last_name, phone, branch_id, job_title, responsibilities, hire_date,
     *       employment_status (terminated also deactivates the login), employee_code.
     * Role changes go through user_change_employee_role().
     */
    function user_update_employee(int $userId, array $data, ?int $actorId = null): array
    {
        $actor = user_actor_id($actorId);
        if (($denied = user_actor_denied($actor, 'employees.manage')) !== null) {
            return $denied;
        }
        $row = db_row(
            'SELECT u.id, u.role, u.first_name, u.last_name, u.phone, u.status, e.employee_code, e.branch_id, e.job_title,
                    e.responsibilities, e.hire_date, e.employment_status
             FROM users u JOIN employee_profiles e ON e.user_id = u.id WHERE u.id = ?',
            [$userId]
        );
        if ($row === null) {
            return user_fail('Employee not found.');
        }
        $check = user_validate_employee($data, false);
        $errors = $check['errors'];
        $c = $check['clean'];
        $newBranch = array_key_exists('branch_id', $c) ? $c['branch_id'] : ($row['branch_id'] !== null ? (int) $row['branch_id'] : null);
        if ($row['role'] !== 'admin' && $newBranch === null) {
            $errors['branch_id'] = 'Staff and fulfillment employees must be assigned to a branch.';
        }
        if (isset($c['employment_status']) && $c['employment_status'] === 'terminated') {
            if ($actor === $userId) {
                $errors['employment_status'] = 'You cannot terminate your own account.';
            } elseif ($row['role'] === 'admin' && user_active_admin_count($userId) === 0) {
                $errors['employment_status'] = 'At least one active administrator is required.';
            }
        }
        if ($errors !== []) {
            return user_fail($errors);
        }

        try {
            db_transaction(static function (PDO $pdo) use ($userId, $c): void {
                $userSet = [];
                foreach (['first_name', 'last_name', 'phone'] as $k) {
                    if (array_key_exists($k, $c)) {
                        $userSet[$k] = $c[$k];
                    }
                }
                if ($userSet !== []) {
                    db_exec('UPDATE users SET ' . implode(', ', array_map(static function (string $k): string {
                        return $k . ' = ?';
                    }, array_keys($userSet))) . ' WHERE id = ?', array_merge(array_values($userSet), [$userId]));
                }
                $profileSet = [];
                foreach (['branch_id', 'job_title', 'responsibilities', 'hire_date', 'employment_status', 'employee_code'] as $k) {
                    if (array_key_exists($k, $c) && !($k === 'employee_code' && $c[$k] === '')) {
                        $profileSet[$k] = $c[$k];
                    }
                }
                if ($profileSet !== []) {
                    db_exec('UPDATE employee_profiles SET ' . implode(', ', array_map(static function (string $k): string {
                        return $k . ' = ?';
                    }, array_keys($profileSet))) . ' WHERE user_id = ?', array_merge(array_values($profileSet), [$userId]));
                }
            });
        } catch (PDOException $e) {
            if (db_is_duplicate_key($e)) {
                return user_fail(['employee_code' => 'This employee code is already in use.']);
            }
            throw $e;
        }

        $changed = [];
        foreach ($c as $k => $v) {
            if ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = ['from' => $row[$k] ?? null, 'to' => $v];
            }
        }
        if ($changed !== []) {
            if (array_key_exists('branch_id', $changed)) {
                user_employee_history_add($userId, 'branch_changed', $actor, (string) ($row['branch_id'] ?? ''), (string) ($c['branch_id'] ?? ''), null);
            }
            $others = array_diff(array_keys($changed), ['branch_id']);
            if ($others !== []) {
                user_employee_history_add($userId, 'updated', $actor, null, null, 'Changed: ' . implode(', ', $others));
            }
            user_audit('employee.update', 'users', $userId, array_map(static function ($x) { return $x['from']; }, $changed),
                array_map(static function ($x) { return $x['to']; }, $changed), $actor);
        }
        if (($c['employment_status'] ?? '') === 'terminated' && $row['status'] === 'active') {
            user_set_status($userId, 'inactive', $actor, 'Employment terminated');
        }
        return user_ok(['changed' => array_keys($changed)]);
    }
}

if (!function_exists('user_change_employee_role')) {
    /** Change an employee's role (admin / staff / fulfillment). Needs employees.manage. */
    function user_change_employee_role(int $userId, string $newRole, ?int $actorId = null, ?int $branchId = null): array
    {
        $actor = user_actor_id($actorId);
        if (($denied = user_actor_denied($actor, 'employees.manage')) !== null) {
            return $denied;
        }
        if (!in_array($newRole, USER_EMPLOYEE_ROLES, true)) {
            return user_fail('Invalid role.');
        }
        $row = db_row('SELECT u.id, u.role, u.status, e.branch_id FROM users u JOIN employee_profiles e ON e.user_id = u.id WHERE u.id = ?', [$userId]);
        if ($row === null) {
            return user_fail('Employee not found.');
        }
        if ($row['role'] === $newRole) {
            return user_ok(['unchanged' => true]);
        }
        if ($actor === $userId) {
            return user_fail('You cannot change your own role.');
        }
        if ($row['role'] === 'admin' && $row['status'] === 'active' && user_active_admin_count($userId) === 0) {
            return user_fail('At least one active administrator is required.');
        }
        $branch = $branchId !== null ? $branchId : ($row['branch_id'] !== null ? (int) $row['branch_id'] : null);
        if ($newRole !== 'admin' && ($branch === null || !user_branch_active($branch))) {
            return user_fail(['branch_id' => 'Staff and fulfillment employees must be assigned to an available branch.']);
        }

        db_transaction(static function (PDO $pdo) use ($userId, $newRole, $branch): void {
            db_exec('UPDATE users SET role = ? WHERE id = ?', [$newRole, $userId]);
            db_exec('UPDATE employee_profiles SET branch_id = ? WHERE user_id = ?', [$branch, $userId]);
        });
        user_permission_codes($userId, true);
        user_employee_history_add($userId, 'role_changed', $actor, (string) $row['role'], $newRole, null);
        user_audit('employee.role_change', 'users', $userId, ['role' => $row['role']], ['role' => $newRole, 'branch_id' => $branch], $actor);
        return user_ok();
    }
}

if (!function_exists('user_reset_employee_password')) {
    /** Admin sends an employee a password reset link (valid 24 h). Returns the raw token. */
    function user_reset_employee_password(int $userId, ?int $actorId = null, bool $sendEmail = true): array
    {
        $actor = user_actor_id($actorId);
        if (($denied = user_actor_denied($actor, 'employees.manage')) !== null) {
            return $denied;
        }
        $row = db_row("SELECT id FROM users WHERE id = ? AND role IN ('admin','staff','fulfillment')", [$userId]);
        if ($row === null) {
            return user_fail('Employee not found.');
        }
        $token = user_create_reset_token($userId, null, 24 * 60);
        user_employee_history_add($userId, 'password_reset', $actor, null, null, 'Reset link issued by an administrator');
        user_audit('employee.password_reset_issued', 'users', $userId, null, null, $actor);
        $mail = $sendEmail ? user_mail('mail_send_password_reset', $userId, $token) : null;
        return user_ok(['token' => $token, 'mail' => $mail]);
    }
}

if (!function_exists('user_search_employees')) {
    /**
     * Employee list. $filters: q, role, branch_id, status (account), employment_status.
     * Branch-scoped admins/staff should pass their own branch_id.
     */
    function user_search_employees(array $filters = []): array
    {
        $where  = ["u.role IN ('admin','staff','fulfillment')"];
        $params = [];
        $q = user_clean($filters['q'] ?? '');
        if ($q !== '') {
            $like = '%' . db_like_escape($q) . '%';
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR e.employee_code LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        if (in_array((string) ($filters['role'] ?? ''), USER_EMPLOYEE_ROLES, true)) {
            $where[] = 'u.role = ?';
            $params[] = (string) $filters['role'];
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'e.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (in_array((string) ($filters['status'] ?? ''), USER_STATUSES, true)) {
            $where[] = 'u.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (in_array((string) ($filters['employment_status'] ?? ''), ['active', 'on_leave', 'terminated'], true)) {
            $where[] = 'e.employment_status = ?';
            $params[] = (string) $filters['employment_status'];
        }
        return db_all(
            'SELECT u.id, u.role, u.first_name, u.last_name, u.email, u.phone, u.status, u.last_login_at, u.created_at,
                    e.employee_code, e.branch_id, e.job_title, e.hire_date, e.employment_status, b.name AS branch_name
             FROM users u LEFT JOIN employee_profiles e ON e.user_id = u.id LEFT JOIN branches b ON b.id = e.branch_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY u.role, u.last_name, u.first_name',
            $params
        );
    }
}

if (!function_exists('user_employee_detail')) {
    function user_employee_detail(int $userId): ?array
    {
        $user = user_find($userId);
        if ($user === null || !$user['is_employee']) {
            return null;
        }
        return [
            'user'       => $user,
            'profile'    => db_row('SELECT e.*, b.name AS branch_name FROM employee_profiles e LEFT JOIN branches b ON b.id = e.branch_id WHERE e.user_id = ?', [$userId]),
            'permissions' => user_permission_codes($userId),
            'overrides'  => user_permission_overrides($userId),
            'history'    => user_employee_history($userId),
        ];
    }
}

if (!function_exists('user_employee_history')) {
    function user_employee_history(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return db_all(
            "SELECT h.id, h.change_type, h.old_value, h.new_value, h.notes, h.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name
             FROM employee_account_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.employee_user_id = ? ORDER BY h.id DESC LIMIT {$limit}",
            [$userId]
        );
    }
}

if (!function_exists('user_branch_staff')) {
    /** Active employees of a branch (e.g. the "assign to" dropdown of an order). */
    function user_branch_staff(int $branchId, ?string $role = 'fulfillment'): array
    {
        $sql = "SELECT u.id, u.first_name, u.last_name, u.role, e.employee_code
                FROM users u JOIN employee_profiles e ON e.user_id = u.id
                WHERE u.status = 'active' AND e.employment_status = 'active' AND e.branch_id = ?";
        $params = [$branchId];
        if ($role !== null) {
            $sql .= ' AND u.role = ?';
            $params[] = $role;
        }
        return db_all($sql . ' ORDER BY u.first_name, u.last_name', $params);
    }
}

// ---------------------------------------------------------------------
// 22. Maintenance helper (optional)
// ---------------------------------------------------------------------
if (!function_exists('user_cleanup_security_tables')) {
    /**
     * Housekeeping for the security tables. Call from an admin page or a maintenance job:
     * removes login_attempts older than $days and expired / used / verified tokens older than 7 days.
     * @return array{login_attempts:int,password_resets:int,email_verifications:int}
     */
    function user_cleanup_security_tables(int $days = 90): array
    {
        $days = max(7, $days);
        return [
            'login_attempts'      => db_exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL {$days} DAY)"),
            'password_resets'     => db_exec('DELETE FROM password_resets WHERE (used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY)) OR expires_at < (NOW() - INTERVAL 7 DAY)'),
            'email_verifications' => db_exec('DELETE FROM email_verifications WHERE (verified_at IS NOT NULL AND verified_at < (NOW() - INTERVAL 7 DAY)) OR expires_at < (NOW() - INTERVAL 7 DAY)'),
        ];
    }
}