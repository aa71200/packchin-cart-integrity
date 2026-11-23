<?php
/** Central class loader; no Composer runtime dependency. */
defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $qualified_class ): void {
		$prefix = 'Packchin\Kits\\';
		if ( ! str_starts_with( $qualified_class, $prefix ) ) {
			return;
		}
		$classes  = array(
			'Plugin'          => 'Core/Plugin.php',
			'Repository'      => 'Database/Repository.php',
			'Catalog'         => 'Features/Catalog.php',
			'Commerce'        => 'Features/Commerce.php',
			'Controller'      => 'Features/Controller.php',
			'Definition'      => 'Features/Definition.php',
			'RuleEngine'      => 'Features/RuleEngine.php',
			'KitDefinition'   => 'Features/KitDefinition.php',
			'Admin'          => 'Features/Admin.php',
			'Support'         => 'Utilities/Support.php',
			'PriceChanged'    => 'Utilities/PriceChanged.php',
		);
		$relative = substr( $qualified_class, strlen( $prefix ) );
		if ( isset( $classes[ $relative ] ) ) {
			require_once PACKCHIN_DIR . 'src/' . $classes[ $relative ];
			return;
		}
		if ( str_starts_with( $relative, 'Core\\' ) ) {
			$file = PACKCHIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}
);
