<?php
/**
Plugin Name: Crypto Ticker
Plugin URI: https://github.com/alexshares/cryptoticker.git
Description: Easy add customizable moving or static ticker tapes with cryptocurrency price information for a chosen list of symbols.
Version: 1.0.0
Author: Alexander Morris
Author URI: https://github.com/alexshares/
License: GNU GPL3
 * @package Crypto Ticker
 */

/**
This plugin was forked from the work of Aleksandar Urosevic (urke.kg@gmail.com) 
Available at: https://urosevic.net/wordpress/plugins/stock-ticker/

Copyright 2018 Alexander Morris (alex@blockchain.wtf)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wpau_Stock_Ticker' ) ) {

	/**
	 * Wpau_Stock_Ticker Class provide main plugin functionality
	 *
	 * @category Class
	 * @package Stock Ticker
	 * @author Aleksandar Urosevic
	 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License
	 * @link https://urosevic.net
	 */
	class Wpau_Stock_Ticker {
		

		const DB_VER = 6;
		const VER = '1.0.0';

		public $plugin_name   = 'Stock Ticker';
		public $plugin_slug   = 'stock-ticker';
		public $plugin_option = 'stockticker_defaults';
		public $plugin_url;

		public static $exchanges = array(
			'CMC'    => 'CoinMarketCap.com',
		);

		/**
		 * Construct the plugin object
		 */
		public function __construct() {

			

			$this->plugin_url = plugin_dir_url( __FILE__ );
			$this->plugin_file = plugin_basename( __FILE__ );
			load_plugin_textdomain( $this->plugin_slug, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			// Installation and uninstallation hooks.
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

			// Throw message on multisite
			if ( is_multisite() ) {
				add_action( 'admin_notices', array( $this, 'multisite_notice' ) );
				return;
			}

			// Maybe update trigger.
			add_action( 'plugins_loaded', array( $this, 'maybe_update' ) );

			// Cleanup transients
			if ( ! empty( $_GET['stockticker_purge_cache'] ) ) {
				self::restart_av_fetching();
			}

			// Initialize default settings
			$this->defaults = self::defaults();
			$defaults = $this->defaults;


			// Crypto Ticker Mod
				// Add the isa filter for the ticker.
				add_filter( 'cron_schedules', 'isa_update_ticker' );

				function isa_update_ticker( $schedules ) {
				    $schedules['run_ticker_update'] = array(
				            'interval'  => 150,
				            'display'   => __( 'Update ticker every 10 seconds', 'textdomain' )
				    );
				    return $schedules;
				}

				// Schedule an action if it's not already scheduled
				if ( ! wp_next_scheduled( 'isa_update_ticker' ) ) {
				    wp_schedule_event( time(), 'run_ticker_update', 'isa_update_ticker' );
				    // error_log('job scheduled');
				}

				// Hook into that action to the isa.
				add_action( 'isa_update_ticker', 'fetch_ticker_feed' );

				function push_item($item) {
					

					global $wpdb;
					
					// error_log(print_r($item, TRUE));

					$symbol_to_fetch = $item->symbol;
					$last_volume = $item->{'24h_volume_usd'};
					$changep = $item->{'percent_change_24h'};
					$table_name = $wpdb->prefix . 'stock_ticker_data';
					error_log('table: ' . $table_name);

					// $currency_choice_price = "price_" . $defaults['currencychoice'];
					$currency_choice_price = "price_usd";
					error_log('currencychoice');
					error_log($default['currencychoice']);
					error_log($currency_choice_price);

					error_log($item->$currency_choice_price);

					// error_log($last_volume);

					error_log($symbol_to_fetch);

					$symbol_exists = $wpdb->get_var( $wpdb->prepare(
						"
							SELECT symbol 
							FROM {$wpdb->prefix}stock_ticker_data
							WHERE symbol = %s
						",
						$symbol_to_fetch
					) );

					// $new_timestamp = date("Y-m-d H:m:s", $item->last_updated);
					$new_date = new DateTime();
					$new_timestamp = date("Y-m-d H:i:s", $new_date->getTimestamp());
					// error_log('input date:');
					// error_log($item->updated);
					// error_log('new date:');
					// error_log($new_timestamp);

					$format = 							array(
								'%s', // symbol
								'%s', // raw
								'%s', // last_refreshed
								'%s', // tz
								'%f', // last_open
								'%f', // last_high
								'%f', // last_low
								'%f', // last_close
								'%d', // last_volume
								'%f', // last_change
								'%f', // last_changep
								'%s', // range
							);

					$payload = array(
								'symbol'         => $item->symbol,
								'raw'            => json_encode($item),
								'last_refreshed' => $new_timestamp,
								'tz'             => 'US Eastern',
								'last_open'      => $item->$currency_choice_price,
								'last_high'      => $item->$currency_choice_price,
								'last_low'       => $item->$currency_choice_price,
								'last_close'     => $item->$currency_choice_price,
								'last_volume'    => $last_volume,
								'change'         => ( $changep * $item->$currency_choice_price ),
								'changep'        => $changep,
								'range'          => $item->symbol,
							);

					error_log('maybe sending payload for ' . $payload['symbol']);
					error_log(print_r($payload, TRUE));

					if ( ! empty( $symbol_exists ) ) {

						// error_log('sending payload as update');
						// UPDATE
						$ret = $wpdb->update(
							// table
							$table_name,
							// data
							$payload,
							// WHERE
							array(
								'symbol' => $payload['symbol'],
							),
							// format
							$format,
							// WHERE format
							array(
								'%s',
							)
						);
					} else {

						// error_log('sending payload as insert');
						// INSERT
						$ret = $wpdb->insert(
							// table
							$table_name,
							// data
							$payload,
							// format
							$format
						);
					}

					// Catch errors
					// error_log( print_r( $wpdb->last_query, TRUE ) );

					// Is failed updated data in DB
					if ( false === $ret ) {
						$msg = "Stock Ticker Fatal Error: Failed to save stock data for {$symbol_to_fetch} to database!";
						// error_log( $msg );
						// Release processing for next run
						// self::unlock_fetch();
						// Return failed status
						return array(
							'message' => $msg,
							'symbol'  => $symbol_to_fetch,
						);
					}

					// After success update in database, report in log
					$msg = "Data for symbol {$symbol_to_fetch} has been updated in database.";
					// error_log( $msg );
					// Set last fetched symbol
					update_option( 'stockticker_av_last', $symbol_to_fetch );
					// Release processing for next run
					// self::unlock_fetch();
					// Return succes status
					return array(
						'message' => $msg,
						'symbol'  => $symbol_to_fetch,
					);
				}

				function update_ticker_db($data) {
					// Push the new ticker data to the DB.
					// error_log(count($data));

					// Cycle through the array and json_decode each element.
					$limit = count($data) - 4;
					// error_log('Updating ' . $limit . " items");
					for ( $i = 0; $i < $limit; $i++){
						// error_log('pushed ' . $data[$i]->symbol . 'to db');

						push_item($data[$i]);
					}

					// $arr is now array(2, 4, 6, 8)
					unset($data);

				}

				function fetch_ticker_feed() {
				    // Get the feed data from the coinmarketcap API.
					$feed_url = 'https://api.coinmarketcap.com/v1/ticker/';

					$wparg = array(
						'timeout' => intval( $defaults['timeout'] ),
						);

					// self::log( 'Fetching data from AV: ' . $feed_url );
					$response = wp_remote_get( $feed_url, $wparg );
					
					if (is_wp_error($response)) {
						// error_log('Failed to pull coinmarketcap feed');
						// error_log(print_r($response));

					} else {
						// error_log('Sending to db put');
						update_ticker_db(json_decode($response['body']));
					
					}
				}	


			//

			// Register AJAX ticker loader
			add_action( 'wp_ajax_stockticker_load', array( $this, 'ajax_stockticker_load' ) );
			add_action( 'wp_ajax_nopriv_stockticker_load', array( $this, 'ajax_stockticker_load' ) );
			// Register AJAX stock updater
			add_action( 'wp_ajax_stockticker_update_quotes', array( $this, 'ajax_stockticker_update_quotes' ) );
			add_action( 'wp_ajax_nopriv_stockticker_update_quotes', array( $this, 'ajax_stockticker_update_quotes' ) );
			// Restart fetching loop by AJAX request
			add_action( 'wp_ajax_stockticker_purge_cache', array( $this, 'ajax_restart_av_fetching' ) );
			add_action( 'wp_ajax_nopriv_stockticker_purge_cache', array( $this, 'ajax_restart_av_fetching' ) );

			if ( is_admin() ) {
				// Initialize Plugin Settings Magic
				add_action( 'init', array( $this, 'admin_init' ) );
				// Maybe display admin notices?
				add_action( 'admin_notices', array( $this, 'admin_notice' ) );
			} else {
				// Enqueue frontend scripts.
				add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			}

			// Initialize Widget.
			require_once( 'inc/widget.php' );

			// Register stock_ticker shortcode.
			add_shortcode( 'stock_ticker', array( $this, 'shortcode' ) );

		} // END public function __construct()

		/**
		 * Throw notice that plugin does not work on Multisite
		 */
		function multisite_notice() {
			$class = 'notice notice-error';
			$message = sprintf(
				__( 'We are sorry, %1$s v%2$s does not support Multisite WordPress.', 'wpaust' ),
				$this->plugin_name,
				self::VER
			);
			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
		}

		function admin_notice() {

			$missing_option = array();

			// // If no AlphaVantage API Key, display admin notice
			// if ( empty( $this->defaults['avapikey'] ) ) {
			// 	$missing_option[] = __( 'AlphaVantage.co API Key', 'wpaust' );
			// }

			// // If no all symbls, display admin notice
			// if ( empty( $this->defaults['all_symbols'] ) ) {
			// 	$missing_option[] = __( 'All Stock Symbols', 'wpaust' );
			// }

			if ( ! empty( $missing_option ) ) {
				$class = 'notice notice-error';
				$missing_options = '<ul><li>' . join( '</li><li>', $missing_option ) . '</li></ul>';
				$settings_title = __( 'Settings' );
				$settings_link = "<a href=\"options-general.php?page={$this->plugin_slug}\">{$settings_title}</a>";
				$message = sprintf(
					__( 'Plugin %1$s v%2$s require that you have defined options listed below to work properly. Please visit plugin %3$s page and read description for those options. %4$s', 'wpaust' ),
					"<strong>{$this->plugin_name}</strong>",
					self::VER,
					$settings_link,
					$missing_options
				);
				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $message );
			}

		} // END function admin_notice()

		/**
		 * Activate the plugin
		 */
		function activate() {
			// Auto disable on WPMU
			if ( is_multisite() ) {
				deactivate_plugins( plugin_basename( __FILE__ ) );
				wp_die( sprintf(
					__( 'We are sorry, %1$s v%2$s does not support Multisite WordPress.', 'wpaust' ),
					$this->plugin_name,
					self::VER
				) );
			}
			// Single WP activation process
			global $wpau_stockticker;
			$wpau_stockticker->init_options();
			$wpau_stockticker->maybe_update();
		} // END function activate()

		/**
		 * Deactivate the plugin
		 */
		function deactivate() {
			// Do nothing.
		} // END function deactivate()

		/**
		 * Return initial options
		 * @return array Global defaults for current plugin version
		 */
		function init_options() {

			$init = array(
				'all_symbols'     => 'BTC,ETH,XRP,BCH,ADA,XLM,NEO,LTC,EOS,XEM,MIOTA,DASH,XMR,TRX,LSK,VEN,QTUM,ETC,USDT,XRB,ICX,PPT,BTG,OMG,ZEC,STEEM,BTS,STRAT,BNB,BCN,SC,XVG,MKR,ZRX,VERI,DGD,WTC,REP,WAVES,KCS,SNT,RHOC,AE,DCR,DOGE,ARDR,HSR,GAS,KMD,KNC,ZIL,BAT,DRGN,LRC,ARK,DGB,ELF,ETN,IOST,QASH,PIVX,NAS,ZCL,PLR,BTM,GBYTE,GNT,DCN,CNX,CND,ETHOS,FUN,R,AION,SALT,SYS,FCT,GXS,BTX,POWR,DENT,AGI,XZC,SMART,NXT,IGNIS,REQ,MAID,KIN,RDD,NXS,ENG,BNT,MONA,PAY,ICN,PART,GNO,WAX,NEBL',
				'symbols'         => 'BTC,ETH,XRP,BCH,ADA,XLM,NEO,LTC,EOS,XEM,MIOTA,DASH,XMR,TRX,LSK,VEN,QTUM,ETC,USDT,XRB,ICX,PPT,BTG,OMG,ZEC,STEEM,BTS,STRAT,BNB,BCN,SC,XVG,MKR,ZRX,VERI,DGD,WTC,REP,WAVES,KCS,SNT,RHOC,AE,DCR,DOGE,ARDR,HSR,GAS,KMD,KNC,ZIL,BAT,DRGN,LRC,ARK,DGB,ELF,ETN,IOST,QASH,PIVX,NAS,ZCL,PLR,BTM,GBYTE,GNT,DCN,CNX,CND,ETHOS,FUN,R,AION,SALT,SYS,FCT,GXS,BTX,POWR,DENT,AGI,XZC,SMART,NXT,IGNIS,REQ,MAID,KIN,RDD,NXS,ENG,BNT,MONA,PAY,ICN,PART,GNO,WAX,NEBL',
				'show'            => 'name',
				'zero'            => '#454545',
				'minus'           => '#D8442F',
				'plus'            => '#009D59',
				'symbolchoice' 	  => 'caret',
				'tag_disabled' 	  => 'false',
				'currencychoice'  => 'usd',
				'cache_timeout'   => '180', // 3 minutes
				'template_title'  => '%company% %price% %change% %changep%',
				'template_price'  => '%price% %changep%',
				'error_message'   => 'Unfortunately, we could not get crypto prices at this time.',
				'legend'          => "BTC;Bitcoin\nETH;Ethereum\nXRP;Ripple\nBCH;Bitcoin Cash\nADA;Cardano\nXLM;Stellar\nNEO;NEO\nLTC;Litecoin\nEOS;EOS\nXEM;NEM\nMIOTA;IOTA\nDASH;Dash\nXMR;Monero\nTRX;TRON\nLSK;Lisk",
				'style'           => 'font-family:"Open Sans",Helvetica,Arial,sans-serif;font-weight:normal;font-size:14px;',
				'timeout'         => 4,
				'refresh'         => false,
				'refresh_timeout' => 5 * MINUTE_IN_SECONDS,
				'speed'           => 25,
				'globalassets'    => false,
				'avapikey'        => '',
				'loading_message' => 'Loading crypto prices...',
				'number_format'   => 'dc',
				'decimals'        => 2,
			);

			// add_option( 'stockticker_version', self::VER, '', 'no' );
			// add_option( 'stockticker_db_ver', self::DB_VER, '', 'no' );
			add_option( $this->plugin_option, $init, '', 'no' );

			return $init;

		} // END function init_options() {

		/**
		 * Check do we need to migrate options
		 */
		function maybe_update() {
			// Bail if this plugin data doesn't need updating
			if ( get_option( 'stockticker_db_ver', 0 ) >= self::DB_VER ) {
				return;
			}
			require_once( dirname( __FILE__ ) . '/update.php' );
			au_stockticker_update();
		} // END function maybe_update()

		/**
		 * Initialize Settings link for Plugins page and create Settings page
		 *
		 */
		function admin_init() {

			// Add plugin Settings link.
			add_filter( 'plugin_action_links_' . $this->plugin_file, array( $this, 'plugin_settings_link' ) );

			// Update links in plugin row on Plugins page.
			add_filter( 'plugin_row_meta', array( $this, 'add_plugin_meta_links' ), 10, 2 );

			// Load colour picker scripts on plugin settings page and on widgets/customizer.
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

			require_once( 'inc/settings.php' );

			global $wpau_stockticker_settings;
			if ( empty( $wpau_stockticker_settings ) ) {
				$wpau_stockticker_settings = new Wpau_Stock_Ticker_Settings();
			}

		} // END function admin_init_settings()

		/**
		 * Add link to official plugin pages
		 * @param array $links  Array of existing plugin row links.
		 * @param string $file  Path of current plugin file.
		 * @return array        Array of updated plugin row links
		 */
		function add_plugin_meta_links( $links, $file ) {
			if ( 'stock-ticker/stock-ticker.php' === $file ) {
				return array_merge(
					$links,
					array(
						sprintf(
							'<a href="https://blockchain.wtf" target="_blank">%s</a>',
							__( 'Support' )
						),
						sprintf(
							'<a href="https://blockchain.wtf" target="_blank">%s</a>',
							__( 'Donate' )
						),
					)
				);
			}
			return $links;
		} // END function add_plugin_meta_links()

		/**
		 * Generate Settings link on Plugins page listing
		 * @param  array $links Array of existing plugin row links.
		 * @return array        Updated array of plugin row links with link to Settings page
		 */
		function plugin_settings_link( $links ) {
			$settings_title = __( 'Settings' );
			$settings_link = "<a href=\"options-general.php?page={$this->plugin_slug}\">{$settings_title}</a>";
			array_unshift( $links, $settings_link );
			return $links;
		} // END function plugin_settings_link()

		/**
		 * Enqueue the colour picker and admin style
		 */
		function admin_scripts( $hook ) {
			if ( 'settings_page_' . $this->plugin_slug == $hook ) {
				wp_enqueue_style( 'wp-color-picker' );
				wp_enqueue_script( 'wp-color-picker' );
				wp_enqueue_style(
					$this->plugin_slug . '-admin', // 'stock-ticker',
					plugins_url( 'assets/css/admin.css', __FILE__ ),
					array(),
					self::VER
				);

				wp_register_script(
					'stock-ticker-admin',
					$this->plugin_url . ( WP_DEBUG ? 'assets/js/jquery.admin.js' : 'assets/js/jquery.admin.min.js' ),
					array( 'jquery' ),
					self::VER,
					true
				);
				wp_localize_script(
					'stock-ticker-admin',
					'stockTickerJs',
					array( 'ajax_url' => admin_url( 'admin-ajax.php' ) )
				);
				wp_enqueue_script( 'stock-ticker-admin' );
			}
		} // END function admin_scripts()

		/**
		 * Enqueue frontend assets
		 */
		function enqueue_scripts() {
			$defaults = $this->defaults;
			$upload_dir = wp_upload_dir();

			wp_enqueue_script(
				'jquery-webticker',
				$this->plugin_url . ( WP_DEBUG ? 'assets/js/jquery.webticker.js' : 'assets/js/jquery.webticker.min.js' ),
				array( 'jquery' ),
				'2.2.0.1',
				true
			);
			wp_enqueue_style(
				'stock-ticker',
				$this->plugin_url . 'assets/css/stock-ticker.css',
				array(),
				self::VER
			);
			wp_enqueue_style(
				'stock-ticker',
				$this->plugin_url . 'assets/css/wtf.css',
				array(),
				self::VER
			);
			wp_enqueue_style(
				'cryptocoins',
				$this->plugin_url . 'assets/css/cryptocoins.css',
				array(),
				self::VER
			);
			wp_enqueue_style(
				'stock-ticker-custom',
				set_url_scheme( $upload_dir['baseurl'] ) . '/stock-ticker-custom.css',
				array(),
				self::VER
			);

			wp_register_script(
				'stock-ticker',
				$this->plugin_url . ( WP_DEBUG ? 'assets/js/jquery.stockticker.js' : 'assets/js/jquery.stockticker.min.js' ),
				array( 'jquery', 'jquery-webticker' ),
				self::VER,
				true
			);
			wp_localize_script(
				'stock-ticker',
				'stockTickerJs',
				array( 'ajax_url' => admin_url( 'admin-ajax.php' ) )
			);
			// Enqueue script parser
			if ( isset( $defaults['globalassets'] ) ) {
				wp_enqueue_script( 'stock-ticker' );
			}

			// Register refresh script if option is enabled
			if ( ! empty( $defaults['refresh'] ) ) {
				wp_register_script(
					'stock-ticker-refresh',
					set_url_scheme( $upload_dir['baseurl'] ) . '/stock-ticker-refresh.js',
					array( 'jquery', 'jquery-webticker', 'stock-ticker' ),
					self::VER,
					true
				);
				wp_enqueue_script( 'stock-ticker-refresh' );
			}

		} // END function enqueue_scripts()

		/**
		 * Get default options from DB
		 * @return array Latest global defaults
		 */
		public function defaults() {
			$defaults = get_option( $this->plugin_option );
			if ( empty( $defaults ) ) {
				$defaults = $this->init_options();
			}
			return $defaults;
		} // END public function defaults()

		/**
		 * Delete control options to force re-fetching from first symbol
		 */
		public static function restart_av_fetching() {
			update_option( 'stockticker_av_last', '' );
			$expired_timestamp = time() - ( 10 * YEAR_IN_SECONDS );
			update_option( 'stockticker_av_last_timestamp', $expired_timestamp );
			update_option( 'stockticker_av_progress', false );
			self::log( 'Stock Ticker: data fetching from first symbol has been restarted' );
		} // END public static function restart_av_fetching() {

		function ajax_restart_av_fetching() {
			self::restart_av_fetching();
			$result['status']  = 'success';
			$result['message'] = 'OK';
			$result = json_encode( $result );
			echo $result;
			wp_die();
		} // END function ajax_restart_av_fetching() {

		function ajax_stockticker_load() {
			// @TODO Provide error message if any of params missing + add nonce check
			if ( ! empty( $_POST['symbols'] ) ) {
				// Sanitize data
				$symbols       = strip_tags( $_POST['symbols'] );
				$show          = strip_tags( $_POST['show'] );
				$number_format = (int) $_POST['number_format'];
				$decimals      = (int) $_POST['decimals'];
				$static        = (int) $_POST['static'];
				$empty         = (int) $_POST['empty'];
				$duplicate     = (int) $_POST['duplicate'];
				$class         = strip_tags( $_POST['class'] );
				$speed         = (int) $_POST['speed'];

				// Treat as error if no stock ticker composed but 'Unfortunately' message displayed
				$message = self::stock_ticker( $symbols, $show, $number_format, $decimals, $static, $empty, $duplicate, $class );
				if ( strpos( $message, 'error' ) !== false ) {
					$message = strip_tags( $message );
					$result['status']  = 'error';
					$result['message'] = $message;
				} else {
					$result['status']  = 'success';
					$result['speed']   = $speed;
					$result['message'] = $message;
				}
			} else {
				$result['status']  = 'error';
				$result['message'] = 'Error ocurred: No symbols provided';
			}
			$result = json_encode( $result );
			echo $result;
			wp_die();
		} // END function ajax_stockticker_load() {

		/**
		 * AJAX to update AlphaVantage.co quotes
		 */
		function ajax_stockticker_update_quotes() {
			$response = $this->get_alphavantage_quotes();
			$result['status']  = 'success';
			$result['message'] = $response;

			if ( strpos( $response, 'no need to fetch' ) !== false ) {
				$result['done'] = true;
				$result['message'] = 'DONE';
			} else {
				$result['done'] = false;
				// If we have some plugin functionality fatal error
				// (missing API key, no symbols, can't write to DB, etc)
				// then throw error and signal stop fetching:
				// * There is no defined All Stock Symbols
				// * Failed to save stock data for {$symbol_to_fetch} to database!
				// * AlphaVantage.co API key has not set
				if ( strpos( $response, 'Stock Ticker Fatal Error:' ) !== false ) {
					$result['done'] = true;
				}
			}
			$result = json_encode( $result );

			echo $result;
			wp_die();
		} // END function ajax_stockticker_update_quotes()

		/**
		 * Generate and output stock ticker block
		 * @param  string   $symbols       Comma separated array of symbols.
		 * @param  string   $show          What to show (name or symbol).
		 * @param  bool     $static        Request for static (non-animated) block.
		 * @param  bool     $empty         Start ticker empty or prefilled with symbols.
		 * @param  bool     $duplicate     If there is less items than visible on the ticker make it continuous
		 * @param  string   $class         Custom class for styling Stock Ticker block.
		 * @param  integer  $decimals      Number of decimal places.
		 * @param  string   $number_format Which number format to use (dc, sc, cd, sd).
		 * @return string          Composed HTML for block.
		 */
		public function stock_ticker( $symbols, $show, $number_format = null, $decimals = null, $static, $empty = true, $duplicate = false, $class = '' ) {

			if ( empty( $symbols ) ) {
				return;
			}

			// Get legend for company names.
			$defaults = $this->defaults;

			// Prepare number format
			if ( ! empty( $number_format ) && in_array( $number_format, array( 'dc', 'sd', 'sc', 'cd' ) ) ) {
				$defaults['number_format'] = $number_format;
			} else if ( ! isset( $defaults['number_format'] ) ) {
				$defaults['number_format'] = 'cd';
			}
			switch ( $defaults['number_format'] ) {
				case 'dc': // 0.000,00
					$thousands_sep = '.';
					$dec_point     = ',';
					break;
				case 'sd': // 0 000.00
					$thousands_sep = ' ';
					$dec_point     = '.';
					break;
				case 'sc': // 0 000,00
					$thousands_sep = ' ';
					$dec_point     = ',';
					break;
				default: // 0,000.00
					$thousands_sep = ',';
					$dec_point     = '.';
			}

			// Prepare number of decimals
			if ( null !== $decimals ) {
				// From shortcode or widget
				$decimals = (int) $decimals;
			} else {
				// From settings
				if ( ! isset( $defaults['decimals'] ) ) {
					$defaults['decimals'] = 2;
				}
				$decimals = (int) $defaults['decimals'];
			}

			// Parse legend
			$matrix = explode( "\n", $defaults['legend'] );
			$msize = count( $matrix );
			for ( $m = 0; $m < $msize; ++$m ) {
				$line = explode( ';', $matrix[ $m ] );
				if ( ! empty( $line[0] ) && ! empty( $line[1] ) ) {
					$legend[ strtoupper( trim( $line[0] ) ) ] = trim( $line[1] );
				}
			}
			unset( $m, $msize, $matrix, $line );

			// Prepare ticker.
			if ( ! empty( $static ) && 1 == $static ) { $class .= ' static'; }

			// Prepare out vars
			$out_start = sprintf(  '<ul class="stock_ticker %s">', $class );
			$out_start = $out_start;
			$out_end = '</ul>';
			$out_error_msg = "<li class=\"error\">{$defaults['error_message']}</li>";

			// Get stock data from database
			$stock_data = self::get_stock_from_db( $symbols );
			if ( empty( $stock_data ) ) {
				return "{$out_start}{$out_error_msg}{$out_end}";
			}

			// Start ticker string.
			$q = '';

			// Parse results and extract data to display.
			$symbols_arr = explode( ',', $symbols );

			// Currently supported WTF Symbols (coverage pages exist) 
			$supported_symbols = array(
				"BTC"=>1,
				"ETH"=>1,
				"LTC"=>1,
				"XRP"=>1,
				"DASH"=>1,
				"ZEC"=>1,
				"XMR"=>1,
				"NEO"=>1,
				"DGE"=>1,
				"STEEM"=>1,
				"IOTA"=>1,
				"ANT"=>1,
				"REP"=>1,
				"1ST"=>1,
				"GNO"=>1,
				"GNT"=>1,
				"ICN"=>1,
				"MLN"=>1,
				"SWT"=>1
			);

			foreach ( $symbols_arr as $symbol ) {

				if ( empty( $stock_data[ $symbol ] ) ) {
					continue;
				}

				// Assign object elements to vars.
				$q_symbol  = $symbol;
				$q_name    = $stock_data[ $symbol ]['symbol']; // ['t']; // No nicename on AlphaVantage.co so use ticker instead.
				$q_change  = $stock_data[ $symbol ]['change']; // ['c'];
				$q_price   = $stock_data[ $symbol ]['last_open']; // ['l'];
				$q_changep = $stock_data[ $symbol ]['changep']; // ['cp'];
				$q_volume  = $stock_data[ $symbol ]['last_volume'];
				$q_tz      = $stock_data[ $symbol ]['tz'];
				$q_ltrade  = $stock_data[ $symbol ]['last_refreshed']; // ['lt'];
				$q_ltrade  = str_replace( ' 00:00:00', '', $q_ltrade ); // Strip zero time from last trade date string
				$q_ltrade  = "{$q_ltrade} {$q_tz}";
				// Extract Exchange from Symbol
				$q_exch = '';
				if ( strpos( $symbol, ':' ) !== false ) {
					list( $q_exch, $q_symbol ) = explode( ':', $symbol );
				}

				$symbolchoice = $defaults['symbolchoice'];
				

				// Define class based on change.
				$prefix = '';
				if ( $q_change < 0 ) {
					$chclass = 'minus';
					$q_changedir = $symbolchoice . "-down";
					$q_changecolor = $defaults['minus'];
				} elseif ( $q_change > 0 ) {
					$chclass = 'plus';
					$prefix = '+';
					$q_changedir = $symbolchoice . "-up";
					$q_changecolor =  $defaults['plus'];
				} else {
					$chclass = 'zero';
					$q_change = '0.00';
					$q_changedir = "balance-scale";
					$q_changecolor =  $defaults['zero'];

				}

				// Get custom company name if exists.
				if ( ! empty( $legend[ $q_exch . ':' . $q_symbol ] ) ) {
					// First in format EXCHANGE:SYMBOL.
					$q_name = $legend[ $q_exch . ':' . $q_symbol ];
				} elseif ( ! empty( $legend[ $q_symbol ] ) ) {
					// Then in format SYMBOL.
					$q_name = $legend[ $q_symbol ];
				}

				// What to show: Symbol or Company Name?
				if ( 'name' == $show ) {
					$company_show = $q_name;
				} else {
					$company_show = $q_symbol;
				}

				// Format numbers.
				$q_price   = number_format( $q_price, $decimals, $dec_point, $thousands_sep );
				$q_change  = $prefix . number_format( $q_change, $decimals, $dec_point, $thousands_sep );
				$q_changep = $prefix . number_format( $q_changep, $decimals, $dec_point, $thousands_sep );

				$url_query = $q_symbol;
				if ( ! empty( $q_exch ) ) {
					$quote_title = $q_name . ' (' . self::$exchanges[ $q_exch ] . ', Volume ' . $q_volume . ', Last trade ' . $q_ltrade . ')';
				} else {
					$quote_title = $q_name . ' (Last trade ' . $q_ltrade . ')';
				}

				// set up image icon 
				// supported by https://labs.allienworks.net/icons/cryptocoins/ (Nice work - thanks!)
				$q_img = " <i class='cc " . $symbol . "'></i> ";

				// Set price format - Needs to be added to settings
				$currencyformat = $defaults['currencychoice'];
				if( 'usd' === $currencyformat ) {
					$price_format = " $" . $q_price; // USD
				} else if ( 'can' === $currencyformat ) {
					$price_format = " $" . $q_price; // CAN
				} else if ( 'eur' === $currencyformat ) {
					$price_format = " €" . $q_price; // EURO
				} else if ( 'aus' === $currencyformat ) {
					$price_format = " $" . $q_price; // AUS
				} else if ( 'gpb' === $currencyformat ) {
					$price_format = " ‎£" . $q_price; // British Pound
				} else if ( 'yen' === $currencyformat ) {
					$price_format = " ¥" . $q_price; // Chinese Yen
				} else if ( 'won' === $currencyformat ) {
					$price_format = " ₩" . $q_price; // SK Won
				} else { // default to usd 
					$price_format = " $" . $q_price; // USD
				}

				// New logic to handle title / price separation

				// Assemble title
				$q_title = $defaults['template_title'];
				$q_title = str_replace( '%icon%', $q_img, $q_title );
				$q_title = str_replace( '%company%', $company_show, $q_title );
				$q_title = str_replace( '%symbol%', $q_symbol, $q_title );
				
				$q_title = str_replace( '%price%', $price_format, $q_title );

				if ( isset($supported_symbols[$symbol]) ) {
					$link_to_wtf = "t/" . $symbol;
				} else {
					$link_to_wtf = "";
				}

				// Html prep
				$q_title =  '<li><span class="sqitem" title="' . $symbol . '"><a href="https://blockchain.wtf/' . $link_to_wtf . '">' . $q_title;

				// Clear symbols from q_changep (direction has already been set)
				$q_changep = str_replace( ['+','-'],['',''],$q_changep );
				
				// Set format for change percentage display
				$q_change_p_format = "<span id=\"price_change_$symbol\" style=\"color:{$q_changecolor}\"> <i class=\"fa fa-{$q_changedir}\"></i><strong> {$q_changep}% </strong> </span>";

				// Assemble price
				$q_price = $defaults['template_price'];
				$q_price = str_replace( '%price%', $price_format, $q_price );
				$q_price = str_replace( '%change%', $q_change, $q_price );
				$q_price = str_replace( '%changep%', $q_change_p_format, $q_price );
				$q_price = str_replace( '%volume%', $q_volume, $q_price );

				// add stock quote item.
				$q .= $q_title . $q_price . '</span></a></li>';

			} // END foreach ( $symbols_arr as $symbol ) {

			// No results were returned?
			if ( empty( $q ) ) {
				return "{$out_start}{$out_error_msg}{$out_end}";
			} else if (!$defaults['tag_disabled']) {
				// Blockchain.wtf Tag - Please don't remove this. It's used for internal analytics only.
				$q .= "<li><span><a class='wtf_tag' href='https://staging.blockchain.wtf/'> Sponsored by Blockchain.wtf</a><span></li>";
			}

			// Print ticker content if we have it.
			return "{$out_start}{$q}{$out_end}";

		} // END public function stock_ticker()

		/**
		 * Shortcode processor for Stock Ticker
		 * @param  array $atts    Array of shortcode parameters.
		 * @return string         Generated HTML output for block.
		 */
		public function shortcode( $atts ) {
			$defaults = $this->defaults;

			$atts = shortcode_atts( array(
				'symbols'         => $defaults['symbols'],
				'show'            => $defaults['show'],
				'number_format'   => isset( $defaults['number_format'] ) ? $defaults['number_format'] : 'dc',
				'decimals'        => isset( $defaults['decimals'] ) ? $defaults['decimals'] : 2,
				'static'          => 0,
				'nolink'          => 0,
				'prefill'         => 0,
				'duplicate'       => 0,
				'speed'           => isset( $defaults['speed'] ) ? $defaults['speed'] : 50,
				'class'           => '',
				'loading_message' => isset( $defaults['loading_message'] ) ? $defaults['loading_message'] : __( 'Loading stock data...', 'wpaust' ),
			), $atts );

			// If we have defined symbols, enqueue script and print stock holder
			if ( ! empty( $atts['symbols'] ) ) {
				// Strip tags as we allow only real symbols
				$atts['symbols'] = strip_tags( $atts['symbols'] );

				// Enqueue script parser on demand
				if ( empty( $defaults['globalassets'] ) ) {
					wp_enqueue_script( 'stock-ticker' );
					if ( ! empty( $defaults['refresh'] ) ) {
						wp_enqueue_script( 'stock-ticker-refresh' );
					}
				}

				// startEmpty based on prefill option
				$empty = empty( $atts['prefill'] ) ? 'true' : 'false';
				// duplicate
				$duplicate = empty( $atts['duplicate'] ) ? 'false' : 'true';

				// Return stock holder
				return sprintf(
					'<div
					 class="stock-ticker-wrapper %5$s"
					 data-stockticker_symbols="%1$s"
					 data-stockticker_show="%2$s"
					 data-stockticker_number_format="%4$s"
					 data-stockticker_decimals="%10$s"
					 data-stockticker_static="%3$s"
					 data-stockticker_class="%5$s"
					 data-stockticker_speed="%6$s"
					 data-stockticker_empty="%7$s"
					 data-stockticker_duplicate="%8$s"
					><ul class="stock_ticker"><li class="init"><span class="sqitem">%9$s</span></li></ul></div>',
					$atts['symbols'],         // 1
					$atts['show'],            // 2
					$atts['static'],          // 3
					$atts['number_format'],   // 4
					$atts['class'],           // 5
					$atts['speed'],           // 6
					$empty,                   // 7
					$duplicate,               // 8
					$atts['loading_message'], // 9
					$atts['decimals']         // 10
				);
			}
			return false;

		} // END public function shortcode()

		// Thanks to https://coderwall.com/p/zepnaw/sanitizing-queries-with-in-clauses-with-wpdb-on-wordpress
		private function get_stock_from_db( $symbols = '' ) {
			// If no symbols we have to fetch from DB, then exit
			if ( empty( $symbols ) ) {
				return;
			}

			global $wpdb;
			// Explode symbols to array
			$symbols_arr = explode( ',', $symbols );
			// Count how many entries will we select?
			$how_many = count( $symbols_arr );
			// prepare the right amount of placeholders for each symbol
			$placeholders = array_fill( 0, $how_many, '%s' );
			// glue together all the placeholders...
			$format = implode( ',', $placeholders );
			// put all in the query and prepare
			/*
			$stock_sql = $wpdb->prepare(
				"
				SELECT `symbol`,`tz`,`last_refreshed`,`last_open`,`last_high`,`last_low`,`last_close`,`last_volume`,`change`,`changep`,`range`
				FROM {$wpdb->prefix}stock_ticker_data
				WHERE symbol IN ($format)
				",
				$symbols_arr
			);

			// retrieve the results from database
			$stock_data_a = $wpdb->get_results( $stock_sql, ARRAY_A );
			/**/
			$stock_data_a = $wpdb->get_results( $wpdb->prepare(
				"
				SELECT `symbol`,`tz`,`last_refreshed`,`last_open`,`last_high`,`last_low`,`last_close`,`last_volume`,`change`,`changep`,`range`
				FROM {$wpdb->prefix}stock_ticker_data
				WHERE symbol IN ($format)
				",
				$symbols_arr
			), ARRAY_A );

			// If we don't have anything retrieved, just exit
			if ( empty( $stock_data_a ) ) {
				return;
			}

			// Convert DB result to associated array
			$stock_data = array();
			foreach ( $stock_data_a as $stock_data_item ) {
				$stock_data[ $stock_data_item['symbol'] ] = $stock_data_item;
			}

			// Return re-composed assiciated array
			return $stock_data;
		} // END private function get_stock_from_db( $symbols ) {

		/**
		 * Download stock quotes from AlphaVantage.co and store them all to single transient
		 */
		function get_alphavantage_quotes() {

			// Check is currently fetch in progress
			$progress = get_option( 'stockticker_av_progress', false );

			if ( false != $progress ) {
				return;
			}

			// Set fetch progress as active
			self::lock_fetch();

			// Get defaults (for API key)
			$defaults = $this->defaults;
			// Get symbols we should to fetch from AlphaVantage
			$symbols = $defaults['all_symbols'];

			// If we don't have defined global symbols, exit
			if ( empty( $symbols ) ) {
				return 'Stock Ticker Fatal Error: There is no defined All Stock Symbols';
			}

			// Make array of global symbols
			$symbols_arr = explode( ',', $symbols );

			// Remove unsupported stock exchanges from global array to prevent API errors
			$symbols_supported = array();
			foreach ( $symbols_arr as $symbol_pos => $symbol_to_check ) {
				// If there is semicolon, it's symbol with exchange
				if ( strpos( $symbol_to_check, ':' ) ) {
					// Explode symbol so we can get exchange code
					$symbol_exchange = explode( ':', $symbol_to_check );
					// If exchange code is supported, add symbol to query array
					if ( ! empty( self::$exchanges[ strtoupper( trim( $symbol_exchange[0] ) ) ] ) ) {
						$symbols_supported[] = $symbol_to_check;
					}
				} else {
					// Add symbol w/o exchange to query array
					$symbols_supported[] = $symbol_to_check;
				}
			}
			// Set back query array to $symbols_arr
			$symbols_arr = $symbols_supported;

			// Default symbol to fetch first (first form array)
			$current_symbol_index = 0;
			$symbol_to_fetch = $symbols_arr[ $current_symbol_index ];

			// Get last fetched symbol
			$last_fetched = strtoupper( get_option( 'stockticker_av_last' ) );

			// Find which symbol we should fetch
			if ( ! empty( $last_fetched ) ) {
				$last_symbol_index = array_search( $last_fetched, $symbols_arr );
				$current_symbol_index = $last_symbol_index + 1;
				// If we have less than next symbol, then rewind to beginning
				if ( count( $symbols_arr ) <= $current_symbol_index ) {
					$current_symbol_index = 0;
				} else {
					$symbol_to_fetch = strtoupper( $symbols_arr[ $current_symbol_index ] );
				}
			}
			/*
			// If no symbol to fetch, exit
			if ( empty( $symbol_to_fetch ) ) {
				// Set last fetched symbol to none
				update_option( 'stockticker_av_last', '' );
				// and release processing for next run
				self::unlock_fetch();
				// then return message as a response
				$msg = 'No symbols to fetch!';
				return $msg;
			}
			*/

			// If current_symbol_index is 0 and cache timeout has not expired,
			// do not attempt to fetch again but wait to expire timeout for next loop (UTC)
			if ( 0 == $current_symbol_index ) {
				$current_timestamp = time();
				$last_fetched_timestamp = get_option( 'stockticker_av_last_timestamp', $current_timestamp );
				$target_timestamp = $last_fetched_timestamp + (int) $defaults['cache_timeout'];
				if ( $target_timestamp > $current_timestamp ) {
					// If timestamp not expired, do not fetch but exit
					self::unlock_fetch();
					return 'Cache timeout has not expired, no need to fetch new loop at the moment.';
				} else {
					// If timestamp expired, set new value and proceed
					update_option( 'stockticker_av_last_timestamp', $current_timestamp );
					self::log( 'Set current timestamp when first symbol is fetched as a reference for next loop' );
				}
			}

			// Now call AlphaVantage fetcher for current symbol
			$stock_data = $this->fetch_alphavantage_feed( $symbol_to_fetch );

			// If we have not got array with stock data, exit w/o updating DB
			if ( ! is_array( $stock_data ) ) {
				self::log( $stock_data );
				// If we got some error for first symbol, revert last timestamp
				if ( 0 == $current_symbol_index ) {
					self::log( 'Failed fetching and crunching for first symbol, set back previous timestamp' );
					update_option( 'stockticker_av_last_timestamp', $last_fetched_timestamp );
				}
				// Release processing for next run
				self::unlock_fetch();
				// Return response status
				return $stock_data;
			}

			// With success stock data in array, save data to database
			global $wpdb;
			// Define plugin table name
			$table_name = $wpdb->prefix . 'stock_ticker_data';
			// Check does symbol already exists in DB (to update or to insert new one)
			// I'm not using here $wpdb->replace() as I wish to avoid reinserting row to table which change primary key (delete row, insert new row)
			$symbol_exists = $wpdb->get_var( $wpdb->prepare(
				"
					SELECT symbol 
					FROM {$wpdb->prefix}stock_ticker_data
					WHERE symbol = %s
				",
				$symbol_to_fetch
			) );
			if ( ! empty( $symbol_exists ) ) {
				// UPDATE
				$ret = $wpdb->update(
					// table
					$table_name,
					// data
					array(
						'symbol'         => $stock_data['t'],
						'raw'            => $stock_data['raw'],
						'last_refreshed' => $stock_data['lt'],
						'tz'             => $stock_data['ltz'],
						'last_open'      => $stock_data['o'],
						'last_high'      => $stock_data['h'],
						'last_low'       => $stock_data['low'],
						'last_close'     => $stock_data['l'],
						'last_volume'    => $stock_data['v'],
						'change'         => $stock_data['c'],
						'changep'        => $stock_data['cp'],
						'range'          => $stock_data['r'],
					),
					// WHERE
					array(
						'symbol' => $stock_data['t'],
					),
					// format
					array(
						'%s', // symbol
						'%s', // raw
						'%s', // last_refreshed
						'%s', // tz
						'%f', // last_open
						'%f', // last_high
						'%f', // last_low
						'%f', // last_close
						'%d', // last_volume
						'%f', // last_change
						'%f', // last_changep
						'%s', // range
					),
					// WHERE format
					array(
						'%s',
					)
				);
			} else {
				// INSERT
				$ret = $wpdb->insert(
					// table
					$table_name,
					// data
					array(
						'symbol'         => $stock_data['t'],
						'raw'            => $stock_data['raw'],
						'last_refreshed' => $stock_data['lt'],
						'tz'             => $stock_data['ltz'],
						'last_open'      => $stock_data['o'],
						'last_high'      => $stock_data['h'],
						'last_low'       => $stock_data['low'],
						'last_close'     => $stock_data['l'],
						'last_volume'    => $stock_data['v'],
						'change'         => $stock_data['c'],
						'changep'        => $stock_data['cp'],
						'range'          => $stock_data['r'],
					),
					// format
					array(
						'%s', // symbol
						'%s', // raw
						'%s', // last_refreshed
						'%s', // tz
						'%f', // last_open
						'%f', // last_high
						'%f', // last_low
						'%f', // last_close
						'%d', // last_volume
						'%f', // last_change
						'%f', // last_changep
						'%s', // range
					)
				);
			}

			// Is failed updated data in DB
			if ( false === $ret ) {
				$msg = "Stock Ticker Fatal Error: Failed to save stock data for {$symbol_to_fetch} to database!";
				self::log( $msg );
				// Release processing for next run
				self::unlock_fetch();
				// Return failed status
				return $msg;
			}

			// After success update in database, report in log
			$msg = "Stock data for symbol {$symbol_to_fetch} has been updated in database.";
			self::log( $msg );
			// Set last fetched symbol
			update_option( 'stockticker_av_last', $symbol_to_fetch );
			// Release processing for next run
			self::unlock_fetch();
			// Return succes status
			return $msg;

		} // END function get_alphavantage_quotes( $symbols )

		function fetch_alphavantage_feed( $symbol ) {
			return "alphavantage feed fetch was triggered but it is disabled";
			// self::log( "Fetching data for symbol {$symbol}..." );

			// // Get defaults (for API key)
			// $defaults = $this->defaults;

			// // Exit if we don't have API Key
			// if ( empty( $defaults['avapikey'] ) ) {
			// 	return 'Stock Ticker Fatal Error: AlphaVantage.co API key has not set';
			// }

			// // Define AplhaVantage API URL
			// // $feed_url = 'https://www.alphavantage.co/query?function=TIME_SERIES_INTRADAY&interval=5min&apikey=' . $defaults['avapikey'] . '&symbol=';
			// $feed_url = 'https://www.alphavantage.co/query?function=TIME_SERIES_DAILY&outputsize=compact&apikey=' . $defaults['avapikey'] . '&symbol=';
			// $feed_url .= $symbol;

			// $wparg = array(
			// 	'timeout' => intval( $defaults['timeout'] ),
			// );

			// // self::log( 'Fetching data from AV: ' . $feed_url );
			// $response = wp_remote_get( $feed_url, $wparg );

			// // Initialize empty $json variable
			// $data_arr = '';

			// // If we have WP error log it and return none
			// if ( is_wp_error( $response ) ) {
			// 	return 'Stock Ticker got error fetching feed from AlphaVantage.co: ' . $response->get_error_message();
			// } else {
			// 	// Get response from AV and parse it - look for error
			// 	$json = wp_remote_retrieve_body( $response );
			// 	$response_arr = json_decode( $json, true );
			// 	// If we got some error from AV, log to self::log and return none
			// 	if ( ! empty( $response_arr['Error Message'] ) ) {
			// 		return 'Stock Ticker connected to AlphaVantage.co but got error: ' . $response_arr['Error Message'];
			// 	} else {
			// 		// Crunch data from AlphaVantage for symbol and prepare compact array
			// 		self::log( "We got data from AlphaVantage for $symbol, so now let we crunch them and save to database..." );

			// 		// Get basics
			// 		// $ticker_symbol      = $response_arr['Meta Data']['2. Symbol']; // We don't use this at the moment, but requested symbol
			// 		$last_trade_refresh = $response_arr['Meta Data']['3. Last Refreshed'];
			// 		$last_trade_tz      = $response_arr['Meta Data']['5. Time Zone']; // TIME_SERIES_DAILY
			// 		// $last_trade_tz      = $response_arr['Meta Data']['6. Time Zone']; // TIME_SERIES_INTRADAY

			// 		// Get prices
			// 		$i = 0;

			// 		// foreach ( $response_arr['Time Series (5min)'] as $key => $val ) { // TIME_SERIES_INTRADAY
			// 		foreach ( $response_arr['Time Series (Daily)'] as $key => $val ) { // TIME_SERIES_DAILY
			// 			switch ( $i ) {
			// 				case 0:
			// 					$last_trade_date = $key;
			// 					$last_trade = $val;
			// 					break;
			// 				case 1:
			// 					$prev_trade_date = $key;
			// 					$prev_trade = $val;
			// 					break;
			// 				case 2: // Workaround for inconsistent data
			// 					$prev_trade_2_date = $key;
			// 					$prev_trade_2 = $val;
			// 					break;
			// 				case 3: // Workaround for weekend data (currencies)
			// 					$prev_trade_3_date = $key;
			// 					$prev_trade_3 = $val;
			// 					break;
			// 				default:
			// 					continue;
			// 			}
			// 			++$i;
			// 		}

			// 		$last_open   = $last_trade['1. open'];
			// 		$last_high   = $last_trade['2. high'];
			// 		$last_low    = $last_trade['3. low'];
			// 		$last_close  = $last_trade['4. close'];
			// 		$last_volume = (int) $last_trade['5. volume'];

			// 		$prev_open   = $prev_trade['1. open'];
			// 		$prev_high   = $prev_trade['2. high'];
			// 		$prev_low    = $prev_trade['3. low'];
			// 		$prev_close  = $prev_trade['4. close'];
			// 		$prev_volume = (int) $prev_trade['5. volume'];

			// 		// Try fallback for previous data if AV return zero for second day
			// 		if ( '0.0000' == $prev_open ) {
			// 			$prev_open   = $prev_trade_2['1. open'];
			// 			// 3rd day (weekend)
			// 			if ( '0.0000' == $prev_open ) {
			// 				$prev_open   = $prev_trade_3['1. open'];
			// 			}
			// 		}
			// 		if ( '0.0000' == $prev_high ) {
			// 			$prev_high   = $prev_trade_2['2. high'];
			// 			// 3rd day (weekend)
			// 			if ( '0.0000' == $prev_high ) {
			// 				$prev_high   = $prev_trade_3['2. high'];
			// 			}
			// 		}
			// 		if ( '0.0000' == $prev_low ) {
			// 			$prev_low    = $prev_trade_2['3. low'];
			// 			// 3rd day (weekend)
			// 			if ( '0.0000' == $prev_low ) {
			// 				$prev_low    = $prev_trade_3['3. low'];
			// 			}
			// 		}
			// 		if ( '0.0000' == $prev_close ) {
			// 			$prev_close  = $prev_trade_2['4. close'];
			// 			// 3rd day (weekend)
			// 			if ( '0.0000' == $prev_close ) {
			// 				$prev_close  = $prev_trade_3['4. close'];
			// 			}
			// 		}

			// 		// Volume (1st day)
			// 		if ( 0 == $last_volume ) {
			// 			// 2nd day
			// 			$last_volume = (int) $prev_trade['5. volume'];
			// 			// 3rd day
			// 			if ( 0 == $last_volume ) {
			// 				$last_volume = (int) $prev_trade_2['5. volume'];
			// 				// 4th day
			// 				if ( 0 == $last_volume ) {
			// 					$last_volume = (int) $prev_trade_3['5. volume'];
			// 				}
			// 			}
			// 		}

			// 		// The difference between 2017-09-01's close price and 2017-08-31's close price gives you the "Change" value.
			// 		$change = $last_close - $prev_close;
			// 		// So the gain on Friday was 25.92 (5025.92 - 5000) or 0.52% (25.92/5000 x 100%). No mystery!
			// 		$change_p = ( $change / $prev_close ) * 100;
			// 		// if we got INF, fake changep to 0
			// 		if ( 'INF' == $change_p ) {
			// 			$change_p = 0;
			// 		}

			// 		// The high and low prices combined give you the "Range" information
			// 		$range = "$last_low - $last_high";

			// 		// unset( $json );
			// 		$data_arr = array(
			// 			't'   => $symbol, // $ticker_symbol,
			// 			'c'   => $change,
			// 			'cp'  => $change_p,
			// 			'l'   => $last_close,
			// 			'lt'  => $last_trade_refresh,
			// 			'ltz' => $last_trade_tz,
			// 			'r'   => $range,
			// 			'o'   => $last_open,
			// 			'h'   => $last_high,
			// 			'low' => $last_low,
			// 			'v'   => $last_volume,
			// 		);
			// 		$data_arr['raw'] = $json;

			// 	}
			// 	unset( $response_arr );
			// }

			// return $data_arr;

		} // END function fetch_alphavantage_feed( $symbol )

		private function lock_fetch() {
			update_option( 'stockticker_av_progress', true );
			return;
		}
		private function unlock_fetch() {
			update_option( 'stockticker_av_progress', false );
			return;
		}
		public static function log( $str ) {
			// Only if WP_DEBUG is enabled
			if ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
				$log_file = trailingslashit( WP_CONTENT_DIR ) . 'stockticker.log';
				$date = date( 'c' );
				// error_log( "{$date}: {$str}\n", 3, $log_file );
			}
		}
	} // END class Wpau_Stock_Ticker

} // END if(!class_exists('Wpau_Stock_Ticker'))

if ( class_exists( 'Wpau_Stock_Ticker' ) ) {
	// Instantiate the plugin class.
	global $wpau_stockticker;
	if ( empty( $wpau_stockticker ) ) {
		$wpau_stockticker = new Wpau_Stock_Ticker();
	}
} // END class_exists( 'Wpau_Stock_Ticker' )
