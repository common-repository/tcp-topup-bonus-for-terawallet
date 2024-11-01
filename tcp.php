<?php
namespace TheCartPress;

// only classes need to import, func & constants auto fallback to global context
use Closure;
use DateTime;
use DateTimeZone;
use Exception;
use JsonSerializable;
use ReflectionClass;
use stdClass;

defined('ABSPATH') or exit;
/**
 * Changelogs:
 *
 * 20220714 - enable/disable console log by passing ?tcp_enable_console_log=0/1/2, tcp_enable_console_log() become internal, fix tcp_trace use tcp_v4 id
 * 20220712 - tcp_var_dump() support dump JSON string as assoc array
 * 20220707 - add tcp_var_dump(), tcp_add_route() support homepage route, tcp_log() console log support html tcp_var_dump(), tcp_init_plugin() assign $obj to class static $instance
 * 20220706 - add tcp_route_head_* & tcp_route_footer_* hook, tcp_trace() & tcp_var_export() support JsonSerializable, add TCP_MAX_CONSOLE_LOG
 * 20220705 - tcp_trace() support dump stdClass
 * 20220630 - tcp_v4, use TheCartPress namespace, tcp_* prefix
 * 20220630 - removed TCPP_DateTimeZone() changed to wp_timezone()
 * 20220629 - change tcp icon to svg base64 & use inline css, add $use_theme to tcpp_add_route()
 * 20220628 - tcp_v3, rename func with prefix tcpp_, add tcpp_include(), removed tcpp_br2nl(), tcpp_split_by_lines()
 * 20220624 - add tcp_trace(), show dbm in Query Monitor, dbm use transient
 * 20220623 - implement file-based caching if no external object cache used
 * 20220622 - __()
 * 20220621 - display dbm in TCP plugin list page
 * 20220620 - tcp_v2, rename func with prefix tcp_
 * 20220614 - add WP_DateTime::createFromFormat(), deprecate create WP_DateTime() using format from constructor
 * 20220613 - removed is_hub() & enable_error_log(), add add_notice() & redirect_notice(), rename get_wp_filesystem() to get_wpfs(), *_log() support JS console.log() style parameter
 * 20220610 - add $asset_version & $is_hub to main plugin obj in init_plugin()
 * 20220607 - add init_plugin()
 * 20220603 - add timer()
 * 20220527 - add wp_var_export() & is_hub(), enable_console_log() will set custom error handler & exception handler
 * 20220526 - debug_log() & console_log() log WP_Error message, enable_console_log() use add_route()
 * 20220524 - add wp_cache_autoget()
 * 20220513 - add wp_sanitize_options(), str_replace_end() and str_replace_start()
 * 20220512 - add_route() support URL path pattern
 * 20220425 - TCP_updater disable get_info_json() for 1 hr if loading problem > 2 times
 * 20220420 - add get_caller()
 * 20220413 - add add_dbm(), add_tcp_menu() & register_updater(), add back console_log(), rename attach_console_log() to enable_console_log()
 * 20220407 - add get_wp_filesystem(), replace console_log & attach_console_log() with enable_error_log()
 * 20220405 - add add_route()
 * 20220331 - add is_plugin_installed_and_activated()
 * 20220325 - merge tcp-util.php, tcp-menu.php & updater.php into tcp.php, MYSQL_FORMAT added to WP_DateTime
 * 20220323 - changed to TCP_updater, add br2nl(), console_log(), attach_console_log()
 * 20220125 - define Reso_WP_updater class only when is_admin()
 * 20220124 - Update include code & json[banner][low|high] checking
 * 20220121 - change to check current version is lower, instead of different
 * 20220117 - Support set dbm & dbm_key through /wp-admin/plugins.php page
 * 20220113 - info_url become required parameter, json[sections] checking
 * 20220105 - info.json & plugin zip all use cdn.700tb.com
 * 20220104 - Support custom info_url
 * 20211207 - Initial release
 *
 * Global variables:
 * - $tcp_include_files : array
 * - $tcp_routes_v4 : array
 * - $tcp_notice_attached_v4 : bool
 * - $tcp_console_log_enabled : bool
 * - $tcp_traces_v4 : array
 * - $tcp_trace_v2 : bool
 */
if (!defined('TCP_MAX_CONSOLE_LOG')) {
	define('TCP_MAX_CONSOLE_LOG', 1000);
}

