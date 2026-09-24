<?php
/**
 * Tells a site that was blocking AI crawlers that AumViso no longer does it.
 *
 * Until 2.0.10 AumViso carried a copy of the AumCrawl crawler module. Removing
 * it means a site that had blocked GPTBot stops blocking it the moment this
 * update installs -- before the owner has read anything, because an automatic
 * update finishes first and the notice is only seen afterwards. Nothing can
 * close that gap, so the notice has to be unmissable and has to say the change
 * has already happened, not that it is coming.
 *
 * The rules themselves are untouched in `aumviso_crawl_settings`, and the
 * history is untouched in the three `aumviso_crawl_*` tables. Installing
 * AumCrawl imports both.
 *
 * @package AumViso
 */

defined( 'ABSPATH' ) || exit;

/**
 * A dismissible-but-not-self-clearing notice, for affected sites only.
 */
final class AumViso_Crawl_Moved {

	const SOURCE_OPTION  = 'aumviso_crawl_settings';
	const DISMISS_ACTION = 'aumviso_dismiss_crawl_moved';
	const DISMISS_META   = 'aumviso_crawl_moved_dismissed';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * Does this site need telling?
	 *
	 * Only a site that actually had rules: one that never blocked anything has
	 * lost nothing and does not need a notice about a feature it never used.
	 *
	 * @return bool
	 */
	private static function applies(): bool {
		if ( defined( 'AUMCRAWL_VERSION' ) ) {
			return false;
		}

		$saved = get_option( self::SOURCE_OPTION );

		return is_array( $saved ) && ! empty( $saved['blocked'] );
	}

	/**
	 * The blocked crawlers as a reader would name them.
	 *
	 * "GPTBot is not being blocked" is something a site owner can weigh in a
	 * second; "2 crawlers are not being blocked" sends him looking for which.
	 * He chose these names himself, so seeing them is what makes the notice
	 * actionable rather than merely alarming.
	 *
	 * The map is display only -- the registry that decides behaviour lives in
	 * AumCrawl now, and this deliberately does not try to be a second copy of
	 * it. Anything it does not recognise is printed as the stored slug, which
	 * is still the name the owner ticked.
	 *
	 * @param string[] $slugs Stored bot slugs.
	 * @return string
	 */
	private static function name_list( array $slugs ): string {
		$labels = array(
			'gptbot'             => 'GPTBot',
			'oai-searchbot'      => 'OAI-SearchBot',
			'chatgpt-user'       => 'ChatGPT-User',
			'claudebot'          => 'ClaudeBot',
			'perplexitybot'      => 'PerplexityBot',
			'ccbot'              => 'CCBot',
			'google-extended'    => 'Google-Extended',
			'bytespider'         => 'Bytespider',
			'amazonbot'          => 'Amazonbot',
			'applebot-extended'  => 'Applebot-Extended',
			'meta-externalagent' => 'meta-externalagent',
		);

		$names = array();
		foreach ( $slugs as $slug ) {
			$names[] = isset( $labels[ $slug ] ) ? $labels[ $slug ] : (string) $slug;
		}

		$extra = count( $names ) - 3;
		if ( $extra > 0 ) {
			$names = array_slice( $names, 0, 3 );
			/* translators: %d: how many further crawlers are not listed. */
			$names[] = sprintf( _n( 'and %d more', 'and %d more', $extra, 'aumviso' ), $extra );
		}

		return implode( ', ', $names );
	}

	/**
	 * Print it.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::applies() ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$saved   = (array) get_option( self::SOURCE_OPTION );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );

		echo '<div class="notice notice-warning"><p><strong>';
		esc_html_e( 'AumViso no longer blocks AI crawlers.', 'aumviso' );
		echo '</strong> ';
		printf(
			/* translators: %s: names of the crawlers, e.g. "GPTBot, CCBot and 2 more". */
			esc_html__( 'The crawlers you were blocking — %s — are not being blocked right now. Your rules are saved: install AumCrawl and it takes them over, along with your visit history.', 'aumviso' ),
			esc_html( self::name_list( (array) $saved['blocked'] ) )
		);
		echo '</p><p>';

		$action = self::install_action();
		if ( $action ) {
			echo '<a class="button button-primary" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a> ';
		}

		echo '<a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'aumviso' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Install/activate link for AumCrawl, from core so the wording and the
	 * state match what Plugins > Add New would show.
	 *
	 * @return array{url:string,label:string}|null
	 */
	private static function install_action(): ?array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file = 'aumcrawl/aumcrawl.php';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			if ( is_plugin_active( $file ) || ! current_user_can( 'activate_plugins' ) ) {
				return null;
			}
			return array(
				'url'   => wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ), 'activate-plugin_' . $file ),
				'label' => __( 'Activate AumCrawl', 'aumviso' ),
			);
		}

		if ( ! current_user_can( 'install_plugins' ) ) {
			return null;
		}

		return array(
			'url'   => wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=aumcrawl' ), 'install-plugin_aumcrawl' ),
			'label' => __( 'Install AumCrawl', 'aumviso' ),
		);
	}

	/**
	 * Remember that this user closed it. Per user, and permanent: a notice
	 * that comes back after being dismissed is the same as one that cannot be
	 * dismissed.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::DISMISS_ACTION ) ) {
			wp_die( esc_html__( 'Permission denied.', 'aumviso' ) );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
