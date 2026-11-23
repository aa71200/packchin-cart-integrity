<?php
declare(strict_types=1);

namespace Packchin\Kits;

/** WooCommerce is the only product, price, tax and inventory source. */
final class Catalog {
	private static array $rows    = array();

	public static function reset(): void {
		self::$rows    = array();
	}

	public function attributes(): array {
		$attributes = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$attributes[ wc_attribute_taxonomy_name( $attribute->attribute_name ) ] = array( 'label' => $attribute->attribute_label );
		}
		return $attributes;
	}

	public function product_attributes( \WC_Product $product ): array {
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		$result = array();
		foreach ( $this->attributes() as $key => $attribute ) {
			$value = $product->get_attributes()[ $key ] ?? null;
			if ( is_string( $value ) && '' !== $value && taxonomy_exists( $key ) && get_term_by( 'slug', $value, $key ) ) {
				$result[ $key ] = $value;
				continue;
			}
			$definition = ( $parent ?: $product )->get_attributes()[ $key ] ?? null;
			if ( $definition instanceof \WC_Product_Attribute && $definition->is_taxonomy() && 1 === count( $definition->get_options() ) ) {
				$term = get_term( (int) $definition->get_options()[0], $key );
				if ( $term instanceof \WP_Term ) {
					$result[ $key ] = $term->slug;
				}
			}
		}
		return $result;
	}

	public function row( int $id ): ?array {
		if ( array_key_exists( $id, self::$rows ) ) {
			return self::$rows[ $id ];
		}
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $p->get_status() ) {
			self::$rows[ $id ] = null;
			return null;
		}
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : null;
		if ( ( $p->is_type( 'variation' ) && ( ! $parent || 'publish' !== $parent->get_status() || in_array( '', $p->get_variation_attributes(), true ) ) ) || '' !== get_post_field( 'post_password', ( $parent ?: $p )->get_id() ) ) {
			self::$rows[ $id ] = null;
			return null;
		}
		$owner      = wc_get_product( $p->get_stock_managed_by_id() );
		$categories = wc_get_product_terms( $parent ? $parent->get_id() : $id, 'product_cat', array( 'fields' => 'slugs' ) );
		$price      = '' !== $p->get_price() ? Support::minor( wc_get_price_to_display( $p ) ) : null;
		$image_id   = $p->get_image_id();
		if ( ! $image_id && $parent ) {
			$image_id = $parent->get_image_id(); }
		$image_url         = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
		self::$rows[ $id ] = array(
			'id'               => (string) $id,
			'name'             => $p->get_name(),
			'sku'              => $p->get_sku(),
			'category'         => $categories[0] ?? '',
			'categories'       => $categories,
			'price'            => null === $price ? null : Support::major( $price ),
			'priceMinor'       => $price,
			'priceHtml'        => $p->get_price_html(),
			'stock'            => $owner && $owner->managing_stock() ? max( 0, (int) $owner->get_stock_quantity() ) : null,
			'inStock'          => $p->is_in_stock(),
			'purchasable'      => $p->is_purchasable(),
			'visible'          => true,
			'stockKey'         => (string) $p->get_stock_managed_by_id(),
			'soldIndividually' => $p->is_sold_individually(),
			'attrs'            => $this->product_attributes( $p ),
			'kind'             => $p->is_type( 'variation' ) ? 'variation' : 'simple',
			'parent'           => $parent ? (string) $parent->get_id() : null,
			'virtual'          => $p->is_virtual(),
			'icon'             => 'box',
			'image'            => $image_url ? $image_url : '',
			'url'              => $p->get_permalink(),
			'editUrl'          => get_edit_post_link( $parent ? $parent->get_id() : $id, 'raw' ),
		);
		return self::$rows[ $id ];
	}

	public function source_ids( array $step, int $limit = 0 ): array {
		$ids = array_map( 'intval', $step['source']['ids'] );
		return $limit ? array_slice( $ids, 0, $limit ) : $ids;
	}

}
