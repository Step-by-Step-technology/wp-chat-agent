<?php
/**
 * Main AI Assistant class
 * 
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// GARDE-FOU FATALS POUR L'AJAX — installé dès le chargement de ce fichier.
// Capture tout fatal PHP durant la requête `ai_assistant_query` et renvoie
// un JSON, au lieu de laisser WP afficher sa page HTML "Internal Server Error".
// ============================================================================
if ( defined( 'DOING_AJAX' ) && DOING_AJAX
     && isset( $_REQUEST['action'] )
     && $_REQUEST['action'] === 'ai_assistant_query' ) {

    // 1) OB : capture TOUT ce que WP pourrait sortir.
    if ( ! defined( 'AI_ASSISTANT_OB_STARTED' ) ) {
        define( 'AI_ASSISTANT_OB_STARTED', true );
        @ob_start();
    }

    // 2) Shutdown : si fatal, vide l'output et renvoie JSON propre.
    register_shutdown_function( function () {
        $err = error_get_last();
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
        if ( ! $err || ! in_array( $err['type'], $fatal_types, true ) ) return;

        while ( ob_get_level() > 0 ) { @ob_end_clean(); }
        if ( ! headers_sent() ) {
            @http_response_code( 500 );
            @header( 'Content-Type: application/json; charset=utf-8' );
        }
        echo json_encode( array(
            'success' => false,
            'data'    => array(
                'message' => sprintf( 'Fatal PHP : %s dans %s:%d', $err['message'], basename( $err['file'] ), $err['line'] ),
            ),
        ) );
    } );
}

class AI_Assistant {
    
    /**
     * Plugin version
     */
    const VERSION = '2.6.0';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }
    
    /**
     * Define plugin constants
     */
    private function define_constants() {
        define( 'AI_ASSISTANT_VERSION', self::VERSION );
        define( 'AI_ASSISTANT_PATH', plugin_dir_path( __FILE__ ) . '../' );
        define( 'AI_ASSISTANT_URL', plugin_dir_url( __FILE__ ) . '../' );
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Load text domain
        add_action( 'init', array( $this, 'load_textdomain' ) );
        
        // Include required files
        $this->includes();
        
        // Activation/deactivation hooks
        register_activation_hook( AI_ASSISTANT_PATH . 'wp-ai-cgc-assistant.php', array( $this, 'activate' ) );
        register_deactivation_hook( AI_ASSISTANT_PATH . 'wp-ai-cgc-assistant.php', array( $this, 'deactivate' ) );
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'ai-assistant', false, dirname( plugin_basename( AI_ASSISTANT_PATH . 'wp-ai-cgc-assistant.php' ) ) . '/languages' );
    }
    
    /**
     * Include required files
     */
    private function includes() {
        require_once AI_ASSISTANT_PATH . 'includes/class-ai-assistant-settings.php';
        require_once AI_ASSISTANT_PATH . 'includes/class-ai-assistant-ajax.php';
        require_once AI_ASSISTANT_PATH . 'includes/class-ai-assistant-assets.php';
        require_once AI_ASSISTANT_PATH . 'includes/class-ai-assistant-search.php';
        require_once AI_ASSISTANT_PATH . 'includes/class-ai-assistant-logger.php';
    }
    
    /**
     * Activation hook
     */
    public function activate() {
        if ( class_exists( 'AI_Assistant_Logger' ) ) {
            AI_Assistant_Logger::maybe_install();
        }
    }
    
    /**
     * Deactivation hook
     */
    public function deactivate() {
        // Nothing to clean up
    }
    
    /**
     * Run the plugin
     */
    public function run() {
        // Initialize components
        AI_Assistant_Settings::get_instance();
        AI_Assistant_Ajax::get_instance();
        AI_Assistant_Assets::get_instance();
        AI_Assistant_Search::get_instance();
    }
}
