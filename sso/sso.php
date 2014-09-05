<?php

/**
 * @file
 * Creates cookies for each of the network sites to signal a login/logout.
 */

if (empty($_SERVER['HTTP_HOST'])) {
  // Some pre-HTTP/1.1 clients will not send a Host header.
  // We can't work around this.
  exit;
}

// The collection of sites for which this script will create cookies.
// Don't include the protocol (http://, https://).
// Example url (SSO script on subdomain): "a.firstsite.com"
// Example url (SSO script in the Drupal directory): "firstsite.com/sso.php"
$network = array(
  'a.firstsite.com',
  'a.shop.secondsite.com',
);

// Validate the query parameters and network size.
if (!sso_validate_query_params() || count($network) < 2) {
  exit;
}

// $_SERVER['HTTP_HOST'] is lowercased here per specifications.
$_SERVER['HTTP_HOST'] = strtolower($_SERVER['HTTP_HOST']);

$origin_site = $_GET['origin_host'];

// Create a list of sites that need to be visited, by removing the site
// which started the process and rekeying the array.
$origin_site_delta = sso_array_search($origin_site, $network);
if ($origin_site_delta === FALSE) {
  // Search for the origin site again, to account for subdomain-based SSO.
  $origin_site_delta = sso_array_search('a.' . $origin_site, $network);
}
if ($origin_site_delta !== FALSE) {
  unset($network[$origin_site_delta]);
}
$network = array_values($network);

if (ltrim($_SERVER['HTTP_HOST'], 'a.') == $origin_site) {
  // We are on the site which has started the process.
  // No need to create the cookie, the site already handled its login / logout.
  // Start from the beginning of the redirect list.
  $redirect_destination = sso_redirect_url($network[0]);
}
else {
  sso_create_cookie($_GET['op']);

  $current_site_delta = sso_array_search($_SERVER['HTTP_HOST'], $network);
  $next_site_delta = $current_site_delta + 1;
  if (isset($network[$next_site_delta])) {
    // Redirect to the next network site.
    $redirect_destination = sso_redirect_url($network[$next_site_delta]);
  }
  else {
    // We are at the last network site. In these scenarios, we need to
    // redirect to the destination, or to the original host in case of a logout.
    if ($_GET['op'] == 'login') {
      $redirect_destination = $_GET['destination'];
    }
    else {
      $redirect_destination = 'http://' . $_GET['origin_host'];
    }
  }
}

// Redirect the user.
header('Location: ' . $redirect_destination, TRUE, 302);
exit;

/**
 * Validates the query parameters.
 *
 * Required parameters:
 * - op: Tells us what the current operation is: login or logout.
 * - origin_host: Indicates which site initiated the login/logout.
 * Additional required parameter when the operation is "login":
 * - destination: The url to redirect the user to after all redirects are done.
 */
function sso_validate_query_params() {
  if (empty($_GET['op']) || empty($_GET['origin_host'])) {
    return FALSE;
  }
  if (!in_array($_GET['op'], array('login', 'logout'))) {
    return FALSE;
  }
  if ($_GET['op'] == 'login' && !isset($_GET['destination'])) {
    return FALSE;
  }

  return TRUE;
}

/**
 * Creates a cookie signaling the required operation.
 *
 * Removes any conflicting cookies.
 *
 * @param $operation
 *   The operation to signal, login or logout.
 */
function sso_create_cookie($operation) {
  if ($operation == 'login') {
    $remove = 'Drupal.visitor.SSOLogout';
    $create = 'Drupal.visitor.SSOLogin';
  }
  else {
    $remove = 'Drupal.visitor.SSOLogin';
    $create = 'Drupal.visitor.SSOLogout';
  }

  $domain = ltrim($_SERVER['HTTP_HOST'], 'a.');
  setcookie($remove, '', time() - 3600, '/', $domain);
  // The expiration should be less than the Drupal session duration.
  // The most common Drupal `session.gc_maxlifetime` value is 200000 seconds,
  // so we define the expiration to half a minute before that, accordingly.
  setcookie($create, 1, time() + 200000 - 30, '/', $domain);
}

/**
 * Returns an URL to which redirection can be issued.
 */
function sso_redirect_url($host) {
  $url = 'http://' . $host . '?op=' . $_GET['op'] . '&origin_host=' . $_GET['origin_host'];
  if ($_GET['op'] == 'login') {
    $url .= '&destination=' . $_GET['destination'];
  }
  return $url;
}

/**
 * Same as array_search, but does a STARTS_WITH instead of a "=" comparison.
 *
 * Useful for matching network urls while not caring about the script names
 * such as "/index.php" or "/sso.php".
 */
function sso_array_search($needle, $haystack) {
  foreach ($haystack as $key => $value) {
    if (strpos($value, $needle) === 0) {
      return $key;
    }
  }

  return FALSE;
}
