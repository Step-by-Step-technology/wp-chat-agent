<?php
/**
 * Gestion des réglages de l'Assistant IA (interface en français, config-driven).
 *
 * @package AI_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Assistant_Settings {

    const OPTION_GROUP = 'ai_assistant_settings';
    const PAGE_SLUG    = 'ai-assistant-settings';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Ne registre les settings QUE sur de vraies pages admin, jamais pendant AJAX/CRON/REST.
        // Évite tout effet de bord pendant les requêtes AJAX qui n'ont pas besoin de ces hooks.
        if ( $this->is_real_admin_request() ) {
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
        }
        // Bouton de test OpenAI (AJAX admin seulement, utilisateur authentifié admin).
        add_action( 'wp_ajax_ai_assistant_test_openai', array( $this, 'ajax_test_openai' ) );
        add_action( 'wp_ajax_ai_assistant_reset_rate_limit', array( $this, 'ajax_reset_rate_limit' ) );
    }

    private function is_real_admin_request() {
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return false;
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return false;
        return is_admin();
    }

    /**
     * Schéma de tous les réglages — source unique de vérité.
     * Utilisé pour : register_setting, add_settings_field, rendu, et lecture.
     */
    public static function schema() {
        return array(

            // ───────── API & modèles ─────────
            'api' => array(
                'title'  => 'Clés API et modèles',
                'fields' => array(
                    'ai_assistant_api_key' => array(
                        'label'       => 'Clé API OpenAI',
                        'type'        => 'password',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'description' => 'Obtenez une clé sur <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>.',
                    ),
                    'ai_assistant_main_model' => array(
                        'label'       => 'Modèle principal',
                        'type'        => 'select',
                        'default'     => 'gpt-4.1-nano',
                        'sanitize'    => 'sanitize_text_field',
                        'options'     => array(
                            'gpt-5.4-nano' => 'GPT-5.4 Nano',
                            'gpt-5-nano'   => 'GPT-5 Nano',
                            'gpt-4.1-nano' => 'GPT-4.1 Nano',
                        ),
                        'description' => 'Modèle OpenAI utilisé pour générer les réponses.',
                    ),
                    'ai_assistant_max_tokens' => array(
                        'label'       => 'Longueur max. des réponses (tokens)',
                        'type'        => 'number',
                        'default'     => 400,
                        'sanitize'    => 'absint',
                        'min'         => 50,
                        'max'         => 2000,
                        'description' => 'Plus élevé = réponses plus longues (et coût plus élevé).',
                    ),
                    'ai_assistant_brave_api_key' => array(
                        'label'       => 'Clé API Brave Search',
                        'type'        => 'password',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'description' => 'Optionnel. <a href="https://brave.com/search/api/" target="_blank">Obtenir une clé</a>.',
                    ),
                    'ai_assistant_enable_web_search' => array(
                        'label'       => 'Activer la recherche web',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Utilise Brave Search pour les questions nécessitant des infos récentes.',
                    ),
                ),
            ),

            // ───────── Apparence ─────────
            'appearance' => array(
                'title'  => 'Apparence et identité',
                'fields' => array(
                    'ai_assistant_site_name' => array(
                        'label'       => 'Nom de l\'assistant',
                        'type'        => 'text',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'placeholder' => 'Mon site',
                        'description' => 'Affiché dans l\'en-tête et le message de bienvenue. Laisser vide utilise « Assistant » par défaut.',
                    ),
                    'ai_assistant_welcome_message' => array(
                        'label'       => 'Message de bienvenue',
                        'type'        => 'textarea',
                        'default'     => 'Bonjour ! Je suis votre assistant IA. Posez-moi vos questions.',
                        'sanitize'    => 'sanitize_textarea_field',
                        'rows'        => 3,
                        'description' => 'Premier message affiché à l\'ouverture du chat.',
                    ),
                    'ai_assistant_input_placeholder' => array(
                        'label'       => 'Placeholder du champ de saisie',
                        'type'        => 'text',
                        'default'     => 'Tapez votre question ici…',
                        'sanitize'    => 'sanitize_text_field',
                    ),
                    'ai_assistant_primary_color' => array(
                        'label'       => 'Couleur principale',
                        'type'        => 'color',
                        'default'     => '#10b981',
                        'sanitize'    => 'sanitize_hex_color',
                        'description' => 'Couleur de la bulle, de l\'en-tête et des boutons.',
                    ),
                ),
            ),

            // ───────── Contact ─────────
            'contact' => array(
                'title'  => 'Boutons de contact',
                'fields' => array(
                    'ai_assistant_phone_number' => array(
                        'label'       => 'Numéro de téléphone',
                        'type'        => 'text',
                        'default'     => '',
                        'sanitize'    => 'sanitize_text_field',
                        'placeholder' => '+33123456789',
                        'description' => 'Format international (ex: +33123456789). Laisser vide pour masquer le bouton téléphone.',
                    ),
                    'ai_assistant_contact_page_url' => array(
                        'label'       => 'URL de la page contact',
                        'type'        => 'url',
                        'default'     => '',
                        'sanitize'    => 'esc_url_raw',
                        'placeholder' => 'https://exemple.com/contact/',
                        'description' => 'Laisser vide pour masquer le bouton email/contact.',
                    ),
                    'ai_assistant_show_credit' => array(
                        'label'       => 'Afficher le crédit auteur',
                        'type'        => 'bool',
                        'default'     => 'yes',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Affiche « développement Step by Step » dans le footer du chat. Décochable.',
                    ),
                    'ai_assistant_contact_word_limit' => array(
                        'label'       => 'Seuil d\'affichage des boutons contact',
                        'type'        => 'number',
                        'default'     => 50,
                        'sanitize'    => 'absint',
                        'min'         => 0,
                        'max'         => 1000,
                        'description' => 'Nombre minimum de mots dans une réponse pour afficher les boutons contact. 0 = toujours afficher.',
                    ),
                ),
            ),

            // ───────── Persistance du chat ─────────
            'persistence' => array(
                'title'  => 'Persistance de la conversation',
                'fields' => array(
                    'ai_assistant_chat_persistence' => array(
                        'label'       => 'Mode de persistance',
                        'type'        => 'select',
                        'default'     => 'session',
                        'sanitize'    => 'sanitize_text_field',
                        'options'     => array(
                            'session' => 'Session (conservée tant que l\'onglet reste ouvert, survit aux changements de page)',
                            'local'   => 'Local (conservée plusieurs jours même après fermeture du navigateur)',
                            'none'    => 'Désactivée (le chat se vide à chaque rechargement)',
                        ),
                        'description' => 'Recommandé : « Session ». Évite la perte du chat lors de la navigation.',
                    ),
                    'ai_assistant_chat_persistence_ttl' => array(
                        'label'       => 'Durée de conservation (minutes, mode Local)',
                        'type'        => 'number',
                        'default'     => 1440,
                        'sanitize'    => 'absint',
                        'min'         => 5,
                        'max'         => 43200,
                        'description' => 'Uniquement pour le mode « Local ». 1440 = 24h, 10080 = 7 jours.',
                    ),
                    'ai_assistant_max_history' => array(
                        'label'       => 'Nombre max. de messages en contexte',
                        'type'        => 'number',
                        'default'     => 10,
                        'sanitize'    => 'absint',
                        'min'         => 2,
                        'max'         => 50,
                        'description' => 'Messages envoyés à OpenAI pour maintenir le contexte.',
                    ),
                ),
            ),

            // ───────── Filtrage et thèmes ─────────
            'scope' => array(
                'title'  => 'Filtrage par thèmes',
                'fields' => array(
                    'ai_assistant_enable_theme_filtering' => array(
                        'label'       => 'Activer le filtrage par thèmes',
                        'type'        => 'bool',
                        'default'     => 'yes',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Si activé, l\'IA ne répond qu\'aux questions liées aux thèmes ci-dessous.',
                    ),
                    'ai_assistant_themes' => array(
                        'label'       => 'Liste des thèmes',
                        'type'        => 'themes',
                        'default'     => '[]',
                        'sanitize'    => array( __CLASS__, 'sanitize_themes' ),
                        'description' => 'Un thème par ligne. Format : <code>Nom du thème</code> ou <code>Nom : Description</code>.',
                    ),
                    'ai_assistant_out_of_scope_message' => array(
                        'label'       => 'Message « hors périmètre »',
                        'type'        => 'textarea',
                        'default'     => 'Désolé, cette question dépasse le périmètre de cet assistant. Comment puis-je vous aider avec nos services ?',
                        'sanitize'    => 'sanitize_textarea_field',
                        'rows'        => 4,
                        'description' => 'Affiché lorsque la réponse ne correspond à aucun thème configuré.',
                    ),
                ),
            ),

            // ───────── Recherche web ─────────
            'websearch' => array(
                'title'  => 'Mots-clés de recherche web',
                'fields' => array(
                    'ai_assistant_web_search_keywords' => array(
                        'label'       => 'Mots-clés déclencheurs',
                        'type'        => 'textarea',
                        'default'     => "actualité\nnews\nnouveau\nrécent\naujourd'hui\nhier\ndemain\n2025\n2026\n2027\ndernier\ndernière\nmise à jour\ntendance\nprix\ntarif",
                        'sanitize'    => array( __CLASS__, 'sanitize_keywords' ),
                        'rows'        => 10,
                        'description' => 'Un mot-clé par ligne. Déclenche une recherche Brave si trouvé dans la question.',
                    ),
                ),
            ),

            // ───────── Sécurité & debug ─────────
            'advanced' => array(
                'title'  => 'Sécurité et débogage',
                'fields' => array(
                    'ai_assistant_max_requests_per_hour' => array(
                        'label'       => 'Requêtes max. par heure / utilisateur',
                        'type'        => 'number',
                        'default'     => 10,
                        'sanitize'    => 'absint',
                        'min'         => 0,
                        'max'         => 1000,
                        'description' => 'Protection anti-abus. 0 = illimité.',
                    ),
                    'ai_assistant_enable_debug' => array(
                        'label'       => 'Mode debug',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Affiche les logs dans le chat et la console. À désactiver en production.',
                    ),
                    'ai_assistant_enable_chat_log' => array(
                        'label'       => 'Journaliser les conversations',
                        'type'        => 'bool',
                        'default'     => 'no',
                        'sanitize'    => array( __CLASS__, 'sanitize_bool' ),
                        'description' => 'Enregistre chaque échange (question/réponse + IP + horodatage) dans un fichier .log journalier. <strong>Attention RGPD</strong> : informez vos utilisateurs dans votre politique de confidentialité.',
                    ),
                    'ai_assistant_log_retention_days' => array(
                        'label'       => 'Rétention des logs (jours)',
                        'type'        => 'number',
                        'default'     => 30,
                        'sanitize'    => 'absint',
                        'min'         => 1,
                        'max'         => 365,
                        'description' => 'Durée de conservation des fichiers de logs. Les fichiers plus anciens sont supprimés automatiquement.',
                    ),
                ),
            ),
        );
    }

    /**
     * Récupère une option en utilisant le défaut du schéma.
     */
    public static function get( $key ) {
        foreach ( self::schema() as $section ) {
            if ( isset( $section['fields'][ $key ] ) ) {
                return get_option( $key, $section['fields'][ $key ]['default'] );
            }
        }
        return get_option( $key );
    }

    public function register_settings() {
        foreach ( self::schema() as $section_id => $section ) {
            add_settings_section(
                'ai_assistant_section_' . $section_id,
                $section['title'],
                '__return_false',
                self::PAGE_SLUG
            );

            foreach ( $section['fields'] as $key => $field ) {
                register_setting(
                    self::OPTION_GROUP,
                    $key,
                    array(
                        'sanitize_callback' => $field['sanitize'],
                        'default'           => $field['default'],
                    )
                );

                add_settings_field(
                    $key,
                    $field['label'],
                    array( $this, 'render_field' ),
                    self::PAGE_SLUG,
                    'ai_assistant_section_' . $section_id,
                    array( 'key' => $key, 'field' => $field )
                );
            }
        }
    }

    public function render_field( $args ) {
        $key   = $args['key'];
        $field = $args['field'];
        $value = get_option( $key, $field['default'] );
        $placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

        switch ( $field['type'] ) {
            case 'password':
                printf(
                    '<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />',
                    esc_attr( $key ),
                    esc_attr( $value )
                );
                break;

            case 'text':
            case 'url':
                printf(
                    '<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
                    esc_attr( $key ),
                    esc_attr( $value ),
                    esc_attr( $placeholder )
                );
                break;

            case 'number':
                printf(
                    '<input type="number" name="%s" value="%s" class="small-text" min="%s" max="%s" />',
                    esc_attr( $key ),
                    esc_attr( $value ),
                    esc_attr( $field['min'] ?? '' ),
                    esc_attr( $field['max'] ?? '' )
                );
                break;

            case 'color':
                printf(
                    '<input type="text" name="%s" value="%s" class="ai-assistant-color-picker" data-default-color="%s" />',
                    esc_attr( $key ),
                    esc_attr( $value ),
                    esc_attr( $field['default'] )
                );
                break;

            case 'bool':
                $checked = ( $value === 'yes' ) ? 'checked' : '';
                // Hidden "no" d'abord : si la case est décochée, seul "no" est envoyé.
                // Si elle est cochée, les deux sont envoyés et PHP garde le dernier (yes).
                printf( '<input type="hidden" name="%s" value="no" />', esc_attr( $key ) );
                printf(
                    '<label><input type="checkbox" name="%s" value="yes" %s /> Activer</label>',
                    esc_attr( $key ),
                    $checked
                );
                break;

            case 'select':
                printf( '<select name="%s">', esc_attr( $key ) );
                foreach ( $field['options'] as $opt_val => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_val ),
                        selected( $value, $opt_val, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'textarea':
                $rows = $field['rows'] ?? 5;
                printf(
                    '<textarea name="%s" rows="%d" class="large-text code">%s</textarea>',
                    esc_attr( $key ),
                    intval( $rows ),
                    esc_textarea( $value )
                );
                break;

            case 'themes':
                $this->render_themes_field( $key, $value );
                break;
        }

        if ( ! empty( $field['description'] ) ) {
            printf( '<p class="description">%s</p>', wp_kses_post( $field['description'] ) );
        }
    }

    private function render_themes_field( $key, $value ) {
        $themes = json_decode( $value, true );
        if ( ! is_array( $themes ) ) {
            $themes = array();
        }

        $text_value = '';
        foreach ( $themes as $theme ) {
            if ( ! isset( $theme['name'] ) ) continue;
            if ( ! empty( $theme['description'] ) ) {
                $text_value .= $theme['name'] . ' : ' . $theme['description'] . "\n";
            } else {
                $text_value .= $theme['name'] . "\n";
            }
        }

        printf(
            '<textarea name="%s" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
            esc_attr( $key ),
            esc_attr( "Création d'entreprise : Aide aux formalités\nRessources humaines" ),
            esc_textarea( $text_value )
        );
    }

    public function add_settings_page() {
        // Menu principal
        add_menu_page(
            'Assistant IA',
            'Assistant IA',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' ),
            'dashicons-format-chat',
            85
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Réglages',
            'Réglages',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Journal des conversations',
            'Journal',
            'manage_options',
            self::PAGE_SLUG . '-logs',
            array( $this, 'render_logs_page' )
        );
    }

    public function render_logs_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! class_exists( 'AI_Assistant_Logger' ) ) {
            echo '<div class="wrap"><h1>Journal</h1><p>Module de journalisation non chargé.</p></div>';
            return;
        }
        AI_Assistant_Logger::maybe_install();

        // Action : vider le journal
        if ( isset( $_POST['ai_assistant_truncate_log'] ) && check_admin_referer( 'ai_assistant_truncate_log' ) ) {
            AI_Assistant_Logger::truncate();
            echo '<div class="notice notice-success is-dismissible"><p>Journal vidé.</p></div>';
        }

        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $ip       = isset( $_GET['ip'] ) ? sanitize_text_field( wp_unslash( $_GET['ip'] ) ) : '';
        $per_page = 25;

        $result = AI_Assistant_Logger::get_page( $page, $per_page, $search, $ip );
        $total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );
        $enabled = ( get_option( 'ai_assistant_enable_chat_log', 'no' ) === 'yes' );
        ?>
        <div class="wrap">
            <h1>Journal des conversations</h1>

            <?php if ( ! $enabled ) : ?>
                <div class="notice notice-warning">
                    <p>La journalisation est <strong>désactivée</strong>. Activez-la dans <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Réglages → Assistant IA</a> (section « Sécurité et débogage »).</p>
                </div>
            <?php endif; ?>

            <p><strong><?php echo (int) $result['total']; ?></strong> échange(s) enregistré(s).</p>

            <form method="get" style="margin:15px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG . '-logs' ); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Rechercher dans les messages…" class="regular-text" />
                <input type="text" name="ip" value="<?php echo esc_attr( $ip ); ?>" placeholder="Filtrer par IP" />
                <button type="submit" class="button">Filtrer</button>
                <?php if ( $search || $ip ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-logs' ) ); ?>" class="button">Effacer les filtres</a>
                <?php endif; ?>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:140px;">Date</th>
                        <th style="width:110px;">IP</th>
                        <th>Question</th>
                        <th>Réponse</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['rows'] ) ) : ?>
                        <tr><td colspan="4"><em>Aucun échange enregistré.</em></td></tr>
                    <?php else : foreach ( $result['rows'] as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
                            <td><?php echo esc_html( wp_trim_words( $row['question'], 40 ) ); ?></td>
                            <td><?php echo esc_html( wp_trim_words( $row['answer'], 60 ) ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $base = add_query_arg( array(
                    'page' => self::PAGE_SLUG . '-logs',
                    's'    => $search ?: null,
                    'ip'   => $ip ?: null,
                ), admin_url( 'admin.php' ) );
            ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%', $base ),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $total_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    ) ); ?>
                </div></div>
            <?php endif; ?>

            <hr style="margin:30px 0;">
            <form method="post" onsubmit="return confirm('Vider tout le journal ? Action irréversible.');">
                <?php wp_nonce_field( 'ai_assistant_truncate_log' ); ?>
                <button type="submit" name="ai_assistant_truncate_log" value="1" class="button button-secondary">Vider le journal</button>
            </form>
        </div>
        <?php
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $test_nonce = wp_create_nonce( 'ai_assistant_test_openai' );
        ?>
        <div class="wrap">
            <h1>Réglages de l'Assistant IA</h1>
            <p class="description">Tous les paramètres du chatbot, regroupés par section.</p>

            <div style="background:#fff; border:1px solid #c3c4c7; padding:15px 20px; margin:15px 0; border-radius:4px;">
                <h2 style="margin-top:0;">Test de la connexion OpenAI</h2>
                <p>Vérifie que la clé API et le modèle configurés fonctionnent réellement.</p>
                <button type="button" id="ai-assistant-test-btn" class="button button-primary">Tester maintenant</button>
                <button type="button" id="ai-assistant-reset-rl-btn" class="button" style="margin-left:10px;">Réinitialiser le compteur anti-abus</button>
                <span id="ai-assistant-test-spinner" class="spinner" style="float:none; margin-left:10px;"></span>
                <div id="ai-assistant-test-result" style="margin-top:15px;"></div>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( 'Enregistrer les modifications' );
                ?>
            </form>
        </div>

        <style>
            .form-table th { width: 260px; }
            #ai-assistant-test-result .ok   { color: #008a20; font-weight: bold; }
            #ai-assistant-test-result .fail { color: #d63638; font-weight: bold; }
            #ai-assistant-test-result pre   { background: #f6f7f7; padding: 10px; overflow: auto; }
        </style>

        <script>
        jQuery(function($){
            $('#ai-assistant-test-btn').on('click', function(){
                var $btn = $(this);
                var $result = $('#ai-assistant-test-result');
                var $spinner = $('#ai-assistant-test-spinner');

                $btn.prop('disabled', true);
                $spinner.css('visibility', 'visible').addClass('is-active');
                $result.html('<em>Test en cours…</em>');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'ai_assistant_test_openai',
                        _wpnonce: '<?php echo esc_js( $test_nonce ); ?>'
                    }
                }).done(function(resp){
                    if (resp && resp.success) {
                        var d = resp.data;
                        var html = '<p class="ok">✓ Connexion OpenAI OK</p>';
                        html += '<ul>';
                        html += '<li><strong>Modèle :</strong> ' + d.model + '</li>';
                        html += '<li><strong>Réponse de test :</strong> ' + d.reply + '</li>';
                        html += '<li><strong>Temps :</strong> ' + d.duration + ' ms</li>';
                        html += '</ul>';
                        $result.html(html);
                    } else {
                        var msg = (resp && resp.data && resp.data.message) || 'Erreur inconnue.';
                        var details = (resp && resp.data && resp.data.details) ? '<pre>' + $('<div>').text(resp.data.details).html() + '</pre>' : '';
                        $result.html('<p class="fail">✗ Échec : ' + $('<div>').text(msg).html() + '</p>' + details);
                    }
                }).fail(function(xhr){
                    var raw = (xhr && xhr.responseText) || '';
                    $result.html('<p class="fail">✗ Requête AJAX échouée (HTTP ' + (xhr && xhr.status) + ')</p><pre>' + $('<div>').text(raw.substring(0, 2000)).html() + '</pre>');
                }).always(function(){
                    $btn.prop('disabled', false);
                    $spinner.css('visibility', 'hidden').removeClass('is-active');
                });
            });

            $('#ai-assistant-reset-rl-btn').on('click', function(){
                var $btn = $(this);
                var $result = $('#ai-assistant-test-result');
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'ai_assistant_reset_rate_limit',
                    _wpnonce: '<?php echo esc_js( wp_create_nonce( 'ai_assistant_reset_rl' ) ); ?>'
                }, null, 'json').done(function(resp){
                    if (resp && resp.success) {
                        $result.html('<p class="ok">✓ Compteur anti-abus remis à zéro (' + resp.data.cleared + ' entrée(s) effacée(s)).</p>');
                    } else {
                        $result.html('<p class="fail">✗ ' + ((resp && resp.data && resp.data.message) || 'Erreur') + '</p>');
                    }
                }).always(function(){ $btn.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX : teste la connexion OpenAI avec la config actuelle.
     */
    public function ajax_test_openai() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_test_openai' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }

        $api_key = (string) get_option( 'ai_assistant_api_key', '' );
        $model   = (string) get_option( 'ai_assistant_main_model', 'gpt-4.1-nano' );

        if ( $api_key === '' ) {
            wp_send_json_error( array( 'message' => 'Aucune clé API OpenAI configurée.' ) );
        }

        $start = microtime( true );

        $body_payload = array(
            'model'    => $model,
            'messages' => array(
                array( 'role' => 'user', 'content' => 'Dis simplement "OK" pour tester la connexion.' ),
            ),
        );
        if ( preg_match( '/^(gpt-5|gpt-4\.1|o1|o3|o4)/i', $model ) ) {
            $body_payload['max_completion_tokens'] = 20;
        } else {
            $body_payload['max_tokens'] = 20;
        }

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body_payload ),
            'timeout' => 30,
        ) );

        $duration = round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => 'Échec réseau : ' . $response->get_error_message(),
            ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $body = json_decode( (string) $body_raw, true );

        if ( $code !== 200 ) {
            $err_msg = is_array( $body ) && isset( $body['error']['message'] )
                ? $body['error']['message']
                : 'HTTP ' . $code;
            wp_send_json_error( array(
                'message' => 'OpenAI a refusé la requête : ' . $err_msg,
                'details' => substr( (string) $body_raw, 0, 1000 ),
            ) );
        }

        if ( ! is_array( $body ) || empty( $body['choices'][0]['message']['content'] ) ) {
            wp_send_json_error( array(
                'message' => 'Réponse OpenAI vide ou mal formée.',
                'details' => substr( (string) $body_raw, 0, 1000 ),
            ) );
        }

        wp_send_json_success( array(
            'model'    => $model,
            'reply'    => trim( $body['choices'][0]['message']['content'] ),
            'duration' => $duration,
        ) );
    }

    /**
     * AJAX : remet à zéro les transients de rate limit.
     */
    public function ajax_reset_rate_limit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ai_assistant_reset_rl' ) ) {
            wp_send_json_error( array( 'message' => 'Nonce invalide.' ), 403 );
        }

        global $wpdb;
        $cleared = 0;
        $like = $wpdb->esc_like( '_transient_ai_assistant_rl_' ) . '%';
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        foreach ( $rows as $name ) {
            $key = str_replace( '_transient_', '', $name );
            if ( delete_transient( $key ) ) {
                $cleared++;
            }
        }
        wp_send_json_success( array( 'cleared' => $cleared ) );
    }

    public function admin_enqueue_scripts( $hook ) {
        // Ne charge que sur nos pages admin (principale ou sous-menus).
        if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script(
            'wp-color-picker',
            'jQuery(function($){ $(".ai-assistant-color-picker").wpColorPicker(); });'
        );
    }

    // ───────── Sanitizers ─────────

    public static function sanitize_bool( $value ) {
        return ( $value === 'yes' ) ? 'yes' : 'no';
    }

    public static function sanitize_keywords( $input ) {
        if ( empty( $input ) ) {
            return '';
        }
        $lines = array_filter( array_map( 'trim', explode( "\n", $input ) ) );
        $lines = array_unique( array_map( 'sanitize_text_field', $lines ) );
        return implode( "\n", $lines );
    }

    public static function sanitize_themes( $input ) {
        if ( empty( $input ) ) {
            return json_encode( array() );
        }

        // Si c'est déjà du JSON, on le valide
        $decoded = json_decode( $input, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $valid = array();
            foreach ( $decoded as $theme ) {
                if ( is_array( $theme ) && isset( $theme['name'] ) ) {
                    $valid[] = array(
                        'name'        => sanitize_text_field( $theme['name'] ),
                        'description' => sanitize_text_field( $theme['description'] ?? '' ),
                    );
                }
            }
            return json_encode( $valid );
        }

        // Sinon on parse le format texte "Nom : Description"
        $themes = array();
        foreach ( explode( "\n", $input ) as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;

            if ( strpos( $line, ':' ) !== false ) {
                $parts = explode( ':', $line, 2 );
                $themes[] = array(
                    'name'        => sanitize_text_field( trim( $parts[0] ) ),
                    'description' => sanitize_text_field( trim( $parts[1] ) ),
                );
            } else {
                $themes[] = array(
                    'name'        => sanitize_text_field( $line ),
                    'description' => '',
                );
            }
        }
        return json_encode( $themes );
    }
}
