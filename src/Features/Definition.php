<?php
declare(strict_types=1);

namespace Packchin\Kits;

/** The editor document is data only. No HTML, PHP, selectors or executable rules. */
final class Definition {
	public const RULE_TYPES = array( 'match', 'dependency', 'allow', 'deny', 'numeric', 'count', 'capacity' );
	public const OPERATORS  = array( 'eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains' );

	private static function text( mixed $value, int $limit = 300 ): string {
		return mb_substr( sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	private static function list_ids( mixed $ids ): array {
		if ( ! is_array( $ids ) || count( $ids ) > 5000 ) {
			Support::fail( __( 'فهرست شناسه‌ها معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		return array_values( array_unique( array_map( static fn( mixed $id ): string => Support::id( (string) $id ), $ids ) ) );
	}

	public static function clean( array $data ): array {
		if ( ! isset( $data['name'], $data['steps'], $data['rules'], $data['appearance'], $data['services'] ) || ! is_array( $data['steps'] ) || ! is_array( $data['rules'] ) || ! is_array( $data['services'] ) || count( $data['steps'] ) > 100 || count( $data['rules'] ) > 300 || count( $data['services'] ) > 100 ) {
			Support::fail( __( 'ساختار پیکربندی معتبر نیست یا از محدوده پردازش بیشتر است.', 'packchin-kits-for-woocommerce' ) );
		}
		$result = array(
			'name'        => self::text( $data['name'], 80 ),
			'description' => self::text( $data['description'] ?? '' ),
			'kind'        => in_array( $data['kind'] ?? '', array( 'pc', 'gift', 'custom' ), true ) ? $data['kind'] : 'custom',
			'steps'       => array(),
			'rules'       => array(),
			'services'    => array(),
		);
		foreach ( $data['steps'] as $s ) {
			if ( ! is_array( $s ) || ! is_array( $s['source'] ?? null ) ) {
				Support::fail( __( 'ساختار مرحله معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$source = $s['source'];
			if ( ! in_array( $source['type'] ?? '', array( 'direct', 'category', 'attribute' ), true ) ) {
				Support::fail( __( 'منبع محصولات معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$result['steps'][] = array(
				'id'          => Support::id( $s['id'] ?? null ),
				'name'        => self::text( $s['name'] ?? '', 80 ),
				'description' => self::text( $s['description'] ?? '' ),
				'icon'        => sanitize_key( $s['icon'] ?? 'box' ),
				'required'    => ! empty( $s['required'] ),
				'multiple'    => ! empty( $s['multiple'] ),
				'min'         => self::quantity( $s['min'] ?? 0 ),
				'max'         => self::quantity( $s['max'] ?? 1 ),
				'deps'        => self::list_ids( $s['deps'] ?? array() ),
				'source'      => array(
					'type'      => $source['type'],
					'category'  => self::text( $source['category'] ?? '', 100 ),
					'ids'       => self::list_ids( $source['ids'] ?? array() ),
					'attribute' => sanitize_key( $source['attribute'] ?? '' ),
					'value'     => self::text( $source['value'] ?? '', 500 ),
				),
			);
		}
		foreach ( $data['rules'] as $r ) {
			if ( ! is_array( $r ) || ! in_array( $r['type'] ?? '', self::RULE_TYPES, true ) || ! in_array( $r['operator'] ?? 'eq', self::OPERATORS, true ) || ! is_array( $r['conditions'] ?? null ) || count( $r['conditions'] ) > 30 ) {
				Support::fail( __( 'ساختار قانون معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$conditions = array();
			foreach ( $r['conditions'] as $condition ) {
				if ( ! in_array( $condition['operator'] ?? '', self::OPERATORS, true ) ) {
					Support::fail( __( 'عملگر شرط معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
				}
				$conditions[] = array(
					'step'      => Support::id( $condition['step'] ?? null ),
					'attribute' => sanitize_key( $condition['attribute'] ?? '' ),
					'operator'  => $condition['operator'],
					'value'     => self::text( $condition['value'] ?? '', 500 ),
				);
			}
			$value  = $r['value'] ?? 0;
			$factor = $r['factor'] ?? 1;
			if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || abs( (float) $value ) > 1000000000 || ! is_numeric( $factor ) || (float) $factor < 1 || (float) $factor > 10 ) {
				Support::fail( __( 'مقادیر عددی قانون معتبر نیستند.', 'packchin-kits-for-woocommerce' ) );
			}
			$result['rules'][] = array(
				'id'           => Support::id( $r['id'] ?? null ),
				'name'         => self::text( $r['name'] ?? '', 100 ),
				'type'         => $r['type'],
				'target'       => self::text( $r['target'] ?? '', 100 ),
				'source'       => self::text( $r['source'] ?? '', 100 ),
				'enabled'      => ! empty( $r['enabled'] ),
				'logic'        => 'any' === ( $r['logic'] ?? 'all' ) ? 'any' : 'all',
				'conditions'   => $conditions,
				'sourceAttr'   => sanitize_key( $r['sourceAttr'] ?? '' ),
				'targetAttr'   => sanitize_key( $r['targetAttr'] ?? '' ),
				'operator'     => $r['operator'] ?? 'eq',
				'value'        => (float) $value,
				'factor'       => (float) $factor,
				'products'     => self::list_ids( $r['products'] ?? array() ),
				'capacityMode' => 'supply' === ( $r['capacityMode'] ?? 'consume' ) ? 'supply' : 'consume',
			);
		}
		foreach ( $data['services'] as $s ) {
			$result['services'][] = array(
				'id'        => Support::id( $s['id'] ?? null ),
				'name'      => self::text( $s['name'] ?? '', 100 ),
				'productId' => (string) absint( $s['productId'] ?? 0 ),
				'scope'     => 'products' === ( $s['scope'] ?? 'bundle' ) ? 'products' : 'bundle',
				'products'  => self::list_ids( $s['products'] ?? array() ),
				'enabled'   => ! empty( $s['enabled'] ),
			);
		}
		$a                    = is_array( $data['appearance'] ) ? $data['appearance'] : array();
		$result['appearance'] = array(
			'title'      => self::text( $a['title'] ?? $result['name'], 100 ),
			'subtitle'   => self::text( $a['subtitle'] ?? '', 250 ),
			'button'     => self::text( $a['button'] ?? __( 'افزودن مجموعه به سبد', 'packchin-kits-for-woocommerce' ), 80 ),
			'totalLabel' => self::text( $a['totalLabel'] ?? __( 'مجموع انتخاب‌ها', 'packchin-kits-for-woocommerce' ), 80 ),
			'styles'     => self::styles( $a['styles'] ?? array() ),
			'devices'    => array(),
		);
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$result['appearance']['devices'][ $device ] = self::styles( $a['devices'][ $device ] ?? array() );
		}
		return $result;
	}

	private static function quantity( mixed $v ): int {
		if ( ! is_numeric( $v ) || (float) (int) $v !== (float) $v || (int) $v < 0 || (int) $v > 10000 ) {
			Support::fail( __( 'تعداد باید یک عدد صحیح بین صفر تا ۱۰۰۰۰ باشد.', 'packchin-kits-for-woocommerce' ) );
		}
		return (int) $v;
	}

	private static function styles( mixed $styles ): array {
		if ( ! is_array( $styles ) || count( $styles ) > 150 ) {
			Support::fail( __( 'تنظیمات ظاهر معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		$result = array();
		foreach ( $styles as $target => $values ) {
			if ( ! preg_match( '/^(global|title|step|card|message|total|button)(:[a-zA-Z0-9_-]+)?$/D', (string) $target ) || ! is_array( $values ) ) {
				Support::fail( __( 'جزء ظاهری معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$result[ $target ] = array();
			foreach ( $values as $key => $value ) {
				if ( in_array( $key, array( 'background', 'color', 'accent', 'borderColor' ), true ) && is_string( $value ) && preg_match( '/^#[a-f0-9]{6}$/iD', $value ) ) {
					$result[ $target ][ $key ] = $value;
				} elseif ( in_array( $key, array( 'fontSize', 'padding', 'margin', 'radius', 'borderWidth' ), true ) && is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 150 ) {
					$result[ $target ][ $key ] = (float) $value;
				} elseif ( 'fontWeight' === $key && in_array( (string) $value, array( '400', '500', '700' ), true ) ) {
					$result[ $target ][ $key ] = (string) $value;
				} elseif ( 'shadow' === $key && in_array( $value, array( 'none', 'soft' ), true ) ) {
					$result[ $target ][ $key ] = $value;
				} else {
					Support::fail( __( 'یک مقدار ظاهری نامعتبر است؛ فقط تنظیمات تعریف‌شده قابل ذخیره هستند.', 'packchin-kits-for-woocommerce' ) );
				}
			}
		}
		return $result;
	}

	public static function validate( array $d ): array {
		$errors = array();
		$ids    = array_column( $d['steps'], 'id' );
		if ( ! trim( $d['name'] ) || ! $ids ) {
			$errors[] = __( 'نام پیکربندی و حداقل یک مرحله لازم است.', 'packchin-kits-for-woocommerce' );
		}
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			$errors[] = __( 'شناسه مرحله تکراری است.', 'packchin-kits-for-woocommerce' );
		}
		$catalog = new Catalog();
		foreach ( $d['steps'] as $s ) {
			if ( ! trim( $s['name'] ) || $s['max'] < max( 1, $s['min'] ) || ( ! $s['multiple'] && 1 !== $s['max'] ) ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'نام یا محدوده تعداد «%s» معتبر نیست.', 'packchin-kits-for-woocommerce' ), $s['name'] );
			}
			foreach ( RuleEngine::dependencies( $d, $s ) as $dependency ) {
				if ( ! in_array( $dependency, $ids, true ) ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'پیش‌نیاز حذف‌شده در «%s».', 'packchin-kits-for-woocommerce' ), $s['name'] );
				}
			}
			if ( ! $catalog->source_ids( $s, 1 ) ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'محصول واقعی برای مرحله «%s» متصل نشده است.', 'packchin-kits-for-woocommerce' ), $s['name'] );
			}
			if ( 'direct' === $s['source']['type'] ) {
				foreach ( $s['source']['ids'] as $id ) {
					if ( ! ctype_digit( (string) $id ) || ! wc_get_product( (int) $id ) ) {
						/* translators: %s: Merchant-defined step, rule, or service name. */
						$errors[] = sprintf( __( 'شناسه نمونه یا محصول حذف‌شده در «%s» باید به محصول واقعی متصل شود.', 'packchin-kits-for-woocommerce' ), $s['name'] );
					}
				}
			}
		}
		$visiting = array();
		$visited  = array();
		$visit    = static function ( string $id ) use ( &$visit, &$visiting, &$visited, &$errors, $d ): void {
			if ( isset( $visiting[ $id ] ) ) {
				$errors[] = __( 'وابستگی دوری بین مراحل وجود دارد.', 'packchin-kits-for-woocommerce' );
				return;
			}
			if ( isset( $visited[ $id ] ) ) {
				return;
			}
			$visiting[ $id ] = true;
			foreach ( $d['steps'] as $s ) {
				if ( $s['id'] === $id ) {
					foreach ( RuleEngine::dependencies( $d, $s ) as $dep ) {
						$visit( $dep );
					}
				}
			}
			unset( $visiting[ $id ] );
			$visited[ $id ] = true;
		};
		foreach ( $ids as $id ) {
			$visit( $id );
		}
		$rule_ids = array();
		foreach ( $d['rules'] as $r ) {
			if ( isset( $rule_ids[ $r['id'] ] ) ) {
				$errors[] = __( 'شناسه قانون تکراری است.', 'packchin-kits-for-woocommerce' );
			}
			$rule_ids[ $r['id'] ] = true;
			if ( ! $r['enabled'] ) {
				continue;
			}
			if ( ! in_array( $r['target'], $ids, true ) || ( $r['source'] && ( ! in_array( $r['source'], $ids, true ) || $r['source'] === $r['target'] ) ) ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'ارجاع مرحله در قانون «%s» معتبر نیست.', 'packchin-kits-for-woocommerce' ), $r['name'] );
			}
			if ( in_array( $r['type'], array( 'match', 'dependency' ), true ) && ! $r['source'] ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'مرحله مرجع قانون «%s» مشخص نشده است.', 'packchin-kits-for-woocommerce' ), $r['name'] );
			}
			if ( in_array( $r['type'], array( 'match', 'numeric', 'capacity' ), true ) && ! $r['targetAttr'] ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'ویژگی قانون «%s» مشخص نشده است.', 'packchin-kits-for-woocommerce' ), $r['name'] );
			}
			foreach ( $r['products'] as $product_id ) {
				if ( ! ctype_digit( (string) $product_id ) || ! wc_get_product( (int) $product_id ) ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'محصول حذف‌شده یا تطبیق‌نیافته در قانون «%s» وجود دارد.', 'packchin-kits-for-woocommerce' ), $r['name'] );
				}
			}
			foreach ( $r['conditions'] as $condition ) {
				if ( 'candidate' !== $condition['step'] && ! in_array( $condition['step'], $ids, true ) ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'شرط قانون «%s» به مرحله حذف‌شده اشاره می‌کند.', 'packchin-kits-for-woocommerce' ), $r['name'] );
				}
			}
		}
		foreach ( $d['steps'] as $s ) {
			$rules  = array_values( array_filter( $d['rules'], static fn( array $r ): bool => $r['enabled'] && $r['target'] === $s['id'] && ! $r['conditions'] ) );
			$allows = array_filter( $rules, static fn( array $r ): bool => 'allow' === $r['type'] );
			$denied = array_merge( array(), ...array_column( array_filter( $rules, static fn( array $r ): bool => 'deny' === $r['type'] ), 'products' ) );
			if ( $allows ) {
				$possible = null;
				foreach ( $allows as $r ) {
					$possible = null === $possible ? $r['products'] : array_intersect( $possible, $r['products'] );
				}
				if ( ! array_diff( $possible ?? array(), $denied ) ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'قانون‌های مجاز و ممنوع «%s» متناقض هستند و هیچ محصولی باقی نمی‌گذارند.', 'packchin-kits-for-woocommerce' ), $s['name'] );
				}
			}
		}
		foreach ( $d['services'] as $service ) {
			if ( ! $service['enabled'] ) {
				continue;
			}
			$p = wc_get_product( (int) $service['productId'] );
			if ( ! $p || ! $p->is_virtual() || ! $p->is_type( 'simple' ) ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'خدمت «%s» باید به محصول مجازی ساده ووکامرس متصل شود.', 'packchin-kits-for-woocommerce' ), $service['name'] );
			}
			if ( 'products' === $service['scope'] && ! $service['products'] ) {
				/* translators: %s: Merchant-defined step, rule, or service name. */
				$errors[] = sprintf( __( 'محصولات مرتبط با خدمت «%s» مشخص نشده‌اند.', 'packchin-kits-for-woocommerce' ), $service['name'] );
			}
			foreach ( $service['products'] as $product_id ) {
				if ( ! ctype_digit( (string) $product_id ) || ! wc_get_product( (int) $product_id ) ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'محصول مرتبط با خدمت «%s» حذف شده یا تطبیق داده نشده است.', 'packchin-kits-for-woocommerce' ), $service['name'] );
				}
			}
		}
		$errors = array_merge( $errors, ( new RuleEngine() )->static_errors( $d ) );
		return array_values( array_unique( $errors ) );
	}
}
