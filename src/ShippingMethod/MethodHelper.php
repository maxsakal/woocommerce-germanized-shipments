<?php

namespace Vendidero\Germanized\Shipments\ShippingMethod;

use Vendidero\Germanized\Shipments\Labels\ConfigurationSet;
use Vendidero\Germanized\Shipments\ShippingProvider\Method;

defined( 'ABSPATH' ) || exit;

class MethodHelper {

	protected static $provider_method_settings = null;

    protected static $methods = array();

	/**
	 * Hook in methods.
	 */
	public static function init() {
		// Use a high priority here to make sure we are hooking even after plugins such as flexible shipping
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'set_method_filters' ), 5000, 1 );

		add_filter( 'woocommerce_generate_shipping_provider_method_tabs_html', array( __CLASS__, 'render_method_tabs' ), 10, 4 );
		add_filter( 'woocommerce_generate_shipping_provider_method_zone_override_open_html', array( __CLASS__, 'render_zone_override' ), 10, 4 );
		add_filter( 'woocommerce_generate_shipping_provider_method_zone_override_close_html', array( __CLASS__, 'render_zone_override_close' ), 10, 4 );
		add_filter( 'woocommerce_generate_shipping_provider_method_tabs_open_html', array( __CLASS__, 'render_method_tab_content' ), 10, 4 );
		add_filter( 'woocommerce_generate_shipping_provider_method_tabs_close_html', array( __CLASS__, 'render_method_tab_content_close' ), 10, 4 );
		add_filter( 'woocommerce_generate_shipping_provider_method_configuration_sets_html', array( __CLASS__, 'render_method_configuration_sets' ), 10 );
	}

    public static function render_method_configuration_sets() {
        return '';
    }

	public static function set_method_filters( $methods ) {
		foreach ( $methods as $method => $class ) {
			if ( in_array( $method, self::get_excluded_methods(), true ) ) {
				continue;
			}

			/**
			 * Update during save
			 */
			add_filter( 'woocommerce_shipping_' . $method . '_instance_settings_values', array( __CLASS__, 'filter_method_settings' ), 10, 2 );
			/**
			 * Register additional setting fields
			 */
			add_filter( 'woocommerce_shipping_instance_form_fields_' . $method, array( __CLASS__, 'add_method_settings' ), 10, 1 );
			/**
			 * Lazy-load option values
			 */
			add_filter( 'woocommerce_shipping_' . $method . '_instance_option', array( __CLASS__, 'filter_method_option_value' ), 10, 3 );

			/**
			 * Use this filter as a backup to support plugins like Flexible Shipping which may override methods
			 */
			add_filter( 'woocommerce_settings_api_form_fields_' . $method, array( __CLASS__, 'add_method_settings' ), 10, 1 );
		}

		return $methods;
	}

	/**
	 * @param \WC_Shipping_Method $method
	 *
	 * @return Method
	 */
    public static function get_method( $method ) {
        $method_id = $method->id . '_' . $method->get_instance_id();

        if ( ! array_key_exists( $method_id, self::$methods ) ) {
            self::$methods[ $method_id ] = new Method( $method );
        }

	    return self::$methods[ $method_id ];
    }

	public static function get_excluded_methods() {
		return apply_filters( 'woocommerce_gzd_shipments_get_methods_excluded_from_provider_settings', array( 'pr_dhl_paket', 'flexible_shipping_info' ) );
	}

	public static function validate_method_zone_override( $value ) {
		return ! is_null( $value ) ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value
	 * @param mixed $setting_id
	 * @param \WC_Shipping_Method $method
	 *
	 * @return mixed
	 */
	public static function filter_method_option_value( $value, $setting_id, $method ) {
        $shipping_method = self::get_method( $method );

		if ( $shipping_method->is_configuration_set_setting( $setting_id ) ) {
            if ( $configuration_set = $shipping_method->get_configuration_set( $setting_id ) ) {
                $suffix = $shipping_method->get_configuration_setting_suffix( $setting_id );

                if ( 'override' === $suffix ) {
                    return 'yes';
                } else {
	                return $configuration_set->has_setting( $setting_id ) ? $configuration_set->get_setting( $setting_id ) : $value;
                }
            }
		}

		return $value;
	}

	/**
	 * @param array $p_settings
	 * @param \WC_Shipping_Method $method
	 *
	 * @return array
	 */
	public static function filter_method_settings( $p_settings, $method ) {
		$shipping_provider = isset( $p_settings['shipping_provider'] ) ? $p_settings['shipping_provider'] : '';
        $method            = self::get_method( $method );

        $method->set_shipping_provider( $shipping_provider );

		foreach( $p_settings as $setting_id => $setting_val ) {
            if ( 'configuration_sets' === $setting_id ) {
                unset( $p_settings[ $setting_id ] );
            } elseif ( $method->is_configuration_set_setting( $setting_id ) ) {
                $args = $method->get_configuration_set_args_by_id( $setting_id );

                if ( ! empty( $args['shipping_provider_name'] ) && $args['shipping_provider_name'] === $method->get_shipping_provider() ) {
                    if ( 'override' === $args['setting_name'] && wc_string_to_bool( $setting_val ) ) {
                        if ( $config_set = $method->get_or_create_configuration_set( $args ) ) {
                            $config_set->update_setting( $setting_id, $setting_val );
                        }
                    } elseif ( $config_set = $method->get_configuration_set( $args ) ) {
                        $config_set->update_setting( $setting_id, $setting_val );
                    }
                }

                unset( $p_settings[ $setting_id ] );
			}
		}

		$p_settings['configuration_sets'] = $method->get_configuration_sets();

        return $p_settings;
	}

	public static function add_method_settings( $p_settings ) {
		$wc = WC();

		/**
		 * Prevent undefined index notices during REST API calls.
		 *
		 * @see WC_REST_Shipping_Zone_Methods_V2_Controller::get_settings()
		 */
		if ( is_callable( array( $wc, 'is_rest_api_request' ) ) && $wc->is_rest_api_request() ) {
			return $p_settings;
		}

		$shipping_provider_settings = self::get_method_settings();

		return array_merge( $p_settings, $shipping_provider_settings );
	}

	protected static function load_all_method_settings() {
		$screen                  = function_exists( 'get_current_screen' ) ? get_current_screen() : false;
		$load_all_setting_fields = false;

		if ( $screen && isset( $screen->id ) && 'woocommerce_page_wc-settings' === $screen->id ) {
			$load_all_setting_fields = true;
		}

		if (
			doing_action( 'wp_ajax_woocommerce_shipping_zone_methods_save_settings' ) ||
			doing_action( 'wp_ajax_woocommerce_shipping_zone_add_method' ) ||
			doing_action( 'wp_ajax_woocommerce_shipping_zone_remove_method' )
		) {
			$load_all_setting_fields = true;
		}

		return $load_all_setting_fields;
	}

	public static function get_method_settings( $force_load_all = false ) {
		$load_all_settings = $force_load_all ? true : self::load_all_method_settings();
		$method_settings   = array(
			'label_configuration_set_shipping_provider_title' => array(
				'title'       => _x( 'Shipping Provider Settings', 'shipments', 'woocommerce-germanized-shipments' ),
				'type'        => 'title',
                'id'          => 'label_configuration_set_shipping_provider_title',
				'default'     => '',
				'description' => _x( 'Adjust shipping provider settings used for managing shipments.', 'shipments', 'woocommerce-germanized-shipments' ),
			),
			'shipping_provider' => array(
				'title'       => _x( 'Shipping Provider', 'shipments', 'woocommerce-germanized-shipments' ),
				'type'        => 'select',
				/**
				 * Filter to adjust default shipping provider pre-selected within shipping provider method settings.
				 *
				 * @param string $provider_name The shipping provider name e.g. dhl.
				 *
				 * @since 3.0.6
				 * @package Vendidero/Germanized/Shipments
				 */
				'default'     => apply_filters( 'woocommerce_gzd_shipping_provider_method_default_provider', '' ),
				'options'     => wc_gzd_get_shipping_provider_select(),
				'description' => _x( 'Choose a shipping provider which will be selected by default for an eligible shipment.', 'shipments', 'woocommerce-germanized-shipments' ),
			),
			'configuration_sets' => array(
				'title'       => '',
				'type'        => 'shipping_provider_method_configuration_sets',
				'default'     => array(),
			),
		);

		if ( $load_all_settings ) {
			if ( is_null( self::$provider_method_settings ) ) {
				self::$provider_method_settings = array();

				foreach ( wc_gzd_get_shipping_providers() as $provider ) {
					if ( ! $provider->is_activated() ) {
						continue;
					}

					self::$provider_method_settings[ $provider->get_name() ] = $provider->get_shipping_method_settings();
				}
			}

			$supported_zones = array_keys( wc_gzd_get_shipping_label_zones() );

			foreach( self::$provider_method_settings as $provider => $zone_settings ) {
				$provider_tabs           = array();
				$provider_inner_settings = array();

				foreach( $zone_settings as $zone => $shipment_type_settings ) {
					if ( ! in_array( $zone, $supported_zones ) ) {
						continue;
					}

					foreach( $shipment_type_settings as $shipment_type => $settings ) {
						if ( ! isset( $provider_inner_settings[ $shipment_type ] ) ) {
							$provider_inner_settings[ $shipment_type ] = array();
						}

						$provider_inner_settings[ $shipment_type ] = array_merge( $provider_inner_settings[ $shipment_type ], $settings );
						$provider_tabs[ $provider . "_" . $shipment_type ] = wc_gzd_get_shipment_label_title( $shipment_type );
					}
				}

				if ( ! empty( $provider_inner_settings ) ) {
					$tabs_open_id  = "label_config_set_tabs_{$provider}";

					$method_settings = array_merge( $method_settings, array(
						$tabs_open_id => array(
							'id'       => $tabs_open_id,
							'tabs'     => $provider_tabs,
							'type'     => 'shipping_provider_method_tabs',
							'default'  => '',
							'display_only' => true,
							'provider' => $provider,
						),
					) );

					$count = 0;

					foreach( $provider_inner_settings as $shipment_type => $settings ) {
						$count ++;

						$tabs_open_id  = "label_config_set_tabs_{$provider}_{$shipment_type}_open";
						$tabs_close_id = "label_config_set_tabs_{$provider}_{$shipment_type}_close";

						$method_settings = array_merge( $method_settings, array(
							$tabs_open_id => array(
								'id'       => $tabs_open_id,
								'type'     => 'shipping_provider_method_tabs_open',
								'tab'      => $provider . "_" . $shipment_type,
								'default'  => '',
								'provider' => $provider,
								'active'   => 1 === $count ? true : false,
							)
						) );

						$method_settings = array_merge( $method_settings, $settings );

						$method_settings = array_merge( $method_settings, array(
							$tabs_close_id => array(
								'id'       => $tabs_close_id,
								'type'     => 'shipping_provider_method_tabs_close',
								'tab'      => $provider . "_" . $shipment_type,
								'default'  => '',
								'provider' => $provider,
							)
						) );
					}
				}
			}
		}

		/**
		 * Append a stop title to make sure the table is closed within settings.
		 */
		$method_settings = array_merge(
			apply_filters( 'woocommerce_gzd_shipping_provider_method_admin_settings', $method_settings, $load_all_settings ),
			array(
				'label_configuration_set_shipping_provider_stop_title' => array(
					'title'   => '',
					'id'      => 'label_configuration_set_shipping_provider_stop_title',
					'type'    => 'title',
					'default' => '',
				),
			)
		);

		return $method_settings;
	}

	public static function render_method_tab_content_close( $html, $key, $value, $method ) {
		return '</table></div>';
	}

	public static function render_method_tab_content( $html, $key, $setting, $method ) {
		$setting = wp_parse_args( $setting, array(
			'active' => false,
			'id'     => '',
			'tab'    => '',
		) );

		return '</table><div class="wc-gzd-shipping-provider-method-tab-content ' . ( $setting['active'] ? 'tab-content-active' : '' ) . '" id="' . esc_attr( $setting['id'] ) . '" data-tab="' . esc_attr( $setting['tab'] ) . '">';
	}

	public static function render_zone_override_close( $html, $key, $setting, $method ) {
		return '</table></div></div>';
	}

	public static function render_zone_override( $html, $key, $setting, $method ) {
		$setting = wp_parse_args( $setting, array(
			'active' => false,
			'id'     => '',
			'tab'    => '',
			'class'  => '',
			'disabled' => false,
			'desc_tip' => '',
			'css'      => '',
		) );
		$field_key   = $method->get_field_key( $key );
		$field_value = $method->get_option( $key );
		ob_start();
		?>
        </table>
        <div class="wc-gzd-shipping-provider-override-wrapper">
        <div class="wc-gzd-shipping-provider-override-title-wrapper">
            <h3 class="wc-settings-sub-title <?php echo esc_attr( $setting['class'] ); ?>"><?php echo wp_kses_post( $setting['title'] ); ?></h3>

            <p class="override-checkbox">
                <label for="<?php echo esc_attr( $field_key ); ?>">
                    <input <?php disabled( $setting['disabled'], true ); ?> class="<?php echo esc_attr( $setting['class'] ); ?>" type="checkbox" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $setting['css'] ); ?>" value="1" <?php checked( $field_value, 'yes' ); ?> <?php echo $method->get_custom_attribute_html( $setting ); // WPCS: XSS ok. ?> />
					<?php echo wp_kses_post( _x( 'Override?', 'shipments', 'woocommerce-germanized-shipments' ) ); ?>
					<?php echo $method->get_tooltip_html( $setting ); // WPCS: XSS ok. ?>
                </label>
            </p>
        </div>
        <div class="wc-gzd-shipping-provider-override-inner-wrapper <?php echo esc_attr( 'yes' === $field_value ? 'has-override' : '' ); ?>">
        <table class="form-table">
		<?php
		$html = ob_get_clean();

		return $html;
	}

	public static function render_method_tabs( $html, $key, $setting, $method ) {
		$setting = wp_parse_args( $setting, array(
			'id'       => '',
			'tabs'     => array(),
			'provider' => '',
		) );
		$count = 0;
		ob_start();
		?>
        </table>
        <div class="wc-gzd-shipping-provider-method-tabs" id="<?php echo esc_attr( $setting['id'] ); ?>" data-provider="<?php echo esc_attr( $setting['provider'] ); ?>">
            <nav class="nav-tab-wrapper woo-nav-tab-wrapper shipments-nav-tab-wrapper">
				<?php foreach( $setting['tabs'] as $tab => $tab_title ) : $count++; ?>
                    <a class="nav-tab <?php echo 1 === $count ? esc_attr( 'nav-tab-active' ) : ''; ?>" href="#<?php echo esc_attr( $tab ); ?>" data-tab="<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $tab_title ); ?></a>
				<?php endforeach; ?>
            </nav>
        </div>
        <table>
		<?php
		$html = ob_get_clean();

		return $html;
	}
}