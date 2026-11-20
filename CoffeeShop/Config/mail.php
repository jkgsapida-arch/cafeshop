<?php
/**
 * =====================================================================
 *  COFFEE SHOP WEB SYSTEM  -  Config/mail.php
 *  Project  : Forest & Cream Coffee (Web System and Cloud Computing - Finals)
 *  Database : coffeeshop   (admin/database/schema.sql)
 *  Needs    : PHP 7.4+ (works on PHP 8.x) | no Composer packages required
 * =====================================================================
 *
 *  WHAT THIS FILE DOES
 *    1. Holds the e-mail settings (XAMPP-friendly defaults, overridable
 *       with Config/mail.local.php or environment variables).
 *    2. Sends e-mail through ONE of three drivers:
 *         'log'  (default) -> writes HTML previews to a private temporary
 *                             outbox. The CLI test prints the file path.
 *                             Override with MAIL_LOG_DIR outside the web root.
 *         'smtp'           -> built-in SMTP client (STARTTLS / SSL / AUTH
 *                             LOGIN or PLAIN). Works with Gmail (App Password),
 *                             Brevo, Mailtrap, Mailpit, etc.
 *         'mail'           -> PHP's mail() (needs sendmail set up in XAMPP).
 *    3. Wraps every message in a Forest & Cream branded HTML layout with
 *       a plain-text alternative.
 *    4. Records every e-mail in the `notifications` table (status, attempts,
 *       error message, dedupe_key) and respects `customer_preferences`
 *       (notify_email, marketing_consent)   -> features 12, 49, 50.
 *    5. Provides ready-made senders for the e-mails in the requirements.
 *
 *  HOW TO USE (note the capital "C" in Config)
 *      require_once __DIR__ . '/../Config/mail.php';      // also loads Config/db.php
 *
 *      // --- account e-mails (pass the RAW token; the DB stores only its SHA-256 hash)
 *      mail_send_welcome($userId);                         // or a user row array
 *      mail_send_verification($user, $rawToken);           // email_verifications
 *      mail_send_password_reset($user, $rawToken);         // password_resets
 *
 *      // --- order e-mails (send AFTER db_transaction() has committed)
 *      mail_send_order_event($orderId, 'order_confirmation');
 *      mail_send_order_event($orderId, 'payment_confirmation');
 *      mail_send_order_event($orderId, 'order_ready_for_pickup');
 *      mail_send_order_event($orderId, 'order_shipped');
 *      mail_send_order_event($orderId, 'order_delivered');
 *      mail_send_order_event($orderId, 'order_cancelled', ['reason' => 'Requested by customer']);
 *
 *      // --- returns / cancellations / refunds
 *      mail_send_return_update($returnRequestId);
 *      mail_send_refund_update($refundId);
 *
 *      // --- committed business events (pass the indicated record ID)
 *      mail_send_gift_card($giftCardId);                   // gift_cards.id; payment success or due-card job
 *      mail_send_reward_earned($transactionId);            // loyalty_transactions.id
 *      mail_send_support_reply($messageId);                // support_messages.id; public staff reply
 *      mail_send_admin_alert($staffNotificationId, $employeeUserId); // optional copy of a staff in-app alert
 *
 *      // --- anything else (custom event using a notification_templates row)
 *      mail_notify(['event' => 'my_event', 'user_id' => 5, 'to_email' => 'a@b.com',
 *                   'vars' => ['first_name' => 'Ana'], 'dedupe_key' => 'my_event:5:email']);
 *
 *      // --- plain sending (no database involved)
 *      mail_send('a@b.com', 'Subject', '<p>Hello</p>');
 *
 *  Every sender RETURNS an array and NEVER throws for delivery problems:
 *      ['ok' => bool, 'status' => 'sent'|'failed'|'skipped'|'duplicate',
 *       'id' => notification id|null, 'error' => string|null, ...]
 *  A failed e-mail must never break checkout or registration - just check
 *  the result (or ignore it) and carry on.
 *
 *  SWITCHING TO REAL E-MAIL (Gmail example)
 *    Create Config/mail.local.php (and add it to .gitignore) containing:
 *        <?php
 *        define('MAIL_DRIVER',       'smtp');
 *        define('MAIL_HOST',         'smtp.gmail.com');
 *        define('MAIL_PORT',         587);
 *        define('MAIL_ENCRYPTION',   'tls');
 *        // Optional: use your SMTP client's DNS hostname if the relay requires one.
 *        // define('MAIL_EHLO_NAME', 'shop.example.com');
 *        define('MAIL_USERNAME',     '');
 *        define('MAIL_PASSWORD',     '');   // Google "App Password" (needs 2-step verification)
 *        define('MAIL_FROM_ADDRESS', 'yourname@gmail.com');    // Gmail rewrites From to the account anyway
 *    Test it from a terminal (XAMPP shell):
 *        C:\xampp\php\php.exe C:\xampp\htdocs\CoffeeShop\Config\mail.php test you@example.com
 *
 *  RETRYING FAILED E-MAILS (no cron on XAMPP)
 *      mail_retry_failed();    // call from an admin page, or run from a terminal / Windows Task Scheduler:
 *      C:\xampp\php\php.exe C:\xampp\htdocs\CoffeeShop\Config\mail.php retry
 *
 *  Emails/ VIEW CONTRACT (optional)
 *    If Emails/confirmation.php, cancellation.php or shipping.php exist, they
 *    are used for the events order_confirmation, order_cancelled and
 *    order_shipped. Each file receives ONE variable, $vars (array: see
 *    mail_load_order_context()), and must only ECHO an HTML fragment
 *    (no <html>/<body>, no function declarations). mail.php adds the branded
 *    header/footer. If a file is missing, empty or throws, the matching
 *    notification_templates row from schema.sql is used instead.
 *
 *  SECURITY NOTES
 *    * Links in e-mails use MAIL_APP_URL (never the browser's Host header),
 *      which prevents password-reset-link poisoning.
 *    * Header values are stripped of CR/LF (header injection).
 *    * Keep credentials in private configuration. Store the log outbox privately
 *      because preview HTML contains the actual message, including redemption codes.
 *    * This file cannot be run from the browser.
 * =====================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 0. Block direct browser access (http://localhost/CoffeeShop/Config/mail.php)
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/db.php';   // db_* helpers + Asia/Manila timezone

// ---------------------------------------------------------------------
// 1. Settings
//    Priority: Config/mail.local.php  >  environment variable  >  default
//    Environment variables: COFFEESHOP_MAIL_DRIVER, _HOST, _PORT,
//    _ENCRYPTION, _EHLO_NAME, _USERNAME, _PASSWORD, _FROM, _FROM_NAME, _REPLY_TO,
//    COFFEESHOP_APP_URL
// ---------------------------------------------------------------------
if (!function_exists('mail_env')) {
    /** Read an environment variable, falling back to a default. */
    function mail_env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : (string) $value;
    }
}

if (!function_exists('mail_env_bool')) {
    function mail_env_bool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

// Optional private overrides (keep passwords OUT of this file and out of Git).
(static function (): void {
    $local = __DIR__ . '/mail.local.php';
    if (is_file($local)) {
        require $local;
    }
})();

// Brand / store
if (!defined('MAIL_STORE_NAME'))    define('MAIL_STORE_NAME',    'Forest & Cream Coffee');            // DB setting "store_name" wins at send time
if (!defined('MAIL_SUPPORT_EMAIL')) define('MAIL_SUPPORT_EMAIL', 'support@coffeeshop.local');          // DB setting "support_email" wins at send time

// Forest & Cream palette (edit to match your UI/UX specification)
if (!defined('MAIL_COLOR_FOREST'))  define('MAIL_COLOR_FOREST',  '#2F4F3A');   // header / buttons
if (!defined('MAIL_COLOR_CREAM'))   define('MAIL_COLOR_CREAM',   '#F6F0E4');   // page background / header text
if (!defined('MAIL_COLOR_SAND'))    define('MAIL_COLOR_SAND',    '#EFE6D2');   // footer background
if (!defined('MAIL_COLOR_TEXT'))    define('MAIL_COLOR_TEXT',    '#2B2B2B');
if (!defined('MAIL_COLOR_MUTED'))   define('MAIL_COLOR_MUTED',   '#6B6B6B');
if (!defined('MAIL_LOGO_URL'))      define('MAIL_LOGO_URL',      '');          // optional absolute https:// URL to a logo image

// Delivery driver: log | smtp | mail
if (!defined('MAIL_DRIVER'))        define('MAIL_DRIVER',        mail_env('COFFEESHOP_MAIL_DRIVER', 'log'));

// SMTP (used when MAIL_DRIVER = smtp)
if (!defined('MAIL_HOST'))          define('MAIL_HOST',          mail_env('COFFEESHOP_MAIL_HOST', 'smtp.gmail.com'));
if (!defined('MAIL_PORT'))          define('MAIL_PORT',          (int) mail_env('COFFEESHOP_MAIL_PORT', '587'));
if (!defined('MAIL_ENCRYPTION'))    define('MAIL_ENCRYPTION',    mail_env('COFFEESHOP_MAIL_ENCRYPTION', 'tls'));   // tls (STARTTLS, 587) | ssl (465) | none (Mailpit/MailHog)
if (!defined('MAIL_EHLO_NAME'))     define('MAIL_EHLO_NAME',     mail_env('COFFEESHOP_MAIL_EHLO_NAME', 'localhost'));
if (!defined('MAIL_USERNAME'))      define('MAIL_USERNAME',      mail_env('COFFEESHOP_MAIL_USERNAME', ''));
if (!defined('MAIL_PASSWORD'))      define('MAIL_PASSWORD',      mail_env('COFFEESHOP_MAIL_PASSWORD', ''));
if (!defined('MAIL_TIMEOUT'))       define('MAIL_TIMEOUT',       15);          // seconds
if (!defined('MAIL_VERIFY_PEER'))   define('MAIL_VERIFY_PEER',   mail_env_bool('COFFEESHOP_MAIL_VERIFY_PEER', true));  // set false ONLY on XAMPP if you get certificate errors

// Sender
if (!defined('MAIL_FROM_ADDRESS'))  define('MAIL_FROM_ADDRESS',  mail_env('COFFEESHOP_MAIL_FROM', 'no-reply@coffeeshop.local'));
if (!defined('MAIL_FROM_NAME'))     define('MAIL_FROM_NAME',     mail_env('COFFEESHOP_MAIL_FROM_NAME', MAIL_STORE_NAME));
if (!defined('MAIL_REPLY_TO'))      define('MAIL_REPLY_TO',      mail_env('COFFEESHOP_MAIL_REPLY_TO', ''));        // empty = support e-mail

// Links inside e-mails. CHANGE THIS when you deploy (no trailing slash).
if (!defined('MAIL_APP_URL'))       define('MAIL_APP_URL',       rtrim(mail_env('COFFEESHOP_APP_URL', 'http://localhost/CoffeeShop'), '/'));
if (!defined('MAIL_VERIFY_PATH'))   define('MAIL_VERIFY_PATH',   '/auth/verify-email.php');     // page that receives ?token=...
if (!defined('MAIL_RESET_PATH'))    define('MAIL_RESET_PATH',    '/auth/reset-password.php');   // page that receives ?token=...
if (!defined('MAIL_ORDERS_PATH'))   define('MAIL_ORDERS_PATH',   '/Customer/orders.php');
if (!defined('MAIL_PREFS_PATH'))    define('MAIL_PREFS_PATH',    '/Customer/dashboard.php');    // where customers manage notification preferences

// Files / retries
if (!defined('MAIL_LOG_DIR')) {
    $defaultOutbox = rtrim(sys_get_temp_dir(), '/\\') . '/coffeeshop-mail-' . substr(hash('sha256', dirname(__DIR__)), 0, 16);
    define('MAIL_LOG_DIR', mail_env('COFFEESHOP_MAIL_LOG_DIR', $defaultOutbox));
    unset($defaultOutbox);
}
if (!defined('MAIL_VIEWS_DIR'))     define('MAIL_VIEWS_DIR',     dirname(__DIR__) . '/Emails');
if (!defined('MAIL_MAX_ATTEMPTS'))  define('MAIL_MAX_ATTEMPTS',  3);           // total tries per notification
if (!defined('MAIL_RETRY_MINUTES')) define('MAIL_RETRY_MINUTES', 10);          // wait between automatic retries

// ---------------------------------------------------------------------
// 2. Small helpers
// ---------------------------------------------------------------------
if (!function_exists('mail_e')) {
    /** HTML-escape text (also usable inside Emails/*.php views). */
    function mail_e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('mail_clean_header')) {
    /** Remove line breaks / NULs so user input can never inject extra headers. */
    function mail_clean_header(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', ' ', $value));
    }
}

if (!function_exists('mail_valid_email')) {
    function mail_valid_email(string $email): bool
    {
        return $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('mail_driver')) {
    function mail_driver(): string
    {
        $driver = strtolower(trim((string) MAIL_DRIVER));
        return in_array($driver, ['log', 'smtp', 'mail'], true) ? $driver : 'log';
    }
}

if (!function_exists('mail_truncate')) {
    /** Cut a string to at most $bytes bytes without breaking a UTF-8 character. */
    function mail_truncate(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $bytes, 'UTF-8');
        }
        // Walk back to the start of any UTF-8 character split by the byte limit.
        while ($bytes > 0 && (ord($text[$bytes]) & 0xC0) === 0x80) {
            $bytes--;
        }
        return substr($text, 0, $bytes);
    }
}

