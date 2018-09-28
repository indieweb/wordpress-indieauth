<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

$auth = filter_input(
    INPUT_POST,
    'auth',
    FILTER_VALIDATE_REGEXP,
    array('options' => array('regexp' => '@^authtest_\d+$@'))
);
if (is_string($auth)) {
    $auth = 'Bearer ' . $auth;
    exit(json_encode(array(
        'server' => array_keys($_SERVER, $auth, true),
        'getallheaders' => function_exists('getallheaders')
            ? array_keys(getallheaders(), $auth, true)
            : null,
        'apache_request_headers' => function_exists('apache_request_headers')
            ? array_keys(apache_request_headers(), $auth, true)
            : null,
    )));
}

$json = null;
$url = filter_input(
    INPUT_POST,
    'url',
    FILTER_VALIDATE_URL,
    FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED
);
if (is_string($url)) {
    $auth = 'authtest_' . strval(time());
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    if (defined('CURL_HTTP_VERSION_2')) {
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2);
    }
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(array('auth' => $auth)));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $auth));
    $json = json_decode(curl_exec($curl), true);
    curl_close($curl);
}

?><!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Does this server receive Authorization headers?</title>
    <style>
      html, body {
        background-color: #FFF;
        color: #111;
        font-family: system-ui, sans-serif;
        font-size: 100%;
        margin: 0;
        padding: 0;
      }
      body {
        padding: 1em 2em 2em;
        max-width: 50em;
      }
      code, pre {
        color: #B10DC9;
        font-family: monospace, monospace;
        font-size: 100%;
      }
      pre {
        border-left: 3px solid #B10DC9;
        margin-left: -3px;
        padding-left: 1em;
        padding-left: 2ch;
      }
      .green {
        color: #3D9970;
      }
      .red {
        color: #FF4136;
      }
    </style>
  </head>
  <body>
    <h1>Test Authorization Headers</h1>
    <form method="post">
      <label for="url">URL of this page</label>
      <input type="url" id="url" name="url" placeholder="http…/authdiag.php"<?php if (is_string($url)) { echo ' value="' . htmlspecialchars($url) . '"'; } ?>>
      <button type="submit">Run Test</button>
    </form>
<?php if ($json !== null) { ?>
    <h2>Summary Results</h2>
<?php if (in_array('HTTP_AUTHORIZATION', $json['server'])) { ?>
    <p><code>$_SERVER['HTTP_AUTHORIZATION']</code> is <b class="green">available</b> on this server! Everything should be fine as most tools depend on reading that variable.</p>
<?php } else { ?>
    <p><code>$_SERVER['HTTP_AUTHORIZATION']</code> is <b class="red">unavailable</b> on this server. You may need to <a href="https://wordpress.org/plugins/micropub/#faq-header">change some configurations</a> to get tools working.</p>
<?php } ?>
<?php if (in_array('REDIRECT_HTTP_AUTHORIZATION', $json['server'])) { ?>
    <p><code>$_SERVER['REDIRECT_HTTP_AUTHORIZATION']</code> is <b class="green">available</b> on this server! This can be used as a fallback for <code>$_SERVER['HTTP_AUTHORIZATION']</code>.</p>
<?php } else { ?>
    <p><code>$_SERVER['REDIRECT_HTTP_AUTHORIZATION']</code> is <b class="red">unavailable</b> on this server. Some tools may use this as fallback for <code>$_SERVER['HTTP_AUTHORIZATION']</code>.</p>
<?php } ?>
<?php if ($json['getallheaders'] === null) { ?>
    <p>Tools may use <code>getallheaders()</code>, but this server does <b class="red">not support</b> that function.</p>
<?php } else if (is_array($json['getallheaders']) && count($json['getallheaders']) > 0) { ?>
    <p>Tools may use <code>getallheaders()</code>, the authorization header is <b class="green">available</b> there!</p>
<?php } else if (is_array($json['getallheaders'])) { ?>
    <p>Tools may use <code>getallheaders()</code>, but the authorization header was <b class="red">not found</b> there.</p>
<?php } else { ?>
    <p>Tools may use <code>getallheaders()</code>. This server supports the function, but was unable to use it for this test.</p>
<?php } ?>
<?php if ($json['apache_request_headers'] === null) { ?>
    <p>Tools may use <code>apache_request_headers()</code>, but this server does <b class="red">not support</b> that function.</p>
<?php } else if (is_array($json['apache_request_headers']) && count($json['apache_request_headers']) > 0) { ?>
    <p>Tools may use <code>apache_request_headers()</code>, the authorization header is <b class="green">available</b> there!</p>
<?php } else if (is_array($json['apache_request_headers'])) { ?>
    <p>Tools may use <code>apache_request_headers()</code>, but the authorization header was <b class="red">not found</b> there.</p>
<?php } else { ?>
    <p>Tools may use <code>apache_request_headers()</code>. This server supports the function, but was unable to use it for this test.</p>
<?php } ?>
    <h2>Full Results</h2>
    <p>This is mostly for your friendly neighbourhood developer.</p>
    <pre><?php var_dump($json); ?></pre>
<?php } ?>
  </body>
</html>
