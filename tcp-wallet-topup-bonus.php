<?php
namespace TheCartPress;

use WC_Order;
/**
 * Plugin Name: TCP TeraWallet Topup Bonus
 * Plugin URI:
 * Description: TeraWallet extension to give bonus credit when customers topup their wallet.
 * Version: 1.3.0
 * Stable tag: 1.3.0
 * Requires PHP: 5.6
 * Requires at least: 5.5
 * Tested up to: 6.0
 * Author: TCP Team
 * Author URI: https://www.thecartpress.com
 * WC tested up to: 6.3.1
 */
defined('ABSPATH') or exit;

class TCP_topup_bonus {

	const ASSET_VERSION = 2;

	function __construct() {
		$tcp_f = __DIR__ . '/tcp.php';
		if (file_exists($tcp_f)) {
			require_once $tcp_f;
		}
		tcp_init_plugin($this, __FILE__);
		tcp_register_updater('tcp-wallet-topup-bonus', 'https://app.thecartpress.com/api/?op=check_update&view=json&pid=' . $this->plugin_id);
		if (!tcp_is_plugin_available('woocommerce', 'WooCommerce', 'woocommerce/woocommerce.php', $this->plugin_name)) {
			return;
		}
		if (!tcp_is_plugin_available('woo-wallet', 'TeraWallet', 'woo-wallet/woo-wallet.php', $this->plugin_name)) {
			return; // check woocommerce & terrawallet is active
		}
		$this->settings_url = admin_url('admin.php?page=woo-wallet-settings&activewwtab=_wallet_settings_topup_bonus');
		tcp_add_menu(
			'tcp-wallet-topup-bonus',
			__('TCP Wallet Topup Bonus'),
			__('Wallet Topup Bonus'),
			'tcp_wallet_topup_bonus'
		);

		add_filter('plugin_action_links_'. $this->plugin_basename, [$this, 'plugin_links']);
		add_filter('woo_wallet_settings_sections', [$this, 'settings_sections']);
		add_filter('woo_wallet_settings_filds', [$this, 'settings_fields']);
		add_filter('woo_wallet_payment_is_available', [$this, 'wallet_is_available'], 20);

		add_action('admin_enqueue_scripts', [$this, 'load_admin_js_and_css']);
		add_action('woo_wallet_menu_content', [$this, 'menu_content']);
		add_action('woo_wallet_transaction_recorded', [$this, 'transaction_recorded'], 20, 4);
		add_action('woo_wallet_credit_purchase_completed', [$this, 'credit_purchase_completed'], 20, 2);
		add_action('woocommerce_before_cart_table', [$this, 'before_cart_table']);
		add_action('woocommerce_before_checkout_form', [$this, 'before_cart_table']);
		add_action('thecartpress_page_tcp_wallet_topup_bonus', [$this, 'redirect_wallet_settings_page']);

	}

	//----------------------------------------------------------------------------
	// hooks
	//----------------------------------------------------------------------------

  function plugin_links($links) {
    $plugin_links = [
      '<a href="'. esc_url($this->settings_url) .'">'. __('Settings') .'</a>'
    ];
    return array_merge($plugin_links, $links);
  }

	function settings_sections($sections) {
		$sections[] = [
			'id' => '_wallet_settings_topup_bonus',
			'title' => __('Topup Bonus'),
			'icon' => 'dashicons-admin-generic',
		];
		return $sections;
	}