if (!function_exists('mail_money')) {
    /** "PHP 1,234.50" (same style as the seeded rewards). */
    function mail_money($amount): string
    {
        return 'PHP ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('mail_datetime')) {
    /** "Nov 19, 2026 3:45 PM" or '' when empty. */
    function mail_datetime($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $time = strtotime($value);
        return $time === false ? $value : date('M j, Y g:i A', $time);
    }
}

if (!function_exists('mail_label')) {
    /** ready_for_pickup -> "Ready for pickup", dine_in -> "Dine-in", gcash -> "GCash" ... */
    function mail_label(string $value): string
    {
        static $special = [
            'dine_in'          => 'Dine-in',
            'pickup'           => 'Store pickup',
            'gcash'            => 'GCash',
            'paypal'           => 'PayPal',
            'cash_on_delivery' => 'Cash on delivery',
        ];
        if (isset($special[$value])) {
            return $special[$value];
        }
        return ucfirst(str_replace('_', ' ', $value));
    }
}

if (!function_exists('mail_url')) {
    /** Absolute link into the site, e.g. mail_url('/auth/reset-password.php', ['token' => $t]). */
    function mail_url(string $path = '', array $query = []): string
    {
        $url = MAIL_APP_URL . ($path !== '' ? '/' . ltrim($path, '/') : '');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }
}

if (!function_exists('mail_fail')) {
    function mail_fail(string $message): array
    {
        error_log('[CoffeeShop][MAIL] ' . $message);
        return ['ok' => false, 'status' => 'failed', 'id' => null, 'error' => $message];
    }
}

// ---------------------------------------------------------------------
// 3. Text <-> HTML conversion and template rendering
// ---------------------------------------------------------------------
if (!function_exists('mail_text_to_html')) {
    /** Escape plain text, turn blank lines into paragraphs and URLs into links. */
    function mail_text_to_html(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        $out = '';
        foreach (preg_split("/\n{2,}/", $text) as $paragraph) {
            $p = mail_e($paragraph);
            $p = (string) preg_replace_callback('~https?://[^\s<]+~i', static function (array $m): string {
                $url   = $m[0];
                $trail = '';
                while ($url !== '' && strpos('.,;:!?)', substr($url, -1)) !== false) {
                    $trail = substr($url, -1) . $trail;
                    $url   = substr($url, 0, -1);
                }
                return '<a href="' . $url . '" style="color:' . MAIL_COLOR_FOREST
                    . ';text-decoration:underline;word-break:break-all;">' . $url . '</a>' . $trail;
            }, $p);
            $out .= '<p style="margin:0 0 16px 0;">' . nl2br($p, false) . '</p>';
        }
        return $out;
    }
}

if (!function_exists('mail_html_to_text')) {
    /** Rough plain-text version of an HTML fragment (for the text/plain part). */
    function mail_html_to_text(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|head)\b.*?</\1>~is', '', $html);
        $html = (string) preg_replace_callback(
            '~<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',
            static function (array $m): string {
                $label = trim(strip_tags($m[2]));
                return ($label === '' || $label === $m[1]) ? $m[1] : $label . ' (' . $m[1] . ')';
            },
            $html
        );
        $html = (string) preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        $html = (string) preg_replace('~<li[^>]*>~i', "\n- ", $html);
        $html = (string) preg_replace('~</(p|div|tr|h[1-6]|ul|ol|table)>~i', "\n\n", $html);
        $html = (string) preg_replace('~</t[dh]>~i', '  ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}

if (!function_exists('mail_render_string')) {
    /**
     * Replace {{placeholders}} with values from $vars. Unknown or non-scalar
     * placeholders become empty (customers never see a raw "{{x}}").
     * Values are inserted as-is: escape later (mail_text_to_html does it).
     */
    function mail_render_string(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', static function (array $m) use ($vars): string {
            $value = $vars[$m[1]] ?? '';
            return (is_scalar($value) || $value === null) ? (string) $value : '';
        }, $template);
    }
}

// ---------------------------------------------------------------------
// 4. Branded HTML layout (Forest & Cream)
// ---------------------------------------------------------------------
if (!function_exists('mail_is_full_document')) {
    function mail_is_full_document(string $html): bool
    {
        return stripos($html, '<html') !== false;
    }
}

if (!function_exists('mail_layout')) {
    /**
     * Wrap an HTML fragment in the branded e-mail layout.
     *
     * @param array $opts store_name, support_email, preheader,
     *                    cta_label + cta_url (button), marketing (bool)
     */
    function mail_layout(string $bodyHtml, array $opts = []): string
    {
        $store     = mail_e((string) ($opts['store_name'] ?? MAIL_STORE_NAME));
        $support   = mail_e((string) ($opts['support_email'] ?? MAIL_SUPPORT_EMAIL));
        $preheader = mail_e(mail_truncate((string) ($opts['preheader'] ?? ''), 140));
        $forest    = mail_e(MAIL_COLOR_FOREST);
        $cream     = mail_e(MAIL_COLOR_CREAM);
        $sand      = mail_e(MAIL_COLOR_SAND);
        $text      = mail_e(MAIL_COLOR_TEXT);
        $muted     = mail_e(MAIL_COLOR_MUTED);

        // Header: logo image (optional) + store name
        $logo = '';
        if (MAIL_LOGO_URL !== '' && preg_match('~^https?://~i', MAIL_LOGO_URL)) {
            $logo = '<img src="' . mail_e(MAIL_LOGO_URL) . '" alt="" height="36" style="vertical-align:middle;margin-right:10px;border:0;">';
        }

        // Call-to-action button
        $cta   = '';
        $label = (string) ($opts['cta_label'] ?? '');
        $url   = (string) ($opts['cta_url'] ?? '');
        if ($label !== '' && preg_match('~^https?://~i', $url)) {
            $cta = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 8px 0;"><tr>'
                . '<td bgcolor="' . $forest . '" style="border-radius:8px;">'
                . '<a href="' . mail_e($url) . '" style="display:inline-block;padding:14px 28px;font-family:Arial,Helvetica,sans-serif;'
                . 'font-size:15px;font-weight:bold;color:' . $cream . ';text-decoration:none;">' . mail_e($label) . '</a>'
                . '</td></tr></table>';
        }

        $footer = 'You are receiving this e-mail because of your account or an order with ' . $store . '.<br>'
            . 'Questions? Contact us at <a href="mailto:' . $support . '" style="color:' . $forest . ';">' . $support . '</a>.';
        if (!empty($opts['marketing'])) {
            $footer .= '<br>You can turn off promotional e-mails anytime in your account notification settings: '
                . '<a href="' . mail_e(mail_url(MAIL_PREFS_PATH)) . '" style="color:' . $forest . ';">manage preferences</a>.';
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . $store . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $cream . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:' . $cream . ';">' . $preheader . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $cream . '">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">'
            // header
            . '<tr><td bgcolor="' . $forest . '" style="padding:24px 32px;border-radius:12px 12px 0 0;">'
            . $logo . '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:24px;letter-spacing:0.5px;color:' . $cream . ';">' . $store . '</span>'
            . '</td></tr>'
            // body
            . '<tr><td bgcolor="#FFFFFF" style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:' . $text . ';">'
            . $bodyHtml . $cta
            . '</td></tr>'
            // footer
            . '<tr><td bgcolor="' . $sand . '" style="padding:20px 32px;border-radius:0 0 12px 12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:' . $muted . ';">'
            . $footer . '<br>&copy; ' . date('Y') . ' ' . $store
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}

// ---------------------------------------------------------------------
// 5. Building the raw message (MIME) and the three delivery drivers
// ---------------------------------------------------------------------
if (!function_exists('mail_encode_header')) {
    /** Encode a header value (subject / display name) for UTF-8 text. */
    function mail_encode_header(string $value): string
    {
        $value = mail_clean_header($value);
        if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

if (!function_exists('mail_format_address')) {
    /** "Display Name" <email@example.com> */
    function mail_format_address(string $email, string $name = ''): string
    {
        $name = mail_clean_header($name);
        if ($name === '') {
            return $email;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name)) {
            return '"' . addcslashes($name, "\"\\") . '" <' . $email . '>';
        }
        return mail_encode_header($name) . ' <' . $email . '>';
    }
}

if (!function_exists('mail_build_message')) {
    /**
     * Build headers + multipart/alternative body (text + HTML, base64).
     * @return array{headers:string[],body:string,message_id:string,from:string}
     */
    function mail_build_message(string $html, string $text, array $opts = []): array
    {
        $from     = (string) ($opts['from_address'] ?? MAIL_FROM_ADDRESS);
        $fromName = (string) ($opts['from_name'] ?? MAIL_FROM_NAME);
        $replyTo  = trim((string) ($opts['reply_to'] ?? MAIL_REPLY_TO));
        $domain   = substr((string) strrchr($from, '@'), 1);
        $domain   = $domain !== '' ? $domain : 'localhost';

        $boundary  = 'cs_' . bin2hex(random_bytes(12));
        $messageId = '<' . bin2hex(random_bytes(14)) . '@' . $domain . '>';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . mail_format_address($from, $fromName),
        ];
        if (mail_valid_email($replyTo)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: CoffeeShop-Mailer/1.0';
        $unsub = trim((string) ($opts['list_unsubscribe'] ?? ''));
        if ($unsub !== '' && preg_match('~^https?://~i', $unsub)) {
            $headers[] = 'List-Unsubscribe: <' . mail_clean_header($unsub) . '>';
        }
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $norm = static function (string $s): string {
            return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $s);
        };
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($norm($text)))
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($norm($html)))
            . '--' . $boundary . "--\r\n";

        return ['headers' => $headers, 'body' => $body, 'message_id' => $messageId, 'from' => $from];
    }
}

if (!function_exists('mail_deliver_log')) {
    /** Driver "log": save the e-mail as an HTML file you can open in the browser. */
    function mail_deliver_log(string $toHeader, string $subject, string $html, string $messageId): string
    {
        $dir = rtrim(str_replace('\\', '/', (string) MAIL_LOG_DIR), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the e-mail log folder: ' . $dir);
        }
        // Keep the log folder private (Apache 2.4 / 2.2) - created once.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }

        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $subject), '-'));
        $slug = substr($slug !== '' ? $slug : 'email', 0, 40);
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . $slug . '.html';

        $comment = static function (string $s): string {
            return str_replace('--', '- -', mail_clean_header($s));
        };
        $content = "<!--\n  LOGGED E-MAIL (MAIL_DRIVER = log) - not actually sent\n"
            . '  To:         ' . $comment($toHeader) . "\n"
            . '  Subject:    ' . $comment($subject) . "\n"
            . '  Message-ID: ' . $comment($messageId) . "\n"
            . '  Date:       ' . date('Y-m-d H:i:s') . "\n-->\n" . $html;

        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the e-mail log file: ' . $file);
        }
        return $file;
    }
}

