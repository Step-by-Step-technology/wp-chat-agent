<?php
/**
 * Search functionality for AI Assistant
 * 
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_Search {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Nothing to initialize
    }
    
    public function brave_search( $query ) {
        $api_key = get_option( 'ai_assistant_brave_api_key' );
        if ( ! $api_key ) {
            return false;
        }
        
        $url = 'https://api.search.brave.com/res/v1/web/search';
        $args = array(
            'headers' => array(
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Subscription-Token' => $api_key,
            ),
            'body' => array(
                'q' => $query,
                'count' => 5,
            ),
            'timeout' => 15,
        );
        
        $response = wp_remote_get( add_query_arg( $args['body'], $url ), $args );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data['web']['results'] ) ) {
            return false;
        }
        
        $results = array();
        foreach ( $data['web']['results'] as $result ) {
            $results[] = array(
                'title' => $result['title'] ?? '',
                'description' => $result['description'] ?? '',
                'url' => $result['url'] ?? '',
            );
        }
        
        return $results;
    }
}
