<?php
/**
 * Plugin Name: WS40 Weather Signature
 * Description: Creates live weather signature images for forums and email, with four selectable visual styles, compact/card layouts and configurable weather data sources.
 * Version: 0.4.4
 * Author: John Martin
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later

 */
if (!defined('ABSPATH')) { exit; }
require_once plugin_dir_path(__FILE__) . 'includes/class-ws40-signature-themes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ws40-signature-custom.php';

if (!class_exists('WS40_Weather_Signature_044', false)) {
class WS40_Weather_Signature_044 {
    const OPT = 'ws40_weather_signature_options';
    const CACHE = 'ws40_weather_signature_data';
    const LAST_GOOD = 'ws40_weather_signature_last_good';
    const SCHEMA_CACHE = 'ws40_weather_signature_schema';
    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_ws40_weather_signature_test_json', array($this, 'test_json_connection'));
        add_action('admin_post_ws40_weather_signature_refresh', array($this, 'refresh_now'));
        add_action('admin_post_ws40_weather_signature_use_cervignano', array($this, 'use_cervignano_endpoint'));
        add_action('admin_post_ws40_weather_signature_repair_mapping', array($this, 'repair_cervignano_mapping'));
        add_action('init', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'render_signature'));
        add_shortcode('ws40_weather_signature', array($this, 'shortcode'));
        add_shortcode('ws40_weather_signature_live', array($this, 'live_shortcode'));
        add_action('wp_ajax_ws40_weather_signature_live_data', array($this, 'ajax_live_data'));
        add_action('wp_ajax_nopriv_ws40_weather_signature_live_data', array($this, 'ajax_live_data'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_links'));
    }
    public static function defaults() {
        return array(
            'site_name' => get_bloginfo('name'), 'domain' => wp_parse_url(home_url(), PHP_URL_HOST),
            'style' => 'light', 'source' => 'json', 'table' => 'ws40_log', 'time_col' => 'dateTime',
            'temp_col' => 'outTemp', 'humidity_col' => 'outHumidity', 'wind_col' => 'windSpeed',
            'gust_col' => 'windGust', 'direction_col' => 'windDir', 'temp_units' => 'C', 'wind_units' => 'km/h',
            'api_url' => 'https://www.cervignanometeo.org/wp-json/ws40/v1/current?station_id=1', 'api_temp' => 'data.outTemp', 'api_humidity' => 'data.outHumidity',
            'api_wind' => 'data.windSpeed', 'api_gust' => 'data.windGustMax', 'api_direction' => 'data.windDirTxt',
            'bg' => '#102b46', 'text' => '#ffffff', 'accent' => '#65d6ff', 'border' => '#315875',
            'humidity_label_color' => '#ffffff', 'humidity_value_color' => '#ffffff',
            'wind_label_color' => '#ffffff', 'wind_value_color' => '#ffffff',
            'gust_label_color' => '#ffffff', 'gust_value_color' => '#ffffff',
            'transparent_bg' => 0, 'bg_image_url' => '',
            'custom_panel' => '#194869', 'custom_footer' => '#09263e', 'custom_soft' => '#c3deea',
            'custom_panel_opacity' => 84,
            'custom_temp_label' => 'TEMPERATURE', 'custom_humidity_label' => 'HUMIDITY',
            'custom_wind_label' => 'WIND', 'custom_gust_label' => 'MAX WIND / GUST',
            'refresh' => 300, 'show_humidity' => 1, 'show_wind' => 1, 'show_gust' => 1, 'show_direction' => 1, 'show_timestamp' => 0,
        );
    }
    public function options() {
        $stored = get_option(self::OPT, array());
        if (!is_array($stored)) $stored = array();
        // Migrate only known obsolete field paths for the Cervignano WS40 API.
        // Older releases stored several different aliases in wp_options; those
        // persisted values take precedence over the new defaults after upgrade.
        // Do not touch mapping fields of a different/custom JSON endpoint.
        if ($this->is_cervignano_endpoint(isset($stored['api_url']) ? $stored['api_url'] : self::defaults()['api_url'])) {
            $aliases = array(
                'api_temp' => array('current.temperature', 'current.outTemp', 'outTemp', 'current.temp'),
                'api_humidity' => array('current.humidity', 'current.outHumidity', 'outHumidity'),
                'api_wind' => array('current.wind_speed', 'current.windSpeed', 'windSpeed'),
                'api_gust' => array('current.wind_gust', 'current.windGust', 'windGust', 'current.windGustMax', 'windGustMax'),
                'api_direction' => array('current.wind_direction', 'current.windDir', 'windDir', 'current.windDirTxt', 'windDirTxt'),
            );
            $changed = false;
            $canonical = self::cervignano_mapping();
            foreach ($aliases as $key => $legacy_paths) {
                if (isset($stored[$key]) && in_array(trim((string)$stored[$key]), $legacy_paths, true)) {
                    $stored[$key] = $canonical[$key];
                    $changed = true;
                }
            }
            if ($changed) {
                // Save the repair, otherwise the same obsolete paths would
                // return on every request and after the next plugin update.
                update_option(self::OPT, $stored);
                delete_transient(self::CACHE);
                delete_transient(self::LAST_GOOD);
            }
        }
        return wp_parse_args($stored, self::defaults());
    }
    private static function cervignano_mapping() {
        return array(
            'api_temp' => 'data.outTemp',
            'api_humidity' => 'data.outHumidity',
            'api_wind' => 'data.windSpeed',
            'api_gust' => 'data.windGustMax',
            'api_direction' => 'data.windDirTxt',
        );
    }
    private function is_cervignano_endpoint($url) {
        if (!is_string($url) || $url === '') return false;
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        $path = rtrim((string)wp_parse_url($url, PHP_URL_PATH), '/');
        return in_array($host, array('cervignanometeo.org', 'www.cervignanometeo.org'), true)
            && $path === '/wp-json/ws40/v1/current';
    }
    private function normalize_style($style) {
        $style = sanitize_key((string)$style);
        if ($style === 'scenic') return 'minimal';
        if ($style === 'classic') return 'light';
        if (!in_array($style, array_keys(WS40_Signature_Themes::names()), true)) return 'light';
        return $style;
    }
    public function plugin_links($links) { array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=ws40-weather-signature')) . '">Settings</a>'); return $links; }
    public function admin_menu() { add_menu_page('WS40 Weather Signature', 'Weather Signature', 'manage_options', 'ws40-weather-signature', array($this, 'settings_page'), 'dashicons-cloud', 58); }
    public function register_settings() { register_setting('ws40_weather_signature_group', self::OPT, array($this, 'sanitize')); }
    public function sanitize($input) {
        $d = self::defaults(); $out = array();
        foreach (array('site_name','domain','table','time_col','temp_col','humidity_col','wind_col','gust_col','direction_col','api_url','api_temp','api_humidity','api_wind','api_gust','api_direction') as $k) {
            $out[$k] = isset($input[$k]) ? sanitize_text_field($input[$k]) : $d[$k];
        }
        $out['bg_image_url'] = isset($input['bg_image_url']) ? esc_url_raw(trim($input['bg_image_url'])) : $d['bg_image_url'];
        if ($out['bg_image_url'] && (!wp_http_validate_url($out['bg_image_url']) || wp_parse_url($out['bg_image_url'], PHP_URL_SCHEME) !== 'https')) $out['bg_image_url'] = '';
        $out['site_name'] = $out['site_name'] ?: $d['site_name'];
        $out['domain'] = preg_replace('~^https?://~i', '', trim($out['domain']));
        
        $valid_styles = array_merge(array('classic','scenic'), array_keys(WS40_Signature_Themes::names()));
        $raw_style = isset($input['style']) ? sanitize_key($input['style']) : $d['style'];
        if ($raw_style === 'scenic') $raw_style = 'minimal';
        if ($raw_style === 'classic') $raw_style = 'light';
        $out['style'] = in_array($raw_style, $valid_styles, true) ? $raw_style : $d['style'];
        $out['source'] = (isset($input['source']) && in_array($input['source'], array('ws40','json'), true)) ? $input['source'] : $d['source'];
        $out['temp_units'] = (isset($input['temp_units']) && $input['temp_units'] === 'F') ? 'F' : 'C';
        $out['wind_units'] = (isset($input['wind_units']) && in_array($input['wind_units'], array('km/h','mph','m/s','knots'), true)) ? $input['wind_units'] : 'km/h';
        foreach (array('bg','text','accent','border','humidity_label_color','humidity_value_color','wind_label_color','wind_value_color','gust_label_color','gust_value_color','custom_panel','custom_footer','custom_soft') as $k) { $v = isset($input[$k]) ? sanitize_hex_color($input[$k]) : false; $out[$k] = $v ? $v : $d[$k]; }
        $out['refresh'] = isset($input['refresh']) ? max(60, min(3600, absint($input['refresh']))) : 300;
        $out['custom_panel_opacity'] = isset($input['custom_panel_opacity']) ? max(0,min(100,absint($input['custom_panel_opacity']))) : $d['custom_panel_opacity'];
        foreach (array('custom_temp_label','custom_humidity_label','custom_wind_label','custom_gust_label') as $key) {
            $value = isset($input[$key]) && is_scalar($input[$key]) ? sanitize_text_field((string)$input[$key]) : $d[$key];
            $out[$key] = function_exists('mb_substr') ? mb_substr($value,0,24,'UTF-8') : substr($value,0,24);
        }
        foreach (array('show_humidity','show_wind','show_gust','show_direction','transparent_bg','show_timestamp') as $k) $out[$k] = !empty($input[$k]) ? 1 : 0;
        delete_transient(self::CACHE);
        delete_transient(self::SCHEMA_CACHE);
        return $out;
    }
    public function refresh_now() {
        if (!current_user_can('manage_options')) wp_die('You are not allowed to refresh the weather signature.');
        check_admin_referer('ws40_weather_signature_refresh');
        delete_transient(self::CACHE);
        delete_transient(self::SCHEMA_CACHE);
        wp_safe_redirect(add_query_arg(array('page'=>'ws40-weather-signature','ws40_refreshed'=>'1'), admin_url('admin.php')));
        exit;
    }
    public function use_cervignano_endpoint() {
        if (!current_user_can('manage_options')) wp_die('You are not allowed to change this data source.');
        check_admin_referer('ws40_weather_signature_use_cervignano');
        $stored = get_option(self::OPT, array());
        if (!is_array($stored)) $stored = array();
        $stored['source'] = 'json';
        $stored['api_url'] = 'https://www.cervignanometeo.org/wp-json/ws40/v1/current?station_id=1';
        $stored['api_temp'] = 'data.outTemp';
        $stored['api_humidity'] = 'data.outHumidity';
        $stored['api_wind'] = 'data.windSpeed';
        $stored['api_gust'] = 'data.windGustMax';
        $stored['api_direction'] = 'data.windDirTxt';
        update_option(self::OPT, $stored);
        delete_transient(self::CACHE);
        delete_transient(self::LAST_GOOD);
        wp_safe_redirect(add_query_arg(array('page'=>'ws40-weather-signature', 'ws40_configured'=>'1'), admin_url('admin.php')));
        exit;
    }
    public function repair_cervignano_mapping() {
        if (!current_user_can('manage_options')) wp_die('You are not allowed to repair this data source.');
        check_admin_referer('ws40_weather_signature_repair_mapping');
        $stored = get_option(self::OPT, array());
        if (!is_array($stored)) $stored = array();
        $url = isset($stored['api_url']) ? $stored['api_url'] : self::defaults()['api_url'];
        if (!$this->is_cervignano_endpoint($url)) wp_die('This action is only available for the Cervignano Meteo WS40 endpoint.');
        // Keep the URL (including station_id) and every display preference.
        $stored = array_merge($stored, self::cervignano_mapping());
        $stored['source'] = 'json';
        update_option(self::OPT, $stored);
        delete_transient(self::CACHE);
        delete_transient(self::LAST_GOOD);
        wp_safe_redirect(add_query_arg(array('page'=>'ws40-weather-signature','ws40_mappings_fixed'=>'1'), admin_url('admin.php')));
        exit;
    }
    public function test_json_connection() {
        if (!current_user_can('manage_options')) wp_die('You are not allowed to run this test.');
        check_admin_referer('ws40_weather_signature_test_json');
        $o = $this->options();
        $result = array('title'=>'JSON connection test', 'items'=>array());
        $url = trim((string)$o['api_url']);
        if (!$url || !wp_http_validate_url($url) || !in_array(wp_parse_url($url, PHP_URL_SCHEME), array('http','https'), true)) {
            $result['items'][] = array('label'=>'Endpoint URL','status'=>'error','message'=>'Enter a valid http:// or https:// JSON endpoint and save settings first.');
        } else {
            $response = wp_safe_remote_get($url, array('timeout'=>12, 'redirection'=>2, 'limit_response_size'=>524288, 'headers'=>array('Accept'=>'application/json')));
            if (is_wp_error($response)) {
                $result['items'][] = array('label'=>'Connection','status'=>'error','message'=>$response->get_error_message());
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $result['items'][] = array('label'=>'HTTP response','status'=>($code >= 200 && $code < 300) ? 'ok' : 'error','message'=>'HTTP '.$code.'; received '.strlen($body).' bytes.');
                $json = json_decode($body, true);
                if (!is_array($json)) {
                    $result['items'][] = array('label'=>'JSON parsing','status'=>'error','message'=>'Response is not valid JSON. '.(function_exists('json_last_error_msg') ? json_last_error_msg() : '')); 
                } else {
                    $result['items'][] = array('label'=>'JSON parsing','status'=>'ok','message'=>'Valid JSON object/array received.');
                    if (isset($json['ok']) && $json['ok'] === false) {
                        $result['items'][] = array('label'=>'WS40 response','status'=>'error','message'=>'The endpoint returned ok:false. Check the station ID and the provider response.');
                    }
                    $fields = array(
                        'Temperature'=>$o['api_temp'], 'Humidity'=>$o['api_humidity'], 'Wind speed'=>$o['api_wind'],
                        'Maximum gust'=>$o['api_gust'], 'Wind direction'=>$o['api_direction']
                    );
                    foreach ($fields as $label=>$path) {
                        $value = $this->json_path($json, trim((string)$path));
                        $valid = is_scalar($value) && $value !== '' && is_numeric($value) || ($label === 'Wind direction' && is_scalar($value) && $value !== '');
                        $display = is_scalar($value) ? (string)$value : (is_null($value) ? 'field not found or null' : 'non-scalar value');
                        if (!$valid && $this->is_cervignano_endpoint($url)) {
                            $field_keys = array('Temperature'=>'api_temp','Humidity'=>'api_humidity','Wind speed'=>'api_wind','Maximum gust'=>'api_gust','Wind direction'=>'api_direction');
                            $suggested_path = self::cervignano_mapping()[$field_keys[$label]];
                            $suggested_value = $this->json_path($json, $suggested_path);
                            if ($suggested_value !== null && $suggested_value !== '') {
                                $display .= '; WS40 field available at '.$suggested_path.'. Use Repair WS40 JSON mapping below.';
                            }
                        }
                        $result['items'][] = array('label'=>$label.' ('.$path.')','status'=>$valid ? 'ok' : 'error','message'=>$display);
                    }
                    $units = isset($json['meta']['units']) && is_array($json['meta']['units']) ? $json['meta']['units'] : array();
                    $result['items'][] = array('label'=>'Observation timestamp','status'=>(!empty($json['logged_ts']) || !empty($json['logged_at'])) ? 'ok' : 'info','message'=>isset($json['logged_at']) ? (string)$json['logged_at'] : (isset($json['logged_ts']) ? (string)$json['logged_ts'] : 'Not provided'));
                    $result['items'][] = array('label'=>'Reported units','status'=>'info','message'=>'Temperature: '.(isset($units['temp']) ? (string)$units['temp'] : 'not supplied').'; wind: '.(isset($units['wind']) ? (string)$units['wind'] : 'not supplied').'; pressure: '.(isset($units['press']) ? (string)$units['press'] : 'not supplied').'.');
                }
            }
        }
        set_transient('ws40_weather_signature_json_test_'.get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        delete_transient(self::CACHE);
        delete_transient(self::SCHEMA_CACHE);
        wp_safe_redirect(add_query_arg(array('page'=>'ws40-weather-signature','ws40_json_test'=>'1'), admin_url('admin.php')));
        exit;
    }
    public function query_vars() { add_filter('query_vars', function($vars) { $vars[] = 'ws40_weather_signature'; return $vars; }); }
    private function number_or_null($v) { return is_numeric($v) ? (float)$v : null; }
    private function data_from_ws40($o) {
        global $wpdb;
        $table = trim($o['table']);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return array();
        if (strpos($table, $wpdb->prefix) !== 0) $table = $wpdb->prefix . $table;
        $schema = get_transient(self::SCHEMA_CACHE);
        if (!is_array($schema) || ($schema['table'] ?? '') !== $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            $columns = $exists ? $wpdb->get_col("SHOW COLUMNS FROM `" . esc_sql($table) . "`", 0) : array();
            $schema = array('table'=>$table, 'columns'=>is_array($columns) ? $columns : array());
            set_transient(self::SCHEMA_CACHE, $schema, 3600);
        }
        $columns = $schema['columns'];
        if (!$columns) return array();
        $map = array('temp'=>$o['temp_col'], 'humidity'=>$o['humidity_col'], 'wind'=>$o['wind_col'], 'gust'=>$o['gust_col'], 'direction'=>$o['direction_col']);
        $select = array();
        $time_column = in_array($o['time_col'], $columns, true) ? $o['time_col'] : '';
        if ($time_column) $select[] = '`' . esc_sql($time_column) . '` AS `_observed_at`';
        foreach ($map as $alias=>$col) if ($col && in_array($col, $columns, true)) $select[] = '`' . esc_sql($col) . '` AS `' . $alias . '`';
        if (count($select) === ($time_column ? 1 : 0)) return array();
        $order = in_array($o['time_col'], $columns, true) ? $o['time_col'] : (in_array('id', $columns, true) ? 'id' : '');
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . esc_sql($table) . '`' . ($order ? ' ORDER BY `' . esc_sql($order) . '` DESC' : '') . ' LIMIT 1';
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : array();
    }
    private function json_path($data, $path) {
        if ($path === '') return null;
        foreach (explode('.', $path) as $part) { if (is_array($data) && array_key_exists($part, $data)) $data = $data[$part]; else return null; }
        return $data;
    }
    private function data_from_json($o) {
        if (!$o['api_url'] || !wp_http_validate_url($o['api_url']) || !in_array(wp_parse_url($o['api_url'], PHP_URL_SCHEME), array('http','https'), true)) return array();
        $response = wp_safe_remote_get($o['api_url'], array('timeout'=>5, 'redirection'=>2, 'limit_response_size'=>524288, 'headers'=>array('Accept'=>'application/json')));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) return array();
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json) || (isset($json['ok']) && $json['ok'] === false)) return array();
        $units = isset($json['meta']['units']) && is_array($json['meta']['units']) ? $json['meta']['units'] : array();
        $temp_unit = strtoupper((string)($units['temp'] ?? ''));
        $wind_unit = strtolower((string)($units['wind'] ?? ''));
        // WS40 endpoints publish units in meta.units (for example, °F/mph or °C/km/h).
        if (strpos($temp_unit, 'F') !== false) $temp_unit = 'F';
        elseif (strpos($temp_unit, 'C') !== false) $temp_unit = 'C';
        else $temp_unit = 'C';
        if (strpos($wind_unit, 'mph') !== false) $wind_unit = 'mph';
        elseif (strpos($wind_unit, 'knot') !== false || strpos($wind_unit, 'kt') !== false) $wind_unit = 'knots';
        elseif (strpos($wind_unit, 'm/s') !== false) $wind_unit = 'm/s';
        else $wind_unit = 'km/h';
        return array(
            'temp'=>$this->json_path($json, $o['api_temp']), 'humidity'=>$this->json_path($json, $o['api_humidity']),
            'wind'=>$this->json_path($json, $o['api_wind']), 'gust'=>$this->json_path($json, $o['api_gust']),
            'direction'=>$this->json_path($json, $o['api_direction']),
            '_observed_at'=>$this->json_path($json, 'data.dateTime') ?: $this->json_path($json, 'data.timestamp') ?: $this->json_path($json, 'meta.timestamp') ?: $this->json_path($json, 'logged_ts') ?: $this->json_path($json, 'meta.logged_at') ?: $this->json_path($json, 'logged_at'),
            '_temp_unit'=>$temp_unit, '_wind_unit'=>$wind_unit,
            '_source_online'=>isset($json['meta']['online']) ? (bool)$json['meta']['online'] : null,
            '_offline_threshold'=>isset($json['meta']['offline_threshold_sec']) ? absint($json['meta']['offline_threshold_sec']) : null
        );
    }
    private function weather_data() {
        $o = $this->options();
        $cached = get_transient(self::CACHE);
        if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) return $cached['data'];
        $raw = ($o['source'] === 'json') ? $this->data_from_json($o) : $this->data_from_ws40($o);
        $data = array();
        foreach (array('temp','humidity','wind','gust') as $k) $data[$k] = isset($raw[$k]) ? $this->number_or_null($raw[$k]) : null;
        $data['direction'] = isset($raw['direction']) && is_scalar($raw['direction']) ? sanitize_text_field((string)$raw['direction']) : '';
        $data['temp_unit'] = ($o['source'] === 'json' && isset($raw['_temp_unit'])) ? $raw['_temp_unit'] : 'C';
        $data['wind_unit'] = ($o['source'] === 'json' && isset($raw['_wind_unit'])) ? $raw['_wind_unit'] : 'km/h';
        $observed = $raw['_observed_at'] ?? null;
        if (is_numeric($observed)) {
            $data['observed_at'] = (int)$observed;
            if ($data['observed_at'] > 9999999999) $data['observed_at'] = (int)floor($data['observed_at']/1000);
        } elseif (is_string($observed) && $observed !== '') {
            // WS40 SQL timestamps are interpreted in the WordPress site timezone.
            try { $data['observed_at'] = (new DateTimeImmutable($observed, wp_timezone()))->getTimestamp(); }
            catch (Exception $e) { $data['observed_at'] = null; }
        } else $data['observed_at'] = null;
        $has_reading = false;
        foreach (array('temp','humidity','wind','gust') as $key) if ($data[$key] !== null) { $has_reading = true; break; }
        if ($has_reading) {
            $data['fetched_at'] = time();
            set_transient(self::LAST_GOOD, $data, 7 * DAY_IN_SECONDS);
        } else {
            $last = get_transient(self::LAST_GOOD);
            if (is_array($last)) $data = $last;
        }
        $data['status'] = 'online';
        if (!$has_reading) $data['status'] = isset($last) && is_array($last) ? 'stale' : 'offline';
        if ($data['status'] === 'online' && isset($raw['_source_online']) && $raw['_source_online'] === false) $data['status'] = 'stale';
        $stale_limit = !empty($raw['_offline_threshold']) ? (int)$raw['_offline_threshold'] : max(600, (int)$o['refresh'] * 3);
        if ($data['status'] === 'online' && !empty($data['observed_at']) && (time() - $data['observed_at']) > $stale_limit) $data['status'] = 'stale';
        if ($data['status'] === 'online' && empty($data['observed_at']) && !empty($data['fetched_at']) && (time() - $data['fetched_at']) > max(600, (int)$o['refresh'] * 3)) $data['status'] = 'stale';
        set_transient(self::CACHE, array('at'=>time(), 'data'=>$data), max(60, (int)$o['refresh']));
        return $data;
    }
    private function data_status_text($d) {
        if ($d['status'] === 'offline') return 'Offline - no readings';
        if ($d['status'] === 'stale') return 'Stale readings';
        return 'Online';
    }
    private function footer_text($d, $o) {
        $state = $this->data_status_text($d);
        if (!empty($o['show_timestamp']) && !empty($d['observed_at'])) return $state . ' | Measured ' . wp_date('d/m H:i', $d['observed_at']);
        if (!empty($o['show_timestamp'])) return $state . ' | Measured time unavailable';
        return $state . ' | Weather signature';
    }
    private function fmt($v, $decimals=0) { return $v === null ? '--' : number_format_i18n($v, $decimals); }
    private function convert_temp($v, $from, $to) { if ($v === null || $from === $to) return $v; return $to === 'F' ? ($v * 9/5) + 32 : ($v - 32) * 5/9; }
    private function convert_wind($v, $from, $to) {
        if ($v === null || $from === $to) return $v;
        $kmh = array('km/h'=>1, 'mph'=>1.609344, 'm/s'=>3.6, 'knots'=>1.852);
        return isset($kmh[$from], $kmh[$to]) ? $v * $kmh[$from] / $kmh[$to] : $v;
    }
    public function render_signature() {
        $layout = get_query_var('ws40_weather_signature');
        if (!$layout && isset($_GET['ws40_weather_signature'])) $layout = sanitize_key(wp_unslash($_GET['ws40_weather_signature']));
        if (!in_array($layout, array('compact','card'), true)) return;
        $options = $this->options();
        $style = $this->normalize_style(isset($options['style']) ? $options['style'] : 'light');
        if (isset($_GET['style'])) {
            $style = $this->normalize_style(wp_unslash($_GET['style']));
        }
        nocache_headers();
        // Never cache the live wrapper or signature image response.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        header('X-Content-Type-Options: nosniff');

        // Browser test page: refresh only the image every five minutes, not the whole page.
        // Keep the normal compact/card URL as a PNG endpoint for Outlook and forums.
        $live_page = false;
        if (isset($_GET['ws40_signature_live'])) {
            $live_page = sanitize_text_field(wp_unslash($_GET['ws40_signature_live'])) === '1';
        }
        if ($live_page) {
            $image_url = add_query_arg('ws40_weather_signature', $layout, home_url('/'));
            if (isset($_GET['style'])) $image_url = add_query_arg('style', $style, $image_url);
            list($width, $height) = WS40_Signature_Themes::dimensions($layout);
            header('Content-Type: text/html; charset=utf-8');
            $safe_url = esc_url($image_url);
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Live WS40 Weather Signature Test</title></head><body style="margin:16px;background:#f4f7fa;font-family:Arial,sans-serif"><p style="margin:0 0 10px;color:#234">Live weather signature test — image refreshes every 5 minutes without refreshing this page.</p><img id="ws40-signature-live-image" src="' . $safe_url . '" width="' . (int) $width . '" height="' . (int) $height . '" alt="Live weather signature" style="display:block;max-width:100%;height:auto"><p id="ws40-signature-live-status" style="font-size:12px;color:#456">Last image refresh: page opened</p><script>(function(){var img=document.getElementById("ws40-signature-live-image");var status=document.getElementById("ws40-signature-live-status");if(!img){return;}var base=img.getAttribute("src");function refreshSignature(){var separator="?";if(base.indexOf("?")>=0){separator="&";}img.src=base+separator+"ws40refresh="+String(new Date().getTime());if(status){status.textContent="Last image refresh: "+new Date().toLocaleTimeString();}}window.setInterval(refreshSignature,300000);})();</script></body></html>';
            exit;
        }

        // Prefer PNG for forum and email compatibility; retain SVG fallback if GD is unavailable.
        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            header('Content-Type: image/png');
            $this->render_modern_theme($style, $layout, true);
        } else {
            header('Content-Type: image/svg+xml; charset=utf-8');
            $this->render_modern_theme($style, $layout, false);
        }
        exit;
    }

    private function render_modern_theme($style, $layout, $png) {
        $o = $this->options();
        $d = $this->weather_data();
        $temp = $this->fmt($this->convert_temp($d['temp'], isset($d['temp_unit']) ? $d['temp_unit'] : 'C', $o['temp_units']));
        $wind = $this->fmt($this->convert_wind($d['wind'], isset($d['wind_unit']) ? $d['wind_unit'] : 'km/h', $o['wind_units']));
        $gust = $this->fmt($this->convert_wind($d['gust'], isset($d['wind_unit']) ? $d['wind_unit'] : 'km/h', $o['wind_units']));
        $footer = $this->footer_text($d, $o);
        if ($png) WS40_Signature_Themes::render_png($style,$layout,$o,$d,$temp,$wind,$gust,$footer);
        else echo WS40_Signature_Themes::render_svg($style,$layout,$o,$d,$temp,$wind,$gust,$footer);
    }
    public function shortcode($atts) {
        $atts = shortcode_atts(array('layout'=>'compact','style'=>'','link'=>'yes','alt'=>'Live weather signature'), $atts, 'ws40_weather_signature');
        $layout = ($atts['layout'] === 'card') ? 'card' : 'compact';
        $chosen_style = $this->normalize_style($this->options()['style']);
        $valid_styles = array_merge(array('classic','scenic'), array_keys(WS40_Signature_Themes::names()));
        if (!empty($atts['style']) && in_array($atts['style'], $valid_styles, true)) $chosen_style = $this->normalize_style($atts['style']);
        $url = add_query_arg('ws40_weather_signature', $layout, home_url('/'));
        if (!empty($atts['style']) && in_array($atts['style'], $valid_styles, true)) $url = add_query_arg('style', $chosen_style, $url);
        list($w, $h) = WS40_Signature_Themes::dimensions($layout);
        $img = '<img src="'.esc_url($url).'" alt="'.esc_attr($atts['alt']).'" width="'.(int)$w.'" height="'.(int)$h.'" style="max-width:100%;height:auto" />';
        return ($atts['link'] === 'yes') ? '<a href="'.esc_url(home_url('/')).'">'.$img.'</a>' : $img;
    }
    /** Return current readings as separate data fields for the live HTML shortcode. */
    public function ajax_live_data() {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $o = $this->options();
        $d = $this->weather_data();
        $temp = $this->convert_temp($d['temp'], isset($d['temp_unit']) ? $d['temp_unit'] : 'C', $o['temp_units']);
        $wind = $this->convert_wind($d['wind'], isset($d['wind_unit']) ? $d['wind_unit'] : 'km/h', $o['wind_units']);
        $gust = $this->convert_wind($d['gust'], isset($d['wind_unit']) ? $d['wind_unit'] : 'km/h', $o['wind_units']);
        wp_send_json_success(array(
            'temp' => $this->fmt($temp) . '°' . $o['temp_units'],
            'humidity' => $this->fmt($d['humidity']) . '%',
            'wind' => (($o['show_direction'] && !empty($d['direction'])) ? $d['direction'] . ' ' : '') . $this->fmt($wind) . ' ' . $o['wind_units'],
            'gust' => $this->fmt($gust) . ' ' . $o['wind_units'],
            'timestamp' => !empty($d['observed_at']) ? wp_date('d/m H:i', $d['observed_at']) : 'Unavailable',
            'status' => $d['status']
        ));
    }

    /** HTML/CSS presentation with separately refreshed weather fields. */
    public function live_shortcode($atts) {
        $o = $this->options();
        $atts = shortcode_atts(array('link' => 'yes', 'refresh' => ''), $atts, 'ws40_weather_signature_live');
        $interval = ($atts['refresh'] !== '') ? absint($atts['refresh']) : absint($o['refresh']);
        $interval = max(60, min(3600, $interval));
        $id = 'ws40-live-' . wp_generate_uuid4();
        $domain = $o['domain'] ? $o['domain'] : wp_parse_url(home_url(), PHP_URL_HOST);
        $initial = $this->weather_data();
        $temp = $this->convert_temp($initial['temp'], isset($initial['temp_unit']) ? $initial['temp_unit'] : 'C', $o['temp_units']);
        $wind = $this->convert_wind($initial['wind'], isset($initial['wind_unit']) ? $initial['wind_unit'] : 'km/h', $o['wind_units']);
        $gust = $this->convert_wind($initial['gust'], isset($initial['wind_unit']) ? $initial['wind_unit'] : 'km/h', $o['wind_units']);
        $direction = (($o['show_direction'] && !empty($initial['direction'])) ? $initial['direction'] . ' ' : '');
        $style = 'display:block;box-sizing:border-box;width:640px;max-width:100%;min-height:112px;padding:10px 16px;border:2px solid ' . esc_attr($o['border']) . ';border-radius:16px;background:' . (!empty($o['transparent_bg']) ? 'transparent' : esc_attr($o['bg'])) . ';color:' . esc_attr($o['text']) . ';font-family:Arial,Helvetica,sans-serif;';
        $out = '<div id="' . esc_attr($id) . '" class="ws40-weather-signature-live" data-refresh="' . esc_attr($interval) . '" style="' . $style . '">';
        $out .= '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;border-bottom:1px solid ' . esc_attr($o['border']) . ';padding-bottom:7px;font-weight:bold;"><span>' . esc_html($o['site_name']) . '</span><span style="font-size:12px;color:' . esc_attr($o['accent']) . ';">' . esc_html($domain) . '</span></div>';
        $out .= '<div style="padding-top:9px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.35;">';
        $out .= '<div style="font-size:28px;font-weight:bold;color:' . esc_attr($o['accent']) . '" data-field="temp">' . esc_html($this->fmt($temp) . '°' . $o['temp_units']) . '</div>';
        if ($o['show_humidity']) $out .= '<span style="display:inline-block;vertical-align:top;width:95px;margin:0 12px 6px 0;font-family:Arial,Helvetica,sans-serif;"><span style="display:block;font-size:11px;font-weight:normal;letter-spacing:0;">Humidity</span><strong style="display:block;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;white-space:nowrap;" data-field="humidity">' . esc_html($this->fmt($initial['humidity']) . '%') . '</strong></span>';
        if ($o['show_wind']) $out .= '<span style="display:inline-block;vertical-align:top;width:135px;margin:0 12px 6px 0;font-family:Arial,Helvetica,sans-serif;"><span style="display:block;font-size:11px;font-weight:normal;letter-spacing:0;">Wind</span><strong style="display:block;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;white-space:nowrap;" data-field="wind">' . esc_html($direction . $this->fmt($wind) . ' ' . $o['wind_units']) . '</strong></span>';
        if ($o['show_gust']) $out .= '<span style="display:inline-block;vertical-align:top;width:150px;margin:0 0 6px 0;font-family:Arial,Helvetica,sans-serif;"><span style="display:block;font-size:11px;font-weight:normal;letter-spacing:0;">Max wind / gust</span><strong style="display:block;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;white-space:nowrap;" data-field="gust">' . esc_html($this->fmt($gust) . ' ' . $o['wind_units']) . '</strong></span>';
        $out .= '</div><div style="font-size:9px;margin-top:5px;opacity:.85;">Live weather · <span data-field="timestamp">' . esc_html(gmdate('H:i') . ' UTC') . '</span></div></div>';
        if ($atts['link'] === 'yes') {
            $out = '<a href="' . esc_url(home_url('/')) . '" style="text-decoration:none;">' . $out . '</a>';
        }
        $ajax = wp_json_encode(admin_url('admin-ajax.php'));
        $out .= '<script>(function(){var box=document.getElementById(' . wp_json_encode($id) . ');if(!box){return;}var endpoint=' . $ajax . ';var seconds=parseInt(box.getAttribute("data-refresh"),10);if(!seconds||seconds<60){seconds=300;}function updateWeather(){var url=endpoint+"?action=ws40_weather_signature_live_data&check="+String(new Date().getTime());fetch(url,{method:"GET",cache:"no-store",credentials:"same-origin"}).then(function(response){if(!response.ok){throw new Error("Weather request failed");}return response.json();}).then(function(result){if(!result||!result.success||!result.data){return;}var fields=result.data;Object.keys(fields).forEach(function(key){var target=box.querySelector("[data-field=\""+key+"\"]");if(target){target.textContent=fields[key];}});}).catch(function(){});}window.setInterval(updateWeather,seconds*1000);})();</script>';
        return $out;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $o = $this->options(); $base = add_query_arg('ws40_weather_signature','compact',home_url('/')); $card = add_query_arg('ws40_weather_signature','card',home_url('/'));
        $bbcode = '[url='.home_url('/').'][img]'.$base.'[/img][/url]';
        $o['style'] = $this->normalize_style($o['style']);
        $dims = WS40_Signature_Themes::dimensions('compact');
        $html = '<a href="'.esc_url(home_url('/')).'"><img src="'.esc_url($base).'" alt="Live weather" width="'.(int)$dims[0].'" height="'.(int)$dims[1].'"></a>';
        ?>
        <div class="wrap"><h1>WS40 Weather Signature</h1>
        <?php if (isset($_GET['ws40_configured'])): ?><div class="notice notice-success is-dismissible"><p>Cervignano Meteo JSON endpoint activated. Use Test JSON Connection to verify access from this WordPress server.</p></div><?php endif; ?>
        <?php if ($o['source'] !== 'json' || !$o['api_url']): ?><div class="notice notice-warning" style="padding:12px 16px"><p><strong>Weather data source:</strong> This installation is configured for <?php echo esc_html($o['source'] === 'ws40' ? 'a local WS40 database table' : 'an empty JSON URL'); ?>. To read Cervignano Meteo data, activate the verified JSON mapping below.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ws40_weather_signature_use_cervignano'); ?><input type="hidden" name="action" value="ws40_weather_signature_use_cervignano"><?php submit_button('Use Cervignano Meteo JSON endpoint', 'secondary', 'submit', false); ?></form></div><?php endif; ?>
        <?php if (isset($_GET['ws40_mappings_fixed'])): ?><div class="notice notice-success is-dismissible"><p>WS40 JSON mapping repaired. The endpoint URL, station ID and appearance settings have been preserved. Run Test JSON Connection to confirm readings.</p></div><?php endif; ?>
        <?php if ($this->is_cervignano_endpoint($o['api_url']) && $o['source'] === 'json'): ?>
        <div class="notice notice-info" style="padding:12px 16px"><p><strong>Cervignano Meteo WS40:</strong> The API provides the readings in <code>data.*</code>. If you have old or incorrect field paths, restore the correct mapping without changing your station ID, colors, or signature URLs.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ws40_weather_signature_repair_mapping'); ?><input type="hidden" name="action" value="ws40_weather_signature_repair_mapping"><?php submit_button('Repair WS40 JSON mapping', 'secondary', 'submit', false); ?></form></div>
        <?php endif; ?>
        <?php if (isset($_GET['ws40_refreshed'])): ?><div class="notice notice-success is-dismissible"><p>Weather cache cleared. The signature preview will fetch the latest available readings.</p></div><?php endif; ?>
        <?php if (isset($_GET['ws40_json_test']) && current_user_can('manage_options')): $test = get_transient('ws40_weather_signature_json_test_'.get_current_user_id()); delete_transient('ws40_weather_signature_json_test_'.get_current_user_id()); if (is_array($test)): ?>
        <div class="notice notice-info" style="padding:12px 16px"><h2 style="margin-top:0">JSON connection test results</h2><table class="widefat striped" style="max-width:900px"><tbody><?php foreach ($test['items'] as $item): $color = ($item['status'] === 'ok') ? '#16803c' : (($item['status'] === 'error') ? '#b32d2e' : '#50575e'); ?><tr><th><?php echo esc_html($item['label']); ?></th><td><strong style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html(strtoupper($item['status'])); ?></strong> — <?php echo esc_html($item['message']); ?></td></tr><?php endforeach; ?></tbody></table><p>Fix any ERROR rows below, save the settings, and run the test again.</p></div>
        <?php endif; endif; ?>
        <p>Create live weather signatures for forums and email. The images refresh from your selected data source; the forum or email service may cache images.</p>
        <h2>Weather Signature gallery</h2>
        <p class="description">Boletus Forest retains its woodland design. The Custom style lets you create your own banner using colors, image and text settings below.</p>
        <p>Choose one of four dynamic designs. Every preview uses the same live WS40 readings; no temperature or weather values are preprinted in the templates.</p>
        <form method="post" action="options.php"><?php settings_fields('ws40_weather_signature_group'); ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,425px),1fr));gap:16px;max-width:1160px;margin:15px 0 25px">
        <?php foreach (WS40_Signature_Themes::names() as $style_id=>$style_label):
            $theme_url=add_query_arg(array('ws40_weather_signature'=>'compact','style'=>$style_id),home_url('/')); ?>
            <label style="display:block;box-sizing:border-box;background:#fff;padding:14px 14px 12px;border:2px solid <?php echo ($o['style'] === $style_id) ? '#2271b1' : '#d9e0e7'; ?>;border-radius:10px;cursor:pointer">
                <span style="display:flex;gap:8px;align-items:center;margin-bottom:10px;font-size:14px;font-weight:600"><input type="radio" name="<?php echo esc_attr(self::OPT.'[style]'); ?>" value="<?php echo esc_attr($style_id); ?>" <?php checked($o['style'],$style_id); ?>><?php echo esc_html($style_label); ?><?php if($o['style']===$style_id): ?><span style="color:#2271b1;font-size:12px">(selected)</span><?php endif; ?></span>
                <img loading="lazy" src="<?php echo esc_url(add_query_arg('preview',time(),$theme_url)); ?>" width="900" style="display:block;width:100%;max-width:100%;height:auto;border-radius:6px" alt="<?php echo esc_attr($style_label); ?> weather preview">
                <span style="display:block;margin-top:9px;overflow-wrap:anywhere;font-size:11px;color:#42566c;user-select:all"><?php echo esc_html($theme_url); ?></span>
                <span style="display:block;margin-top:5px;font-size:11px;color:#637487">Shortcode: <code>[ws40_weather_signature style="<?php echo esc_attr($style_id); ?>"]</code></span>
            </label>
        <?php endforeach; ?></div>
        <p><strong>Card preview with selected default style</strong><br><img style="max-width:100%;height:auto" src="<?php echo esc_url(add_query_arg('preview',time(),$card)); ?>" alt="Weather card preview"></p>
        <p class="description">Select a style and save below to use it as the default for existing image URLs. You can also force an individual design using the style URL parameter or shortcode.</p>
        <h2>Branding and design</h2><p class="description">The site name and displayed domain apply to all four themes; graphic color controls below apply only to Custom.</p><table class="form-table"><tbody>
        <?php $this->text_field('site_name','Displayed site name',$o); $this->text_field('domain','Displayed domain name',$o); ?>
        <?php foreach (array('bg'=>'Background color','text'=>'Text color','accent'=>'Accent color','border'=>'Border color') as $k=>$label): ?><tr><th><label for="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></label></th><td><input type="color" id="<?php echo esc_attr($k); ?>" name="<?php echo esc_attr(self::OPT.'['.$k.']'); ?>" value="<?php echo esc_attr($o[$k]); ?>"></td></tr><?php endforeach; ?>
        <tr><th colspan="2"><h3>Weather field colors</h3><p class="description">Choose separate colors for each field label and its reading. These controls apply to the Custom banner only. The three preset themes retain their coordinated colors.</p></th></tr>
        <?php foreach (array('humidity_label_color'=>'Humidity label','humidity_value_color'=>'Humidity value','wind_label_color'=>'Wind label','wind_value_color'=>'Wind value','gust_label_color'=>'Maximum wind / gust label','gust_value_color'=>'Maximum wind / gust value') as $k=>$label): ?><tr><th><label for="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></label></th><td><input type="color" id="<?php echo esc_attr($k); ?>" name="<?php echo esc_attr(self::OPT.'['.$k.']'); ?>" value="<?php echo esc_attr($o[$k]); ?>"></td></tr><?php endforeach; ?>
        <tr><th>Transparent background</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT.'[transparent_bg]'); ?>" value="1" <?php checked(!empty($o['transparent_bg'])); ?>> Use a transparent background when no image is selected.</label><p class="description">For the Custom design: remove the solid background while retaining the weather panels and logo.</p></td></tr>
        <?php $this->text_field('bg_image_url','Optional background image URL',$o,'Custom design only: paste a full HTTPS image URL from the WordPress Media Library (maximum 1.5 MB / 6 megapixels). Leave empty to use the background color or transparency setting.'); ?>
        <tr><th colspan="2"><h3>Custom banner appearance</h3><p class="description">These settings apply to <strong>Custom</strong> only. The three preset designs remain unchanged. Save settings to refresh the gallery preview.</p></th></tr>
        <?php foreach (array('custom_panel'=>'Measurement panel color','custom_footer'=>'Footer bar color','custom_soft'=>'Secondary text color') as $k=>$label): ?><tr><th><label for="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></label></th><td><input type="color" id="<?php echo esc_attr($k); ?>" name="<?php echo esc_attr(self::OPT.'['.$k.']'); ?>" value="<?php echo esc_attr($o[$k]); ?>"></td></tr><?php endforeach; ?>
        <tr><th><label for="custom_panel_opacity">Measurement panel opacity</label></th><td><input id="custom_panel_opacity" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPT.'[custom_panel_opacity]'); ?>" value="<?php echo esc_attr($o['custom_panel_opacity']); ?>"> % <p class="description">100 = opaque panel, 0 = fully transparent. Use 70–95 when placing text over a background photograph.</p></td></tr>
        <?php foreach (array('custom_temp_label'=>'Temperature caption','custom_humidity_label'=>'Humidity caption','custom_wind_label'=>'Wind caption','custom_gust_label'=>'Gust caption') as $k=>$label): $this->text_field($k,$label,$o,'Custom design only; maximum 24 characters.'); endforeach; ?>
        <tr><th>Signature update time</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT.'[show_timestamp]'); ?>" value="1" <?php checked(!empty($o['show_timestamp'])); ?>> Show a small “Updated” time on the signature image</label><p class="description">Displays the last recorded measurement when the data source provides a measurement timestamp, otherwise indicates that time is unavailable.</p></td></tr>
        <tr><th>Display fields</th><td><?php foreach (array('show_humidity'=>'Humidity','show_wind'=>'Wind speed','show_direction'=>'Wind direction','show_gust'=>'Maximum wind / gust') as $k=>$label): ?><label style="display:inline-block;margin:0 18px 8px 0"><input type="checkbox" name="<?php echo esc_attr(self::OPT.'['.$k.']'); ?>" value="1" <?php checked($o[$k],1); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></td></tr>
        <tr><th>Temperature units</th><td><select name="<?php echo esc_attr(self::OPT.'[temp_units]'); ?>"><option value="C" <?php selected($o['temp_units'],'C'); ?>>Celsius</option><option value="F" <?php selected($o['temp_units'],'F'); ?>>Fahrenheit</option></select></td></tr>
        <tr><th>Wind units</th><td><select name="<?php echo esc_attr(self::OPT.'[wind_units]'); ?>"><?php foreach(array('km/h','mph','m/s','knots') as $unit): ?><option value="<?php echo esc_attr($unit); ?>" <?php selected($o['wind_units'],$unit); ?>><?php echo esc_html($unit); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th>Refresh interval</th><td><input type="number" min="60" max="3600" name="<?php echo esc_attr(self::OPT.'[refresh]'); ?>" value="<?php echo esc_attr($o['refresh']); ?>"> seconds (60–3600; default 300 seconds / 5 minutes)<p class="description">The server caches weather readings for this interval to reduce load. The signature image sends no-cache headers so forums and browsers have a better chance of showing the latest image, though a forum's own image proxy may still cache it.</p></td></tr>
        </tbody></table>
        <h2>Weather data source</h2><table class="form-table"><tbody>
        <tr><th>Source type</th><td><select name="<?php echo esc_attr(self::OPT.'[source]'); ?>"><option value="ws40" <?php selected($o['source'],'ws40'); ?>>WS40 WordPress database table</option><option value="json" <?php selected($o['source'],'json'); ?>>External JSON weather API</option></select><p class="description">Choose the WS40 database option if this site stores readings in a WS40 log table. Choose External JSON only if you want to retrieve readings from a separate JSON endpoint. You only need to configure the fields for the selected source.</p></td></tr>
        <?php $this->text_field('table','WS40 table name (without WordPress prefix)',$o,'Enter the table name used by your WS40 installation.'); $this->text_field('time_col','Timestamp column (optional)',$o,'Leave blank if your table does not have a timestamp column.'); $this->text_field('temp_col','Temperature column (stored in Celsius)',$o); $this->text_field('humidity_col','Humidity column',$o); $this->text_field('wind_col','Wind speed column (stored in km/h)',$o); $this->text_field('gust_col','Maximum wind / gust column (km/h)',$o); $this->text_field('direction_col','Wind direction column',$o); ?>
        </tbody></table>
        <div style="margin:22px 0 18px;padding:16px 18px;border-left:4px solid #2271b1;background:#f0f6fc;max-width:900px"><h3 style="margin:0 0 8px">Optional: External JSON data source</h3><p style="margin:0">The settings above are for readings stored in this site's WS40 database. If you are using that source, you can leave the JSON settings below alone. Configure the next section for the selected JSON source, or use the one-click Cervignano Meteo preset above. Enter the endpoint URL and the JSON field paths that match its response.</p></div>
        <h3>External JSON settings</h3><table class="form-table"><tbody>
        <?php $this->text_field('api_url','JSON endpoint URL',$o,'Full URL that returns a JSON response, beginning with https:// when available.'); $this->text_field('api_temp','JSON path: temperature',$o,'Example field path: data.outTemp'); $this->text_field('api_humidity','JSON path: humidity',$o,'Example field path: data.outHumidity'); $this->text_field('api_wind','JSON path: wind speed',$o,'Example field path: data.windSpeed'); $this->text_field('api_gust','JSON path: maximum gust',$o,'Example field path: data.windGustMax. For maximum sustained wind, a provider may use a different field such as data.windMax.'); $this->text_field('api_direction','JSON path: wind direction',$o,'Example field path: data.windDirTxt'); ?>
        </tbody></table><?php submit_button('Save Weather Signature Settings'); ?></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><?php wp_nonce_field('ws40_weather_signature_test_json'); ?><input type="hidden" name="action" value="ws40_weather_signature_test_json"><p><button type="submit" class="button button-secondary">Test JSON Connection</button> <span class="description">Tests the saved endpoint and each configured JSON field from this WordPress server.</span></p></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px"><?php wp_nonce_field('ws40_weather_signature_refresh'); ?><input type="hidden" name="action" value="ws40_weather_signature_refresh"><p><button type="submit" class="button">Refresh Weather Now</button> <span class="description">Clears the cached readings so the next preview or forum request fetches fresh data.</span></p></form>
        <h2>System diagnostics</h2>
        <?php $diag=$this->weather_data(); ?>
        <p><strong>Data status:</strong> <?php echo esc_html($this->data_status_text($diag)); ?> | <strong>Last observation:</strong> <?php echo esc_html(!empty($diag['observed_at']) ? wp_date('Y-m-d H:i:s', $diag['observed_at']) : 'Unavailable'); ?> | <strong>PHP GD:</strong> <?php echo function_exists('imagepng') ? 'Available' : 'Not available (SVG fallback)'; ?> | <strong>mbstring:</strong> <?php echo function_exists('mb_substr') ? 'Available' : 'Not installed (fallback active)'; ?></p>
        <p class="description">External forum image proxies may cache signatures independently of your server. For reliable automatic updates on your site, use the live shortcode.</p>
        <h2>Use your signature</h2><p><strong>Compact image URL</strong><br><input class="large-text" readonly onclick="this.select()" value="<?php echo esc_attr($base); ?>"></p>
        <p><strong>Card image URL</strong><br><input class="large-text" readonly onclick="this.select()" value="<?php echo esc_attr($card); ?>"></p>
        <p><strong>Forum BBCode (if your forum supports it)</strong><br><textarea class="large-text code" rows="2" readonly onclick="this.select()"><?php echo esc_textarea($bbcode); ?></textarea></p>
        <p><strong>Email HTML</strong><br><textarea class="large-text code" rows="3" readonly onclick="this.select()"><?php echo esc_textarea($html); ?></textarea></p>
        <p><strong>WordPress shortcode</strong><br><code>[ws40_weather_signature layout="compact"]</code> or <code>[ws40_weather_signature layout="card"]</code>. To choose one specific design: <code>[ws40_weather_signature layout="compact" style="minimal"]</code>.</p>
        <h2>About external JSON field paths</h2><p>JSON field paths depend on the structure of the service you connect to. The examples below are illustrative only; use the Test JSON Connection button to verify the paths for your endpoint.</p><table class="widefat striped" style="max-width:800px"><tbody><tr><th>Temperature</th><td><code>data.outTemp</code></td></tr><tr><th>Humidity</th><td><code>data.outHumidity</code></td></tr><tr><th>Wind speed</th><td><code>data.windSpeed</code></td></tr><tr><th>Maximum gust</th><td><code>data.windGustMax</code></td></tr><tr><th>Wind direction</th><td><code>data.windDirTxt</code></td></tr></tbody></table><p>These paths are examples, not universal defaults. Use the field names supplied by your JSON service. Set temperature and wind units above to match the data returned by that service. Missing readings display as a dash.</p></div>
        <?php
    }
    private function text_field($key, $label, $o, $description='') {
        echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td><input class="regular-text" id="'.esc_attr($key).'" name="'.esc_attr(self::OPT.'['.$key.']').'" value="'.esc_attr(isset($o[$key])?$o[$key]:'').'">'.($description ? '<p class="description">'.esc_html($description).'</p>' : '').'</td></tr>';
    }
}
} // End conditional class declaration.