if (!function_exists('mail_deliver_mail')) {
    /** Driver "mail": PHP mail() (XAMPP needs sendmail configured in php.ini / sendmail.ini). */
    function mail_deliver_mail(string $toHeader, string $subject, array $msg): void
    {
        $params = '';
        if (stripos(PHP_OS, 'WIN') === 0) {
            @ini_set('sendmail_from', $msg['from']);
        } else {
            $params = '-f' . $msg['from'];
        }
        $ok = @mail($toHeader, mail_encode_header($subject), $msg['body'], implode("\r\n", $msg['headers']), $params);
        if (!$ok) {
            throw new RuntimeException('PHP mail() failed. Configure sendmail in XAMPP, or set MAIL_DRIVER to "smtp" or "log".');
        }
    }
}

if (!function_exists('mail_smtp_read')) {
    /** Read one (possibly multi-line) SMTP reply. */
    function mail_smtp_read($fp): string
    {
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($reply === '') {
            $meta = stream_get_meta_data($fp);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'The SMTP server timed out.' : 'The SMTP connection closed unexpectedly.');
        }
        return rtrim($reply);
    }
}

if (!function_exists('mail_smtp_cmd')) {
    /** Send one SMTP command and require one of the expected reply codes. */
    function mail_smtp_cmd($fp, string $command, array $expected, bool $secret = false): string
    {
        if (@fwrite($fp, $command . "\r\n") === false) {
            throw new RuntimeException('Could not write to the SMTP server.');
        }
        $reply = mail_smtp_read($fp);
        if (!in_array((int) substr($reply, 0, 3), $expected, true)) {
            if ($secret) {
                // Some relays echo the submitted credential in the diagnostic.
                throw new RuntimeException('SMTP authentication failed (reply ' . (int) substr($reply, 0, 3) . ').');
            }
            throw new RuntimeException('SMTP error after ' . strtok($command, ' ') . ': ' . $reply);
        }
        return $reply;
    }
}

if (!function_exists('mail_deliver_smtp')) {
    /** Driver "smtp": minimal SMTP client (STARTTLS / implicit SSL, AUTH LOGIN or PLAIN). */
    function mail_deliver_smtp(string $envelopeFrom, string $toEmail, string $toHeader, string $subject, array $msg): void
    {
        $host = trim((string) MAIL_HOST);
        $port = (int) MAIL_PORT;
        $enc  = strtolower(trim((string) MAIL_ENCRYPTION));
        if ($host === '' || $port < 1) {
            throw new RuntimeException('SMTP is not configured: set MAIL_HOST and MAIL_PORT.');
        }

        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('MAIL_ENCRYPTION must be tls, ssl or none.');
        }
        $ehloName = (string) MAIL_EHLO_NAME;
        if ($ehloName === '' || filter_var($ehloName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException('MAIL_EHLO_NAME must be a valid SMTP client hostname.');
        }

        $verify = (bool) MAIL_VERIFY_PEER;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            'allow_self_signed' => !$verify,
            'peer_name'         => $host,
        ]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, (float) MAIL_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('Could not connect to ' . $host . ':' . $port . ' (' . $errstr . ').');
        }
        stream_set_timeout($fp, (int) MAIL_TIMEOUT);

        try {
            if ((int) substr(mail_smtp_read($fp), 0, 3) !== 220) {
                throw new RuntimeException('The SMTP server refused the connection.');
            }
            $capabilities = mail_smtp_cmd($fp, 'EHLO ' . $ehloName, [250]);

            if ($enc === 'tls') {
                mail_smtp_cmd($fp, 'STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($fp, true, $method)) {
                    throw new RuntimeException('Could not start TLS encryption (check the CA certificates in php.ini, or MAIL_VERIFY_PEER).');
                }
                $capabilities = mail_smtp_cmd($fp, 'EHLO ' . $ehloName, [250]);
            }

            if ((string) MAIL_USERNAME !== '') {
                // Plaintext is useful for local mail catchers. Require TLS for remote credentials.
                if ($enc === 'none' && !preg_match('/^(?:127\.\d+\.\d+\.\d+|\[::1\]):\d+$/D', (string) stream_socket_get_name($fp, true))) {
                    throw new RuntimeException('Remote SMTP authentication requires MAIL_ENCRYPTION tls or ssl.');
                }
                preg_match_all('/^250[- ]AUTH[ =]([^\r\n]+)/mi', $capabilities, $matches);
                $mechanisms = preg_split('/\s+/', strtoupper(trim(implode(' ', $matches[1]))));
                if (in_array('LOGIN', $mechanisms, true)) {
                    mail_smtp_cmd($fp, 'AUTH LOGIN', [334], true);
                    mail_smtp_cmd($fp, base64_encode((string) MAIL_USERNAME), [334], true);
                    mail_smtp_cmd($fp, base64_encode((string) MAIL_PASSWORD), [235], true);
                } elseif (in_array('PLAIN', $mechanisms, true)) {
                    mail_smtp_cmd($fp, 'AUTH PLAIN', [334], true);
                    mail_smtp_cmd($fp, base64_encode("\0" . MAIL_USERNAME . "\0" . MAIL_PASSWORD), [235], true);
                } else {
                    throw new RuntimeException('The SMTP server did not advertise AUTH LOGIN or PLAIN.');
                }
            }

            mail_smtp_cmd($fp, 'MAIL FROM:<' . $envelopeFrom . '>', [250]);
            mail_smtp_cmd($fp, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            mail_smtp_cmd($fp, 'DATA', [354]);

            $raw = 'To: ' . $toHeader . "\r\n"
                . 'Subject: ' . mail_encode_header($subject) . "\r\n"
                . implode("\r\n", $msg['headers']) . "\r\n\r\n" . $msg['body'];
            $raw = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $raw);
            $raw = (string) preg_replace('/^\./m', '..', $raw);   // dot-stuffing

            if (@fwrite($fp, $raw . "\r\n.\r\n") === false) {
                throw new RuntimeException('Could not send the message data to the SMTP server.');
            }
            $reply = mail_smtp_read($fp);
            if ((int) substr($reply, 0, 3) !== 250) {
                throw new RuntimeException('The SMTP server rejected the message (reply ' . (int) substr($reply, 0, 3) . ').');
            }
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }
}

if (!function_exists('mail_send')) {
    /**
     * Send ONE e-mail. Never throws; check the returned array.
     *
     * @param string|array $to      'a@b.com'  or  ['email' => 'a@b.com', 'name' => 'Ana Cruz']
     * @param string|null  $text    plain-text version (generated from $html when null)
     * @param array        $options from_address, from_name, reply_to, list_unsubscribe
     * @return array{ok:bool,driver:string,error:?string,message_id:?string,file:?string}
     */
    function mail_send($to, string $subject, string $html, ?string $text = null, array $options = []): array
    {
        $result = ['ok' => false, 'driver' => mail_driver(), 'error' => null, 'message_id' => null, 'file' => null];

        try {
            $email = trim((string) (is_array($to) ? ($to['email'] ?? '') : $to));
            $name  = is_array($to) ? (string) ($to['name'] ?? '') : '';
            if (!mail_valid_email($email)) {
                throw new InvalidArgumentException('Invalid recipient e-mail address.');
            }
            if (!mail_valid_email((string) ($options['from_address'] ?? MAIL_FROM_ADDRESS))) {
                throw new InvalidArgumentException('MAIL_FROM_ADDRESS is not a valid e-mail address.');
            }
            $subject = mail_clean_header($subject);
            if ($subject === '') {
                throw new InvalidArgumentException('The e-mail subject is empty.');
            }

            $text     = $text ?? mail_html_to_text($html);
            $msg      = mail_build_message($html, $text, $options);
            $toHeader = mail_format_address($email, $name);
            $result['message_id'] = $msg['message_id'];

            switch ($result['driver']) {
                case 'smtp':
                    mail_deliver_smtp($msg['from'], $email, $toHeader, $subject, $msg);
                    break;
                case 'mail':
                    mail_deliver_mail($toHeader, $subject, $msg);
                    break;
                default:
                    $result['file'] = mail_deliver_log($toHeader, $subject, $html, $msg['message_id']);
            }
            $result['ok'] = true;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            error_log('[CoffeeShop][MAIL] ' . $result['driver'] . ' delivery failed: ' . $e->getMessage());
        }

        return $result;
    }
}

// ---------------------------------------------------------------------
// 6. Templates (notification_templates table, Emails/ views, fallbacks)
// ---------------------------------------------------------------------
if (!function_exists('mail_setting')) {
    /** Value from system_settings (store_name, support_email, expiry times...). */
    function mail_setting(string $key, string $default = ''): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (db_all('SELECT setting_key, setting_value FROM system_settings') as $row) {
                    $cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
                }
            } catch (Throwable $e) {
                error_log('[CoffeeShop][MAIL] Could not read system_settings: ' . $e->getMessage());
            }
        }
        return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
    }
}

if (!function_exists('mail_default_vars')) {
    /** Values every template can use: {{store_name}} {{support_email}} {{store_url}} {{year}}. Caller values win. */
    function mail_default_vars(array $vars): array
    {
        return $vars + [
            'store_name'    => mail_setting('store_name', MAIL_STORE_NAME),
            'support_email' => mail_setting('support_email', MAIL_SUPPORT_EMAIL),
            'store_url'     => mail_url(),
            'year'          => date('Y'),
        ];
    }
}

