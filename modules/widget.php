<?php
/**
 * Marginal dashboard widget.
 *
 * Replaces WordPress's default clutter with one panel. Every claim here is
 * derived from a check — never asserted in prose. A static reassurance about
 * backups on the dashboard of a site that has none is the kind of thing that
 * matters precisely once, badly.
 *
 * No network calls and no uncached queries: this renders on every dashboard
 * load across the whole fleet.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MARGINAL_CORE_TESTS' ) ) {
	exit;
}

/**
 * Gather everything the panel reports.
 *
 * @return array<string,mixed>
 */
function marginal_core_facts(): array {
	$environment = marginal_core_normalize_environment(
		apply_filters( 'marginal_core_detected_environment', wp_get_environment_type() )
	);

	$declared = ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE )
		|| ( false !== getenv( 'WP_ENVIRONMENT_TYPE' ) && '' !== getenv( 'WP_ENVIRONMENT_TYPE' ) );

	$unique_id = marginal_core_mainwp_unique_id();
	$user      = wp_get_current_user();

	return array(
		'search_engines_blocked' => '0' === (string) get_option( 'blog_public', '1' ),
		'debug_in_production'    => defined( 'WP_DEBUG' ) && WP_DEBUG && 'production' === $environment,
		'environment'            => $environment,
		'environment_declared'   => $declared,
		'backup'                 => marginal_core_backup_status(
			get_option( 'updraft_last_backup' ),
			(int) marginal_core_config( 'backup_max_age_days', 7 ),
			time()
		),
		'mainwp_connected'       => marginal_core_mainwp_is_connected(),
		'mainwp_unique_id'       => $unique_id,
		'mainwp_id_safe'         => marginal_core_is_safe_unique_id( $unique_id ),
		'patchstack_active'      => defined( 'PATCHSTACK_VERSION' )
			|| class_exists( 'Patchstack' )
			|| (bool) get_option( 'patchstack_options' ),
		'object_cache'           => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
		'php_version'            => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		'is_marginal_viewer'     => $user && marginal_core_is_protected_login( (string) $user->user_login ),
	);
}

/**
 * One status row.
 */
function marginal_core_widget_row( string $label, string $value, string $colour = '#64748b' ): string {
	return sprintf(
		'<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:6px 0;color:#475569;">%s</td>'
		. '<td style="padding:6px 0;text-align:right;color:%s;font-weight:600;">%s</td></tr>',
		esc_html( $label ),
		esc_attr( $colour ),
		esc_html( $value )
	);
}

function marginal_core_widget_render(): void {
	$facts    = marginal_core_facts();
	$marginal = (bool) $facts['is_marginal_viewer'];
	$warnings = marginal_core_visible_warnings( marginal_core_warnings( $facts ), $marginal );

	$green = '#16a34a';
	$amber = '#d97706';
	$red   = '#dc2626';

	$summary_colour = empty( $warnings ) ? $green : $amber;

	$backup_state = $facts['backup']['state'];

	// Five non-ok states, each phrased so the row says what is actually
	// wrong. "Unknown" alone would read as a glitch rather than a finding.
	$backup_labels = array(
		'missing' => 'Never run',
		'failed'  => 'Last run failed',
		'stale'   => 'Out of date',
		'unknown' => 'Result not recorded',
		'invalid' => 'Date not trustworthy',
	);

	$backup_text = ( 'ok' === $backup_state && $facts['backup']['time'] )
		? date_i18n( get_option( 'date_format' ), (int) $facts['backup']['time'] )
		: ( isset( $backup_labels[ $backup_state ] ) ? $backup_labels[ $backup_state ] : 'Unknown' );
	?>
	<div style="font-family:system-ui,-apple-system,sans-serif;font-size:13px;color:#1e293b;">

		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #e2e8f0;">
			<div style="background:#0f172a;color:#fff;font-weight:700;padding:4px 10px;border-radius:4px;font-size:12px;letter-spacing:0.5px;">MARGINAL</div>
			<div style="font-size:11px;color:<?php echo esc_attr( $summary_colour ); ?>;font-weight:600;">
				<?php echo esc_html( marginal_core_summary_text( count( $warnings ) ) ); ?>
			</div>
		</div>

		<?php if ( 'production' !== $facts['environment'] ) : ?>
			<div style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:8px 10px;border-radius:6px;margin-bottom:12px;font-weight:600;">
				<?php echo esc_html( strtoupper( $facts['environment'] ) ); ?> — not the live site
			</div>
		<?php endif; ?>

		<table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
			<?php
			echo marginal_core_widget_row(
				'Firewall protection (Patchstack)',
				$facts['patchstack_active'] ? 'Protected' : 'Standard',
				$facts['patchstack_active'] ? $green : $amber
			);

			echo marginal_core_widget_row(
				'Central maintenance (MainWP)',
				$facts['mainwp_connected'] ? 'Connected' : 'Disconnected',
				$facts['mainwp_connected'] ? $green : $red
			);

			echo marginal_core_widget_row(
				'Latest backup',
				$backup_text,
				'ok' === $backup_state ? $green : $red
			);

			echo marginal_core_widget_row(
				'Object cache',
				$facts['object_cache'] ? 'Active' : 'Not in use'
			);

			echo marginal_core_widget_row( 'Server runtime', 'PHP ' . $facts['php_version'] );

			if ( $marginal ) {
				echo marginal_core_widget_row(
					'MainWP security ID',
					'' === $facts['mainwp_unique_id'] ? 'Not set' : $facts['mainwp_unique_id']
				);
			}
			?>
		</table>

		<?php if ( ! empty( $warnings ) ) : ?>
			<ul style="list-style:none;margin:0 0 14px;padding:0;">
				<?php foreach ( $warnings as $warning ) : ?>
					<li style="padding:8px 10px;margin-bottom:6px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;">
						<?php echo esc_html( $warning['text'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">
			<div style="font-weight:600;margin-bottom:4px;color:#0f172a;">Need assistance or site changes?</div>
			<div style="color:#64748b;font-size:12px;margin-bottom:10px;">This site is maintained by Marginal.</div>
			<a href="<?php echo esc_url( (string) marginal_core_config( 'support_url', 'https://marginal.dk' ) ); ?>"
				target="_blank" rel="noopener"
				style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:6px 12px;border-radius:4px;font-weight:600;font-size:12px;">
				Contact support &rarr;
			</a>
		</div>
	</div>
	<?php
}

function marginal_core_widget_setup(): void {
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );

	wp_add_dashboard_widget(
		'marginal_core_status',
		'Marginal — site care and protection',
		'marginal_core_widget_render'
	);
}

function marginal_core_widget_boot(): void {
	add_action( 'wp_dashboard_setup', 'marginal_core_widget_setup' );
}
