<?php
/**
 * Local development only: disable Jetpack Account Protection.
 *
 * Why: the Account Protection module flags weak passwords (e.g. the local
 * `test` account) against known data-breach lists and then requires an
 * email verification code to complete login. This local environment has no
 * mail transport, so the code can never arrive and wp-admin login becomes
 * impossible. Defining this constant is Jetpack's official killswitch for
 * the module (see jetpack_account_protection's is_supported_environment()).
 *
 * Production (WordPress.com) is unaffected — this file only exists in the
 * local Docker stack.
 *
 * @package Local
 */

define( 'DISABLE_JETPACK_ACCOUNT_PROTECTION', true );

/**
 * Belt and braces: never apply the password-breach challenge to any user,
 * even if the module still manages to register its runtime hooks.
 */
add_filter( 'jetpack_account_protection_user_requires_protection', '__return_false' );
