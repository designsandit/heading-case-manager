<?php
/**
 * Plugin Name: Heading Case Manager
 * Plugin URI: https://leonardobaez.com/herramientas-gratuitas-marketing-digital/
 * Description: Manages heading case in WordPress content while preserving specified words and acronyms
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Leonardo Báez - Consultor SEO Senior
 * Author URI: https://leonardobaez.com/
 * License: Free
 * License URI: 
 */

if (!defined('ABSPATH')) {
    exit;
}

class HeadingCaseManager {
    private static $instance = null;
    private $option_name = 'heading_case_manager_settings';
    private $changes_log = 'heading_case_manager_log';
    private $backup_option = 'heading_case_manager_backups';

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'addMenuPage'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_action('wp_ajax_preview_changes', array($this, 'previewChanges'));
        add_action('wp_ajax_process_content', array($this, 'processContent'));
        add_action('wp_ajax_revert_changes', array($this, 'revertChanges'));
        add_action('wp_ajax_get_posts_list', array($this, 'getPostsList'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueStyles'));
    }

    public function enqueueStyles($hook) {
        if ('settings_page_heading-case-manager' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'heading-case-manager-styles', 
            plugins_url('css/style.css', __FILE__),
            array(),
            '1.0.0'
        );
        
        wp_enqueue_style(
            'montserrat-font',
            'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&display=swap',
            array(),
            null
        );
    }

    public function addMenuPage() {
        add_options_page(
            'Heading Case Manager',
            'Heading Case',
            'manage_options',
            'heading-case-manager',
            array($this, 'renderSettingsPage')
        );
    }

    public function registerSettings() {
        register_setting('heading_case_manager_options', $this->option_name);
        
        add_settings_section(
            'heading_case_manager_main',
            'Configuración General',
            array($this, 'sectionCallback'),
            'heading-case-manager'
        );

        add_settings_field(
            'excluded_words',
            'Palabras Excluidas',
            array($this, 'excludedWordsCallback'),
            'heading-case-manager',
            'heading_case_manager_main'
        );

        add_settings_field(
            'acronyms',
            'Acrónimos',
            array($this, 'acronymsCallback'),
            'heading-case-manager',
            'heading_case_manager_main'
        );

        add_settings_field(
            'post_types',
            'Tipos de Contenido',
            array($this, 'postTypesCallback'),
            'heading-case-manager',
            'heading_case_manager_main'
        );
    }

    public function sectionCallback() {
        echo '<p class="heading-case-help-text">Configure las opciones para el manejo de mayúsculas en encabezados.</p>';
    }

    public function excludedWordsCallback() {
        $options = get_option($this->option_name);
        $excluded_words = isset($options['excluded_words']) ? $options['excluded_words'] : '';
        ?>
        <div class="heading-case-form-group">
            <textarea name="<?php echo $this->option_name; ?>[excluded_words]" 
                      class="heading-case-textarea"
                      rows="4"><?php echo esc_textarea($excluded_words); ?></textarea>
            <span class="heading-case-help-text">Ingrese las palabras que no deben convertirse a minúsculas, separadas por comas.</span>
        </div>
        <?php
    }

    public function acronymsCallback() {
        $options = get_option($this->option_name);
        $acronyms = isset($options['acronyms']) ? $options['acronyms'] : '';
        ?>
        <div class="heading-case-form-group">
            <textarea name="<?php echo $this->option_name; ?>[acronyms]" 
                      class="heading-case-textarea"
                      rows="4"><?php echo esc_textarea($acronyms); ?></textarea>
            <span class="heading-case-help-text">Ingrese los acrónimos que deben mantenerse en mayúsculas, separados por comas (ejemplo: NASA, ONU).</span>
        </div>
        <?php
    }

    public function postTypesCallback() {
        $options = get_option($this->option_name);
        $post_types = isset($options['post_types']) ? $options['post_types'] : array();
        $available_types = array('post' => 'Posts', 'page' => 'Páginas', 'product' => 'Productos');

        echo '<div class="heading-case-form-group">';
        foreach ($available_types as $type => $label) {
            ?>
            <label class="heading-case-checkbox-label">
                <input type="checkbox" 
                       name="<?php echo $this->option_name; ?>[post_types][]" 
                       value="<?php echo $type; ?>"
                       <?php checked(in_array($type, $post_types)); ?>>
                <?php echo $label; ?>
            </label><br>
            <?php
        }
        echo '</div>';
    }
	private function processBlock($block) {
        if (!is_array($block)) {
            return $block;
        }

        if ($block['blockName'] === 'core/heading') {
            $level = isset($block['attrs']['level']) ? $block['attrs']['level'] : 2;
            if ($level >= 2 && $level <= 4) {
                $content = $block['innerHTML'];
                $options = get_option($this->option_name);
                $excluded_words = array_map('trim', explode(',', $options['excluded_words'] ?? ''));
                $acronyms = array_map('trim', explode(',', $options['acronyms'] ?? ''));

                preg_match('/<h' . $level . '([^>]*)>(.*?)<\/h' . $level . '>/i', $content, $matches);
                if (!empty($matches)) {
                    $attributes = $matches[1];
                    $text = $matches[2];
                    $words = explode(' ', strip_tags($text));

                    foreach ($words as $key => $word) {
                        if (in_array($word, $excluded_words) || in_array($word, $acronyms)) {
                            continue;
                        }
                        
                        if ($key === 0) {
                            $words[$key] = ucfirst(mb_strtolower($word));
                        } else {
                            $words[$key] = mb_strtolower($word);
                        }
                    }

                    $block['innerHTML'] = '<h' . $level . $attributes . '>' . implode(' ', $words) . '</h' . $level . '>';
                    $block['innerContent'][0] = $block['innerHTML'];
                }
            }
        }

        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $i => $inner_block) {
                $block['innerBlocks'][$i] = $this->processBlock($inner_block);
            }
        }

