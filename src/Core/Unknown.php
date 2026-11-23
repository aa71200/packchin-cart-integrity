<?php
declare(strict_types=1);

namespace Packchin\Kits\Core;

final class Unknown {
	public function __construct( public readonly string $reason = 'MISSING_DATA' ) {}
}
