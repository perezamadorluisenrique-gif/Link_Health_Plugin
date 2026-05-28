<?php
/**
 * Settings page template.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap nlh-wrap">
	<h1><?php esc_html_e( 'Native Link Health Settings', 'native-link-health' ); ?></h1>

	<form action="options.php" method="post" class="nlh-settings-form">
		<?php
		settings_fields( 'nlh_settings' );
		do_settings_sections( 'nlh-settings' );
		submit_button(
			__( 'Save Changes', 'native-link-health' ),
			'primary',
			'submit',
			true,
			array( 'disabled' => 'disabled' )
		);
		?>
	</form>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="nlh-export-form">
		<input type="hidden" name="action" value="nlh_export_csv">
		<?php wp_nonce_field( 'nlh_export_csv_action', 'nlh_export_nonce' ); ?>
		<h2><?php esc_html_e( 'CSV Export', 'native-link-health' ); ?></h2>
		<p><?php esc_html_e( 'Download the current broken-link report as a CSV file.', 'native-link-health' ); ?></p>
		<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export CSV', 'native-link-health' ); ?></button>
	</form>
</div>