        return $block;
    }

    public function getPostsList() {
        check_ajax_referer('heading_case_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permiso denegado');
        }

        $post_type = $_POST['post_type'] ?? 'post';
        $search = $_POST['search'] ?? '';

        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => array('publish', 'draft'),
            'orderby' => 'title',
            'order' => 'ASC'
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $posts = get_posts($args);
        $posts_list = array();

        foreach ($posts as $post) {
            $posts_list[] = array(
                'ID' => $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'type' => $post->post_type
            );
        }

        wp_send_json_success($posts_list);
    }

    public function processContent() {
        check_ajax_referer('heading_case_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permiso denegado');
        }

        $process_mode = $_POST['process_mode'] ?? 'all';
        $selected_posts = isset($_POST['selected_posts']) ? array_map('intval', $_POST['selected_posts']) : array();
        
        $processed_count = 0;
        $log = array();
        $backups = array();

        if ($process_mode === 'selected' && empty($selected_posts)) {
            wp_send_json_error('No se han seleccionado posts para procesar');
        }

        $query_args = array(
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => -1
        );

        if ($process_mode === 'all') {
            $options = get_option($this->option_name);
            $query_args['post_type'] = $options['post_types'] ?? array('post');
        } else {
            $query_args['post__in'] = $selected_posts;
            $query_args['post_type'] = 'any';
        }

        $query = new WP_Query($query_args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post = get_post($post_id);
                
                // Guardar backup
                $backups[$post_id] = array(
                    'content' => $post->post_content,
                    'modified' => current_time('mysql')
                );

                $blocks = parse_blocks($post->post_content);
                $modified = false;

                foreach ($blocks as $i => $block) {
                    $processed_block = $this->processBlock($block);
                    if ($processed_block !== $block) {
                        $blocks[$i] = $processed_block;
                        $modified = true;
                    }
                }

                if ($modified) {
                    $new_content = serialize_blocks($blocks);
                    $update_post = array(
                        'ID' => $post_id,
                        'post_content' => $new_content
                    );

                    wp_update_post($update_post);
                    $processed_count++;
                    $log[] = array(
                        'post_id' => $post_id,
                        'title' => get_the_title(),
                        'status' => 'success',
                        'type' => get_post_type_object(get_post_type())->labels->singular_name
                    );
                }
            }
        }
        wp_reset_postdata();

        // Guardar backups
        update_option($this->backup_option, $backups);

        // Guardar log
        $current_log = get_option($this->changes_log, array());
        $current_log[] = array(
            'date' => current_time('mysql'),
            'processed' => $processed_count,
            'details' => $log,
            'mode' => $process_mode
        );
        update_option($this->changes_log, $current_log);

        $response = array(
            'message' => sprintf('Procesamiento completado. %d elementos actualizados.', $processed_count),
            'processed' => $processed_count,
            'log' => $log
        );

        wp_send_json_success($response);
    }

    public function revertChanges() {
        check_ajax_referer('heading_case_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permiso denegado');
        }

        $backups = get_option($this->backup_option);
        if (empty($backups)) {
            wp_send_json_error('No hay backups disponibles para restaurar');
        }

        $restored_count = 0;
        $log = array();

        foreach ($backups as $post_id => $backup) {
            $update_post = array(
                'ID' => $post_id,
                'post_content' => $backup['content']
            );

            wp_update_post($update_post);
            $restored_count++;
            $log[] = array(
                'post_id' => $post_id,
                'title' => get_the_title($post_id),
                'status' => 'restored'
            );
        }

        delete_option($this->backup_option);

        $response = array(
            'message' => sprintf('Restauración completada. %d elementos restaurados.', $restored_count),
            'restored' => $restored_count,
            'log' => $log
        );

        wp_send_json_success($response);
    }

    public function renderSettingsPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <div class="heading-case-container">
                <h1 class="heading-case-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
                
                <form action="options.php" method="post" class="heading-case-form">
                    <?php
                    settings_fields('heading_case_manager_options');
                    do_settings_sections('heading-case-manager');
                    submit_button('Guardar Cambios', 'heading-case-button');
                    ?>
                </form>

                <div class="heading-case-section">
                    <h2 class="heading-case-subtitle">Procesar Contenido</h2>

                    <div class="heading-case-process-mode">
                        <label>
                            <input type="radio" name="process_mode" value="all" checked> 
                            Procesar todos los contenidos seleccionados
                        </label>
                        <label>
                            <input type="radio" name="process_mode" value="selected">
                            Seleccionar contenidos específicos
                        </label>
                    </div>

                    <div class="heading-case-post-selector" id="post-selector">
                        <div class="heading-case-post-type-filter">
                            <select id="post-type-filter" class="heading-case-select">
                                <option value="post">Posts</option>
                                <option value="page">Páginas</option>
                                <option value="product">Productos</option>
                            </select>
                        </div>

                        <input type="text" 
                               id="post-search" 
                               class="heading-case-search" 
                               placeholder="Buscar contenidos...">

                        <div class="heading-case-selection-controls">
                            <button type="button" id="select-all" class="heading-case-button heading-case-button-secondary">
                                Seleccionar Todo
                            </button>
                            <button type="button" id="deselect-all" class="heading-case-button heading-case-button-secondary">
                                Deseleccionar Todo
                            </button>
                        </div>

                        <div id="posts-list" class="heading-case-post-list"></div>
                    </div>

                    <div class="heading-case-buttons">
                        <button type="button" id="preview-changes" class="heading-case-button heading-case-button-secondary">
                            Vista Previa
                        </button>
                        <button type="button" id="process-content" class="heading-case-button">
                            Procesar Contenido
                        </button>
                        <?php if (get_option($this->backup_option)) : ?>
                            <button type="button" id="revert-changes" class="heading-case-button heading-case-button-warning">
                                Revertir Cambios
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="heading-case-stats" style="display:none;">
                        <h3>Estadísticas del Proceso</h3>
                        <div id="stats-content"></div>
                    </div>

                    <div id="preview-area" class="heading-case-preview-area" style="display:none;">
                        <h3>Vista Previa de Cambios</h3>
                        <div id="preview-content"></div>
                    </div>

                    <div id="processing-log" class="heading-case-log" style="display:none;">
                        <h3>Log de Procesamiento</h3>
                        <div id="log-content"></div>
                    </div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function showMessage(message, type) {
                const messageDiv = $('<div></div>')
                    .addClass('heading-case-message')
                    .addClass(type)
                    .text(message)
                    .appendTo('body')
                    .css('opacity', '1');

                setTimeout(function() {
                    messageDiv.css('opacity', '0');
                    setTimeout(function() {
                        messageDiv.remove();
                    }, 500);
                }, 3000);
            }

            function loadPosts(postType, search = '') {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_posts_list',
                        nonce: '<?php echo wp_create_nonce("heading_case_manager_nonce"); ?>',
                        post_type: postType,
                        search: search
                    },
                    success: function(response) {
                        const postsList = $('#posts-list');
                        postsList.empty();

                        if (response.success && response.data.length > 0) {
                            response.data.forEach(function(post) {
                                const status = post.status === 'draft' ? ' (Borrador)' : '';
                                postsList.append(`
                                    <div class="heading-case-post-item">
                                        <input type="checkbox" 
                                               name="selected_posts[]" 
                                               value="${post.ID}" 
                                               id="post-${post.ID}">
                                        <label for="post-${post.ID}">
                                            ${post.title}${status}
                                        </label>
                                    </div>
                                `);
                            });
                        } else {
                            postsList.html('<p>No se encontraron contenidos.</p>');
                        }
                    }
                });
            }

            // Manejar cambio de modo de proceso
            $('input[name="process_mode"]').change(function() {
                if (this.value === 'selected') {
                    $('#post-selector').show();
                    loadPosts($('#post-type-filter').val());
                } else {
                    $('#post-selector').hide();
                }
            });

            // Manejar cambio de tipo de post
            $('#post-type-filter').change(function() {
                loadPosts($(this).val(), $('#post-search').val());
            });

            // Manejar búsqueda
            let searchTimeout;
            $('#post-search').on('input', function() {
                clearTimeout(searchTimeout);
                const search = $(this).val();
                const postType = $('#post-type-filter').val();
                
                searchTimeout = setTimeout(function() {
                    loadPosts(postType, search);
                }, 300);
            });

            // Seleccionar/Deseleccionar todo
            $('#select-all').click(function() {
                $('#posts-list input[type="checkbox"]').prop('checked', true);
            });

            $('#deselect-all').click(function() {
                $('#posts-list input[type="checkbox"]').prop('checked', false);
            });

            $('#process-content').click(function() {
                const processMode = $('input[name="process_mode"]:checked').val();
                let selectedPosts = [];
                
                if (processMode === 'selected') {
                    selectedPosts = $('#posts-list input[type="checkbox"]:checked').map(function() {
                        return $(this).val();
                    }).get();

                    if (selectedPosts.length === 0) {
                        showMessage('Por favor, seleccione al menos un contenido para procesar.', 'error');
                        return;
						}
                }

                if (!confirm('¿Está seguro de que desea procesar ' + 
                    (processMode === 'all' ? 'todo el contenido' : 'los contenidos seleccionados') + 
                    '? Se creará un backup automático.')) {
                    return;
                }

                const button = $(this);
                button.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'process_content',
                        nonce: '<?php echo wp_create_nonce("heading_case_manager_nonce"); ?>',
                        process_mode: processMode,
                        selected_posts: selectedPosts
                    },
                    success: function(response) {
                        $('.heading-case-stats').show();
                        $('#stats-content').html(`
                            <p>Posts procesados: ${response.data.processed}</p>
                            <p>Fecha: ${new Date().toLocaleString()}</p>
                            <p>Modo: ${processMode === 'all' ? 'Todos los contenidos' : 'Contenidos seleccionados'}</p>
                        `);
                        $('#processing-log').show();
                        
                        let logHtml = '<ul>';
                        response.data.log.forEach(function(item) {
                            logHtml += `<li>${item.title} (${item.type}) - ${item.status}</li>`;
                        });
                        logHtml += '</ul>';
                        
                        $('#log-content').html(logHtml);
                        showMessage(response.data.message, 'success');
                        location.reload();
                    },
                    error: function(xhr) {
                        let message = 'Error al procesar el contenido';
                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            message = xhr.responseJSON.data;
                        }
                        showMessage(message, 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            $('#revert-changes').click(function() {
                if (!confirm('¿Está seguro de que desea revertir todos los cambios? Esta acción no se puede deshacer.')) {
                    return;
                }

                const button = $(this);
                button.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'revert_changes',
                        nonce: '<?php echo wp_create_nonce("heading_case_manager_nonce"); ?>'
                    },
                    success: function(response) {
                        showMessage(response.data.message, 'success');
                        location.reload();
                    },
                    error: function() {
                        showMessage('Error al revertir los cambios', 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            $('#preview-changes').click(function() {
                const button = $(this);
                button.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'preview_changes',
                        nonce: '<?php echo wp_create_nonce("heading_case_manager_nonce"); ?>'
                    },
                    success: function(response) {
                        $('#preview-area').show();
                        $('#preview-content').html(response.data);
                        showMessage('Vista previa generada con éxito', 'success');
                    },
                    error: function() {
                        showMessage('Error al generar la vista previa', 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function extractHeadings($content) {
        $headings = array();
        $blocks = parse_blocks($content);
        
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/heading') {
                $level = isset($block['attrs']['level']) ? $block['attrs']['level'] : 2;
                if ($level >= 2 && $level <= 4) {
                    preg_match('/<h' . $level . '[^>]*)>(.*?)<\/h' . $level . '>/i', $block['innerHTML'], $matches);
                    if (!empty($matches[1])) {
                        $headings[] = $matches[1];
                    }
                }
            }

            if (!empty($block['innerBlocks'])) {
                $headings = array_merge($headings, $this->extractHeadings(serialize_blocks($block['innerBlocks'])));
            }
        }

        return implode('<br>', $headings);
    }
}

// Initialize plugin
add_action('plugins_loaded', array('HeadingCaseManager', 'getInstance'));
?>