if (!function_exists('mail_fallback_templates')) {
    /** Used only when notification_templates has no e-mail row for these critical events. */
    function mail_fallback_templates(): array
    {
        return [
            'gift_card_delivery' => [
                'subject' => '{{sender_name}} sent you a {{store_name}} gift card',
                'body'    => "Hi {{recipient_name}},\n\n{{sender_name}} sent you a gift card worth {{amount}}.\n\n{{message}}\n\nGift card code: {{gift_card_code}}\nValid until: {{expires_at}}\nRedeem it at checkout: {{store_url}}",
            ],
            'admin_alert' => [
                'subject' => '{{alert_subject}}',
                'body'    => '{{alert_message}}',
            ],
            'welcome' => [
                'subject' => 'Welcome to {{store_name}}',
                'body'    => "Hi {{first_name}},\n\nWelcome to {{store_name}}! Your account is ready.",
            ],
            'reward_earned' => [
                'subject' => 'You earned {{points}} points',
                'body'    => "Hi {{first_name}},\n\nYou earned {{points}} points for order {{order_number}}. Your balance after this award was {{points_balance}} points.",
            ],
            'reward_earned_no_order' => [
                'subject' => 'You earned {{points}} points',
                'body'    => "Hi {{first_name}},\n\nYou earned {{points}} loyalty points. Your balance after this award was {{points_balance}} points.",
            ],
            'reward_milestone' => [
                'subject' => 'You reached a rewards milestone',
                'body'    => "Hi {{first_name}},\n\nYour {{points}} new points brought your balance to {{points_balance}}. You reached the points required for {{reward_names}}. Check your dashboard for current availability.",
            ],
            'support_reply' => [
                'subject' => 'Re: {{ticket_subject}} [{{ticket_number}}]',
                'body'    => "Hi {{first_name}},\n\nOur team replied to support request {{ticket_number}}:\n\n{{reply_message}}\n\nYour account: {{ticket_url}}",
            ],
            'support_reply_guest' => [
                'subject' => 'Re: {{ticket_subject}} [{{ticket_number}}]',
                'body'    => "Hi {{first_name}},\n\nOur team replied to support request {{ticket_number}}:\n\n{{reply_message}}\n\nReply to this email and include your ticket number if you need more help.",
            ],
            'email_verification' => [
                'subject' => 'Verify your email',
                'body'    => "Hi {{first_name}},\n\nPlease verify your email using this link (valid for {{expiry_hours}} hours):\n{{verification_link}}",
            ],
            'password_reset' => [
                'subject' => 'Reset your password',
                'body'    => "Hi {{first_name}},\n\nReset your password using this link (valid for {{expiry_minutes}} minutes):\n{{reset_link}}\n\nIf you did not ask for this, you can ignore this e-mail.",
            ],
        ];
    }
}

