<?php
/**
 * Bootstrap des tests — mocks minimaux des fonctions WordPress utilisées
 * par les parties testables (sanitizers, chunking, cosinus).
 * Permet de lancer les tests en CLI sans installation WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        $str = is_string( $str ) ? $str : '';
        $str = preg_replace( '/<[^>]*>/', '', $str );
        $str = preg_replace( '/[\r\n\t\0\x0B]/', '', $str );
        return trim( $str );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        $str = is_string( $str ) ? $str : '';
        $str = preg_replace( '/<[^>]*>/', '', $str );
        return trim( $str );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $v ) { return abs( (int) $v ); }
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
    function sanitize_hex_color( $c ) {
        return preg_match( '/^#([a-f0-9]{3}){1,2}$/i', $c ) ? $c : null;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) { return (string) $url; }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $str ) { return trim( strip_tags( (string) $str ) ); }
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
    function strip_shortcodes( $str ) {
        return preg_replace( '/\[\/?[^\]]+\]/', '', (string) $str );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$a ) { /* no-op en tests */ }
    function add_filter( ...$a ) { /* no-op */ }
    function register_setting( ...$a ) { /* no-op */ }
    function add_settings_section( ...$a ) { /* no-op */ }
    function add_settings_field( ...$a ) { /* no-op */ }
}
