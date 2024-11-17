<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
	exit;
}

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

if (!isset($_SESSION['wim_screen']) || !isset($_POST['submit'])) {
	$_SESSION['wim_screen'] = 1;
}

$wim_product_fields = [
	'set_name' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Product Name',
	],
	'set_sku' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'SKU',
	],
	'set_description' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Description',
	],
	'set_short_description' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Short Description',
	],
	'set_category_ids' => [
		'type' => 'array',
		'advanced' => false,
		'label' => 'Categories',
	],
	'set_image_id' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Main Image ID',
	],
	'set_gallery_image_ids' => [
		'type' => 'array',
		'advanced' => false,
		'label' => 'Gallery Image IDs',
	],
	'set_weight' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Weight',
	],
	'language_code' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Language Code',
	],
	'original_product_sku' => [
		'type' => 'text',
		'advanced' => false,
		'label' => 'Original Product SKU',
	]
];

$wim_attributes = wc_get_attribute_taxonomies();
foreach ($wim_attributes as $attribute) {
	$wim_product_fields[$attribute->attribute_name] = array(
		"type" => "attribute",
		"advanced" => false,
		"label" => "Attr: " . $attribute->attribute_label
	);
}

$field_groups = acf_get_field_groups(array(
	'post_type' => 'product'
));

foreach ($field_groups as $field_group) {
	$fields = acf_get_fields($field_group);
	foreach ($fields as $field) {
		$wim_product_fields['acf_' . $field['name']] = array(
			"type" => "acf",
			"advanced" => false,
			"label" => "ACF: " . $field['label']
		);
	}
}

$wim_csv_inputs = array(
	array(
		"label" => "Separator",
		"id" => "separator",
		"value" => ",",
	),
	array(
		"label" => "Enclosure",
		"id" => "enclosure",
		"value" => '"',
	),
	array(
		"label" => "Escape",
		"id" => "escape",
		"value" => "\\",
	),
);

// Check if a file was uploaded
if (isset($_POST['submit']) && $_SESSION['wim_screen'] == 1) {
	$_SESSION['wim_csv_options'] = [
		"separator" => stripslashes($_POST['separator'] ?? ","),
		"enclosure" => stripslashes($_POST['enclosure'] ?? '"'),
		"escape" => stripslashes($_POST['escape'] ?? "\\"),
	];

	$wim_file_data = wim_handle_upload($_FILES['file_upload'], $_SESSION['wim_csv_options']);

	$wim_file_headers = $wim_file_data['headers'];
	$_SESSION['wim_csv_file_headers'] = $wim_file_data['headers'];
	$_SESSION['wim_csv_file_rows'] = $wim_file_data['rows'];

	$_SESSION['wim_screen'] = 2;
} elseif (isset($_POST['submit']) && $_SESSION['wim_screen'] == 2) {

	$test_html = wim_load_products($_SESSION['wim_csv_file_rows'], $_SESSION['wim_csv_file_headers'], $wim_product_fields);
	$_SESSION['wim_screen'] = 3;
}

?>

<div class="wrap">
	<h1>Woocommerce Importer Improved</h1>

	<?php if ($_SESSION['wim_screen'] == 1): ?>
		<form method="post" enctype="multipart/form-data" id="screen-1-form">
			<label>
				<strong>Select File</strong>
				<input type="file" name="file_upload" id="file_upload" required>
			</label>

			<?php foreach ($wim_csv_inputs as $input): ?>
				<label>
					<strong><?php echo $input['label'] ?></strong>
					<input type="text" name="<?php echo $input['id'] ?>" id="<?php echo $input['id'] ?>" value='<?php echo $input["value"] ?>'>
				</label>
			<?php endforeach; ?>

			<?php submit_button('Upload File'); ?>
		</form>
	<?php endif; ?>

	<?php if ($_SESSION['wim_screen'] == 2): ?>
		<h2>Headers</h2>
		<div class="headers-wrapper">
			<?php foreach ($wim_file_headers as $header): ?>
				<button class="header button button-primary" data-disabled="false">
					<?php echo $header ?>
				</button>
			<?php endforeach; ?>
		</div>
		<form class="product-fields" method="post" enctype="multipart/form-data">
			<?php foreach ($wim_product_fields as $id => $field): ?>
				<div class="input-wrapper <?php echo $field['advanced'] ? 'advanced' : ''; ?>">
					<label for="<?php echo $id ?>">
						<span><?php echo $field['label'] ?></span>
						<input type="text" name="<?php echo $id ?>" id="<?php echo $id ?>">
					</label>
					<div class="field-wrapper" data-id="<?php echo $id ?>"></div>
				</div>
			<?php endforeach; ?>

			<?php if (false): ?>
				<label class="checkbox">
					<input type="checkbox" name="show-advanced" id="show-advanced">
					Show advanced fields
				</label>
			<?php endif; ?>

			<?php submit_button('Load Products'); ?>
		</form>
	<?php endif; ?>

	<?php if ($_SESSION['wim_screen'] == 3): ?>
		<?php echo $test_html ?>
	<?php endif; ?>
</div>