	function settings_fields($fields) {
		$amount_type = woo_wallet()->settings_api->get_option('topup_bonus_amount_type', '_wallet_settings_topup_bonus', 'percent');
		$amount_symbol = $amount_type == 'percent' ? '%' : get_woocommerce_currency_symbol();
		$fields['_wallet_settings_topup_bonus'] = [
			[
				'name' => 'is_enable_topup_bonus',
				'label' => __('Enable'),
				'desc' => __('Enable topup bonus on your store'),
				'type' => 'checkbox',
			],
			[
				'name' => 'topup_bonus_amount_type',
				'label' => __('Bonus Amount Type'),
				'desc' => __('Select type of bonus amount'),
				'type' => 'select',
				'options' => [
					'percent' => __('Percentage'),
					'fixed' => __('Fixed'),
				],
				'default' => 'percent',
				'size' => 'regular-text wc-enhanced-select',
			],
			[
				'name' => 'topup_bonus_amount',
				'label' => __('Bonus Amount'),
				'desc' => sprintf(__('Topup bonus amount (%s)'), $amount_symbol),
				'type' => 'number',
				'step' => '0.01',
				'sanitize_callback' => [$this, 'sanitize_topup_bonus_amount'],
			],
			[
				'name' => 'min_topup_amount',
				'label' => __('Minimum Topup Amount'),
				'desc' => sprintf(__('Minimum topup amount to be eligible for bonus (%s)'), get_woocommerce_currency_symbol()),
				'type' => 'number',
				'step' => '0.01',
				'sanitize_callback' => [$this, 'sanitize_min_topup_amount'],
			],
		];
		return $fields;
	}

	function sanitize_topup_bonus_amount($value) {
		$amount_type = woo_wallet()->settings_api->get_option('topup_bonus_amount_type', '_wallet_settings_topup_bonus', 'percent');
		if ($amount_type == 'percent') {
			if ($value < 0 || $value > 100) {
				$value = 0;
			}
		} else if ($amount_type == 'fixed') {
			if ($value < 0) {
				$value = 0;
			}
		}
		return $value;
	}

	function sanitize_min_topup_amount($value) {
		$settings = get_option('_wallet_settings_general');
		if (is_array($settings)) {
			$min = isset($settings['min_topup_amount']) ? $settings['min_topup_amount'] : 0;
			$max = isset($settings['max_topup_amount']) ? $settings['max_topup_amount'] : 0;
			if ($min > 0 && $value < $min) {
				$value = $max;
			}
			if ($max > 0 && $value > $max) {
				$value = $max;
			}
		}
		return $value;
	}

	function wallet_is_available($available) {
		if (WC()->cart) {
			$all_fees = WC()->cart->fees_api()->get_fees(); // woo_wallet_add_partial_payment_fee() - woo-wallet/includes/class-woo-wallet-frontend.php
			if (isset($all_fees['_via_wallet_partial_payment'])) {
				// bug: order that already used amount from wallet to pay partially,
				// still able to select wallet as payment gateway.
				// so, need to disable wallet gateway if cart already added partial payment from wallet
				$available = false;
			} else {
				// same bug as above, but above only check inside cart, this is for orders
				// that have been created with status=pending payment,
				// need to check order's fee for wallet partial payment & disable wallet gateway if found
				$order_id = absint(get_query_var('order-pay'));
				$order = wc_get_order($order_id);
				if ($order) {
					$fees = $order->get_fees();
					foreach ($fees as $fee) {
						if ($fee->get_meta('_legacy_fee_key') === '_via_wallet_partial_payment') {
							$available = false;
							break;
						}
					}
				}
			}
		}
		return $available;
	}

	function load_admin_js_and_css() {
		if (isset($_GET['page']) && $_GET['page'] == 'woo-wallet-settings') {
			wp_enqueue_script('twtb_topup_bonus_js', $this->plugin_url . '/js/topup_bonus.js', ['jquery'], $this->asset_version, true);
			wp_localize_script('twtb_topup_bonus_js', 'twtb_lang', [
				'topup_bonus_percent' => __('Topup bonus amount (%)'),
				'topup_bonus_fixed' => sprintf(__('Topup bonus amount (%s)'), get_woocommerce_currency_symbol())
			]);
		}
	}

