<?php
/**
 * One answer to "should this request emit SEO head tags at all?"
 *
 * ══ 🔴 为什么是一个类，而不是两处各判一次 ═══════════════════════════
 *
 * 2026-09-30 发现的泄漏：WooCommerce 的 coming soon 开着时，AumViso 照样把
 * 真实的 canonical、meta description（内容里的一整句话）和 og:title 发给
 * 匿名访客。门控挡住了页面，没挡住我们贴在 <head> 上的东西。
 *
 * 根因不是漏了某一处，是**两处各自发 head 而没有一处共同的判断**：
 * MetaManager 在 wp_head:1，OpenGraph 在 wp_head:2，将来还会有第三个。
 * 主题那侧的门控清单里从来没有 AumViso 后来加的这三样——**一个「所有会
 * 泄漏的东西」的问题，被实现成一张「我想到的会泄漏的东西」的名单。**
 *
 * 所以这里只有一个答案，谁发 head 谁问它。
 *
 * ══ ⚠️ 为什么不调 Woo 的 ComingSoonHelper ═══════════════════════════
 *
 * `Automattic\WooCommerce\Internal\ComingSoon\ComingSoonHelper::is_current_page_coming_soon()`
 * 看起来正是要问的那句话，但它在 `Internal` 命名空间下——Woo 明确说可以
 * 随时改。哪天它改了，我们**静默失效，而且失效方向是「又开始泄漏」**，
 * 不会有任何一端报错。
 *
 * 用的是 `woocommerce_coming_soon_exclude`（公开过滤器，@since 9.1.0）：
 * Woo 只在**已经判定这一页要门控之后**才触发它（ComingSoonRequestHandler
 * 里先 `is_current_page_coming_soon()` 提前返回，再 apply_filters）。所以
 * 「它为本次请求跑过」本身就是一个精确到页的信号，而且全程只碰公开 API。
 * 时序也对：template_include 在 wp_head 之前。
 *
 * 退路是两个 option（Woo < 9.1 没有那个过滤器）。那条退路按站判断、不按页，
 * 所以只在「整站门控」时才敢答 true——**宁可少挡，不可在正常页面上乱挡**。
 *
 * @package AumViso
 */

defined( 'ABSPATH' ) || exit;

class AumViso_HeadGate {

	/** @var bool|null Woo 是否为本次请求判定了门控；null = 那个过滤器没跑过。 */
	private static $woo_screened = null;

	public static function init(): void {
		add_filter(
			'woocommerce_coming_soon_exclude',
			static function ( $excluded ) {
				/*
				 * 走到这里 = Woo 已经判定本页要门控。$excluded 为 true 表示
				 * 有人（包括 Woo 自己的 private link）把它放行了，那就不算门控。
				 * PHP_INT_MAX 是为了看到所有放行者之后的最终值。
				 */
				self::$woo_screened = ! $excluded;
				/*
				 * ⚠️ Woo 的 private link 判断排在这个过滤器**后面**
				 * （ComingSoonRequestHandler 里 :170 起）。所以拿着分享链接、
				 * 其实看得见内容的访客，在这里也会被记成「挡住了」，元数据会缺。
				 * 失效方向是「预览页少了 og」而不是「门控页漏了标题」——
				 * 两害里选了这一边，不是没看见。
				 */
				return $excluded;
			},
			PHP_INT_MAX
		);
	}

	/**
	 * 这次请求该不该发我们的 SEO head 标签。
	 *
	 * @param string $context 调用方，只为过滤器的使用者能区分来源。
	 */
	public static function should_output( string $context = '' ): bool {
		$out = ! self::is_screened();

		/**
		 * 最终决定权。主题、其它插件都可以在这里说「这次别发」。
		 *
		 * 名字说的是它控制什么，不是这一次为什么——将来还会有别的理由
		 * （密码保护、预览、维护模式），不该每来一个就加一个过滤器。
		 *
		 * @param bool   $out     默认值。
		 * @param string $context 'meta' | 'og' | …
		 */
		return (bool) apply_filters( 'aumviso_output_head_meta', $out, $context );
	}

	private static function is_screened(): bool {
		if ( null !== self::$woo_screened ) {
			return self::$woo_screened;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		/* 退路：只认「整站门控」。仅门店门控时这里答 false，宁可少挡。 */
		return 'yes' === get_option( 'woocommerce_coming_soon' )
			&& 'yes' !== get_option( 'woocommerce_store_pages_only' );
	}
}
