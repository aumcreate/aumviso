<?php
/**
 * The crawler registry.
 *
 * Everything else in this plugin is plumbing; this file is the substance. The
 * grouping matters more than the list: crawlers are sorted by what the site
 * gets back, not by who operates them.
 *
 * @package AumCrawl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known crawlers, grouped by what they give back.
 */
class AumViso_Crawl_Bots {

	/**
	 * Fetches a page because a person asked a question, and cites the source.
	 * Blocking these costs real referral traffic.
	 */
	const GROUP_CITES = 'cites';

	/**
	 * Takes content for training or for resale to third parties. Nothing comes
	 * back to the site: no link, no citation, no visit.
	 */
	const GROUP_TAKES = 'takes';

	/**
	 * Blocking these breaks the site in ways owners rarely intend, so the
	 * plugin records them and deliberately offers no switch.
	 */
	const GROUP_ESSENTIAL = 'essential';

	/**
	 * Full registry.
	 *
	 * `ua` holds the case-insensitive needles matched against the request's
	 * User-Agent. `robots` is the token written into robots.txt, which is not
	 * always the same string -- Google-Extended is a robots.txt directive that
	 * never appears as a User-Agent at all.
	 *
	 * @return array
	 */
	public static function all() {
		$bots = array(

			// ---- Ask a question, get an answer with your link in it ----
			'oai-searchbot'    => array(
				'label'  => 'OAI-SearchBot',
				'vendor' => 'OpenAI',
				'group'  => self::GROUP_CITES,
				'ua'     => array( 'OAI-SearchBot' ),
				'robots' => 'OAI-SearchBot',
				'note'   => __( 'Builds the index behind ChatGPT search. Answers link back to you.', 'aumviso' ),
				'verify' => array( 'openai.com' ),
			),
			'chatgpt-user'     => array(
				'label'  => 'ChatGPT-User',
				'vendor' => 'OpenAI',
				'group'  => self::GROUP_CITES,
				'ua'     => array( 'ChatGPT-User' ),
				'robots' => 'ChatGPT-User',
				'note'   => __( 'Fetches a page because someone in ChatGPT clicked through to it.', 'aumviso' ),
				'verify' => array( 'openai.com' ),
			),
			'perplexitybot'    => array(
				'label'  => 'PerplexityBot',
				'vendor' => 'Perplexity',
				'group'  => self::GROUP_CITES,
				'ua'     => array( 'PerplexityBot' ),
				'robots' => 'PerplexityBot',
				'note'   => __( 'Indexes for Perplexity, which shows sources under every answer.', 'aumviso' ),
				'verify' => array( 'perplexity.ai', 'perplexity.com' ),
			),
			'perplexity-user'  => array(
				'label'  => 'Perplexity-User',
				'vendor' => 'Perplexity',
				'group'  => self::GROUP_CITES,
				'ua'     => array( 'Perplexity-User' ),
				'robots' => 'Perplexity-User',
				'note'   => __( 'A person following a citation out of a Perplexity answer.', 'aumviso' ),
				'verify' => array( 'perplexity.ai', 'perplexity.com' ),
			),
			'claude-user'      => array(
				'label'  => 'Claude-User',
				'vendor' => 'Anthropic',
				'group'  => self::GROUP_CITES,
				'ua'     => array( 'Claude-User' ),
				'robots' => 'Claude-User',
				'note'   => __( 'Fetches a page on behalf of someone chatting with Claude.', 'aumviso' ),
				'verify' => array( 'anthropic.com' ),
			),
			'claude-searchbot' => array(
				'label'  => 'Claude-SearchBot',
				'vendor' => 'Anthropic',
				'group'  => self::GROUP_CITES,
				'ua'     => array( 'Claude-SearchBot' ),
				'robots' => 'Claude-SearchBot',
				'note'   => __( 'Builds the search index Claude cites from.', 'aumviso' ),
				'verify' => array( 'anthropic.com' ),
			),
			'google-extended'  => array(
				'label'  => 'Google-Extended',
				'vendor' => 'Google',
				'group'  => self::GROUP_CITES,
				// Listed so that anything sending this as a User-Agent is not
				// mistaken for Googlebot. Google itself never does.
				'ua'     => array( 'Google-Extended' ),
				'robots' => 'Google-Extended',
				'note'   => __( 'A robots.txt token, not a crawler. Controls whether Gemini and AI Overviews may ground answers in your content. Blocking it does not affect normal Google Search ranking.', 'aumviso' ),
				'verify' => array(),
			),

			// ---- Take the content, send nothing back ----
			'gptbot'           => array(
				'label'  => 'GPTBot',
				'vendor' => 'OpenAI',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'GPTBot' ),
				'robots' => 'GPTBot',
				'note'   => __( 'Collects content to train OpenAI models. Separate from ChatGPT search.', 'aumviso' ),
				'verify' => array( 'openai.com' ),
			),
			'claudebot'        => array(
				'label'  => 'ClaudeBot',
				'vendor' => 'Anthropic',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'ClaudeBot', 'anthropic-ai' ),
				'robots' => 'ClaudeBot',
				'note'   => __( 'Collects content to train Anthropic models.', 'aumviso' ),
				'verify' => array( 'anthropic.com' ),
			),
			'ccbot'            => array(
				'label'  => 'CCBot',
				'vendor' => 'Common Crawl',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'CCBot' ),
				'robots' => 'CCBot',
				'note'   => __( 'Builds the public Common Crawl archive, which nearly every model vendor trains on.', 'aumviso' ),
				'verify' => array(),
			),
			'bytespider'       => array(
				'label'  => 'Bytespider',
				'vendor' => 'ByteDance',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'Bytespider' ),
				'robots' => 'Bytespider',
				'note'   => __( 'ByteDance training crawler. Widely reported as heavy-handed about crawl rate.', 'aumviso' ),
				'verify' => array(),
			),
			'applebot-extended' => array(
				'label'  => 'Applebot-Extended',
				'vendor' => 'Apple',
				'group'  => self::GROUP_TAKES,
				// Apple crawls as plain "Applebot" and reads this token from
				// robots.txt; the needle only stops a spoofed agent being
				// filed under the essential Applebot row.
				'ua'     => array( 'Applebot-Extended' ),
				'robots' => 'Applebot-Extended',
				'note'   => __( 'A robots.txt token that opts your content out of Apple model training. Does not affect Siri or Spotlight.', 'aumviso' ),
				'verify' => array(),
			),
			'meta-externalagent' => array(
				'label'  => 'Meta-ExternalAgent',
				'vendor' => 'Meta',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'Meta-ExternalAgent', 'meta-externalagent' ),
				'robots' => 'Meta-ExternalAgent',
				'note'   => __( 'Collects content for Meta AI training.', 'aumviso' ),
				'verify' => array(),
			),
			'amazonbot'        => array(
				'label'  => 'Amazonbot',
				'vendor' => 'Amazon',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'Amazonbot' ),
				'robots' => 'Amazonbot',
				'note'   => __( 'Feeds Amazon services including model training.', 'aumviso' ),
				'verify' => array(),
			),
			'diffbot'          => array(
				'label'  => 'Diffbot',
				'vendor' => 'Diffbot',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'Diffbot' ),
				'robots' => 'Diffbot',
				'note'   => __( 'Extracts structured data and resells it as a commercial dataset.', 'aumviso' ),
				'verify' => array(),
			),
			'omgilibot'        => array(
				'label'  => 'omgilibot',
				'vendor' => 'Webz.io',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'omgilibot', 'omgili' ),
				'robots' => 'omgilibot',
				'note'   => __( 'Collects web data sold on as a training corpus.', 'aumviso' ),
				'verify' => array(),
			),
			'ahrefsbot'        => array(
				'label'  => 'AhrefsBot',
				'vendor' => 'Ahrefs',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'AhrefsBot' ),
				'robots' => 'AhrefsBot',
				'note'   => __( 'Maps your backlinks so Ahrefs subscribers, including competitors, can study them.', 'aumviso' ),
				'verify' => array( 'ahrefs.com' ),
			),
			'semrushbot'       => array(
				'label'  => 'SemrushBot',
				'vendor' => 'Semrush',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'SemrushBot' ),
				'robots' => 'SemrushBot',
				'note'   => __( 'Same idea as AhrefsBot, for the Semrush index.', 'aumviso' ),
				'verify' => array( 'semrush.com' ),
			),
			'mj12bot'          => array(
				'label'  => 'MJ12bot',
				'vendor' => 'Majestic',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'MJ12bot' ),
				'robots' => 'MJ12bot',
				'note'   => __( 'Majestic backlink crawler.', 'aumviso' ),
				'verify' => array(),
			),
			'dotbot'           => array(
				'label'  => 'DotBot',
				'vendor' => 'Moz',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'DotBot' ),
				'robots' => 'DotBot',
				'note'   => __( 'Moz backlink crawler.', 'aumviso' ),
				'verify' => array(),
			),
			'dataforseobot'    => array(
				'label'  => 'DataForSeoBot',
				'vendor' => 'DataForSEO',
				'group'  => self::GROUP_TAKES,
				'ua'     => array( 'DataForSeoBot' ),
				'robots' => 'DataForSeoBot',
				'note'   => __( 'Collects SERP and site data sold through an API.', 'aumviso' ),
				'verify' => array(),
			),

			// ---- Recorded, never switchable ----
			'googlebot'        => array(
				'label'  => 'Googlebot',
				'vendor' => 'Google',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'Googlebot' ),
				'robots' => 'Googlebot',
				'note'   => __( 'Google Search. Blocking it removes you from Google.', 'aumviso' ),
				'verify' => array( 'googlebot.com', 'google.com' ),
			),
			'bingbot'          => array(
				'label'  => 'Bingbot',
				'vendor' => 'Microsoft',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'bingbot' ),
				'robots' => 'bingbot',
				'note'   => __( 'Bing Search, which also grounds Microsoft Copilot.', 'aumviso' ),
				'verify' => array( 'search.msn.com' ),
			),
			/*
			 * ---- Search engines outside the English-speaking web ----
			 *
			 * Added 2026-09-03. The list until then was Google, Bing and DuckDuckGo: a site owner in China,
			 * Russia or Korea opened this report and saw nothing, while the crawler that actually indexes
			 * them came every day. Baidu alone is most of search in its market.
			 *
			 * ⚠️ `verify` is filled **only where the vendor documents forward-confirmed reverse DNS**. Baidu
			 * publishes `.baidu.com` / `.baidu.jp` and Yandex publishes three domains; for the rest no such
			 * guarantee was found, so the field stays empty and the check reports `unknown`. That is the
			 * whole point of this feature — a wrong hostname here would brand honest crawlers as forgeries,
			 * which is worse than admitting we cannot tell.
			 */
			'baiduspider'      => array(
				'label'  => 'Baiduspider',
				'vendor' => 'Baidu',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'Baiduspider' ),
				'robots' => 'Baiduspider',
				'note'   => __( 'Baidu Search. Blocking it removes you from search in mainland China.', 'aumviso' ),
				// Both suffixes are documented; .baidu.jp serves Baidu's Japanese infrastructure and
				// omitting it would report those visits as forged.
				'verify' => array( 'baidu.com', 'baidu.jp' ),
			),
			'yandexbot'        => array(
				'label'  => 'YandexBot',
				'vendor' => 'Yandex',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'YandexBot' ),
				'robots' => 'Yandex',
				'note'   => __( 'Yandex Search — Russia, and much of the Russian-speaking web.', 'aumviso' ),
				'verify' => array( 'yandex.ru', 'yandex.net', 'yandex.com' ),
			),
			'sogou'            => array(
				'label'  => 'Sogou web spider',
				'vendor' => 'Sogou',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'Sogou web spider' ),
				'robots' => 'Sogou web spider',
				'note'   => __( 'Sogou Search, mainland China.', 'aumviso' ),
				'verify' => array(),
			),
			'360spider'        => array(
				'label'  => '360Spider',
				'vendor' => 'Qihoo 360',
				'group'  => self::GROUP_ESSENTIAL,
				// One crawler, two names in the wild: the string carries both "360Spider" and
				// "HaosouSpider" depending on the property it is crawling for.
				'ua'     => array( '360Spider', 'HaosouSpider' ),
				'robots' => '360Spider',
				'note'   => __( 'Qihoo 360 / Haosou Search, mainland China.', 'aumviso' ),
				'verify' => array(),
			),
			'yisouspider'      => array(
				'label'  => 'YisouSpider',
				'vendor' => 'Alibaba',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'YisouSpider' ),
				'robots' => 'YisouSpider',
				'note'   => __( 'Shenma Search — the default on UC Browser, and mostly mobile.', 'aumviso' ),
				'verify' => array(),
			),
			'petalbot'         => array(
				'label'  => 'PetalBot',
				'vendor' => 'Huawei',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'PetalBot' ),
				'robots' => 'PetalBot',
				'note'   => __( 'Petal Search, the search built into Huawei devices.', 'aumviso' ),
				'verify' => array(),
			),
			'yeti'             => array(
				'label'  => 'Yeti',
				'vendor' => 'Naver',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'Yeti' ),
				'robots' => 'Yeti',
				'note'   => __( 'Naver Search, South Korea.', 'aumviso' ),
				'verify' => array(),
			),
			'duckduckbot'      => array(
				'label'  => 'DuckDuckBot',
				'vendor' => 'DuckDuckGo',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'DuckDuckBot' ),
				'robots' => 'DuckDuckBot',
				'note'   => __( 'DuckDuckGo Search.', 'aumviso' ),
				'verify' => array(),
			),
			'applebot'         => array(
				'label'  => 'Applebot',
				'vendor' => 'Apple',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'Applebot' ),
				'robots' => 'Applebot',
				'note'   => __( 'Siri and Spotlight suggestions. Separate from Applebot-Extended.', 'aumviso' ),
				'verify' => array( 'applebot.apple.com' ),
			),
			'facebookexternalhit' => array(
				'label'  => 'facebookexternalhit',
				'vendor' => 'Meta',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'facebookexternalhit' ),
				'robots' => 'facebookexternalhit',
				'note'   => __( 'Builds the preview card when someone shares your link. Blocking it means shares appear without a title or image.', 'aumviso' ),
				'verify' => array(),
			),
			'twitterbot'       => array(
				'label'  => 'Twitterbot',
				'vendor' => 'X',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'Twitterbot' ),
				'robots' => 'Twitterbot',
				'note'   => __( 'Link preview cards on X.', 'aumviso' ),
				'verify' => array(),
			),
			'linkedinbot'      => array(
				'label'  => 'LinkedInBot',
				'vendor' => 'LinkedIn',
				'group'  => self::GROUP_ESSENTIAL,
				'ua'     => array( 'LinkedInBot' ),
				'robots' => 'LinkedInBot',
				'note'   => __( 'Link preview cards on LinkedIn.', 'aumviso' ),
				'verify' => array(),
			),
		);

		/**
		 * Filters the crawler registry.
		 *
		 * New crawlers appear faster than plugin releases, so a site can add
		 * one without waiting for an update.
		 *
		 * @param array $bots Registry keyed by slug.
		 */
		return apply_filters( 'aumviso_crawl_bots', $bots );
	}

	/**
	 * Bots in one group.
	 *
	 * @param string $group One of the GROUP_* constants.
	 * @return array
	 */
	public static function in_group( $group ) {
		return array_filter(
			self::all(),
			static function ( $bot ) use ( $group ) {
				return $bot['group'] === $group;
			}
		);
	}

	/**
	 * Can the site owner switch this bot off?
	 *
	 * Essential crawlers are deliberately not switchable. If someone truly
	 * wants Googlebot gone, that belongs in their own robots.txt, not behind a
	 * toggle they can hit by accident.
	 *
	 * @param string $slug Bot slug.
	 * @return bool
	 */
	public static function is_switchable( $slug ) {
		$bots = self::all();

		return isset( $bots[ $slug ] ) && self::GROUP_ESSENTIAL !== $bots[ $slug ]['group'];
	}

	/**
	 * Identify a User-Agent string.
	 *
	 * Longest needle first, so "Applebot-Extended" is never mistaken for
	 * "Applebot" and "Claude-SearchBot" never collapses into "ClaudeBot".
	 *
	 * @param string $ua Raw User-Agent header.
	 * @return string Bot slug, or '' when nothing matched.
	 */
	public static function identify( $ua ) {
		if ( '' === $ua ) {
			return '';
		}

		$needles = array();

		foreach ( self::all() as $slug => $bot ) {
			foreach ( $bot['ua'] as $needle ) {
				$needles[ $needle ] = $slug;
			}
		}

		uksort(
			$needles,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $needles as $needle => $slug ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $slug;
			}
		}

		return '';
	}
}
