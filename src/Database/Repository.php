<?php
declare(strict_types=1);

namespace Packchin\Kits;

/** Revision checks, immutable publication and rollback derive from Hamsaz Repository. */
final class Repository {
	private function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'packchin_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix  = $wpdb->prefix . 'packchin_';
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$prefix}configurations (
id varchar(100) NOT NULL,
title varchar(160) NOT NULL,
draft longtext NOT NULL,
revision bigint unsigned NOT NULL DEFAULT 1,
published_id varchar(100) DEFAULT NULL,
trashed tinyint NOT NULL DEFAULT 0,
created_by bigint unsigned NOT NULL DEFAULT 0,
updated_at datetime NOT NULL,
PRIMARY KEY  (id)
) ENGINE=InnoDB $collate;"
		);
		dbDelta(
			"CREATE TABLE {$prefix}versions (
id varchar(100) NOT NULL,
configuration_id varchar(100) NOT NULL,
kind varchar(16) NOT NULL,
definition longtext NOT NULL,
content_hash char(64) NOT NULL,
created_at datetime(6) NOT NULL,
PRIMARY KEY  (id),
KEY configuration_kind (configuration_id,kind)
) ENGINE=InnoDB $collate;"
		);
		update_option( 'packchin_schema_version', 1, false );
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'manage_packchin_kits' );
			}
		}
	}

	public function all(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY updated_at DESC,id LIMIT 200', $this->table( 'configurations' ) ) );
		return array_values( array_filter( array_map( array( $this, 'get' ), $ids ?? array() ) ) );
	}

	public function get( string $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $this->table( 'configurations' ), $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$published = $row['published_id'] ? $this->version( $row['published_id'] ) : null;
		return array(
			'id'          => $id,
			'draft'       => json_decode( $row['draft'], true ),
			'revision'    => (int) $row['revision'],
			'published'   => $published['definition'] ?? null,
			'publishedAt' => isset( $published['created_at'] ) ? $published['created_at'] . 'Z' : null,
			'versionId'   => $row['published_id'],
			'trashed'     => (bool) $row['trashed'],
			'updatedAt'   => $row['updated_at'] . 'Z',
		);
	}

	public function version( string $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $this->table( 'versions' ), $id ), ARRAY_A );
		if ( $row ) {
			$row['definition'] = json_decode( $row['definition'], true );
		}
		return $row ?? null;
	}

	/** Each write checks the revision displayed in the editor. */
	public function save( string $id, array $definition, int $expected ): array {
		global $wpdb;
		$table = $this->table( 'configurations' );
		$row = array(
			'title' => $definition['name'],
			'draft' => Support::json( $definition ),
			'revision' => $expected + 1,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( 0 === $expected ) {
			$result = $wpdb->insert( $table, $row + array( 'id' => $id, 'created_by' => get_current_user_id() ) );
		} else {
			$result = $wpdb->update( $table, $row, array( 'id' => $id, 'revision' => $expected ) );
		}
		if ( 1 !== $result ) {
			Support::fail( __( 'ذخیره انجام نشد. نسخهٔ فعلی را دوباره باز کنید.', 'packchin-kits-for-woocommerce' ), false === $result ? 500 : 409 );
		}
		return $this->get( $id );
	}

	public function set_archived( string $id, int $expected, bool $archive ): array {
		global $wpdb;
		$result = $wpdb->update(
			$this->table( 'configurations' ),
			array( 'trashed' => (int) $archive, 'revision' => $expected + 1 ),
			array( 'id' => $id, 'revision' => $expected )
		);
		if ( 1 !== $result ) {
			Support::fail( __( 'وضعیت پک تغییر کرده است. نسخهٔ فعلی را باز کنید.', 'packchin-kits-for-woocommerce' ), false === $result ? 500 : 409 );
		}
		return $this->get( $id );
	}

	private function insert_version( string $id, string $kind, array $definition, ?string $version_id = null ): string {
		global $wpdb;
		$version_id ??= wp_generate_uuid4();
		$json         = Support::json( $definition );
		$previous     = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM %i WHERE configuration_id=%s', $this->table( 'versions' ), $id ) );
		$stamp        = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		if ( $previous && $stamp->format( 'Y-m-d H:i:s.u' ) <= $previous ) {
			$stamp = ( new \DateTimeImmutable( $previous, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 microsecond' );
		}
		if ( 1 !== $wpdb->insert(
			$this->table( 'versions' ),
			array(
				'id'               => $version_id,
				'configuration_id' => $id,
				'kind'             => $kind,
				'definition'       => $json,
				'content_hash'     => hash( 'sha256', $json ),
				'created_at'       => $stamp->format( 'Y-m-d H:i:s.u' ),
			)
		) ) {
			Support::fail( __( 'ثبت نسخه مستقل انجام نشد.', 'packchin-kits-for-woocommerce' ), 500 );
		}
		return $version_id;
	}

	public function publish( string $id, int $expected ): array {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s FOR UPDATE', $this->table( 'configurations' ), $id ), ARRAY_A );
			if ( ! $row || (int) $row['revision'] !== $expected ) {
				Support::fail( __( 'پیش‌نویس تغییر کرده است؛ ابتدا نسخه تازه را ذخیره کنید.', 'packchin-kits-for-woocommerce' ), 409 );
			}
			$definition = json_decode( $row['draft'], true );
			if ( $row['trashed'] ) {
				Support::fail( __( 'پک بایگانی شده است.', 'packchin-kits-for-woocommerce' ), 409 );
			}
			KitDefinition::compile( KitDefinition::editor( $definition ), true );
			$version_id = $this->insert_version( $id, 'published', $definition );
			if ( 1 !== $wpdb->update(
				$this->table( 'configurations' ),
				array( 'published_id' => $version_id, 'revision' => $expected + 1 ),
				array(
					'id'       => $id,
					'revision' => $expected,
				)
			) ) {
				Support::fail( __( 'انتشار انجام نشد.', 'packchin-kits-for-woocommerce' ), 500 );
			}
			$wpdb->query( 'COMMIT' );
			return $this->get( $id );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}
}