// Compatibility safeguard for sites where a version-numbered archive was installed
// beside the existing plugin instead of upgrading its original directory.
// Only one copy may provide the weather signature endpoints and admin actions.
$ws40_signature_self = plugin_basename(__FILE__);
$ws40_signature_other_copies = array();
$ws40_signature_active = (array) get_option('active_plugins', array());
if (function_exists('get_site_option')) {
    $ws40_signature_network_active = get_site_option('active_sitewide_plugins', array());
    if (is_array($ws40_signature_network_active)) {
        $ws40_signature_active = array_merge($ws40_signature_active, array_keys($ws40_signature_network_active));
    }
}
foreach ($ws40_signature_active as $ws40_signature_candidate) {
    if ($ws40_signature_candidate !== $ws40_signature_self && basename($ws40_signature_candidate) === 'ws40-weather-signature.php') {
        $ws40_signature_other_copies[] = $ws40_signature_candidate;
    }
}
if ($ws40_signature_other_copies || class_exists('WS40_Weather_Signature', false)) {
    if (function_exists('is_admin') && is_admin()) {
        add_action('admin_notices', static function () {
            if (!current_user_can('activate_plugins')) return;
            echo '<div class="notice notice-error"><p><strong>WS40 Weather Signature:</strong> More than one plugin copy was detected. Deactivate and delete the duplicate copy (keep your preferred installation), then reactivate this version. Only one copy should be active.</p></div>';
        });
    }
} else {
    new WS40_Weather_Signature_044();
}
