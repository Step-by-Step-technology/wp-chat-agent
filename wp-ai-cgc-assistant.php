<?php
/**
 * Plugin Name: Assistant IA
 * Description: Assistant IA conversationnel flottant pour WordPress (OpenAI + Brave Search optionnel). Entièrement configurable depuis l'admin.
 * Version: 2.6.0
 * Author: Step by Step
 * Author URI: https://step-by-step.technology
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * Tested up to: 6.7
 * Text Domain: ai-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// GARDE-FOU FATALS EN REQUÊTE AJAX uniquement.
// On démarre un output buffer dès la première ligne du plugin : tout ce que WP
// pourrait sortir (page HTML d'erreur fatale incluse) reste dans notre buffer.
// Notre shutdown le vide et envoie un JSON propre à la place.
// ============================================================================
if ( defined( 'DOING_AJAX' ) && DOING_AJAX
     && isset( $_REQUEST['action'] )
     && in_array( $_REQUEST['action'], array( 'ai_assistant_query' ), true ) ) {

    @ob_start();

    register_shutdown_function( function () {
        $err = error_get_last();
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
        $is_fatal = $err && in_array( $err['type'], $fatal_types, true );

        if ( ! $is_fatal ) {
            // Pas de fatal : laisse le flow normal se dérouler (flush du buffer).
            return;
        }

        // Fatal : on jette tout output parasite et on renvoie un JSON propre.
        while ( ob_get_level() > 0 ) { @ob_end_clean(); }
        if ( ! headers_sent() ) {
            @http_response_code( 500 );
            @header( 'Content-Type: application/json; charset=utf-8' );
        }
        echo json_encode( array(
            'success' => false,
            'data'    => array(
                'message' => sprintf(
                    'Fatal PHP : %s dans %s:%d',
                    $err['message'],
                    basename( $err['file'] ),
                    $err['line']
                ),
            ),
        ) );
    } );
}

// ============================================================================
// Chargement normal du plugin
// ============================================================================
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ai-assistant.php';

function ai_assistant_init() {
    $plugin = AI_Assistant::get_instance();
    $plugin->run();
}
add_action( 'plugins_loaded', 'ai_assistant_init' );
