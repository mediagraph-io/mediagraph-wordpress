<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's own options. Imported attachments and the provenance
 * meta on them are deliberately left alone: those are the user's media files
 * and deleting them would break published posts.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mediagraph_options = array(
	'mediagraph_access_token',
	'mediagraph_refresh_token',
	'mediagraph_token_expires_at',
	'mediagraph_token_created_at',
	'mediagraph_token_scope',
	'mediagraph_user_id',
	'mediagraph_user_name',
	'mediagraph_user_email',
	'mediagraph_organization_id',
	'mediagraph_organization_name',
	'mediagraph_organization_slug',
	'mediagraph_membership_role',
	'mediagraph_api_base_url',
	'mediagraph_platform',
	'mediagraph_version',
	'mediagraph_activated_at',
	'mediagraph_cache_version',
	// Written by 1.x.
	'mediagraph_picker_version',
	'mediagraph_picker_activated',
	'mediagraph_available_organizations',
);

foreach ( $mediagraph_options as $mediagraph_option ) {
	delete_option( $mediagraph_option );
}
