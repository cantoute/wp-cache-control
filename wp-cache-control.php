<?php

/**
 * Plugin Name: WP Cache-Control
 * Plugin URI: https://github.com/cantoute/wp-cache-control
 * GitHub Plugin URI: https://github.com/cantoute/wp-cache-control
 * Description: Cache-Control for WordPress with Reverse Proxy in mind (ie varnish, squid)
 * Version: 0.0
 * Author: Antony GIBBS <antony@cantoute.com>
 * Author URI: http://cantoute.com
 */

defined('ABSPATH') or die('No script kiddies please!');

require_once plugin_dir_path(__FILE__) . 'config.default.php';

if(file_exists(plugin_dir_path(__FILE__).'config.local.php')) {
  try {
    require_once plugin_dir_path(__FILE__) . 'config.local.php';
  } catch (Exception $e) {
    // TODO: error handling
  }
}

// return a value if we have a matching key or null as default
function wpcc_settings($key)
{
  // TODO:
  if ($key == 'isReverseProxy')
    return null;

  // no match TODO: some form of alert when asked for a non valid key ?
  return null;
}

// out of wp mess guess if page is cacheable
function wpcc_unCacheable()
{
  global $wp_query;

  $unCacheable = (is_preview()
    || is_user_logged_in()
    || is_trackback()
    || is_admin());

  // Requires post password, and post has been unlocked.
  if (
    !$unCacheable
    && isset($wp_query)
    && isset($wp_query->posts)
    && count($wp_query->posts) >= 1
    && !empty($wp_query->posts[0]->post_password)
    && !post_password_required()
  ) {
    $unCacheable = true;
  }

  // WooCommerce support
  elseif (
    !$unCacheable
    && is_woocommerce_activated()
  ) {
    $unCacheable = (is_cart()
      || is_checkout()
      || is_account_page());
  }

  return $unCacheable;
}

function wpcc_fromArgs($args)
{
  $cc = [];
  $cc['fromArgs'] = $args; // for debug
  $cc['cc'] = []; // store the Cache-Control header elements
  $cc['sw'] = []; // switch that could help decision taking
  $cc['sw']['isReverseProxy'] = wpcc_settings('isReverseProxy');
  $cc['sw']['isEmpty'] = true; // In the beginning there was nothing

  foreach ([
    'directive', // public|private|no-cache|must-revalidate|proxy-must-revalidate TODO: check list
    'max-age',
    's-maxage',
    'stale-while-revalidate',
    'stale-if-error'
  ] as $k) {
    $cc['cc'][$k] = (array_key_exists($k, $args)) ? convertToSeconds($args[$k]) : null;
  }

  if (
    $cc['sw']['isReverseProxy'] === null // aka auto
    // one of those is asking a reverse proxy to keep it
    && (((int) $cc['cc']['s-maxage'] + (int) $cc['cc']['stale-while-revalidate'] + (int) $cc['cc']['stale-if-error']) > 0)
    && strpos($cc['cc']['directive'], 'private') === false
  ) {
    $cc['sw']['isReverseProxy'] = true;
  }

  if (
    $cc['cc']['directive'] === null // aka auto
  ) { // then we can add the public directive explicitly
    if ($cc['sw']['isReverseProxy']) {
      $cc['cc']['directive'] = 'public';
    }
  }

  foreach ($cc['cc'] as $k => $v) {
    // null || ''
    if ($v != '') {
      $cc['sw']['isEmpty'] = false;
    }
  }

  return $cc;
}

/*
 * accepts an array of Cache-Control directives (case sensitive)
 * the 'directive' key will be to set extra (Ex: 'public, must-revalidate')
 */
function wpcc_theCacheControl($args)
{
  $cc = wpcc_fromArgs($args);

  if (count($args)) {
    header('Cache-Control: ' . implode(', ', $cc['cc']), true); // true = replace header
  } else {
    // we are in charge of this header

    header_remove('Cache-Control');
  }

  // remove headers that could block reverse proxy caching
  if ($cc['sw']['isReverseProxy']) {
    header_remove('Set-Cookie');
    header_remove('Pragma');
    // we could be smarter on this one... but in general it can only come in the way
    // isn't it mostly used by dev wanting to get cache out of the way?
    // We just want things to obey Cache-Control?
    header_remove('Expires');
  }
}

