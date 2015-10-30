<?php

include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
include_once ABSPATH . 'wp-admin/includes/file.php';

class Jetpack_JSON_API_Plugins_Install_Endpoint extends Jetpack_JSON_API_Plugins_Endpoint {

	// POST /sites/%s/plugins/%s/install
	protected $needed_capabilities = 'install_plugins';
	protected $action              = 'install';
	protected $download_links      = array();

	protected function install() {
		foreach ( $this->plugins as $index => $slug ) {

			// lets not translate the error messages
			add_filter( 'gettext', array( $this, 'keep_original_text' ), 100, 2 );

			$skin      = new Jetpack_Automatic_Plugin_Install_Skin();
			$upgrader  = new Plugin_Upgrader( $skin );

			$result = $upgrader->install( $this->download_links[ $slug ] );
			remove_filter( 'gettext', array( $this, 'keep_original_text' ), 100 );

			if ( ! $this->bulk && is_wp_error( $result ) ) {
				return $result;
			}

			$plugin = self::get_plugin_id_by_slug( $slug );
			$error_reason = 'install_error';
			if ( ! $plugin ) {
				$error = $this->log[ $slug ]['error'] = __( 'There was an error installing your plugin', 'jetpack' );
			}

			if ( ! $this->bulk && ! $result ) {
				$list_messages = $upgrader->skin->get_upgrade_messages();
				$message = ( ! empty( $list_messages ) && is_array( $list_messages ) ) ?
					$list_messages[ count( $list_messages ) - 1 ] : null;
				$error_reason = $this->get_error_reason( $message, $upgrader->skin->get_upgrade_messages() );

				$error = $this->log[ $slug ]['error'] = $message ? $message : __( 'An unknown error occurred during installation123' , 'jetpack' );
			}

			$this->log[ $plugin ][] = $upgrader->skin->get_upgrade_messages();
		}

		if ( ! $this->bulk && isset( $error ) ) {
			return new WP_Error( $error_reason, $this->log[ $slug ]['error'], 400 );
		}

		// replace the slug with the actual plugin id
		$this->plugins[ $index ] = $plugin;

		return true;
	}

	protected function get_error_reason( $message, $messages ) {
		$message = substr( $message,0, 16 )  == 'Could not create' ? 'Could not create' : $message;
		switch( $message ) {
			case 'Could not access filesystem.':
				return 'install_error_fs_unavailable';
				break;

			case 'Could not create':
				return 'install_error_filesystem_full';

			case 'Plugin install failed.':
				end($messages);
				$previous_message = prev( $messages );
				$previous_message = substr( $previous_message, 0, 33 )  == 'Destination folder already exists' ? 'Destination folder already exists' : $previous_message ;
				switch( $previous_message ) {
					case 'Destination folder already exists':
						return 'install_error_folder_exists';
						break;

					default:
						return 'install_error';
						break;
				}
				break;

			case 'Install package not available.':
				return 'install_error_package_not_available';
				break;

			default:
				return 'install_error';
				break;

		}
	}
	/**
	 * Keep the original text this will help the error messages return the right error_reason.
	 */
	function keep_original_text( $translations, $text ) {
		return $text;
	}

	protected function validate_plugins() {
		if ( empty( $this->plugins ) || ! is_array( $this->plugins ) ) {
			return new WP_Error( 'missing_plugins', __( 'No plugins found.', 'jetpack' ) );
		}
		foreach( $this->plugins as $index => $slug ) {

			// make sure it is not already installed
			if ( self::get_plugin_id_by_slug( $slug ) ) {
				return new WP_Error( 'plugin_already_installed', __( 'The plugin is already installed', 'jetpack' ) );
			}

			$response    = wp_remote_get( "http://api.wordpress.org/plugins/info/1.0/$slug" );
			$plugin_data = unserialize( $response['body'] );
			if ( is_wp_error( $plugin_data ) ) {
				return $plugin_data;
			}

			$this->download_links[ $slug ] = $plugin_data->download_link;

		}
		return true;
	}

	protected static function get_plugin_id_by_slug( $slug ) {
		$plugins = get_plugins();
		if( ! is_array( $plugins ) ) {
			return false;
		}
		foreach( $plugins as $id => $plugin_data ) {
			if( strpos( $id, $slug ) !== false ) {
				return $id;
			}
		}
		return false;
	}
}
/**
 * Allows us to capture that the site doesn't have proper file system access.
 * In order to update the plugin.
 */
class Jetpack_Automatic_Plugin_Install_Skin extends Automatic_Upgrader_Skin {
	/**
	 * @param WP_Upgrader $upgrader
	 */
	public function set_upgrader( &$upgrader ) {
		parent::set_upgrader( $upgrader );
		// check if we even have permission to
		$res = $upgrader->fs_connect( array( WP_CONTENT_DIR, WP_PLUGIN_DIR ) );
		if( !$res ) {
			$this->messages[] = 'Could not access filesystem.';
		}

	}
}
