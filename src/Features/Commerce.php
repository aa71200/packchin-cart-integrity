<?php
declare(strict_types=1);

namespace Packchin\Kits;

/** Session locks, idempotency and snapshot rollback adapt Hamsaz WooCommerceAdapter. */
final class Commerce {
	public static bool $mutating = false;

	public static function session(): void {
		if ( ! WC()->cart || ! WC()->session ) {
			wc_load_cart();
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	public static function token( ?int $tick = null ): string {
		self::session();
		return hash_hmac( 'sha256', 'packchin|' . WC()->session->get_customer_id() . '|' . ( $tick ?? wp_nonce_tick( 'packchin_cart' ) ), wp_salt( 'nonce' ) );
	}

	public static function verify_token( ?string $token ): bool {
		if ( ! $token ) {
			return false;
		}
		return hash_equals( self::token(), $token ) || hash_equals( self::token( (int) wp_nonce_tick( 'packchin_cart' ) - 1 ), $token );
	}

	public function register(): void {
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart' ), 20 );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'validate_cart' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_item' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'quantity_html' ), 10, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'quantity_validation' ), 10, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $this, 'editable_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'classic_remove_link' ), 10, 2 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks' ) );
	}

	public function definition( string $config_id, ?string $version_id = null ): array {
		$repo    = new Repository();
		$config  = $repo->get( $config_id );
		$version = $config ? $repo->version( $version_id ? $version_id : ( $config['versionId'] ?? '' ) ) : null;
		if ( ! $config || $config['trashed'] || ! $version || 'published' !== $version['kind'] || $version['configuration_id'] !== $config_id ) {
			Support::fail( __( 'این پیکربندی منتشرشده در دسترس نیست.', 'packchin-kits-for-woocommerce' ), 404 );
		}
		return array(
			'config'     => $config,
			'version'    => $version,
			'definition' => $version['definition'],
		);
	}

	private function lines( array $d, array $selection, array $services, int $bundle ): array {
		$lines = array();
		foreach ( $d['steps'] as $step ) {
			foreach ( $selection[ $step['id'] ] ?? array() as $entry ) {
				$lines[] = array(
					'productId' => (int) $entry['id'],
					'qty'       => $entry['qty'] * $bundle,
					'perBundle' => $entry['qty'],
					'stepId'    => $step['id'],
					'step'      => $step['name'],
					'serviceId' => '',
				);
			}
		}
		foreach ( $services as $service ) {
			$lines[] = array(
				'productId' => (int) $service['productId'],
				'qty'       => $service['qty'],
				'perBundle' => intdiv( $service['qty'], $bundle ),
				'stepId'    => 'service',
				'step'      => $service['name'],
				'serviceId' => $service['id'],
			);
		}
		return $lines;
	}

	private function lock( callable $callback ): mixed {
		global $wpdb;
		self::session();
		// The mother and Packchin share WooCommerce's session, so use the same mutex.
		$key = 'pcfg-cart:' . substr( hash( 'sha256', $wpdb->prefix . '|' . WC()->session->get_customer_id() ), 0, 48 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $key ) ) ) {
			Support::fail( __( 'درخواست دیگری در حال تغییر سبد است؛ دوباره تلاش کنید.', 'packchin-kits-for-woocommerce' ), 409 );
		}
		try {
			if ( WC()->session instanceof \WC_Session_Handler ) {
				wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . WC()->session->get_customer_id(), WC_SESSION_CACHE_GROUP );
				$saved = WC()->session->get_session_data();
				if ( is_array( $saved ) ) {
					foreach ( array( 'cart', 'cart_totals', 'removed_cart_contents', 'applied_coupons', 'coupon_discount_totals', 'coupon_discount_tax_totals', 'packchin_operations', 'pcfg_operations' ) as $name ) {
						WC()->session->set( $name, isset( $saved[ $name ] ) ? maybe_unserialize( $saved[ $name ] ) : null );
					}
					( new \WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
				}
			}
			$result = $callback();
			( new \WC_Cart_Session( WC()->cart ) )->set_session();
			WC()->session->save_data();
			return $result;
		} finally {
			self::$mutating = false;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	public function add( array $body ): array {
		return $this->lock(
			function () use ( $body ): array {
				$id = Support::id( $body['id'] ?? null );
				$group_id = isset( $body['groupId'] ) && $body['groupId'] ? Support::id( $body['groupId'] ) : null;
				$group = $group_id ? $this->group( $group_id ) : null;
				if ( $group_id && ( ! $group || $group['packchin_id'] !== $id ) ) {
					Support::fail( __( 'مجموعه در سبد این نشست پیدا نشد.', 'packchin-kits-for-woocommerce' ), 404 );
				}
				$resolved = $this->definition( $id, $group['packchin_version'] ?? null );
				$d = $resolved['definition'];
				if ( $resolved['version']['id'] !== ( $body['versionId'] ?? null ) ) {
					Support::fail( __( 'نسخهٔ پک تغییر کرده است. صفحه را تازه کنید.', 'packchin-kits-for-woocommerce' ), 409 );
				}
				$bundle = 1;
				$engine       = new RuleEngine( array(), $group_id, $bundle );
				$selection    = $engine->selections( $d, $body['selections'] ?? array() );
				$services     = (array) ( $body['services'] ?? array() );
				$request_id   = Support::id( $body['requestId'] ?? null );
				$payload_hash = hash( 'sha256', Support::json( array( $resolved['version']['id'], $group_id, $selection, $services, $bundle ) ) );
				$operations   = (array) WC()->session->get( 'packchin_operations', array() );
				if ( isset( $operations[ $request_id ] ) ) {
					if ( ! hash_equals( $operations[ $request_id ]['payload'], $payload_hash ) ) {
						Support::fail( __( 'شناسه درخواست برای انتخاب دیگری استفاده شده است.', 'packchin-kits-for-woocommerce' ), 409 );
					}
					return $this->summary();
				}
				$quote = $engine->assess( $d, $selection, $services );
				if ( ! $quote['ok'] ) {
					Support::fail( implode( "\n", $quote['errors'] ) );
				}
				if ( ! is_string( $body['priceToken'] ?? null ) || ! hash_equals( $quote['priceToken'], $body['priceToken'] ) ) {
					throw new PriceChanged( $quote );
				}
				$snapshot       = WC()->cart->cart_contents;
				$removed        = WC()->cart->removed_cart_contents;
				$group_id     ??= wp_generate_uuid4();
				self::$mutating = true;
				try {
					foreach ( WC()->cart->get_cart() as $key => $item ) {
						if ( ( $item['packchin_group'] ?? '' ) === $group_id ) {
							WC()->cart->remove_cart_item( $key );
						}
					}
					foreach ( $this->lines( $d, $selection, $quote['services'], $bundle ) as $line ) {
						$p          = wc_get_product( $line['productId'] );
						$parent     = $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id();
						$variation  = $p->is_type( 'variation' ) ? $p->get_id() : 0;
						$attributes = $variation ? $p->get_variation_attributes() : array();
						$meta       = array(
							'packchin_group'      => $group_id,
							'packchin_id'         => $resolved['config']['id'],
							'packchin_title'      => $d['name'],
							'packchin_version'    => $resolved['version']['id'],
							'packchin_hash'       => $resolved['version']['content_hash'],
							'packchin_selection'  => $selection,
							'packchin_services'   => $services,
							'packchin_bundle'     => $bundle,
							'packchin_step'       => $line['stepId'],
							'packchin_label'      => $line['step'],
							'packchin_per_bundle' => $line['perBundle'],
							'packchin_service'    => $line['serviceId'],
						);
						if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent, $line['qty'], $variation, $attributes, $meta ) || ! WC()->cart->add_to_cart( $parent, $line['qty'], $variation, $attributes, $meta ) ) {
							Support::fail( __( 'یکی از اقلام اضافه نشد؛ سبد قبلی حفظ شد.', 'packchin-kits-for-woocommerce' ) );
						}
					}
					WC()->cart->calculate_totals();
					$operations[ $request_id ] = array(
						'payload' => $payload_hash,
						'at'      => time(),
					);
					$operations                = array_filter( $operations, static fn( array $op ): bool => $op['at'] > time() - DAY_IN_SECONDS );
					WC()->session->set( 'packchin_operations', array_slice( $operations, -100, null, true ) );
				} catch ( \Throwable $error ) {
					WC()->cart->cart_contents         = $snapshot;
					WC()->cart->removed_cart_contents = $removed;
					WC()->cart->calculate_totals();
					throw $error;
				} finally {
					self::$mutating = false;
				}
				return $this->summary();
			}
		);
	}

	public function group( string $id ): ?array {
		self::session();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ( $item['packchin_group'] ?? '' ) === $id ) {
				return $item;
			}
		}
		return null;
	}

	public function remove( string $id ): array {
		return $this->lock(
			function () use ( $id ): array {
				$snapshot = WC()->cart->cart_contents;
				$removed = WC()->cart->removed_cart_contents;
				self::$mutating = true;
				try {
					foreach ( WC()->cart->get_cart() as $key => $item ) {
						if ( ( $item['packchin_group'] ?? '' ) === $id ) {
							WC()->cart->remove_cart_item( $key );
						}
					}
					WC()->cart->calculate_totals();
				} catch ( \Throwable $error ) {
					WC()->cart->cart_contents = $snapshot;
					WC()->cart->removed_cart_contents = $removed;
					WC()->cart->calculate_totals();
					throw $error;
				}
				return $this->summary();
			}
		);
	}

	public function summary(): array {
		self::session();
		$groups = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( empty( $item['packchin_group'] ) ) {
				continue;
			}
			$id = $item['packchin_group'];
			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'id'             => $id,
					'configId'       => $item['packchin_id'],
					'name'           => $item['packchin_title'],
					'bundleQuantity' => $item['packchin_bundle'],
					'lines'          => array(),
					'services'       => array(),
					'totalMinor'     => 0,
					'editUrl'        => add_query_arg(
						array(
							'packchin_kit'   => $item['packchin_id'],
							'packchin_group' => $id,
						),
						home_url( '/' )
					),
				);
			}
			$price                        = Support::minor( wc_get_price_to_display( $item['data'] ) );
			$line                         = array(
				'id'       => (string) $item['data']->get_id(),
				'name'     => $item['data']->get_name(),
				'qty'      => (int) $item['quantity'],
				'price'    => Support::major( $price ),
				'step'     => $item['packchin_label'],
				'stepId'   => $item['packchin_step'],
				'stockKey' => (string) $item['data']->get_stock_managed_by_id(),
			);
			$groups[ $id ]['totalMinor'] += $price * $line['qty'];
			if ( $item['packchin_service'] ) {
				$groups[ $id ]['services'][] = array(
					'id'    => $item['packchin_service'],
					'name'  => $item['packchin_label'],
					'qty'   => $line['qty'],
					'price' => Support::major( $price * $line['qty'] ),
				);
			} else {
				$groups[ $id ]['lines'][] = $line;
			}
		}
		foreach ( $groups as &$group ) {
			$group['total'] = Support::major( $group['totalMinor'] );
			unset( $group['totalMinor'] );
		}
		return array(
			'groups'      => array_values( $groups ),
			'cartUrl'     => wc_get_cart_url(),
			'checkoutUrl' => wc_get_checkout_url(),
			'token'       => self::token(),
			'itemCount'   => WC()->cart->get_cart_contents_count(),
		);
	}

	public function validate_cart( mixed $error = null ): void {
		if ( self::$mutating || ! WC()->cart || ( ! $error instanceof \WP_Error && defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$groups = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['packchin_group'] ) ) {
				$groups[ $item['packchin_group'] ][] = $item;
			}
		}
		foreach ( $groups as $id => $items ) {
			try {
				$first     = $items[0];
				$resolved  = $this->definition( $first['packchin_id'], $first['packchin_version'] );
				$engine    = new RuleEngine( array(), $id, (int) $first['packchin_bundle'] );
				$selection = $engine->selections( $resolved['definition'], $first['packchin_selection'] );
				$quote     = $engine->assess( $resolved['definition'], $selection, $first['packchin_services'] );
				if ( ! $quote['ok'] ) {
					Support::fail( implode( ' ', $quote['errors'] ) );
				}
				$expected = array();
				foreach ( $this->lines( $resolved['definition'], $selection, $quote['services'], (int) $first['packchin_bundle'] ) as $line ) {
					$expected[ $line['stepId'] . ':' . $line['productId'] . ':' . $line['serviceId'] ] = $line['qty'];
				}
				if ( count( $expected ) !== count( $items ) ) {
					Support::fail( __( 'تعداد اقلام مجموعه تغییر کرده است.', 'packchin-kits-for-woocommerce' ) );
				}
				foreach ( $items as $item ) {
					$key = $item['packchin_step'] . ':' . $item['data']->get_id() . ':' . $item['packchin_service'];
					if ( ! isset( $expected[ $key ] ) || $expected[ $key ] !== (int) $item['quantity'] || $item['packchin_selection'] !== $selection || $item['packchin_services'] !== $first['packchin_services'] || $item['packchin_version'] !== $first['packchin_version'] || $item['packchin_hash'] !== $resolved['version']['content_hash'] ) {
						Support::fail( __( 'اقلام مجموعه باید با هم ویرایش شوند.', 'packchin-kits-for-woocommerce' ) );
					}
					unset( $expected[ $key ] );
				}
			} catch ( \Throwable $failure ) {
				/* translators: 1: Configuration name, 2: Validation error details. */
				$message = sprintf( __( 'مجموعه «%1$s» نیاز به اصلاح دارد: %2$s', 'packchin-kits-for-woocommerce' ), $items[0]['packchin_title'] ?? '', $failure->getMessage() );
				if ( $error instanceof \WP_Error ) {
					$error->add( 'packchin_invalid_group', $message );
				} else {
					wc_add_notice( $message, 'error' );
				}
			}
		}
	}

	public function checkout_validation( array $data, \WP_Error $errors ): void {
		$this->validate_cart( $errors );
	}

	public function order_item( \WC_Order_Item_Product $item, string $key, array $values, \WC_Order $order ): void {
		if ( empty( $values['packchin_group'] ) ) {
			return;
		}
		foreach ( $values as $name => $value ) {
			if ( str_starts_with( $name, 'packchin_' ) ) {
				$item->add_meta_data( '_' . $name, $value, true );
			}
		}
		$item->add_meta_data( __( 'مجموعه', 'packchin-kits-for-woocommerce' ), $values['packchin_title'], true );
		$item->add_meta_data( __( 'بخش', 'packchin-kits-for-woocommerce' ), $values['packchin_label'], true );
	}

	public function item_data( array $data, array $item ): array {
		if ( ! empty( $item['packchin_group'] ) ) {
			$data[] = array(
				'key'   => __( 'مجموعه', 'packchin-kits-for-woocommerce' ),
				'value' => $item['packchin_title'],
			);
			$data[] = array(
				'key'   => __( 'بخش', 'packchin-kits-for-woocommerce' ),
				'value' => $item['packchin_label'],
			);
		}
		return $data;
	}

	public function quantity_html( string $html, string $key, array $item ): string {
		return isset( $item['packchin_group'] ) ? '<span>' . esc_html( (string) $item['quantity'] ) . '</span>' : $html;
	}

	public function editable_quantity( bool $editable, \WC_Product $product, ?array $item ): bool {
		return ! self::$mutating && ! empty( $item['packchin_group'] ) ? false : $editable;
	}

	public function quantity_validation( bool $passed, string $key, array $item, mixed $quantity ): bool {
		if ( ! self::$mutating && isset( $item['packchin_group'] ) && (int) $quantity !== (int) $item['quantity'] ) {
			wc_add_notice( __( 'تعداد مجموعه را از بخش ویرایش مجموعه تغییر دهید.', 'packchin-kits-for-woocommerce' ), 'error' );
			return false;
		}
		return $passed;
	}

	public function classic_remove_link( string $link, string $key ): string {
		$item = WC()->cart->get_cart_item( $key );
		return empty( $item['packchin_group'] ) ? $link : '';
	}

	public function register_blocks(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'packchin-kits-for-woocommerce',
				'data_callback'   => fn(): array => array( 'groups' => $this->summary()['groups'] ),
				'schema_callback' => static fn(): array => array(
					'groups' => array(
						'description' => 'Configuration groups in the current cart.',
						'type'        => 'array',
						'readonly'    => true,
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'packchin-kits-for-woocommerce',
				'data_callback'   => static fn( array $item ): array => array( 'group' => (string) ( $item['packchin_group'] ?? '' ) ),
				'schema_callback' => static fn(): array => array(
					'group' => array(
						'description' => 'Configuration group identifier.',
						'type'        => 'string',
						'readonly'    => true,
					),
				),
				'schema_type'     => ARRAY_A,
			)
		);
	}
}