	function menu_content() {
		if (!$this->is_enabled()) {
			return;
		}
		global $wp;
		$page = isset($wp->query_vars['woo-wallet']) ? $wp->query_vars['woo-wallet'] : '';
		if ($page == 'add') {
			$min = woo_wallet()->settings_api->get_option('min_topup_amount', '_wallet_settings_topup_bonus', 0);
			$bonus_amount = woo_wallet()->settings_api->get_option('topup_bonus_amount', '_wallet_settings_topup_bonus', 0);
			$amount_type = woo_wallet()->settings_api->get_option('topup_bonus_amount_type', '_wallet_settings_topup_bonus', 'percent');
			if ($bonus_amount > 0) {
				if ($amount_type == 'percent') {
					$amount = '<span class="amount">'. $bonus_amount . '%</span>';
				} else {
					$amount = wc_price($bonus_amount);
				}
				if ($min == 0) { ?>
					<div style="clear: both"></div>
					<p><?php echo sprintf(__('Topup and get %s bonus credit!'), $amount); ?></p><?php
				} else { ?>
					<div style="clear: both"></div>
					<p><?php echo sprintf(__('Topup minimum %s and get %s bonus credit!'), wc_price($min), $amount); ?></p><?php
				}
			}
		}
	}

	function transaction_recorded($transaction_id, $user_id, $amount, $type) {
		if (!$this->is_enabled(false)) {
			return;
		}
		if ($type == 'credit') {
			$topup_bonus = $this->get_bonus_amount($amount);
			if ($topup_bonus > 0) {
				update_user_meta($user_id, '_wwtb_topup_data', [
					'txn_id' => $transaction_id,
					'amount' => $amount,
					'bonus' => $topup_bonus,
				]);
			}
		}
	}

	function credit_purchase_completed($transaction_id, $order) {
		if (!$this->is_enabled(false)) {
			return;
		}
		$user_id = $order->get_customer_id();
		$topup_data = (array) get_user_meta($user_id, '_wwtb_topup_data', true);
		if (isset($topup_data['txn_id'], $topup_data['amount'], $topup_data['bonus']) && $transaction_id == $topup_data['txn_id']) {
			$tid = woo_wallet()->wallet->credit($user_id, $topup_data['bonus'], __('Topup bonus for purchase #') . $order->get_order_number());
			/**
			 * @param string $tid
			 * @param int $user_id
			 * @param double $topup_data[bonus]
			 * @param WC_Order $order
			 */
			do_action('twtb_topup_added', $tid, $user_id, $topup_data['bonus'], $order);
			delete_user_meta($user_id, '_wwtb_topup_data');
		}
	}

	function before_cart_table() {
		if ($this->is_enabled() && is_wallet_rechargeable_cart()) {
			foreach (wc()->cart->get_cart() as $cart_item) {
				if ($cart_item['product_id'] == get_wallet_rechargeable_product()->get_id()) {
					$amount = $cart_item['data']->get_price();
					$topup_bonus = $this->get_bonus_amount($amount);
					if ($topup_bonus > 0) {
						?>
						<div class="woocommerce-Message woocommerce-Message--info woocommerce-info">
							<?php echo sprintf(__('Upon placing this order a topup bonus of %s will be credited to your wallet.'), wc_price($topup_bonus)); ?>
						</div>
						<?php
					}
					break;
				}
			}
		}
	}

	function redirect_wallet_settings_page() {
		wp_redirect($this->settings_url);
		exit;
	}

	//----------------------------------------------------------------------------
	// functions
	//----------------------------------------------------------------------------

	function is_enabled($check_login = true) {
		$enabled = woo_wallet()->settings_api->get_option('is_enable_topup_bonus', '_wallet_settings_topup_bonus', 'off') == 'on';
		if ($check_login) {
			return $enabled && is_user_logged_in();
		}
		return is_user_logged_in();
	}

	function get_bonus_amount($topup_amount) {
		$topup_bonus = 0;
		if (!empty($topup_amount)) {
			$min = woo_wallet()->settings_api->get_option('min_topup_amount', '_wallet_settings_topup_bonus', 0);
			if ($min == 0 || $topup_amount >= $min) {
				$bonus_amount = woo_wallet()->settings_api->get_option('topup_bonus_amount', '_wallet_settings_topup_bonus', 0);
				$amount_type = woo_wallet()->settings_api->get_option('topup_bonus_amount_type', '_wallet_settings_topup_bonus', 'percent');
				if ($amount_type == 'percent') {
					$topup_bonus = $topup_amount * ($bonus_amount / 100.0);
				} else if ($amount_type == 'fixed') {
					$topup_bonus = $bonus_amount;
				}
			}
		}
		return $topup_bonus;
	}

}

new TCP_topup_bonus();