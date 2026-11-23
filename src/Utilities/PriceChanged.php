<?php
declare(strict_types=1);
namespace Packchin\Kits;

final class PriceChanged extends \RuntimeException {
	public function __construct( public readonly array $quote ) {
		parent::__construct( __( 'قیمت انتخاب‌ها تغییر کرده است؛ مبلغ تازه را بررسی کنید.', 'packchin-kits-for-woocommerce' ), 409 );
	}
}
