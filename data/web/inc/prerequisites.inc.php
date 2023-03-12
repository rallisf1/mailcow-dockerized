<?php

// check for development mode
$DEV_MODE = (getenv('DEV_MODE') == 'y');
// check for demo mode
$DEMO_MODE = (getenv('DEMO_MODE') == 'y');

// Slave does not serve UI
/* if (!preg_match('/y|yes/i', getenv('MASTER'))) {
  header('Location: /SOGo', true, 307);
  exit;
}*/

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/vars.inc.php';

$default_autodiscover_config = $autodiscover_config;

if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/inc/vars.local.inc.php')) {
  include_once $_SERVER['DOCUMENT_ROOT'] . '/inc/vars.local.inc.php';
}

// auto-generated by generate-config.sh and update.sh
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/inc/app_info.inc.php')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/app_info.inc.php';
}
unset($https_port);
$autodiscover_config = array_merge($default_autodiscover_config, $autodiscover_config);

header_remove("X-Powered-By");

// Yubi OTP API
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/Yubico.php';

// WebAuthn
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/WebAuthn/WebAuthn.php';

// Autoload composer
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/vendor/autoload.php';

// Load Sieve
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/sieve/SieveParser.php';

// minifierExtended
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/JSminifierExtended.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/CSSminifierExtended.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/lib/array_merge_real.php';

// U2F API + T/HOTP API
// u2f - deprecated, should be removed
$u2f = new u2flib_server\U2F('https://' . $_SERVER['HTTP_HOST']);
$qrprovider = new RobThree\Auth\Providers\Qr\QRServerProvider();
$tfa = new RobThree\Auth\TwoFactorAuth($OTP_LABEL, 6, 30, 'sha1', $qrprovider);

// FIDO2
$server_name = parse_url('https://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST);
$formats = $GLOBALS['FIDO2_FORMATS'];
$WebAuthn = new lbuchs\WebAuthn\WebAuthn('WebAuthn Library', $server_name, $formats);
// only include root ca's when needed
if (getenv('WEBAUTHN_ONLY_TRUSTED_VENDORS') == 'y') $WebAuthn->addRootCertificates($_SERVER['DOCUMENT_ROOT'] . '/inc/lib/WebAuthn/rootCertificates');

// Redis
$redis = new Redis();
try {
  if (!empty(getenv('REDIS_SLAVEOF_IP'))) {
    $redis->connect(getenv('REDIS_SLAVEOF_IP'), getenv('REDIS_SLAVEOF_PORT'));
  }
  else {
    $redis->connect('redis-mailcow', 6379);
  }
}
catch (Exception $e) {
?>
<center style='font-family:sans-serif;'>Connection to Redis failed.<br /><br />The following error was reported:<br/><?=$e->getMessage();?></center>
<?php
exit;
}

// PDO
// Calculate offset
// $now = new DateTime();
// $mins = $now->getOffset() / 60;
// $sgn = ($mins < 0 ? -1 : 1);
// $mins = abs($mins);
// $hrs = floor($mins / 60);
// $mins -= $hrs * 60;
// $offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);

$dsn = $database_type . ":unix_socket=" . $database_sock . ";dbname=" . $database_name;
$opt = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
  //PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . $offset . "', group_concat_max_len = 3423543543;",
];
try {
  $pdo = new PDO($dsn, $database_user, $database_pass, $opt);
}
catch (PDOException $e) {
// Stop when SQL connection fails
?>
<center style='font-family:sans-serif;'>Connection to database failed.<br /><br />The following error was reported:<br/>  <?=$e->getMessage();?></center>
<?php
exit;
}
// Stop when dockerapi is not available
if (fsockopen("tcp://dockerapi", 443, $errno, $errstr) === false) {
?>
<center style='font-family:sans-serif;'>Connection to dockerapi container failed.<br /><br />The following error was reported:<br/><?=$errno;?> - <?=$errstr;?></center>
<?php
exit;
}

