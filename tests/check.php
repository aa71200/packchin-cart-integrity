<?php
declare(strict_types=1);

use Packchin\Kits\Commerce;
use Packchin\Kits\KitDefinition;
use Packchin\Kits\PriceChanged;
use Packchin\Kits\Repository;
use Packchin\Kits\RuleEngine;

if ( PHP_SAPI !== 'cli' || ! getenv( 'CODEXAWP_TEST_WP_LOAD' ) ) {
	throw new RuntimeException( 'Set CODEXAWP_TEST_WP_LOAD to a disposable WordPress installation.' );
}
require getenv( 'CODEXAWP_TEST_WP_LOAD' );
if ( 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'Use a local test store with WooCommerce active.' );
}
define( 'PACKCHIN_DIR', dirname( __DIR__ ) . '/' );
require PACKCHIN_DIR . 'autoload.php';
Repository::install();

$html = in_array( '--html', $argv, true );
if ( $html ) {
	ob_start();
}

function assert_cart( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}

$create = static function ( string $name, string $price ): WC_Product_Simple {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( $price );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 4 );
	$product->set_virtual( true );
	$product->save();
	return $product;
};
$first = $create( 'Workshop camera', '19' );
$second = $create( 'Workshop recorder', '28' );
$definition = KitDefinition::compile( [
	'name' => 'Camera and recorder kit',
	'steps' => [
		[ 'name' => 'Camera', 'products' => [ $first->get_id() ] ],
		[ 'name' => 'Recorder', 'products' => [ $second->get_id() ] ],
	],
], true );

$id = wp_generate_uuid4();
$repository = new Repository();
$draft = $repository->save( $id, $definition, 0 );
$published = $repository->publish( $id, $draft['revision'] );
$selection = [
	'step-1' => [ [ 'id' => (string) $first->get_id(), 'qty' => 1 ] ],
	'step-2' => [ [ 'id' => (string) $second->get_id(), 'qty' => 1 ] ],
];
$commerce = new Commerce();
$quote = static fn() => ( new RuleEngine() )->assess( $definition, $selection );
$body = [
	'id' => $id, 'versionId' => $published['versionId'], 'selections' => $selection,
	'priceToken' => $quote()['priceToken'], 'requestId' => wp_generate_uuid4(),
];
$commerce->add( $body );
$snapshot = WC()->cart->get_cart_contents();
assert_cart( count( $snapshot ) === 2, 'Both products were added as a single native WooCommerce cart group' );
$commerce->add( $body );
assert_cart( count( WC()->cart->get_cart_contents() ) === 2, 'Replaying a request adds no duplicate lines' );

$first->set_regular_price( '25' );
$first->set_price( '25' );
$first->save();
// The checkout requests are separate in production. Clear only the adapter's request-local memo in this CLI process.
( new ReflectionProperty( \Packchin\Kits\Catalog::class, 'rows' ) )->setValue( null, [] );
$body['requestId'] = wp_generate_uuid4();
try {
	$commerce->add( $body );
	throw new RuntimeException( 'Expected price reconfirmation' );
} catch ( PriceChanged $changed ) {
	assert_cart( count( WC()->cart->get_cart_contents() ) === 2, 'A stale price token requires reconfirmation before the cart is changed' );
}

$body['priceToken'] = $quote()['priceToken'];
$blocked = false;
add_filter( 'woocommerce_add_to_cart_validation', static function ( bool $valid, int $product_id ) use ( $second, &$blocked ): bool {
	if ( $second->get_id() === $product_id ) {
		$blocked = true;
		return false;
	}
	return $valid;
}, 99, 2 );
try {
	$commerce->add( $body );
	throw new LogicException( 'Expected the second line to fail' );
} catch ( RuntimeException $failure ) {
	assert_cart( $blocked && array_keys( WC()->cart->get_cart_contents() ) === array_keys( $snapshot ), 'A failed second line restores the previous WooCommerce cart' );
}
if ( $html ) {
	preg_match_all( '/^PASS: (.+)$/m', ob_get_clean(), $results );
	$checks = $results[1];
	require __DIR__ . '/cart-report.php';
} else {
	echo "Cart integrity fixture passed.\n";
}
