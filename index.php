<?php
/*
Plugin Name: Sellaporter
Plugin URI: https://github.com/jlengstorf/sellaporter
Description: A time-aware, modular page layout for WordPress.
Version: 0.0.1
Author: Jason Lengstorf
Author URI: https://lengstorf.com
License: ISC
Copyright: Jason Lengstorf
*/

// Prevent anyone from directly loading this file
if (!defined('DB_NAME')) {
    header( 'HTTP/1.0 403 Forbidden' );
    die;
}

// Some constants for the class
define('SP_PHASE_PRESALE', 'presale');
define('SP_PHASE_SALE', 'sale');
define('SP_PHASE_POSTSALE', 'postsale');

class Sellaporter {

  /**
   * Template(s) made available by the plugin.
   * @var array
   */
  protected $templates;

  /**
   * The instance of this class for use with WordPress.
   * @var object
   */
  private static $_instance;

  /**
   * The constructor is private. To get an instance of this class, use:
   *
         $sp = Sellaporter::get_instance();
   */
  private function __construct() {

    // Registers custom templates for the plugin.
    $this->templates = array(
      'sellaporter.php' => 'Sellaporter Time-Aware Page',
    );

    // Add plugin-registered templates to the page attributes meta box.
    add_filter(
      'default_page_template_title',
      array($this, 'register_templates')
    );

    // Add SVG MIME types to WordPress's allowed uploads list.
    add_filter('upload_mimes', array($this, 'wp_allow_svg_upload'));

    // Add `sellaporter` query vars to the whitelist for WP and WP REST API.
    add_filter('query_vars', array($this, 'register_valid_query_vars'));
    add_filter('rest_query_vars', array($this, 'register_valid_query_vars'));

    // Add query string values to the WP REST API arguments.
    add_filter(
      'rest_post_query',
      array($this, 'register_custom_rest_query_args')
    );

    // For saving the page
    add_filter(
      'wp_insert_post_data',
      array($this, 'register_templates')
    );

    // Loads the template for the site.
    add_filter('template_include', array($this, 'include_template'));

    // Registers the custom fields for the template.
    add_action('after_setup_theme', array($this, 'acf_register_fields'));
    add_action('after_setup_theme', array($this, 'acf_register_options_page'));

    // For displaying custom phases in visibility toggles, register each block.
    $visibility_func = array($this, 'acf_set_custom_phase_visibility_toggle');
    add_filter('acf/load_field/name=visible_phases', $visibility_func);

    // Adds a field to the REST API response telling us the current phase.
    add_action('rest_api_init', array($this, 'register_phase_field'));

    // Registers oEmbed wrapping for better responsive output.
    add_filter('embed_oembed_html', array($this, 'register_responsive_oembed_container'), 10, 3);

    // Registers short codes for content parsing
    add_action('after_setup_theme', array($this, 'register_shortcodes'));
  }

  /**
   * This class follows the Singleton pattern to avoid unnecessary duplicates.
   *
   * @return Object   an instance of the class
   */
  public static function get_instance() {
    if (self::$_instance === null) {
      self::$_instance = new Sellaporter();
    }

    // Makes sure the current sale phase is set.
    self::$_instance->current_phase = self::$_instance->get_current_phase();

    return self::$_instance;
  }

  /**
   * For WP REST API requests, we need to set sellaporter query string args.
   * @param  array $args the current WP REST API args
   * @return array       the updated WP REST API args
   */
  public function register_custom_rest_query_args($args) {
    global $wp;

    // Only make changes to the args if `sellaporter` query vars are set.
    if (
      is_array($wp->query_vars)
      && array_key_exists('sellaporter', $wp->query_vars)
      && is_array($wp->query_vars['sellaporter'])
    ) {

      // To keep things clean, we namespace all args under `sellaporter`.
      foreach ($wp->query_vars['sellaporter'] as $key => $arg) {
        $args['sellaporter'][$key] = $arg;
      }
    }

    return $args;
  }

  /**
   * Jump through WP and WP REST API hoops to allow `sellaporter` query vars
   * @param  array $vars query var whitelist
   * @return array       the updated query var whitelist
   */
  public function register_valid_query_vars( $vars ){
    $vars[] = "sellaporter";
    return $vars;
  }