function wpcc_cleanup()
{
  // Using Cache-Control header, then no need for those
  // and if we asked for caching proxy side,
  // we want to get rid of Set-Cookie
  header_remove('Expires');
  header_remove('Pragma');
  header_remove('Set-Cookie');
}

add_filter('wp_redirect_status', 'wpcc_redirects', 10, 2);

function wpcc_redirects($status, $location = null)
{
  if (wpcc_unCacheable()) {
    // do nothing
  } elseif ($status == 301) {
    wpcc_cleanup();
    // TODO:
    header('Cache-Control: max-age=3600, s-maxage=8640000, stale-while-revalidate=86400', true);
  }

  // not my job?
  // Include a minimal body message. Recommended by HTTP spec, required by many caching proxies.
  // if (in_array($status, array( "301", "302", "303", "307", "308" ))) {
  //     if (ob_start()) {
  //         $location_attr = esc_attr($location);
  //         print("<!doctype html>\n<meta charset=\"utf-8\">\n<title>Document moved</title>\n<p>Document has <a href=\"${location_attr}\">moved here</a>.</p>");
  //     }
  // }

  return $status;
}

// we want to be called last to cleanup after stupid plugins
add_action('template_redirect', 'wpcc_templates', 10000000);

function wpcc_templates()
{
  // TODO:

  /* form cache-control
  if (cache_control_nocacheables()) {
    return cache_control_build_directive_header(false, false, false, false);
  } elseif (is_feed()) {
    return cache_control_build_directive_from_option('feeds');
  } elseif (is_front_page() && !is_paged()) {
    return cache_control_build_directive_from_option('front_page');
  } elseif (is_single()) {
    return cache_control_build_directive_from_option('singles');
  } elseif (is_page()) {
    return cache_control_build_directive_from_option('pages');
  } elseif (is_home()) {
    return cache_control_build_directive_from_option('home');
  } elseif (is_category()) {
    return cache_control_build_directive_from_option('categories');
  } elseif (is_tag()) {
    return cache_control_build_directive_from_option('tags');
  } elseif (is_author()) {
    return cache_control_build_directive_from_option('authors');
  } elseif (is_attachment()) {
    return cache_control_build_directive_from_option('attachment');
  } elseif (is_search()) {
    return cache_control_build_directive_from_option('search');
  } elseif (is_404()) {
    return cache_control_build_directive_from_option('notfound');
  } elseif (is_date()) {
    if ((is_year() && strcmp(get_the_time('Y'), date('Y')) < 0) || (is_month() && strcmp(get_the_time('Y-m'), date('Y-m')) < 0) || ((is_day() || is_time()) && strcmp(get_the_time('Y-m-d'), date('Y-m-d')) < 0)
    ) {
      return cache_control_build_directive_from_option('dates');
    } else {
      return cache_control_build_directive_from_option('home');
    }
  } elseif (cache_control_does_woocommerce()) {
    if (function_exists('is_product') && is_product()) {
      return cache_control_build_directive_from_option('woocommerce_product');
    } elseif (function_exists('is_product_category') && is_product_category()) {
      return cache_control_build_directive_from_option('woocommerce_category');
    }
  }
  */
}


/*
 * Utilities
 */

function convertToSeconds($s)
{
  $secondsPerUnit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

  if ($s === null) {
    return null;
  } elseif ((int) $s == $s) {
    // it's a raw number
    return (int) $s;
  } else {
    return (int) intval(substr($s, 0, -1)) * (int) $secondsPerUnit[substr($s, -1)];
  }
}


/**
 * Check if WooCommerce is activated
 * form https://docs.woocommerce.com/document/query-whether-woocommerce-is-activated/
 */
if (!function_exists('is_woocommerce_activated')) {
  function is_woocommerce_activated()
  {
    if (class_exists('woocommerce')) {
      return true;
    } else {
      return false;
    }
  }
}
