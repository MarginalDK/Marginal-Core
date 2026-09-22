<?php
/**
 * Marginal branding on the admin footer and login screen.
 *
 * All three destinations read MARGINAL_CORE_SUPPORT_URL, the same constant
 * the widget uses, so a client-specific support destination is one define
 * rather than three.
 *
 * wp-login.php is not an admin context, so none of these hooks may sit
 * behind an is_admin() check.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

function marginal_core_support_url(): string {
	return (string) marginal_core_config( 'support_url', 'https://marginal.dk' );
}

function marginal_core_admin_footer_text(): string {
	// The anchor is built and escaped here so the translated string never
	// carries markup — translators handle "Maintained and managed by %s"
	// only, never an <a> tag.
	$link = sprintf(
		'<a href="%s" target="_blank" rel="noopener">Marginal</a>',
		esc_url( marginal_core_support_url() )
	);

	return sprintf( __( 'Maintained and managed by %s', 'marginal-core' ), $link );
}

function marginal_core_login_header_url(): string {
	return marginal_core_support_url();
}

function marginal_core_login_header_text(): string {
	return __( 'Managed by Marginal', 'marginal-core' );
}

function marginal_core_white_label_boot(): void {
	add_filter( 'admin_footer_text', 'marginal_core_admin_footer_text' );
	add_filter( 'login_headerurl', 'marginal_core_login_header_url' );
	add_filter( 'login_headertext', 'marginal_core_login_header_text' );
}
