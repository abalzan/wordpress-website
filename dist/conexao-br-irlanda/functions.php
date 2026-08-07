<?php
/** Theme setup for Conexão BR Irlanda. */
add_action( 'after_setup_theme', function () {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'title-tag' );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'conexao-br-irlanda', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
} );
