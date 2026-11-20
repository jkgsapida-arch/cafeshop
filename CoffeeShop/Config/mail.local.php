<?php
/**
 * =====================================================================
 *  COFFEE SHOP WEB SYSTEM  -  Config/mail.local.php
 *  Project  : Forest & Cream Coffee (Web System and Cloud Computing - Finals)
 *  Loaded by: Config/mail.php  (section 1, BEFORE its own defaults)
 * =====================================================================
 *
 *  PURPOSE
 *    Private, machine-specific e-mail settings. Config/mail.php loads this
 *    file first, and every setting there is wrapped in !defined(), so any
 *    constant defined HERE wins over the defaults in mail.php.
 *
 *    Keep real passwords in THIS file only (and keep it out of Git).
 *    Add this line to .gitignore:
 *        Config/mail.local.php
 *
 *  DRIVER (MAIL_DRIVER)
 *    'smtp' -> real delivery through Gmail (configured below).
 *    'log'  -> no internet needed; every e-mail is saved as an HTML file in
 *              Emails/outbox/ so you can open it in the browser. Switch to
 *              'log' for offline development or if SMTP fails during a demo.
 *    'mail' -> PHP mail() (needs sendmail configured in XAMPP).
 *
 *  GMAIL REQUIREMENTS
 *    * 2-Step Verification must be ON for the Google account.
 *    * MAIL_PASSWORD must be a 16-character Google "App Password"
 *      (https://myaccount.google.com/apppasswords), NOT the normal login
 *      password. Spaces in the App Password are optional; they are removed
 *      below.
 *    * Gmail rewrites the From address to the authenticated account, so
 *      MAIL_FROM_ADDRESS is set to the same Gmail address.
 *    * Gmail's free limit is roughly 500 recipients/day - fine for the demo.
 *
 *  TEST FROM A TERMINAL (XAMPP Shell or Command Prompt)
 *      C:\xampp\php\php.exe C:\xampp\htdocs\CoffeeShop\Config\mail.php config
 *      C:\xampp\php\php.exe C:\xampp\htdocs\CoffeeShop\Config\mail.php test you@example.com
 *      C:\xampp\php\php.exe C:\xampp\htdocs\CoffeeShop\Config\mail.php retry
 *
 *  ENVIRONMENT VARIABLES (for a cloud host - no need to edit this file)
 *    Every value below first looks at an environment variable, so on a
 *    cloud server you can set COFFEESHOP_MAIL_* variables instead of
 *    shipping a password in a file. Names:
 *      COFFEESHOP_MAIL_DRIVER, _HOST, _PORT, _ENCRYPTION, _USERNAME,
 *      _PASSWORD, _FROM, _FROM_NAME, _REPLY_TO, _VERIFY_PEER,
 *      COFFEESHOP_APP_URL
 *
 *  SECURITY NOTES
 *    * This file cannot be run from the browser (guard below).
 *    * Never echo or log MAIL_PASSWORD. mail.php never does.
 *    * If this password was ever pasted into a chat, a screenshot, a
 *      repository or the comments of mail.php, REVOKE it in your Google
 *      account and generate a new App Password.
 * =====================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// 0. Block direct browser access (http://localhost/CoffeeShop/Config/mail.local.php)
//    When loaded by Config/mail.php this file is only "included", so the
//    check below does not trigger.
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Safety net: if somebody includes this file on its own, make sure the
// small env helpers from Config/mail.php exist (they are identical).
// ---------------------------------------------------------------------
if (!function_exists('mail_env')) {
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

// ---------------------------------------------------------------------
// 1. Delivery driver:  smtp | log | mail
// ---------------------------------------------------------------------
if (!defined('MAIL_DRIVER'))       define('MAIL_DRIVER', mail_env('COFFEESHOP_MAIL_DRIVER', 'smtp'));

// ---------------------------------------------------------------------
// 2. SMTP account (Gmail + App Password)
// ---------------------------------------------------------------------
if (!defined('MAIL_HOST'))         define('MAIL_HOST',       mail_env('COFFEESHOP_MAIL_HOST', 'smtp.gmail.com'));
if (!defined('MAIL_PORT'))         define('MAIL_PORT',       (int) mail_env('COFFEESHOP_MAIL_PORT', '587'));
if (!defined('MAIL_ENCRYPTION'))   define('MAIL_ENCRYPTION', mail_env('COFFEESHOP_MAIL_ENCRYPTION', 'tls'));      // tls = STARTTLS (587) | ssl (465) | none
if (!defined('MAIL_USERNAME'))     define('MAIL_USERNAME',   mail_env('COFFEESHOP_MAIL_USERNAME', 'myddumpp@gmail.com'));
if (!defined('MAIL_PASSWORD'))     define('MAIL_PASSWORD',   str_replace(' ', '', mail_env('COFFEESHOP_MAIL_PASSWORD', 'lvxwrvjtaoicrcgs')));  // Google App Password
if (!defined('MAIL_TIMEOUT'))      define('MAIL_TIMEOUT',    15);                                                 // seconds

// TLS certificate check. Keep TRUE (secure). Set it to false ONLY on a local
// XAMPP machine if "Could not start TLS encryption" appears, or fix it
// properly by pointing php.ini's openssl.cafile to a cacert.pem file.
// Must be TRUE on any deployed/cloud server.
if (!defined('MAIL_VERIFY_PEER'))  define('MAIL_VERIFY_PEER', mail_env_bool('COFFEESHOP_MAIL_VERIFY_PEER', true));

// ---------------------------------------------------------------------
// 3. Sender and reply address
// ---------------------------------------------------------------------
if (!defined('MAIL_FROM_ADDRESS')) define('MAIL_FROM_ADDRESS', mail_env('COFFEESHOP_MAIL_FROM', 'myddumpp@gmail.com'));   // Gmail forces this to the account above anyway
if (!defined('MAIL_FROM_NAME'))    define('MAIL_FROM_NAME',    mail_env('COFFEESHOP_MAIL_FROM_NAME', 'Forest & Cream Coffee'));
if (!defined('MAIL_REPLY_TO'))     define('MAIL_REPLY_TO',     mail_env('COFFEESHOP_MAIL_REPLY_TO', 'myddumpp@gmail.com')); // customers' replies land here
if (!defined('MAIL_SUPPORT_EMAIL'))define('MAIL_SUPPORT_EMAIL','myddumpp@gmail.com');                                    // shown in the e-mail footer (DB setting "support_email" overrides it at send time)

// ---------------------------------------------------------------------
// 4. Links inside e-mails (verification, password reset, "View my order")
//    No trailing slash. CHANGE THIS when the site is deployed to the cloud,
//    e.g. https://coffee.example.com   (or set COFFEESHOP_APP_URL).
//    It is used instead of the browser's Host header, which prevents
//    password-reset-link poisoning.
// ---------------------------------------------------------------------
if (!defined('MAIL_APP_URL'))      define('MAIL_APP_URL', rtrim(mail_env('COFFEESHOP_APP_URL', 'http://localhost/CoffeeShop'), '/'));

// ---------------------------------------------------------------------
// 5. Optional overrides (uncomment to change; defaults live in mail.php)
// ---------------------------------------------------------------------
// if (!defined('MAIL_LOGO_URL'))      define('MAIL_LOGO_URL', 'https://example.com/logo.png');   // absolute https:// URL
// if (!defined('MAIL_MAX_ATTEMPTS'))  define('MAIL_MAX_ATTEMPTS', 3);                            // tries per notification
// if (!defined('MAIL_RETRY_MINUTES')) define('MAIL_RETRY_MINUTES', 10);                          // wait between retries
// if (!defined('MAIL_LOG_DIR'))       define('MAIL_LOG_DIR', 'C:/xampp/htdocs/CoffeeShop/Emails/outbox');