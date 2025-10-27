jQuery(document).ready(function($) {
    $('#gcp-student-certs-form').on('submit', function(e) {
        e.preventDefault();
        var cedula = $('#gcp_student_cedula').val().trim();
        var resultsDiv = $('#gcp-student-certs-results');
        var loadingDiv = $('#gcp-student-certs-loading');
        var errorDiv = $('#gcp-student-certs-error');

        resultsDiv.html(''); // Limpiar resultados anteriores
        errorDiv.hide().text(''); // Limpiar errores anteriores
        loadingDiv.show();

        if (!cedula) {
            errorDiv.text('Por favor, ingresa tu cédula.').show();
            loadingDiv.hide();
            return;
        }
        // Validación básica de cédula (opcional, el backend también valida)
        if (!/^\d{5,12}$/.test(cedula)) { // Ejemplo: numérico entre 5 y 12 dígitos
             errorDiv.text('Formato de cédula inválido. Solo números, entre 5 y 12 dígitos.').show();
             loadingDiv.hide();
             return;
        }


        $.ajax({
            url: gcp_student_ajax_obj.ajaxurl,
            type: 'POST',
            data: {
                action: 'gcp_fetch_student_certificates',
                cedula: cedula,
                nonce: gcp_student_ajax_obj.nonce
            },
            dataType: 'json',
            success: function(response) {
                loadingDiv.hide();
                if (response.success && response.data && response.data.length > 0) {
                    var html = '<h4>Certificados Encontrados:</h4>';
                    html += '<div class="gcp-cert-list">';
                    response.data.forEach(function(cert) {
                        html += '<div class="gcp-cert-card">';
                        html += '<p><strong>Curso:</strong> ' + (cert.course_name || 'N/A') + '</p>';
                        html += '<p><strong>Fecha Emisión:</strong> ' + (cert.date_issued_formatted || 'N/A') + '</p>';
                        html += '<p><strong>ID Validación:</strong> ' + (cert.validation_id || '') + '</p>';
                        html += '<p><a href="' + cert.certificate_url + '" target="_blank" download="' + (cert.certificate_filename || 'certificado.pdf') + '" class="button button-primary">Descargar</a></p>';
                        html += '</div>';
                    });
                    html += '</div>';
                    resultsDiv.html(html);
                } else if (response.success && response.data && response.data.length === 0) {
                    resultsDiv.html('<p>No se encontraron certificados para la cédula proporcionada.</p>');
                } else {
                    errorDiv.text(response.data.message || 'Ocurrió un error al buscar los certificados.').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                loadingDiv.hide();
                errorDiv.text('Error de comunicación al intentar buscar certificados. Por favor, intenta más tarde.').show();
                console.error("Error AJAX (student certs):", textStatus, errorThrown, jqXHR.responseText);
            }
        });
    });
});