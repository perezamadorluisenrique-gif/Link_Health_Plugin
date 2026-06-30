<?php
/**
 * Pro feature gating and upsell helper.
 *
 * Native Link Health ships as a free core. Every premium capability is gated
 * behind a WordPress filter that defaults to false; the separate Pro plugin
 * overrides those filters to switch features on. This core never contains any
 * Pro implementation code — only the gates, the extension points the Pro plugin
 * hooks into, and the (optional) upsell UI.
 *
 * The anti-false-positive detection engine is NEVER gated here: detection is the
 * brand and is free forever. Only automation, scale, monitoring and agency
 * tooling are monetizable.
 *
 * @package NativeLinkHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes premium feature checks and upsell rendering.
 */
class NLH_Pro {
	/**
	 * Canonical list of gated premium features.
	 *
	 * The key is also the filter slug: feature `bulk_fix` is gated by
	 * `nlh_pro_bulk_fix_enabled`. Order follows Phase 5 of the roadmap (value /
	 * pain priority).
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function features(): array {
		return array(
			'bulk_fix'            => array(
				'title'       => __( 'Bulk fix &amp; find-and-replace', 'native-link-health' ),
				'description' => __( 'Replace a broken or moved URL everywhere on your site in one safe pass — using the same no-regex HTML engine, never touching your database directly.', 'native-link-health' ),
				'icon'        => 'dashicons-controls-repeat',
			),
			'redirect_management' => array(
				'title'       => __( '301 redirect management', 'native-link-health' ),
				'description' => __( 'Turn detected redirect chains into clean one-hop 301s, and create redirects for removed pages without leaving the dashboard.', 'native-link-health' ),
				'icon'        => 'dashicons-randomize',
			),
			'email_notifications' => array(
				'title'       => __( 'Email alerts &amp; digests', 'native-link-health' ),
				'description' => __( 'Get notified the moment a new broken link is confirmed, plus a scheduled health digest — so a quiet site never rots unnoticed.', 'native-link-health' ),
				'icon'        => 'dashicons-email-alt',
			),
			'link_insertion'      => array(
				'title'       => __( 'One-click internal linking', 'native-link-health' ),
				'description' => __( 'Accept an internal-link recommendation and insert it safely at block level — no copy-pasting into the editor.', 'native-link-health' ),
				'icon'        => 'dashicons-admin-links',
			),
			'reporting'           => array(
				'title'       => __( 'Health trends &amp; reports', 'native-link-health' ),
				'description' => __( 'Track your Link Health Score over time and export branded PDF/CSV reports on a schedule.', 'native-link-health' ),
				'icon'        => 'dashicons-chart-line',
			),
			'multisite'           => array(
				'title'       => __( 'Multisite network dashboard', 'native-link-health' ),
				'description' => __( 'Monitor link health across every site in a network from one screen — the agency tier.', 'native-link-health' ),
				'icon'        => 'dashicons-networking',
			),
		);
	}

	/**
	 * Whether the Pro/Freemius layer is switched on for this install.
	 *
	 * When false (the default), no upsell UI is ever rendered and no premium
	 * filters are expected to be overridden. This is what keeps a fresh install
	 * free of "Available in Pro" clutter.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Filters whether the monetization layer (upsell UI) is active.
		 *
		 * Defaults to the NLH_FREEMIUS_ENABLED constant. The Pro plugin or a site
		 * owner can force it, but it must stay false on the free wordpress.org build.
		 *
		 * @since 1.3.0
		 * @param bool $enabled Whether upsell UI may render.
		 */
		return (bool) apply_filters( 'nlh_pro_ui_enabled', defined( 'NLH_FREEMIUS_ENABLED' ) && NLH_FREEMIUS_ENABLED );
	}

	/**
	 * Whether a specific premium feature is licensed/active.
	 *
	 * Always false in core. The Pro plugin returns true by overriding the
	 * matching `nlh_pro_{feature}_enabled` filter once a license is valid.
	 *
	 * @param string $feature Feature key (see features()).
	 * @return bool
	 */
	public static function can( string $feature ): bool {
		$feature = sanitize_key( $feature );

		/**
		 * Filters whether a given premium feature is enabled.
		 *
		 * The Pro plugin hooks each feature's filter (e.g.
		 * `nlh_pro_bulk_fix_enabled`) and returns true when licensed. Core always
		 * yields false so the free build never runs premium paths.
		 *
		 * @since 1.3.0
		 * @param bool $enabled Whether the feature is active.
		 */
		return (bool) apply_filters( "nlh_pro_{$feature}_enabled", false );
	}

	/**
	 * Returns the upgrade/pricing URL, if the monetization layer set one.
	 *
	 * Never hardcodes a price or a checkout URL: the Freemius layer (or the Pro
	 * plugin) provides it through the filter. Empty string when unavailable.
	 *
	 * @return string
	 */
	public static function upgrade_url(): string {
		/**
		 * Filters the upgrade URL shown in upsell cards.
		 *
		 * @since 1.3.0
		 * @param string $url Upgrade/pricing URL (empty by default).
		 */
		$url = (string) apply_filters( 'nlh_pro_upgrade_url', NLH_UPGRADE_URL );

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Renders an upsell card for a feature — but only when the monetization layer
	 * is enabled and the feature is not already licensed.
	 *
	 * This is the single guard that satisfies the rule "upsell UI appears only
	 * when Freemius is enabled; never show disabled inputs". Every Pro touchpoint
	 * in core calls this instead of printing its own "Available in Pro" markup.
	 *
	 * @param string $feature Feature key (see features()).
	 * @param array  $args    Optional overrides: 'compact' (bool).
	 * @return void
	 */
	public static function upsell_card( string $feature, array $args = array() ): void {
		if ( ! self::is_enabled() || self::can( $feature ) ) {
			return;
		}

		$features = self::features();
		if ( ! isset( $features[ $feature ] ) ) {
			return;
		}

		$meta    = $features[ $feature ];
		$compact = ! empty( $args['compact'] );
		$url     = self::upgrade_url();
		?>
		<div class="nlh-upsell-card<?php echo $compact ? ' nlh-upsell-compact' : ''; ?>">
			<span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>" aria-hidden="true"></span>
			<div class="nlh-upsell-body">
				<strong class="nlh-upsell-title">
					<?php echo wp_kses( $meta['title'], array() ); ?>
					<span class="nlh-pro-badge"><?php esc_html_e( 'Pro', 'native-link-health' ); ?></span>
				</strong>
				<?php if ( ! $compact ) : ?>
					<p class="nlh-upsell-desc"><?php echo esc_html( $meta['description'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $url ) : ?>
				<a class="button button-primary nlh-upsell-cta" href="<?php echo esc_url( $url ); ?>">
					<?php esc_html_e( 'Learn more', 'native-link-health' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}
}