  public function register_templates( $atts ) {
    $cache_key = 'page_templates-'
               . md5(get_stylesheet_directory());

    // Load the existing templates, or create an empty array.
    $templates = wp_get_theme()->get_page_templates();
    if (empty($templates)) {
      $templates = array();
    }

    // Remove the old cache.
    wp_cache_delete($cache_key, 'themes');

    // Merge plugin templates with the theme templates.
    $templates = array_merge($templates, $this->templates);

    // Replace the old cache with the updated version.
    wp_cache_add($cache_key, $templates, 'themes', 1800);

    return $atts;
  }

  public function include_template( $template ) {
    if (!$this->_is_sellaporter_page()) {
      return $template;
    }

    $template_file = plugin_dir_path(__FILE__) . 'templates/' . $post_template;

    if (file_exists($template_file)) {
      return $template_file;
    } else {
      echo 'TODO do we need to handle this?';
    }
  }

  public function wp_allow_svg_upload($mimes) {
    $mimes['svg'] = 'image/svg+xml';
    return $mimes;
  }

  public function register_phase_field() {
    if (!function_exists('register_rest_field')) {
      trigger_error('Sellaporter requires the WP REST API', E_USER_ERROR);
    }

    register_rest_field('page',
      'sellaporter_phase',
      array(
        'get_callback' => array($this, 'get_current_phase'),
      )
    );
  }

  public function acf_register_fields() {

    // Custom fields, as exported by ACF.
    require_once 'includes/advanced-custom-fields.php';
  }

  public function acf_set_custom_phase_visibility_toggle($field) {
    if (have_rows('launch_phases')) {
      while (have_rows('launch_phases')) {
        the_row();

        $phase_name = get_sub_field('phase_name');
        $field['choices'][$phase_name] = $phase_name;
      }
    }

    return $field;
  }

  public function acf_register_options_page() {
    if (function_exists('acf_add_options_page')) {
      $config = array(
        'page_title' => 'Sellaporter Settings',
        'menu_title' => 'Sellaporter',
        'capability' => 'manage_options',
        'parent_slug' => 'options-general.php',
      );
      acf_add_options_page($config);
    } else {
      echo 'ACF acf_add_options_page does not exist.';
    }
  }

  /**
   * This method catches the WP oEmbed process to add a responsive wrapper.
   * @param  string $html the oEmbed markup
   * @return string       the modified oEmbed markup
   */
  public function register_responsive_oembed_container($html) {
    if (!$this->_is_sellaporter_page()) {
      return $html;
    }

    return !!$html ? '<div class="sp-video__container">'.$html.'</div>' : '';
  }

  public function register_shortcodes() {
    add_shortcode('sp', array($this, 'shortcode_phase_conditional'));
    add_shortcode('spButton', array($this, 'shortcode_cta_button'));
    add_shortcode('spNotice', array($this, 'shortcode_notice'));
  }

  public function shortcode_notice($atts, $content) {
    $template = '<small class="sp-text--notice">%s</small>';

    return sprintf($template, $content);
  }

  public function shortcode_cta_button($atts, $content) {

    // TODO Should we validate this?
    $href = !!$atts['href'] ? $atts['href'] : '#no-link-supplied';

    $classes = array('sp-button');
    if ($atts['action'] === 'popover') {
      $classes[] = 'sp-button--popover';
      $href = '#';
    }

    $btn = '<a href="%s" class="%s">%s</a>';

    return sprintf($btn, $href, implode(' ', $classes), $content);
  }

  /**
   * A shortcode to allow conditionally-displayed text.
   * @param  array $atts     the shortcode attributes
   * @param  string $content text to be displayed
   * @return string          markup if phase matches; empty string otherwise
   */
  public function shortcode_phase_conditional($atts, $content) {

    // TODO this is probably a bad idea, so come up with a better way to warn.
    if (!$atts['phase']) {
      $content .= '<strong>No phase set. Was this on purpose?</strong>';
    }

    // By default, we apply WP's markup filter. If `true`, this disables it.
    $is_inline_text = array_key_exists('inline', $atts) ? $atts['inline'] : false;

    // Convert the valid phases to an array.
    $phases_when_text_is_visible = array_map('trim', explode(',', $atts['phase']));
    if (in_array($this->get_current_phase(), $phases_when_text_is_visible)) {

      /*
       * If the current phase is in the array, the text will be displayted. We
       * use `wptexturize()` to clean up quotes and other special characters,
       * and `do_shortcode()` keeps the content sections flexible and
       * interoperable with other plugins/themes.
       */
      $content = wptexturize(do_shortcode($content));

      // Inline text is returned as-is; block-level text also runs `wpautop()`.
      return !!$is_inline_text ? $content : wpautop($content);
    } else {
      return '';
    }
  }

