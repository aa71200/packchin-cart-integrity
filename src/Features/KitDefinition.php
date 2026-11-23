<?php
declare(strict_types=1);

namespace Packchin\Kits;

defined( 'ABSPATH' ) || exit;

/** Map the limited editor contract to the shared evaluator's definition. */
final class KitDefinition {
	public static function compile( array $input, bool $publishing ): array {
		$steps = $input['steps'] ?? array();
		$rules = $input['rules'] ?? array();
		if ( ! is_array( $steps ) || ! array_is_list( $steps ) || count( $steps ) < 2 || count( $steps ) > 4 ||
			! is_array( $rules ) || ! array_is_list( $rules ) || count( $rules ) > 6 ) {
			Support::fail( __( 'بین دو تا چهار مرحله و حداکثر شش قانون تعریف کنید.', 'packchin-kits-for-woocommerce' ) );
		}
		$name = is_string( $input['name'] ?? null ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			Support::fail( __( 'نام پک را وارد کنید.', 'packchin-kits-for-woocommerce' ) );
		}
		$catalog = new Catalog();
		$result = array( 'name' => $name, 'steps' => array(), 'rules' => array(), 'services' => array(), 'appearance' => array() );
		foreach ( $steps as $index => $step ) {
			if ( ! is_array( $step ) || ! is_string( $step['name'] ?? null ) || ! is_array( $step['products'] ?? null ) || count( $step['products'] ) > 60 ) {
				Support::fail( __( 'فهرست کالاهای مرحله معتبر نیست. حداکثر ۶۰ کالا در هر مرحله انتخاب کنید.', 'packchin-kits-for-woocommerce' ) );
			}
			$ids = array_values( array_unique( array_map( static function ( mixed $id ): string {
				if ( ! is_int( $id ) || $id < 1 ) {
					Support::fail( __( 'شناسهٔ کالا معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
				}
				return (string) $id;
			}, $step['products'] ) ) );
			$result['steps'][] = array(
				'id' => 'step-' . ( $index + 1 ),
				'name' => $step['name'] ?? '',
				'required' => true,
				'min' => 1,
				'max' => 1,
				'deps' => $index ? array( 'step-' . $index ) : array(),
				'source' => array( 'type' => 'direct', 'ids' => $ids ),
			);
			if ( $publishing ) {
				foreach ( $ids as $id ) {
					if ( ! $catalog->row( (int) $id ) ) {
						Support::fail( __( 'فقط کالای منتشرشده یا تنوع مشخص و فعال قابل انتشار است.', 'packchin-kits-for-woocommerce' ), 422 );
					}
				}
			}
		}
		$attributes = $catalog->attributes();
		foreach ( $rules as $index => $rule ) {
			$source = $rule['source'] ?? null;
			$target = $rule['target'] ?? null;
			$attribute = $rule['attribute'] ?? '';
			if ( ! is_int( $source ) || ! is_int( $target ) || $source < 0 || $target <= $source || ! isset( $steps[ $target ] ) ||
				! is_string( $attribute ) || ! isset( $attributes[ $attribute ] ) ) {
				Support::fail( __( 'قانون باید یک ویژگی سراسری و دو مرحله به ترتیب انتخاب داشته باشد.', 'packchin-kits-for-woocommerce' ), 422 );
			}
			if ( $publishing ) {
				$values = array();
				foreach ( array( $source, $target ) as $side ) {
					$values[ $side ] = array();
					foreach ( $result['steps'][ $side ]['source']['ids'] as $product_id ) {
						$row = $catalog->row( (int) $product_id );
						$value = $row['attrs'][ $attribute ] ?? null;
						if ( null === $value ) {
							/* translators: 1: product name, 2: attribute name. */
							Support::fail( sprintf( __( 'ویژگی «%2$s» برای «%1$s» باید دقیقاً یک مقدار داشته باشد.', 'packchin-kits-for-woocommerce' ), $row['name'], $attributes[ $attribute ]['label'] ), 422 );
						}
						$values[ $side ][] = $value;
					}
				}
				if ( ! array_intersect( $values[ $source ], $values[ $target ] ) ) {
					Support::fail( __( 'این قانون هیچ جفت سازگاری بین دو مرحله باقی نمی‌گذارد.', 'packchin-kits-for-woocommerce' ), 422 );
				}
			}
			$result['rules'][] = array(
				'id' => 'match-' . ( $index + 1 ),
				'name' => $attributes[ $attribute ]['label'],
				'type' => 'match',
				'operator' => 'eq',
				'conditions' => array(),
				'enabled' => true,
				'source' => 'step-' . ( $source + 1 ),
				'target' => 'step-' . ( $target + 1 ),
				'sourceAttr' => $attribute,
				'targetAttr' => $attribute,
			);
		}
		$service = $input['service'] ?? 0;
		if ( ! is_int( $service ) || $service < 0 ) {
			Support::fail( __( 'شناسهٔ خدمت معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		if ( $service ) {
			$row = $catalog->row( $service );
			if ( ! $row || ! $row['virtual'] || 'simple' !== $row['kind'] ) {
				Support::fail( __( 'خدمت باید یک محصول مجازی ساده و منتشرشده باشد.', 'packchin-kits-for-woocommerce' ), 422 );
			}
			$result['services'][] = array( 'id' => 'assembly', 'name' => $row['name'], 'productId' => $service, 'scope' => 'bundle', 'enabled' => true );
		}
		$definition = Definition::clean( $result );
		if ( $publishing && ( $errors = Definition::validate( $definition ) ) ) {
			Support::fail( implode( "\n", $errors ), 422 );
		}
		return $definition;
	}

	public static function editor( array $definition ): array {
		return array(
			'name' => $definition['name'],
			'steps' => array_map( static fn( array $step ): array => array( 'name' => $step['name'], 'products' => array_map( 'intval', $step['source']['ids'] ) ), $definition['steps'] ),
			'rules' => array_map( static fn( array $rule ): array => array(
				'source' => (int) substr( $rule['source'], 5 ) - 1,
				'target' => (int) substr( $rule['target'], 5 ) - 1,
				'attribute' => $rule['sourceAttr'],
			), $definition['rules'] ),
			'service' => (int) ( $definition['services'][0]['productId'] ?? 0 ),
		);
	}
}
