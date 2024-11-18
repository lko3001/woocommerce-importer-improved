<?php
function wim_load_products($file_rows, $headers, $wim_product_fields)
{
  $output = '';
  $successful = 0;
  $failed = 0;
  $errors = [];

  // Get WPML default language
  $default_language = apply_filters('wpml_default_language', null);

  // Cache for attribute terms to avoid redundant lookups
  $attribute_terms_cache = [];

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
        // Skip language_code and original_product_sku as we handled them above
        if (in_array($field_key, ['language_code', 'original_product_sku'])) {
          continue;
        }

        // Get the mapped column index from the form submission
        $mapped_column = $_POST[$field_key] ?? '';
        if (empty($mapped_column)) continue;

        // Get the column index
        $column_index = array_search($mapped_column, $headers);
        if ($column_index === false) continue;

        $value = $row[$column_index];

        // Handle different field types
        switch ($field_config['type']) {
          case 'array':
            // Convert comma-separated values to array
            $value = array_map('trim', explode(',', $value));
            break;

          case 'attribute':
            if (!empty($value)) {
              $attr_values = array_map('trim', explode('|', $value));
              $taxonomy = 'pa_' . $field_key;
              $term_ids = [];

              // Switch to current language context
              do_action('wpml_switch_language', $language_code);

              if ($is_translation && !empty($original_product_id)) {
                // Get original product's attributes in default language
                $original_product = wc_get_product($original_product_id);
                if ($original_product) {
                  $original_attributes = $original_product->get_attributes();

                  foreach ($original_attributes as $original_attribute) {
                    if ($original_attribute->get_name() === $taxonomy) {
                      $original_term_ids = $original_attribute->get_options();

                      // Process each translation value in order
                      foreach ($attr_values as $index => $translation_term_name) {
                        if (isset($original_term_ids[$index])) {
                          // Get original term
                          do_action('wpml_switch_language', $default_language);
                          $original_term = get_term($original_term_ids[$index], $taxonomy);

                          if ($original_term) {
                            // Switch to translation language
                            do_action('wpml_switch_language', $language_code);

                            // Check if translation already exists
                            $translated_term_id = apply_filters('wpml_object_id', $original_term->term_id, $taxonomy, false, $language_code);
                            var_dump($original_term->name, $language_code, $translated_term_id);

                            if (!$translated_term_id) {
                              // Create new translation term
                              $new_term = wp_insert_term($translation_term_name, $taxonomy);
                              if (!is_wp_error($new_term)) {
                                // Get TRID of original term
                                $trid = apply_filters('wpml_element_trid', null, $original_term->term_id, 'tax_' . $taxonomy);

                                // Set up translation
                                do_action('wpml_set_element_language_details', [
                                  'element_id' => $new_term['term_id'],
                                  'element_type' => 'tax_' . $taxonomy,
                                  'trid' => $trid,
                                  'language_code' => $language_code,
                                  'source_language_code' => $default_language
                                ]);

                                $term_ids[] = $new_term['term_id'];
                              }
                            } else {
                              $term_ids[] = $translated_term_id;
                            }
                          }
                        }
                      }
                      break;
                    }
                  }
                }
              } else {
                // This is a product in default language (Italian)
                foreach ($attr_values as $term_name) {
                  // Check if term already exists in this language
                  $existing_term = get_term_by('name', $term_name, $taxonomy);

                  if (!$existing_term) {
                    // Create new term
                    $new_term = wp_insert_term($term_name, $taxonomy);
                    if (!is_wp_error($new_term)) {
                      $term_id = $new_term['term_id'];

                      // Set language for the new term
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
                    // Check term's language
                    $term_language = apply_filters('wpml_element_language_code', null, [
                      'element_id' => $existing_term->term_id,
                      'element_type' => 'tax_' . $taxonomy
                    ]);

                    if ($term_language === $language_code) {
                      $term_ids[] = $existing_term->term_id;
                    } else {
                      // Create new term in correct language
                      $new_term = wp_insert_term($term_name, $taxonomy);
                      if (!is_wp_error($new_term)) {
                        // Set language for the new term
                        do_action('wpml_set_element_language_details', [
                          'element_id' => $new_term['term_id'],
                          'element_type' => 'tax_' . $taxonomy,
                          'trid' => null,
                          'language_code' => $language_code,
                          'source_language_code' => null
                        ]);

                        $term_ids[] = $new_term['term_id'];
                      }
                    }
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
              // Remove 'acf_' prefix to get original field name
              $acf_field_name = str_replace('acf_', '', $field_key);
              $acf_fields[$acf_field_name] = $value;
            }
            continue 2;
            break;

          case 'text':
            // Text fields can be used as-is
            break;
        }

        // Handle special cases
        switch ($field_key) {
          case 'set_image_id':
            // Convert URL to attachment ID using WordPress core function
            if (!empty($value)) {
              $attachment_id = attachment_url_to_postid($value);
              if ($attachment_id) {
                $product->set_image_id($attachment_id);
              }
            }
            break;

          case 'set_gallery_image_ids':
            // Convert URLs to attachment IDs
            if (!empty($value)) {
              $gallery_urls = is_array($value) ? $value : explode('|', $value);
              $gallery_urls = array_map('trim', $gallery_urls);
              $gallery_ids = array_map('attachment_url_to_postid', $gallery_urls);
              $gallery_ids = array_filter($gallery_ids);
              $product->set_gallery_image_ids($gallery_ids);
            }
            break;

          case 'set_category_ids':
            // Convert category names to IDs
            if (!is_array($value)) break;
            $category_ids = [];

            // Switch to the product's language context for categories
            do_action('wpml_switch_language', $language_code);

            foreach ($value as $cat_name) {
              $term = get_term_by('name', $cat_name, 'product_cat');
              if ($term) {
                $category_ids[] = $term->term_id;
              } else {
                $new_term = wp_insert_term($cat_name, 'product_cat');

                if (!is_wp_error($new_term)) {
                  // Register the category for translation
                  do_action('wpml_register_single_term', $new_term['term_id'], 'product_cat', $language_code);
                  $category_ids[] = $new_term['term_id'];
                }
              }
            }

            // Switch back to default language
            do_action('wpml_switch_language', $default_language);

            if (!empty($category_ids)) {
              $product->set_category_ids($category_ids);
            }

            break;

          default:
            // Use the field key as the method name
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