// OAuth2
class mailcowPdo extends OAuth2\Storage\Pdo {
  public function __construct($connection, $config = array()) {
    parent::__construct($connection, $config);
    $this->config['user_table'] = 'mailbox';
  }
  public function checkUserCredentials($username, $password) {
    if (check_login($username, $password) == 'user') {
      return true;
    }
    return false;
  }
  public function getUserDetails($username) {
    return $this->getUser($username);
  }
}
$oauth2_scope_storage = new OAuth2\Storage\Memory(array('default_scope' => 'profile', 'supported_scopes' => array('profile')));
$oauth2_storage = new mailcowPdo(array('dsn' => $dsn, 'username' => $database_user, 'password' => $database_pass));
$oauth2_server = new OAuth2\Server($oauth2_storage, array(
    'refresh_token_lifetime'         => $REFRESH_TOKEN_LIFETIME,
    'access_lifetime'                => $ACCESS_TOKEN_LIFETIME,
));
$oauth2_server->setScopeUtil(new OAuth2\Scope($oauth2_scope_storage));
$oauth2_server->addGrantType(new OAuth2\GrantType\AuthorizationCode($oauth2_storage));
$oauth2_server->addGrantType(new OAuth2\GrantType\UserCredentials($oauth2_storage));
$oauth2_server->addGrantType(new OAuth2\GrantType\RefreshToken($oauth2_storage, array(
    'always_issue_new_refresh_token' => true
)));

function exception_handler($e) {
    if ($e instanceof PDOException) {
      $_SESSION['return'][] = array(
        'type' => 'danger',
        'log' => array(__FUNCTION__),
        'msg' => array('mysql_error', $e)
      );
      return false;
    }
    else {
      $_SESSION['return'][] = array(
        'type' => 'danger',
        'log' => array(__FUNCTION__),
        'msg' => 'An unknown error occured: ' . print_r($e, true)
      );
      return false;
    }
}
if(!$DEV_MODE) {
  set_exception_handler('exception_handler');
}

// TODO: Move function
function get_remote_ip() {
  $remote = $_SERVER['REMOTE_ADDR'];
  if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
    return '0.0.0.0';
  }
  return $remote;
}

// Load core functions first
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.auth.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/sessions.inc.php';

// IMAP lib
// use Ddeboer\Imap\Server;
// $imap_server = new Server('dovecot', 143, '/imap/tls/novalidate-cert');

