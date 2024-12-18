<?php
/*
Plugin Name: Woocommerce Importer Improved
Requires Plugins:  woocommerce
*/

define('WIM_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WIM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WIM_MESSAGES_NAME', 'wim_messages');
define('WIM_ERROR_CODE', 'wim_error');

if (!defined('ABSPATH')) exit;

// Add menu item under WooCommerce
add_action(
  'admin_menu',
  function () {
    add_submenu_page(
      'woocommerce',
      'Woocommerce Importer Improved',
      'Woocommerce Importer Improved',
      'manage_woocommerce',
      'woocommerce-importer-improved',
      'wim_render_page'
    );
  }
);

// Render the upload page
function wim_render_page()
{
  include WIM_PLUGIN_DIR . 'templates/admin/wim.php';
}

// Add settings errors display
add_action('admin_notices', function () {
  settings_errors(WIM_MESSAGES_NAME);
});

// Activation hook
register_activation_hook(__FILE__, function () {
  if (class_exists('WooCommerce')) return;
  deactivate_plugins(WIM_PLUGIN_BASENAME);
  wp_die('This plugin requires WooCommerce to be installed and active.');
});

add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook != 'woocommerce_page_woocommerce-importer-improved') return;
  wp_enqueue_style('wim-admin-styles', plugins_url('css/admin.css', __FILE__), [], '1.0.0');
  wp_enqueue_script('wim-admin-script', plugins_url('js/admin.js', __FILE__), [], '1.0.0');
});

function wim_handle_upload($file, $csv_options)
{
  $separator = $csv_options['separator'];
  $enclosure = $csv_options['enclosure'];
  $escape = $csv_options['escape'];

  try {
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload failed with error code: ' . $file['error']);
    if (fopen($file['tmp_name'], "r") === false) throw new Exception('Failed to read file content.');

    $handle = fopen($file['tmp_name'], "r");
    $headers = fgetcsv($handle, 0, $separator, $enclosure, $escape);

    $rows = [];
    while (($row = fgetcsv($handle, 0, $separator, $enclosure, $escape)) !== false) {
      $rows[] = $row;
    }
    fclose($handle);

    return array(
      "headers" => $headers,
      "rows" => $rows,
    );
  } catch (Exception $e) {
    add_settings_error(
      WIM_MESSAGES_NAME,
      WIM_ERROR_CODE,
      $e->getMessage(),
      'error'
    );
    return false;
  }
}

function wim_notice($string, $type, ...$args)
{
  $escaped_args = array_map('esc_html', $args);
  $full_string = vsprintf($string, $escaped_args);

  return sprintf(
    '<div class="notice notice-' . $type . ' ">
	<p>%s</p>
/   </div>',
    $full_string
  );
}

