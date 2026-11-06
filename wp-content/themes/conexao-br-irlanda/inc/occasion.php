<?php
/**
 * Conexão BR Irlanda — Seasonal and Commemorative Screen Foundation
 *
 * Provides a deterministic occasion eligibility check for seasonal/commemorative
 * screens. Designed as a reusable foundation that can be extended with visual
 * templates without rewriting core lifecycle logic.
 *
 * Key design decisions:
 * - Dynamic, request-time eligibility check (no scheduled task dependency)
 * - Configuration-driven occasions (PHP config file, no new DB table)
 * - Language isolation through existing Polylang/conexao conventions
 * - Cache-safe: eligibility computed per request to avoid stale state
 * - Safe failure: invalid/ambiguous config never activates a screen
 *
 * @package conexao-br-irlanda
 */

// Quick guard
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The occasion model. Its class lives in a file named after it so the
 * WordPress.Files.FileName convention holds; the legacy helper functions below
 * stay here because existing callers include this file.
 */
require_once __DIR__ . '/class-conexao-seasonal-occasion.php';

function ocasons_register(): array {
	$occasions = array();

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'christmas',
		'name'             => __( 'Christmas', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '12-25',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'christmas',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'halloween',
		'name'             => __( 'Halloween', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '10-31',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'halloween',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'new_year',
		'name'             => __( 'New Year', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '01-01',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'new_year',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'st_patricks_day',
		'name'             => __( 'St. Patrick\'s Day', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '03-17',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'st_patricks_day',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'valentines_day',
		'name'             => __( 'Valentine\'s Day', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::FIXED_ANNUAL,
		'fixed_month_day'  => '02-14',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'valentines_day',
	) );

	$occasions[] = new Conexao_Seasonal_Occasion( array(
		'id'               => 'easter',
		'name'             => __( 'Easter', 'conexao-br-irlanda' ),
		'date_rule'        => Occasion_Date_Rule::VARIABLE_ANNUAL,
		'variable_rule'    => 'easter',
		'lead_in_days'     => 15,
		'enabled'          => true,
		'variant'          => 'easter',
	) );

	return $occasions;
}

function ocasons_get_all(): array {
	static $registered = null;
	if ( null === $registered ) {
		$registered = ocasons_register();
	}
	return $registered;
}

function ocasons_get_active(): array {
	$all = ocasons_get_active_no_filter();
	return array_values(
		array_filter(
			$all,
			function ( $occasion ) {
				return $occasion->is_active() && $occasion->enabled;
			}
		)
	);
}

function ocasons_get_active_no_filter(): array {
	$all    = ocasons_get_all();
	$active = array();
	foreach ( $all as $occasion ) {
		if ( $occasion->is_active() ) {
			$active[] = $occasion;
		}
	}
	return $active;
}

function ocons_get_primary_active(): ?Conexao_Seasonal_Occasion {
	$active = ocasons_get_active();
	return count( $active ) > 0 ? $active[0] : null;
}

function ocasons_get_primary_active(): ?Conexao_Seasonal_Occasion {
	return ocons_get_primary_active();
}

function ocacons_get_primary_display_content() {
	$occasion = ocons_get_primary_active();
	if ( ! $occasion ) {
		return false;
	}
	return $occasion->get_display_content();
}

function ocasons_get_primary_display_content() {
	return ocacons_get_primary_display_content();
}

function conexao_seasonal_is_active(): bool {
	return null !== ocons_get_primary_active();
}

function conexao_seasonal_variant(): ?string {
	$occasion = ocons_get_primary_active();
	return $occasion ? $occasion->variant : null;
}
