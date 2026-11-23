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

if ( '--add-from-session' === ( $argv[1] ?? '' ) ) {
	wp_set_current_user( (int) $argv[2] );
	$request = json_decode( base64_decode( $argv[3], true ), true, 512, JSON_THROW_ON_ERROR );
	fwrite( STDOUT, "adding\n" );
	fflush( STDOUT );
	( new Commerce() )->add( $request );
	echo "saved\n";
	exit;
}

Repository::install();

$customer = 0;
if ( in_array( '--customer', $argv, true ) ) {
	$customer = wp_insert_user( [
		'user_login' => 'packchin-fixture-' . wp_generate_password( 12, false ),
		'user_pass' => wp_generate_password( 32 ),
		'role' => 'customer',
	] );
	if ( is_wp_error( $customer ) ) {
		throw new RuntimeException( $customer->get_error_message() );
	}
	wp_set_current_user( $customer );
}
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
if ( $customer ) {
	Commerce::session();
	$worker = proc_open(
		[ PHP_BINARY, __FILE__, '--add-from-session', (string) $customer, base64_encode( wp_json_encode( $body ) ) ],
		[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
		$pipes
	);
	if ( ! is_resource( $worker ) ) {
		throw new RuntimeException( 'Could not start a separate cart process.' );
	}
	try {
		if ( 'adding' !== trim( (string) fgets( $pipes[1] ) ) ) {
			throw new RuntimeException( 'The separate cart process did not start.' );
		}
		$commerce->add( $body );
		assert_cart( 'saved' === trim( (string) stream_get_contents( $pipes[1] ) ), 'Both independent requests complete for the same customer and request ID' );
	} finally {
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		proc_close( $worker );
	}
} else {
	$commerce->add( $body );
}
$snapshot = WC()->cart->get_cart_contents();
assert_cart( count( $snapshot ) === 2, 'Both products were added as a single native WooCommerce cart group' );
$commerce->add( $body );
assert_cart( count( WC()->cart->get_cart_contents() ) === 2, 'Replaying a request adds no duplicate lines' );
try {
	$commerce->add( $body + [ 'services' => [ 'other-choice' ] ] );
	throw new LogicException( 'Expected the conflicting request ID to be rejected.' );
} catch ( RuntimeException $error ) {
	assert_cart( 409 === $error->getCode(), 'A request ID cannot be reused for a different selection' );
}
$ordinary = $create( 'Workshop accessory', '15' );
$coupon = new WC_Coupon();
$coupon->set_code( strtolower( wp_generate_password( 10, false ) ) );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( 3 );
$coupon->save();
WC()->cart->add_to_cart( $ordinary->get_id(), 2, 0, [], [ 'fixture_note' => 'ordinary product' ] );
WC()->cart->apply_coupon( $coupon->get_code() );
WC()->cart->calculate_totals();
WC()->session->save_data();
$session_before = WC()->session->get_session_data();
$persistent_before = $customer ? get_user_meta( $customer, '_woocommerce_persistent_cart_' . get_current_blog_id(), true ) : null;

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
	assert_cart( count( WC()->cart->get_cart_contents() ) === 3, 'A stale price token requires reconfirmation before the cart is changed' );
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
	$session_after = ( new WC_Session_Handler() )->get_session( WC()->session->get_customer_id(), [] );
	foreach ( [ 'cart', 'removed_cart_contents', 'applied_coupons', 'coupon_discount_totals', 'coupon_discount_tax_totals', 'packchin_operations' ] as $field ) {
		if ( maybe_unserialize( $session_before[ $field ] ?? null ) !== maybe_unserialize( $session_after[ $field ] ?? null ) ) {
			throw new RuntimeException( 'Rollback changed persisted cart field: ' . $field );
		}
	}
	assert_cart( $blocked && 3 === count( WC()->cart->get_cart_contents() ), 'A failed second line restores product data, quantities and coupons in the saved session' );
	if ( $customer ) {
		wp_cache_delete( $customer, 'user_meta' );
		assert_cart( $persistent_before === get_user_meta( $customer, '_woocommerce_persistent_cart_' . get_current_blog_id(), true ), 'A failed request leaves the logged-in persistent cart intact' );
	}
}
if ( $html ) {
	preg_match_all( '/^PASS: (.+)$/m', ob_get_clean(), $results );
	$checks = $results[1];
	require __DIR__ . '/cart-report.php';
} else {
	echo "Cart integrity fixture passed.\n";
}
