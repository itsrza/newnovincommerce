<?php
namespace Novinwp\Novin_Commerce\Common;

class SyncLog {
    public static function add( $event, $status = 'info', $item_type = '', $item_id = 0, $message = '', $context = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'novin_commerce_sync_logs';
        $allowed = [ 'info', 'success', 'warning', 'error' ];
        $status = in_array( $status, $allowed, true ) ? $status : 'info';
        $safe_context  = self::safe_context( $context );
        $context_json = ! empty( $safe_context ) ? wp_json_encode( $safe_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null;
        return $wpdb->insert(
            $table,
            [
                'event_type' => sanitize_key( $event ),
                'status'     => $status,
                'item_type'  => sanitize_key( $item_type ),
                'item_id'    => absint( $item_id ),
                'message'    => sanitize_textarea_field( Text_Encoding::normalize( $message ) ),
                'context'    => $context_json,
                'created_at'=> current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Keep operational context useful without allowing credentials, tokens,
     * OTPs or provider payloads into the database/log UI.
     *
     * @param mixed $context Context supplied by a caller.
     * @return array
     */
    private static function safe_context( $context ) {
        if ( ! is_array( $context ) ) {
            return array();
        }
        $safe = array();
        foreach ( $context as $key => $value ) {
            $name = strtolower( (string) $key );
            if ( preg_match( '/(?:pass|secret|token|api|auth|otp|code|key|response|body|phone)/i', $name ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $safe[ sanitize_key( $key ) ] = self::safe_context( $value );
            } elseif ( is_scalar( $value ) ) {
                $text = sanitize_text_field( (string) $value );
                $safe[ sanitize_key( $key ) ] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
            }
        }
        return $safe;
    }

    public static function recent( $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'novin_commerce_sync_logs';
        $limit = min( 50, max( 1, absint( $limit ) ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
        foreach ( (array) $rows as $row ) {
            if ( isset( $row->message ) ) {
                $row->message = Text_Encoding::normalize( $row->message );
            }
        }
        return $rows;
    }

    public static function count_since( $seconds = 86400, $status = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'novin_commerce_sync_logs';
        $since = gmdate( 'Y-m-d H:i:s', time() - absint( $seconds ) );
        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status = %s", $since, sanitize_key( $status ) ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since ) );
    }

    public static function prune( $days = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'novin_commerce_sync_logs';
        $days = max( 1, absint( $days ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
    }
}