  /**
   * Determines and caches the current sales phase.
   * @return string the current sale phase
   */
  public function get_current_phase() {

    // If this isn't a Sellaporter page or ACF isn't running, bail.
    if (!$this->_is_sellaporter_page() || !function_exists('get_field')) {
      return '';
    }

    /*
     * This allows testing via the REST API. To test a phase, add a query
     * string to the request like so:
     *
     *    ENDPOINT?filter[name]=SLUG_TO_LOAD&sellaporter[phase]=PHASE_TO_TEST
     */
    global $wp;
    if (
      is_array($wp->query_vars)
      && array_key_exists('sellaporter', $wp->query_vars)
      && array_key_exists('phase', $wp->query_vars['sellaporter'])
    ) {
      return $wp->query_vars['sellaporter']['phase'];
    }

    if (!!$this->current_phase) {

      // The phase is already cached, so return it.
      return $this->current_phase;
    } else {

      // Initialize the current phase as presale.
      $this->current_phase = SP_PHASE_PRESALE;
    }

    // Get the time right now.
    $current_timestamp = time();

    // Get the start date as a timestamp.
    $sale_start = strtotime($this->_to_ISO8601(
      get_field('launch_start_date'),
      get_field('launch_start_time')
    ));

    // Get the sale end date as a timestamp.
    $sale_end = strtotime($this->_to_ISO8601(
      get_field('launch_end_date'),
      get_field('launch_end_time')
    ));

    // Check if the sale is in progress or over
    if ($sale_start <= $current_timestamp) {
      if ($sale_end <= $current_timestamp) {

        // If we get here, the sale is over.
        $this->current_phase = SP_PHASE_POSTSALE;
      } else {

        // If we get here, the sale is in progress.
        $this->current_phase = SP_PHASE_SALE;
      }
    }

    // If we get here, the sale hasn't started yet. Check for custom phases.
    if (have_rows('launch_phases')) {
      while (have_rows('launch_phases')) {
        the_row();

        $start = $sale_start - (get_sub_field('phase_start_offset') * 86400);
        $end = $sale_start - (get_sub_field('phase_end_offset') * 86400);

        // If the current time falls within a custom phase, return its name.
        if ($start <= $current_timestamp && $current_timestamp <= $end) {
          $this->current_phase = get_sub_field('phase_name');
        }
      }
    }

    return $this->current_phase;
  }

  /**
   * Helper function to determine whether a given page uses Sellaporter.
   * @return boolean `true` if it's a Sellaporter page; otherwise `false`
   */
  private function _is_sellaporter_page() {
    global $post;

    if (!$post) {
      return false;
    }

    // Load the current WordPress post's template.
    $post_template = get_post_meta($post->ID, '_wp_page_template', true);

    // Return whether or not the post's template matches one of Sellaporter's.
    return isset($this->templates[$post_template]);
  }

  /**
   * A helper function to convert output to ISO 8601-formatted dates.
   * @param  string $date  the date from ACF's DatePicker field
   * @param  string  $time the time from a custom text input
   * @return string        an ISO 8601-formatted date string
   *
   * @link https://en.wikipedia.org/wiki/ISO_8601 ISO 8601 details
   */
  private function _to_ISO8601($date=false, $time='00:00') {

    // Strip non-numeric characters to validate (e.g. 20160114 vs. 2016-01-14).
    $clean_date = preg_replace('/\D+/', '', $date);

    // Pad the time to a six-digit number (e.g. 120000 for 12:00:00).
    $clean_time = sprintf('%04d00', preg_replace('/\D+/', '', $time));

    // Loads the timezone offset (e.g. '+02:00').
    $gmt_offset = date('P');

    // If the date is a falsy value, something is wrong.
    if (intval($clean_date) === 0) {
      trigger_error('Invalid date information supplied.');
      return false;
    }

    // Build and return the ISO 8601 date string.
    return sprintf('%sT%s%s', $clean_date, $clean_time, $gmt_offset);
  }

}

// Initializes the plugin
add_action('plugins_loaded', array('Sellaporter', 'get_instance'));
