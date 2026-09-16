<?php
/**
 * AumViso Uninstall
 *
 * Runs when the plugin is deleted (not just deactivated).
 * Removes all plugin options and database tables.
 *
 * @package AumViso
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ------------------------------------
// Delete all plugin options
// ------------------------------------
$options = [
    'aumviso_db_version',
    'aumviso_data_version',
    'aumviso_title_template',
    'aumviso_desc_template',
    'aumviso_og_image_fallback',
    'aumviso_enabled_post_types',
    'aumviso_enabled_taxonomies',
    'aumviso_sitemap_enabled',
    'aumviso_sitemap_post_types',
    'aumviso_sitemap_taxonomies',
    'aumviso_sitemap_images',
    'aumviso_schema_org_name',
    'aumviso_schema_org_url',
    'aumviso_schema_org_logo',
    'aumviso_schema_org_type',
    'aumviso_social_twitter',
    'aumviso_social_facebook',
    'aumviso_ai_provider',
    'aumviso_ai_key',
    'aumviso_ai_model',
    'aumviso_ilinks_enabled',
    'aumviso_ilinks_post_types',
    'aumviso_related_auto_append',
    'aumviso_lb_address',
    'aumviso_lb_phone',
    'aumviso_lb_email',
    'aumviso_lb_geo_lat',
    'aumviso_lb_geo_lng',
    'aumviso_lb_price_range',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ------------------------------------
// Drop custom database table
// ------------------------------------
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aumviso_link_keywords" );
