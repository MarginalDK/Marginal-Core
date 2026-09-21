<?php
/**
 * Plugin Name: Marginal Core Standards & Hardening
 * Description: Enforces Marginal agency security, REST API hardening, white-labeling, cron fixes, and custom dashboard branding across the fleet.
 * Version:     1.4.1
 * Author:      Marginal
 * Author URI:  https://marginal.dk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
   1. AGENCY CONFIGURATION & IP TOGGLES
   ============================================================================= */

// Master Toggle: Set to 'true' to restrict file installs/updates to whitelisted IPs.
// Set to 'false' during initial setup or standard maintenance.
define( 'MARGINAL_ENABLE_IP_RESTRICTIONS', false );

// Whitelisted IP Addresses (Evaluated only if MARGINAL_ENABLE_IP_RESTRICTIONS is true)
define( 'MARGINAL_ALLOWED_IPS', [
	'127.0.0.1',          // Localhost / Internal WP-Cron
	// '1.2.3.4',         // Bastion (bastion.marginal.dk) IP — Required for MainWP remote updates
	// '5.6.7.8',         // Marginal Tailscale Exit Node / Office Static IP
] );


/* =============================================================================
   2. IP DETECTION & EVALUATION HELPERS
   ============================================================================= */

/**
 * Safely retrieve request IP without header spoofing risks
 */
function marginal_get_request_ip(): string {
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Check if the current request is permitted to modify files
 */
function marginal_can_modify_files(): bool {
	if ( ! MARGINAL_ENABLE_IP_RESTRICTIONS ) {
		return true;
	}

	$ip = marginal_get_request_ip();
	return $ip !== '' && in_array( $ip, MARGINAL_ALLOWED_IPS, true );
}


/* =============================================================================
   3. HARDENING & CORE CONSTANTS
   ============================================================================= */

// Always disable the in-dashboard Theme & Plugin Code Editor
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// Restrict plugin/theme installations and updates based on the master toggle / IP check
if ( ! defined( 'DISALLOW_FILE_MODS' ) ) {
	define( 'DISALLOW_FILE_MODS', ! marginal_can_modify_files() );
}

// Disable XML-RPC entirely
add_filter( 'xmlrpc_enabled', '__return_false' );

// Remove X-Pingback Header
add_filter( 'wp_headers', function( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
} );

// Block unauthenticated REST API User Enumeration (/wp-json/wp/v2/users)
add_filter( 'rest_endpoints', function( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );


/* =============================================================================
   4. MULTISITE & CRON ENGINE FIXES
   ============================================================================= */

// Register missing 'minute' cron schedule for MainWP System Monitor to prevent WP-Cron errors
add_filter( 'cron_schedules', function( $schedules ) {
	if ( ! isset( $schedules['minute'] ) ) {
		$schedules['minute'] = [
			'interval' => 60,
			'display'  => 'Every Minute',
		];
	}
	return $schedules;
} );

// Safely unhook MainWP Child connection notices on sub-sites
add_action( 'admin_init', function() {
	if ( is_multisite() && ! is_main_site() ) {
		if ( class_exists( '\MainWP\Child\MainWP_Pages' ) ) {
			$pages_class = '\MainWP\Child\MainWP_Pages';
			if ( method_exists( $pages_class, 'get_instance' ) ) {
				$pages_instance = $pages_class::get_instance();
				remove_action( 'admin_notices', [ $pages_instance, 'admin_notice' ] );
				remove_action( 'all_admin_notices', [ $pages_instance, 'admin_notice' ] );
			}
		}
	}
} );


/* =============================================================================
   5. MARGINAL DASHBOARD WIDGET & CLEANUP
   ============================================================================= */

add_action( 'wp_dashboard_setup', function() {
	// Remove default WP clutter
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );       // WP Events & News
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );   // Quick Draft
	remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' ); // Site Health

	// Register Marginal Status Widget
	wp_add_dashboard_widget(
		'marginal_agency_dashboard_widget',
		'Marginal Site Care & Protection',
		'marginal_render_dashboard_widget'
	);
} );

/**
 * Render Marginal Status & Support Dashboard Widget
 */
function marginal_render_dashboard_widget() {
	$mainwp_active = class_exists( 'MainWP_Child' ) || defined( 'MAINWP_CHILD_VERSION' );
	$mainwp_badge  = $mainwp_active 
		? '<span style="color:#16a34a; font-weight:600;">● Connected</span>' 
		: '<span style="color:#dc2626; font-weight:600;">● Disconnected</span>';

	$patchstack_active = defined( 'PATCHSTACK_VERSION' ) || class_exists( 'Patchstack' ) || get_option( 'patchstack_options' );
	$patchstack_badge  = $patchstack_active 
		? '<span style="color:#16a34a; font-weight:600;">● Protected</span>' 
		: '<span style="color:#d97706; font-weight:600;">● Standard</span>';
	?>
	<div style="font-family: system-ui, -apple-system, sans-serif; font-size: 13px; color: #1e293b;">
		<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0;">
			<div style="background:#0f172a; color:#fff; font-weight:700; padding:4px 10px; border-radius:4px; font-size:12px; letter-spacing:0.5px;">MARGINAL</div>
			<div style="font-size:11px; color:#64748b; font-weight:500;">Agency Maintenance Plan</div>
		</div>

		<table style="width:100%; border-collapse:collapse; margin-bottom: 14px;">
			<tr style="border-bottom: 1px solid #f1f5f9;">
				<td style="padding:6px 0; color:#475569;">Firewall Protection (Patchstack)</td>
				<td style="padding:6px 0; text-align:right;"><?php echo $patchstack_badge; ?></td>
			</tr>
			<tr style="border-bottom: 1px solid #f1f5f9;">
				<td style="padding:6px 0; color:#475569;">Central Maintenance (MainWP)</td>
				<td style="padding:6px 0; text-align:right;"><?php echo $mainwp_badge; ?></td>
			</tr>
			<tr>
				<td style="padding:6px 0; color:#475569;">Server Runtime</td>
				<td style="padding:6px 0; text-align:right; color:#64748b; font-weight:500;">PHP <?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ); ?></td>
			</tr>
		</table>

		<div style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
			<div style="font-weight:600; margin-bottom:4px; color:#0f172a;">Need assistance or site changes?</div>
			<div style="color:#64748b; font-size:12px; margin-bottom:10px;">Your site is actively monitored, hardened, and backed up by Marginal.</div>
			<a href="https://marginal.dk" target="_blank" rel="noopener" style="display:inline-block; background:#2563eb; color:#ffffff; text-decoration:none; padding:6px 12px; border-radius:4px; font-weight:600; font-size:12px;">Contact Support &rarr;</a>
		</div>
	</div>
	<?php
}


/* =============================================================================
   6. WHITE-LABELING
   ============================================================================= */

add_filter( 'admin_footer_text', function() {
	return 'Maintained & Managed by <a href="https://marginal.dk" target="_blank" rel="noopener">Marginal</a>';
} );

add_filter( 'login_headerurl', function() {
	return 'https://marginal.dk';
} );

add_filter( 'login_headertext', function() {
	return 'Managed by Marginal';
} );