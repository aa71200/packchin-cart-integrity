<?php
declare(strict_types=1);

namespace Packchin\Kits\Core;

final class Units {
	/** The merchant supplies exact rational factors relative to a canonical unit. */
	public static function normalize( int $amount, array $factor ): int {
		$numerator   = $factor['numerator'] ?? null;
		$denominator = $factor['denominator'] ?? null;
		if ( ! is_int( $numerator ) || ! is_int( $denominator ) || $numerator <= 0 || $denominator <= 0 ) {
			throw new \InvalidArgumentException( 'Invalid unit factor.' );
		}
		$scaled = $amount * $numerator;
		if ( ! is_int( $scaled ) ) {
			throw new \OverflowException( 'Unit overflow.' );
		}
		if ( $scaled % $denominator !== 0 ) {
			throw new \InvalidArgumentException( 'Conversion loses precision.' );
		}
		return intdiv( $scaled, $denominator );
	}
}
