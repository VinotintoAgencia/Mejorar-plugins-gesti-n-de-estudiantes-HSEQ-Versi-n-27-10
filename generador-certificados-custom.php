<?php
/**
 * Plugin Name: Generador de Certificados Personalizado
 * Description: Permite generar certificados buscando contactos en FluentCRM y utilizando su API REST, y que los alumnos descarguen sus certificados.
 * Version: 1.5.0
 * Author: <a href="https://www.vinotintoagencia.com">Vinotinto Agencia</a>
 * Text Domain: gcp-generador-cert
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevenir acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Importar la clase Subscriber de FluentCRM
use FluentCrm\App\Models\Subscriber;

// Incluir el autoloader de Composer para mPDF y otras dependencias
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log(
            'GCP Plugin CRITICAL: vendor/autoload.php no encontrado. ' .
            'mPDF no se cargará. Ejecuta "composer install".'
        );
    }
    // Mostrar aviso en el admin si falta mPDF
    add_action( 'admin_notices', function() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong>Generador de Certificados:</strong>
                La librería <code>mPDF</code> no está instalada.
                Por favor, ejecuta <code>composer install</code> en la carpeta del plugin
                o contacta al administrador del sitio.
            </p>
        </div>
        <?php
    } );
    // Detenemos la carga del resto del plugin que depende de mPDF
    return;
}

// +-------------------------------------------------------------------+
// | PLUGIN ACTIVATION HOOK                                          |
// +-------------------------------------------------------------------+

register_activation_hook( __FILE__, 'gcp_plugin_activation_tasks' );
/**
 * Tareas a ejecutar en la activación del plugin.
 * - Refrescar caché de slugs de FluentCRM.
 * - Crear tabla de certificados emitidos.
 * - Crear tabla de verificaciones de registro.
 */
function gcp_plugin_activation_tasks() {
    gcp_get_fluentcrm_contact_custom_field_slugs( true ); // Forzar refresco al activar
    gcp_create_issued_certificates_table(); // NEW: Crear tabla de certificados
    gcp_create_contact_verifications_table(); // NEW: tabla para verificaciones
}

/**
 * Ensure schema updates on plugin load.
 * Adds new columns if the plugin was updated without reactivation.
 */
add_action( 'plugins_loaded', 'gcp_maybe_update_db_schema' );

function gcp_maybe_update_db_schema() {
    gcp_maybe_add_etapa_column();
}

/**
 * Add etapa_del_curso column to contact verifications table if missing.
 */
function gcp_maybe_add_etapa_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_contact_verifications';
    $exists     = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name} LIKE %s", 'etapa_del_curso' ) );
    if ( empty( $exists ) ) {
        $wpdb->query( "ALTER TABLE {$table_name} ADD etapa_del_curso VARCHAR(255) DEFAULT '' NOT NULL AFTER course_name" );
    }
}

/**
 * NEW: Crea la tabla personalizada para almacenar los certificados emitidos.
 */
function gcp_create_issued_certificates_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_issued_certificates';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        cedula_alumno VARCHAR(255) NOT NULL,
        fluentcrm_contact_id BIGINT(20) UNSIGNED NULL,
        course_name VARCHAR(255) NOT NULL,
        certificate_filename VARCHAR(255) NOT NULL,
        certificate_url TEXT NOT NULL,
        date_issued DATETIME NOT NULL,
        validation_id VARCHAR(255) NULL,
        extra_data LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY cedula_alumno (cedula_alumno)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql ); // dbDelta se encarga de crear o actualizar la tabla si es necesario.
}

/**
 * NEW: Crea la tabla para registrar verificaciones de contacto.
 */
function gcp_create_contact_verifications_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_contact_verifications';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        cedula_alumno VARCHAR(255) NOT NULL,
        fluentcrm_contact_id BIGINT(20) UNSIGNED NULL,
        first_name VARCHAR(255) DEFAULT '' NOT NULL,
        last_name VARCHAR(255) DEFAULT '' NOT NULL,
        email VARCHAR(255) DEFAULT '' NOT NULL,
        course_name VARCHAR(255) DEFAULT '' NOT NULL,
        etapa_del_curso VARCHAR(255) DEFAULT '' NOT NULL,
        nit_empresa VARCHAR(255) DEFAULT '' NOT NULL,
        nombre_empresa VARCHAR(255) DEFAULT '' NOT NULL,
        date_verified DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY cedula_alumno (cedula_alumno)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ---------------------------------------------------------------
// | CUSTOM POST TYPE: TRAINERS                                   |
// ---------------------------------------------------------------

/**
 * Register the gcp_trainer post type used to store instructors.
 */
function gcp_register_trainer_post_type() {
    $labels = array(
        'name'          => __( 'Instructores', 'gcp-generador-cert' ),
        'singular_name' => __( 'Instructor', 'gcp-generador-cert' ),
    );

    $args = array(
        'labels'       => $labels,
        'public'       => false,
        'show_ui'      => false, // UI will be provided by the plugin
        'supports'     => array( 'title' ),
        'capability_type' => 'post',
    );

    register_post_type( 'gcp_trainer', $args );
}
add_action( 'init', 'gcp_register_trainer_post_type' );

/**
 * Retrieve all published trainers ordered by title.
 *
 * @return WP_Post[] Array of trainer posts.
 */
function gcp_get_trainer_posts() {
    return get_posts( array(
        'post_type'   => 'gcp_trainer',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby'     => 'title',
        'order'       => 'ASC',
    ) );
}

/**
 * Get trainer data by ID.
 *
 * @param int $trainer_id Trainer post ID.
 * @return array|false Array with name, license and signature_url or false on failure.
 */
function gcp_get_trainer_data( $trainer_id ) {
    $trainer_id = intval( $trainer_id );
    if ( ! $trainer_id ) {
        return false;
    }

    $trainer = get_post( $trainer_id );
    if ( ! $trainer || 'gcp_trainer' !== $trainer->post_type ) {
        return false;
    }

    return array(
        'name'          => $trainer->post_title,
        'license'       => get_post_meta( $trainer_id, 'gcp_trainer_license', true ),
        'signature_url' => get_post_meta( $trainer_id, 'gcp_trainer_signature_url', true ),
    );
}


// +-------------------------------------------------------------------+
// | SHORTCODES                                                        |
// +-------------------------------------------------------------------+

// SHORTCODE Página de descarga del certificado para estudiantes
add_shortcode( 'gcp_descargar_certificados_form', 'gcp_render_descarga_certificados_form' );

function gcp_render_descarga_certificados_form() {
    // Enqueue a dedicated JS file for this page if needed for AJAX
    wp_enqueue_script(
        'gcp-student-certs-script',
        plugin_dir_url( __FILE__ ) . 'js/student-certs.js', // Archivo JS para el buscador
        array( 'jquery' ),
        '1.5.0',
        true
    );
    wp_enqueue_style(
        'gcp-student-certs-style',
        plugin_dir_url( __FILE__ ) . 'css/student-certs.css',
        array(),
        '1.5.0'
    );
    wp_localize_script(
        'gcp-student-certs-script',
        'gcp_student_ajax_obj',
        array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'gcp_student_download_nonce' )
        )
    );

    ob_start();
    ?>
    <div id="gcp-student-certs-container">
        

        <form id="gcp-student-certs-form">
            <label for="gcp_student_cedula"><?php _e( 'Número de Cédula:', 'gcp-generador-cert' ); ?></label>
            <input type="text" id="gcp_student_cedula" name="gcp_student_cedula" required />
            <button type="submit" id="gcp-find-my-certs-button"><?php _e( 'Buscar Certificados', 'gcp-generador-cert' ); ?></button>
        </form>
        <div id="gcp-student-certs-results" style="margin-top: 20px;">
            </div>
        <div id="gcp-student-certs-loading" style="display:none;"><?php _e( 'Buscando...', 'gcp-generador-cert' ); ?></div>
        <div id="gcp-student-certs-error" style="display:none; color:red;"></div>
    </div>
    <?php
    return ob_get_clean();
}

// Incluir el shortcode público (existente en tu código)
// Asegúrate que el archivo 'includes/shortcode-public-certificado.php' existe y es correcto.
if (file_exists(plugin_dir_path( __FILE__ ) . 'includes/shortcode-public-certificado.php')) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/shortcode-public-certificado.php';

    // Registrar el shortcode público en el hook init (existente en tu código)
    add_action( 'init', function() {
        // Asegúrate que la función 'expide_certificado_publico_shortcode' está definida
        // en el archivo 'shortcode-public-certificado.php'
        if (function_exists('expide_certificado_publico_shortcode')) {
            add_shortcode( 'expide_certificado_publico', 'expide_certificado_publico_shortcode' );
        }
    } );
}


// +-------------------------------------------------------------------+
// | PÁGINA DE ADMINISTRACIÓN (MENÚ Y HTML)                            |
// +-------------------------------------------------------------------+

add_action( 'admin_menu', 'gcp_add_admin_menu_page' );

function gcp_add_admin_menu_page() {
    add_menu_page(
        __( 'Gestión estudiantes', 'gcp-generador-cert' ),
        __( 'Gestión estudiantes', 'gcp-generador-cert' ),
        'manage_options',
        'gcp_generar_certificado',
        'gcp_render_admin_page_content', // Esta es la función que modificamos
        'dashicons-awards',
        20
    );

    // Register the main page also as a submenu with a custom label
    add_submenu_page(
        'gcp_generar_certificado',
        __( 'Expedir Certificados', 'gcp-generador-cert' ),
        __( 'Expedir certificados', 'gcp-generador-cert' ),
        'manage_options',
        'gcp_generar_certificado',
        'gcp_render_admin_page_content'
    );
}