function wim_load_products($file_rows, $headers, $wim_product_fields)
{
  $output = '';
  $successful = 0;
  $failed = 0;
  $errors = [];

  // Get WPML default language
  $default_language = apply_filters('wpml_default_language', null);

  foreach ($file_rows as $row) {
    try {
      $language_column = array_search($_POST['language_code'], $headers);
      $sku_column = array_search($_POST['set_sku'], $headers);
      $original_sku_column = array_search($_POST['original_product_sku'], $headers);

      if ($language_column === false || $sku_column === false) {
        throw new Exception('Required columns (language_code or SKU) not found');
      }

      $language_code = strtolower(trim($row[$language_column]));
      $sku = trim($row[$sku_column]);
      $original_sku = $original_sku_column !== false ? trim($row[$original_sku_column]) : '';

      // Check if this is a translation
      $is_translation = !empty($original_sku);

      // If this is a translation, check if the original product exists
      if ($is_translation) {
        $original_product_id = wc_get_product_id_by_sku($original_sku);
        if (!$original_product_id) {
          throw new Exception("Original product with SKU {$original_sku} not found");
        }
      }

      // For translations, we'll modify the SKU to make it unique
      if ($is_translation) {
        $existing_product_id = wc_get_product_id_by_sku($sku);
        if ($existing_product_id) {
          $existing_lang = apply_filters('wpml_element_language_code', null, array('element_id' => $existing_product_id, 'element_type' => 'post_product'));
          if ($existing_lang !== $language_code) {
            $original_sku = $sku;
            $sku = $sku . '-' . $language_code;
          }
        }
      }

      // Create the product
      $product = new WC_Product_Simple();
      $product->set_sku($sku);
      $acf_fields = [];

      foreach ($wim_product_fields as $field_key => $field_config) {
        if (in_array($field_key, ['language_code', 'original_product_sku'])) {
          continue;
        }

        $mapped_column = $_POST[$field_key] ?? '';
        if (empty($mapped_column)) continue;

        $column_index = array_search($mapped_column, $headers);
        if ($column_index === false) continue;

        $value = $row[$column_index];

        switch ($field_config['type']) {
          case 'array':
            $value = array_map('trim', explode(',', $value));
            break;

          case 'attribute':
            if (!isset($original_product_id)) {
              $original_product_id = null;
            }

            if (!empty($value)) {
              $attr_values = array_map('trim', explode('|', $value));
              $taxonomy = 'pa_' . $field_key;
              $term_ids = [];

              if ($is_translation && $original_product_id && !empty($original_product_id)) {
                // Switch to default language first
                do_action('wpml_switch_language', $default_language);

                // Get original product's attributes
                $original_product = wc_get_product($original_product_id);
                if ($original_product) {
                  $original_attributes = $original_product->get_attributes();

                  foreach ($original_attributes as $original_attribute) {
                    if ($original_attribute->get_name() === $taxonomy) {
                      $original_term_ids = $original_attribute->get_options();

                      // Process each translation value in order
                      foreach ($attr_values as $index => $translation_term_name) {

                        if (isset($original_term_ids[$index])) {
                          $original_term = get_term($original_term_ids[$index], $taxonomy);

                          if ($original_term) {
                            // Switch to translation language for creating/getting translated term
                            do_action('wpml_switch_language', $language_code);

                            // First try to get existing translation
                            $translated_term_id = apply_filters('wpml_object_id', $original_term->term_id, $taxonomy, false, $language_code);

                            var_dump("---------------------------------\n");
                            var_dump("Product Code: $language_code \n");
                            var_dump("Doing: $translation_term_name \n");
                            if (!$translated_term_id) {
                              var_dump("Is the same, creating it in the $taxonomy\n");
                              // Create new term in target language
                              $new_term = wp_insert_term($translation_term_name, $taxonomy, array('slug' => slugify($translation_term_name . '-' . $language_code)));

                              if (!is_wp_error($new_term)) {
                                var_dump("NEW TERM: $new_term");
                                $translated_term_id = $new_term['term_id'];

                                // Get TRID of original term
                                $trid = apply_filters('wpml_element_trid', null, $original_term->term_id, 'tax_' . $taxonomy);
                                var_dump("trid: $trid\n");

                                // Connect the translation
                                do_action('wpml_set_element_language_details', [
                                  'element_id' => $translated_term_id,
                                  'element_type' => 'tax_' . $taxonomy,
                                  'trid' => $trid,
                                  'language_code' => $language_code,
                                  'source_language_code' => $default_language
                                ]);
                              } else {
                                var_dump("Error occured\n");
                              }
                            } else {
                              var_dump("Is different, creating it \n");
                              // Update existing term if name is different
                              wp_update_term($translated_term_id, $taxonomy, ['name' => $translation_term_name]);
                            }

                            if ($translated_term_id) {
                              $term_ids[] = $translated_term_id;
                            }

                            // Switch back to default language for next iteration
                            do_action('wpml_switch_language', $default_language);
                          }
                        }
                      }
                      break;
                    }
                  }
                }
              } else {
                // Creating terms in default language
                foreach ($attr_values as $term_name) {
                  $existing_term = get_term_by('name', $term_name, $taxonomy);

                  if (!$existing_term) {
                    $new_term = wp_insert_term($term_name, $taxonomy);

                    if (!is_wp_error($new_term)) {
                      $term_id = $new_term['term_id'];

                      // Set language for new term
                      do_action('wpml_set_element_language_details', [
                        'element_id' => $term_id,
                        'element_type' => 'tax_' . $taxonomy,
                        'trid' => null,
                        'language_code' => $language_code,
                        'source_language_code' => null
                      ]);

                      $term_ids[] = $term_id;
                    }
                  } else {
                    $term_ids[] = $existing_term->term_id;
                  }
                }
              }

              // Set the attribute for the product
              if (!empty($term_ids)) {

                $attribute = new WC_Product_Attribute();
                $attribute->set_id(wc_attribute_taxonomy_id_by_name($field_key));
                $attribute->set_name($taxonomy);
                $attribute->set_options($term_ids);
                $attribute->set_visible(true);
                $attribute->set_variation(false);

                $attributes = $product->get_attributes();

                $attributes[] = $attribute;
                $product->set_attributes($attributes);
              }

              // Switch back to default language
              do_action('wpml_switch_language', $default_language);
            }
            continue 2;

          case 'acf':
            if (!empty($value)) {
              $acf_field_name = str_replace('acf_', '', $field_key);
              $acf_fields[$acf_field_name] = $value;
            }
            continue 2;
            break;

          case 'text':
            break;
        }

        // Handle special cases
        switch ($field_key) {
          case 'set_image_id':
            if (!empty($value)) {
              $attachment_id = attachment_url_to_postid($value);
              if ($attachment_id) {
                $product->set_image_id($attachment_id);
              }
            }
            break;

          case 'set_gallery_image_ids':
            if (!empty($value)) {
              $gallery_urls = is_array($value) ? $value : explode('|', $value);
              $gallery_urls = array_map('trim', $gallery_urls);
              $gallery_ids = array_map('attachment_url_to_postid', $gallery_urls);
              $gallery_ids = array_filter($gallery_ids);
              $product->set_gallery_image_ids($gallery_ids);
            }
            break;

          case 'set_category_ids':
            if (!is_array($value)) break;
            $category_ids = [];
            do_action('wpml_switch_language', $language_code);

            foreach ($value as $cat_name) {
              $term = get_term_by('name', $cat_name, 'product_cat');
              if ($term) {
                $category_ids[] = $term->term_id;
              } else {
                $new_term = wp_insert_term($cat_name, 'product_cat');
                if (!is_wp_error($new_term)) {
                  do_action('wpml_register_single_term', $new_term['term_id'], 'product_cat', $language_code);
                  $category_ids[] = $new_term['term_id'];
                }
              }
            }

            do_action('wpml_switch_language', $default_language);
            if (!empty($category_ids)) {
              $product->set_category_ids($category_ids);
            }
            break;

          default:
            if (method_exists($product, $field_key)) {
              $product->$field_key($value);
            }
            break;
        }
      }

      // Save the product
      $product->save();
      $product_id = $product->get_id();

      // Set the language for the product using WPML
      if ($is_translation) {
        $trid = apply_filters('wpml_element_trid', null, $original_product_id, 'post_product');

        do_action('wpml_set_element_language_details', [
          'element_id' => $product_id,
          'element_type' => 'post_product',
          'trid' => $trid,
          'language_code' => $language_code,
          'source_language_code' => $default_language
        ]);
      } else {
        do_action('wpml_set_element_language_details', [
          'element_id' => $product_id,
          'element_type' => 'post_product',
          'trid' => null,
          'language_code' => $language_code,
          'source_language_code' => null
        ]);
      }

      // Save ACF fields
      foreach ($acf_fields as $acf_field_name => $acf_field_value) {
        update_field($acf_field_name, $acf_field_value, $product_id);
      }

      $successful++;
      $output .= wim_notice('Successfully created product: %s (Language: %s, SKU: %s)', 'success', $product->get_name(), $language_code, $sku);
    } catch (Exception $e) {
      $failed++;
      $error_message = $e->getMessage();
      $errors[] = $error_message;
      $output .= wim_notice('Failed to create product: %s', 'error', $error_message);
    }
  }

  $summary = wim_notice('Import completed: %d successful, %d failed', 'info', $successful, $failed);
  return $summary . $output;
}

function slugify($text, string $divider = '-')
{
  // replace non letter or digits by divider
  $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

  // transliterate
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

  // remove unwanted characters
  $text = preg_replace('~[^-\w]+~', '', $text);

  // trim
  $text = trim($text, $divider);

  // remove duplicate divider
  $text = preg_replace('~-+~', $divider, $text);

  // lowercase
  $text = strtolower($text);

  if (empty($text)) {
    return 'n-a';
  }

  return $text;
}
