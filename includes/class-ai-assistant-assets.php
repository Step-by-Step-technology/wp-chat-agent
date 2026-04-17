<?php
/**
 * Gestion des assets (CSS/JS) de l'Assistant IA.
 *
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_Assets {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
    }

    public function enqueue_frontend_assets() {
        if ( is_admin() ) {
            return;
        }

        wp_enqueue_style(
            'ai-assistant-style',
            AI_ASSISTANT_URL . 'assets/css/chat.css',
            array(),
            AI_ASSISTANT_VERSION
        );

        $primary_color = AI_Assistant_Settings::get( 'ai_assistant_primary_color' );
        wp_add_inline_style( 'ai-assistant-style', $this->generate_dynamic_css( $primary_color ) );

        wp_enqueue_script(
            'ai-assistant-script',
            AI_ASSISTANT_URL . 'assets/js/chat.js',
            array( 'jquery' ),
            AI_ASSISTANT_VERSION,
            true
        );

        wp_localize_script( 'ai-assistant-script', 'aiAssistant', array(
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'ai_assistant_nonce' ),
            'pluginUrl'         => AI_ASSISTANT_URL,
            'version'           => AI_ASSISTANT_VERSION,
            'siteName'          => AI_Assistant_Settings::get( 'ai_assistant_site_name' ),
            'welcomeMessage'    => AI_Assistant_Settings::get( 'ai_assistant_welcome_message' ),
            'inputPlaceholder'  => AI_Assistant_Settings::get( 'ai_assistant_input_placeholder' ),
            'phoneNumber'       => AI_Assistant_Settings::get( 'ai_assistant_phone_number' ),
            'contactPageUrl'    => AI_Assistant_Settings::get( 'ai_assistant_contact_page_url' ),
            'contactWordLimit'  => (int) AI_Assistant_Settings::get( 'ai_assistant_contact_word_limit' ),
            'enableDebug'       => AI_Assistant_Settings::get( 'ai_assistant_enable_debug' ),
            'persistence'       => AI_Assistant_Settings::get( 'ai_assistant_chat_persistence' ),
            'persistenceTtlMs'  => max( 5, (int) AI_Assistant_Settings::get( 'ai_assistant_chat_persistence_ttl' ) ) * 60 * 1000,
            'maxHistory'        => (int) AI_Assistant_Settings::get( 'ai_assistant_max_history' ),
            'showCredit'        => AI_Assistant_Settings::get( 'ai_assistant_show_credit' ),
            'i18n'              => array(
                'typing'         => 'L\'assistant écrit…',
                'errorGeneric'   => 'Désolé, une erreur est survenue.',
                'errorNetwork'   => 'Erreur de communication avec le serveur.',
                'contactTitle'   => 'Parler à un expert…',
                'phoneLabel'     => 'Téléphone',
                'emailLabel'     => 'Email',
                'debugEmpty'     => 'Aucun log disponible. Envoyez une question d\'abord.',
                'debugTitle'     => 'Derniers logs de débogage :',
                'clearChat'      => 'Effacer la conversation',
            ),
        ) );
    }

    private function generate_dynamic_css( $primary_color ) {
        $primary_color = $primary_color ?: '#10b981';
        $darken        = $this->darken_color( $primary_color, 20 );
        $rgba          = $this->hex_to_rgba( $primary_color, 0.4 );
        $rgba_dark     = $this->hex_to_rgba( $primary_color, 0.6 );
        $rgba_light    = $this->hex_to_rgba( $primary_color, 0.3 );
        $filter        = $this->generate_color_filter( $primary_color );

        return "
            #ai-chat-bubble { background: linear-gradient(135deg, {$primary_color} 0%, {$darken} 100%); box-shadow: 0 10px 30px {$rgba}; }
            #ai-chat-bubble:hover { box-shadow: 0 15px 40px {$rgba_dark}; }
            .chat-header { background: linear-gradient(135deg, {$primary_color} 0%, {$darken} 100%); }
            .message.user { background: linear-gradient(135deg, {$primary_color} 0%, {$darken} 100%); box-shadow: 0 2px 8px {$rgba_light}; }
            #ai-chat-send { background: linear-gradient(135deg, {$primary_color} 0%, {$darken} 100%); }
            #ai-chat-send:hover:not(:disabled) { box-shadow: 0 5px 15px {$rgba}; }
            #ai-chat-input:focus { border-color: {$primary_color}; }
            .step-by-step-credit a { color: {$primary_color}; }
            .step-by-step-credit a:hover { color: {$darken}; }
            .bubble-icon { {$filter} }
        ";
    }

    private function generate_color_filter( $hex_color ) {
        $rgb = $this->hex_to_rgb( $hex_color );
        $r = $rgb[0] / 255; $g = $rgb[1] / 255; $b = $rgb[2] / 255;
        $max = max( $r, $g, $b ); $min = min( $r, $g, $b ); $d = $max - $min;

        if ( $d == 0 ) {
            $h = 0;
        } elseif ( $max == $r ) {
            $h = 60 * fmod( ( $g - $b ) / $d, 6 );
            if ( $b > $g ) $h += 360;
        } elseif ( $max == $g ) {
            $h = 60 * ( ( $b - $r ) / $d + 2 );
        } else {
            $h = 60 * ( ( $r - $g ) / $d + 4 );
        }

        return 'filter: sepia(1) saturate(1000%) hue-rotate(' . round( $h ) . 'deg) brightness(0.9);';
    }

    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return array(
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    private function hex_to_rgba( $hex, $alpha ) {
        list( $r, $g, $b ) = $this->hex_to_rgb( $hex );
        return "rgba($r, $g, $b, $alpha)";
    }

    private function darken_color( $hex, $percent ) {
        list( $r, $g, $b ) = $this->hex_to_rgb( $hex );
        $r = max( 0, min( 255, $r - ( $r * $percent / 100 ) ) );
        $g = max( 0, min( 255, $g - ( $g * $percent / 100 ) ) );
        $b = max( 0, min( 255, $b - ( $b * $percent / 100 ) ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }
}