// MODIFIED FUNCTION
function gcp_render_admin_page_content() {
    $trainers = gcp_get_trainer_posts();
    ?>
    <div class="wrap">
        <h1><?php _e( 'Generar Certificado', 'gcp-generador-cert' ); ?></h1>
        <form id="gcp-certificate-form" method="POST">
            <table class="form-table" role="presentation">
                <tbody>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Datos del contacto','gcp-generador-cert'); ?></th></tr>
                    <tr>
                        <th scope="row"><label for="gcp_cedula"><?php _e( 'Cédula del Contacto (Búsqueda)', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_cedula" name="gcp_cedula" class="regular-text">
                            <p class="description"><?php _e( 'Ingresa la cédula y presiona Tab o haz clic fuera del campo para buscar.', 'gcp-generador-cert' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nombre_completo"><?php _e( 'Nombre alumno', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_completo" name="gcp_nombre_completo" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_email"><?php _e( 'Email', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="email" id="gcp_email" name="gcp_email" class="regular-text" readonly></td>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Datos del curso','gcp-generador-cert'); ?></th></tr>
                    <tr>
                        <th scope="row"><label for="gcp_nombre_del_curso"><?php _e( 'Nombre curso', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_del_curso" name="gcp_nombre_del_curso" class="regular-text" readonly></td>
                    </tr>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Datos de la empresa','gcp-generador-cert'); ?></th></tr>
                    <tr >
                        <th scope="row"><label for="gcp_nombre_de_la_empresa_empl"><?php _e( 'Nombre de la empresa empleadora', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_de_la_empresa_empl" name="gcp_nombre_de_la_empresa_empl" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nit_de_la_empresa_emplead"><?php _e( 'Nit de la empresa', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nit_de_la_empresa_emplead" name="gcp_nit_de_la_empresa_emplead" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_estado_de_pago_del_curso"><?php _e( 'Estado de pago del curso', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_estado_de_pago_del_curso" name="gcp_estado_de_pago_del_curso" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_id_ministerio_del_curso"><?php _e( 'Validación del certificado', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_id_ministerio_del_curso" name="gcp_id_ministerio_del_curso" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_rut_empresa"><?php _e( 'Rut empresa', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_rut_empresa" name="gcp_rut_empresa" class="regular-text" readonly></td>
                    </tr>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Documentación adicional','gcp-generador-cert'); ?></th></tr>
                    <tr >
                        <th scope="row"><label for="gcp_cedula_escaneada"><?php _e( 'Cédula escaneada (URL/Path)', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_cedula_escaneada" name="gcp_cedula_escaneada" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_seguridad_social"><?php _e( 'Seguridad social', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_seguridad_social" name="gcp_seguridad_social" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_curso_avanzado_o_trabajad"><?php _e( 'Curso avanzado o trabajador autorizado', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_curso_avanzado_o_trabajad" name="gcp_curso_avanzado_o_trabajad" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_certificado_sg_sst"><?php _e( 'Certificado SG-SST', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_certificado_sg_sst" name="gcp_certificado_sg_sst" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_certificado_de_curso_reen"><?php _e( 'Certificado de curso reentrenamiento', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_certificado_de_curso_reen" name="gcp_certificado_de_curso_reen" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_examen_medico_en_alturas"><?php _e( 'Examen médico en alturas', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_examen_medico_en_alturas" name="gcp_examen_medico_en_alturas" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_intensidad_horaria"><?php _e( 'Intensidad horaria', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_intensidad_horaria" name="gcp_intensidad_horaria" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_fecha_de_realizado"><?php _e( 'Fecha de realizado', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_fecha_de_realizado" name="gcp_fecha_de_realizado" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_fecha_de_expedicion"><?php _e( 'Fecha de expedición', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_fecha_de_expedicion" name="gcp_fecha_de_expedicion" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_arl"><?php _e( 'ARL', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_arl" name="gcp_arl" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_representante_legal_de_la"><?php _e( 'Representante legal de la empresa', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_representante_legal_de_la" name="gcp_representante_legal_de_la" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_etapa_del_curso"><?php _e( 'Etapa del curso', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_etapa_del_curso" name="gcp_etapa_del_curso" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp__estado_de_la_documentaci"><?php _e( 'Estado de la documentación', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp__estado_de_la_documentaci" name="gcp__estado_de_la_documentaci" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_numero_factura"><?php _e( 'Número factura', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_numero_factura" name="gcp_numero_factura" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nci"><?php _e( 'NCI', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nci" name="gcp_nci" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_fecha_de_inicio"><?php _e( 'Fecha de inicio', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_fecha_de_inicio" name="gcp_fecha_de_inicio" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_tipo_de_documento"><?php _e( 'Tipo de documento', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_tipo_de_documento" name="gcp_tipo_de_documento" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nombre_de_contacto_de_la_">
                            <?php _e( 'Nombre de contacto de la empresa empleadora', 'gcp-generador-cert' ); ?>
                        </label></th>
                        <td><input type="text" id="gcp_nombre_de_contacto_de_la_" name="gcp_nombre_de_contacto_de_la_" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_correo_electronico_de_la_">
                            <?php _e( 'Correo electrónico de la empresa empleadora', 'gcp-generador-cert' ); ?>
                        </label></th>
                        <td><input type="text" id="gcp_correo_electronico_de_la_" name="gcp_correo_electronico_de_la_" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_telefono"><?php _e( 'Teléfono', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_telefono" name="gcp_telefono" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_trainer_id"><?php _e( 'Instructor', 'gcp-generador-cert' ); ?></label></th>
                        <td>
                            <select id="gcp_trainer_id" name="gcp_trainer_id">
                                <option value="">-- <?php esc_html_e( 'Seleccione', 'gcp-generador-cert' ); ?> --</option>
                                <?php foreach ( $trainers as $trainer ) : ?>
                                    <option value="<?php echo esc_attr( $trainer->ID ); ?>"><?php echo esc_html( $trainer->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php wp_nonce_field( 'gcp_buscar_contacto_nonce', 'gcp_nonce' ); ?>
            <p class="submit">
                <button type="button" id="gcp-generate-preview-button" class="button"><?php _e( 'Generar Vista Previa HTML', 'gcp-generador-cert' ); ?></button>
                <button type="button" id="gcp-generate-real-pdf-button" class="button button-primary" style="margin-left: 10px;"><?php _e( 'Generar y Descargar PDF', 'gcp-generador-cert' ); ?></button>
            </p>
        </form>

        <div id="gcp-certificate-preview" style="margin-top: 20px; border: 1px solid #ccc; padding: 20px; background: #fff; display: none;">
            <h2><?php _e( 'Vista Previa del Certificado', 'gcp-generador-cert' ); ?></h2>
            <p><strong><?php _e( 'Nombre:', 'gcp-generador-cert' ); ?></strong> <span id="preview_nombre"></span></p>
            <p ><strong><?php _e( 'Email:', 'gcp-generador-cert' ); ?></strong> <span id="preview_email"></span></p>
            <p><strong><?php _e( 'Cédula:', 'gcp-generador-cert' ); ?></strong> <span id="preview_cedula_display"></span></p>
            <p><strong><?php _e( 'Curso:', 'gcp-generador-cert' ); ?></strong> <span id="preview_curso"></span></p>
            <p ><strong><?php _e( 'Empresa:', 'gcp-generador-cert' ); ?></strong> <span id="preview_empresa"></span></p>
            <p><strong><?php _e( 'Nit Empresa:', 'gcp-generador-cert' ); ?></strong> <span id="preview_nit_empresa"></span></p>
            <p ><strong><?php _e( 'Estado Pago Curso:', 'gcp-generador-cert' ); ?></strong> <span id="preview_estado_pago_del_curso"></span></p>
            <p><strong><?php _e( 'Validación Certificado:', 'gcp-generador-cert' ); ?></strong> <span id="preview_id_ministerio_del_curso"></span></p>
            <p ><strong><?php _e( 'Rut Empresa:', 'gcp-generador-cert' ); ?></strong> <span id="preview_rut_empresa"></span></p>
            <p ><strong><?php _e( 'Cédula Escaneada:', 'gcp-generador-cert' ); ?></strong> <span id="preview_cedula_escaneada"></span></p>
            <p ><strong><?php _e( 'Seguridad Social:', 'gcp-generador-cert' ); ?></strong> <span id="preview_seguridad_social"></span></p>
            <p ><strong><?php _e( 'Tipo Curso/Trabajador:', 'gcp-generador-cert' ); ?></strong> <span id="preview_curso_avanzado_o_trabajad"></span></p>
            <p ><strong><?php _e( 'Certificado SG-SST:', 'gcp-generador-cert' ); ?></strong> <span id="preview_certificado_sg_sst"></span></p>
            <p ><strong><?php _e( 'Certificado Reentrenamiento:', 'gcp-generador-cert' ); ?></strong> <span id="preview_certificado_de_curso_reen"></span></p>
            <p ><strong><?php _e( 'Examen Médico Alturas:', 'gcp-generador-cert' ); ?></strong> <span id="preview_examen_medico_en_alturas"></span></p>
            <p><strong><?php _e( 'Intensidad Horaria:', 'gcp-generador-cert' ); ?></strong> <span id="preview_intensidad_horaria"></span></p>
            <p><strong><?php _e( 'Fecha Realizado:', 'gcp-generador-cert' ); ?></strong> <span id="preview_fecha_de_realizado"></span></p>
            <p><strong><?php _e( 'Fecha Expedición:', 'gcp-generador-cert' ); ?></strong> <span id="preview_fecha_de_expedicion"></span></p>
            <p><strong><?php _e( 'ARL:', 'gcp-generador-cert' ); ?></strong> <span id="preview_arl"></span></p>
            <p><strong><?php _e( 'Representante Legal Empresa:', 'gcp-generador-cert' ); ?></strong> <span id="preview_representante_legal_de_la"></span></p>
            <p ><strong><?php _e( 'Etapa del Curso:', 'gcp-generador-cert' ); ?></strong> <span id="preview_etapa_del_curso"></span></p>
            <p ><strong><?php _e( 'Estado de la documentación:', 'gcp-generador-cert' ); ?></strong> <span id="preview__estado_de_la_documentaci"></span></p>
            <p ><strong><?php _e( 'Número factura:', 'gcp-generador-cert' ); ?></strong> <span id="preview_numero_factura"></span></p>
            <p ><strong><?php _e( 'NCI:', 'gcp-generador-cert' ); ?></strong> <span id="preview_nci"></span></p>
            <p ><strong><?php _e( 'Fecha de inicio:', 'gcp-generador-cert' ); ?></strong> <span id="preview_fecha_de_inicio"></span></p>
            <p ><strong><?php _e( 'Tipo de documento:', 'gcp-generador-cert' ); ?></strong> <span id="preview_tipo_de_documento"></span></p>
            <p ><strong><?php _e( 'Nombre contacto empresa:', 'gcp-generador-cert' ); ?></strong> <span id="preview_nombre_de_contacto_de_la_"></span></p>
            <p ><strong><?php _e( 'Correo empresa:', 'gcp-generador-cert' ); ?></strong> <span id="preview_correo_electronico_de_la_"></span></p>
            <p ><strong><?php _e( 'Teléfono:', 'gcp-generador-cert' ); ?></strong> <span id="preview_telefono"></span></p>
            <p ><strong><?php _e( 'Instructor:', 'gcp-generador-cert' ); ?></strong> <span id="preview_trainer"></span></p>
            <p style="margin-top:20px;"><em><?php _e( 'Esto es solo una vista previa HTML. La generación final del PDF se activará con el otro botón.', 'gcp-generador-cert' ); ?></em></p>
        </div>
        <div id="gcp-pdf-link-container" style="margin-top:15px;"></div>
    </div>
    <?php
}
// END OF MODIFIED FUNCTION

// +-------------------------------------------------------------------+
// | ENCOLAR SCRIPTS (JS Y CSS) - ADMIN                                |
// +-------------------------------------------------------------------+

add_action( 'admin_enqueue_scripts', 'gcp_enqueue_admin_scripts' );

function gcp_enqueue_admin_scripts( $hook_suffix ) {
    $page_slug = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
    if ( strpos( $page_slug, 'gcp_' ) !== 0 ) {
        return;
    }

    wp_enqueue_script(
        'gcp-admin-main-script',
        plugin_dir_url( __FILE__ ) . 'js/admin-scripts.js',
        array( 'jquery' ),
        '1.5.0',
        true
    );

    wp_localize_script(
        'gcp-admin-main-script',
        'gcp_ajax_obj', // Este nonce se usa para el PDF, el de búsqueda está en el form
        array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'gcp_pdf_generation_nonce' )
        )
    );
    // Estilos del formulario en la página de administración
    wp_enqueue_style(
        'gcp-admin-style',
        plugin_dir_url( __FILE__ ) . 'css/admin-style.css',
        array(),
        '1.5.0'
    );
}


// +-----------------------------------------------------------------------------------+
// | OBTENER Y CACHEAR SLUGS DE CAMPOS PERSONALIZADOS DE FLUENTCRM                     |
// +-----------------------------------------------------------------------------------+

function gcp_get_fluentcrm_contact_custom_field_slugs( $force_refresh = false ) {
    $cache_key = 'gcp_fluentcrm_custom_field_slugs';
    $cached_slugs = get_transient( $cache_key );

    if ( ! $force_refresh && false !== $cached_slugs && is_array( $cached_slugs ) ) {
        return $cached_slugs;
    }

    $api_url_base = rtrim( get_option('gcp_fluentcrm_api_url', 'https://snow-alligator-339390.hostingersite.com/wp-json/fluent-crm/v2'), '/' ); // MODIFIED: Use get_option for URL too
    $api_username = get_option('gcp_fluentcrm_api_username', 'prueba.prueba');
    $api_password = get_option('gcp_fluentcrm_api_password', 'z1mB Rqqa mTww 1xa1 5uhv HY6u');

    if (empty($api_url_base) || empty($api_username) || empty($api_password)) {
        error_log('GCP Plugin - API credentials for FluentCRM custom fields are not set in WordPress options.');
        return (false !== $cached_slugs && is_array($cached_slugs)) ? $cached_slugs : [];
    }

    $request_url = $api_url_base . '/custom-fields/contacts';
    $args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $api_username . ':' . $api_password ),
        ),
        'timeout' => 20,
    );

    $response = wp_remote_get( $request_url, $args );
    $slugs = array(); // MODIFIED: ensure slugs is initialized

    if ( is_wp_error( $response ) ) {
        error_log( 'GCP Plugin - Error API (custom-fields/contacts): ' . $response->get_error_message() );
        return (false !== $cached_slugs && is_array($cached_slugs)) ? $cached_slugs : [];
    }

    $body = wp_remote_retrieve_body( $response );
    $data_from_api = json_decode( $body, true );
    $http_code = wp_remote_retrieve_response_code( $response );

    if ( $http_code === 200 && !empty( $data_from_api ) && isset( $data_from_api['fields'] ) && is_array( $data_from_api['fields'] ) ) {
        $fields_array = $data_from_api['fields'];
        if ( is_array( $fields_array ) ) {
            foreach ( $fields_array as $field ) {
                if ( isset( $field['slug'] ) && !empty( $field['slug'] ) ) {
                    $slugs[] = sanitize_key($field['slug']); // MODIFIED: Use $slugs instead of $slugs_from_api
                } else {
                    error_log('GCP - Campo sin slug en respuesta de /custom-fields/contacts: ' . print_r($field, true));
                }
            }
        }
    } else {
        error_log( "GCP Plugin - Respuesta API (custom-fields/contacts) no exitosa (HTTP {$http_code}): " . $body );
    }

    if ( ! empty( $slugs ) ) {
        set_transient( $cache_key, $slugs, DAY_IN_SECONDS );
    } else if ( false !== $cached_slugs && is_array($cached_slugs) ) {
        return $cached_slugs;
    }
    
    return $slugs;
}

/**
 * NEW: Obtiene los datos completos de un contacto en FluentCRM por cédula.
 */
function gcp_get_contact_data_by_cedula( $cedula_buscar ) {
    if ( ! function_exists( 'fluentCrmDb' ) || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
        return new \WP_Error( 'no_fluentcrm', __( 'FluentCRM no está activo o accesible.', 'gcp-generador-cert' ) );
    }

    try {
        $contact_internal = fluentCrmDb()->table( 'fc_subscribers' )
            ->join( 'fc_subscriber_meta', 'fc_subscribers.id', '=', 'fc_subscriber_meta.subscriber_id' )
            ->where( 'fc_subscriber_meta.key', 'cedula' )
            ->where( 'fc_subscriber_meta.value', $cedula_buscar )
            ->select( 'fc_subscribers.id', 'fc_subscribers.email', 'fc_subscribers.first_name', 'fc_subscribers.last_name' )
            ->first();
    } catch ( Exception $e ) {
        error_log( 'GCP Plugin - Error en búsqueda interna FluentCRM: ' . $e->getMessage() );
        return new \WP_Error( 'internal_search_error', __( 'Error durante la búsqueda interna del contacto por cédula.', 'gcp-generador-cert' ) );
    }

    if ( ! $contact_internal ) {
        return new \WP_Error( 'not_found', __( 'No se encontró ningún contacto con la cédula proporcionada.', 'gcp-generador-cert' ) );
    }
    $contact_id_fluentcrm = $contact_internal->id;

    $api_url_base = rtrim( get_option( 'gcp_fluentcrm_api_url', 'https://snow-alligator-339390.hostingersite.com/wp-json/fluent-crm/v2' ), '/' );
    $api_username = get_option( 'gcp_fluentcrm_api_username', 'prueba.prueba' );
    $api_password = get_option( 'gcp_fluentcrm_api_password', 'z1mB Rqqa mTww 1xa1 5uhv HY6u' );

    if ( empty( $api_url_base ) || empty( $api_username ) || empty( $api_password ) ) {
        return new \WP_Error( 'api_credentials', __( 'Error de configuración de API para obtener detalles del contacto.', 'gcp-generador-cert' ) );
    }

    $base_subscriber_url = $api_url_base . '/subscribers/' . $contact_id_fluentcrm;
    $request_url = add_query_arg( array( 'with' => array( 'subscriber.custom_values' ) ), $base_subscriber_url );

    $args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $api_username . ':' . $api_password ),
            'Content-Type'  => 'application/json',
        ),
        'timeout' => 30,
    );
    $response = wp_remote_get( $request_url, $args );

    if ( is_wp_error( $response ) ) {
        error_log( 'GCP Plugin - Error API REST (wp_remote_get /subscribers/{ID}): ' . $response->get_error_message() );
        return new \WP_Error( 'api_request', __( 'Error al conectar con la API REST de FluentCRM: ', 'gcp-generador-cert' ) . $response->get_error_message() );
    }

    $body = wp_remote_retrieve_body( $response );
    $data_from_api = json_decode( $body, true );
    $http_code = wp_remote_retrieve_response_code( $response );

    if ( $http_code !== 200 || empty( $data_from_api ) || ! isset( $data_from_api['subscriber'] ) ) {
        error_log( "GCP Plugin - Respuesta API REST /subscribers/{ID} no exitosa (HTTP {$http_code}) para Contacto ID {$contact_id_fluentcrm}. Body: " . $body );
        return new \WP_Error( 'api_response', __( 'La API REST de FluentCRM no devolvió datos válidos para el contacto.', 'gcp-generador-cert' ) );
    }
    $contact_api_data = $data_from_api['subscriber'];

    $formatted_contact_data = [
        'id'            => $contact_api_data['id'] ?? $contact_id_fluentcrm,
        'first_name'    => $contact_api_data['first_name'] ?? $contact_internal->first_name ?? '',
        'last_name'     => $contact_api_data['last_name'] ?? $contact_internal->last_name ?? '',
        'email'         => $contact_api_data['email'] ?? $contact_internal->email ?? '',
        'custom_fields' => []
    ];

    // Recoger slugs conocidos, pero no limitar los valores devueltos
    $allowed_slugs = gcp_get_fluentcrm_contact_custom_field_slugs();
    if ( isset( $contact_api_data['custom_values'] ) && is_array( $contact_api_data['custom_values'] ) && ! empty( $contact_api_data['custom_values'] ) ) {
        foreach ( $contact_api_data['custom_values'] as $slug => $value ) {
            $processed_value = is_array( $value ) ? implode( ', ', $value ) : $value;
            $formatted_contact_data['custom_fields'][ sanitize_key( $slug ) ] = [ 'value' => $processed_value ];
        }
    }

    if ( ! isset( $formatted_contact_data['custom_fields']['cedula'] ) ) {
        $is_cedula_slug_known = empty( $allowed_slugs ) || in_array( 'cedula', $allowed_slugs );
        if ( $is_cedula_slug_known ) {
            $formatted_contact_data['custom_fields']['cedula'] = [ 'value' => $cedula_buscar ];
        }
    }

    return $formatted_contact_data;
}

// +-------------------------------------------------------------------+
// | MANEJADORES AJAX                                                  |
// +-------------------------------------------------------------------+

// ---- AJAX para búsqueda de contacto por cédula (Admin) ----
add_action( 'wp_ajax_gcp_buscar_contacto_por_cedula', 'gcp_ajax_buscar_contacto_handler_rest_v2' );

function gcp_ajax_buscar_contacto_handler_rest_v2() {
    // 1. Verificar Nonce de Seguridad (del formulario principal de admin)
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gcp_buscar_contacto_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Error de seguridad: Nonce inválido.', 'gcp-generador-cert' ) ), 403 );
        return;
    }

    // 2. Verificar Permisos del Usuario
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes para realizar esta acción.', 'gcp-generador-cert' ) ), 403 );
        return;
    }

    // 3. Validar y Sanitizar Datos de Entrada
    if ( ! isset( $_POST['cedula'] ) || empty( trim( $_POST['cedula'] ) ) ) {
        wp_send_json_error( array( 'message' => __( 'Cédula no proporcionada.', 'gcp-generador-cert' ) ), 400 );
        return;
    }
    $cedula_buscar = sanitize_text_field( trim( $_POST['cedula'] ) );

    $contact_data = gcp_get_contact_data_by_cedula( $cedula_buscar );
    if ( is_wp_error( $contact_data ) ) {
        wp_send_json_error( array( 'message' => $contact_data->get_error_message() ), 404 );
        return;
    }
    wp_send_json_success( $contact_data );
}


// ---- AJAX para generación de PDF (Admin) ----
add_action( 'wp_ajax_gcp_guardar_verificacion_registro', 'gcp_handle_verificacion_registro' );
add_action( 'wp_ajax_gcp_generar_certificado_pdf', 'gcp_handle_pdf_generation_request' );

function gcp_handle_verificacion_registro() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gcp_buscar_contacto_nonce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Error de seguridad: Nonce inválido.', 'gcp-generador-cert' ) ), 403 );
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes para realizar esta acción.', 'gcp-generador-cert' ) ), 403 );
        return;
    }

    if ( ! isset( $_POST['cedula'] ) || empty( trim( $_POST['cedula'] ) ) ) {
        wp_send_json_error( array( 'message' => __( 'Cédula no proporcionada.', 'gcp-generador-cert' ) ), 400 );
        return;
    }
    $cedula = sanitize_text_field( trim( $_POST['cedula'] ) );

    $contact_data = gcp_get_contact_data_by_cedula( $cedula );
    if ( is_wp_error( $contact_data ) ) {
        wp_send_json_error( array( 'message' => $contact_data->get_error_message() ), 404 );
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_contact_verifications';

    $insert_data = array(
        'cedula_alumno'        => $cedula,
        'fluentcrm_contact_id' => $contact_data['id'] ?? null,
        'first_name'           => $contact_data['first_name'] ?? '',
        'last_name'            => $contact_data['last_name'] ?? '',
        'email'                => $contact_data['email'] ?? '',
        'course_name'          => $contact_data['custom_fields']['nombre_del_curso']['value'] ?? '',
        'etapa_del_curso'      => $contact_data['custom_fields']['etapa_del_curso']['value'] ?? '',
        'nit_empresa'          => $contact_data['custom_fields']['nit_de_la_empresa_emplead']['value'] ?? '',
        'nombre_empresa'       => $contact_data['custom_fields']['nombre_de_la_empresa_empl']['value'] ?? '',
        'date_verified'        => current_time( 'mysql' )
    );

    // Si Tutor LMS está activo, intentar inscribir al usuario en el curso
    if ( function_exists( 'tutor_utils' ) ) {
        $user_email = $contact_data['email'] ?? '';
        $course_name = $insert_data['course_name'];

        $user = $user_email ? get_user_by( 'email', $user_email ) : false;
        $course_post = $course_name ? get_page_by_title( $course_name, OBJECT, 'courses' ) : false;

        if ( $user && $course_post ) {
            $course_id = $course_post->ID;
            $user_id   = $user->ID;

            if ( ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
                tutor_utils()->do_enroll( $course_id, 0, $user_id );
            }
        }
    }

    $wpdb->insert( $table_name, $insert_data );

    if ( $wpdb->last_error ) {
        error_log( 'GCP Plugin - Error al insertar verificación: ' . $wpdb->last_error );
        wp_send_json_error( array( 'message' => __( 'Error al guardar la verificación en la base de datos.', 'gcp-generador-cert' ) ), 500 );
        return;
    }

    wp_send_json_success( array( 'message' => __( 'Verificación registrada correctamente.', 'gcp-generador-cert' ) ) );
}

function gcp_handle_pdf_generation_request() {
    // 1. Verificar Nonce de Seguridad (del JS gcp_ajax_obj.nonce para esta acción específica)
    // O puedes usar el nonce del formulario principal 'gcp_buscar_contacto_nonce' si se envía
    // consistentemente. Aquí usaré el nonce específico para la generación de PDF que se localiza en gcp_ajax_obj.
    // Revisa tu JS: $('#gcp_nonce').val() es 'gcp_buscar_contacto_nonce'
    // gcp_ajax_obj.nonce es 'gcp_pdf_generation_nonce'
    // Para simplificar, usaré el nonce que parece que envías desde JS ('gcp_buscar_contacto_nonce').
    // Si decides usar gcp_pdf_generation_nonce, asegúrate que el JS lo envíe con el nombre 'nonce'.

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gcp_buscar_contacto_nonce' ) ) { // MODIFIED: Usando el nonce principal del form como parece en JS
        wp_send_json_error( array( 'message' => __( 'Error de seguridad (PDF): Nonce inválido.', 'gcp-generador-cert' ) ), 403 );
        return;
    }

    // 2. Verificar Permisos del Usuario
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes para esta acción (PDF).', 'gcp-generador-cert' ) ), 403 );
        return;
    }

    // 3. Recolectar y sanitizar datos del POST
    $certificate_data = array();
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'gcp_') === 0) {
            $clean_key = substr($key, 4);
            $certificate_data[$clean_key] = sanitize_text_field(wp_unslash($value));
        }
    }
    
    if ( empty($certificate_data['nombre_completo']) || empty($certificate_data['nombre_del_curso']) || empty($certificate_data['cedula']) ) {
        wp_send_json_error( array('message' => __('Faltan datos esenciales para generar el certificado (nombre, curso o cédula).', 'gcp-generador-cert')), 400);
        return;
    }

    if ( ! class_exists('\Mpdf\Mpdf') ) {
        error_log('GCP Plugin CRITICAL: La clase \Mpdf\Mpdf no fue encontrada.');
        wp_send_json_error( array('message' => __('La librería PDF (mPDF) no está disponible.', 'gcp-generador-cert')), 500);
        return;
    }

    try {
        $certificate_html = gcp_get_certificate_html_template($certificate_data);
        if (empty($certificate_html)) {
            error_log('GCP Plugin ERROR: La función gcp_get_certificate_html_template devolvió HTML vacío.');
            wp_send_json_error( array('message' => __('No se pudo generar la plantilla HTML del certificado.', 'gcp-generador-cert')), 500);
            return;
        }

        $mpdf_config = [
            'mode'              => 'utf-8',
            // Use portrait orientation so the certificate downloads vertically
            'format'            => 'A4',
            'margin_left'       => 10,
            'margin_right'      => 10,
            'margin_top'        => 10,
            'margin_bottom'     => 15,
            'tempDir'           => WP_CONTENT_DIR . '/uploads/mpdf_temp',
            'autoLangToFont'    => true,
            'autoScriptToLang'  => true,
        ];
        
        $tempDir = $mpdf_config['tempDir'];
        if (!file_exists($tempDir)) { 
            if (!@wp_mkdir_p($tempDir)) {
                 error_log('GCP Plugin ERROR: No se pudo crear el directorio temporal para mPDF: ' . $tempDir);
                 wp_send_json_error( array('message' => __('Error de configuración: No se pudo crear el directorio temporal para PDF.', 'gcp-generador-cert')), 500);
                 return;
            }
        }
        if (!is_writable($tempDir)) {
            error_log('GCP Plugin ERROR: El directorio temporal para mPDF no es escribible: ' . $tempDir);
            wp_send_json_error( array('message' => __('Error de configuración: El directorio temporal para PDF no es escribible.', 'gcp-generador-cert')), 500);
            return;
        }

        $mpdf = new \Mpdf\Mpdf($mpdf_config);
        $mpdf->SetDisplayMode('fullpage');
        $mpdf->WriteHTML($certificate_html);

        $file_name_base = 'Certificado-' . sanitize_title($certificate_data['nombre_completo'] ?? 'contacto') . '-' . sanitize_title($certificate_data['nombre_del_curso'] ?? 'curso') . '-' . date('Ymd-His');
        $file_name = $file_name_base . '.pdf';

        $upload_dir_info = wp_upload_dir();
        $pdf_target_dir = $upload_dir_info['basedir'] . '/certificados-gcp/'; 
        
        if (!file_exists($pdf_target_dir)) { 
            if (!@wp_mkdir_p($pdf_target_dir)) {
                error_log('GCP Plugin ERROR: No se pudo crear el directorio de destino de PDFs: ' . $pdf_target_dir);
                wp_send_json_error(array('message' => __('Error de configuración: No se pudo crear el directorio de destino para los PDFs.', 'gcp-generador-cert')), 500);
                return;
            }
        }

        if (is_writable($pdf_target_dir)) {
            $final_pdf_path = $pdf_target_dir . $file_name;
            $final_pdf_url = $upload_dir_info['baseurl'] . '/certificados-gcp/' . $file_name;
            
            $mpdf->Output($final_pdf_path, \Mpdf\Output\Destination::FILE);

            if (file_exists($final_pdf_path)) {
                // NEW: Record the certificate issuance
                global $wpdb;
                $table_name_issued_certs = $wpdb->prefix . 'gcp_issued_certificates';
                
                $cedula_for_lookup = $certificate_data['cedula'] ?? null;
                $fluent_contact_id = null;

                if ($cedula_for_lookup && function_exists('fluentCrmDb')) {
                     $contact_obj = fluentCrmDb()->table('fc_subscriber_meta')
                                         ->where('key', 'cedula') // Asumiendo que el slug del campo cédula es 'cedula'
                                         ->where('value', $cedula_for_lookup)
                                         ->first();
                     if ($contact_obj && isset($contact_obj->subscriber_id)) {
                         $fluent_contact_id = $contact_obj->subscriber_id;
                     }
                }

                $insert_data = array(
                    'cedula_alumno'        => $cedula_for_lookup,
                    'fluentcrm_contact_id' => $fluent_contact_id,
                    'course_name'          => $certificate_data['nombre_del_curso'] ?? 'N/A',
                    'certificate_filename' => $file_name,
                    'certificate_url'      => $final_pdf_url,
                    'date_issued'         => current_time( 'mysql' ),
                    'validation_id'       => $certificate_data['id_ministerio_del_curso'] ?? null
                );

                $trainer_id = isset( $certificate_data['trainer_id'] ) ? intval( $certificate_data['trainer_id'] ) : 0;
                if ( $trainer_id ) {
                    $has_col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table_name_issued_certs} LIKE %s", 'trainer_id' ) );
                    if ( $has_col ) {
                        $insert_data['trainer_id'] = $trainer_id;
                    } else {
                        $insert_data['extra_data'] = wp_json_encode( array( 'trainer_id' => $trainer_id ) );
                    }
                }
                
                $insert_data = array_filter($insert_data, function($value) { return $value !== null; });

                if (!empty($insert_data['cedula_alumno']) && !empty($insert_data['course_name'])) {
                    $wpdb->insert( $table_name_issued_certs, $insert_data );
                    if ($wpdb->last_error) {
                         error_log("GCP: Failed to insert certificate record for cedula {$insert_data['cedula_alumno']}. DB Error: " . $wpdb->last_error);
                    }
                } else {
                    error_log("GCP: Missing cedula or course name for DB record. Data: " . print_r($certificate_data, true));
                }

                wp_send_json_success([ // MODIFIED: Updated success message
                    'message'   => __('PDF generado y guardado exitosamente. Registro creado.', 'gcp-generador-cert'),
                    'pdf_url'   => $final_pdf_url,
                    'file_name' => $file_name
                ]);
            } else {
                error_log('GCP Plugin CRITICAL: mPDF Output("F") falló al crear el archivo en: ' . $final_pdf_path);
                wp_send_json_error(array('message' => __('Error crítico: No se pudo guardar el PDF en el servidor.', 'gcp-generador-cert')), 500);
            }
        } else {
            error_log('GCP Plugin ERROR: El directorio de destino para los PDFs no es escribible: ' . $pdf_target_dir);
            wp_send_json_error(array('message' => __('Error de configuración: El directorio de destino para los PDFs no es escribible.', 'gcp-generador-cert')), 500);
        }

    } catch (\Mpdf\MpdfException $e) {
        error_log('GCP Plugin - Error específico de mPDF: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
        wp_send_json_error(array('message' => __('Error interno al generar el PDF con mPDF: ', 'gcp-generador-cert') . $e->getMessage()), 500);
    } catch (Exception $e) {
        error_log('GCP Plugin - Error inesperado en PDF: ' . $e->getMessage());
        wp_send_json_error(array('message' => __('Ocurrió un error inesperado al generar el PDF: ', 'gcp-generador-cert') . $e->getMessage()), 500);
    }
    wp_die();
}

// ---- NEW: AJAX para búsqueda de certificados por cédula (Student/Public) ----
add_action( 'wp_ajax_gcp_fetch_student_certificates', 'gcp_ajax_fetch_student_certificates_handler' );
add_action( 'wp_ajax_nopriv_gcp_fetch_student_certificates', 'gcp_ajax_fetch_student_certificates_handler' );

function gcp_ajax_fetch_student_certificates_handler() {
    check_ajax_referer( 'gcp_student_download_nonce', 'nonce' );

    if ( !isset($_POST['cedula']) || empty(trim($_POST['cedula'])) ) {
        wp_send_json_error( array( 'message' => __('Cédula no proporcionada.', 'gcp-generador-cert') ), 400 );
        return;
    }
    $cedula = sanitize_text_field(trim($_POST['cedula']));

    // Basic validation for Cédula (Colombian Cédulas are numeric)
    if (!ctype_digit($cedula) || strlen($cedula) < 5 || strlen($cedula) > 12) { // Adjust length as per typical Cédula
        wp_send_json_error( array( 'message' => __('Formato de cédula inválido.', 'gcp-generador-cert') ), 400 );
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_issued_certificates';

    // Fetching specific fields and formatting date for display
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT course_name, certificate_filename, certificate_url, DATE_FORMAT(date_issued, %s) as date_issued_formatted, validation_id 
             FROM {$table_name} 
             WHERE cedula_alumno = %s 
             ORDER BY date_issued DESC",
            '%d/%m/%Y', // Date format d/m/Y
            $cedula
        ), ARRAY_A
    );

    if ( $wpdb->last_error ) {
        error_log("GCP Student Certs DB Error: " . $wpdb->last_error);
        wp_send_json_error( array( 'message' => __('Error al consultar la base de datos de certificados.', 'gcp-generador-cert') ), 500 );
        return;
    }

    // Even if empty, send success, JS will handle "no results found"
    wp_send_json_success( $results );
}


// +-------------------------------------------------------------------+
// | PLANTILLA HTML DEL CERTIFICADO (CON MPDF)                         |
// +-------------------------------------------------------------------+

function gcp_get_certificate_html_template($data) {
    // Sanitizar datos
    $nombre_completo    = !empty($data['nombre_completo']) ? htmlspecialchars($data['nombre_completo'], ENT_QUOTES, 'UTF-8') : '[Nombre no disponible]';
    $nombre_curso       = !empty($data['nombre_del_curso']) ? htmlspecialchars($data['nombre_del_curso'], ENT_QUOTES, 'UTF-8') : '[Curso no especificado]';
    $cedula_display     = !empty($data['cedula']) ? htmlspecialchars($data['cedula'], ENT_QUOTES, 'UTF-8') : '[Cédula no disponible]'; // Para mostrar en el certificado si es necesario.
    $fecha_expedicion   = !empty($data['fecha_de_expedicion']) ? htmlspecialchars($data['fecha_de_expedicion'], ENT_QUOTES, 'UTF-8') : date_i18n(get_option('date_format'));
    $intensidad_horaria = !empty($data['intensidad_horaria']) ? htmlspecialchars($data['intensidad_horaria'], ENT_QUOTES, 'UTF-8') : '[N/A]';
    $nit_empresa        = !empty($data['nit_de_la_empresa_emplead']) ? htmlspecialchars($data['nit_de_la_empresa_emplead'], ENT_QUOTES, 'UTF-8') : '[N/A]'; // Usar este para $nit_empresa_empleadora
    $arl                = !empty($data['arl']) ? htmlspecialchars($data['arl'], ENT_QUOTES, 'UTF-8') : '[N/A]'; // Usar este para $arl_alumno
    $fecha_realizado    = !empty($data['fecha_de_realizado']) ? htmlspecialchars($data['fecha_de_realizado'], ENT_QUOTES, 'UTF-8') : '[Fecha no especificada]';
    $codigo_validacion  = !empty($data['id_ministerio_del_curso']) ? htmlspecialchars($data['id_ministerio_del_curso'], ENT_QUOTES, 'UTF-8') : '[N/A]';
    $representante_legal_empleadora = !empty($data['representante_legal_de_la']) ? htmlspecialchars($data['representante_legal_de_la'], ENT_QUOTES, 'UTF-8') : '[N/A]';
    
    // Datos Fijos
    $logo_url = plugin_dir_url( __FILE__ ) . 'assets/images/logo hseq.png';

    $default_trainer_name    = 'RUBY HIGUITA';
    $default_trainer_license = '[LICENCIA SST RUBY AQUÍ]';

    $trainer_name      = ! empty( $data['trainer_name'] ) ? htmlspecialchars( $data['trainer_name'], ENT_QUOTES, 'UTF-8' ) : $default_trainer_name;
    $trainer_license   = ! empty( $data['trainer_license'] ) ? htmlspecialchars( $data['trainer_license'], ENT_QUOTES, 'UTF-8' ) : $default_trainer_license;
    $trainer_signature = ! empty( $data['trainer_signature'] ) ? esc_url( $data['trainer_signature'] ) : '';

    if ( empty( $data['trainer_name'] ) && ! empty( $data['trainer_id'] ) ) {
        $trainer = gcp_get_trainer_data( $data['trainer_id'] );
        if ( $trainer ) {
            $trainer_name      = htmlspecialchars( $trainer['name'], ENT_QUOTES, 'UTF-8' );
            $trainer_license   = htmlspecialchars( $trainer['license'], ENT_QUOTES, 'UTF-8' ) ?: $trainer_license;
            $trainer_signature = $trainer['signature_url'] ? esc_url( $trainer['signature_url'] ) : $trainer_signature;
        }
    }

    $trainer_signature_html = $trainer_signature ? '<img src="' . $trainer_signature . '" alt="Firma del instructor" style="max-height:40px;">' : '&nbsp;';

    $representante_legal_certificadora = "Mónica Marcela Cañas Gomez";
    $url_verificacion_web = "https://www.hseqdelgolfo.com.co";
    $web_verificacion_display = "www.hseqdelgolfo.com.co";
    $licencia_sst_hseq = "Resolución 202460390983 Licencia de Seguridad y Salud en Trabajo de la Secretaría de Salud y Protección Social de Antioquia";
    $telefonos_verificacion = "310 463 2102 - 311 609 5867";
    $ciudad_expedicion = "Apartadó, Antioquia";
    $resolucion_mintrabajo = "4272 de 2021 Mintrabajo"; // Corregir a Mintrabajo si es el caso
    
    // Fecha de inicio del curso (campo personalizado)
    $fecha_inicio_curso = !empty($data['fecha_de_inicio']) ? htmlspecialchars($data['fecha_de_inicio'], ENT_QUOTES, 'UTF-8') : '[FECHA INICIO PENDIENTE]';


    // HTML y CSS del certificado (tomado de tu último fragmento)
    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Certificado - {$nombre_completo}</title>
  <style>
    /* Reset y tipografía */
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      font-family: 'DejaVu Sans', sans-serif; /* DejaVu Sans es buena para mPDF y caracteres especiales */
      color: #333;
      background: #fff;
      line-height:1.5;
      font-size:11pt; /* Tamaño base puede ser ajustado */
    }
    .certificate {
      padding:15mm; /* Márgenes internos del contenido del certificado */
      position:relative; /* Para la marca de agua */
    }
    /* Marca de agua muy sutil */
    .certificate::before {
      content: 'HSEQ del Golfo';
      position:absolute;
      top:50%; left:50%;
      transform:translate(-50%,-50%) rotate(-30deg); /* Ajustar rotación y posición */
      font-size:80pt; /* Reducir si es muy grande */
      color:#4f81ba; /* Color primario */
      opacity:0.03; /* Muy sutil */
      z-index:0; /* Detrás del contenido */
    }
    /* Encabezado con línea de acento */
    .header {
      width:100%;
      margin-bottom:10px;
      border-bottom:3px solid #4f81ba;
      padding-bottom:5px;
      text-align:center;
    }
    .header td {
      vertical-align:middle;
      text-align:center;
      padding-bottom:5px;
    }
    .logo img {
      width:90px !important;
      height:auto;
      display:block;
      margin:0 auto 5px;
    }
    .title {
      text-align:center;
      font-weight:700;
      color:#4f81ba;
      font-size:16pt;
      font-family:'DejaVu Sans', sans-serif;
      padding-left:0;
    }
    .title small {
      display:block;
      font-size:8pt;
      color:#555;
      margin-top:4px;
      font-weight:400;
    }
    /* Secciones principales */
    .section {
      text-align:center;
      margin:15px 0; /* Reducir margen vertical */
    }
    .section .label {
      font-weight:600; /* Un poco menos bold que 700 */
      font-size:10pt;
      margin-bottom:3px; /* Menos espacio */
      color: #222; /* Un poco más oscuro */
    }
    .section .value {
      font-size:14pt; /* Un poco más pequeño que antes */
      font-weight:700;
      color: #000; /* Negro para el nombre y curso */
      margin-top:1px;
    }
    /* Tabla de detalles refinada */
    .details {
      width:100%;
      border-collapse:collapse;
      margin:15px 0;
      font-size:9pt;
    }
    .details th, .details td {
      padding:7px 5px; /* Ajustar padding */
      text-align:left;
      border-bottom:1px solid #e0e0e0;
    }
    .details th {
      background:#4f81ba; /* Color primario */
      color:#fff;
      font-weight:600; /* Un poco menos bold */
    }
    .details tr:nth-child(even) td { background: #f8f9fa; } /* Color de fila alterno más sutil */
    /* Firmas */
    .signatures {
      width:100%;
      margin-top:25mm; /* Más espacio antes de las firmas */
      margin-bottom: 15mm;
      font-size:9pt;
      table-layout: fixed; /* Ayuda a que las celdas respeten el ancho */
    }
    .signatures td {
      width:50%; /* Asegurar anchos iguales */
      text-align:center;
      vertical-align:top;
      padding:0 10px;
    }
    .sign-line {
      border-top:1px solid #333;
      width:70%; /* Línea más corta */
      margin:0 auto 5px auto; /* Menos espacio después de la línea */
      height: 20px; /* Espacio para la firma */
    }
    .sign-line p { margin:0; line-height: 1.3; }
    /* Pie de página minimalista */
    .footer {
      text-align:center;
      font-size:7.5pt; /* Un poco más grande */
      color:#666; /* Un poco más oscuro */
      border-top:1px solid #e0e0e0;
      padding-top:8px;
      margin-top: 15mm;
    }
    .footer p { margin-bottom: 3px; }
    .footer a {
      color:#4f81ba; /* Color primario */
      text-decoration:none;
      font-weight:600;
    }
  </style>
</head>
<body>
  <div class="certificate">
    <table class="header">
      <tr>
        <td class="logo" colspan="2"><img src="{$logo_url}" alt="Logo HSEQ"></td>
      </tr>
      <tr>
        <td class="title" colspan="2">
          <strong>Certificado de formación y entrenamiento<br>para trabajos en alturas</strong>
          <br><small>MINTRABAJO N° RADICADO 08SE2018220000000030200</small>
        </td>
      </tr>
    </table>

    <div class="section">
      <div class="label"><strong>Certifica que:</strong></div>
      <div class="value">{$nombre_completo}</div>
    </div>

    <div class="section">
      <div class="label"><strong>Cursó y aprobó la formación y entrenamiento en:</strong></div>
      <div class="value">"{$nombre_curso}"</div>
    </div>

    <table class="details">
      <tr><th>Intensidad</th><td>{$intensidad_horaria} horas, bajo la resolución {$resolucion_mintrabajo}</td></tr>
      <tr><th>Realizado en</th><td>{$ciudad_expedicion} entre el {$fecha_inicio_curso} y el {$fecha_realizado}</td></tr>
      <tr><th>Expedido en</th><td>{$ciudad_expedicion}, el {$fecha_expedicion}</td></tr>
      <tr><th>Validación del certificado</th><td>NCI - HSEQ - {$codigo_validacion}</td></tr>
      <tr><th>NIT empresa empleadora</th><td>{$nit_empresa}</td></tr>
      <tr><th>Representante legal empresa empleadora</th><td>{$representante_legal_empleadora}</td></tr>
      <tr><th>ARL</th><td>{$arl}</td></tr>
    </table>

    <table class="signatures">
      <tr>
        <td>
          <div class="sign-line">{$trainer_signature_html}</div>
          <p>{$trainer_name}<br>Entrenador trabajo en altura<br>Licencia SST: {$trainer_license}</p>
        </td>
        <td>
          <div class="sign-line">&nbsp;</div>
          <p>{$representante_legal_certificadora}<br>Representante legal</p>
        </td>
      </tr>
    </table>

    <div class="footer">
      <p>{$licencia_sst_hseq}</p>
      <p>Este diploma puede ser verificado llamando al número <strong>{$telefonos_verificacion}</strong></p>
      <p>La autenticidad de este documento puede ser verificada en el registro electrónico en <a href="{$url_verificacion_web}" target="_blank">{$web_verificacion_display}</a></p>
    </div>
  </div>
</body>
</html>
HTML;
    return $html;
}

// +-------------------------------------------------------------------+
// | PÁGINA DE ADMINISTRACIÓN DE CERTIFICADOS EMITIDOS                 |
// +-------------------------------------------------------------------+

/**
 * Añade la subpágina de administración de certificados.
 */
function gcp_add_admin_manage_certificates_submenu_page() {
    add_submenu_page(
        'gcp_generar_certificado', // Slug del menú padre (tu página actual de generar certificado)
        __( 'Administrar Certificados', 'gcp-generador-cert' ), // Título de la página
        __( 'Certificados', 'gcp-generador-cert' ), // Título del menú
        'manage_options', // Capacidad requerida
        'gcp_administrar_certificados', // Slug de esta página de submenú
        'gcp_render_administrar_certificados_page' // Función que renderiza el contenido de la página
    );
}
add_action( 'admin_menu', 'gcp_add_admin_manage_certificates_submenu_page' );

/**
 * Añade la subpágina de estudiantes inscritos.
 */
function gcp_add_students_submenu_page() {
    add_submenu_page(
        'gcp_generar_certificado',
        __( 'Estudiantes Inscritos', 'gcp-generador-cert' ),
        __( 'Estudiantes Inscritos', 'gcp-generador-cert' ),
        'manage_options',
        'gcp_estudiantes_inscritos',
        'gcp_render_estudiantes_inscritos_page'
    );
}
add_action( 'admin_menu', 'gcp_add_students_submenu_page' );

/**
 * Add the verification submenu page.
 */
function gcp_add_verificacion_admision_submenu_page() {
    add_submenu_page(
        'gcp_generar_certificado',
        __( 'Verificación de Admisión', 'gcp-generador-cert' ),
        __( 'Verificación de Admisión', 'gcp-generador-cert' ),
        'manage_options',
        'gcp_verificacion_admision',
        'gcp_render_verificacion_admision_page'
    );
}
add_action( 'admin_menu', 'gcp_add_verificacion_admision_submenu_page' );

/**
 * Register the trainers management submenu page.
 */
function gcp_add_trainers_submenu_page() {
    add_submenu_page(
        'gcp_generar_certificado',
        __( 'Instructores', 'gcp-generador-cert' ),
        __( 'Instructores', 'gcp-generador-cert' ),
        'manage_options',
        'gcp_trainers',
        'gcp_render_trainers_page'
    );
}
add_action( 'admin_menu', 'gcp_add_trainers_submenu_page' );

/**
 * Render the admission verification page with a cedula search and verification button.
 */
function gcp_render_verificacion_admision_page() {
    ?>
    <div class="wrap">
        <h1><?php _e( 'Verificación de Admisión', 'gcp-generador-cert' ); ?></h1>
        <form id="gcp-certificate-form" method="POST">
            <table class="form-table" role="presentation">
                <tbody>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Datos del contacto','gcp-generador-cert'); ?></th></tr>
                    <tr>
                        <th scope="row"><label for="gcp_cedula"><?php _e( 'Cédula del Contacto (Búsqueda)', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_cedula" name="gcp_cedula" class="regular-text">
                            <p class="description"><?php _e( 'Ingresa la cédula y presiona Tab o haz clic fuera del campo para buscar.', 'gcp-generador-cert' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nombre_completo"><?php _e( 'Nombre alumno', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_completo" name="gcp_nombre_completo" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_email"><?php _e( 'Email', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="email" id="gcp_email" name="gcp_email" class="regular-text" readonly></td>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Datos del curso','gcp-generador-cert'); ?></th></tr>
                    <tr>
                        <th scope="row"><label for="gcp_nombre_del_curso"><?php _e( 'Nombre curso', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_del_curso" name="gcp_nombre_del_curso" class="regular-text" readonly></td>
                    </tr>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Datos de la empresa','gcp-generador-cert'); ?></th></tr>
                    <tr >
                        <th scope="row"><label for="gcp_nombre_de_la_empresa_empl"><?php _e( 'Nombre de la empresa empleadora', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_de_la_empresa_empl" name="gcp_nombre_de_la_empresa_empl" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nit_de_la_empresa_emplead"><?php _e( 'Nit de la empresa', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nit_de_la_empresa_emplead" name="gcp_nit_de_la_empresa_emplead" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_estado_de_pago_del_curso"><?php _e( 'Estado de pago del curso', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_estado_de_pago_del_curso" name="gcp_estado_de_pago_del_curso" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_id_ministerio_del_curso"><?php _e( 'Validación del certificado', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_id_ministerio_del_curso" name="gcp_id_ministerio_del_curso" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_rut_empresa"><?php _e( 'Rut empresa', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_rut_empresa" name="gcp_rut_empresa" class="regular-text" readonly></td>
                    </tr>
<tr class="gcp-section"><th colspan="2" style="background:#f1f1f1;"><?php _e('Documentación adicional','gcp-generador-cert'); ?></th></tr>
                    <tr >
                        <th scope="row"><label for="gcp_cedula_escaneada"><?php _e( 'Cédula escaneada (URL/Path)', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_cedula_escaneada" name="gcp_cedula_escaneada" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_seguridad_social"><?php _e( 'Seguridad social', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_seguridad_social" name="gcp_seguridad_social" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_curso_avanzado_o_trabajad"><?php _e( 'Curso avanzado o trabajador autorizado', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_curso_avanzado_o_trabajad" name="gcp_curso_avanzado_o_trabajad" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_certificado_sg_sst"><?php _e( 'Certificado SG-SST', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_certificado_sg_sst" name="gcp_certificado_sg_sst" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_certificado_de_curso_reen"><?php _e( 'Certificado de curso reentrenamiento', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_certificado_de_curso_reen" name="gcp_certificado_de_curso_reen" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_examen_medico_en_alturas"><?php _e( 'Examen médico en alturas', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_examen_medico_en_alturas" name="gcp_examen_medico_en_alturas" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_intensidad_horaria"><?php _e( 'Intensidad horaria', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_intensidad_horaria" name="gcp_intensidad_horaria" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_fecha_de_realizado"><?php _e( 'Fecha de realizado', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_fecha_de_realizado" name="gcp_fecha_de_realizado" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_fecha_de_expedicion"><?php _e( 'Fecha de expedición', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_fecha_de_expedicion" name="gcp_fecha_de_expedicion" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_arl"><?php _e( 'ARL', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_arl" name="gcp_arl" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_representante_legal_de_la"><?php _e( 'Representante legal de la empresa', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_representante_legal_de_la" name="gcp_representante_legal_de_la" class="regular-text" readonly></td>
                    </tr>
                    <tr >
                        <th scope="row"><label for="gcp_etapa_del_curso"><?php _e( 'Etapa del curso', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_etapa_del_curso" name="gcp_etapa_del_curso" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp__estado_de_la_documentaci"><?php _e( 'Estado de la documentación', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp__estado_de_la_documentaci" name="gcp__estado_de_la_documentaci" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_numero_factura"><?php _e( 'Número factura', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_numero_factura" name="gcp_numero_factura" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nci"><?php _e( 'NCI', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nci" name="gcp_nci" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_fecha_de_inicio"><?php _e( 'Fecha de inicio', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_fecha_de_inicio" name="gcp_fecha_de_inicio" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_tipo_de_documento"><?php _e( 'Tipo de documento', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_tipo_de_documento" name="gcp_tipo_de_documento" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_nombre_de_contacto_de_la_"><?php _e( 'Nombre de contacto de la empresa empleadora', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_nombre_de_contacto_de_la_" name="gcp_nombre_de_contacto_de_la_" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_correo_electronico_de_la_"><?php _e( 'Correo electrónico de la empresa empleadora', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_correo_electronico_de_la_" name="gcp_correo_electronico_de_la_" class="regular-text" readonly></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gcp_telefono"><?php _e( 'Teléfono', 'gcp-generador-cert' ); ?></label></th>
                        <td><input type="text" id="gcp_telefono" name="gcp_telefono" class="regular-text" readonly></td>
                    </tr>
                </tbody>
            </table>
            <?php wp_nonce_field( 'gcp_buscar_contacto_nonce', 'gcp_nonce' ); ?>
            <p class="submit">
                <button type="button" id="gcp-register-verification-button" class="button button-primary"><?php _e( 'Verificación de Admisión', 'gcp-generador-cert' ); ?></button>
            </p>
        </form>
    </div>
    <?php
}

add_action( 'admin_init', 'gcp_handle_update_student_record' );

/**
 * Handle inline updates for the students table.
 */
function gcp_handle_update_student_record() {
    if ( empty( $_POST['gcp_edit_verification_nonce'] ) ) {
        return;
    }

    if ( empty( $_POST['page'] ) || 'gcp_estudiantes_inscritos' !== $_POST['page'] ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'gcp_edit_verification', 'gcp_edit_verification_nonce' );

    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_contact_verifications';

    $record_id = isset( $_POST['gcp_record_id'] ) ? intval( $_POST['gcp_record_id'] ) : 0;
    if ( $record_id <= 0 ) {
        add_settings_error( 'gcp_students', 'gcp_invalid_record', __( 'No se ha podido identificar el registro a actualizar.', 'gcp-generador-cert' ), 'error' );
        return;
    }

    $first_name    = isset( $_POST['gcp_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_first_name'] ) ) : '';
    $last_name     = isset( $_POST['gcp_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_last_name'] ) ) : '';
    $course_name   = isset( $_POST['gcp_course_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_course_name'] ) ) : '';
    $course_stage  = isset( $_POST['gcp_etapa_del_curso'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_etapa_del_curso'] ) ) : '';
    $company_name  = isset( $_POST['gcp_nombre_empresa'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_nombre_empresa'] ) ) : '';
    $company_nit   = isset( $_POST['gcp_nit_empresa'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_nit_empresa'] ) ) : '';
    $date_verified = isset( $_POST['gcp_date_verified'] ) ? sanitize_text_field( wp_unslash( $_POST['gcp_date_verified'] ) ) : '';

    $data   = array(
        'first_name'      => $first_name,
        'last_name'       => $last_name,
        'course_name'     => $course_name,
        'etapa_del_curso' => $course_stage,
        'nombre_empresa'  => $company_name,
        'nit_empresa'     => $company_nit,
    );
    $format = array( '%s', '%s', '%s', '%s', '%s', '%s' );

    if ( ! empty( $date_verified ) ) {
        $timestamp = strtotime( $date_verified );
        if ( false !== $timestamp ) {
            $data['date_verified'] = gmdate( 'Y-m-d H:i:s', $timestamp );
        } else {
            $data['date_verified'] = $date_verified;
        }
        $format[] = '%s';
    }

    $updated = $wpdb->update( $table_name, $data, array( 'id' => $record_id ), $format, array( '%d' ) );

    if ( false === $updated ) {
        add_settings_error( 'gcp_students', 'gcp_update_failed', __( 'No se pudo actualizar el registro. Intenta de nuevo.', 'gcp-generador-cert' ), 'error' );
    } else {
        add_settings_error( 'gcp_students', 'gcp_update_success', __( 'Registro actualizado correctamente.', 'gcp-generador-cert' ), 'updated' );
    }
}

/**
 * Map the FluentCRM custom fields that should surface in the enrolled students module.
 *
 * @return array[] Array of grouped field definitions.
 */
function gcp_get_fluentcrm_custom_field_groups() {
    static $groups = null;

    if ( null !== $groups ) {
        return $groups;
    }

    $groups = array(
        'participant' => array(
            'label'  => __( 'Información del Participante', 'gcp-generador-cert' ),
            'fields' => array(
                'tipo_de_documento'            => __( 'Tipo de Documento', 'gcp-generador-cert' ),
                'cedula'                       => __( 'Cédula', 'gcp-generador-cert' ),
                'cedula_escaneada'             => __( 'Cédula Escaneada', 'gcp-generador-cert' ),
                'telefono'                     => __( 'Teléfono', 'gcp-generador-cert' ),
                'seguridad_social'             => __( 'Seguridad Social', 'gcp-generador-cert' ),
                'arl'                          => __( 'ARL', 'gcp-generador-cert' ),
                'examen_medico_en_alturas'     => __( 'Examén Médico en Alturas', 'gcp-generador-cert' ),
            ),
        ),
        'company'     => array(
            'label'  => __( 'Información de la Empresa', 'gcp-generador-cert' ),
            'fields' => array(
                'nombre_de_la_empresa_empl'    => __( 'Nombre de la Empresa Empleadora', 'gcp-generador-cert' ),
                'nit_de_la_empresa_emplead'    => __( 'Nit de la Empresa Empleadora', 'gcp-generador-cert' ),
                'rut_empresa'                  => __( 'Rut Empresa', 'gcp-generador-cert' ),
                'representante_legal_de_la'    => __( 'Nombre Representante Legal', 'gcp-generador-cert' ),
                'nombre_de_contacto_de_la_'    => __( 'Nombre de Contacto de la Empresa', 'gcp-generador-cert' ),
                'correo_electronico_de_la_'    => __( 'Correo Electrónico de la Empresa', 'gcp-generador-cert' ),
                'sector_economico_de_la_em'    => __( 'Sector Económico de la Empresa', 'gcp-generador-cert' ),
                'centro_de_costos'             => __( 'Centro de Costos', 'gcp-generador-cert' ),
            ),
        ),
        'course'      => array(
            'label'  => __( 'Datos del Curso y Certificación', 'gcp-generador-cert' ),
            'fields' => array(
                'nombre_del_curso'             => __( 'Nombre del Curso', 'gcp-generador-cert' ),
                'intensidad_horaria'           => __( 'Intensidad Horaria', 'gcp-generador-cert' ),
                'fecha_de_inicio'              => __( 'Fecha de Inicio', 'gcp-generador-cert' ),
                'fecha_de_realizado'           => __( 'Fecha de Finalización', 'gcp-generador-cert' ),
                'etapa_del_curso'              => __( 'Etapa del Curso', 'gcp-generador-cert' ),
                'nci'                          => __( 'NCI', 'gcp-generador-cert' ),
                'curso_avanzado_o_trabajad'    => __( 'Certificado Curso Avanzado/Autorizado', 'gcp-generador-cert' ),
                'certificado_de_curso_reen'    => __( 'Certificado de Curso Reentrenamiento', 'gcp-generador-cert' ),
                'certificado_sg-sst'           => __( 'Certificado SG-SST', 'gcp-generador-cert' ),
                'id_ministerio_del_curso'      => __( 'Validación del Certificado Ministerio', 'gcp-generador-cert' ),
                'fecha_de_expedicion'          => __( 'Fecha de Expedición', 'gcp-generador-cert' ),
                'enfoque_a_necesidades'        => __( 'Enfoque a Necesidades de Formación', 'gcp-generador-cert' ),
                'viene_por'                    => __( 'Viene por', 'gcp-generador-cert' ),
            ),
        ),
        'management'  => array(
            'label'  => __( 'Gestión y Pagos (Administrativo)', 'gcp-generador-cert' ),
            'fields' => array(
                'numero_factura'               => __( 'Número Factura', 'gcp-generador-cert' ),
                'estado_de_pago_del_curso'     => __( 'Estado de Pago del Curso', 'gcp-generador-cert' ),
                'fecha_de_pago'                => __( 'Fecha de Pago', 'gcp-generador-cert' ),
                '_estado_de_la_documentaci'    => __( 'Estado de la Documentación', 'gcp-generador-cert' ),
                'encargado_de_verificacion'    => __( 'Encargado de Verificación', 'gcp-generador-cert' ),
                'novedad'                      => __( 'Novedad', 'gcp-generador-cert' ),
            ),
        ),
    );

    return $groups;
}

/**
 * Flatten the FluentCRM custom field groups into a single slug => label map.
 *
 * @return array<string, string>
 */
function gcp_get_all_fluentcrm_custom_field_labels() {
    $groups = gcp_get_fluentcrm_custom_field_groups();
    $labels = array();

    foreach ( $groups as $group ) {
        if ( empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
            continue;
        }

        foreach ( $group['fields'] as $slug => $label ) {
            $labels[ sanitize_key( $slug ) ] = $label;
        }
    }

    return $labels;
}

/**
 * Convert FluentCRM custom field values into strings ready for display/export.
 *
 * @param mixed $value Raw value from the database or API.
 * @return string
 */
function gcp_format_fluentcrm_custom_field_value( $value ) {
    if ( is_array( $value ) ) {
        $value = array_filter( array_map( 'trim', $value ), 'strlen' );
        $value = implode( ', ', $value );
    } elseif ( is_object( $value ) ) {
        $value = wp_json_encode( $value );
    }

    if ( null === $value ) {
        $value = '';
    }

    return is_string( $value ) ? $value : (string) $value;
}

/**
 * Retrieve the FluentCRM custom field values for a list of contact IDs.
 *
 * @param int[] $contact_ids Contact IDs stored in the verification table.
 * @param string[] $target_slugs Slugs we are interested in.
 * @return array<int, array<string, string>>
 */
function gcp_get_fluentcrm_custom_values_for_contacts( $contact_ids, $target_slugs ) {
    $results = array();

    if ( empty( $contact_ids ) || empty( $target_slugs ) ) {
        return $results;
    }

    global $wpdb;
    $contact_ids   = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
    $target_slugs  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $target_slugs ) ) ) );

    if ( empty( $contact_ids ) || empty( $target_slugs ) ) {
        return $results;
    }

    $meta_table         = $wpdb->prefix . 'fc_subscriber_meta';
    $ids_placeholders   = implode( ', ', array_fill( 0, count( $contact_ids ), '%d' ) );
    $keys_placeholders  = implode( ', ', array_fill( 0, count( $target_slugs ), '%s' ) );
    $sql                = "SELECT subscriber_id, `key`, `value` FROM {$meta_table} WHERE subscriber_id IN ({$ids_placeholders}) AND `key` IN ({$keys_placeholders})";
    $prepared           = $wpdb->prepare( $sql, array_merge( $contact_ids, $target_slugs ) );
    $meta_rows          = $wpdb->get_results( $prepared );

    if ( empty( $meta_rows ) ) {
        return $results;
    }

    foreach ( $meta_rows as $row ) {
        $subscriber_id = isset( $row->subscriber_id ) ? intval( $row->subscriber_id ) : 0;
        $slug          = isset( $row->key ) ? sanitize_key( $row->key ) : '';

        if ( ! $subscriber_id || ! $slug ) {
            continue;
        }

        $raw_value = maybe_unserialize( $row->value );
        $results[ $subscriber_id ][ $slug ] = gcp_format_fluentcrm_custom_field_value( $raw_value );
    }

    return $results;
}

/**
 * Attach FluentCRM custom field values to each verification record.
 *
 * @param array $records Verification records loaded from the database.
 * @param array $custom_field_labels Flattened slug => label map.
 * @return array
 */
function gcp_attach_custom_fields_to_student_records( $records, $custom_field_labels ) {
    if ( empty( $records ) ) {
        return $records;
    }

    $custom_field_labels = is_array( $custom_field_labels ) ? $custom_field_labels : array();

    if ( empty( $custom_field_labels ) ) {
        foreach ( $records as $record ) {
            $record->custom_fields = array();
        }
        return $records;
    }

    $contact_ids = array();
    foreach ( $records as $record ) {
        if ( ! empty( $record->fluentcrm_contact_id ) ) {
            $contact_ids[] = intval( $record->fluentcrm_contact_id );
        }
    }

    $contact_meta_values = gcp_get_fluentcrm_custom_values_for_contacts( $contact_ids, array_keys( $custom_field_labels ) );
    $cedula_cache        = array();

    foreach ( $records as $record ) {
        $record->custom_fields = array();
        $record_meta_values    = array();

        if ( ! empty( $record->fluentcrm_contact_id ) && isset( $contact_meta_values[ $record->fluentcrm_contact_id ] ) ) {
            $record_meta_values = $contact_meta_values[ $record->fluentcrm_contact_id ];
        } elseif ( ! empty( $record->cedula_alumno ) ) {
            $cedula_key = sanitize_text_field( $record->cedula_alumno );
            if ( ! isset( $cedula_cache[ $cedula_key ] ) ) {
                $cedula_cache[ $cedula_key ] = gcp_get_contact_data_by_cedula( $cedula_key );
            }

            $contact_details = $cedula_cache[ $cedula_key ];
            if ( ! is_wp_error( $contact_details ) && ! empty( $contact_details['custom_fields'] ) ) {
                foreach ( $contact_details['custom_fields'] as $slug => $info ) {
                    $value = is_array( $info ) && isset( $info['value'] ) ? $info['value'] : $info;
                    $record_meta_values[ sanitize_key( $slug ) ] = gcp_format_fluentcrm_custom_field_value( $value );
                }
            }
        }

        foreach ( $custom_field_labels as $slug => $label ) {
            $record->custom_fields[ $slug ] = isset( $record_meta_values[ $slug ] ) ? $record_meta_values[ $slug ] : '';
        }
    }

    return $records;
}

/**
 * Normalize a raw Fluent Forms submission value into a scalar string.
 *
 * @param mixed $value Raw submission value.
 * @return string Normalized string (may be empty).
 */
function gcp_normalize_form_submission_value( $value ) {
    if ( is_array( $value ) ) {
        if ( isset( $value['value'] ) && is_scalar( $value['value'] ) ) {
            $value = $value['value'];
        } elseif ( isset( $value['url'] ) && is_scalar( $value['url'] ) ) {
            $value = $value['url'];
        } elseif ( isset( $value['path'] ) && is_scalar( $value['path'] ) ) {
            $value = $value['path'];
        } else {
            $flat_values = array();

            foreach ( $value as $nested ) {
                if ( is_scalar( $nested ) ) {
                    $flat_values[] = (string) $nested;
                } elseif ( is_array( $nested ) && isset( $nested['value'] ) && is_scalar( $nested['value'] ) ) {
                    $flat_values[] = (string) $nested['value'];
                } elseif ( is_array( $nested ) && isset( $nested['label'] ) && is_scalar( $nested['label'] ) ) {
                    $flat_values[] = (string) $nested['label'];
                } elseif ( is_object( $nested ) ) {
                    $flat_values[] = wp_json_encode( $nested );
                }
            }

            $value = implode( ', ', array_filter( array_map( 'trim', $flat_values ), 'strlen' ) );
        }
    } elseif ( is_object( $value ) ) {
        $value = wp_json_encode( $value );
    }

    if ( is_string( $value ) ) {
        $value = trim( wp_unslash( $value ) );
    }

    if ( '' === $value || null === $value ) {
        return '';
    }

    if ( is_scalar( $value ) ) {
        return (string) $value;
    }

    return wp_json_encode( $value );
}

/**
 * Sanitize a normalized submission value for safe storage.
 *
 * @param string $value Normalized value.
 * @return string Sanitized value.
 */
function gcp_sanitize_custom_field_value( $value ) {
    if ( '' === $value ) {
        return '';
    }

    if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
        return esc_url_raw( $value );
    }

    return sanitize_text_field( $value );
}

/**
 * Build a list of possible form keys for a given FluentCRM field slug.
 *
 * @param string $slug  Custom field slug.
 * @param string $label Human readable label.
 * @return string[] Candidate keys.
 */
function gcp_possible_submission_keys_for_slug( $slug, $label = '' ) {
    $candidates = array( $slug );

    $sanitized_slug = sanitize_key( $slug );
    if ( $sanitized_slug !== $slug ) {
        $candidates[] = $sanitized_slug;
    }

    $hyphenated = str_replace( '_', '-', $slug );
    if ( $hyphenated !== $slug ) {
        $candidates[] = $hyphenated;
    }

    $underscored = str_replace( '-', '_', $slug );
    if ( $underscored !== $slug ) {
        $candidates[] = $underscored;
    }

    $trimmed = rtrim( $slug, '_-' );
    if ( $trimmed !== $slug ) {
        $candidates[] = $trimmed;
    }

    if ( $label ) {
        $label_key = sanitize_key( $label );
        if ( $label_key ) {
            $candidates[] = $label_key;
        }

        $label_slug = sanitize_title( $label );
        if ( $label_slug ) {
            $candidates[] = $label_slug;
            $candidates[] = str_replace( '-', '_', $label_slug );
        }
    }

    return array_values( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) );
}

/**
 * Retrieve a submission value for a given FluentCRM field slug.
 *
 * @param array  $form_data Submission data array.
 * @param string $slug      Custom field slug or key to look for.
 * @param string $label     Optional label used to derive alternate keys.
 * @return string|null Normalized value or null if the key is absent.
 */
function gcp_extract_submission_value_for_slug( $form_data, $slug, $label = '' ) {
    if ( empty( $form_data ) || ! is_array( $form_data ) ) {
        return null;
    }

    $candidate_keys = gcp_possible_submission_keys_for_slug( $slug, $label );
    foreach ( $candidate_keys as $key ) {
        if ( isset( $form_data[ $key ] ) ) {
            $normalized = gcp_normalize_form_submission_value( $form_data[ $key ] );
            if ( '' !== $normalized ) {
                return $normalized;
            }
        }
    }

    foreach ( $form_data as $key => $value ) {
        if ( sanitize_key( $key ) === sanitize_key( $slug ) ) {
            $normalized = gcp_normalize_form_submission_value( $value );
            if ( '' !== $normalized ) {
                return $normalized;
            }
        }
    }

    return null;
}

/**
 * Locate a FluentCRM subscriber using cedula and/or email.
 *
 * @param string $cedula Cedula value.
 * @param string $email  Email address.
 * @return Subscriber|null
 */
function gcp_find_fluentcrm_contact_for_sync( $cedula, $email = '' ) {
    if ( ! function_exists( 'fluentCrmDb' ) || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
        return null;
    }

    $subscriber = null;

    if ( $cedula ) {
        try {
            $meta_row = fluentCrmDb()->table( 'fc_subscriber_meta' )
                ->select( 'subscriber_id' )
                ->where( 'key', 'cedula' )
                ->where( 'value', $cedula )
                ->first();
        } catch ( Exception $e ) {
            error_log( 'GCP Plugin - Error al localizar contacto por cédula: ' . $e->getMessage() );
            $meta_row = null;
        }

        if ( $meta_row && ! empty( $meta_row->subscriber_id ) ) {
            try {
                $subscriber = Subscriber::find( intval( $meta_row->subscriber_id ) );
            } catch ( Exception $e ) {
                error_log( 'GCP Plugin - Error al cargar contacto por ID: ' . $e->getMessage() );
            }
        }
    }

    if ( ! $subscriber && $email ) {
        try {
            $subscriber = Subscriber::where( 'email', $email )->first();
        } catch ( Exception $e ) {
            error_log( 'GCP Plugin - Error al localizar contacto por correo: ' . $e->getMessage() );
        }
    }

    return $subscriber instanceof Subscriber ? $subscriber : null;
}

/**
 * Insert or update a FluentCRM custom field value.
 *
 * @param int    $subscriber_id Subscriber ID.
 * @param string $slug          Custom field slug.
 * @param string $value         Sanitized value.
 */
function gcp_upsert_fluentcrm_meta_value( $subscriber_id, $slug, $value ) {
    global $wpdb;

    $subscriber_id = intval( $subscriber_id );
    $slug          = sanitize_key( $slug );

    if ( $subscriber_id <= 0 || '' === $slug ) {
        return;
    }

    $table_name = $wpdb->prefix . 'fc_subscriber_meta';
    $serialized = maybe_serialize( $value );

    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE subscriber_id = %d AND `key` = %s LIMIT 1",
            $subscriber_id,
            $slug
        )
    );

    if ( $existing_id ) {
        $wpdb->update(
            $table_name,
            array( 'value' => $serialized ),
            array( 'id' => intval( $existing_id ) ),
            array( '%s' ),
            array( '%d' )
        );
    } else {
        $wpdb->insert(
            $table_name,
            array(
                'subscriber_id' => $subscriber_id,
                'key'           => $slug,
                'value'         => $serialized,
            ),
            array( '%d', '%s', '%s' )
        );
    }

    if ( $wpdb->last_error ) {
        error_log( 'GCP Plugin - Error al guardar meta de FluentCRM: ' . $wpdb->last_error );
    }
}

/**
 * Persist multiple custom field values for a subscriber.
 *
 * @param int   $subscriber_id Subscriber ID.
 * @param array $updates       Array of slug => value pairs.
 */
function gcp_update_fluentcrm_custom_values( $subscriber_id, $updates ) {
    if ( empty( $updates ) ) {
        return;
    }

    $slugs          = array_keys( $updates );
    $existing_sets  = gcp_get_fluentcrm_custom_values_for_contacts( array( $subscriber_id ), $slugs );
    $existing_values = isset( $existing_sets[ $subscriber_id ] ) ? $existing_sets[ $subscriber_id ] : array();

    foreach ( $updates as $slug => $value ) {
        $slug = sanitize_key( $slug );
        if ( '' === $slug ) {
            continue;
        }

        $formatted_value = gcp_format_fluentcrm_custom_field_value( $value );
        if ( isset( $existing_values[ $slug ] ) && $existing_values[ $slug ] === $formatted_value ) {
            continue;
        }

        gcp_upsert_fluentcrm_meta_value( $subscriber_id, $slug, $formatted_value );
    }
}

/**
 * Extract an email address from the submitted data.
 *
 * @param array $form_data Submission data array.
 * @return string Sanitized email or empty string.
 */
function gcp_extract_submission_email( $form_data ) {
    if ( empty( $form_data ) || ! is_array( $form_data ) ) {
        return '';
    }

    $email_keys = array(
        'email',
        'correo',
        'correo_electronico',
        'correo-electronico',
        'correo_electrónico',
        'correo_electronico_de_la_',
        'email_address',
        'correo_participante',
    );

    foreach ( $email_keys as $key ) {
        $value = gcp_extract_submission_value_for_slug( $form_data, $key );
        if ( $value && is_email( $value ) ) {
            return sanitize_email( $value );
        }
    }

    foreach ( $form_data as $value ) {
        if ( is_string( $value ) && is_email( $value ) ) {
            return sanitize_email( $value );
        }
    }

    return '';
}

add_action( 'fluentform_submission_inserted', 'gcp_sync_fluentform_submission_to_fluentcrm', 20, 3 );

/**
 * Sync Fluent Forms submissions into FluentCRM custom fields.
 *
 * @param int   $entry_id  Submission ID.
 * @param array $form_data Raw submission data.
 * @param mixed $form      Form definition object/array.
 */
function gcp_sync_fluentform_submission_to_fluentcrm( $entry_id, $form_data, $form ) {
    if ( ! function_exists( 'fluentCrmDb' ) || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
        return;
    }

    if ( empty( $form_data ) || ! is_array( $form_data ) ) {
        return;
    }

    $field_labels = gcp_get_all_fluentcrm_custom_field_labels();
    if ( empty( $field_labels ) ) {
        return;
    }

    $updates      = array();
    $cedula_value = '';

    foreach ( $field_labels as $slug => $label ) {
        $raw_value = gcp_extract_submission_value_for_slug( $form_data, $slug, $label );
        if ( null === $raw_value ) {
            continue;
        }

        $sanitized = gcp_sanitize_custom_field_value( $raw_value );
        if ( '' === $sanitized ) {
            continue;
        }

        $updates[ $slug ] = $sanitized;

        if ( 'cedula' === $slug ) {
            $cedula_value = $sanitized;
        }
    }

    if ( empty( $updates ) ) {
        return;
    }

    $email_value = gcp_extract_submission_email( $form_data );
    $subscriber  = gcp_find_fluentcrm_contact_for_sync( $cedula_value, $email_value );

    $profile_updates = array();

    $first_name = gcp_extract_submission_value_for_slug( $form_data, 'first_name', __( 'Nombre', 'gcp-generador-cert' ) );
    if ( ! $first_name ) {
        $first_name = gcp_extract_submission_value_for_slug( $form_data, 'nombres', __( 'Nombre', 'gcp-generador-cert' ) );
    }
    if ( $first_name ) {
        $profile_updates['first_name'] = sanitize_text_field( $first_name );
    }

    $last_name = gcp_extract_submission_value_for_slug( $form_data, 'last_name', __( 'Apellido', 'gcp-generador-cert' ) );
    if ( ! $last_name ) {
        $last_name = gcp_extract_submission_value_for_slug( $form_data, 'apellidos', __( 'Apellido', 'gcp-generador-cert' ) );
    }
    if ( $last_name ) {
        $profile_updates['last_name'] = sanitize_text_field( $last_name );
    }

    if ( ! empty( $updates['telefono'] ) ) {
        $profile_updates['phone'] = $updates['telefono'];
    }

    if ( $subscriber && $email_value && is_email( $email_value ) && $email_value !== $subscriber->email ) {
        $profile_updates['email'] = sanitize_email( $email_value );
    }

    if ( ! $subscriber && $email_value ) {
        $creation_payload = array(
            'email'  => $email_value,
            'status' => 'subscribed',
        );

        if ( isset( $profile_updates['first_name'] ) ) {
            $creation_payload['first_name'] = $profile_updates['first_name'];
        }

        if ( isset( $profile_updates['last_name'] ) ) {
            $creation_payload['last_name'] = $profile_updates['last_name'];
        }

        if ( isset( $profile_updates['phone'] ) ) {
            $creation_payload['phone'] = $profile_updates['phone'];
        }

        try {
            $subscriber = Subscriber::updateOrCreate(
                array( 'email' => $email_value ),
                $creation_payload
            );
        } catch ( Exception $e ) {
            error_log( 'GCP Plugin - Error al crear/actualizar el contacto de FluentCRM: ' . $e->getMessage() );
            $subscriber = null;
        }
    }

    if ( ! $subscriber ) {
        return;
    }

    gcp_update_fluentcrm_custom_values( intval( $subscriber->id ), $updates );

    if ( ! empty( $profile_updates ) ) {
        try {
            foreach ( $profile_updates as $prop => $value ) {
                $subscriber->{$prop} = $value;
            }
            $subscriber->save();
        } catch ( Exception $e ) {
            error_log( 'GCP Plugin - Error al actualizar el contacto de FluentCRM: ' . $e->getMessage() );
        }
    }
}

add_action( 'admin_init', 'gcp_handle_export_estudiantes' );

/**
 * Allow exporting the students table as CSV.
 */
function gcp_handle_export_estudiantes() {
    if ( empty( $_GET['page'] ) || 'gcp_estudiantes_inscritos' !== $_GET['page'] ) {
        return;
    }

    if ( empty( $_GET['gcp_export'] ) || 'csv' !== $_GET['gcp_export'] ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'gcp_export_students' ) ) {
        wp_die( __( 'Error de seguridad: Nonce inválido.', 'gcp-generador-cert' ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_contact_verifications';

    $search_cedula = isset( $_GET['s_cedula'] ) ? sanitize_text_field( wp_unslash( $_GET['s_cedula'] ) ) : '';
    $search_nit    = isset( $_GET['s_nit'] ) ? sanitize_text_field( wp_unslash( $_GET['s_nit'] ) ) : '';

    $sql    = "SELECT id, cedula_alumno, fluentcrm_contact_id, first_name, last_name, email, course_name, etapa_del_curso, nit_empresa, nombre_empresa, date_verified FROM {$table_name}";
    $where  = array();
    $params = array();

    if ( ! empty( $search_cedula ) ) {
        $where[]  = 'cedula_alumno = %s';
        $params[] = $search_cedula;
    }

    if ( ! empty( $search_nit ) ) {
        $where[]  = 'nit_empresa = %s';
        $params[] = $search_nit;
    }

    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }

    $sql .= ' ORDER BY date_verified DESC';

    if ( ! empty( $params ) ) {
        $registros = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    } else {
        $registros = $wpdb->get_results( $sql );
    }

    $custom_field_labels  = gcp_get_all_fluentcrm_custom_field_labels();
    $registros            = gcp_attach_custom_fields_to_student_records( $registros, $custom_field_labels );

    $filename = 'estudiantes-inscritos-' . gmdate( 'Ymd-His' ) . '.csv';

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $output = fopen( 'php://output', 'w' );

    $csv_headers = array( 'ID', 'Cedula', 'Nombre', 'Apellido', 'Email', 'Curso', 'Etapa', 'Nombre Empresa', 'NIT Empresa', 'Fecha Inscripcion' );

    if ( ! empty( $custom_field_labels ) ) {
        foreach ( $custom_field_labels as $label ) {
            $csv_headers[] = $label;
        }
    }

    fputcsv( $output, $csv_headers );

    if ( ! empty( $registros ) ) {
        foreach ( $registros as $reg ) {
            $date = ! empty( $reg->date_verified ) ? mysql2date( 'Y-m-d H:i:s', $reg->date_verified ) : '';
            $row = array(
                $reg->id,
                $reg->cedula_alumno,
                $reg->first_name,
                $reg->last_name,
                $reg->email,
                $reg->course_name,
                $reg->etapa_del_curso,
                $reg->nombre_empresa,
                $reg->nit_empresa,
                $date,
            );

            if ( ! empty( $custom_field_labels ) ) {
                foreach ( $custom_field_labels as $slug => $label ) {
                    $row[] = isset( $reg->custom_fields[ $slug ] ) ? $reg->custom_fields[ $slug ] : '';
                }
            }

            fputcsv( $output, $row );
        }
    }

    fclose( $output );
    exit;
}

/**
 * Renderiza la página que muestra el historial de verificaciones de estudiantes.
 */
function gcp_render_estudiantes_inscritos_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_contact_verifications';

    $search_cedula = isset( $_GET['s_cedula'] ) ? sanitize_text_field( trim( $_GET['s_cedula'] ) ) : '';
    $search_nit    = isset( $_GET['s_nit'] ) ? sanitize_text_field( trim( $_GET['s_nit'] ) ) : '';

    $sql    = "SELECT id, cedula_alumno, fluentcrm_contact_id, first_name, last_name, email, course_name, etapa_del_curso, nit_empresa, nombre_empresa, date_verified FROM {$table_name}";
    $where  = array();
    $params = array();

    if ( ! empty( $search_cedula ) ) {
        $where[]  = 'cedula_alumno = %s';
        $params[] = $search_cedula;
    }
    if ( ! empty( $search_nit ) ) {
        $where[]  = 'nit_empresa = %s';
        $params[] = $search_nit;
    }

    if ( ! empty( $where ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where );
    }
    $sql .= ' ORDER BY date_verified DESC';

    if ( ! empty( $params ) ) {
        $registros = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    } else {
        $registros = $wpdb->get_results( $sql );
    }

    $custom_field_groups  = gcp_get_fluentcrm_custom_field_groups();
    $custom_field_labels  = gcp_get_all_fluentcrm_custom_field_labels();
    $registros            = gcp_attach_custom_fields_to_student_records( $registros, $custom_field_labels );

    $current_edit_id = isset( $_GET['edit_id'] ) ? intval( $_GET['edit_id'] ) : 0;
    if ( ! $current_edit_id && ! empty( $_POST['gcp_record_id'] ) ) {
        $current_edit_id = intval( $_POST['gcp_record_id'] );
    }

    $base_query_args = array(
        'page' => 'gcp_estudiantes_inscritos',
    );

    if ( ! empty( $search_cedula ) ) {
        $base_query_args['s_cedula'] = $search_cedula;
    }
    if ( ! empty( $search_nit ) ) {
        $base_query_args['s_nit'] = $search_nit;
    }

    $export_url = add_query_arg(
        array(
            'page'       => 'gcp_estudiantes_inscritos',
            's_cedula'   => $search_cedula,
            's_nit'      => $search_nit,
            'gcp_export' => 'csv',
            '_wpnonce'   => wp_create_nonce( 'gcp_export_students' ),
        ),
        admin_url( 'admin.php' )
    );
    ?>
    <div class="wrap">
        <h1><?php _e( 'Estudiantes Inscritos', 'gcp-generador-cert' ); ?></h1>

        <div class="notice notice-warning inline">
            <p><strong><?php _e( 'Advertencia:', 'gcp-generador-cert' ); ?></strong> <?php _e( 'Los cambios que realices aquí modificarán la base de datos. Asegúrate de verificar los datos antes de guardar.', 'gcp-generador-cert' ); ?></p>
        </div>

        <?php settings_errors( 'gcp_students' ); ?>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="gcp_estudiantes_inscritos">
            <p class="search-box">
                <label class="screen-reader-text" for="gcp-search-cedula"><?php _e( 'Buscar Cédula', 'gcp-generador-cert' ); ?></label>
                <input type="search" id="gcp-search-cedula" name="s_cedula" value="<?php echo esc_attr( $search_cedula ); ?>" placeholder="<?php _e( 'Ingrese Cédula', 'gcp-generador-cert' ); ?>">

                <label class="screen-reader-text" for="gcp-search-nit"><?php _e( 'Buscar NIT', 'gcp-generador-cert' ); ?></label>
                <input type="search" id="gcp-search-nit" name="s_nit" value="<?php echo esc_attr( $search_nit ); ?>" placeholder="<?php _e( 'Ingrese NIT', 'gcp-generador-cert' ); ?>">

                <input type="submit" id="search-submit" class="button" value="<?php _e( 'Buscar', 'gcp-generador-cert' ); ?>">
                <?php if ( ! empty( $search_cedula ) || ! empty( $search_nit ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gcp_estudiantes_inscritos' ) ); ?>" class="button" style="margin-left:5px;">
                        <?php _e( 'Mostrar Todos', 'gcp-generador-cert' ); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary" style="margin-left:5px;">
                    <?php _e( 'Exportar CSV', 'gcp-generador-cert' ); ?>
                </a>
            </p>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e( 'Cédula', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Nombre Alumno', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Curso', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Etapa del curso', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Nombre Empresa', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'NIT Empresa', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Fecha de inscripción', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Acciones', 'gcp-generador-cert' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $registros ) ) : ?>
                    <?php foreach ( $registros as $reg ) : ?>
                        <?php
                        $is_open          = ( intval( $reg->id ) === $current_edit_id );
                        $edit_query_args  = $base_query_args;
                        $edit_query_args['edit_id'] = $reg->id;
                        $edit_url         = add_query_arg( $edit_query_args, admin_url( 'admin.php' ) );
                        $cancel_url       = add_query_arg( $base_query_args, admin_url( 'admin.php' ) );
                        $aria_expanded    = $is_open ? 'true' : 'false';
                        $edit_row_classes = 'gcp-student-edit-row' . ( $is_open ? ' is-open' : '' );
                        $edit_row_attrs   = $is_open ? ' aria-hidden="false"' : ' aria-hidden="true" hidden';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $reg->cedula_alumno ); ?></td>
                            <td><?php echo esc_html( trim( $reg->first_name . ' ' . $reg->last_name ) ); ?></td>
                            <td><?php echo esc_html( $reg->course_name ); ?></td>
                            <td><?php echo esc_html( $reg->etapa_del_curso ); ?></td>
                            <td><?php echo esc_html( $reg->nombre_empresa ); ?></td>
                            <td><?php echo esc_html( $reg->nit_empresa ); ?></td>
                            <?php
                            $date_display = '';
                            if ( ! empty( $reg->date_verified ) ) {
                                $timestamp = strtotime( $reg->date_verified );
                                if ( false !== $timestamp ) {
                                    $date_display = date_i18n( get_option( 'date_format' ), $timestamp );
                                }
                            }
                            ?>
                            <td><?php echo esc_html( $date_display ); ?></td>
                            <td>
                                <a
                                    class="button gcp-toggle-edit"
                                    href="<?php echo esc_url( $edit_url ); ?>"
                                    data-target="gcp-edit-row-<?php echo esc_attr( $reg->id ); ?>"
                                    aria-expanded="<?php echo esc_attr( $aria_expanded ); ?>"
                                    aria-controls="gcp-edit-row-<?php echo esc_attr( $reg->id ); ?>"
                                >
                                    <?php _e( 'Editar', 'gcp-generador-cert' ); ?>
                                </a>
                            </td>
                        </tr>
                        <tr id="gcp-edit-row-<?php echo esc_attr( $reg->id ); ?>" class="<?php echo esc_attr( $edit_row_classes ); ?>"<?php echo $edit_row_attrs; ?>>
                            <td colspan="8">
                                <form method="post" class="gcp-student-edit-form">
                                    <?php wp_nonce_field( 'gcp_edit_verification', 'gcp_edit_verification_nonce' ); ?>
                                    <input type="hidden" name="page" value="gcp_estudiantes_inscritos">
                                    <input type="hidden" name="gcp_record_id" value="<?php echo esc_attr( $reg->id ); ?>">

                                    <p class="description">
                                        <?php _e( 'Confirma que los datos son correctos antes de guardar; esta acción no se puede deshacer fácilmente.', 'gcp-generador-cert' ); ?>
                                    </p>

                                    <div class="gcp-edit-grid">
                                        <div>
                                            <label for="gcp-first-name-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'Nombre', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-first-name-<?php echo esc_attr( $reg->id ); ?>" name="gcp_first_name" value="<?php echo esc_attr( $reg->first_name ); ?>" class="regular-text">
                                        </div>
                                        <div>
                                            <label for="gcp-last-name-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'Apellido', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-last-name-<?php echo esc_attr( $reg->id ); ?>" name="gcp_last_name" value="<?php echo esc_attr( $reg->last_name ); ?>" class="regular-text">
                                        </div>
                                        <div>
                                            <label for="gcp-course-name-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'Curso', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-course-name-<?php echo esc_attr( $reg->id ); ?>" name="gcp_course_name" value="<?php echo esc_attr( $reg->course_name ); ?>" class="regular-text">
                                        </div>
                                        <div>
                                            <label for="gcp-course-stage-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'Etapa del curso', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-course-stage-<?php echo esc_attr( $reg->id ); ?>" name="gcp_etapa_del_curso" value="<?php echo esc_attr( $reg->etapa_del_curso ); ?>" class="regular-text">
                                        </div>
                                        <div>
                                            <label for="gcp-company-name-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'Nombre Empresa', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-company-name-<?php echo esc_attr( $reg->id ); ?>" name="gcp_nombre_empresa" value="<?php echo esc_attr( $reg->nombre_empresa ); ?>" class="regular-text">
                                        </div>
                                        <div>
                                            <label for="gcp-company-nit-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'NIT Empresa', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-company-nit-<?php echo esc_attr( $reg->id ); ?>" name="gcp_nit_empresa" value="<?php echo esc_attr( $reg->nit_empresa ); ?>" class="regular-text">
                                        </div>
                                        <div>
                                            <label for="gcp-date-verified-<?php echo esc_attr( $reg->id ); ?>"><?php _e( 'Fecha de inscripción', 'gcp-generador-cert' ); ?></label>
                                            <input type="text" id="gcp-date-verified-<?php echo esc_attr( $reg->id ); ?>" name="gcp_date_verified" value="<?php echo esc_attr( $reg->date_verified ); ?>" class="regular-text">
                                        </div>
                                    </div>

                                    <?php if ( ! empty( $custom_field_groups ) ) : ?>
                                        <div class="gcp-student-custom-data" aria-live="polite">
                                            <?php foreach ( $custom_field_groups as $group_key => $group_config ) : ?>
                                                <?php
                                                if ( empty( $group_config['fields'] ) || ! is_array( $group_config['fields'] ) ) {
                                                    continue;
                                                }
                                                ?>
                                                <section class="gcp-student-custom-group">
                                                    <h3><?php echo esc_html( $group_config['label'] ); ?></h3>
                                                    <div class="gcp-student-custom-grid">
                                                        <?php foreach ( $group_config['fields'] as $slug => $label ) : ?>
                                                            <?php
                                                            $field_slug   = sanitize_key( $slug );
                                                            $raw_value    = isset( $reg->custom_fields[ $field_slug ] ) ? $reg->custom_fields[ $field_slug ] : '';
                                                            $display_text = is_string( $raw_value ) ? trim( $raw_value ) : '';
                                                            $is_link      = $display_text && filter_var( $display_text, FILTER_VALIDATE_URL );
                                                            ?>
                                                            <div class="gcp-student-custom-item">
                                                                <span class="gcp-student-custom-label"><?php echo esc_html( $label ); ?></span>
                                                                <span class="gcp-student-custom-value">
                                                                    <?php if ( $is_link ) : ?>
                                                                        <a href="<?php echo esc_url( $display_text ); ?>" target="_blank" rel="noopener noreferrer">
                                                                            <?php esc_html_e( 'Abrir enlace', 'gcp-generador-cert' ); ?>
                                                                        </a>
                                                                    <?php else : ?>
                                                                        <?php echo $display_text !== '' ? esc_html( $display_text ) : '&mdash;'; ?>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </section>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <p class="gcp-edit-actions">
                                        <button type="submit" class="button button-primary">
                                            <?php _e( 'Guardar cambios', 'gcp-generador-cert' ); ?>
                                        </button>
                                        <a
                                            href="<?php echo esc_url( $cancel_url ); ?>"
                                            class="button gcp-cancel-edit"
                                            data-target="gcp-edit-row-<?php echo esc_attr( $reg->id ); ?>"
                                            aria-controls="gcp-edit-row-<?php echo esc_attr( $reg->id ); ?>"
                                        >
                                            <?php _e( 'Cancelar', 'gcp-generador-cert' ); ?>
                                        </a>
                                    </p>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="8"><?php _e( 'No hay registros.', 'gcp-generador-cert' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Gestiona la eliminación de un certificado.
 */
function gcp_handle_delete_certificate_action() {
    if ( isset( $_GET['action'], $_GET['cert_id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'gcp_delete_certificate' ) {
        $cert_id = intval( $_GET['cert_id'] );
        $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

        if ( ! wp_verify_nonce( $nonce, 'gcp_delete_certificate_' . $cert_id ) ) {
            wp_die( __( 'Error de seguridad: Nonce inválido.', 'gcp-generador-cert' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No tienes permisos para realizar esta acción.', 'gcp-generador-cert' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'gcp_issued_certificates';

        // Opcional: Obtener datos del certificado para eliminar el archivo físico
        $certificate_to_delete = $wpdb->get_row( $wpdb->prepare( "SELECT certificate_filename, certificate_url FROM $table_name WHERE id = %d", $cert_id ) );

        $deleted = $wpdb->delete( $table_name, array( 'id' => $cert_id ), array( '%d' ) );

        if ( $deleted ) {
            // Opcional: Intentar eliminar el archivo PDF físico
            if ( $certificate_to_delete && ! empty( $certificate_to_delete->certificate_filename ) ) {
                $upload_dir_info = wp_upload_dir();
                $pdf_target_dir = $upload_dir_info['basedir'] . '/certificados-gcp/';
                $file_path = $pdf_target_dir . $certificate_to_delete->certificate_filename;
                if ( file_exists( $file_path ) ) {
                    @unlink( $file_path ); // Usamos @ para suprimir errores si el archivo no se puede eliminar por permisos, etc.
                }
            }
            add_settings_error('gcp_manage_certs', 'cert_deleted_success', __('Certificado eliminado exitosamente.', 'gcp-generador-cert'), 'updated');
        } else {
            add_settings_error('gcp_manage_certs', 'cert_deleted_error', __('Error al eliminar el certificado de la base de datos.', 'gcp-generador-cert'), 'error');
        }
        // Redirigir para limpiar los parámetros GET y mostrar el mensaje
        wp_safe_redirect( remove_query_arg( array( 'action', 'cert_id', '_wpnonce' ), wp_get_referer() ) );
        exit;
    }
}
add_action( 'admin_init', 'gcp_handle_delete_certificate_action' ); // admin_init es un buen hook para manejar acciones


/**
 * Renderiza el contenido de la página de administración de certificados.
 */
function gcp_render_administrar_certificados_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'gcp_issued_certificates';

    $search_cedula = isset( $_GET['s_cedula'] ) ? sanitize_text_field( trim( $_GET['s_cedula'] ) ) : '';

    // Preparar consulta SQL
    $sql = "SELECT id, cedula_alumno, course_name, certificate_filename, certificate_url, date_issued, validation_id FROM {$table_name}";
    $params = array();

    if ( ! empty( $search_cedula ) ) {
        $sql .= " WHERE cedula_alumno = %s";
        $params[] = $search_cedula;
    }
    $sql .= " ORDER BY date_issued DESC";

    if ( ! empty( $params ) ) {
        $certificates = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    } else {
        $certificates = $wpdb->get_results( $sql );
    }
    ?>
    <div class="wrap">
        <h1><?php _e( 'Administrar Certificados Emitidos', 'gcp-generador-cert' ); ?></h1>
        
        <?php settings_errors('gcp_manage_certs'); // Muestra los mensajes de éxito/error ?>

        <form method="get">
            <input type="hidden" name="page" value="gcp_administrar_certificados">
            <p class="search-box">
                <label class="screen-reader-text" for="gcp-cedula-search-input"><?php _e( 'Buscar por Cédula:', 'gcp-generador-cert' ); ?></label>
                <input type="search" id="gcp-cedula-search-input" name="s_cedula" value="<?php echo esc_attr( $search_cedula ); ?>" placeholder="<?php _e( 'Ingrese Cédula', 'gcp-generador-cert' ); ?>">
                <input type="submit" id="search-submit" class="button" value="<?php _e( 'Buscar Cédula', 'gcp-generador-cert' ); ?>">
                <?php if ( ! empty( $search_cedula ) ) : ?>
                    <a href="<?php echo esc_url( admin_url('admin.php?page=gcp_administrar_certificados') ); ?>" class="button" style="margin-left: 5px;"><?php _e( 'Mostrar Todos', 'gcp-generador-cert' ); ?></a>
                <?php endif; ?>
            </p>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php _e( 'Cédula Alumno', 'gcp-generador-cert' ); ?></th>
                    <th scope="col"><?php _e( 'Curso', 'gcp-generador-cert' ); ?></th>
                    <th scope="col"><?php _e( 'Fecha Emisión', 'gcp-generador-cert' ); ?></th>
                    <th scope="col"><?php _e( 'ID Validación', 'gcp-generador-cert' ); ?></th>
                    <th scope="col"><?php _e( 'Archivo', 'gcp-generador-cert' ); ?></th>
                    <th scope="col"><?php _e( 'Acciones', 'gcp-generador-cert' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $certificates ) ) : ?>
                    <?php foreach ( $certificates as $cert ) : ?>
                        <tr>
                            <td><?php echo esc_html( $cert->cedula_alumno ); ?></td>
                            <td><?php echo esc_html( $cert->course_name ); ?></td>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $cert->date_issued ) ) ); ?></td>
                            <td><?php echo esc_html( $cert->validation_id ? $cert->validation_id : 'N/A' ); ?></td>
                            <td>
                                <?php if ( ! empty( $cert->certificate_url ) ) : ?>
                                    <a href="<?php echo esc_url( $cert->certificate_url ); ?>" target="_blank">
                                        <?php echo esc_html( $cert->certificate_filename ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php _e( 'No disponible', 'gcp-generador-cert' ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $delete_link = add_query_arg( array(
                                    'action'  => 'gcp_delete_certificate',
                                    'cert_id' => $cert->id,
                                    '_wpnonce'=> wp_create_nonce( 'gcp_delete_certificate_' . $cert->id )
                                ), admin_url( 'admin.php?page=gcp_administrar_certificados' ) ); // Redirige a la misma página admin tras la acción
                                ?>
                                <a href="<?php echo esc_url( $delete_link ); ?>" 
                                   onclick="return confirm('<?php esc_attr_e( '¿Estás seguro de que deseas eliminar este certificado? Esta acción no se puede deshacer.', 'gcp-generador-cert' ); ?>');"
                                   style="color: #a00;"><?php _e( 'Eliminar', 'gcp-generador-cert' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6"><?php _e( 'No se encontraron certificados.', 'gcp-generador-cert' ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Render the trainers management page.
 */
function gcp_render_trainers_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $edit_id = isset( $_GET['trainer_id'] ) ? intval( $_GET['trainer_id'] ) : 0;

    if ( isset( $_POST['gcp_trainer_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gcp_trainer_nonce'] ) ), 'gcp_save_trainer' ) ) {
        $name          = sanitize_text_field( wp_unslash( $_POST['trainer_name'] ) );
        $license       = sanitize_text_field( wp_unslash( $_POST['trainer_license'] ) );
        $signature_url = esc_url_raw( wp_unslash( $_POST['trainer_signature'] ) );
        $trainer_id    = isset( $_POST['trainer_id'] ) ? intval( $_POST['trainer_id'] ) : 0;

        if ( $trainer_id ) {
            wp_update_post( array( 'ID' => $trainer_id, 'post_title' => $name ) );
        } else {
            $trainer_id = wp_insert_post( array(
                'post_type'   => 'gcp_trainer',
                'post_status' => 'publish',
                'post_title'  => $name,
            ) );
        }

        if ( $trainer_id ) {
            update_post_meta( $trainer_id, 'gcp_trainer_license', $license );
            update_post_meta( $trainer_id, 'gcp_trainer_signature_url', $signature_url );
            add_settings_error( 'gcp_trainers', 'trainer_saved', __( 'Instructor guardado correctamente.', 'gcp-generador-cert' ), 'updated' );
            $edit_id = 0;
        } else {
            add_settings_error( 'gcp_trainers', 'trainer_error', __( 'Error al guardar el instructor.', 'gcp-generador-cert' ), 'error' );
        }
    }

    if ( isset( $_GET['action'], $_GET['trainer_id'], $_GET['_wpnonce'] ) && 'delete' === $_GET['action'] ) {
        $del_id = intval( $_GET['trainer_id'] );
        if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'gcp_delete_trainer_' . $del_id ) ) {
            wp_delete_post( $del_id, true );
            add_settings_error( 'gcp_trainers', 'trainer_deleted', __( 'Instructor eliminado.', 'gcp-generador-cert' ), 'updated' );
            wp_safe_redirect( remove_query_arg( array( 'action', 'trainer_id', '_wpnonce' ) ) );
            exit;
        }
    }

    $trainer_name      = '';
    $trainer_license   = '';
    $trainer_signature = '';

    if ( $edit_id ) {
        $trainer = get_post( $edit_id );
        if ( $trainer && 'gcp_trainer' === $trainer->post_type ) {
            $trainer_name      = $trainer->post_title;
            $trainer_license   = get_post_meta( $edit_id, 'gcp_trainer_license', true );
            $trainer_signature = get_post_meta( $edit_id, 'gcp_trainer_signature_url', true );
        } else {
            $edit_id = 0;
        }
    }

    $trainers = get_posts( array(
        'post_type'   => 'gcp_trainer',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby'     => 'title',
        'order'       => 'ASC',
    ) );

    ?>
    <div class="wrap">
        <h1><?php _e( 'Instructores', 'gcp-generador-cert' ); ?></h1>
        <?php settings_errors( 'gcp_trainers' ); ?>
        <form method="post">
            <?php wp_nonce_field( 'gcp_save_trainer', 'gcp_trainer_nonce' ); ?>
            <input type="hidden" name="trainer_id" value="<?php echo esc_attr( $edit_id ); ?>">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="trainer_name"><?php _e( 'Nombre', 'gcp-generador-cert' ); ?></label></th>
                    <td><input type="text" id="trainer_name" name="trainer_name" class="regular-text" value="<?php echo esc_attr( $trainer_name ); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="trainer_license"><?php _e( 'Licencia', 'gcp-generador-cert' ); ?></label></th>
                    <td><input type="text" id="trainer_license" name="trainer_license" class="regular-text" value="<?php echo esc_attr( $trainer_license ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="trainer_signature"><?php _e( 'URL de Firma', 'gcp-generador-cert' ); ?></label></th>
                    <td><input type="text" id="trainer_signature" name="trainer_signature" class="regular-text" value="<?php echo esc_attr( $trainer_signature ); ?>"></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button button-primary" value="<?php echo $edit_id ? esc_attr__( 'Guardar', 'gcp-generador-cert' ) : esc_attr__( 'Agregar', 'gcp-generador-cert' ); ?>">
                <?php if ( $edit_id ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( 'trainer_id' ) ); ?>" class="button"><?php _e( 'Cancelar', 'gcp-generador-cert' ); ?></a>
                <?php endif; ?>
            </p>
        </form>

        <h2><?php _e( 'Lista de Instructores', 'gcp-generador-cert' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e( 'Nombre', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Licencia', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Firma', 'gcp-generador-cert' ); ?></th>
                    <th><?php _e( 'Acciones', 'gcp-generador-cert' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( $trainers ) : foreach ( $trainers as $trainer_item ) : ?>
                    <?php
                    $lic  = get_post_meta( $trainer_item->ID, 'gcp_trainer_license', true );
                    $sign = get_post_meta( $trainer_item->ID, 'gcp_trainer_signature_url', true );
                    $edit_link   = add_query_arg( array( 'trainer_id' => $trainer_item->ID ), menu_page_url( 'gcp_trainers', false ) );
                    $delete_link = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'trainer_id' => $trainer_item->ID ), menu_page_url( 'gcp_trainers', false ) ), 'gcp_delete_trainer_' . $trainer_item->ID );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $trainer_item->post_title ); ?></td>
                        <td><?php echo esc_html( $lic ); ?></td>
                        <td><?php echo esc_html( $sign ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $edit_link ); ?>"><?php _e( 'Editar', 'gcp-generador-cert' ); ?></a> |
                            <a href="<?php echo esc_url( $delete_link ); ?>" onclick="return confirm('<?php esc_attr_e( '¿Borrar instructor?', 'gcp-generador-cert' ); ?>');"><?php _e( 'Eliminar', 'gcp-generador-cert' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="4"><?php _e( 'No hay instructores.', 'gcp-generador-cert' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

?>