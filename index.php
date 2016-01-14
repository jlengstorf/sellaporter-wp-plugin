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
    add_action('after_setup_theme', array($this, 'register_fields'));
    add_action('after_setup_theme', array($this, 'register_options_page'));

    // For displaying custom phases in visibility toggles, register each block.
    $visibility_func = array($this, 'acf_set_custom_phase_visibility_toggle');
    add_filter('acf/load_field/name=visible_phases', $visibility_func);

    // Adds a field to the REST API response telling us the current phase.
    add_action('rest_api_init', array($this, 'register_phase_field'));

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

  public function register_fields() {
    if (function_exists("register_field_group")) {
      register_field_group(array (
        'id' => 'acf_sellaporter',
        'title' => 'Smart Sales Page',
        'fields' => array (
          array (
            'key' => 'field_569215f3fa9ae',
            'label' => 'Sales Page Content',
            'name' => 'sales_page_content',
            'type' => 'tab',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => '',
              'class' => '',
              'id' => '',
            ),
            'placement' => 'left',
            'endpoint' => 0,
          ),
          array (
            'key' => 'field_567e514aaee38',
            'label' => 'Page Layout',
            'name' => 'layout',
            'type' => 'flexible_content',
            'instructions' => 'Choose a section layout from below to build your page content. You can create as many sections as you like, in any order you like. You can also drag and drop each section to change the order.',
            'layouts' => array (

              array(
                'label' => 'Freestyle Copy Block',
                'name' => 'sp-generic',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  // array(
                  //   'key' => 'field_567e51c1ae100',
                  //   'label' => 'Headline',
                  //   'name' => 'headline',
                  //   'type' => 'text',
                  //   'column_width' => '',
                  //   'default_value' => '',
                  //   'placeholder' => '',
                  //   'prepend' => '',
                  //   'append' => '',
                  //   'formatting' => 'none',
                  //   'maxlength' => '',
                  // ),
                  array (
                    'key' => 'field_567e51c1ae101',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'instructions' => 'Put anything you want in here. No rules, boss!',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_5690e7d00202d',
                    'label' => 'Section Alignment',
                    'name' => 'alignment',
                    'type' => 'radio',
                    'column_width' => '',
                    'choices' => array (
                      'center' => 'Center',
                      'left' => 'Left',
                      'right' => 'Right',
                    ),
                    'other_choice' => 0,
                    'save_other_choice' => 0,
                    'default_value' => 'center',
                    'layout' => 'horizontal',
                  ),
                  array (
                    'key' => 'field_5690e8090202e',
                    'label' => 'Background Image',
                    'name' => 'background_image',
                    'type' => 'image',
                    'instructions' => 'This image will show up to the side of the content. ',
                    'conditional_logic' => array (
                      'status' => 1,
                      'rules' => array (
                        array (
                          'field' => 'field_5690e7d00202d',
                          'operator' => '!=',
                          'value' => 'center',
                        ),
                      ),
                      'allorany' => 'all',
                    ),
                    'column_width' => '',
                    'save_format' => 'url',
                    'preview_size' => 'large',
                    'library' => 'all',
                  ),
                  array (
                    'key' => 'field_56973cb7caee6',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'CTA Box',
                'name' => 'sp-cta',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array (
                  array (
                    'key' => 'field_568f8ea9ace2d',
                    'label' => 'Button Text',
                    'name' => 'button_text',
                    'type' => 'text',
                    'required' => 1,
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_568f8ed7ace2e',
                    'label' => 'Button Link',
                    'name' => 'button_link',
                    'type' => 'text',
                    'instructions' => 'What page should the user be taken to when they click this button?',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => 'http://',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_568f8f17ace2f',
                    'label' => 'Choose a background style.',
                    'name' => 'style',
                    'type' => 'radio',
                    'column_width' => '',
                    'choices' => array (
                      'dark' => 'Dark',
                      'light' => 'Light',
                    ),
                    'other_choice' => 0,
                    'save_other_choice' => 0,
                    'default_value' => 'dark',
                    'layout' => 'horizontal',
                  ),
                  array (
                    'key' => 'field_56973cb7caee7',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'FAQ',
                'name' => 'sp-faq',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array(
                    'key' => 'field_567e51c1ae103',
                    'label' => 'Headline',
                    'name' => 'headline',
                    'type' => 'text',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_567e51c1ae104',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_56973cb7caee8',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'Features',
                'name' => 'sp-features',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array(
                    'key' => 'field_567e51c1ae105',
                    'label' => 'Headline',
                    'name' => 'headline',
                    'type' => 'text',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_567e51c1ae106',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_56973cb7caee9',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'Hero',
                'name' => 'sp-hero',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array (
                    'key' => 'field_568e4c5332aec',
                    'label' => 'Choose the type of hero box:',
                    'name' => 'hero_type',
                    'type' => 'radio',
                    'required' => 1,
                    'column_width' => '',
                    'choices' => array (
                      'super' => 'Super Heading',
                      'normal' => 'Regular Heading',
                    ),
                    'other_choice' => 0,
                    'save_other_choice' => 0,
                    'default_value' => 'normal',
                    'layout' => 'horizontal',
                  ),
                  array (
                    'key' => 'field_568e4cb332aed',
                    'label' => 'Superheading',
                    'name' => 'superheading',
                    'type' => 'text',
                    'required' => 1,
                    'conditional_logic' => array (
                      'status' => 1,
                      'rules' => array (
                        array (
                          'field' => 'field_568e4c5332aec',
                          'operator' => '==',
                          'value' => 'super',
                        ),
                      ),
                      'allorany' => 'all',
                    ),
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_568e4cd832aee',
                    'label' => 'Subtitle',
                    'name' => 'subtitle',
                    'type' => 'text',
                    'required' => 1,
                    'conditional_logic' => array (
                      'status' => 1,
                      'rules' => array (
                        array (
                          'field' => 'field_568e4c5332aec',
                          'operator' => '==',
                          'value' => 'super',
                        ),
                      ),
                      'allorany' => 'all',
                    ),
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_568e4cea32aef',
                    'label' => 'Heading',
                    'name' => 'heading',
                    'type' => 'text',
                    'conditional_logic' => array (
                      'status' => 1,
                      'rules' => array (
                        array (
                          'field' => 'field_568e4c5332aec',
                          'operator' => '==',
                          'value' => 'normal',
                        ),
                      ),
                      'allorany' => 'all',
                    ),
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_568e4cf932af0',
                    'label' => 'Text',
                    'name' => 'text',
                    'type' => 'wysiwyg',
                    // 'conditional_logic' => array (
                    //   'status' => 1,
                    //   'rules' => array (
                    //     array (
                    //       'field' => 'field_568e4c5332aec',
                    //       'operator' => '==',
                    //       'value' => 'normal',
                    //     ),
                    //   ),
                    //   'allorany' => 'all',
                    // ),
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'basic',
                    'media_upload' => 'no',
                  ),
                  array (
                    'key' => 'field_568e4d5632af1',
                    'label' => 'Background Image',
                    'name' => 'background_image',
                    'type' => 'image',
                    'column_width' => '',
                    'save_format' => 'url',
                    'preview_size' => 'large',
                    'library' => 'all',
                  ),
                  array (
                    'key' => 'field_568e4dbd32af2',
                    'label' => 'Image Tint',
                    'name' => 'image_tint',
                    'type' => 'radio',
                    'column_width' => '',
                    'choices' => array (
                      'none' => 'None',
                      'medium' => 'Medium',
                      'dark' => 'Dark',
                    ),
                    'other_choice' => 0,
                    'save_other_choice' => 0,
                    'default_value' => '',
                    'layout' => 'horizontal',
                  ),
                  array (
                    'key' => 'field_568e4df332af3',
                    'label' => 'Text Options',
                    'name' => 'text_options',
                    'type' => 'checkbox',
                    'instructions' => 'Check boxes to apply effects to the text in the hero box.',
                    'column_width' => '',
                    'choices' => array (
                      'shadow' => 'Text Shadow',
                      'inverted' => 'Invert Colors',
                    ),
                    'default_value' => '',
                    'layout' => 'horizontal',
                  ),
                  array (
                    'key' => 'field_5690c204489bb',
                    'label' => 'Show CTA Button?',
                    'name' => 'show_cta_button',
                    'type' => 'true_false',
                    'column_width' => '',
                    'message' => 'If true, the hero box will have a button to click.',
                    'default_value' => 0,
                  ),
                  array (
                    'key' => 'field_5690c267ea193',
                    'label' => 'Button Text',
                    'name' => 'button_text',
                    'type' => 'text',
                    'conditional_logic' => array (
                      'status' => 1,
                      'rules' => array (
                        array (
                          'field' => 'field_5690c204489bb',
                          'operator' => '==',
                          'value' => '1',
                        ),
                      ),
                      'allorany' => 'all',
                    ),
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_5690c277ea194',
                    'label' => 'Button Link',
                    'name' => 'button_link',
                    'type' => 'text',
                    'conditional_logic' => array (
                      'status' => 1,
                      'rules' => array (
                        array (
                          'field' => 'field_5690c204489bb',
                          'operator' => '==',
                          'value' => '1',
                        ),
                      ),
                      'allorany' => 'all',
                    ),
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => 'http://',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_56973cb7caee5',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'How It Works',
                'name' => 'sp-how-it-works',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array(
                    'key' => 'field_567e51c1ae109',
                    'label' => 'Headline',
                    'name' => 'headline',
                    'type' => 'text',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_567e51c1ae110',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_56973cb7caef0',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'Photo Box',
                'name' => 'sp-photobox',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array(
                    'key' => 'field_567e51c1ae111',
                    'label' => 'Headline',
                    'name' => 'headline',
                    'type' => 'text',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_567e51c1ae112',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_56973cb7caef1',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'Pricing',
                'name' => 'sp-pricing',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array(
                    'key' => 'field_567e51c1ae113',
                    'label' => 'Headline',
                    'name' => 'headline',
                    'type' => 'text',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_567e51c1ae114',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_56973cb7caef2',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

              array(
                'label' => 'Video',
                'name' => 'sp-video',
                'display' => 'row',
                'min' => '',
                'max' => '',
                'sub_fields' => array(
                  array(
                    'key' => 'field_567e51c1ae115',
                    'label' => 'Headline',
                    'name' => 'headline',
                    'type' => 'text',
                    'column_width' => '',
                    'default_value' => '',
                    'placeholder' => '',
                    'prepend' => '',
                    'append' => '',
                    'formatting' => 'none',
                    'maxlength' => '',
                  ),
                  array (
                    'key' => 'field_567e51c1ae116',
                    'label' => 'Body Text',
                    'name' => 'body_text',
                    'type' => 'wysiwyg',
                    'column_width' => '',
                    'default_value' => '',
                    'toolbar' => 'full',
                    'media_upload' => 'yes',
                  ),
                  array (
                    'key' => 'field_56973cb7caef3',
                    'label' => 'Which phases is this block visible during?',
                    'name' => 'visible_phases',
                    'type' => 'checkbox',
                    'instructions' => 'If no boxes are checked, this block will never be visible.',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array (
                      'width' => '',
                      'class' => '',
                      'id' => '',
                    ),
                    'choices' => array (
                      'presale' => 'Presale',
                      'sale' => 'Sale',
                      'postsale' => 'Post-Sale',
                    ),
                    'default_value' => array (
                    ),
                    'layout' => 'horizontal',
                    'toggle' => 1,
                  ),
                ),
              ),

            ),
            'button_label' => 'Add Section',
            'min' => '',
            'max' => '',
          ),
          array (
            'key' => 'field_5692161ffa9af',
            'label' => 'Launch Settings',
            'name' => '',
            'type' => 'tab',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => '',
              'class' => '',
              'id' => '',
            ),
            'placement' => 'left',
            'endpoint' => 0,
          ),
          array (
            'key' => 'field_56921642fa9b0',
            'label' => 'What date does the launch begin?',
            'name' => 'launch_start_date',
            'type' => 'date_picker',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => 50,
              'class' => '',
              'id' => '',
            ),
            'display_format' => 'Y-m-d',
            'return_format' => 'Ymd',
            'first_day' => 1,
          ),
          array (
            'key' => 'field_56921d2a3a55d',
            'label' => 'What date does the launch end?',
            'name' => 'launch_end_date',
            'type' => 'date_picker',
            'instructions' => '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => 50,
              'class' => '',
              'id' => '',
            ),
            'display_format' => 'Y-m-d',
            'return_format' => 'Ymd',
            'first_day' => 1,
          ),
          array (
            'key' => 'field_56921800fa9b1',
            'label' => 'What time does the launch begin?',
            'name' => 'launch_start_time',
            'type' => 'text',
            'instructions' => 'NOTE: Use military time! If the sale starts at the beginning of the day, set the value for this field to 0:00. If it starts at the end, use 23:59.',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => 50,
              'class' => '',
              'id' => '',
            ),
            'default_value' => '0:00',
            'placeholder' => '',
            'prepend' => '',
            'append' => '',
            'maxlength' => '',
            'readonly' => 0,
            'disabled' => 0,
          ),
          array (
            'key' => 'field_56921a77fa9b6',
            'label' => 'What time does the launch end?',
            'name' => 'launch_end_time',
            'type' => 'text',
            'instructions' => 'NOTE: Use military time! If the sale ends at midnight, it\'s probably easiest to use 23:59.',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => 50,
              'class' => '',
              'id' => '',
            ),
            'default_value' => '0:00',
            'placeholder' => '',
            'prepend' => '',
            'append' => '',
            'maxlength' => '',
            'readonly' => 0,
            'disabled' => 0,
          ),
          array (
            'key' => 'field_569218cdfa9b2',
            'label' => 'Launch Phases',
            'name' => 'launch_phases',
            'type' => 'repeater',
            'instructions' => '<p>You can create as many launch phases as you like. The can even overlap, technically, though that\'s probably going to be super confusing for both you and your customers.</p><p>To set phase-specific content, wrap it in a shortcode: <code>[sp phase="my_phase_name"]YOUR CONTENT[/sp]</code></p><p>You can also make text visible during multiple phases with a comma-separated list: <code>[sp phase="my_phase_name, other_phase_name"]CONTENT[/sp]</code></p>',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array (
              'width' => '',
              'class' => '',
              'id' => '',
            ),
            'collapsed' => '',
            'min' => '',
            'max' => '',
            'layout' => 'table',
            'button_label' => 'Add Row',
            'sub_fields' => array (
              array (
                'key' => 'field_56921916fa9b3',
                'label' => 'Phase Name',
                'name' => 'phase_name',
                'type' => 'text',
                'instructions' => 'This is the value to use in the `[sp]` shortcode.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array (
                  'width' => '',
                  'class' => '',
                  'id' => '',
                ),
                'default_value' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'maxlength' => '',
                'readonly' => 0,
                'disabled' => 0,
              ),
              array (
                'key' => 'field_56921975fa9b4',
                'label' => 'How many days before launch does this phase begin?',
                'name' => 'phase_start_offset',
                'type' => 'number',
                'instructions' => 'Measured in 24-hour increments from the launch start.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array (
                  'width' => '',
                  'class' => '',
                  'id' => '',
                ),
                'default_value' => 0,
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'min' => 0,
                'max' => '',
                'step' => 1,
                'readonly' => 0,
                'disabled' => 0,
              ),
              array (
                'key' => 'field_569219dafa9b5',
                'label' => 'How many days before launch does this phase end?',
                'name' => 'phase_end_offset',
                'type' => 'number',
                'instructions' => 'Measured in 24-hour increments from the launch start.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array (
                  'width' => '',
                  'class' => '',
                  'id' => '',
                ),
                'default_value' => 0,
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'min' => 0,
                'max' => '',
                'step' => 1,
                'readonly' => 0,
                'disabled' => 0,
              ),
            ),
          ),
        ),
        'location' => array (
          array (
            array (
              'param' => 'page_template',
              'operator' => '==',
              'value' => 'sellaporter.php',
              'order_no' => 0,
              'group_no' => 0,
            ),
          ),
        ),
        'options' => array (
          'position' => 'acf_after_title',
          'layout' => 'default',
          'hide_on_screen' => array (
            0 => 'the_content',
            1 => 'custom_fields',
            2 => 'discussion',
            3 => 'comments',
            4 => 'categories',
            5 => 'tags',
            6 => 'send-trackbacks',
          ),
        ),
        'menu_order' => 0,
      ));
    }
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

  public function register_options_page() {
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

  public function register_shortcodes() {
    add_shortcode('sp', array($this, 'shortcode_phase_conditional'));
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
    $is_inline_text = $atts['inline'] || false;

    // Convert the valid phases to an array.
    $phases_when_text_is_visible = array_map('trim', split(',', $atts['phase']));
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
