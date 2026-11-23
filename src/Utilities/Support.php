<?php
declare(strict_types=1);

namespace Packchin\Kits;

final class Support {
	public static function fail( string $message, int $status = 400 ): never {
		throw new \RuntimeException( $message, $status );
	}

	public static function id( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/D', $value ) || in_array( $value, array( 'constructor', 'prototype', '__proto__' ), true ) ) {
			self::fail( __( 'شناسه معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		return $value;
	}

	/** Convert a native decimal price to integer minor units without binary arithmetic. */
	public static function minor( mixed $price ): int {
		$decimals = wc_get_price_decimals();
		$value    = wc_format_decimal( $price, $decimals );
		if ( ! preg_match( '/^\d+(?:\.\d+)?$/D', $value ) ) {
			self::fail( __( 'قیمت محصول معتبر نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		$parts  = explode( '.', $value );
		$digits = ltrim( $parts[0] . str_pad( $parts[1] ?? '', $decimals, '0' ), '0' );
		if ( strlen( $digits ) > 15 || (int) $digits > 900719925474099 ) {
			self::fail( __( 'مبلغ از محدوده قابل پردازش بیشتر است.', 'packchin-kits-for-woocommerce' ) );
		}
		return (int) $digits;
	}

	public static function major( int $minor ): string {
		$decimals = wc_get_price_decimals();
		if ( 0 === $decimals ) {
			return (string) $minor;
		}
		$value = str_pad( (string) $minor, $decimals + 1, '0', STR_PAD_LEFT );
		return substr( $value, 0, -$decimals ) . '.' . substr( $value, -$decimals );
	}

	public static function settings(): array {
		$saved = get_option( 'packchin_settings', array() );
		return array_merge(
			array(
				'autoNext'      => true,
				'showProgress'  => true,
				'showPrices'    => true,
				'groupCart'     => true,
				'emptyMessage'  => __( 'برای این انتخاب، گزینه سازگار و موجود پیدا نشد. انتخاب قبلی را تغییر بدهید.', 'packchin-kits-for-woocommerce' ),
				'lockedMessage' => __( 'ابتدا {step} را انتخاب کنید.', 'packchin-kits-for-woocommerce' ),
			),
			is_array( $saved['settings'] ?? null ) ? $saved['settings'] : array(),
			array( 'currency' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) )
		);
	}

	public static function clean_settings( array $settings ): array {
		$result = array();
		foreach ( array( 'autoNext', 'showProgress', 'showPrices', 'groupCart' ) as $key ) {
			$result[ $key ] = ! empty( $settings[ $key ] );
		}
		foreach ( array( 'emptyMessage', 'lockedMessage' ) as $key ) {
			$result[ $key ] = mb_substr( sanitize_textarea_field( (string) ( $settings[ $key ] ?? '' ) ), 0, 500 );
		}
		return $result;
	}

	public static function json( mixed $data ): string {
		$result = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $result ) ) {
			self::fail( __( 'داده قابل ذخیره نیست.', 'packchin-kits-for-woocommerce' ) );
		}
		return $result;
	}
}