if (!function_exists('mail_find_template')) {
    /** E-mail template row (id, subject, body_template, is_active) or null. */
    function mail_find_template(string $code, bool $strict = false): ?array
    {
        try {
            return db_row(
                "SELECT id, subject, body_template, is_active FROM notification_templates
                 WHERE code = ? AND channel = 'email' LIMIT 1",
                [$code]
            );
        } catch (Throwable $e) {
            if ($strict) {
                throw new RuntimeException('Could not read the e-mail template. Retry once the database is available.', 0, $e);
            }
            error_log('[CoffeeShop][MAIL] Template lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mail_view_path')) {
    /** Emails/ view file for an event, if the file exists. */
    function mail_view_path(string $event): ?string
    {
        static $map = [
            'order_confirmation' => 'confirmation.php',
            'order_cancelled'    => 'cancellation.php',
            'order_shipped'      => 'shipping.php',
        ];
        if (!isset($map[$event])) {
            return null;
        }
        $path = rtrim((string) MAIL_VIEWS_DIR, '/\\') . DIRECTORY_SEPARATOR . $map[$event];
        return is_file($path) ? $path : null;
    }
}

if (!function_exists('mail_render_view')) {
    /** Include an Emails/ view with $vars and return the HTML it echoes (or returns). */
    function mail_render_view(string $file, array $vars): string
    {
        return (static function (string $__file, array $vars): string {
            ob_start();
            try {
                $returned = include $__file;
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            $out = (string) ob_get_clean();
            return (trim($out) === '' && is_string($returned)) ? $returned : $out;
        })($file, $vars);
    }
}

if (!function_exists('mail_compose')) {
    /**
     * Build subject / HTML fragment / plain text for an event.
     * @return array{disabled:bool,subject:string,html:string,text:string,template_id:?int,vars:array}
     * @throws RuntimeException when no template exists for the event
     */
    function mail_compose(string $event, array $vars, bool $strict = false): array
    {
        $vars = mail_default_vars($vars);
        $tpl = mail_find_template($event, $strict);
        $family = ['reward_earned_no_order' => 'reward_earned', 'reward_milestone' => 'reward_earned', 'support_reply_guest' => 'support_reply'];
        $base = isset($family[$event]) ? mail_find_template($family[$event], $strict) : null;
        foreach ([$base, $tpl] as $template) {
            if ($template !== null && (int) $template['is_active'] !== 1) {
                return ['disabled' => true, 'subject' => '', 'html' => '', 'text' => '', 'template_id' => (int) $template['id'], 'vars' => $vars];
            }
        }
        $templateId = null;

        if ($tpl !== null) {
            $templateId = (int) $tpl['id'];
            $subjectTpl = (string) ($tpl['subject'] ?? '');
            $bodyTpl    = (string) $tpl['body_template'];
        } else {
            $fallback = mail_fallback_templates()[$event] ?? null;
            if ($fallback === null) {
                throw new RuntimeException("No e-mail template found for event '" . $event . "'.");
            }
            $subjectTpl = $fallback['subject'];
            $bodyTpl    = $fallback['body'];
        }

        $subject = mail_clean_header(mail_render_string($subjectTpl !== '' ? $subjectTpl : '{{store_name}}', $vars));
        $text    = trim(mail_render_string($bodyTpl, $vars));
        $html    = '';

        $view = mail_view_path($event);
        if ($view !== null) {
            try {
                $html = mail_render_view($view, $vars + ['subject' => $subject]);
            } catch (Throwable $e) {
                error_log('[CoffeeShop][MAIL] Emails view failed (' . basename($view) . '): ' . $e->getMessage());
                $html = '';
            }
        }

        if (trim($html) === '') {
            $html = mail_text_to_html($text);
        } else {
            $text = mail_html_to_text($html);
        }

        return ['disabled' => false, 'subject' => $subject, 'html' => $html, 'text' => $text, 'template_id' => $templateId, 'vars' => $vars];
    }
}

// ---------------------------------------------------------------------
// 7. Notification log (notifications table): send + record + dedupe
// ---------------------------------------------------------------------
if (!function_exists('mail_log_insert_failure')) {
    /** Insert a row for an e-mail that will NOT be (re)tried: invalid recipient, missing template... */
    function mail_log_insert_failure(array $n, string $recipient, string $event, string $error): void
    {
        try {
            db_insert(
                "INSERT INTO notifications
                    (audience, user_id, recipient, channel, event_type, subject, message, order_id, related_type, related_id,
                     status, attempt_count, last_attempt_at, error_message)
                 VALUES (?, ?, ?, 'email', ?, NULL, ?, ?, ?, ?, 'failed', ?, NOW(), ?)",
                [
                    ($n['audience'] ?? 'customer') === 'staff' ? 'staff' : 'customer',
                    !empty($n['user_id']) ? (int) $n['user_id'] : null,
                    $recipient !== '' ? mail_truncate($recipient, 190) : null,
                    mail_truncate($event, 60),
                    'E-mail could not be created: ' . mail_truncate($error, 200),
                    !empty($n['order_id']) ? (int) $n['order_id'] : null,
                    isset($n['related_type']) ? mail_truncate((string) $n['related_type'], 40) : null,
                    !empty($n['related_id']) ? (int) $n['related_id'] : null,
                    (int) MAIL_MAX_ATTEMPTS,       // not retryable
                    mail_truncate($error, 255),
                ]
            );
        } catch (Throwable $e) {
            error_log('[CoffeeShop][MAIL] Could not log failed notification: ' . $e->getMessage());
        }
    }
}

if (!function_exists('mail_mark_result')) {
    /** Update the notification row after a send attempt; alert staff after the last failed attempt. */
    function mail_mark_result(int $id, bool $ok, ?string $error, string $event, string $recipient): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            if ($ok) {
                if ($event === 'gift_card_delivery') {
                    // Finish the card and its notification in the same database statement.
                    return db_exec("UPDATE notifications n
                             LEFT JOIN gift_cards g ON n.related_type = 'gift_card' AND g.id = n.related_id
                                  AND n.dedupe_key = CONCAT('gift_card_delivery:', g.id, ':email')
                             SET n.status = 'sent', n.sent_at = NOW(), n.last_attempt_at = NOW(), n.error_message = NULL,
                                 g.sent_at = COALESCE(g.sent_at, NOW())
                             WHERE n.id = ? AND n.event_type = 'gift_card_delivery'", [$id]) > 0;
                }
                return db_exec("UPDATE notifications SET status = 'sent', sent_at = NOW(), last_attempt_at = NOW(), error_message = NULL WHERE id = ?", [$id]) > 0;
            }
            db_exec(
                "UPDATE notifications SET status = 'failed', last_attempt_at = NOW(), error_message = ? WHERE id = ?",
                [mail_truncate((string) $error, 255), $id]
            );
            $attempts = (int) db_val('SELECT attempt_count FROM notifications WHERE id = ?', [$id]);
            if ($attempts >= (int) MAIL_MAX_ATTEMPTS && $event !== 'admin_alert') {
                // Feature 49: "failed notification alerts" for administrators / staff.
                try {
                    db_insert(
                        "INSERT INTO notifications
                            (audience, user_id, channel, event_type, subject, message, related_type, related_id, status, sent_at, dedupe_key)
                         VALUES ('staff', NULL, 'in_app', 'notification_failed', 'E-mail delivery failed', ?, 'notification', ?, 'sent', NOW(), ?)",
                        [
                            mail_truncate("E-mail '" . $event . "' to " . $recipient . ' failed after ' . $attempts . ' attempts: ' . (string) $error, 1000),
                            $id,
                            'notification_failed:' . $id,
                        ]
                    );
                } catch (PDOException $e) {
                    if (!db_is_duplicate_key($e)) {
                        throw $e;
                    }
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log('[CoffeeShop][MAIL] Could not update notification #' . $id . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mail_notify')) {
    /**
     * Compose, send and LOG one e-mail notification.
     *
     * @param array $n  event        (required) template code / notifications.event_type
     *                  to_email     (required) recipient address
     *                  to_name      display name
     *                  user_id      customers.users.id (enables preference checks)
     *                  vars         placeholder values for the template
     *                  order_id, related_type, related_id   links stored in the log
     *                  dedupe_key   UNIQUE key, e.g. "order_confirmation:123:email" (blocks duplicates)
     *                  category     'transactional' (default) | 'security' (always sent) | 'marketing' (needs consent)
     *                  audience     'customer' (default) | 'staff'
     *                  cta          ['label' => '...', 'url' => 'https://...'] button
     *                  force        true = ignore customer preferences
     *                  require_claim true = reserve a deduplicated row before sending
     * @return array{ok:bool,status:string,id:?int,error:?string}
     */
    function mail_notify(array $n): array
    {
        $event = trim((string) ($n['event'] ?? ''));
        if ($event === '') {
            throw new InvalidArgumentException('mail_notify() needs an "event".');
        }

        $userId   = !empty($n['user_id']) ? (int) $n['user_id'] : null;
        $email    = trim((string) ($n['to_email'] ?? ''));
        $name     = (string) ($n['to_name'] ?? '');
        $audience = (($n['audience'] ?? 'customer') === 'staff') ? 'staff' : 'customer';
        $category = in_array($n['category'] ?? 'transactional', ['transactional', 'security', 'marketing'], true)
            ? (string) ($n['category'] ?? 'transactional') : 'transactional';
        $force    = !empty($n['force']);
        $dedupe   = isset($n['dedupe_key']) && $n['dedupe_key'] !== '' ? mail_truncate((string) $n['dedupe_key'], 190) : null;
        $orderId  = !empty($n['order_id']) ? (int) $n['order_id'] : null;
        $relType  = isset($n['related_type']) ? mail_truncate((string) $n['related_type'], 40) : null;
        $relId    = !empty($n['related_id']) ? (int) $n['related_id'] : null;

        try {
            // 1. Recipient
            if (!mail_valid_email($email)) {
                mail_log_insert_failure($n, $email, $event, 'Invalid recipient e-mail address.');
                return mail_fail('Invalid recipient e-mail address for event ' . $event . '.');
            }

            // 2. Customer preferences (security e-mails are always sent)
            if (!$force && $audience === 'customer' && $category !== 'security') {
                $pref = $userId !== null
                    ? db_row('SELECT notify_email, marketing_consent FROM customer_preferences WHERE user_id = ?', [$userId])
                    : null;
                if ($pref !== null && (int) $pref['notify_email'] !== 1) {
                    return ['ok' => true, 'status' => 'skipped', 'id' => null, 'error' => null, 'reason' => 'customer_opted_out'];
                }
                if ($category === 'marketing' && ($pref === null || (int) $pref['marketing_consent'] !== 1)) {
                    return ['ok' => true, 'status' => 'skipped', 'id' => null, 'error' => null, 'reason' => 'no_marketing_consent'];
                }
            }

            // 3. Compose
            $gift = $event === 'gift_card_delivery' && $relType === 'gift_card' && $relId !== null
                && $dedupe === 'gift_card_delivery:' . $relId . ':email';
            $linked = $gift || !empty($n['require_claim']);
            if ($linked && $dedupe === null) {
                return mail_fail('A linked e-mail requires a deduplication key.');
            }
            try {
                $mail = mail_compose($event, (array) ($n['vars'] ?? []), $linked);
            } catch (Throwable $e) {
                mail_log_insert_failure($n, $email, $event, $e->getMessage());
                return mail_fail($e->getMessage());
            }
            if ($mail['disabled']) {
                return ['ok' => true, 'status' => 'skipped', 'id' => null, 'error' => null, 'reason' => 'template_disabled'];
            }

            // 4. Log row (claims the send; the UNIQUE dedupe_key blocks duplicates)
            // Keep redeemable gift codes out of the staff/customer notification history.
            // The retry worker rebuilds this message from the card itself.
            $subjectDb = $gift ? 'Gift card delivery' : mail_truncate($mail['subject'], 190);
            $messageDb = $gift ? 'Gift card delivery details are private.' : mail_truncate($mail['text'], 60000);
            $id = 0;
            try {
                $id = db_insert(
                    "INSERT INTO notifications
                        (audience, user_id, recipient, channel, event_type, template_id, subject, message,
                         order_id, related_type, related_id, status, attempt_count, last_attempt_at, dedupe_key)
                     VALUES (?, ?, ?, 'email', ?, ?, ?, ?, ?, ?, ?, 'pending', 1, NOW(), ?)",
                    [$audience, $userId, $email, mail_truncate($event, 60), $mail['template_id'], $subjectDb, $messageDb,
                     $orderId, $relType, $relId, $dedupe]
                );
            } catch (PDOException $e) {
                if ($dedupe !== null && db_is_duplicate_key($e)) {
                    $existing = db_row('SELECT id, status, attempt_count FROM notifications WHERE dedupe_key = ?', [$dedupe]);
                    if ($existing !== null && in_array((string) $existing['status'], ['sent', 'delivered', 'read'], true)) {
                        return ['ok' => true, 'status' => 'duplicate', 'id' => (int) $existing['id'], 'error' => null];
                    }
                    if ($existing !== null) {
                        // Earlier attempt failed (or crashed): take it over unless another process is sending right now.
                        if ($linked && (int) $existing['attempt_count'] >= (int) MAIL_MAX_ATTEMPTS) {
                            return ['ok' => false, 'status' => 'failed', 'id' => (int) $existing['id'],
                                    'error' => 'E-mail delivery has reached the retry limit.'];
                        }
                        $wait = $linked ? max(1, (int) MAIL_RETRY_MINUTES) : 2;
                        $claimed = db_exec(
                            "UPDATE notifications
                             SET status = 'pending', attempt_count = attempt_count + 1, last_attempt_at = NOW(),
                                 subject = ?, message = ?, recipient = ?, event_type = ?, template_id = ?, error_message = NULL
                             WHERE id = ? AND status IN ('pending','failed')
                               AND attempt_count = ?
                               AND (last_attempt_at IS NULL OR last_attempt_at <= (NOW() - INTERVAL {$wait} MINUTE))",
                            [$subjectDb, $messageDb, $email, mail_truncate($event, 60), $mail['template_id'],
                             (int) $existing['id'], (int) $existing['attempt_count']]
                        );
                        if ($claimed === 0) {
                            return ['ok' => true, 'status' => 'duplicate', 'id' => (int) $existing['id'], 'error' => null];
                        }
                        $id = (int) $existing['id'];
                    }
                } else {
                    // Logging problems must never block the e-mail itself.
                    error_log('[CoffeeShop][MAIL] Could not log notification: ' . $e->getMessage());
                }
            }

            if ($linked && $id <= 0) {
                return mail_fail('E-mail delivery could not reserve a notification.');
            }

            // 5. Layout + send
            $vars = $mail['vars'];
            $cta  = (array) ($n['cta'] ?? []);
            if (mail_is_full_document($mail['html'])) {
                $html = $mail['html'];
            } else {
                $html = mail_layout($mail['html'], [
                    'store_name'    => (string) $vars['store_name'],
                    'support_email' => (string) $vars['support_email'],
                    'preheader'     => $gift ? 'You received a gift card' : mail_truncate(preg_replace('/\s+/', ' ', $mail['text']) ?? '', 140),
                    'cta_label'     => (string) ($cta['label'] ?? ''),
                    'cta_url'       => (string) ($cta['url'] ?? ''),
                    'marketing'     => $category === 'marketing',
                ]);
            }
            $text = $mail['text'] . "\n\n--\n" . $vars['store_name'] . "\n" . $vars['support_email'];

            $send = mail_send(['email' => $email, 'name' => $name], $mail['subject'], $html, $text, [
                'reply_to'         => MAIL_REPLY_TO !== '' ? MAIL_REPLY_TO : (string) $vars['support_email'],
                'list_unsubscribe' => $category === 'marketing' ? mail_url(MAIL_PREFS_PATH) : '',
            ]);

            $recorded = mail_mark_result($id, $send['ok'], $send['error'], $event, $email);
            if ($linked && $send['ok'] && !$recorded) {
                $send['ok'] = false;
                $send['error'] = 'The mail transport accepted the message, but recording delivery failed.';
            }

            return [
                'ok'     => $send['ok'],
                'status' => $send['ok'] ? 'sent' : 'failed',
                'id'     => $id > 0 ? $id : null,
                'error'  => $send['error'],
                'file'   => $send['file'],
            ];
        } catch (Throwable $e) {
            return mail_fail('mail_notify(' . $event . ') failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('mail_retry_linked_notification')) {
    /** Rebuild typed notices so delivery honors edits, permissions, preferences and templates. */
    function mail_retry_linked_notification(array $row): ?array
    {
        $relatedId = (int) $row['related_id'];
        $recipientId = (int) $row['user_id'];
        $key = (string) $row['dedupe_key'];

        if ($row['related_type'] === 'gift_card' && $key === 'gift_card_delivery:' . $relatedId . ':email') {
            return mail_send_gift_card($relatedId);
        }
        if ($row['related_type'] === 'notification' && $key === 'admin_alert:' . $relatedId . ':' . $recipientId . ':email') {
            return mail_send_admin_alert($relatedId, $recipientId);
        }
        if ($row['related_type'] === 'loyalty_transaction' && $key === 'reward_earned:' . $relatedId . ':email') {
            $notice = mail_reward_earned_notice($relatedId);
        } elseif ($row['related_type'] === 'support_message' && $key === 'support_reply:' . $relatedId . ':email') {
            $notice = mail_support_reply_notice($relatedId);
        } else {
            return null;
        }
        if ($notice === null || (int) $notice['user_id'] !== $recipientId
            || strcasecmp(trim($notice['to_email']), (string) $row['recipient']) !== 0) {
            return ['ok' => true, 'status' => 'skipped', 'id' => null, 'error' => null,
                    'reason' => 'linked_recipient_or_content_changed'];
        }
        return mail_notify($notice);
    }
}

if (!function_exists('mail_retry_failed')) {
    /**
     * Retry e-mails whose status is 'failed' (or stuck 'pending'), up to
     * MAIL_MAX_ATTEMPTS tries, waiting MAIL_RETRY_MINUTES between tries.
     * Linked notices are rebuilt from current data. Other rows use the stored message.
     *
     * @return array{attempted:int,sent:int,failed:int}
     */
    function mail_retry_failed(int $limit = 20): array
    {
        $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0];
        $limit   = max(1, min(200, $limit));
        $wait    = max(1, (int) MAIL_RETRY_MINUTES);

        try {
            $rows = db_all(
                "SELECT id, recipient, event_type, subject, message, attempt_count, user_id, related_type, related_id, dedupe_key
                 FROM notifications
                 WHERE channel = 'email' AND status IN ('failed','pending')
                   AND recipient IS NOT NULL AND subject IS NOT NULL
                   AND attempt_count < ?
                   AND (last_attempt_at IS NULL OR last_attempt_at <= (NOW() - INTERVAL {$wait} MINUTE))
                 ORDER BY last_attempt_at, id LIMIT {$limit}",
                [(int) MAIL_MAX_ATTEMPTS]
            );

            foreach ($rows as $row) {
                try {
                    $result = mail_retry_linked_notification($row);
                } catch (Throwable $e) {
                    $result = mail_fail('Could not rebuild notification #' . (int) $row['id'] . ': ' . $e->getMessage());
                }
                if ($result !== null) {
                    if ($result['id'] !== null) {
                        if (in_array($result['status'], ['sent','failed'], true)) {
                            $summary['attempted']++;
                            $summary[$result['status']]++;
                        }
                        continue;
                    }

                    if ($result['status'] === 'duplicate') {
                        // Gift cards marked sent by an existing caller can leave an older retry row behind.
                        db_exec(
                            "UPDATE notifications n JOIN gift_cards g ON g.id = n.related_id
                             SET n.status = 'sent', n.sent_at = g.sent_at, n.error_message = NULL
                             WHERE n.id = ? AND n.attempt_count = ? AND n.status IN ('pending','failed') AND g.sent_at IS NOT NULL",
                            [(int) $row['id'], (int) $row['attempt_count']]
                        );
                        continue;
                    }

                    // Leave future-dated cards and disabled templates eligible for a later run.
                    $deferred = in_array($result['reason'] ?? '', ['gift_card_not_due', 'template_disabled'], true);
                    $attempts = (int) $row['attempt_count'];
                    if (!$deferred) {
                        // A rebuild or claim error can be transient. An ineligible notice stops retrying.
                        $attempts = $result['status'] === 'failed' ? $attempts + 1 : (int) MAIL_MAX_ATTEMPTS;
                    }
                    $updated = db_exec(
                        "UPDATE notifications SET status = 'failed', last_attempt_at = NOW(),
                                attempt_count = ?, error_message = ?
                         WHERE id = ? AND attempt_count = ? AND status IN ('pending','failed')",
                        [$attempts,
                         mail_truncate($result['error'] ?? 'Linked notice is not currently eligible for delivery.', 255),
                         (int) $row['id'], (int) $row['attempt_count']]
                    );
                    if (!$deferred && $updated === 1) {
                        $summary['attempted']++;
                        $summary['failed']++;
                    }
                    continue;
                }
                $claimed = db_exec(
                    "UPDATE notifications SET status = 'pending', attempt_count = attempt_count + 1, last_attempt_at = NOW()
                     WHERE id = ? AND attempt_count = ? AND status IN ('failed','pending')",
                    [(int) $row['id'], (int) $row['attempt_count']]
                );
                if ($claimed !== 1) {
                    continue;   // another process took it
                }
                $summary['attempted']++;

                $text = (string) $row['message'];
                $html = mail_layout(mail_text_to_html($text), [
                    'store_name'    => mail_setting('store_name', MAIL_STORE_NAME),
                    'support_email' => mail_setting('support_email', MAIL_SUPPORT_EMAIL),
                    'preheader'     => mail_truncate((string) preg_replace('/\s+/', ' ', $text), 140),
                ]);
                $send = mail_send((string) $row['recipient'], (string) $row['subject'], $html, $text, [
                    'reply_to' => MAIL_REPLY_TO !== '' ? MAIL_REPLY_TO : mail_setting('support_email', MAIL_SUPPORT_EMAIL),
                ]);
                mail_mark_result((int) $row['id'], $send['ok'], $send['error'], (string) $row['event_type'], (string) $row['recipient']);
                $summary[$send['ok'] ? 'sent' : 'failed']++;
            }
        } catch (Throwable $e) {
            error_log('[CoffeeShop][MAIL] mail_retry_failed() error: ' . $e->getMessage());
        }

        return $summary;
    }
}

// ---------------------------------------------------------------------
// 8. Ready-made senders
// ---------------------------------------------------------------------
if (!function_exists('mail_send_gift_card')) {
    /** Call after activation commits, or from the scheduled gift_card_delivery job with a due card ID. */
    function mail_send_gift_card(int $giftCardId): array
    {
        try {
            $card = db_row("SELECT code, initial_value, currency, sender_name, recipient_name, recipient_email,
                                   message, expires_at, purchase_order_id, sent_at, status,
                                   (delivery_date IS NULL OR delivery_date <= CURDATE()) AS due,
                                   (expires_at IS NOT NULL AND expires_at <= NOW()) AS expired
                            FROM gift_cards WHERE id = ?", [$giftCardId]);
        } catch (Throwable $e) {
            return mail_fail('mail_send_gift_card(): ' . $e->getMessage());
        }
        if ($card === null) {
            return mail_fail('Gift card #' . $giftCardId . ' not found.');
        }
        if ($card['sent_at'] !== null) {
            return ['ok' => true, 'status' => 'duplicate', 'id' => null, 'error' => null];
        }
        if ($card['status'] !== 'active' || (int) $card['expired'] || !(int) $card['due']) {
            $reason = $card['status'] === 'active' && !(int) $card['expired'] ? 'gift_card_not_due' : 'gift_card_inactive';
            return ['ok' => true, 'status' => 'skipped', 'id' => null, 'error' => null, 'reason' => $reason];
        }
        if (!mail_valid_email(trim((string) $card['recipient_email'])) || trim((string) $card['code']) === '') {
            return mail_fail('Gift card requires a valid recipient address and a redemption code.');
        }
        return mail_notify([
            'event'        => 'gift_card_delivery',
            'to_email'     => (string) $card['recipient_email'],
            'to_name'      => (string) $card['recipient_name'],
            'vars'         => [
                'recipient_name' => trim((string) $card['recipient_name']) ?: 'there',
                'sender_name'    => trim((string) $card['sender_name']) ?: 'A friend',
                'amount'         => $card['currency'] . ' ' . number_format((float) $card['initial_value'], 2),
                'message'        => (string) $card['message'],
                'gift_card_code' => (string) $card['code'],
                'expires_at'     => mail_datetime($card['expires_at']) ?: 'No expiration',
            ],
            'order_id'     => $card['purchase_order_id'],
            'related_type' => 'gift_card',
            'related_id'   => $giftCardId,
            'dedupe_key'   => 'gift_card_delivery:' . $giftCardId . ':email',
        ]);
    }
}

if (!function_exists('mail_resolve_user')) {
    /** @param array|int $user user row (id, first_name, last_name, email) or a users.id */
    function mail_resolve_user($user): ?array
    {
        if (is_array($user) && isset($user['email'])) {
            return $user;
        }
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : (int) $user;
        if ($id <= 0) {
            return null;
        }
        return db_row('SELECT id, first_name, last_name, email FROM users WHERE id = ?', [$id]);
    }
}

if (!function_exists('mail_valid_token')) {
    function mail_valid_token(string $token): bool
    {
        return $token !== '' && strlen($token) <= 255 && preg_match('/^[A-Za-z0-9_\-]+$/', $token) === 1;
    }
}

if (!function_exists('mail_send_welcome')) {
    /** Feature 12: welcome / account registration. @param array|int $user */
    function mail_send_welcome($user): array
    {
        $u = mail_resolve_user($user);
        if ($u === null) {
            return mail_fail('mail_send_welcome(): user not found.');
        }
        $uid = (int) ($u['id'] ?? 0);
        return mail_notify([
            'event'        => 'welcome',
            'user_id'      => $uid,
            'to_email'     => (string) $u['email'],
            'to_name'      => trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
            'vars'         => ['first_name' => (string) ($u['first_name'] ?? ''), 'last_name' => (string) ($u['last_name'] ?? '')],
            'related_type' => 'user',
            'related_id'   => $uid,
            'dedupe_key'   => 'welcome:' . $uid . ':email',
            'cta'          => ['label' => 'Start ordering', 'url' => mail_url()],
        ]);
    }
}

if (!function_exists('mail_send_verification')) {
    /** Feature 4/12: e-mail verification. $rawToken is the unhashed token (DB keeps only its SHA-256). @param array|int $user */
    function mail_send_verification($user, string $rawToken): array
    {
        $u = mail_resolve_user($user);
        if ($u === null) {
            return mail_fail('mail_send_verification(): user not found.');
        }
        if (!mail_valid_token($rawToken)) {
            return mail_fail('mail_send_verification(): invalid token format.');
        }
        $link = mail_url(MAIL_VERIFY_PATH, ['token' => $rawToken]);
        return mail_notify([
            'event'        => 'email_verification',
            'category'     => 'security',
            'user_id'      => (int) ($u['id'] ?? 0),
            'to_email'     => (string) $u['email'],
            'to_name'      => trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
            'vars'         => [
                'first_name'        => (string) ($u['first_name'] ?? ''),
                'verification_link' => $link,
                'expiry_hours'      => mail_setting('email_verification_expiry_hours', '24'),
            ],
            'related_type' => 'user',
            'related_id'   => (int) ($u['id'] ?? 0),
            'cta'          => ['label' => 'Verify my email', 'url' => $link],
        ]);
    }
}

if (!function_exists('mail_send_password_reset')) {
    /** Feature 4/12: password reset link. $rawToken is the unhashed token. @param array|int $user */
    function mail_send_password_reset($user, string $rawToken): array
    {
        $u = mail_resolve_user($user);
        if ($u === null) {
            return mail_fail('mail_send_password_reset(): user not found.');
        }
        if (!mail_valid_token($rawToken)) {
            return mail_fail('mail_send_password_reset(): invalid token format.');
        }
        $link = mail_url(MAIL_RESET_PATH, ['token' => $rawToken]);
        return mail_notify([
            'event'        => 'password_reset',
            'category'     => 'security',
            'user_id'      => (int) ($u['id'] ?? 0),
            'to_email'     => (string) $u['email'],
            'to_name'      => trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
            'vars'         => [
                'first_name'     => (string) ($u['first_name'] ?? ''),
                'reset_link'     => $link,
                'expiry_minutes' => mail_setting('password_reset_expiry_minutes', '60'),
            ],
            'related_type' => 'user',
            'related_id'   => (int) ($u['id'] ?? 0),
            'cta'          => ['label' => 'Reset my password', 'url' => $link],
        ]);
    }
}

if (!function_exists('mail_load_order_context')) {
    /**
     * Load an order and build the template variables.
     * Available in templates ({{name}}) and in Emails/ views ($vars['name']):
     *   first_name customer_name order_number order_type status payment_status payment_method
     *   subtotal discount_total tax_total delivery_fee grand_total amount payment_reference
     *   branch_name branch_address branch_phone placed_at estimated_ready_at estimated_delivery_at
     *   courier_name tracking_number order_url items_text
     *   items (array: product_name, variant_name, quantity, unit_price, customization_total, line_total, options[])
     *
     * @return array{order:array,vars:array}|null
     */
    function mail_load_order_context(int $orderId): ?array
    {
        $o = db_row(
            'SELECT o.*, b.name AS branch_name, b.address AS branch_address, b.city AS branch_city, b.phone AS branch_phone,
                    u.first_name AS user_first_name
             FROM orders o
             LEFT JOIN branches b ON b.id = o.branch_id
             LEFT JOIN users u    ON u.id = o.user_id
             WHERE o.id = ?',
            [$orderId]
        );
        if ($o === null) {
            return null;
        }

        $items = db_all(
            'SELECT id, product_name, variant_name, quantity, unit_price, customization_total, line_total
             FROM order_items WHERE order_id = ? ORDER BY id',
            [$orderId]
        );
        $optionsByItem = [];
        if ($items !== []) {
            $ids = array_map(static function (array $i): int { return (int) $i['id']; }, $items);
            $opts = db_all(
                'SELECT order_item_id, group_name, option_name, quantity, additional_price
                 FROM order_item_options WHERE order_item_id IN (' . db_in_placeholders($ids) . ') ORDER BY id',
                $ids
            );
            foreach ($opts as $opt) {
                $optionsByItem[(int) $opt['order_item_id']][] = $opt;
            }
        }

        $lines = [];
        foreach ($items as &$item) {
            $item['options'] = $optionsByItem[(int) $item['id']] ?? [];
            $line = '- ' . (int) $item['quantity'] . ' x ' . $item['product_name']
                . (($item['variant_name'] ?? '') !== '' ? ' (' . $item['variant_name'] . ')' : '')
                . ' - ' . mail_money($item['line_total']);
            $names = [];
            foreach ($item['options'] as $opt) {
                $names[] = $opt['option_name'] . ((int) $opt['quantity'] > 1 ? ' x' . (int) $opt['quantity'] : '');
            }
            if ($names !== []) {
                $line .= "\n    + " . implode(', ', $names);
            }
            $lines[] = $line;
        }
        unset($item);

        $shipment = db_row(
            'SELECT courier_name, tracking_number, estimated_delivery_at FROM shipments WHERE order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId]
        );
        $payment = db_row(
            "SELECT payment_reference, amount, method, status FROM payments
             WHERE order_id = ? ORDER BY (status = 'successful') DESC, id DESC LIMIT 1",
            [$orderId]
        );

        $firstName = trim((string) ($o['user_first_name'] ?? ''));
        if ($firstName === '') {
            $parts     = preg_split('/\s+/', trim((string) $o['customer_name']));
            $firstName = (string) ($parts[0] ?? '');
        }
        $branchAddress = trim((string) ($o['branch_address'] ?? '') . ((string) ($o['branch_city'] ?? '') !== '' ? ', ' . $o['branch_city'] : ''));
        $method = (string) ($payment['method'] ?? $o['payment_method'] ?? '');

        $vars = [
            'first_name'            => $firstName,
            'customer_name'         => (string) $o['customer_name'],
            'order_number'          => (string) $o['order_number'],
            'order_type'            => mail_label((string) $o['order_type']),
            'status'                => mail_label((string) $o['status']),
            'payment_status'        => mail_label((string) $o['payment_status']),
            'payment_method'        => $method !== '' ? mail_label($method) : '',
            'subtotal'              => mail_money($o['subtotal']),
            'discount_total'        => mail_money($o['discount_total']),
            'tax_total'             => mail_money($o['tax_total']),
            'delivery_fee'          => mail_money($o['delivery_fee']),
            'grand_total'           => mail_money($o['grand_total']),
            'amount'                => mail_money($payment['amount'] ?? $o['grand_total']),
            'payment_reference'     => (string) ($payment['payment_reference'] ?? ''),
            'branch_name'           => (string) ($o['branch_name'] ?? ''),
            'branch_address'        => $branchAddress,
            'branch_phone'          => (string) ($o['branch_phone'] ?? ''),
            'placed_at'             => mail_datetime($o['placed_at']),
            'estimated_ready_at'    => mail_datetime($o['estimated_ready_at']),
            'estimated_delivery_at' => mail_datetime($shipment['estimated_delivery_at'] ?? $o['estimated_delivery_at']),
            'courier_name'          => (string) ($shipment['courier_name'] ?? ''),
            'tracking_number'       => (string) ($shipment['tracking_number'] ?? ''),
            'order_url'             => mail_url(MAIL_ORDERS_PATH),
            'items_text'            => implode("\n", $lines),
            'items'                 => $items,
        ];

        return ['order' => $o, 'vars' => $vars];
    }
}

if (!function_exists('mail_admin_alert_context')) {
    /** Resolve a saved staff alert and its currently authorized recipient, including per-user permission overrides. */
    function mail_admin_alert_context(int $alertId, int $staffUserId): ?array
    {
        return db_row(
            "SELECT n.id, n.event_type, n.subject, n.message, o.id AS order_id,
                    u.id AS user_id, u.email, u.first_name, u.last_name
             FROM notifications n
             JOIN users u ON u.id = ? AND u.status = 'active' AND u.role IN ('admin','staff','fulfillment')
             LEFT JOIN employee_profiles e ON e.user_id = u.id
             JOIN permissions p ON p.code = CASE
                 WHEN n.event_type IN ('new_order','order_fulfillment_update') THEN 'orders.view'
                 WHEN n.event_type IN ('new_return_request','new_cancellation_request') THEN 'returns.view'
                 WHEN n.event_type = 'refund_request' THEN 'refunds.view'
                 WHEN n.event_type IN ('low_stock','out_of_stock') THEN 'inventory.view'
                 WHEN n.event_type = 'notification_failed' THEN 'notifications.manage' END
             LEFT JOIN role_permissions rp ON rp.role = u.role AND rp.permission_id = p.id
             LEFT JOIN user_permissions up ON up.user_id = u.id AND up.permission_id = p.id
             LEFT JOIN return_requests rr ON n.related_type = 'return_request' AND rr.id = n.related_id
             LEFT JOIN refunds rf ON n.related_type = 'refund' AND rf.id = n.related_id
             LEFT JOIN notifications failed ON n.event_type = 'notification_failed'
                  AND n.related_type = 'notification' AND failed.id = n.related_id
                  AND failed.channel = 'email' AND failed.status = 'failed'
             LEFT JOIN orders o ON o.id = CASE n.related_type
                 WHEN 'return_request' THEN rr.order_id
                 WHEN 'refund' THEN rf.order_id
                 WHEN 'order' THEN n.related_id
                 WHEN 'notification' THEN failed.order_id
                 ELSE n.order_id END
             LEFT JOIN stock_alerts s ON n.related_type = 'stock_alert' AND s.id = n.related_id
             WHERE n.id = ? AND n.channel = 'in_app' AND n.audience = 'staff'
               AND n.status IN ('sent','delivered','read')
               AND (n.user_id IS NULL OR n.user_id = u.id)
               AND (e.employment_status = 'active' OR (u.role = 'admin' AND e.id IS NULL))
               AND COALESCE(up.is_granted, rp.permission_id IS NOT NULL) = 1
               AND (n.event_type <> 'notification_failed' OR failed.event_type <> 'admin_alert')
               AND ((u.role = 'admin' AND (e.branch_id IS NULL OR n.event_type = 'notification_failed'))
                    OR (n.event_type IN ('low_stock','out_of_stock') AND s.branch_id = e.branch_id)
                    OR (n.event_type NOT IN ('low_stock','out_of_stock') AND o.branch_id = e.branch_id))
               AND (u.role <> 'fulfillment' OR (p.code = 'orders.view' AND o.assigned_to = u.id))",
            [$staffUserId, $alertId]
        );
    }
}

if (!function_exists('mail_send_admin_alert')) {
    /**
     * Optionally e-mail a recorded staff in-app alert to one authorized employee.
     * Link events via order_id or related_type + related_id (order, return_request, refund, stock_alert).
     * A disabled 'admin_alert' e-mail template opts out of every alert copy.
     */
    function mail_send_admin_alert(int $staffNotificationId, int $recipientUserId): array
    {
        try {
            $alert = mail_admin_alert_context($staffNotificationId, $recipientUserId);
        } catch (Throwable $e) {
            return mail_fail('mail_send_admin_alert(): ' . $e->getMessage());
        }
        if ($alert === null) {
            return ['ok' => true, 'status' => 'skipped', 'id' => null, 'error' => null, 'reason' => 'no_eligible_staff_alert'];
        }
        // A transport error can contain the customer's address or provider diagnostics.
        $deliveryFailure = $alert['event_type'] === 'notification_failed';
        $message = $deliveryFailure
            ? 'An e-mail could not be delivered. Review the failed notification in the staff dashboard.'
            : (string) $alert['message'];
        return mail_notify([
            'event'        => 'admin_alert',
            'audience'     => 'staff',
            'user_id'      => $recipientUserId,
            'to_email'     => (string) $alert['email'],
            'to_name'      => trim($alert['first_name'] . ' ' . $alert['last_name']),
            'vars'         => [
                'alert_subject' => $deliveryFailure ? 'E-mail delivery failed' : (string) ($alert['subject'] ?? mail_label($alert['event_type'])),
                'alert_message' => $message,
            ],
            'order_id'     => $alert['order_id'],
            'related_type' => 'notification',
            'related_id'   => $staffNotificationId,
            'require_claim' => true,
            'dedupe_key'   => 'admin_alert:' . $staffNotificationId . ':' . $recipientUserId . ':email',
            'cta'          => ['label' => 'Open staff dashboard', 'url' => mail_url('/admin/index.php')],
        ]);
    }
}

if (!function_exists('mail_send_order_event')) {
    /**
     * Features 10-12: send an order e-mail to the customer.
     *
     * @param string $event one of: order_confirmation, payment_confirmation, order_ready_for_pickup,
     *                      order_shipped, order_delivered, order_cancelled
     * @param array  $extra extra template values (e.g. ['reason' => '...']); 'dedupe_suffix' => '...'
     *                      lets the SAME event be sent again on purpose (e.g. new tracking number)
     */
    function mail_send_order_event(int $orderId, string $event, array $extra = []): array
    {
        static $allowed = ['order_confirmation', 'payment_confirmation', 'order_ready_for_pickup',
                           'order_shipped', 'order_delivered', 'order_cancelled'];
        if (!in_array($event, $allowed, true)) {
            return mail_fail("mail_send_order_event(): unsupported event '" . $event . "'.");
        }

        try {
            $ctx = mail_load_order_context($orderId);
        } catch (Throwable $e) {
            return mail_fail('mail_send_order_event(): ' . $e->getMessage());
        }
        if ($ctx === null) {
            return mail_fail('mail_send_order_event(): order #' . $orderId . ' not found.');
        }

        $suffix = (string) ($extra['dedupe_suffix'] ?? '');
        unset($extra['dedupe_suffix']);
        $o = $ctx['order'];

        return mail_notify([
            'event'        => $event,
            'user_id'      => !empty($o['user_id']) ? (int) $o['user_id'] : null,
            'to_email'     => (string) $o['customer_email'],
            'to_name'      => (string) $o['customer_name'],
            'vars'         => $extra + $ctx['vars'],
            'order_id'     => $orderId,
            'related_type' => 'order',
            'related_id'   => $orderId,
            'dedupe_key'   => $event . ':' . $orderId . ($suffix !== '' ? ':' . $suffix : '') . ':email',
            'cta'          => ['label' => 'View my order', 'url' => mail_url(MAIL_ORDERS_PATH)],
        ]);
    }
}

if (!function_exists('mail_reward_earned_notice')) {
    /** Build a notice from a committed positive points award and its ledger balance. */
    function mail_reward_earned_notice(int $transactionId): ?array
    {
        $award = db_row(
            "SELECT tx.points, tx.balance_after, tx.created_at, o.id AS order_id, o.order_number,
                    u.id AS user_id, u.first_name, u.last_name, u.email, lt.name AS tier_name
             FROM loyalty_transactions tx
             JOIN loyalty_accounts a ON a.id = tx.loyalty_account_id
             JOIN users u ON u.id = a.user_id
             LEFT JOIN loyalty_tiers lt ON lt.id = a.tier_id
             LEFT JOIN orders o ON o.id = tx.order_id AND o.user_id = a.user_id
             WHERE tx.id = ? AND tx.transaction_type IN ('earn', 'bonus') AND tx.points > 0
               AND a.status = 'active' AND u.status = 'active'",
            [$transactionId]
        );
        if ($award === null) {
            return null;
        }

        // Each reward whose threshold this ledger entry crossed belongs in the same message.
        $balance = (int) $award['balance_after'];
        $rewards = db_all(
            'SELECT name FROM rewards
             WHERE is_active = 1 AND points_required > ? AND points_required <= ?
               AND created_at <= ? AND (available_from IS NULL OR available_from <= ?)
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
               AND (max_redemptions IS NULL OR redeemed_count < max_redemptions)
             ORDER BY points_required, id',
            [$balance - (int) $award['points'], $balance, $award['created_at'], $award['created_at']]
        );
        $event = $award['order_id'] === null ? 'reward_earned_no_order' : 'reward_earned';
        if ($rewards !== []) {
            $event = 'reward_milestone';
        }

        return [
            'event'        => $event,
            'user_id'      => (int) $award['user_id'],
            'to_email'     => (string) $award['email'],
            'to_name'      => trim($award['first_name'] . ' ' . $award['last_name']),
            'order_id'     => $award['order_id'],
            'related_type' => 'loyalty_transaction',
            'related_id'   => $transactionId,
            'require_claim' => true,
            'dedupe_key'   => 'reward_earned:' . $transactionId . ':email',
            'vars'         => [
                'first_name'     => $award['first_name'],
                'points'         => (int) $award['points'],
                'points_balance' => $balance,
                'tier_name'      => $award['tier_name'] ?? 'unassigned',
                'order_number'   => $award['order_number'] ?? '',
                'reward_names'   => implode(', ', array_column($rewards, 'name')),
            ],
        ];
    }
}

if (!function_exists('mail_send_reward_earned')) {
    /** Feature 14: send once per posted earn/bonus entry, including newly reached reward thresholds. */
    function mail_send_reward_earned(int $transactionId): array
    {
        try {
            $notice = mail_reward_earned_notice($transactionId);
            return $notice === null ? mail_fail('Eligible loyalty award not found.') : mail_notify($notice);
        } catch (Throwable $e) {
            return mail_fail('mail_send_reward_earned(): ' . $e->getMessage());
        }
    }
}

if (!function_exists('mail_support_reply_notice')) {
    /** Only a public staff message may be sent to the ticket owner or guest contact. */
    function mail_support_reply_notice(int $messageId): ?array
    {
        $reply = db_row(
            "SELECT m.message, t.ticket_number, t.subject, t.order_id, t.user_id,
                    t.contact_name, t.contact_email, u.first_name, u.last_name, u.email
             FROM support_messages m
             JOIN support_tickets t ON t.id = m.ticket_id
             LEFT JOIN users u ON u.id = t.user_id
             WHERE m.id = ? AND m.sender_role = 'staff' AND m.is_internal = 0
               AND (t.user_id IS NULL OR u.status = 'active')",
            [$messageId]
        );
        if ($reply === null) {
            return null;
        }
        $guest = $reply['user_id'] === null;
        $name = $guest ? trim((string) $reply['contact_name']) : trim($reply['first_name'] . ' ' . $reply['last_name']);

        return [
            'event'        => $guest ? 'support_reply_guest' : 'support_reply',
            'user_id'      => $reply['user_id'],
            'to_email'     => (string) ($guest ? $reply['contact_email'] : $reply['email']),
            'to_name'      => $name,
            'order_id'     => $reply['order_id'],
            'related_type' => 'support_message',
            'related_id'   => $messageId,
            'require_claim' => true,
            'dedupe_key'   => 'support_reply:' . $messageId . ':email',
            'vars'         => [
                'first_name'     => $guest ? ($name !== '' ? $name : 'there') : $reply['first_name'],
                'ticket_number'  => $reply['ticket_number'],
                'ticket_subject' => $reply['subject'],
                'reply_message'  => $reply['message'],
                'ticket_url'     => $guest ? '' : mail_url('/Customer/dashboard.php'),
            ],
        ];
    }
}

if (!function_exists('mail_send_support_reply')) {
    /** Feature 41: pass support_messages.id after the staff reply has committed. */
    function mail_send_support_reply(int $messageId): array
    {
        try {
            $notice = mail_support_reply_notice($messageId);
            return $notice === null ? mail_fail('Public staff reply not found.') : mail_notify($notice);
        } catch (Throwable $e) {
            return mail_fail('mail_send_support_reply(): ' . $e->getMessage());
        }
    }
}

if (!function_exists('mail_send_return_update')) {
    /** Features 13/34/35: tell the customer the current status of a return OR cancellation request. */
    function mail_send_return_update(int $returnRequestId): array
    {
        try {
            $r = db_row(
                'SELECT rr.id, rr.request_number, rr.request_type, rr.status, rr.admin_notes, rr.order_id, rr.user_id,
                        o.order_number, o.customer_name, o.customer_email, u.first_name AS user_first_name
                 FROM return_requests rr
                 INNER JOIN orders o ON o.id = rr.order_id
                 LEFT JOIN users u   ON u.id = rr.user_id
                 WHERE rr.id = ?',
                [$returnRequestId]
            );
        } catch (Throwable $e) {
            return mail_fail('mail_send_return_update(): ' . $e->getMessage());
        }
        if ($r === null) {
            return mail_fail('mail_send_return_update(): request #' . $returnRequestId . ' not found.');
        }

        $first = trim((string) ($r['user_first_name'] ?? ''));
        if ($first === '') {
            $parts = preg_split('/\s+/', trim((string) $r['customer_name']));
            $first = (string) ($parts[0] ?? '');
        }

        return mail_notify([
            'event'        => 'return_update',
            'user_id'      => !empty($r['user_id']) ? (int) $r['user_id'] : null,
            'to_email'     => (string) $r['customer_email'],
            'to_name'      => (string) $r['customer_name'],
            'vars'         => [
                'first_name'     => $first,
                'request_number' => (string) $r['request_number'],
                'request_type'   => mail_label((string) $r['request_type']),
                'status'         => mail_label((string) $r['status']),
                'admin_notes'    => (string) ($r['admin_notes'] ?? ''),
                'order_number'   => (string) $r['order_number'],
            ],
            'order_id'     => (int) $r['order_id'],
            'related_type' => 'return_request',
            'related_id'   => (int) $r['id'],
            'dedupe_key'   => 'return_update:' . (int) $r['id'] . ':' . $r['status'] . ':email',
            'cta'          => ['label' => 'View request', 'url' => mail_url('/Customer/returns.php')],
        ]);
    }
}

if (!function_exists('mail_send_refund_update')) {
    /** Features 13/36: tell the customer the current status of a refund. */
    function mail_send_refund_update(int $refundId): array
    {
        try {
            $r = db_row(
                'SELECT rf.id, rf.refund_reference, rf.amount, rf.status, rf.refund_method, rf.refund_type, rf.order_id, rf.user_id,
                        o.order_number, o.customer_name, o.customer_email, u.first_name AS user_first_name
                 FROM refunds rf
                 INNER JOIN orders o ON o.id = rf.order_id
                 LEFT JOIN users u   ON u.id = rf.user_id
                 WHERE rf.id = ?',
                [$refundId]
            );
        } catch (Throwable $e) {
            return mail_fail('mail_send_refund_update(): ' . $e->getMessage());
        }
        if ($r === null) {
            return mail_fail('mail_send_refund_update(): refund #' . $refundId . ' not found.');
        }

        $first = trim((string) ($r['user_first_name'] ?? ''));
        if ($first === '') {
            $parts = preg_split('/\s+/', trim((string) $r['customer_name']));
            $first = (string) ($parts[0] ?? '');
        }

        return mail_notify([
            'event'        => 'refund_update',
            'user_id'      => !empty($r['user_id']) ? (int) $r['user_id'] : null,
            'to_email'     => (string) $r['customer_email'],
            'to_name'      => (string) $r['customer_name'],
            'vars'         => [
                'first_name'       => $first,
                'refund_reference' => (string) $r['refund_reference'],
                'amount'           => mail_money($r['amount']),
                'status'           => mail_label((string) $r['status']),
                'refund_method'    => mail_label((string) $r['refund_method']),
                'refund_type'      => mail_label((string) $r['refund_type']),
                'order_number'     => (string) $r['order_number'],
            ],
            'order_id'     => (int) $r['order_id'],
            'related_type' => 'refund',
            'related_id'   => (int) $r['id'],
            'dedupe_key'   => 'refund_update:' . (int) $r['id'] . ':' . $r['status'] . ':email',
            'cta'          => ['label' => 'View my orders', 'url' => mail_url(MAIL_ORDERS_PATH)],
        ]);
    }
}

// ---------------------------------------------------------------------
// 9. Setup test and command-line tools (no browser access)
// ---------------------------------------------------------------------
if (!function_exists('mail_config_summary')) {
    /** Current settings for troubleshooting (the password is never shown). */
    function mail_config_summary(): array
    {
        return [
            'driver'     => mail_driver(),
            'smtp_host'  => MAIL_HOST . ':' . MAIL_PORT . ' (' . MAIL_ENCRYPTION . ')',
            'smtp_user'  => (string) MAIL_USERNAME !== '' ? MAIL_USERNAME : '(none)',
            'smtp_pass'  => (string) MAIL_PASSWORD !== '' ? '(set)' : '(empty)',
            'from'       => MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
            'app_url'    => MAIL_APP_URL,
            'log_dir'    => MAIL_LOG_DIR,
            'local_file' => is_file(__DIR__ . '/mail.local.php') ? 'Config/mail.local.php loaded' : 'no Config/mail.local.php',
        ];
    }
}

if (!function_exists('mail_send_test')) {
    /** Send a test e-mail with the current settings (does not touch the database). */
    function mail_send_test(string $toEmail): array
    {
        $body = '<h2 style="margin:0 0 12px 0;font-family:Georgia,serif;color:' . mail_e(MAIL_COLOR_FOREST) . ';">Your e-mail setup works</h2>'
            . '<p style="margin:0 0 16px 0;">This is a test message from <strong>' . mail_e(MAIL_STORE_NAME) . '</strong>.</p>'
            . '<p style="margin:0;">Driver: <strong>' . mail_e(mail_driver()) . '</strong><br>Sent: ' . mail_e(date('M j, Y g:i A')) . '</p>';
        $html = mail_layout($body, ['preheader' => 'Test e-mail from ' . MAIL_STORE_NAME]);
        return mail_send($toEmail, MAIL_STORE_NAME . ' - test e-mail', $html);
    }
}

if (!function_exists('mail_cli')) {
    /**
     * php Config/mail.php test you@example.com | retry | config
     * @return int exit code
     */
    function mail_cli(array $args): int
    {
        $command = strtolower((string) ($args[0] ?? ''));

        if ($command === 'config') {
            foreach (mail_config_summary() as $key => $value) {
                echo str_pad($key, 11) . ': ' . $value . PHP_EOL;
            }
            return 0;
        }
        if ($command === 'test' && isset($args[1])) {
            $r = mail_send_test((string) $args[1]);
            echo $r['ok']
                ? 'OK (' . $r['driver'] . ')' . ($r['file'] ? ' - saved to ' . $r['file'] : ' - sent to ' . $args[1]) . PHP_EOL
                : 'FAILED (' . $r['driver'] . '): ' . $r['error'] . PHP_EOL;
            return $r['ok'] ? 0 : 1;
        }
        if ($command === 'retry') {
            $s = mail_retry_failed();
            echo 'Attempted: ' . $s['attempted'] . ', sent: ' . $s['sent'] . ', failed: ' . $s['failed'] . PHP_EOL;
            return 0;
        }

        echo "Usage:\n  php Config/mail.php config\n  php Config/mail.php test you@example.com\n  php Config/mail.php retry\n";
        return 2;
    }
}

// Run only when this file is executed directly from the command line.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    exit(mail_cli(array_slice($argv, 1)));
}