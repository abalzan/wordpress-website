<?php
/**
 * Minimal WordPress environment for exercising the REAL event date layer
 * (Conexao_Event_Query + Conexao_Event_Recurrence) without WordPress.
 *
 * Backed by SQLite so the production SQL in candidate_event_ids() runs
 * unmodified (table names come from $wpdb->posts / $wpdb->postmeta).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

final class Harness_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $pdo;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	/** WordPress-compatible %s / %d substitution (enough for our query). */
	public function prepare( $sql, ...$args ) {
		$out = '';
		$i   = 0;
		$len = strlen( $sql );
		for ( $p = 0; $p < $len; $p++ ) {
			if ( '%' === $sql[ $p ] && $p + 1 < $len ) {
				$type = $sql[ $p + 1 ];
				if ( 's' === $type || 'd' === $type ) {
					$arg = $args[ $i++ ] ?? '';
					$out .= ( 'd' === $type ) ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
					$p++;
					continue;
				}
			}
			$out .= $sql[ $p ];
		}
		return $out;
	}

	public function get_col( $sql ) {
		$rows = $this->pdo->query( $sql )->fetchAll( PDO::FETCH_NUM );
		return array_map( static function ( $r ) { return $r[0]; }, $rows );
	}
}

global $HARNESS_TRANSIENTS, $HARNESS_META_CACHE, $HARNESS_TZ;
$HARNESS_TRANSIENTS = array();
$HARNESS_META_CACHE = array();
$HARNESS_TZ         = 'Europe/Dublin';

function wp_timezone() {
	global $HARNESS_TZ;
	return new DateTimeZone( $HARNESS_TZ );
}

/** current_time() is only used by legacy paths we do not exercise here. */
function current_time( $format ) {
	return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( $format );
}

function get_post_meta( $post_id, $key, $single = false ) {
	global $wpdb;
	$stmt = $wpdb->pdo->prepare( 'SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id = ? AND meta_key = ? LIMIT 1' );
	$stmt->execute( array( (int) $post_id, (string) $key ) );
	$row = $stmt->fetch( PDO::FETCH_NUM );
	return $row ? $row[0] : '';
}

function update_post_meta( $post_id, $key, $value ) {
	global $wpdb;
	$wpdb->pdo->prepare( 'DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id = ? AND meta_key = ?' )
		->execute( array( (int) $post_id, (string) $key ) );
	$wpdb->pdo->prepare( 'INSERT INTO ' . $wpdb->postmeta . ' (post_id, meta_key, meta_value) VALUES (?,?,?)' )
		->execute( array( (int) $post_id, (string) $key, (string) $value ) );
}

function update_meta_cache( $type, $ids ) {
	global $HARNESS_META_CACHE;
	foreach ( (array) $ids as $id ) {
		$HARNESS_META_CACHE[ (int) $id ] = true;
	}
}

function get_transient( $key ) {
	global $HARNESS_TRANSIENTS;
	if ( ! isset( $HARNESS_TRANSIENTS[ $key ] ) ) {
		return false;
	}
	$entry = $HARNESS_TRANSIENTS[ $key ];
	if ( null !== $entry['expires'] && $entry['expires'] <= time() ) {
		unset( $HARNESS_TRANSIENTS[ $key ] );
		return false;
	}
	return $entry['value'];
}

function set_transient( $key, $value, $ttl = 0 ) {
	global $HARNESS_TRANSIENTS;
	$HARNESS_TRANSIENTS[ $key ] = array(
		'value'   => $value,
		'expires' => ( $ttl > 0 ) ? time() + (int) $ttl : null,
	);
	return true;
}

function delete_transient( $key ) {
	global $HARNESS_TRANSIENTS;
	unset( $HARNESS_TRANSIENTS[ $key ] );
	return true;
}

/* Polylang: single-language harness (pt), matching the production PT archive. */
function pll_current_language( $field = '' ) {
	return 'pt' === $field || '' === $field ? 'pt' : 'pt';
}
function pll_languages_list( $args = array() ) {
	return array( 'pt', 'en' );
}
function pll_get_post_language( $post_id, $field = '' ) {
	return 'pt';
}
function pll_get_post( $post_id, $lang = '' ) {
	return 0;
}
