<?php
/**
 * Plugin Name: Real Media Export
 * Description: Fournit une page d'export des fichiers classés avec Real Media Library.
 * Version: 1.1.0
 * Author: OpenAI Assistant
 * Text Domain: real-media-export
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Real_Media_Export_Plugin' ) ) {
    class Real_Media_Export_Plugin {
        const NONCE_ACTION = 'real_media_export_action';
        const DOWNLOAD_NONCE = 'real_media_export_download_';
        const TRANSIENT_PREFIX = 'real_media_export_result_';
        const EXPORT_FOLDER = 'real-media-export';
        const RESULT_TTL = 600; // 10 minutes.
        const OPTION_TAXONOMY_OVERRIDE = 'real_media_export_taxonomy_override';

        /**
         * Cached taxonomy name detected.
         *
         * @var string|null
         */
        protected $folder_taxonomy = null;

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'init', array( $this, 'load_textdomain' ) );
            add_action( 'admin_menu', array( $this, 'register_menu' ) );
            add_action( 'admin_init', array( $this, 'maybe_cleanup_old_archives' ) );
            add_action( 'admin_post_real_media_export', array( $this, 'handle_export_request' ) );
            add_action( 'admin_post_real_media_export_download', array( $this, 'handle_download_request' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'wp_ajax_real_media_export_generate', array( $this, 'handle_ajax_export_request' ) );
        }

        /**
         * Load plugin translations.
         */
        public function load_textdomain() {
            load_plugin_textdomain( 'real-media-export', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Register the admin menu entry under Media.
         */
        public function register_menu() {
            add_media_page(
                __( 'Export Real Media Library', 'real-media-export' ),
                __( 'Export RML', 'real-media-export' ),
                'upload_files',
                'real-media-export',
                array( $this, 'render_admin_page' )
            );
        }

        /**
         * Enqueue admin assets for the export screen.
         *
         * @param string $hook_suffix Current admin page hook suffix.
         */
        public function enqueue_assets( $hook_suffix ) {
            if ( 'media_page_real-media-export' !== $hook_suffix ) {
                return;
            }

            $asset_url = plugin_dir_url( __FILE__ );

            wp_enqueue_style(
                'real-media-export-admin',
                $asset_url . 'assets/css/admin.css',
                array(),
                '1.1.0'
            );

            wp_enqueue_script(
                'real-media-export-admin',
                $asset_url . 'assets/js/admin.js',
                array(),
                '1.1.0',
                true
            );
        }

        /**
         * Try to detect the taxonomy used by Real Media Library to store folders.
         *
         * @return string|null
         */
        public function get_folder_taxonomy() {
            if ( null !== $this->folder_taxonomy ) {
                return $this->folder_taxonomy;
            }

            $detected = null;

            // 1) Use manual override if present and valid.
            $override = $this->get_taxonomy_override();
            if ( $override && taxonomy_exists( $override ) ) {
                $detected = $override;
            }

            // 2) Try known defaults.
            $default_taxonomies = array(
                'real_media_library',
                'rml_folder',
                'real_media_category',
            );

            if ( null === $detected ) {
                foreach ( $default_taxonomies as $taxonomy ) {
                if ( taxonomy_exists( $taxonomy ) ) {
                    $detected = $taxonomy;
                    break;
                }
                }
            }

            if ( null === $detected ) {
                if ( defined( 'RML_TAXONOMY' ) && taxonomy_exists( RML_TAXONOMY ) ) {
                    $detected = RML_TAXONOMY;
                } elseif ( defined( 'RML_FOLDER_TAXONOMY' ) && taxonomy_exists( RML_FOLDER_TAXONOMY ) ) {
                    $detected = RML_FOLDER_TAXONOMY;
                }
            }

            if ( null === $detected ) {
                $detected = $this->detect_rml_taxonomy_from_registered();
            }

            /**
             * Filter the taxonomy used to query Real Media Library folders.
             *
             * @param string|null $detected Taxonomy name or null if not found.
             */
            $detected = apply_filters( 'real_media_export/folder_taxonomy', $detected );

            $this->folder_taxonomy = $detected;

            return $this->folder_taxonomy;
        }

        /**
         * Get the saved taxonomy override if any.
         *
         * @return string|null
         */
        protected function get_taxonomy_override() {
            $value = get_option( self::OPTION_TAXONOMY_OVERRIDE, '' );
            if ( ! is_string( $value ) ) {
                return null;
            }
            $value = sanitize_key( $value );
            return $value !== '' ? $value : null;
        }

        /**
         * Try to infer the Real Media Library taxonomy from the registered ones.
         *
         * @return string|null
         */
        protected function detect_rml_taxonomy_from_registered() {
            $taxonomies = get_taxonomies( array(), 'objects' );
            if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
                return null;
            }

            $attachment_taxonomies = array();
            foreach ( $taxonomies as $taxonomy_name => $taxonomy_object ) {
                if ( empty( $taxonomy_object->object_type ) || ! in_array( 'attachment', (array) $taxonomy_object->object_type, true ) ) {
                    continue;
                }

                $attachment_taxonomies[ $taxonomy_name ] = $taxonomy_object;
            }

            if ( empty( $attachment_taxonomies ) ) {
                return null;
            }

            $scores = array();
            foreach ( $attachment_taxonomies as $taxonomy_name => $taxonomy_object ) {
                $score = 0;
                $slug  = strtolower( $taxonomy_name );

                if ( false !== strpos( $slug, 'rml' ) ) {
                    $score += 25;
                }
                if ( false !== strpos( $slug, 'real_media' ) || false !== strpos( $slug, 'realmedialibrary' ) ) {
                    $score += 25;
                }

                $label_candidates = array();
                if ( isset( $taxonomy_object->label ) && is_string( $taxonomy_object->label ) ) {
                    $label_candidates[] = strtolower( $taxonomy_object->label );
                }
                if ( isset( $taxonomy_object->labels ) ) {
                    foreach ( (array) $taxonomy_object->labels as $label_value ) {
                        if ( is_string( $label_value ) ) {
                            $label_candidates[] = strtolower( $label_value );
                        }
                    }
                }

                foreach ( $label_candidates as $label_candidate ) {
                    if ( false !== strpos( $label_candidate, 'real media' ) || false !== strpos( $label_candidate, 'rml' ) ) {
                        $score += 15;
                        break;
                    }
                }

                if ( ! empty( $taxonomy_object->hierarchical ) ) {
                    $score += 10;
                }

                // Look for RML-specific term meta to lift genuine matches to the top.
                $rml_meta_terms = get_terms(
                    array(
                        'taxonomy'   => $taxonomy_name,
                        'meta_key'   => 'rml_folder_type',
                        'fields'     => 'ids',
                        'hide_empty' => false,
                        'number'     => 1,
                    )
                );
                if ( ! is_wp_error( $rml_meta_terms ) && ! empty( $rml_meta_terms ) ) {
                    $score += 40;
                } else {
                    $rml_meta_terms = get_terms(
                        array(
                            'taxonomy'   => $taxonomy_name,
                            'meta_key'   => 'rml_type',
                            'fields'     => 'ids',
                            'hide_empty' => false,
                            'number'     => 1,
                        )
                    );
                    if ( ! is_wp_error( $rml_meta_terms ) && ! empty( $rml_meta_terms ) ) {
                        $score += 30;
                    }
                }

                if ( $score > 0 ) {
                    $scores[ $taxonomy_name ] = $score;
                }
            }

            if ( empty( $scores ) ) {
                foreach ( $attachment_taxonomies as $taxonomy_name => $taxonomy_object ) {
                    if ( ! empty( $taxonomy_object->hierarchical ) ) {
                        return $taxonomy_name;
                    }
                }

                $fallback_taxonomies = array_keys( $attachment_taxonomies );
                if ( empty( $fallback_taxonomies ) ) {
                    return null;
                }

                return reset( $fallback_taxonomies );
            }

            arsort( $scores );

            return key( $scores );
        }

        /**
         * Persist taxonomy override from request if present.
         */
        protected function maybe_persist_taxonomy_override_from_request() {
            if ( ! isset( $_POST['taxonomy_override'] ) ) {
                return;
            }

            $raw = wp_unslash( $_POST['taxonomy_override'] );
            $slug = is_string( $raw ) ? sanitize_key( $raw ) : '';

            if ( '' === $slug ) {
                delete_option( self::OPTION_TAXONOMY_OVERRIDE );
                return;
            }

            update_option( self::OPTION_TAXONOMY_OVERRIDE, $slug );
        }

        /**
         * Render the admin page form and results.
         */
        public function render_admin_page() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'Vous n’avez pas les permissions nécessaires pour accéder à cette page.', 'real-media-export' ) );
            }

            $taxonomy = $this->get_folder_taxonomy();
            $taxonomy_override = $this->get_taxonomy_override();
            $selected_folder = isset( $_GET['folder'] ) ? absint( $_GET['folder'] ) : (int) get_user_meta( get_current_user_id(), 'real_media_export_last_folder', true );
            $include_children = isset( $_GET['include_children'] ) ? (bool) absint( $_GET['include_children'] ) : true;
            $max_size_mb = isset( $_GET['max_size_mb'] ) ? floatval( $_GET['max_size_mb'] ) : '';
            $archive_prefix = isset( $_GET['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_GET['archive_prefix'] ) ) : '';
            $preserve_structure = isset( $_GET['preserve_structure'] ) ? (bool) absint( $_GET['preserve_structure'] ) : true;

            $result = $this->maybe_get_result();

            $script_settings = $this->prepare_script_settings( $result );
            wp_add_inline_script(
                'real-media-export-admin',
                'window.realMediaExportSettings = ' . wp_json_encode( $script_settings ) . ';',
                'before'
            );

            echo '<div class="wrap real-media-export">';
            echo '<h1>' . esc_html__( 'Export des fichiers Real Media Library', 'real-media-export' ) . '</h1>';

            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__( 'Le plugin Real Media Library ne semble pas être actif. Aucune taxonomie de dossiers n’a été détectée.', 'real-media-export' )
                );
            }

            echo '<div class="real-media-export-panels">';

            echo '<section class="real-media-export-panel real-media-export-panel--form">';
            echo '<form id="real-media-export-form" class="real-media-export-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="action" value="real_media_export" />';

            echo '<table class="form-table" role="presentation">';
            echo '<tbody>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-taxonomy-override">' . esc_html__( 'Taxonomie RML (override)', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            printf(
                '<input type="text" id="real-media-export-taxonomy-override" name="taxonomy_override" value="%s" class="regular-text" placeholder="%s" />',
                esc_attr( $taxonomy_override ? $taxonomy_override : '' ),
                esc_attr__( 'ex. rml_folder', 'real-media-export' )
            );
            $detected_info = $taxonomy && taxonomy_exists( $taxonomy ) ? sprintf( /* translators: %s: taxonomy slug */ esc_html__( 'Taxonomie détectée: %s', 'real-media-export' ), $taxonomy ) : esc_html__( 'Aucune taxonomie détectée pour RML.', 'real-media-export' );
            echo '<p class="description">' . esc_html__( 'Optionnel: si renseigné, ce slug remplace l’auto‑détection. Laissez vide pour revenir à l’auto‑détection.', 'real-media-export' ) . '<br /><em>' . esc_html( $detected_info ) . '</em></p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-folder">' . esc_html__( 'Dossier à exporter', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                echo '<em>' . esc_html__( 'Aucun dossier disponible.', 'real-media-export' ) . '</em>';
            } else {
                echo '<select id="real-media-export-folder" name="folder_id" class="regular-text">';
                echo '<option value="">' . esc_html__( 'Sélectionnez un dossier…', 'real-media-export' ) . '</option>';
                echo $this->get_folder_options_html( $selected_folder );
                echo '</select>';
            }
            echo '<p class="description">' . esc_html__( 'Choisissez le dossier racine à exporter. Les fichiers seront rassemblés selon la structure de Real Media Library.', 'real-media-export' ) . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row">' . esc_html__( 'Inclure les sous-dossiers', 'real-media-export' ) . '</th>';
            echo '<td>';
            printf(
                '<label><input type="checkbox" name="include_children" value="1" %s /> %s</label>',
                checked( $include_children, true, false ),
                esc_html__( 'Inclure les fichiers des sous-dossiers du dossier sélectionné.', 'real-media-export' )
            );
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-max-size">' . esc_html__( 'Taille maximale par archive (Mo)', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            printf(
                '<input type="number" min="0" step="0.1" id="real-media-export-max-size" name="max_size_mb" value="%s" class="small-text" />',
                '' !== $max_size_mb ? esc_attr( $max_size_mb ) : ''
            );
            echo '<p class="description">' . esc_html__( 'Laisser vide ou 0 pour ne pas limiter la taille des fichiers ZIP générés.', 'real-media-export' ) . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row"><label for="real-media-export-prefix">' . esc_html__( 'Préfixe du fichier ZIP', 'real-media-export' ) . '</label></th>';
            echo '<td>';
            printf(
                '<input type="text" id="real-media-export-prefix" name="archive_prefix" value="%s" class="regular-text" />',
                esc_attr( $archive_prefix )
            );
            echo '<p class="description">' . esc_html__( 'Utilisé comme préfixe du nom de fichier ZIP. La date et un index seront ajoutés automatiquement.', 'real-media-export' ) . '</p>';
            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row">' . esc_html__( 'Recréer la structure de dossiers', 'real-media-export' ) . '</th>';
            echo '<td>';
            printf(
                '<label><input type="checkbox" name="preserve_structure" value="1" %s /> %s</label>',
                checked( $preserve_structure, true, false ),
                esc_html__( 'Recréer la hiérarchie des sous-dossiers dans l’archive.', 'real-media-export' )
            );
            echo '</td>';
            echo '</tr>';

            echo '</tbody>';
            echo '</table>';

            submit_button( __( 'Générer l’archive', 'real-media-export' ) );

            echo '</form>';
            echo '</section>';

            echo '<section class="real-media-export-panel real-media-export-panel--activity">';
            $this->render_activity_panel( $result );
            echo '</section>';

            echo '</div>';
            echo '</div>';
        }

        /**
         * Prepare settings passed to the JavaScript controller.
         *
         * @param array $result Result data.
         *
         * @return array
         */
        protected function prepare_script_settings( $result ) {
            $settings = array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'i18n'    => array(
                    'preparing'           => esc_html__( 'Préparation de la génération…', 'real-media-export' ),
                    'processing'          => esc_html__( 'Analyse des fichiers et création des archives…', 'real-media-export' ),
                    'success'             => esc_html__( 'Les archives sont prêtes.', 'real-media-export' ),
                    'warning'             => esc_html__( 'Les archives sont prêtes avec des avertissements.', 'real-media-export' ),
                    'error'               => esc_html__( 'Une erreur est survenue pendant la génération.', 'real-media-export' ),
                    'ready'               => esc_html__( 'Liens de téléchargement disponibles.', 'real-media-export' ),
                    'activityPlaceholder' => esc_html__( 'Le suivi en direct de la génération apparaîtra ici.', 'real-media-export' ),
                    'resultsPlaceholder'  => esc_html__( 'Les liens de téléchargement apparaîtront ici une fois les archives prêtes.', 'real-media-export' ),
                    'downloadLabel'       => esc_html__( 'Télécharger', 'real-media-export' ),
                    'generatedOn'         => esc_html__( 'Généré le %s', 'real-media-export' ),
                    'filesExportedSummary' => esc_html__( '%1$s fichier(s) exporté(s) sur %2$s', 'real-media-export' ),
                    'archivesCountSummary' => esc_html__( '%s archive(s)', 'real-media-export' ),
                    'filesSkippedSummary'  => esc_html__( '%s fichier(s) ignoré(s)', 'real-media-export' ),
                    'primaryFoldersLabel'  => esc_html__( 'Dossiers principaux', 'real-media-export' ),
                    'previewFilesLabel'    => esc_html__( 'Exemples de fichiers', 'real-media-export' ),
                    'maxSizeReachedNote'   => esc_html__( 'Cet archive a été clôturée automatiquement car la taille maximale définie a été atteinte.', 'real-media-export' ),
                    'downloadUnavailable'  => esc_html__( 'Lien de téléchargement indisponible.', 'real-media-export' ),
                    'archiveTitleFallback' => esc_html__( 'Archive ZIP', 'real-media-export' ),
                    'loading'              => esc_html__( 'Création des archives…', 'real-media-export' ),
                    'compressedSizeLabel'  => esc_html__( 'Taille compressée', 'real-media-export' ),
                    'originalsSizeLabel'   => esc_html__( 'Taille cumulée des originaux', 'real-media-export' ),
                    'filesCountLabel'      => esc_html__( 'Nombre de fichiers', 'real-media-export' ),
                ),
            );

            $initial_result = $this->prepare_result_for_js( $result );
            if ( null !== $initial_result ) {
                $settings['initialResult'] = $initial_result;
            }

            return $settings;
        }

        /**
         * Render the activity panel containing the live log and generated archives.
         *
         * @param array $result Result data.
         */
        protected function render_activity_panel( $result ) {
            $status  = isset( $result['status'] ) ? $result['status'] : '';
            $message = isset( $result['message'] ) ? $result['message'] : '';

            $notice_class = 'notice notice-info';
            if ( 'success' === $status ) {
                $notice_class = 'notice notice-success';
            } elseif ( 'warning' === $status ) {
                $notice_class = 'notice notice-warning';
            } elseif ( 'error' === $status ) {
                $notice_class = 'notice notice-error';
            }

            echo '<div class="real-media-export-activity">';
            echo '<h2>' . esc_html__( 'Journal d’activité', 'real-media-export' ) . '</h2>';
            echo '<div id="real-media-export-log" class="real-media-export-log" role="log" aria-live="polite" aria-relevant="additions text">';
            echo '<p class="real-media-export-log__placeholder">' . esc_html__( 'Le suivi en direct de la génération apparaîtra ici.', 'real-media-export' ) . '</p>';
            echo '</div>';

            echo '<div id="real-media-export-message" class="real-media-export-message" data-status="' . esc_attr( $status ) . '">';
            if ( ! empty( $message ) ) {
                echo '<div class="' . esc_attr( $notice_class ) . '"><div class="real-media-export-message__content">' . wp_kses_post( $message ) . '</div></div>';
            }
            echo '</div>';

            $this->render_results_cards( $result );
            echo '</div>';
        }

        /**
         * Render the download cards for generated archives.
         *
         * @param array $result Result data.
         */
        protected function render_results_cards( $result ) {
            $archives = isset( $result['archives'] ) ? (array) $result['archives'] : array();

            echo '<div id="real-media-export-results" class="real-media-export-results" aria-live="polite">';

            if ( ! empty( $result['generated_at_formatted'] ) || ! empty( $result['summary'] ) ) {
                echo '<div class="real-media-export-results__header">';
                if ( ! empty( $result['generated_at_formatted'] ) ) {
                    echo '<span class="real-media-export-results__timestamp">' . esc_html( sprintf( esc_html__( 'Généré le %s', 'real-media-export' ), $result['generated_at_formatted'] ) ) . '</span>';
                }

                if ( ! empty( $result['summary'] ) && is_array( $result['summary'] ) ) {
                    $summary_parts = array();
                    if ( isset( $result['summary']['files_exported'] ) && isset( $result['summary']['files_total'] ) ) {
                        $summary_parts[] = sprintf(
                            /* translators: 1: number of exported files, 2: total files. */
                            esc_html__( '%1$s fichier(s) exporté(s) sur %2$s', 'real-media-export' ),
                            number_format_i18n( (int) $result['summary']['files_exported'] ),
                            number_format_i18n( (int) $result['summary']['files_total'] )
                        );
                    }

                    if ( ! empty( $archives ) ) {
                        $summary_parts[] = sprintf(
                            /* translators: %s: number of archives. */
                            esc_html__( '%s archive(s)', 'real-media-export' ),
                            number_format_i18n( count( $archives ) )
                        );
                    }

                    if ( ! empty( $result['summary']['files_skipped'] ) ) {
                        $summary_parts[] = sprintf(
                            /* translators: %s: number of skipped files. */
                            esc_html__( '%s fichier(s) ignoré(s)', 'real-media-export' ),
                            number_format_i18n( (int) $result['summary']['files_skipped'] )
                        );
                    }

                    if ( ! empty( $summary_parts ) ) {
                        echo '<span class="real-media-export-results__summary">' . esc_html( implode( ' · ', $summary_parts ) ) . '</span>';
                    }
                }
                echo '</div>';
            }

            if ( empty( $archives ) ) {
                echo '<p class="real-media-export-results__placeholder">' . esc_html__( 'Les liens de téléchargement apparaîtront ici une fois les archives prêtes.', 'real-media-export' ) . '</p>';
                echo '</div>';
                return;
            }

            echo '<div class="real-media-export-results__grid">';
            foreach ( $archives as $index => $archive ) {
                $this->render_archive_card( $archive, (int) $index );
            }
            echo '</div>';
            echo '</div>';
        }

        /**
         * Render a single archive card.
         *
         * @param array $archive Archive data.
         * @param int   $index   Index within the list.
         */
        protected function render_archive_card( $archive, $index ) {
            $file_name      = isset( $archive['file'] ) ? $archive['file'] : '';
            $size_human     = isset( $archive['size_human'] ) ? $archive['size_human'] : ( isset( $archive['size'] ) ? size_format( (int) $archive['size'] ) : '' );
            $download_url   = isset( $archive['download_url'] ) ? $archive['download_url'] : $this->get_download_url( $file_name );
            $file_count     = isset( $archive['file_count'] ) ? (int) $archive['file_count'] : 0;
            $created_at     = isset( $archive['created_at_formatted'] ) ? $archive['created_at_formatted'] : '';
            $bytes_total    = isset( $archive['bytes_total_human'] ) ? $archive['bytes_total_human'] : '';
            $folders        = isset( $archive['folders'] ) ? array_values( array_filter( (array) $archive['folders'] ) ) : array();
            $files_preview  = isset( $archive['files_preview'] ) ? array_values( array_filter( (array) $archive['files_preview'] ) ) : array();
            $max_size_reach = ! empty( $archive['max_size_reached'] );

            echo '<article class="real-media-export-card" style="--real-media-export-card-index:' . esc_attr( $index ) . '">';
            echo '<header class="real-media-export-card__header">';
            echo '<h3 class="real-media-export-card__title">' . esc_html( $file_name ) . '</h3>';
            if ( ! empty( $created_at ) ) {
                echo '<p class="real-media-export-card__subtitle">' . esc_html( sprintf( esc_html__( 'Créé le %s', 'real-media-export' ), $created_at ) ) . '</p>';
            }
            echo '</header>';

            echo '<ul class="real-media-export-card__meta">';
            if ( '' !== $size_human ) {
                echo '<li><span class="real-media-export-card__meta-label">' . esc_html__( 'Taille compressée', 'real-media-export' ) . '</span><span class="real-media-export-card__meta-value">' . esc_html( $size_human ) . '</span></li>';
            }
            if ( '' !== $bytes_total ) {
                echo '<li><span class="real-media-export-card__meta-label">' . esc_html__( 'Taille cumulée des originaux', 'real-media-export' ) . '</span><span class="real-media-export-card__meta-value">' . esc_html( $bytes_total ) . '</span></li>';
            }
            if ( $file_count > 0 ) {
                echo '<li><span class="real-media-export-card__meta-label">' . esc_html__( 'Nombre de fichiers', 'real-media-export' ) . '</span><span class="real-media-export-card__meta-value">' . esc_html( number_format_i18n( $file_count ) ) . '</span></li>';
            }
            echo '</ul>';

            if ( ! empty( $folders ) ) {
                echo '<p class="real-media-export-card__detail"><span class="real-media-export-card__detail-label">' . esc_html__( 'Dossiers principaux', 'real-media-export' ) . '</span><span class="real-media-export-card__detail-value">' . esc_html( implode( ', ', $folders ) ) . '</span></p>';
            }

            if ( ! empty( $files_preview ) ) {
                echo '<p class="real-media-export-card__detail"><span class="real-media-export-card__detail-label">' . esc_html__( 'Exemples de fichiers', 'real-media-export' ) . '</span><span class="real-media-export-card__detail-value">' . esc_html( implode( ', ', $files_preview ) ) . '</span></p>';
            }

            if ( $max_size_reach ) {
                echo '<p class="real-media-export-card__note">' . esc_html__( 'Cet archive a été clôturée automatiquement car la taille maximale définie a été atteinte.', 'real-media-export' ) . '</p>';
            }

            if ( $download_url ) {
                echo '<div class="real-media-export-card__actions"><a class="button button-primary" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Télécharger ce ZIP', 'real-media-export' ) . '</a></div>';
            } else {
                echo '<p class="real-media-export-card__note">' . esc_html__( 'Lien de téléchargement indisponible.', 'real-media-export' ) . '</p>';
            }

            echo '</article>';
        }

        /**
         * Build HTML options for the folder selector.
         *
         * @param int $selected Selected folder ID.
         *
         * @return string
         */
        protected function get_folder_options_html( $selected ) {
            $taxonomy = $this->get_folder_taxonomy();
            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                return '';
            }

            $terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'orderby'    => 'name',
                )
            );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                return '';
            }

            $by_parent = array();
            foreach ( $terms as $term ) {
                $parent = (int) $term->parent;
                if ( ! isset( $by_parent[ $parent ] ) ) {
                    $by_parent[ $parent ] = array();
                }
                $by_parent[ $parent ][] = $term;
            }

            $options = $this->render_folder_options_recursive( $by_parent, 0, $selected );

            return $options;
        }

        /**
         * Render folder options recursively.
         *
         * @param array $by_parent Map of parent => array of terms.
         * @param int   $parent    Current parent ID.
         * @param int   $selected  Selected term ID.
         * @param int   $depth     Depth level.
         *
         * @return string
         */
        protected function render_folder_options_recursive( $by_parent, $parent, $selected, $depth = 0 ) {
            if ( empty( $by_parent[ $parent ] ) ) {
                return '';
            }

            usort(
                $by_parent[ $parent ],
                static function( $a, $b ) {
                    return strcasecmp( $a->name, $b->name );
                }
            );

            $html = '';
            foreach ( $by_parent[ $parent ] as $term ) {
                $indent = str_repeat( '&#8212; ', $depth );
                $html  .= sprintf(
                    '<option value="%1$d" %2$s>%3$s%4$s</option>',
                    (int) $term->term_id,
                    selected( $selected, (int) $term->term_id, false ),
                    $indent,
                    esc_html( $term->name )
                );
                $html .= $this->render_folder_options_recursive( $by_parent, (int) $term->term_id, $selected, $depth + 1 );
            }

            return $html;
        }

        /**
         * Handle export form submission.
         */
        public function handle_export_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'Action non autorisée.', 'real-media-export' ) );
            }

            check_admin_referer( self::NONCE_ACTION );

            // Persist taxonomy override if provided.
            $this->maybe_persist_taxonomy_override_from_request();

            $params = array(
                'folder_id'          => isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0,
                'include_children'   => ! empty( $_POST['include_children'] ),
                'max_size_mb'        => isset( $_POST['max_size_mb'] ) ? floatval( $_POST['max_size_mb'] ) : 0,
                'archive_prefix'     => isset( $_POST['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_prefix'] ) ) : '',
                'preserve_structure' => ! empty( $_POST['preserve_structure'] ),
            );

            $result = $this->process_export( $params );

            $this->store_result( $result );
            $this->redirect_after_action();
        }

        /**
         * Handle AJAX export requests.
         */
        public function handle_ajax_export_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error(
                    array(
                        'status'       => 'error',
                        'message_html' => esc_html__( 'Action non autorisée.', 'real-media-export' ),
                        'message_text' => esc_html__( 'Action non autorisée.', 'real-media-export' ),
                        'archives'     => array(),
                    ),
                    403
                );
            }

            check_ajax_referer( self::NONCE_ACTION );

            // Persist taxonomy override if provided.
            $this->maybe_persist_taxonomy_override_from_request();

            $params = array(
                'folder_id'          => isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0,
                'include_children'   => ! empty( $_POST['include_children'] ),
                'max_size_mb'        => isset( $_POST['max_size_mb'] ) ? floatval( $_POST['max_size_mb'] ) : 0,
                'archive_prefix'     => isset( $_POST['archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['archive_prefix'] ) ) : '',
                'preserve_structure' => ! empty( $_POST['preserve_structure'] ),
            );

            $result  = $this->process_export( $params );
            $payload = $this->prepare_result_for_js( $result );

            if ( null === $payload ) {
                $payload = array(
                    'status'       => 'error',
                    'message_html' => esc_html__( 'Une erreur inattendue est survenue.', 'real-media-export' ),
                    'message_text' => esc_html__( 'Une erreur inattendue est survenue.', 'real-media-export' ),
                    'archives'     => array(),
                );
            }

            if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
                wp_send_json_error( $payload );
            }

            wp_send_json_success( $payload );
        }

        /**
         * Process an export request and return the result payload.
         *
         * @param array $params Export parameters.
         *
         * @return array
         */
        protected function process_export( $params ) {
            $defaults = array(
                'folder_id'          => 0,
                'include_children'   => true,
                'max_size_mb'        => 0,
                'archive_prefix'     => '',
                'preserve_structure' => true,
            );

            $params = wp_parse_args( $params, $defaults );

            $result = array(
                'status'   => 'error',
                'message'  => '',
                'archives' => array(),
            );

            $taxonomy = $this->get_folder_taxonomy();
            if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
                $result['message'] = esc_html__( 'Impossible de détecter la taxonomie utilisée par Real Media Library.', 'real-media-export' );

                return $result;
            }

            if ( $params['folder_id'] <= 0 ) {
                $result['message'] = esc_html__( 'Veuillez sélectionner un dossier à exporter.', 'real-media-export' );

                return $result;
            }

            $folder = get_term( $params['folder_id'], $taxonomy );
            if ( ! $folder || is_wp_error( $folder ) ) {
                $result['message'] = esc_html__( 'Le dossier demandé est introuvable.', 'real-media-export' );

                return $result;
            }

            $term_ids = array( (int) $params['folder_id'] );
            if ( $params['include_children'] ) {
                $descendants = get_terms(
                    array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                        'fields'     => 'ids',
                        'child_of'   => (int) $params['folder_id'],
                    )
                );
                if ( ! is_wp_error( $descendants ) && ! empty( $descendants ) ) {
                    $term_ids = array_unique( array_merge( $term_ids, array_map( 'intval', $descendants ) ) );
                }
            }

            $attachments = $this->query_attachments( $term_ids, $taxonomy, (bool) $params['include_children'] );

            if ( empty( $attachments ) ) {
                $result['status']  = 'warning';
                $result['message'] = esc_html__( 'Aucun fichier n’a été trouvé pour les paramètres choisis.', 'real-media-export' );

                return $result;
            }

            $archives = $this->create_archives(
                $attachments,
                array(
                    'folder_id'          => (int) $params['folder_id'],
                    'term_ids'           => $term_ids,
                    'taxonomy'           => $taxonomy,
                    'max_size_mb'        => $params['max_size_mb'],
                    'archive_prefix'     => $params['archive_prefix'],
                    'preserve_structure' => $params['preserve_structure'],
                )
            );

            if ( empty( $archives['archives'] ) ) {
                return $archives;
            }

            $archives['archives']            = $this->prepare_archives_for_client( $archives['archives'] );
            $archives['generated_at']         = current_time( 'timestamp' );
            $archives['generated_at_formatted'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $archives['generated_at'] );
            $archives['summary']              = array(
                'files_total'    => isset( $archives['files_total'] ) ? (int) $archives['files_total'] : count( $attachments ),
                'files_exported' => isset( $archives['files_exported'] ) ? (int) $archives['files_exported'] : count( $attachments ),
                'files_skipped'  => isset( $archives['files_skipped'] ) ? count( (array) $archives['files_skipped'] ) : 0,
                'archives_count' => count( $archives['archives'] ),
            );

            if ( isset( $archives['files_skipped'] ) && is_array( $archives['files_skipped'] ) ) {
                $archives['files_skipped'] = array_map( 'wp_strip_all_tags', $archives['files_skipped'] );
            }

            update_user_meta( get_current_user_id(), 'real_media_export_last_folder', (int) $params['folder_id'] );

            return $archives;
        }

        /**
         * Prepare archive details for display or API usage.
         *
         * @param array $archives Archives list.
         *
         * @return array
         */
        protected function prepare_archives_for_client( $archives ) {
            $prepared = array();

            foreach ( (array) $archives as $archive ) {
                $file_name   = isset( $archive['file'] ) ? wp_strip_all_tags( $archive['file'] ) : '';
                $size        = isset( $archive['size'] ) ? (int) $archive['size'] : 0;
                $bytes_total = isset( $archive['bytes_total'] ) ? (int) $archive['bytes_total'] : 0;
                $created_at  = isset( $archive['created_at'] ) ? (int) $archive['created_at'] : 0;

                if ( ! $created_at && ! empty( $archive['path'] ) && file_exists( $archive['path'] ) ) {
                    $created_at = (int) filemtime( $archive['path'] );
                }

                $folders = array();
                if ( isset( $archive['folders'] ) ) {
                    foreach ( (array) $archive['folders'] as $folder ) {
                        $folder = wp_strip_all_tags( $folder );
                        if ( '' !== $folder ) {
                            $folders[] = $folder;
                        }
                    }
                }
                $folders = array_values( array_unique( $folders ) );

                $files_preview = array();
                if ( isset( $archive['files_preview'] ) ) {
                    foreach ( (array) $archive['files_preview'] as $preview ) {
                        $preview = wp_strip_all_tags( $preview );
                        if ( '' !== $preview ) {
                            $files_preview[] = $preview;
                        }
                    }
                }

                $download_url = $this->get_download_url( $file_name );

                $prepared[] = array(
                    'file'                 => $file_name,
                    'size'                 => $size,
                    'size_human'           => $size > 0 ? size_format( $size ) : '',
                    'bytes_total'          => $bytes_total,
                    'bytes_total_human'    => $bytes_total > 0 ? size_format( $bytes_total ) : '',
                    'created_at'           => $created_at,
                    'created_at_formatted' => $created_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_at ) : '',
                    'file_count'           => isset( $archive['file_count'] ) ? (int) $archive['file_count'] : 0,
                    'files_preview'        => $files_preview,
                    'folders'              => $folders,
                    'max_size_reached'     => ! empty( $archive['max_size_reached'] ),
                    'download_url'         => $download_url ? esc_url_raw( $download_url ) : '',
                );
            }

            return $prepared;
        }

        /**
         * Prepare a result payload to be consumed by JavaScript.
         *
         * @param array $result Result data.
         *
         * @return array|null
         */
        protected function prepare_result_for_js( $result ) {
            if ( empty( $result ) || ! is_array( $result ) ) {
                return null;
            }

            $status  = isset( $result['status'] ) ? $result['status'] : '';
            $message = isset( $result['message'] ) ? $result['message'] : '';

            $archives = isset( $result['archives'] ) ? (array) $result['archives'] : array();
            if ( ! empty( $archives ) && ( ! isset( $archives[0]['size_human'] ) || ! array_key_exists( 'download_url', $archives[0] ) ) ) {
                $archives = $this->prepare_archives_for_client( $archives );
            }

            $archives_data = array();
            foreach ( $archives as $archive ) {
                $archives_data[] = array(
                    'file'                 => isset( $archive['file'] ) ? $archive['file'] : '',
                    'size'                 => isset( $archive['size'] ) ? (int) $archive['size'] : 0,
                    'size_human'           => isset( $archive['size_human'] ) ? $archive['size_human'] : ( isset( $archive['size'] ) ? size_format( (int) $archive['size'] ) : '' ),
                    'bytes_total'          => isset( $archive['bytes_total'] ) ? (int) $archive['bytes_total'] : 0,
                    'bytes_total_human'    => isset( $archive['bytes_total_human'] ) ? $archive['bytes_total_human'] : '',
                    'created_at'           => isset( $archive['created_at'] ) ? (int) $archive['created_at'] : 0,
                    'created_at_formatted' => isset( $archive['created_at_formatted'] ) ? $archive['created_at_formatted'] : '',
                    'file_count'           => isset( $archive['file_count'] ) ? (int) $archive['file_count'] : 0,
                    'files_preview'        => isset( $archive['files_preview'] ) ? array_values( (array) $archive['files_preview'] ) : array(),
                    'folders'              => isset( $archive['folders'] ) ? array_values( (array) $archive['folders'] ) : array(),
                    'max_size_reached'     => ! empty( $archive['max_size_reached'] ),
                    'download_url'         => isset( $archive['download_url'] ) ? $archive['download_url'] : $this->get_download_url( isset( $archive['file'] ) ? $archive['file'] : '' ),
                );
            }

            $summary = array(
                'files_total'    => 0,
                'files_exported' => 0,
                'files_skipped'  => 0,
                'archives_count' => count( $archives_data ),
            );

            if ( isset( $result['summary'] ) && is_array( $result['summary'] ) ) {
                $summary['files_total']    = isset( $result['summary']['files_total'] ) ? (int) $result['summary']['files_total'] : $summary['files_total'];
                $summary['files_exported'] = isset( $result['summary']['files_exported'] ) ? (int) $result['summary']['files_exported'] : $summary['files_exported'];
                $summary['files_skipped']  = isset( $result['summary']['files_skipped'] ) ? (int) $result['summary']['files_skipped'] : $summary['files_skipped'];
                $summary['archives_count'] = isset( $result['summary']['archives_count'] ) ? (int) $result['summary']['archives_count'] : $summary['archives_count'];
            } else {
                $summary['files_total']    = isset( $result['files_total'] ) ? (int) $result['files_total'] : $summary['files_total'];
                $summary['files_exported'] = isset( $result['files_exported'] ) ? (int) $result['files_exported'] : $summary['files_exported'];
                $summary['files_skipped']  = isset( $result['files_skipped'] ) ? count( (array) $result['files_skipped'] ) : $summary['files_skipped'];
            }

            $generated_at         = isset( $result['generated_at'] ) ? (int) $result['generated_at'] : 0;
            $generated_at_display = isset( $result['generated_at_formatted'] ) ? $result['generated_at_formatted'] : ( $generated_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at ) : '' );

            return array(
                'status'                 => $status,
                'message_html'           => $message ? wp_kses_post( $message ) : '',
                'message_text'           => $message ? wp_strip_all_tags( $message ) : '',
                'archives'               => $archives_data,
                'generated_at'           => $generated_at,
                'generated_at_formatted' => $generated_at_display,
                'summary'                => $summary,
                'files_skipped'          => isset( $result['files_skipped'] ) ? array_values( array_map( 'wp_strip_all_tags', (array) $result['files_skipped'] ) ) : array(),
            );
        }

        /**
         * Query attachments assigned to selected terms.
         *
         * @param array  $term_ids          Term IDs to include.
         * @param string $taxonomy          Taxonomy name.
         * @param bool   $include_children  Whether to include child terms.
         *
         * @return int[] List of attachment IDs.
         */
        protected function query_attachments( $term_ids, $taxonomy, $include_children ) {
            $term_ids = array_filter( array_map( 'intval', (array) $term_ids ) );
            if ( empty( $term_ids ) ) {
                return array();
            }

            $query = new WP_Query(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'tax_query'      => array(
                        array(
                            'taxonomy'         => $taxonomy,
                            'field'            => 'term_id',
                            'terms'            => $term_ids,
                            'include_children' => $include_children,
                        ),
                    ),
                )
            );

            if ( empty( $query->posts ) ) {
                return array();
            }

            return array_map( 'intval', $query->posts );
        }

        /**
         * Create the ZIP archives for the provided attachments.
         *
         * @param array $attachment_ids List of attachment IDs.
         * @param array $options        Export options.
         *
         * @return array Result data.
         */
        protected function create_archives( $attachment_ids, $options ) {
            $defaults = array(
                'folder_id'          => 0,
                'term_ids'           => array(),
                'taxonomy'           => '',
                'max_size_mb'        => 0,
                'archive_prefix'     => '',
                'preserve_structure' => true,
            );
            $options  = wp_parse_args( $options, $defaults );

            $max_size_bytes = 0;
            if ( $options['max_size_mb'] > 0 ) {
                $max_size_bytes = (float) $options['max_size_mb'] * 1024 * 1024;
            }

            if ( ! class_exists( 'ZipArchive' ) ) {
                return array(
                    'status'  => 'error',
                    'message' => esc_html__( 'La classe ZipArchive n’est pas disponible sur ce serveur. Veuillez activer l’extension ZIP de PHP.', 'real-media-export' ),
                );
            }

            $export_dir = $this->get_export_directory();
            if ( ! wp_mkdir_p( $export_dir ) ) {
                return array(
                    'status'  => 'error',
                    'message' => esc_html__( 'Impossible de créer le dossier d’export.', 'real-media-export' ),
                );
            }

            $term_map = $this->build_term_map( $options['term_ids'], $options['taxonomy'] );
            if ( empty( $term_map ) || ! isset( $term_map[ $options['folder_id'] ] ) ) {
                return array(
                    'status'  => 'error',
                    'message' => esc_html__( 'Impossible de déterminer la hiérarchie des dossiers sélectionnés.', 'real-media-export' ),
                );
            }

            $attachment_terms = wp_get_object_terms(
                $attachment_ids,
                $options['taxonomy'],
                array(
                    'fields' => 'all_with_object_id',
                )
            );

            $terms_by_object = array();
            if ( ! is_wp_error( $attachment_terms ) ) {
                foreach ( $attachment_terms as $term ) {
                    if ( ! isset( $term_map[ $term->term_id ] ) ) {
                        continue;
                    }
                    $object_id = (int) $term->object_id;
                    if ( ! isset( $terms_by_object[ $object_id ] ) ) {
                        $terms_by_object[ $object_id ] = array();
                    }
                    $terms_by_object[ $object_id ][] = $term;
                }
            }

            $archives       = array();
            $current_zip    = null;
            $current_size   = 0;
            $archive_index  = 0;
            $files_in_zip   = 0;
            $files_total    = 0;
            $files_skipped  = array();
            $current_archive_meta = array();
            $timestamp      = gmdate( 'Ymd-His' );
            $prefix         = $options['archive_prefix'] ? sanitize_title( $options['archive_prefix'] ) : sanitize_title( get_bloginfo( 'name' ) );
            if ( empty( $prefix ) ) {
                $prefix = 'real-media-export';
            }
            $base_filename = $prefix . '-' . $timestamp;

            $close_archive = function() use ( &$current_zip, &$archives, &$archive_index, &$files_in_zip, &$current_size, $export_dir, &$current_archive_meta ) {
                if ( $current_zip instanceof ZipArchive ) {
                    $current_zip->close();

                    if ( $files_in_zip > 0 ) {
                        $full_path = '';
                        if ( ! empty( $current_archive_meta['path'] ) ) {
                            $full_path = $current_archive_meta['path'];
                        } elseif ( ! empty( $current_zip->filename ) ) {
                            $full_path = $current_zip->filename;
                        }

                        if ( $full_path && file_exists( $full_path ) ) {
                            $current_archive_meta['path'] = $full_path;
                            $current_archive_meta['file'] = basename( $full_path );
                            $current_archive_meta['size'] = filesize( $full_path );
                            if ( empty( $current_archive_meta['created_at'] ) ) {
                                $current_archive_meta['created_at'] = filemtime( $full_path );
                            }
                        }

                        if ( empty( $current_archive_meta['folders'] ) ) {
                            $current_archive_meta['folders'] = array();
                        } else {
                            $current_archive_meta['folders'] = array_values( array_unique( array_filter( $current_archive_meta['folders'] ) ) );
                        }

                        if ( empty( $current_archive_meta['files_preview'] ) ) {
                            $current_archive_meta['files_preview'] = array();
                        } else {
                            $current_archive_meta['files_preview'] = array_values( array_filter( $current_archive_meta['files_preview'] ) );
                        }

                        if ( ! isset( $current_archive_meta['size'] ) ) {
                            $current_archive_meta['size'] = ( $full_path && file_exists( $full_path ) ) ? filesize( $full_path ) : 0;
                        }

                        if ( ! isset( $current_archive_meta['bytes_total'] ) ) {
                            $current_archive_meta['bytes_total'] = 0;
                        }

                        if ( ! isset( $current_archive_meta['file_count'] ) ) {
                            $current_archive_meta['file_count'] = 0;
                        }

                        $archives[] = $current_archive_meta;
                    } elseif ( ! empty( $current_zip->filename ) && file_exists( $current_zip->filename ) ) {
                        // Remove empty archive.
                        unlink( $current_zip->filename );
                    }
                }

                $current_zip          = null;
                $files_in_zip         = 0;
                $current_size         = 0;
                $current_archive_meta = array();
                $archive_index++;
            };

            $open_archive = function() use ( &$current_zip, &$archive_index, &$base_filename, $export_dir, &$files_in_zip, &$current_archive_meta ) {
                $filename   = sprintf( '%s-part-%02d.zip', $base_filename, $archive_index + 1 );
                $full_path  = trailingslashit( $export_dir ) . $filename;
                $zip        = new ZipArchive();
                $open_result = $zip->open( $full_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
                if ( true !== $open_result ) {
                    return new WP_Error( 'zip_open_failed', sprintf( esc_html__( 'Impossible de créer le fichier ZIP (%s).', 'real-media-export' ), $filename ) );
                }

                $current_zip  = $zip;
                $files_in_zip = 0;
                $current_archive_meta = array(
                    'file'             => $filename,
                    'path'             => $full_path,
                    'size'             => 0,
                    'created_at'       => current_time( 'timestamp' ),
                    'file_count'       => 0,
                    'files_preview'    => array(),
                    'folders'          => array(),
                    'bytes_total'      => 0,
                    'max_size_reached' => false,
                );

                return $zip;
            };

            foreach ( $attachment_ids as $attachment_id ) {
                $attachment_id = (int) $attachment_id;
                $file_path     = get_attached_file( $attachment_id );
                $files_total++;

                if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
                    $files_skipped[] = sprintf( esc_html__( 'Le fichier original de la pièce jointe #%d est introuvable.', 'real-media-export' ), $attachment_id );
                    continue;
                }

                $file_size = filesize( $file_path );

                if ( $max_size_bytes > 0 && $file_size > $max_size_bytes ) {
                    $files_skipped[] = sprintf( esc_html__( 'Le fichier %1$s (%2$s) dépasse la taille maximale autorisée.', 'real-media-export' ), basename( $file_path ), size_format( $file_size ) );
                    continue;
                }

                if ( ! ( $current_zip instanceof ZipArchive ) ) {
                    $opened = $open_archive();
                    if ( is_wp_error( $opened ) ) {
                        return array(
                            'status'  => 'error',
                            'message' => $opened->get_error_message(),
                        );
                    }
                }

                if ( $max_size_bytes > 0 && ( $current_size + $file_size ) > $max_size_bytes && $files_in_zip > 0 ) {
                    if ( ! empty( $current_archive_meta ) ) {
                        $current_archive_meta['max_size_reached'] = true;
                    }
                    $close_archive();
                    $opened = $open_archive();
                    if ( is_wp_error( $opened ) ) {
                        return array(
                            'status'  => 'error',
                            'message' => $opened->get_error_message(),
                        );
                    }
                }

                $zip_path = basename( $file_path );
                if ( $options['preserve_structure'] ) {
                    $zip_path = $this->build_zip_path( $attachment_id, $terms_by_object, $term_map, $options['folder_id'], $zip_path, $options['taxonomy'] );
                }

                $add_result = $current_zip->addFile( $file_path, $zip_path );
                if ( ! $add_result ) {
                    $files_skipped[] = sprintf( esc_html__( 'Impossible d’ajouter %s à l’archive.', 'real-media-export' ), basename( $file_path ) );
                    continue;
                }

                if ( ! empty( $current_archive_meta ) ) {
                    $current_archive_meta['file_count'] = isset( $current_archive_meta['file_count'] ) ? (int) $current_archive_meta['file_count'] + 1 : 1;
                    $current_archive_meta['bytes_total'] = isset( $current_archive_meta['bytes_total'] ) ? (int) $current_archive_meta['bytes_total'] + (int) $file_size : (int) $file_size;
                    if ( count( $current_archive_meta['files_preview'] ) < 3 ) {
                        $current_archive_meta['files_preview'][] = basename( $file_path );
                    }
                    if ( false !== strpos( $zip_path, '/' ) ) {
                        $segments   = explode( '/', $zip_path );
                        $top_folder = reset( $segments );
                        if ( '' !== $top_folder ) {
                            $current_archive_meta['folders'][] = $top_folder;
                        }
                    }
                }

                $current_size += $file_size;
                $files_in_zip++;
            }

            // Close last archive.
            if ( $current_zip instanceof ZipArchive ) {
                $close_archive();
            }

            if ( empty( $archives ) ) {
                return array(
                    'status'  => 'warning',
                    'message' => esc_html__( 'Aucune archive n’a été générée.', 'real-media-export' ),
                );
            }

            $status  = 'success';
            $message = sprintf(
                /* translators: %1$d: number of archives, %2$s: number of files. */
                esc_html__( '%1$d archive(s) générée(s) contenant %2$s fichier(s).', 'real-media-export' ),
                count( $archives ),
                number_format_i18n( $files_total - count( $files_skipped ) )
            );

            if ( ! empty( $files_skipped ) ) {
                $message .= '<br />' . esc_html__( 'Certains fichiers ont été ignorés :', 'real-media-export' ) . '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $files_skipped ) ) . '</li></ul>';
                $status   = 'warning';
            }

            return array(
                'status'         => $status,
                'message'        => $message,
                'archives'       => $archives,
                'files_total'    => $files_total,
                'files_exported' => $files_total - count( $files_skipped ),
                'files_skipped'  => $files_skipped,
            );
        }

        /**
         * Build a mapping of term data for quick access.
         *
         * @param array  $term_ids Term IDs.
         * @param string $taxonomy Taxonomy name.
         *
         * @return array
         */
        protected function build_term_map( $term_ids, $taxonomy ) {
            $term_ids = array_filter( array_map( 'intval', (array) $term_ids ) );
            if ( empty( $term_ids ) ) {
                return array();
            }

            $terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'include'    => $term_ids,
                    'hide_empty' => false,
                )
            );

            $map = array();
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $map[ $term->term_id ] = $term;
                }
            }

            // Ensure the selected folder is present even if not returned by include.
            foreach ( $term_ids as $term_id ) {
                if ( ! isset( $map[ $term_id ] ) ) {
                    $term = get_term( $term_id, $taxonomy );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $map[ $term_id ] = $term;
                    }
                }
            }

            return $map;
        }

        /**
         * Build the path inside the ZIP archive for an attachment.
         *
         * @param int    $attachment_id   Attachment ID.
         * @param array  $terms_by_object Map of object ID => array of WP_Term.
         * @param array  $term_map        Map of term ID => WP_Term.
         * @param int    $root_folder_id  Selected root folder ID.
         * @param string $filename        Default filename.
         * @param string $taxonomy        Taxonomy name.
         *
         * @return string
         */
        protected function build_zip_path( $attachment_id, $terms_by_object, $term_map, $root_folder_id, $filename, $taxonomy ) {
            if ( empty( $terms_by_object[ $attachment_id ] ) ) {
                return $filename;
            }

            $chosen_term = null;
            $chosen_depth = -1;

            foreach ( $terms_by_object[ $attachment_id ] as $term ) {
                if ( ! isset( $term_map[ $term->term_id ] ) ) {
                    continue;
                }

                $depth = $this->calculate_term_depth( $term->term_id, $term_map, $root_folder_id, $taxonomy );
                if ( $depth < 0 ) {
                    continue;
                }

                if ( $depth >= $chosen_depth ) {
                    $chosen_depth = $depth;
                    $chosen_term  = $term_map[ $term->term_id ];
                }
            }

            if ( ! $chosen_term ) {
                return $filename;
            }

            $segments = array();
            $current  = $chosen_term;
            while ( $current && (int) $current->term_id !== (int) $root_folder_id ) {
                $segments[] = $this->sanitize_zip_segment( $current->name );
                if ( empty( $current->parent ) || ! isset( $term_map[ $current->parent ] ) ) {
                    $parent = get_term( $current->parent, $taxonomy );
                    if ( ! $parent || is_wp_error( $parent ) ) {
                        break;
                    }
                    $term_map[ $parent->term_id ] = $parent;
                }

                if ( (int) $current->parent === (int) $root_folder_id ) {
                    break;
                }

                $current = $term_map[ $current->parent ];
            }

            $segments = array_reverse( $segments );
            if ( ! empty( $segments ) ) {
                $path = implode( '/', $segments ) . '/' . $filename;
            } else {
                $path = $filename;
            }

            return $path;
        }

        /**
         * Calculate the depth of a term relative to the selected folder.
         *
         * @param int    $term_id        Term ID.
         * @param array  $term_map       Term map.
         * @param int    $root_folder_id Root folder ID.
         * @param string $taxonomy       Taxonomy name.
         *
         * @return int Depth or -1 if the term is not a descendant.
         */
        protected function calculate_term_depth( $term_id, $term_map, $root_folder_id, $taxonomy ) {
            if ( ! isset( $term_map[ $term_id ] ) ) {
                return -1;
            }

            if ( (int) $term_id === (int) $root_folder_id ) {
                return 0;
            }

            $depth       = 0;
            $current_id  = $term_id;
            $safety      = 0;
            $max_loops   = 200;

            while ( $current_id && $safety < $max_loops ) {
                $safety++;
                $term = isset( $term_map[ $current_id ] ) ? $term_map[ $current_id ] : get_term( $current_id, $taxonomy );
                if ( ! $term || is_wp_error( $term ) ) {
                    return -1;
                }

                if ( empty( $term->parent ) ) {
                    return (int) $term->term_id === (int) $root_folder_id ? $depth : -1;
                }

                $current_id = (int) $term->parent;
                $depth++;

                if ( (int) $current_id === (int) $root_folder_id ) {
                    return $depth;
                }
            }

            return -1;
        }

        /**
         * Sanitize folder names inside the ZIP archive.
         *
         * @param string $name Folder name.
         *
         * @return string
         */
        protected function sanitize_zip_segment( $name ) {
            $name = wp_strip_all_tags( $name );
            $name = sanitize_text_field( $name );
            $name = str_replace( array( '/', '\\' ), '-', $name );
            $name = trim( $name );
            if ( '' === $name ) {
                $name = 'dossier';
            }

            return $name;
        }

        /**
         * Save the result for display after redirect.
         *
         * @param array $result Result data.
         */
        protected function store_result( $result ) {
            set_transient( $this->get_result_key(), $result, self::RESULT_TTL );
        }

        /**
         * Fetch result data if present.
         *
         * @return array
         */
        protected function maybe_get_result() {
            $result = get_transient( $this->get_result_key() );
            if ( ! $result ) {
                return array();
            }

            delete_transient( $this->get_result_key() );

            return $result;
        }

        /**
         * Build the transient key for the current user.
         *
         * @return string
         */
        protected function get_result_key() {
            return self::TRANSIENT_PREFIX . get_current_user_id();
        }

        /**
         * Redirect back to the plugin page after processing.
         */
        protected function redirect_after_action() {
            $url = add_query_arg(
                array(
                    'page' => 'real-media-export',
                ),
                admin_url( 'upload.php' )
            );
            wp_safe_redirect( $url );
            exit;
        }

        /**
         * Generate a download URL secured by nonce.
         *
         * @param string $file_name File name.
         *
         * @return string|false
         */
        protected function get_download_url( $file_name ) {
            if ( empty( $file_name ) ) {
                return false;
            }

            $nonce = wp_create_nonce( self::DOWNLOAD_NONCE . $file_name );

            return add_query_arg(
                array(
                    'action' => 'real_media_export_download',
                    'file'   => rawurlencode( $file_name ),
                    '_wpnonce' => $nonce,
                ),
                admin_url( 'admin-post.php' )
            );
        }

        /**
         * Handle archive download.
         */
        public function handle_download_request() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'Action non autorisée.', 'real-media-export' ) );
            }

            $file_name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
            $nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

            if ( empty( $file_name ) || ! wp_verify_nonce( $nonce, self::DOWNLOAD_NONCE . $file_name ) ) {
                wp_die( esc_html__( 'Lien de téléchargement invalide.', 'real-media-export' ) );
            }

            $export_dir = $this->get_export_directory();
            $full_path  = $export_dir . '/' . $file_name;
            $real_path  = realpath( $full_path );
            $real_dir   = realpath( $export_dir );

            if ( ! $real_path || false === $real_dir || strpos( $real_path, $real_dir ) !== 0 || ! file_exists( $real_path ) ) {
                wp_die( esc_html__( 'Le fichier demandé est introuvable.', 'real-media-export' ) );
            }

            nocache_headers();
            header( 'Content-Type: application/zip' );
            header( 'Content-Disposition: attachment; filename="' . basename( $real_path ) . '"' );
            header( 'Content-Length: ' . filesize( $real_path ) );

            readfile( $real_path );
            exit;
        }

        /**
         * Get the export directory path.
         *
         * @return string
         */
        protected function get_export_directory() {
            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . self::EXPORT_FOLDER;

            return $dir;
        }

        /**
         * Remove old archives from the export directory.
         */
        public function maybe_cleanup_old_archives() {
            $export_dir = $this->get_export_directory();
            if ( ! is_dir( $export_dir ) ) {
                return;
            }

            $files = glob( trailingslashit( $export_dir ) . '*.zip' );
            if ( empty( $files ) ) {
                return;
            }

            $lifetime = apply_filters( 'real_media_export/archive_lifetime', DAY_IN_SECONDS );
            $now      = time();

            foreach ( (array) $files as $file ) {
                if ( ! is_file( $file ) ) {
                    continue;
                }

                if ( ( $now - filemtime( $file ) ) > $lifetime ) {
                    unlink( $file );
                }
            }
        }
    }
}

// Bootstrap the plugin.
function real_media_export_plugin() {
    static $instance = null;

    if ( null === $instance ) {
        $instance = new Real_Media_Export_Plugin();
    }

    return $instance;
}

real_media_export_plugin();
