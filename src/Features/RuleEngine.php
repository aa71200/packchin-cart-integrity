<?php
declare(strict_types=1);

namespace Packchin\Kits;

use Packchin\Kits\Core\Evaluator;

/** Compiles editor rules to the existing Hamsaz AST; PHP is authoritative. */
final class RuleEngine {
	private Catalog $catalog;
	private array $overrides;
	private ?string $exclude_group;
	private int $bundle_quantity;

	public function __construct( array $overrides = array(), ?string $exclude_group = null, int $bundle_quantity = 1 ) {
		$this->catalog       = new Catalog();
		$this->overrides     = $overrides;
		$this->exclude_group = $exclude_group;
		if ( $bundle_quantity < 1 || $bundle_quantity > 1000 ) {
			Support::fail( __( 'تعداد مجموعه معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		$this->bundle_quantity = $bundle_quantity;
	}

	public static function dependencies( array $d, array $s ): array {
		$deps = $s['deps'];
		foreach ( $d['rules'] as $r ) {
			if ( $r['enabled'] && 'dependency' === $r['type'] && $r['target'] === $s['id'] && $r['source'] ) {
				$deps[] = $r['source'];
			}
		}
		return array_values( array_unique( $deps ) );
	}

	public function selections( array $d, mixed $input ): array {
		if ( ! is_array( $input ) || count( $input ) > 100 ) {
			Support::fail( __( 'انتخاب‌ها معتبر نیستند.', 'packchin-kits-for-woocommerce' ) );
		}
		$ids    = array_column( $d['steps'], 'id' );
		$result = array();
		foreach ( $input as $step => $entries ) {
			if ( ! in_array( (string) $step, $ids, true ) || ! is_array( $entries ) || ! array_is_list( $entries ) || count( $entries ) > 200 ) {
				Support::fail( __( 'یک مرحله یا فهرست انتخاب معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$result[ $step ] = array();
			$seen            = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || array_diff( array_keys( $entry ), array( 'id', 'qty' ) ) || ! ctype_digit( (string) ( $entry['id'] ?? '' ) ) || ! is_int( $entry['qty'] ?? null ) || $entry['qty'] < 1 || $entry['qty'] > 10000 || isset( $seen[ (string) $entry['id'] ] ) ) {
					Support::fail( __( 'شناسه یا تعداد محصول انتخابی معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
				}
				$seen[ (string) $entry['id'] ] = true;
				$result[ $step ][]             = array(
					'id'  => (string) $entry['id'],
					'qty' => $entry['qty'],
				);
			}
		}
		return $result;
	}

	public function row( string|int $id ): ?array {
		$row = $this->catalog->row( (int) $id );
		if ( $row && isset( $this->overrides[ $id ] ) ) {
			$override = $this->overrides[ $id ];
			if ( isset( $override['stock'] ) ) {
				$row['stock']   = max( 0, min( 9999, (int) $override['stock'] ) );
				$row['inStock'] = $row['stock'] > 0;
			}
			if ( isset( $override['price'] ) ) {
				$row['priceMinor'] = Support::minor( $override['price'] );
				$row['price']      = Support::major( $row['priceMinor'] );
			}
		}
		return $row;
	}

	private function selected( array $selection, string $step ): array {
		$result = array();
		foreach ( $selection[ $step ] ?? array() as $entry ) {
			$product = $this->row( $entry['id'] );
			if ( $product ) {
				$result[] = $entry + array( 'product' => $product ); }
		}
		return $result;
	}

	public function locked( array $d, array $s, array $selection ): array {
		return array_values( array_filter( self::dependencies( $d, $s ), fn( string $id ): bool => ! $this->selected( $selection, $id ) ) );
	}

	private function literal( mixed $value ): array {
		return null === $value ? array(
			'type'      => 'attribute',
			'component' => 'probe',
			'attribute' => 'missing',
		) : array(
			'type'  => 'literal',
			'value' => $value,
		);
	}

	private function compare( mixed $left, string $operator, mixed $right ): array {
		if ( 'contains' === $operator ) {
			// String containment is compiled to a boolean literal; it is not executable input.
			return $this->literal( null !== $left && null !== $right ? str_contains( (string) $left, (string) $right ) : null );
		}
		return array(
			'type'  => 'compare',
			'op'    => 'ne' === $operator ? 'neq' : $operator,
			'left'  => $this->literal( $left ),
			'right' => $this->literal( $right ),
		);
	}

	private function run( array $assert, ?array $when = null ): bool {
		$r = array(
			'id'       => 'probe',
			'severity' => 'hard',
			'assert'   => $assert,
		);
		if ( $when ) {
			$r['when'] = $when;
		}
		$result = ( new Evaluator() )->evaluate(
			array(
				'components' => array(),
				'rules'      => array( $r ),
			),
			array(
				'selections'     => array(),
				'bundleQuantity' => 1,
			),
			array()
		);
		return $result['canPurchase'];
	}

	private function conjunction( array $nodes, bool $any = false ): array {
		return $nodes ? array(
			'type'  => $any ? 'any' : 'all',
			'nodes' => $nodes,
		) : $this->literal( ! $any );
	}

	private function conditions( array $r, array $product, array $selection ): ?array {
		if ( ! $r['conditions'] ) {
			return null;
		}
		$nodes = array();
		foreach ( $r['conditions'] as $c ) {
			$products = 'candidate' === $c['step'] ? array( $product ) : array_column( $this->selected( $selection, $c['step'] ), 'product' );
			if ( 'selected' === $c['attribute'] ) {
				$nodes[] = $this->literal( 'ne' === $c['operator'] ? ! $products : (bool) $products );
			} else {
				$expected = $c['value'];
				$sub      = array();
				foreach ( $products as $p ) {
					$value = $p['attrs'][ $c['attribute'] ] ?? null;
					$right = is_int( $value ) || is_float( $value ) ? ( is_numeric( $expected ) ? 0 + $expected : null ) : mb_strtolower( trim( $expected ), 'UTF-8' );
					$sub[] = $this->compare( $value, $c['operator'], $right );
				}
				$nodes[] = $this->conjunction( $sub, true );
			}
		}
		return $this->conjunction( $nodes, 'any' === $r['logic'] );
	}

	private function rule_passes( array $d, array $r, array $p, array $selection, bool $partial ): bool {
		$targets    = $this->selected( $selection, $r['target'] );
		$references = $this->selected( $selection, $r['source'] );
		$count      = array_sum( array_column( $selection[ $r['target'] ] ?? array(), 'qty' ) );
		$assert     = $this->literal( true );
		switch ( $r['type'] ) {
			case 'dependency':
				$assert = $this->literal( (bool) $references );
				break;
			case 'match':
				$nodes = array();
				foreach ( $references as $reference ) {
					$nodes[] = $this->compare( $p['attrs'][ $r['targetAttr'] ] ?? null, $r['operator'], $reference['product']['attrs'][ $r['sourceAttr'] ] ?? null );
				}
				$assert = $nodes ? $this->conjunction( $nodes ) : $this->literal( null );
				break;
			case 'allow':
				$assert = $this->literal( in_array( $p['id'], $r['products'], true ) );
				break;
			case 'deny':
				$assert = $this->literal( ! in_array( $p['id'], $r['products'], true ) );
				break;
			case 'numeric':
				$value  = $p['attrs'][ $r['targetAttr'] ] ?? null;
				$assert = $this->compare( is_numeric( $value ) ? 0 + $value : null, $r['operator'], $r['value'] );
				break;
			case 'count':
				if ( $partial && in_array( $r['operator'], array( 'gte', 'gt', 'ne' ), true ) ) {
					return true;
				}
				$assert = $this->compare( $count, $partial && 'eq' === $r['operator'] ? 'lte' : $r['operator'], $r['value'] );
				break;
			case 'capacity':
				if ( 'supply' === $r['capacityMode'] ) {
					$sum   = 0;
					$found = false;
					$valid = true;
					foreach ( $selection as $step_id => $entries ) {
						if ( $step_id === $r['target'] ) {
							continue;
						}
						foreach ( $entries as $entry ) {
							$value = $this->row( $entry['id'] )['attrs'][ $r['sourceAttr'] ] ?? null;
							$valid = $valid && is_numeric( $value );
							if ( is_numeric( $value ) ) {
								$found = true;
								$sum  += $value * $entry['qty'];
							}
						}
					}
					$capacity = $p['attrs'][ $r['targetAttr'] ] ?? null;
					$assert   = $this->compare( $valid && $found && is_numeric( $capacity ) ? 0 + $capacity : null, 'gte', $sum * $r['factor'] );
				} else {
					$capacity = 0;
					$used     = 0;
					$valid    = (bool) $references;
					foreach ( $references as $reference ) {
						$value     = $reference['product']['attrs'][ $r['sourceAttr'] ] ?? null;
						$valid     = $valid && is_numeric( $value );
						$capacity += is_numeric( $value ) ? $value * $reference['qty'] : 0;
					}
					foreach ( $targets as $target ) {
						$value = $target['product']['attrs'][ $r['targetAttr'] ] ?? null;
						$valid = $valid && is_numeric( $value );
						$used += is_numeric( $value ) ? $value * $target['qty'] : 0;
					}
					$assert = $this->compare( $valid ? $used : null, 'lte', $capacity );
				}
				break;
		}
		return $this->run( $assert, $this->conditions( $r, $p, $selection ) );
	}

	/** Aggregate by WooCommerce stock owner, including ordinary cart products. */
	private function inventory( array $selection, ?array $services = null ): array {
		$requested = array();
		foreach ( $selection as $entries ) {
			foreach ( $entries as $entry ) {
				$id               = (int) $entry['id'];
				$requested[ $id ] = ( $requested[ $id ] ?? 0 ) + $entry['qty'] * $this->bundle_quantity;
			}
		}
		foreach ( $services ?? array() as $service ) {
			$id               = (int) $service['productId'];
			$requested[ $id ] = ( $requested[ $id ] ?? 0 ) + $service['qty'];
		}
		$stock        = array();
		$existing_ids = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( $this->exclude_group && ( $item['packchin_group'] ?? '' ) === $this->exclude_group ) {
					continue;
				}
				$p                            = $item['data'];
				$owner                        = $p->get_stock_managed_by_id();
				$stock[ $owner ]              = ( $stock[ $owner ] ?? 0 ) + (int) $item['quantity'];
				$existing_ids[ $p->get_id() ] = ( $existing_ids[ $p->get_id() ] ?? 0 ) + (int) $item['quantity'];
			}
		}
		foreach ( $requested as $id => $qty ) {
			$row = $this->row( $id );
			if ( $row ) {
				$stock[ $row['stockKey'] ] = ( $stock[ $row['stockKey'] ] ?? 0 ) + $qty;
			}
		}
		$errors = array();
		foreach ( $requested as $id => $qty ) {
			$row = $this->row( $id );
			if ( ! $row || ! $row['inStock'] || ! $row['purchasable'] || null === $row['priceMinor'] || ( null !== $row['stock'] && $stock[ $row['stockKey'] ] > $row['stock'] ) || ( $row['soldIndividually'] && $qty + ( $existing_ids[ $id ] ?? 0 ) > 1 ) ) {
				$errors[ (string) $id ] = __( 'محصول موجود یا قابل خرید نیست، یا موجودی کافی ندارد.', 'packchin-kits-for-woocommerce' );
			}
		}
		return $errors;
	}

	public function candidate( array $d, array $s, string $id, array $selection, int $qty = 1 ): array {
		$p       = $this->row( $id );
		$reasons = array();
		if ( ! $p || ! $p['visible'] || ! in_array( (int) $id, $this->catalog->source_ids( $s ), true ) ) {
			return array(
				'ok'      => false,
				'reasons' => array( __( 'محصول در منبع این مرحله نیست یا قابل نمایش نیست.', 'packchin-kits-for-woocommerce' ) ),
			);
		}
		if ( $this->locked( $d, $s, $selection ) ) {
			$reasons[] = __( 'ابتدا پیش‌نیاز این مرحله را انتخاب کنید.', 'packchin-kits-for-woocommerce' );
		}
		$next             = $selection;
		$others           = array_values( array_filter( $next[ $s['id'] ] ?? array(), static fn( array $entry ): bool => $entry['id'] !== $id ) );
		$next[ $s['id'] ] = $s['multiple'] ? array_merge(
			$others,
			array(
				array(
					'id'  => $id,
					'qty' => $qty,
				),
			)
		) : array(
			array(
				'id'  => $id,
				'qty' => $qty,
			),
		);
		$count            = array_sum( array_column( $next[ $s['id'] ], 'qty' ) );
		if ( $qty < 1 || $qty > 10000 || $count > $s['max'] ) {
			$reasons[] = __( 'تعداد از محدوده این مرحله بیشتر است.', 'packchin-kits-for-woocommerce' );
		}
		$inventory = $this->inventory( $next );
		if ( isset( $inventory[ $id ] ) ) {
			$reasons[] = $inventory[ $id ];
		}
		foreach ( $d['rules'] as $r ) {
			if ( $r['enabled'] && $r['target'] === $s['id'] && ! $this->rule_passes( $d, $r, $p, $next, true ) ) {
				$reasons[] = $r['name'] . '؛ ' . __( 'شرایط برقرار نیست یا مشخصات لازم ناقص است.', 'packchin-kits-for-woocommerce' );
			}
		}
		return array(
			'ok'      => ! $reasons,
			'reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	public function sanitize( array $d, array $selection ): array {
		$removed = array();
		for ( $pass = 0; $pass <= count( $d['steps'] ); ++$pass ) {
			$changed = false;
			foreach ( $d['steps'] as $s ) {
				foreach ( $selection[ $s['id'] ] ?? array() as $index => $entry ) {
					if ( ! $this->candidate( $d, $s, $entry['id'], $selection, $entry['qty'] )['ok'] ) {
						unset( $selection[ $s['id'] ][ $index ] );
						$removed[] = $this->row( $entry['id'] )['name'] ?? $entry['id'];
						$changed   = true;
					}
				}
				$selection[ $s['id'] ] = array_values( $selection[ $s['id'] ] ?? array() );
			}
			if ( ! $changed ) {
				break;
			}
		}
		return array(
			'selections' => $selection,
			'removed'    => array_values( array_unique( $removed ) ),
		);
	}

	public function service_lines( array $d, array $selection, array $selected_services ): array {
		if ( count( $selected_services ) > 100 || ! array_is_list( $selected_services ) ) {
			Support::fail( __( 'انتخاب خدمات معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		foreach ( $selected_services as $service_id ) {
			if ( ! is_string( $service_id ) ) {
				Support::fail( __( 'شناسه خدمت معتبر نیست.', 'packchin-kits-for-woocommerce' ) ); }
		}
		$lines       = array();
		$definitions = array_column( $d['services'], null, 'id' );
		foreach ( array_unique( $selected_services ) as $id ) {
			$s = $definitions[ $id ] ?? null;
			if ( ! $s || ! $s['enabled'] ) {
				Support::fail( __( 'خدمت انتخاب‌شده معتبر یا فعال نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$qty     = 'bundle' === $s['scope'] ? 1 : 0;
			$targets = array();
			if ( 'products' === $s['scope'] ) {
				foreach ( $selection as $entries ) {
					foreach ( $entries as $entry ) {
						$p = $this->row( $entry['id'] );
						if ( in_array( $entry['id'], $s['products'], true ) || ( $p && $p['parent'] && in_array( $p['parent'], $s['products'], true ) ) ) {
							$qty      += $entry['qty'];
							$targets[] = $entry['id'];
						}
					}
				}
			}
			$p = $this->row( $s['productId'] );
			if ( $qty < 1 ) {
				continue;
			}
			if ( ! $p || ! $p['virtual'] || 'simple' !== $p['kind'] ) {
				Support::fail( __( 'محصول خدماتی دیگر معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
			}
			$lines[] = array(
				'id'         => $s['id'],
				'productId'  => $s['productId'],
				'name'       => $s['name'],
				'qty'        => $qty * $this->bundle_quantity,
				'targets'    => $targets,
				'price'      => $p['price'],
				'priceMinor' => $p['priceMinor'],
			);
		}
		return $lines;
	}

	public function assess( array $d, array $selection, array $selected_services = array() ): array {
		$errors = array();
		foreach ( $d['steps'] as $s ) {
			$entries = $selection[ $s['id'] ] ?? array();
			$count   = array_sum( array_column( $entries, 'qty' ) );
			if ( $this->locked( $d, $s, $selection ) ) {
				if ( $entries ) {
					$errors[] = $s['name'] . ': ' . __( 'پیش‌نیاز انتخاب نشده است.', 'packchin-kits-for-woocommerce' );
				}
				continue;
			}
			$minimum = $s['required'] ? max( 1, $s['min'] ) : ( $count ? $s['min'] : 0 );
			if ( $count < $minimum || $count > $s['max'] || ( ! $s['multiple'] && count( $entries ) > 1 ) ) {
				/* translators: 1: Step name, 2: Minimum quantity, 3: Maximum quantity. */
				$errors[] = sprintf( __( 'تعداد انتخاب‌های «%1$s» باید بین %2$d و %3$d باشد.', 'packchin-kits-for-woocommerce' ), $s['name'], $minimum, $s['max'] );
			}
			foreach ( $entries as $entry ) {
				$result = $this->candidate( $d, $s, $entry['id'], $selection, $entry['qty'] );
				$errors = array_merge( $errors, $result['reasons'] );
				foreach ( $d['rules'] as $r ) {
					if ( $r['enabled'] && 'count' === $r['type'] && $r['target'] === $s['id'] && ! $this->rule_passes( $d, $r, $this->row( $entry['id'] ) ?? array(), $selection, false ) ) {
						$errors[] = $r['name'];
					}
				}
			}
		}
		$services    = $this->service_lines( $d, $selection, $selected_services );
		$errors      = array_merge( $errors, array_values( $this->inventory( $selection, $services ) ) );
		$total       = 0;
		$price_items = array();
		foreach ( $selection as $step => $entries ) {
			foreach ( $entries as $entry ) {
				$p = $this->row( $entry['id'] );
				if ( $p && null !== $p['priceMinor'] ) {
					$total        += $p['priceMinor'] * $entry['qty'] * $this->bundle_quantity;
					$price_items[] = array( $step, $entry['id'], $entry['qty'], $p['priceMinor'] );
				}
			}
		}
		foreach ( $services as $service ) {
			$total        += $service['priceMinor'] * $service['qty'];
			$price_items[] = array( 'service', $service['id'], $service['qty'], $service['priceMinor'] );
		}
		if ( ! is_int( $total ) || $total > 900719925474099 ) {
			$errors[] = __( 'جمع مبلغ از محدوده قابل پردازش بیشتر است.', 'packchin-kits-for-woocommerce' );
		}
		return array(
			'ok'         => ! $errors,
			'errors'     => array_values( array_unique( $errors ) ),
			'total'      => Support::major( (int) $total ),
			'totalMinor' => (string) $total,
			'services'   => $services,
			'priceToken' => hash( 'sha256', Support::json( array( get_woocommerce_currency(), $this->bundle_quantity, $price_items ) ) ),
		);
	}

	/** Static contradictions must not be confused with temporary stock depletion. */
	public function static_errors( array $d ): array {
		$errors = array();
		foreach ( $d['steps'] as $step ) {
			$rules   = array_values( array_filter( $d['rules'], static fn( array $r ): bool => $r['enabled'] && $r['target'] === $step['id'] && ! $r['conditions'] ) );
			$numeric = array_filter( $rules, static fn( array $r ): bool => 'numeric' === $r['type'] );
			if ( $numeric ) {
				$possible = false;
				foreach ( $this->catalog->source_ids( $step ) as $id ) {
					$product = $this->row( $id );
					if ( ! $product ) {
						continue; }
					$passes = true;
					foreach ( $numeric as $rule ) {
						$passes = $passes && $this->rule_passes( $d, $rule, $product, array(), false );
					}
					if ( $passes ) {
						$possible = true;
						break; }
				}
				if ( ! $possible ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'قانون‌های عددی «%s» هیچ محصولی با مشخصات معتبر باقی نمی‌گذارند.', 'packchin-kits-for-woocommerce' ), $step['name'] );
				}
			}
			$counts = array_filter( $rules, static fn( array $r ): bool => 'count' === $r['type'] );
			if ( $counts ) {
				$possible = false;
				for ( $n = $step['min']; $n <= $step['max']; ++$n ) {
					$passes = true;
					foreach ( $counts as $r ) {
						$passes = $passes && $this->run( $this->compare( $n, $r['operator'], $r['value'] ) );
					}
					if ( $passes ) {
						$possible = true;
						break; }
				}
				if ( ! $possible ) {
					/* translators: %s: Merchant-defined step, rule, or service name. */
					$errors[] = sprintf( __( 'قانون‌های تعداد «%s» متناقض هستند.', 'packchin-kits-for-woocommerce' ), $step['name'] );
				}
			}
			foreach ( $rules as $a ) {
				foreach ( $rules as $b ) {
					if ( 'match' === $a['type'] && 'match' === $b['type'] && $a['source'] === $b['source'] && $a['sourceAttr'] === $b['sourceAttr'] && $a['targetAttr'] === $b['targetAttr'] && $a['operator'] !== $b['operator'] && in_array( $a['operator'], array( 'eq', 'ne' ), true ) && in_array( $b['operator'], array( 'eq', 'ne' ), true ) ) {
						/* translators: %s: Merchant-defined step, rule, or service name. */
						$errors[] = sprintf( __( 'تطبیق ویژگی در «%s» هم‌زمان برابر و نابرابر تعریف شده است.', 'packchin-kits-for-woocommerce' ), $step['name'] );
					}
				}
			}
		}
		return array_values( array_unique( $errors ) );
	}
}
