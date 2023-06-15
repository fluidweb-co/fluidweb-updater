<?php
/**
 * Plugin Updater.
 *
 * Allow activation and auto updates for plugins hosted with License Manager for WooCommerce.
 */
if ( ! class_exists( 'Fluidweb_PluginLicenseManager' ) ) {
	class Fluidweb_PluginLicenseManager {
		private $slug;
		private $plugin_data;
		private $api_update_called = false;

		private $plugin_file;
		private $product_id;
		private $api_url;
		private $customer_key;
		private $customer_secret;
		private $license_key;
		private $activate_option;



		/**
		 * Construct a new instance of plugin updater
		 *
		 * @param		string		$plugin_file			Relative path to plugin file.
		 * @param		string		$product_id				ID of the product on the license manager website.
		 * @param		string		$customer_key			License Manager API consumer key.
		 * @param		string		$customer_secret		License Manager API consumer secret.
		 * @param		string		$activate_option		License Manager API consumer secret.
		 * @param		string		$license_key		License key.
		 */
		function __construct( $plugin_file, $product_id, $api_url, $customer_key, $customer_secret, $activate_option, $license_key ) {	
			// Set variables
			$this->plugin_file = $plugin_file;
			$this->product_id = $product_id;
			$this->api_url = $api_url;
			$this->customer_key = $customer_key;
			$this->customer_secret = $customer_secret;
			$this->activate_option = $activate_option;
			$this->license_key = $license_key;
		}



		/**
		 * Initialize hooks.
		 */
		public function init_plugin_update_hooks() {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'set_transient' ) );
			add_filter( 'plugins_api', array( $this, 'set_plugin_info' ), 10, 3 );
			add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
		}



		/**
		 * Get information regarding the current plugin version
		 */
		private function init_plugin_data() {
			$this->slug = plugin_basename( $this->plugin_file );
			$this->plugin_data = get_plugin_data( $this->plugin_file );
		}



		/**
		 * Call API for data
		 */
		private function call_api( $url ) {
			$process = curl_init( $url );
			curl_setopt( $process, CURLOPT_USERPWD, sprintf( '%s:%s', $this->customer_key, $this->customer_secret ) );
			curl_setopt( $process, CURLOPT_RETURNTRANSFER, TRUE );
			$response = curl_exec( $process );
			curl_close( $process );
			return $response;
		}



		/**
		 * Get information regarding plugin releases from repository
		 */
		public function get_release_info( $force_check = false ) {	
			$transient_name = $this->slug . '_plugin_info';

			// Check transient but allow for $force_check to override
			if( ! $force_check ) {
				$transient = get_transient( $transient_name );
				if( $transient !== false ) {
					return $transient;
				}
			}

			$url = untrailingslashit( $this->api_url ) . '/wp-json/lmfwc/v2/products/update/' . $this->product_id;

			$response = $this->call_api( $url );
			$response = json_decode( $response );

			// Set flag for API called
			$this->api_update_called = true;

			// Update transient
			set_transient( $transient_name, $response, DAY_IN_SECONDS );

			return $response;
		}



		/**
		 * Get the plugin license information from the license manager server.
		 */
		public function get_info( $license_key = null ) {
			// Defaults to the instance license key.
			if ( ! $license_key ) {
				$license_key = $this->license_key;
			}
	
			$url = untrailingslashit( $this->api_url ) . '/wp-json/lmfwc/v2/licenses/' . $license_key;

			$response = $this->call_api( $url );

			if ( $response ) {
				$data = json_decode( $response );
				return $data;
			}

			$error_response = new \stdClass();
			$error_response->code = 'fwplm_rest_connection_error';
			$error_response->message = sprintf( 'Couldn\'t connect to the license server (%s). Try again later.', $this->api_url );
			return $error_response;
		}



		/**
		 * Validate the plugin license key against the license manager server.
		 */
		public function validate( $license_key = null ) {
			// Defaults to the instance license key.
			if ( ! $license_key ) {
				$license_key = $this->license_key;
			}
	
			$url = untrailingslashit( $this->api_url ) . '/wp-json/lmfwc/v2/licenses/validate/' . $license_key;

			$response = $this->call_api( $url );

			if ( $response ) {
				$data = json_decode( $response );
				return $data;
			}

			$error_response = new \stdClass();
			$error_response->code = 'fwplm_rest_connection_error'; 
			$error_response->message = sprintf( 'Couldn\'t connect to the license server (%s). Try again later.', $this->api_url );
			return $error_response;
		}



		/**
		 * Activate the plugin license, also validate against the license manager server.
		 */
		public function activate( $license_key = null ) {
			// Defaults to the instance license key.
			if ( ! $license_key ) {
				$license_key = $this->license_key;
			}

			if ( ! $license_key ) {
				$error_response = new \stdClass();
				$error_response->code = 'fwplm_missing_license_key'; 
				$error_response->message = 'Missing the license key. Please provide a valid license key and try again.';
				return $error_response;
			}
	
			$url = untrailingslashit( $this->api_url ) . '/wp-json/lmfwc/v2/licenses/activate/' . $license_key;
	
			$response = $this->call_api( $url );
	
			if ( $response ) {
				$data = json_decode( $response );

				if ( $data && isset( $data->success ) && $data->success ) {
					update_option( $this->activate_option, 'yes' );
					return $data;
				}
				else if ( $data && isset( $data->message ) && $data->message ) {
					$error_response = new \stdClass();
					$error_response->code = isset( $data->code ) ? $data->code : 'fwplm_generic_error';
					$error_response->message = $data->message;
					return $error_response;
				}
			}

			$error_response = new \stdClass();
			$error_response->code = 'fwplm_rest_connection_error'; 
			$error_response->message = sprintf( 'Couldn\'t connect to the license server (%s). Try again later.', $this->api_url );
			return $error_response;
		}



		/**
		 * Push in plugin version information to get the update notification
		 */
		public function set_transient( $transient ) {
			// Bail if no response (error)
			if( ! isset( $transient->response ) ) {
				return $transient;
			}

			// Get flag for `force-check` (force check only once)
			$force_check = ( ! $this->api_update_called ) ? ! empty( $_GET['force-check'] ) : false;
			
			// Get plugin & latest release information
			$this->init_plugin_data();
			$release_info = $this->get_release_info( $force_check );

			// Nothing found.
			if ( ! $release_info || ! property_exists( $release_info, 'success' ) || ! $release_info->success || ! isset( $release_info->data ) ) { return $transient; }

			// Check the versions if we need to do an update ( $repo_version > current version )
			$doUpdate = version_compare( $release_info->data->version, $this->plugin_data["Version"] );
	
			// Update the transient to include our updated plugin data
			if ( $doUpdate == 1 ) {
				$obj = new \stdClass();
				$obj->slug = $this->slug;
				$obj->new_version = $release_info->data->version;
				$obj->tested = $release_info->data->tested;
				$obj->url = $this->plugin_data["PluginURI"];
				$obj->package = str_replace( '{license_key}', $this->license_key, $release_info->data->package );

				// Copy icons from api_result
				if ( $release_info->data->icons ) {
					$obj->icons = array();
					foreach ( $release_info->data->icons as $key => $value ) {
						$obj->icons[ $key ] = $value;
					}
				}
				
				$transient->response[ $this->slug ] = $obj;
			}

			return $transient;
		}



		/**
		 * Push in plugin version information to display in the details lightbox
		 */
		public function set_plugin_info( $res, $action, $args ) {

			// Only for 'plugin_information' action
			if( 'plugin_information' !== $action ) { return $res; }

			// Get plugin data
			$this->init_plugin_data();

			// Only for 'plugin_information' action
			if( ! $this->plugin_data || ! is_array( $this->plugin_data ) ) { return $res; }

			// Get latest release information
			$release_info = $this->get_release_info();

			// Bail if new plugin info is not available
			if ( ! $release_info || ! property_exists( $release_info, 'success' ) || ! $release_info->success || ! isset( $release_info->data ) ) { return $res; }

			if ( $args->slug == $this->slug ) {
				$res = new \stdClass();

				$res->slug = $this->slug;
				$res->name = $this->plugin_data['Name'];
				$res->author = $this->plugin_data['Author'];
				$res->homepage = $this->plugin_data['PluginURI'];

				// Copy values from release info
				foreach ( $release_info->data as $key => $value ) {
					// Skip sections
					if ( 'sections' == $key ) { continue; }

					$res->$key = $value;
				}

				// Copy icons from release info
				if ( $release_info->data->icons ) {
					$res->icons = array();
					foreach ( $release_info->data->icons as $key => $value ) {
						$res->icons[ $key ] = $value;
					}
				}

				// Copy banners from release info
				if ( $release_info->data->banners ) {
					$res->banners = array();
					foreach ( $release_info->data->banners as $key => $value ) {
						$res->banners[ $key ] = $value;
					}
				}

				// Copy sections from release info
				if ( $release_info->data->sections ) {
					$res->sections = array();
					foreach ( $release_info->data->sections as $key => $value ) {
						$res->sections[ $key ] = $value;
					}
				}
			}

			return $res;
		}



		/**
		 * Perform additional actions to successfully install our plugin
		 */
		public function post_install( $true, $hook_extra, $result ) {
			// Get plugin information
			$this->init_plugin_data();

			// Remember if our plugin was previously activated
			$wasActivated = is_plugin_active( $this->slug );

			global $wp_filesystem;
			$pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->slug );
			$wp_filesystem->move( $result['destination'], $pluginFolder );
			$result['destination'] = $pluginFolder;

			// Re-activate plugin if needed
			if ( $wasActivated ) { $activate = activate_plugin( $this->slug ); }

			return $result;
		}
	}
}
