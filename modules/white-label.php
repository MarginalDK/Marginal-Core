<?php
/**
 * Marginal branding on the admin footer and login screen.
 *
 * Both destinations build their link with marginal_core_support_link(), the
 * same helper the widget uses (in inc/config.php, not here — see
 * modules/widget.php for why the widget must not depend on this file), so a
 * client-specific support destination is one config value rather than three.
 *
 * wp-login.php is not an admin context, so none of these hooks may sit
 * behind an is_admin() check.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

function marginal_core_admin_footer_text(): string {
	// The anchor is built and escaped here so the translated string never
	// carries markup — translators handle "Maintained and managed by %s"
	// only, never an <a> tag.
	$link = sprintf(
		'<a href="%s" target="_blank" rel="noopener">Marginal</a>',
		esc_url( marginal_core_support_link( 'footer' ) )
	);

	return sprintf( __( 'Maintained and managed by %s', 'marginal-core' ), $link );
}

function marginal_core_login_header_url(): string {
	return marginal_core_support_link( 'login' );
}

function marginal_core_login_header_text(): string {
	return __( 'Managed by Marginal', 'marginal-core' );
}

function marginal_core_white_label_boot(): void {
	add_filter( 'admin_footer_text', 'marginal_core_admin_footer_text' );
	add_filter( 'login_headerurl', 'marginal_core_login_header_url' );
	add_filter( 'login_headertext', 'marginal_core_login_header_text' );
}
