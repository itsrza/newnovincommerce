<?php

namespace MobinDev\Novin_Commerce\Digits\Common;

use As247\WpEloquent\Application;
use As247\WpEloquent\Database\Schema\Blueprint;
use As247\WpEloquent\Support\Facades\DB;
use As247\WpEloquent\Support\Facades\Schema;

/**
 * Creates/upgrades the database tables used by the Digits module,
 * independently from the core plugin's Activator so this module can be
 * disabled or removed without touching accounting-sync tables.
 */
class Digits_Activator {

	const SCHEMA_VERSION_OPTION = 'novin_commerce_digits_schema_version';
	const CURRENT_SCHEMA        = '1';

	public static function maybe_upgrade() {
		Application::bootWp();

		if ( self::CURRENT_SCHEMA !== (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			self::create_tables();
			update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA );
		}
	}

	private static function create_tables() {
		if ( ! Schema::hasTable( 'novin_commerce_digits_otp' ) ) {
			Schema::create(
				'novin_commerce_digits_otp',
				function ( Blueprint $table ) {
					$table->increments( 'id' );
					$table->string( 'phone', 32 );
					$table->string( 'code', 10 );
					$table->string( 'purpose', 30 )->default( 'login' ); // login | register | password_reset | checkout
					$table->unsignedTinyInteger( 'attempts' )->default( 0 );
					$table->timestamp( 'expires_at' );
					$table->timestamp( 'created_at' )->default( DB::raw( 'CURRENT_TIMESTAMP' ) );
					$table->index( [ 'phone', 'purpose' ] );
					$table->index( 'expires_at' );
				}
			);
		}

		if ( ! Schema::hasTable( 'novin_commerce_digits_sms_log' ) ) {
			Schema::create(
				'novin_commerce_digits_sms_log',
				function ( Blueprint $table ) {
					$table->increments( 'id' );
					$table->string( 'gateway', 30 );
					$table->string( 'phone', 32 );
					$table->string( 'status', 20 ); // success | error
					$table->text( 'message' )->nullable();
					$table->text( 'raw_response' )->nullable();
					$table->timestamp( 'created_at' )->default( DB::raw( 'CURRENT_TIMESTAMP' ) );
					$table->index( [ 'status', 'created_at' ] );
				}
			);
		}
	}
}