// Set language
if (!isset($_SESSION['mailcow_locale']) && !isset($_COOKIE['mailcow_locale'])) {
  if ($DETECT_LANGUAGE && isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    // regex inspired from @GabrielAnderson on http://stackoverflow.com/questions/6038236/http-accept-language
    preg_match_all('/([a-z]{1,8}(-[a-z]{1,8})*)\s*(;\s*q\s*=\s*(1|0\.[0-9]+))?/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $lang_parse);

    $langs = $lang_parse[1];
    $ranks = $lang_parse[4];

    // (create an associative array 'language' => 'preference')
    $lang2pref = array();
    for ($i=0; $i<count($langs); $i++) {
      $lang2pref[strtolower($langs[$i])] = (float) (!empty($ranks[$i]) ? $ranks[$i] : 1);
    }

    // (comparison function for uksort)
    $cmpLangs = function ($a, $b) use ($lang2pref) {
      if ($lang2pref[$a] > $lang2pref[$b])
        return -1;
      elseif ($lang2pref[$a] < $lang2pref[$b])
        return 1;
      elseif (strlen($a) > strlen($b))
        return -1;
      elseif (strlen($a) < strlen($b))
        return 1;
      else
        return 0;
    };

    // sort the languages by prefered language and by the most specific region
    uksort($lang2pref, $cmpLangs);

    // generate language array without the region part
    $AVAILABLE_BASE_LANGUAGES=array();
    foreach ($AVAILABLE_LANGUAGES as $code => $lang) {
      $base_code = substr($code, 0, 2);
      if (!array_key_exists($base_code, $AVAILABLE_BASE_LANGUAGES)) {
        $AVAILABLE_BASE_LANGUAGES[$base_code] = $code;
      }
    }

    // Find a perfect match or partial match
    // Match en-gb or en
    foreach ($lang2pref as $lang => $q) {
      if (array_key_exists($lang, $AVAILABLE_LANGUAGES)) {
        $_SESSION['mailcow_locale'] = $lang;
        break;
      } elseif (array_key_exists($lang, $AVAILABLE_BASE_LANGUAGES)) {
        $_SESSION['mailcow_locale'] = $AVAILABLE_BASE_LANGUAGES[$lang];
        break;
      }
    }

    // Try suggest match
    // e.g. suggest en-gb when only en-us is provided
    if (!isset($_SESSION['mailcow_locale'])) {
      foreach ($lang2pref as $lang => $q) {
        if (array_key_exists(substr($lang, 0, 2), $AVAILABLE_BASE_LANGUAGES)) {
          $_SESSION['mailcow_locale'] = $AVAILABLE_BASE_LANGUAGES[substr($lang, 0, 2)];
          break;
        }
      }
    }
  }
  else {
    $_SESSION['mailcow_locale'] = strtolower(trim($DEFAULT_LANG));
  }
}
if (isset($_COOKIE['mailcow_locale'])) {
  (preg_match('/^[a-z]{2}$/', $_COOKIE['mailcow_locale'])) ? $_SESSION['mailcow_locale'] = $_COOKIE['mailcow_locale'] : setcookie("mailcow_locale", "", time() - 300);
}
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $AVAILABLE_LANGUAGES)) {
  $_SESSION['mailcow_locale'] = $_GET['lang'];
  setcookie("mailcow_locale", $_GET['lang'], time()+30758400); // one year
}

/*
 * load language
 */
$lang = json_decode(file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/lang/lang.en-gb.json'), true);

$langFile = $_SERVER['DOCUMENT_ROOT'] . '/lang/lang.'.$_SESSION['mailcow_locale'].'.json';
if(file_exists($langFile)) {
  $lang = array_merge_real($lang, json_decode(file_get_contents($langFile), true));
}


require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.acl.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.address_rewriting.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.admin.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.app_passwd.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.customize.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.dkim.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.docker.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.domain_admin.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.fail2ban.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.fwdhost.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.mailbox.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.mailq.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.oauth2.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.policy.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.presets.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.pushover.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.quarantine.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.quota_notification.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.ratelimit.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.rspamd.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.tls_policy_maps.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.transports.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/init_db.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/triggers.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/twig.inc.php';
init_db_schema();
if (isset($_SESSION['mailcow_cc_role'])) {
  // if ($_SESSION['mailcow_cc_role'] == 'user') {
    // list($master_user, $master_passwd) = explode(':', file_get_contents('/etc/sogo/sieve.creds'));
    // $imap_connection = $imap_server->authenticate($_SESSION['mailcow_cc_username'] . '*' . trim($master_user), trim($master_passwd));
    // $master_user = $master_passwd = null;
  // }
  acl('to_session');
}

// init frontend
// Minify JS
use MatthiasMullie\Minify;
$js_minifier = new JSminifierExtended();
$js_dir = array_diff(scandir('/web/js/build'), array('..', '.'));
// Minify CSS
$css_minifier = new CSSminifierExtended();
$css_dir = array_diff(scandir('/web/css/build'), array('..', '.'));
// get customized ui data
$UI_TEXTS = customize('get', 'ui_texts');


// minify bootstrap theme
if (file_exists('/web/css/themes/'.$UI_THEME.'-bootstrap.css'))
  $css_minifier->add('/web/css/themes/'.$UI_THEME.'-bootstrap.css');
else
  $css_minifier->add('/web/css/themes/lumen-bootstrap.css'); 
// minify css build files
foreach ($css_dir as $css_file) {
  $css_minifier->add('/web/css/build/' . $css_file);
}
// minify js build files
foreach ($js_dir as $js_file) {
  $js_minifier->add('/web/js/build/' . $js_file);
}