if (!function_exists(__NAMESPACE__ . '\tcp_is_plugin_available')) {

	/**
	 * @param string $id ID of plugin, e.g woocommerce
	 * @param string $name Name of plugin, e.g WooCommerce
	 * @param string $file Path to plugin's main PHP file, e.g woocommerce/woocommerce.php
	 * @param string $plugin Name of plugin that depend on this plugin, e.g $this->plugin_name
	 * @return bool
	 */
	function tcp_is_plugin_available($id, $name, $file, $plugin) {
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (is_plugin_active($file)) {
			return true;
		}
		add_action('admin_notices', function () use ($id, $name, $file, $plugin) {
			global $pagenow;
			$action = isset($_GET['action']) ? tcp_sanitize_text_field($_GET['action'], 0, 14) : '';
			if ($pagenow == 'update.php' && $action == 'install-plugin') {
				return;
			}
			$installed = file_exists(WP_PLUGIN_DIR . '/' . $file);
			$name = esc_html($name);
			$plugin = esc_html($plugin);
			if ($installed) {
				$url = wp_nonce_url(admin_url('plugins.php?action=activate&plugin=' . urlencode($file) . '&plugin_status=all&paged=1&s'), 'activate-plugin_' . $file);
				$msg = sprintf(__('Please <a href="%s">activate</a> %s in order to use with <b>%s</b>.'), esc_url($url), $name, $plugin);
			} else {
				$url = wp_nonce_url(admin_url('update.php?action=install-plugin&plugin=' . $id), 'install-plugin_' . $id);
				$msg = sprintf(__('Please <a href="%s">install</a> and activate %s in order to use with <b>%s</b>.'), esc_url($url), $name, $plugin);
			}
			echo '<div class="notice notice-error"><p>' . wp_kses_post($msg) . '</p></div>';
		});
		return false;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_init_plugin')) {

	/**
	 * @param object $obj Plugin main class object
	 * @param string $file __FILE__
	 */
	function tcp_init_plugin(&$obj, $file) {
		if (!function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data($file, false);
		$obj->plugin_name = $data['Name'];
		$obj->plugin_version = $data['Version'];
		$obj->plugin_url = plugins_url('', $file);
		$obj->plugin_basename = plugin_basename($file);
		$obj->plugin_id = explode('/', $obj->plugin_basename)[0];
		$obj->plugin_dir = plugin_dir_path($file);
		$obj->plugin_file = $file;
		$asset_version = get_class($obj) . '::ASSET_VERSION';
		$obj->asset_version = $obj->plugin_version . (defined($asset_version) ? '_' . constant($asset_version) : '');
		$host = base64_encode(parse_url(get_site_url(), PHP_URL_HOST));
		$obj->is_hub = ($host == 'aHViLmF6d2FuMDgyLm15') || ($host == 'd3AudXBsb2FkaHViLmNvbQ==');
		$obj_id = trim(strtolower(tcp_str_replace_start(__NAMESPACE__ . '\\', '', get_class($obj))), '_');
		if (!isset($GLOBALS[$obj_id])) {
			$GLOBALS[$obj_id] = $obj;
		}
		if (property_exists($obj, 'instance')) {
			$ref = new ReflectionClass($obj);
			$ref->setStaticPropertyValue('instance', $obj);
		}
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_include')) {
	/**
	 * Include PHP files when plugins_loaded hook run
	 *
	 * @param array $files array of full path to file
	 * @param object $obj assign instance of included class to this object, using filename as property name
	 */
	function tcp_include($files, $obj = null) {
		global $tcp_include_files;
		if (!is_array($tcp_include_files)) {
			$tcp_include_files = [];
			add_action('plugins_loaded', function () {
				global $tcp_include_files;
				if (is_array($tcp_include_files)) {
					foreach ($tcp_include_files as $include) {
						foreach ($include['files'] as $f) {
							if (is_object($include['obj'])) {
								$n = strtolower(wp_basename($f, '.php'));
								$n = preg_replace('/[^a-z0-9]+/', '_', $n);
								$n = preg_replace('/_+/', '_', $n);
								$n = trim($n, '_');
								$include['obj']->{$n} = require $f;
							} else {
								require $f;
							}
						}
					}
				}
			});
		}
		$tcp_include_files[] = [
			'files' => $files,
			'obj' => $obj,
		];
	}
}

if (!function_exists(__NAMESPACE__ . '\tcp_str_contains')) {

	/**
	 * @param string $haystack
	 * @param string $needle
	 * @return bool
	 */
	function tcp_str_contains($haystack, $needle) {
		if (function_exists('str_contains')) {
			return str_contains($haystack, $needle);
		} else {
			return strpos($haystack, $needle) !== false;
		}
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_str_starts_with')) {

	/**
	 * @param string $haystack
	 * @param string $needle
	 * @return bool
	 */
	function tcp_str_starts_with($haystack, $needle) {
		if (function_exists('str_starts_with')) {
			return str_starts_with($haystack, $needle);
		} else {
			return substr($haystack, 0, strlen($needle)) === $needle;
		}
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_str_ends_with')) {

	/**
	 * @param string $haystack
	 * @param string $needle
	 * @return boolean
	 */
	function tcp_str_ends_with($haystack, $needle) {
		if (function_exists('str_ends_with')) {
			return str_ends_with($haystack, $needle);
		} else {
			$length = strlen($needle);
			if ($length == 0) {
				return true;
			}
			return substr($haystack, -$length) === $needle;
		}
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_str_replace_start')) {

	/**
	 * @param string $search
	 * @param string $replace
	 * @param string $subject
	 * @return string
	 */
	function tcp_str_replace_start($search, $replace, $subject) {
		if (tcp_str_starts_with($subject, $search)) {
			return substr_replace($subject, $replace, 0, strlen($search));
		}
		return $subject;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_str_replace_end')) {

	/**
	 * @param string $search
	 * @param string $replace
	 * @param string $subject
	 * @return string
	 */
	function tcp_str_replace_end($search, $replace, $subject) {
		if (tcp_str_ends_with($subject, $search)) {
			return substr_replace($subject, $replace, -strlen($search), strlen($search));
		}
		return $subject;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_var_export')) {

	/**
	 * @param mixed $var variable to export
	 * @return string
	 */
	function tcp_var_export($var) {
		if (is_wp_error($var)) {
			$var = 'WP_Error(code: ' . $var->get_error_code() . ', message: ' . $var->get_error_message() . ', data: ' . var_export($var->get_error_data(), true) . ')';
		} else if (is_resource($var)) {
			$var = '(resource: ' . get_resource_type($var) . ')';
		} else if (is_callable($var)) {
			if ($var instanceof Closure) {
				$var = '(closure)';
			} else if (is_array($var) && count($var) == 2) {
				$var = '(function: ' . get_class($var[0]) . '::' . $var[1] . ')';
			} else {
				$var = '(function: ' . var_export($var, true) . ')';
			}
		} else if (is_array($var) || is_object($var)) { // remove new line at `=> ... array(` from default var_export() output
			$cls = null;
			if (is_object($var)) {
				$cls = get_class($var);
				if ($var instanceof JsonSerializable) {
					$var = $var->jsonSerialize();
				} else {
					$var = get_object_vars($var);
				}
			} else {
				$var = maybe_unserialize($var);
			}
			$var = var_export($var, true);
			$replace = ['array (', '(object) array('];
			foreach ($replace as $s) {
				$count = substr_count($var, $s);
				$pos = [];
				for ($i = 0; $i < $count; $i++) {
					$offset = isset($pos[$i - 1]) ? ($pos[$i - 1] + 1) : 0;
					$pos[$i] = strpos($var, $s, $offset);
					$j = strrpos($var, '=>', -(strlen($var) - $pos[$i]));
					if ($j !== false) {
						$var = substr_replace($var, '=> ' . $s, $j, $pos[$i] - $j + strlen($s));
					}
				}
			}
			if ($cls) {
				$var = '[' . $cls . '] ' . $var;
			}
		} else if (!is_object($var) && mb_detect_encoding((string) $var, null, true) === false) { // is binary - https://stackoverflow.com/a/69678887/1784450
			$var = '(binary data)';
		} else {
			$var = var_export($var, true);
		}
		return $var;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_var_dump')) {
	/**
	 * @param mixed $var
	 * @return string
	 */
	function tcp_var_dump($var) {
		$get_k_title = function($k, $v, $is_object = true) {
			$v_type = is_object($v) ? get_class($v) : gettype($v);
			$v_count = 1;
			if (is_object($v)) {
				if ($v instanceof JsonSerializable) {
					$v_count = count($v->jsonSerialize());
				} else {
					$v_count = count(get_object_vars($v));
				}
			} else if (is_array($v) && !is_callable($v)) {
				$v_count = count($v);
			}
			if (is_string($v) && tcp_str_starts_with(trim($v), '{')) {
				$json = json_decode($v, true);
				if (json_last_error() == JSON_ERROR_NONE) {
					$v_type = 'JSON';
					$v_count = count($json);
				}
			}
			$v_count = (is_object($v) || is_array($v) || $v_type == 'JSON') ? '[' . $v_count . ']' : '';
			$k_type = $is_object ? 'property' : gettype($k);
			$k_title = $k_type . ':' . $v_type . $v_count;
			return esc_attr($k_title);
		};
		$r = '';
		if (is_object($var)) {
			$ori_var = $var;
			$cls = get_class($var);
			if ($var instanceof JsonSerializable) {
				$var = $var->jsonSerialize();
			} else {
				$var = get_object_vars($var);
			}
			if (empty($var)) {
				$r .= '<p title="' . esc_attr($cls) . '">';
				if (method_exists($ori_var, '__toString')) {
					$var = '' . $ori_var;
					if (tcp_str_starts_with(trim($var), '{')) {
						$json = json_decode($var, true);
						if (json_last_error() == JSON_ERROR_NONE) {
							return tcp_var_dump($json);
						}
					}
					$r .= esc_html($var);
				} else if ($ori_var instanceof DateTime) {
					$r .= esc_html($ori_var->format('c'));
				} else {
					$r .= '{}';
				}
				$r .= '</p>';
			} else {
				$r .= '<table class="d">';
				foreach ($var as $k => $v) {
					$r .= '<tr>';
					$r .= '<td class="k" title="' . $get_k_title($k, $v) . '">' . esc_html($k) . '</td>';
					$r .= '<td class="v">' . tcp_var_dump($v) . '</td>';
					$r .= '</tr>';
				}
				$r .= '</table>';
			}
		} else if (is_callable($var)) {
			$r .= '<p title="function">';
			if ($var instanceof Closure) {
				$r .= '*closure*';
			} else if (is_array($var) && count($var) == 2) {
				$r .= esc_html(get_class($var[0]) . '::' . $var[1] . '()');
			} else {
				$r .= esc_html($var . '()');
			}
			$r .= '</p>';
		} else if (is_array($var)) {
			if (empty($var)) {
				$r .= '<p title="array">[]</p>';
			} else {
				$r .= '<table class="d">';
				foreach ($var as $k => $v) {
					$r .= '<tr>';
					$r .= '<td class="k" title="'. $get_k_title($k, $v, false) .'">' . esc_html($k) . '</td>';
					$r .= '<td class="v">' . tcp_var_dump($v) . '</td>';
					$r .= '</tr>';
				}
				$r .= '</table>';
			}
		} else if (is_resource($var)) {
			$r .= '<p title="resource">' . esc_html(get_resource_type($var)) . '</p>';
		} else if (mb_detect_encoding((string) $var, null, true) === false) {
			$r .= '<p title="binary">*binary data*</p>';
		} else if (is_string($var) || is_numeric($var)) {
			if (is_string($var) && tcp_str_starts_with(trim($var), '{')) {
				$json = json_decode($var, true);
				if (json_last_error() == JSON_ERROR_NONE) {
					return tcp_var_dump($json);
				}
			}
			$r .= '<p title="' . esc_attr(gettype($var)) . '">' . (empty($var) && !is_numeric($var) ? "''" :  esc_html($var)) . '</p>';
		} else {
			$r .= '<p title="' . esc_attr(gettype($var)) . '">' . esc_html(var_export($var, true)) . '</p>';
		}
		return $r;
	}
}

if (!function_exists(__NAMESPACE__ . '\tcp_sanitize_text_field')) {

	/**
	 * Do sanitize_text_field() on a string & check minimum and maximum string length.
	 * If min length > 0 and string length is less, return empty string.
	 * If string length > max length, return string characters up to max length.
	 *
	 * @param string $str
	 * @param int $min_len
	 * @param int $max_len
	 * @return string
	 */
	function tcp_sanitize_text_field($str, $min_len, $max_len) {
		$str = sanitize_text_field($str);
		if ($min_len < 0) {
			$min_len = 0;
		}
		if ($min_len > 0 && strlen($str) < $min_len) {
			$str = '';
		}
		if (strlen($str) > $max_len) {
			$str = substr($str, 0, $max_len);
		}
		return $str;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_sanitize_textarea_field')) {

	/**
	 * Do sanitize_textarea_field() on a string & check minimum and maximum string length.
	 * If min length > 0 and string length is less, return empty string.
	 * If string length > max length, return string characters up to max length.
	 *
	 * @param string $str
	 * @param int $min_len
	 * @param int $max_len
	 * @return string
	 */
	function tcp_sanitize_textarea_field($str, $min_len, $max_len) {
		$str = sanitize_textarea_field($str);
		if ($min_len < 0) {
			$min_len = 0;
		}
		if ($min_len > 0 && strlen($str) < $min_len) {
			$str = '';
		}
		if (strlen($str) > $max_len) {
			$str = substr($str, 0, $max_len);
		}
		return $str;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_add_route')) {

	/**
	 * @param string $route URL path, must start with slash
	 * @param callable $callback
	 * @param bool $use_theme
	 */
	function tcp_add_route($route, $callback, $use_theme = false) {
		global $tcp_routes_v4;
		if (empty($route)) {
			return;
		}
		if (!tcp_str_starts_with($route, '/')) {
			_deprecated_argument(__FUNCTION__, '20220707', '$route must start with slash');
			$route = '/' . $route;
		}
		$attached = true;
		if (!is_array($tcp_routes_v4)) {
			$tcp_routes_v4 = [];
			$attached = false;
		}
		$tcp_routes_v4[$route] = [
			'callback' => $callback,
			'use_theme' => $use_theme,
		];
		if ($attached) {
			return;
		}
		add_filter('query_vars', function ($vars) {
			global $tcp_routes_v4;
			if (is_array($tcp_routes_v4)) {
				$qvars = array_map(function($v) {
					return ltrim($v, '/');
				}, array_keys($tcp_routes_v4));
				$vars = array_merge($vars, $qvars);
			}
			return $vars;
		});
		/**
		 * @param string $route
		 * @param array $opts
		 * @param array $args
		 */
		$output = function($route, $opts, $args) {
			do_action('tcp_route', $route);
			do_action('tcp_route' . $route);
			add_action('wp_head', function () use ($route) {
				do_action('tcp_route_head' . $route);
			}, 100);
			add_action('wp_footer', function () use ($route) {
				do_action('tcp_route_footer' . $route);
			}, 100);
			if ($opts['use_theme']) {
				echo '<!doctype html><html><head>';
				wp_head();
				echo '</head><body>';
			}
			call_user_func_array($opts['callback'], $args);
			if ($opts['use_theme']) {
				echo '</body>';
				wp_footer();
				echo '</html>';
			}
			exit;
		};
		add_action('parse_request', function ($wp) use ($output) {
			global $tcp_routes_v4;
			if (!is_array($tcp_routes_v4)) {
				return;
			}
			foreach ($tcp_routes_v4 as $route => $opts) {
				$qvar = ltrim($route, '/');
				if (!empty($qvar) && array_key_exists($qvar, $wp->query_vars)) {
					$output($route, $opts, [$wp->query_vars[$qvar]]);
				}
			}
		});
		add_action('template_redirect', function () use ($output) {
			global $tcp_routes_v4, $wp_query;
			if (!is_array($tcp_routes_v4)) {
				return;
			}
			$opts = null;
			$route = '/';
			if (is_home() && isset($tcp_routes_v4[$route])) {
				$opts = $tcp_routes_v4[$route];
			} else if (is_404()) {
				$pagename = get_query_var('pagename');
				$route = '/' . $pagename;
				if (isset($tcp_routes_v4[$route])) {
					$wp_query->init();
					status_header(200);
					$opts = $tcp_routes_v4[$route];
				}
			}
			if ($opts) {
				$v = isset($_GET['arg']) ? sanitize_text_field(urldecode($_GET['arg'])) : '';
				$output($route, $opts, [$v]);
			}
		});
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_fs')) {

	/**
	 * @return WP_Filesystem_Direct
	 */
	function tcp_fs() {
		if (!function_exists('WP_Filesystem')) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ($wp_filesystem && $wp_filesystem->method == 'direct') {
			return $wp_filesystem;
		}
		$fs_method = function () {
			return 'direct';
		};
		add_filter('filesystem_method', $fs_method);
		WP_Filesystem();
		remove_filter('filesystem_method', $fs_method);
		$fs = $GLOBALS['wp_filesystem'];
		return $fs;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_add_dbm')) {

	/**
	 * @param string|array $url_or_qs URL (string) or query string (array)
	 * @return string|array URL or query string with dbm info
	 */
	function tcp_add_dbm($url_or_qs) {
		$dbm = get_transient('tcp_dbm');
		if (is_array($dbm) && isset($dbm['dbm'], $dbm['dbm_key'])) {
			if (is_string($url_or_qs)) {
				return add_query_arg($dbm, $url_or_qs);
			} else if (is_array($url_or_qs)) {
				$url_or_qs['dbm'] = $dbm['dbm'];
				$url_or_qs['dbm_key'] = $dbm['dbm_key'];
				return $url_or_qs;
			}
		}
		return $url_or_qs;
	}

	if (isset($_SERVER['REQUEST_METHOD']) && sanitize_text_field($_SERVER['REQUEST_METHOD']) == 'GET') {
		$dbm = isset($_GET['dbm']) ? tcp_sanitize_text_field($_GET['dbm'], 0, 9) : '';
		if ($dbm) {
			if ($dbm == '1') {
				delete_transient('tcp_dbm');
			} else {
				if ($dbm == '1-1-0-0-1') {
					$dbm = '1-1';
				}
				$dbm_key = isset($_GET['dbm_key']) ? tcp_sanitize_text_field($_GET['dbm_key'], 0, 12) : '';
				$value = [
					'dbm' => $dbm,
					'dbm_key' => $dbm_key,
				];
				set_transient('tcp_dbm', $value, DAY_IN_SECONDS);
			}
		}
	}
}

if (!function_exists(__NAMESPACE__ . '\tcp_get_caller')) {

	/**
	 * Get where does current function is being called from
	 *
	 * @param string $function pass __FUNCTION__
	 * @return string E.g func_name() - /path/to/page.php:10
	 */
	function tcp_get_caller($function) {
		$backtrace = debug_backtrace();
		$key = array_search($function, array_column($backtrace, 'function'));
		$t0 = $backtrace[$key];
		$fn = (isset($t0['class']) ? $t0['class'] . '::' : '') . $t0['function'] . '()';
		if (in_array($function, ['tcp_trace', 'tcp_timer']) && isset($backtrace[$key + 1])) {
			$t1 = $backtrace[$key + 1];
			$fn = (isset($t1['class']) ? $t1['class'] . '::' : '') . $t1['function'] . '()';
		}
		if (in_array($fn, ['require()', 'require_once()', 'include()', 'include_once()'])) {
			$fn = '';
		} else {
			$fn .= ' - ';
		}
		return $fn . str_replace(ABSPATH, '/', $t0['file']) . ':' . $t0['line'];
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_sanitize_options')) {

	/**
	 * E.g
	 * tcp_sanitize_options($_POST['enabled'], ['yes', 'no'], 'no');
	 * tcp_sanitize_options($_POST['enabled'], ['yes', 'no'], 0); // default is 'yes'
	 *
	 * @param string $input
	 * @param array $options
	 * @param int|string $default
	 * @return string
	 */
	function tcp_sanitize_options($input, $options, $default = '') {
		$opt = current($options);
		if (is_numeric($opt)) {
			if (is_int($opt)) {
				$input = (int) $input;
			} else {
				$input = (float) $input;
			}
		} else {
			$max = max(array_map('strlen', $options));
			$input = tcp_sanitize_text_field($input, 0, $max);
		}
		if (!in_array($input, $options)) {
			return is_int($default) && isset($options[$default]) ? $options[$default] : $default;
		}
		return $input;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_notice')) {

	/**
	 * @param array $notice {
	 *   @type string $status success, error, warning, info
	 *   @type string|array $message
	 * }
	 * @param string $redirect_url
	 */
	function tcp_notice($notice, $redirect_url = null) {
		$notices = get_transient('tcp_notices_v4');
		if (!is_array($notices)) {
			$notices = [];
		}
		if (is_array($notice['message'])) {
			foreach ($notice['message'] as $msg) {
				$notices[] = [
					'status' => $notice['status'],
					'message' => $msg,
				];
			}
		} else {
			$notices[] = $notice;
		}
		set_transient('tcp_notices_v4', $notices, MINUTE_IN_SECONDS);
		if ($redirect_url) {
			wp_redirect($redirect_url);
		}
	}

	global $tcp_notice_attached_v4;
	if (!$tcp_notice_attached_v4) {
		add_action('admin_notices', function () {
			/**
			 * tcp_notices = [
			 *   [
			 *     status => success,
			 *     message => ''
			 *   ],
			 *   [
			 *     status => error,
			 *     message => '',
			 *   ]
			 * ]
			 */
			$transient = get_transient('tcp_notices_v4');
			if (is_array($transient)) {
				$notices = [];
				foreach ($transient as $t) {
					if (isset($t['status'], $t['message'])) {
						if (!in_array($t['status'], ['success', 'error', 'warning', 'info'])) {
							continue;
						}
						if (!isset($notices[$t['status']])) {
							$notices[$t['status']] = [];
						}
						$notices[$t['status']][] = $t['message'];
					}
				}
				delete_transient('tcp_notices_v4');
				foreach ($notices as $type => $messages) { ?>
					<div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
						<?php if (count($messages) == 1) { ?>
							<p><?php echo wp_kses_post($messages[0]); ?></p>
						<?php } else { ?>
							<ul style="list-style: disc; padding: 0 0 0 1.2em">
							<?php foreach ($messages as $msg) { ?>
								<li><?php echo wp_kses_post($msg); ?></li>
							<?php } ?>
							</ul>
						<?php } ?>
					</div><?php
				}
			}
		});
		$tcp_notice_attached_v4 = true;
	}
}

if (!function_exists(__NAMESPACE__ . '\tcp_add_menu')) {

	/**
	 * @param string $plugin_id e.g tcp-wcjsonsync
	 * @param string $page_title e.g TCP Products Sync (browser title)
	 * @param string $menu_title e.g Products Sync
	 * @param string $menu_slug e.g wcjsonsync_admin (or $this->plugin_name)
	 * @param callable $function
	 */
	function tcp_add_menu($plugin_id, $page_title, $menu_title, $menu_slug, $function = null) {
		add_action('admin_menu', function () use ($plugin_id, $page_title, $menu_title, $menu_slug, $function) {
			$callback = $function ?: function () use ($plugin_id) {
				if (!function_exists('get_plugin_data')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$f = WP_PLUGIN_DIR . '/' . $plugin_id . '/' . $plugin_id . '.php';
				$plugin = get_plugin_data($f);
				?>
				<div class="wrap">
					<h2><?php echo esc_html($plugin['Name']); ?></h2>
					<p><?php printf(__('Version %s'), esc_html($plugin['Version'])); ?></p>
					<p><?php echo wp_kses_post($plugin['Description']); ?></p>
				</div><?php
			};
			add_submenu_page(
				'thecartpress',
				$page_title,
				$menu_title,
				'manage_options',
				$menu_slug,
				$callback
			);
		}, 20);
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_cache_open')) {

	/**
	 * @internal
	 */
	function tcp_cache_open() {
		$cache_dir = null;
		$fs = null;
		if (!wp_using_ext_object_cache()) {
			$fs = tcp_fs();
			$cache_dir = $fs->wp_content_dir() . 'tcpcache/';
			if ($fs->exists($cache_dir)) {
				$r = $fs->is_writable($cache_dir);
			} else {
				$r = $fs->mkdir($cache_dir);
				if ($r) {
					$fs->put_contents($cache_dir . 'index.php', '');
				}
			}
			if (!$r) {
				$cache_dir = null;
			}
		}
		return [$cache_dir, $fs];
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_cache_get')) {

	/**
	 * @param callable|string $callback_or_key Callable - function to run & return data when cache is unavailable. String - cache key
	 * @param array $fn_args Callback arguments, default to empty array
	 * @param array $extra {
	 *     @type int $expire Cache expiry in seconds, default to 1 day
	 *     @type string $key Cache key, required if using closure as callback, optional otherwise, default to hashed callback name & arguments
	 *     @type string $group Cache group
	 * }
	 * @param array $out {
	 *     @type string $key Cache key, useful if want to get the generated key
	 * }
	 * @return mixed
	 */
	function tcp_cache_get($callback_or_key, $fn_args = [], $extra = [], &$out = []) {
		$key = null;
		if (is_callable($callback_or_key)) {
			$func_name = null;
			if ($callback_or_key instanceof Closure) {
				if (isset($extra['key']) && !empty($extra['key'])) {
					$key = $extra['key'];
				} else {
					throw new Exception('Must pass $extra[key] when using closure as callback');
				}
			} else if (is_array($callback_or_key) && count($callback_or_key) == 2) {
				$func_name = get_class($callback_or_key[0]) . '::' . $callback_or_key[1];
			} else if (is_string($callback_or_key)) {
				$func_name = $callback_or_key;
			}
			if ($func_name) {
				$key = md5(serialize($func_name) . serialize($fn_args));
			}
			if (isset($extra['key']) && !empty($extra['key'])) {
				$key = $extra['key'];
			}
		} else if (is_string($callback_or_key) && !empty($callback_or_key)) {
			$key = $callback_or_key;
		} else {
			throw new Exception('$callback must be a callable or non-empty string');
		}
		if (!$key) {
			return;
		}
		$out['key'] = $key;
		$group = isset($extra['group']) && !empty($extra['group']) ? $extra['group'] : 'tcpcache';
		if (!wp_using_ext_object_cache()) {
			$key = $group . '_' . $key;
		}
		list($cache_dir, $fs) = tcp_cache_open();
		$dbm = null;
		$tcp_dbm = get_transient('tcp_dbm');
		if (is_array($tcp_dbm) && isset($tcp_dbm['dbm'])) {
			$dbm = $tcp_dbm['dbm'];
		}
		if (isset($_GET['dbm'])) {
			$dbm = tcp_sanitize_text_field($_GET['dbm'], 0, 9);
		}
		$var = false;
		if ($dbm != '1-1-0-1') { // disable cache
			if (wp_using_ext_object_cache()) {
				$var = wp_cache_get($key, $group);
			} else {
				if ($cache_dir) {
					$f = $cache_dir . $key . '.php';
					if ($fs->exists($f)) {
						$content = require $f;
						if (is_array($content) && isset($content['data'], $content['expire']) && $content['expire'] > time()) {
							$var = unserialize($content['data']);
						} else {
							if (function_exists('opcache_invalidate')) {
								opcache_invalidate($f, true);
							}
							$fs->delete($f);
						}
					}
				} else {
					$var = get_transient($key);
				}
			}
		}
		if ($dbm == '1-1-0-0-1') { // clear cache
			if (wp_using_ext_object_cache()) {
				wp_cache_delete($key, $group);
			} else {
				if ($cache_dir) {
					$f = $cache_dir . $key . '.php';
					if (function_exists('opcache_invalidate')) {
						opcache_invalidate($f, true);
					}
					$fs->delete($f);
				} else {
					delete_transient($key);
				}
			}
			$var = false;
		}
		$cached = true;
		if ($var === false) {
			$cached = false;
			$expire = (int) isset($extra['expire']) ? $extra['expire'] : 0;
			if (empty($expire)) {
				$expire = DAY_IN_SECONDS;
			}
			$var = call_user_func_array($callback_or_key, $fn_args);
			if ($var === null || $var === false) {
				return;
			}
			$group_key = 'tcpcachegroup_' . $group;
			if (wp_using_ext_object_cache()) {
				wp_cache_set($key, $var, $group, $expire);
				$cache_keys = wp_cache_get($group_key);
				if (!is_array($cache_keys)) {
					$cache_keys = [];
				}
				$cache_keys[] = $key;
				$cache_keys = array_unique($cache_keys);
				wp_cache_set($group_key, $cache_keys);
			} else {
				if ($cache_dir) {
					$content = [
						'data' => serialize($var),
						'expire' => time() + $expire,
					];
					$f = $cache_dir . $key . '.php';
					$fs->put_contents($f, "<?php defined('ABSPATH') or exit; return " . var_export($content, true) . ';');
					if (function_exists('opcache_compile_file')) {
						touch($f, time() - 60, time() - 60);
						@opcache_compile_file($f);
					}
					$cache_keys = [];
					$f = $cache_dir . $group_key . '.php';
					if ($fs->exists($f)) {
						$content = require $f;
						if (is_array($content) && isset($content['keys']) && is_array($content['keys'])) {
							$cache_keys = $content['keys'];
						}
					}
				} else {
					set_transient($key, $var, $expire);
					$cache_keys = get_transient($group_key);
				}
				if (!is_array($cache_keys)) {
					$cache_keys = [];
				}
				$cache_keys[] = $key;
				$cache_keys = array_unique($cache_keys);
				if ($cache_dir) {
					$content = [
						'keys' => $cache_keys,
					];
					$f = $cache_dir . $group_key . '.php';
					$fs->put_contents($f, "<?php defined('ABSPATH') or exit; return " . var_export($content, true) . ';');
					if (function_exists('opcache_compile_file')) {
						touch($f, time() - 60, time() - 60);
						@opcache_compile_file($f);
					}
				} else {
					set_transient($group_key, $cache_keys);
				}
			}
		}
		if (function_exists(__NAMESPACE__ . '\tcp_trace')) {
			tcp_trace([
				'callback_or_key' => $callback_or_key,
				'args' => $fn_args,
				'extra' => $extra,
				'wp_using_ext_object_cache' => wp_using_ext_object_cache(),
				'cache_dir' => $cache_dir,
				'key' => $key,
				'cached' => $cached,
				'opcache_is_script_cached' => $cache_dir && function_exists('opcache_is_script_cached') ? opcache_is_script_cached($cache_dir . $key . '.php') : null,
				'var' => $var,
			], 'cache');
		}
		return $var;
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_cache_delete')) {

	/**
	 * @param string|array $keys_or_groups
	 * @param string $group
	 */
	function tcp_cache_delete($keys_or_groups, $group = 'tcpcache') {
		if (is_string($keys_or_groups)) {
			$keys_or_groups = [$keys_or_groups];
		}
		$keys_or_groups = array_unique(array_filter($keys_or_groups));
		list($cache_dir, $fs) = tcp_cache_open();
		foreach ($keys_or_groups as $v) {
			$group_key = 'tcpcachegroup_' . $v;
			$cache_keys = false;
			if (wp_using_ext_object_cache()) {
				$cache_keys = wp_cache_get($group_key);
			} else {
				if ($cache_dir) {
					$f = $cache_dir . $group_key . '.php';
					if ($fs->exists($f)) {
						$content = require $f;
						if (is_array($content) && isset($content['keys']) && is_array($content['keys'])) {
							$cache_keys = $content['keys'];
						} else {
							if (function_exists('opcache_invalidate')) {
								opcache_invalidate($f, true);
							}
							$fs->delete($f);
						}
					}
				} else {
					$cache_keys = get_transient($group_key);
				}
			}
			if ($cache_keys === false) {
				if (wp_using_ext_object_cache()) {
					wp_cache_delete($v, $group);
				} else {
					$key = $group . '_' . $v;
					if ($cache_dir) {
						$f = $cache_dir . $key . '.php';
						if (function_exists('opcache_invalidate')) {
							opcache_invalidate($f, true);
						}
						$fs->delete($f);
					} else {
						delete_transient($key);
					}
				}
			} else if (is_array($cache_keys)) {
				foreach ($cache_keys as $key) {
					if (is_string($key)) {
						if (wp_using_ext_object_cache()) {
							wp_cache_delete($key, $v);
						} else {
							if ($cache_dir) {
								$f = $cache_dir . $key . '.php';
								if (function_exists('opcache_invalidate')) {
									opcache_invalidate($f, true);
								}
								$fs->delete($f);
							} else {
								delete_transient($key);
							}
						}
					}
				}
				if (wp_using_ext_object_cache()) {
					wp_cache_delete($group_key);
				} else {
					if ($cache_dir) {
						$f = $cache_dir . $group_key . '.php';
						if (function_exists('opcache_invalidate')) {
							opcache_invalidate($f, true);
						}
						$fs->delete($f);
					} else {
						delete_transient($group_key);
					}
				}
			}
		}
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_register_updater')) {

	/**
	 * @param string $plugin_id
	 * @param string $info_url
	 */
	function tcp_register_updater($plugin_id, $info_url) {
		if (is_admin() && class_exists(__NAMESPACE__ . '\TCP_updater')) {
			new TCP_updater($plugin_id, $info_url);
		}
	}

}

if (!function_exists(__NAMESPACE__ . '\tcp_log')) {

	/**
	 * E.g:
	 *   tcp_log('message');
	 *   tcp_log('message, var1={1}, var2={2}', $var1, $var2);
	 *   tcp_log('message, var1=', $var1, 'var2=', $var2);
	 */
	function tcp_log() {
		global $tcp_console_log_enabled;
		$n = func_num_args();
		if ($n == 0) {
			return;
		}
		$msg = func_get_arg(0);
		if ($n > 1) {
			for ($i = 1; $i < $n; $i++) {
				$var = func_get_arg($i);
				if (is_string($var)) {
					if ($i > 1) {
						if (tcp_str_ends_with($var, '=')) {
							$msg .= ',';
						}
						$msg .= ' ';
					}
					$msg .= $var;
				} else {
					if ($tcp_console_log_enabled && (is_array($var) || is_object($var))) {
						$msg .= tcp_var_dump($var);
					} else {
						$msg .= tcp_var_export($var);
					}
				}
			}
		}
		if ($tcp_console_log_enabled) {
			$logs = get_option('tcp_console_log');
			if (!is_array($logs)) {
				$logs = [];
			}
			$logs[] = (new TCP_DateTime())->format(TCP_DateTime::MYSQL_FORMAT) . ' - ' . $msg;
			$logs = array_slice($logs, -TCP_MAX_CONSOLE_LOG, TCP_MAX_CONSOLE_LOG);
			update_option('tcp_console_log', $logs, false);
		} else {
			error_log($msg);
		}
	}

}

if (!class_exists(__NAMESPACE__ . '\TCP_DateTime')) {

	class TCP_DateTime extends DateTime {

		const MYSQL_FORMAT = 'Y-m-d H:i:s';

		/**
		 * DateTime instance that's in WordPress timezone (General > Settings > Timezone)
		 *
		 * E.g:
		 *   new TCP_DateTime(1634298308); // unix timestamp
		 *   new TCP_DateTime(new DateTime()); // existing DateTime instance
		 *   new TCP_DateTime($dt, false); // existing DateTime already in intended timezone
		 *
		 * @param int|DateTime $ts_or_dt unix timestamp or existing DateTime instance
		 * @param bool $autoset_timezone
		 */
		public function __construct($ts_or_dt = 0, $autoset_timezone = true) {
			parent::__construct();
			if ($ts_or_dt instanceof DateTime) {
				$this->setTimestamp($ts_or_dt->getTimestamp());
			} else if (is_int($ts_or_dt) && !empty($ts_or_dt)) {
				$this->setTimestamp($ts_or_dt);
			}
			if ($autoset_timezone) {
				$this->setTimezone(wp_timezone());
			}
		}

		public function __toString() {
			return $this->format();
		}

		/**
		 * E.g:
		 *   $dt->format();
		 *   $dt->format(true);
		 *   $dt->format('Y-m-d');
		 *   $dt->format(TCP_DateTime::MYSQL_FORMAT); // mysql datetime field
		 *
		 * @param bool|string $fmt_or_date_only
		 * @return string
		 */
		public function format($fmt_or_date_only = false) {
			if (is_bool($fmt_or_date_only)) {
				$date_fmt = get_option('date_format');
				if ($fmt_or_date_only) {
					return $this->format($date_fmt);
				} else {
					$time_fmt = get_option('time_format');
					return $this->format($date_fmt . ', ' . $time_fmt);
				}
			}
			return parent::format($fmt_or_date_only);
		}

		/**
		 * @return TCP_DateTime in GMT
		 */
		public function gmt() {
			$dt = $this;
			$dt->setTimezone(new DateTimeZone('GMT'));
			return $dt;
		}

		/**
		 * E.g
		 *   TCP_DateTime::createFromFormat('Y-m-d', '2021-10-15'); // formatted datetime string
		 *   TCP_DateTime::createFromFormat(TCP_DateTime::MYSQL_FORMAT, '2021-10-15 14:20:50'); // mysql's `datetime` formatted datetime string
		 *
		 * @param string $format
		 * @param string $datetime
		 * @return DateTime|false
		 */
		public static function createFromFormat($format, $datetime, $timezone = null) {
			$timezone = wp_timezone();
			return parent::createFromFormat($format, $datetime, $timezone);
		}

	}

}

if (!class_exists(__NAMESPACE__ . '\TCP_menu')) {

	/**
	 * 20220617 - remove add_submenu(), use tcp_add_menu()
	 * 20220323 - add add_submenu()
	 * 20220121 - optimize class, new transient ID & data struct
	 */
	class TCP_menu {

		const PLUGIN_LIST_URL = 'https://app.thecartpress.com/notice/?view=tcp_plugin_list';
		const CONTACT_URL = 'https://www.thecartpress.com/contact/?utm_source=contact&utm_medium=menu&utm_campaign=wporg';
		const WEBSITE_URL = 'https://www.thecartpress.com/?utm_source=visit&utm_medium=menu&utm_campaign=wporg';

		function __construct() {
			add_action('admin_menu', [$this, 'admin_menu'], 1);
			add_action('plugins_loaded', [$this, 'plugins_loaded']);
		}

		function plugins_loaded() {
			if (class_exists('TCPMenu') || class_exists('TCP_Menu') || class_exists('TCPP_Menu')) {
				remove_action('admin_menu', [$this, 'admin_menu'], 1);
				return;
			}
		}

		function admin_menu() {
			add_menu_page(
				'TheCartPress', // string $page_title
				'TheCartPress', // string $menu_title
				'manage_options', // string $capability
				'thecartpress', // string $menu_slug
				null,
				'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAyNC4wLjIsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiDQoJIHZpZXdCb3g9IjAgMCAxMDAwIDEwMDAiIHdpZHRoPSIyMC41IiBoZWlnaHQ9IjIwLjQ3OSIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgMTAwMCAxMDAwOyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSI+DQo8c3R5bGUgdHlwZT0idGV4dC9jc3MiPg0KCS5zdDB7ZmlsbDojRkZGRkZGO30NCjwvc3R5bGU+DQo8Zz4NCgk8cGF0aCBjbGFzcz0ic3QwIiBkPSJNNzE2LjUsODM4LjZjMCwwLDU2LjEtNTguOCw1Ny41LTExMi4yYzEuNC01My4xLTQuMS0xMzQuOC00LjEtMTM1LjRjNi43LTYuMywxMy41LTEyLjgsMjAuMi0xOS41DQoJCUM5NzUuNywzODYsMTAyOC4zLDU1LjcsOTg2LjUsMTRjLTQzLjEtNDMuMS0zNzIuMiwxMC44LTU1Ny44LDE5Ni4zYy02LjcsNi43LTEzLjIsMTMuNS0xOS41LDIwLjJjLTEuMi0wLjEtODIuNS01LjUtMTM1LjUtNC4xDQoJCWMtNTMuNCwxLjQtMTEyLjIsNTcuNS0xMTIuMiw1Ny41TDAsNTM1LjZsMjU3LjksOS4zYzAuOCw1NS45LDIwLjEsMTA0LjQsNTYuNiwxNDAuOGMzNi40LDM2LjQsODUsNTUuNywxNDAuOSw1Ni41bDkuMywyNTcuOA0KCQlMNzE2LjUsODM4LjZ6IE02MTcuNiwzODRjLTQ0LjItNDQuMi00NC4yLTExNS45LDAtMTYwLjFzMTE1LjktNDQuMiwxNjAuMSwwczQ0LjIsMTE1LjksMCwxNjAuMVM2NjEuOSw0MjguMiw2MTcuNiwzODR6Ii8+DQoJPHBhdGggY2xhc3M9InN0MCIgZD0iTTI1My44LDc0Ni40Yy0zOS45LTM5LjktNjMuMy05MC43LTcwLjgtMTQ1LjVMMjIuMSw3NjEuNWwxMTEuNSwxTDc0LjIsOTI1LjlsMTYzLjUtNTkuNGwxLDExMS40bDE2MC44LTE2MC43DQoJCUMzNDQuNiw4MDkuNiwyOTMuNyw3ODYuMiwyNTMuOCw3NDYuNHoiLz4NCjwvZz4NCjwvc3ZnPg0K',
				26
			);
			add_submenu_page(
				'thecartpress', // string $parent_slug,
				'TCP Plugins', // string $page_title,
				'TCP Plugins', // string $menu_title,
				'manage_options', // string $capability,
				'thecartpress', // string $menu_slug,
				[$this, 'plugins_content'], // callable $function = '',
				0 // int $position = null
			);
		}

		function plugins_content() {
			$tcp_plugins = (array) get_transient('tcp_plugins_v2');
			if (!isset($tcp_plugins['plugins'], $tcp_plugins['promote'])) {
				$tcp_plugins = [
					'plugins' => [],
					'promote' => [],
				];
				$response = wp_remote_get(self::PLUGIN_LIST_URL);
				if (wp_remote_retrieve_response_code($response) == 200) {
					$json = json_decode(wp_remote_retrieve_body($response), true);
					if (is_array($json)) {
						if (isset($json['plugins']) && is_array($json['plugins'])) {
							foreach ($json['plugins'] as $pl) {
								if ($pl['slug'] == 'tcp-fpx-payment') {
									$pl['slug'] = 'tcp-wc-fpx-gateway';
									$pl['download_link'] = str_replace('/fpx_payment_gateway', '/tcp-wc-fpx-gateway/', $pl['download_link']);
								}
								$tcp_plugins['plugins'][] = [
									'slug' => $pl['slug'],
									'name' => $pl['name'],
									'short_description' => $pl['short_description'],
									'version' => $pl['version'],
									'active_installs' => isset($pl['active_installs']) ? $pl['active_installs'] : 0,
									'icon' => $pl['icons']['1x'],
									'download_page' => $pl['download_link'],
								];
							}
						}
						if (isset($json['promote']) && is_array($json['promote'])) {
							foreach ($json['promote'] as $pr) {
								$tcp_plugins['promote'][] = [
									'promote_image' => $pr['promote_image'],
									'promote_link' => $pr['promote_link'],
								];
							}
						}
					}
					set_transient('tcp_plugins_v2', $tcp_plugins, DAY_IN_SECONDS);
				}
			}
			?>
			<style>
				.tcp_plugins_card {
					border: 2px solid #e7e7e7;
					border-radius: 4px;
					width:100%;
					max-width:100%;
					min-width:500px;
					padding: 10px;
					background-color: white;
				}
				.tcp_plugins_card:hover {
					background-color: #fafafa;
					color: black;
					text-decoration: none;
				}
				.tcp_plugins_cards {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(600px, 1fr));
					grid-auto-rows: auto;
					grid-gap: 10px;
				}
				.tcp_plugin_icon{
					width:50px;
					height:50px;
					margin-right:10px;
				}
				.tcp_plugins_card_container {
					display: inline-block;
					width: 100%;
					color: #23282d;
					text-decoration: none;
					outline: none;
					box-shadow: none;
				}
			</style>
			<div class="tcp_plugins_page wrap">
				<h1>TheCartPress</h1>
				<div class="tcp_plugins">
					<h2 class="title">TCP Plugins</h2>
					<?php
					// promote banner
					if (!empty($tcp_plugins['promote'])) {
						foreach ($tcp_plugins['promote'] as $pr) {
							echo '<div>';
							echo '<a href="' . esc_url($pr['promote_link']) . '"><img src="' . esc_url($pr['promote_image']) . '"></a>';
							echo '</div>';
						}
					}

					// wordpress plugins
					if (!empty($tcp_plugins['plugins'])) {
						echo '<div class="tcp_plugins_cards">';
						foreach ($tcp_plugins['plugins'] as $pl) {
							echo '<a class="tcp_plugins_card_container" href="' . esc_url($pl['download_page']) . '">';
							echo '<table class="tcp_plugins_card"><tr>';
							echo '<td class="tcp_plugin_icon">';
							echo '<img class="tcp_plugin_icon" src="' . esc_url($pl['icon']) . '" alt="img.png"/>';
							echo '</td>';
							echo '<td align="left">';
							echo '<strong>' . esc_html($pl['name']) . '</strong>';
							echo '<br>' . esc_html($pl['short_description']);
							echo '</td>';
							echo '</tr></table>';
							echo '</a>';
						}
						echo '</div>';
					}
					?>
				</div>

				<div class="card">
					<h2><?php _e('Contact'); ?></h2>
					<p>
						<?php printf(__('Feel free to contact us via %s'), sprintf('<a href="%s" target="_blank">%s</a>', esc_url(self::CONTACT_URL), __('contact page'))); ?>
						<br/>
						<?php printf(__('Website: %s'), sprintf('<a href="%s" target="_blank">%s</a>', esc_url(self::WEBSITE_URL), esc_url(current(explode('?', self::WEBSITE_URL))))); ?>
					</p>
				</div>
			</div>
			<?php
		}

	}

	new TCP_menu();
}

//------------------------------------------------------------------------------
