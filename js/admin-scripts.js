jQuery(function($) {
  'use strict';

  // Cache de selectores
  const $form         = $('#gcp-certificate-form');
  const $inputs       = $form.find('input.regular-text:not(#gcp_cedula)');
  const $selects      = $form.find('select');
  const $trainerSel   = $('#gcp_trainer_id');
  const $cedula       = $('#gcp_cedula');
  const $preview      = $('#gcp-certificate-preview');
  const $previewSpans = $preview.find('span');
  const $pdfContainer = $('#gcp-pdf-link-container');
  const SPINNER_CLASS = 'gcp-spinner';
  const SLUGS = [
    'nombre_del_curso', 'nombre_de_la_empresa_empl', 'nit_de_la_empresa_emplead',
    'estado_de_pago_del_curso', 'id_ministerio_del_curso', 'rut_empresa',
    'cedula_escaneada', 'seguridad_social', 'curso_avanzado_o_trabajad',
    'certificado_sg_sst', 'certificado_de_curso_reen', 'examen_medico_en_alturas',
    'intensidad_horaria', 'fecha_de_realizado', 'fecha_de_expedicion',
    'arl', 'representante_legal_de_la', 'etapa_del_curso',
    '_estado_de_la_documentaci', 'numero_factura', 'nci', 'fecha_de_inicio',
    'tipo_de_documento', 'nombre_de_contacto_de_la_', 'correo_electronico_de_la_',
    'telefono'
  ];

  // Limpia todos los campos y vistas
  function clearUI() {
    $inputs.val('');
    $selects.prop('selectedIndex', 0);
    $preview.hide();
    $previewSpans.text('');
    $pdfContainer.empty();
  }

  // Muestra un spinner único junto al elemento dado
  function showSpinner($el) {
    $('<span>')
      .addClass(`spinner is-active ${SPINNER_CLASS}`)
      .css({
        float: 'none',
        'vertical-align': 'middle',
        'margin-left': '5px'
      })
      .insertAfter($el);
  }

  // Elimina todos los spinners generados
  function removeSpinners() {
    $(`.${SPINNER_CLASS}`).remove();
  }

  // Obtiene con seguridad un campo personalizado
  function getCustomField(contact, slug) {
    if (!contact.custom_fields) return '';
    const direct = contact.custom_fields[slug];
    if (direct && typeof direct.value !== 'undefined') {
      return direct.value;
    }
    // Compatibilidad: algunos slugs pueden usar guiones o guiones bajos
    const dashed = slug.replace(/_/g, '-');
    if (dashed !== slug && contact.custom_fields[dashed]) {
      return contact.custom_fields[dashed].value ?? '';
    }
    const underscored = slug.replace(/-/g, '_');
    if (underscored !== slug && contact.custom_fields[underscored]) {
      return contact.custom_fields[underscored].value ?? '';
    }
    // También intentamos quitar guiones bajos finales, por si acaso
    const trimmed = slug.replace(/_+$/, '');
    if (trimmed !== slug && contact.custom_fields[trimmed]) {
      return contact.custom_fields[trimmed].value ?? '';
    }
    return '';
  }

  // Al cargar la página
  clearUI();

  // 1) Búsqueda de contacto por cédula
  $cedula.on('blur', function() {
    const val = $(this).val().trim();
    clearUI();

    if (!val) return;
    if (val.length <= 3) {
      return alert('Por favor, ingresa más de 3 caracteres para buscar.');
    }

    const nonce = $('#gcp_nonce').val();
    if (!nonce) {
      console.error('Nonce no encontrado.');
      return alert('Error de seguridad.');
    }

    showSpinner($cedula);

    $.post(ajaxurl, {
      action: 'gcp_buscar_contacto_por_cedula',
      cedula: val,
      nonce: nonce
    }, 'json')
    .done(function(resp) {
      removeSpinners();
      if (!resp.success || !resp.data) {
        const msg = resp.data?.message || 'No se encontró el contacto.';
        return alert(msg);
      }

      const c = resp.data;
      $('#gcp_nombre_completo').val(`${c.first_name || ''} ${c.last_name || ''}`.trim());
      $('#gcp_email').val(c.email || '');

      SLUGS.forEach(slug => {
        $(`#gcp_${slug}`).val(getCustomField(c, slug));
      });
    })
    .fail(function(jqXHR, textStatus) {
      removeSpinners();
      console.error('Error AJAX (buscar contacto):', textStatus, jqXHR);
      alert('Error de comunicación al buscar el contacto.');
    });
  });

  // 2) Generar vista previa
  $('#gcp-generate-preview-button').on('click', function() {
    const data = {
      nombre: $('#gcp_nombre_completo').val(),
      email: $('#gcp_email').val(),
      cedula_display: $cedula.val(),
      curso: $('#gcp_nombre_del_curso').val()
    };

    const trainerName = $trainerSel.find('option:selected').text();
    $('#preview_trainer').text(trainerName);

    if (!data.nombre && !data.cedula_display) {
      return alert('Por favor, busca primero un contacto.');
    }
    if (!data.curso) {
      alert('Advertencia: el nombre del curso está vacío.');
    }

    Object.entries(data).forEach(([key, val]) => {
      $(`#preview_${key}`).text(val);
    });
    SLUGS.forEach(slug => {
      $(`#preview_${slug}`).text($(`#gcp_${slug}`).val());
    });
    $preview.show();
  });

  // 3) Registrar verificación
  $('#gcp-register-verification-button').on('click', function() {
    const val = $cedula.val().trim();
    if (!val) {
      return alert('Por favor, ingresa una cédula.');
    }

    showSpinner($pdfContainer);
    const nonce = $('#gcp_nonce').val();
    if (!nonce) {
      removeSpinners();
      return alert('Error de seguridad.');
    }

    $.post(ajaxurl, {
      action: 'gcp_guardar_verificacion_registro',
      nonce: nonce,
      cedula: val
    }, 'json')
    .done(function(resp) {
      removeSpinners();
      const cls = resp.success ? 'notice-success' : 'notice-error';
      const msg = resp.data?.message || (resp.success ? 'Verificación registrada.' : 'Error al registrar.');
      $pdfContainer.html(`<div class="notice ${cls} is-dismissible"><p>${msg}</p></div>`);
    })
    .fail(function() {
      removeSpinners();
      $pdfContainer.html('<div class="notice notice-error is-dismissible"><p>Error de comunicación al registrar.</p></div>');
    });
  });

  // 4) Generar PDF real
  $('#gcp-generate-real-pdf-button').on('click', function() {
    $pdfContainer.html('Generando PDF, por favor espera…');
    showSpinner($pdfContainer);

    const formData = {
      action: 'gcp_generar_certificado_pdf',
      nonce: $('#gcp_nonce').val()
    };
    $form.serializeArray().forEach(obj => {
      if (obj.name && obj.name.startsWith('gcp_')) {
        formData[obj.name] = obj.value;
      }
    });
    formData.gcp_trainer_id = $trainerSel.val();

    if (!formData.gcp_nombre_completo || !formData.gcp_cedula) {
      removeSpinners();
      $pdfContainer.empty();
      return alert('Busca un contacto y asegura cargar datos.');
    }
    if (!formData.gcp_nombre_del_curso) {
      alert('Advertencia: el nombre del curso está vacío.');
    }

    $.ajax({
      url: ajaxurl,
      method: 'POST',
      dataType: 'json',
      data: formData
    })
    .done(function(resp) {
      removeSpinners();
      if (resp.success && resp.data?.pdf_url) {
        const fn = resp.data.file_name || 'certificado.pdf';
        $pdfContainer.html(`
          <p>PDF generado: 
            <a href="${resp.data.pdf_url}" download="${fn}" target="_blank" class="button">
              Descargar/Ver
            </a>
          </p>
        `);
      } else {
        const msg = resp.data?.message || 'No se recibió URL de PDF.';
        $pdfContainer.html(`<p style="color:orange;">${msg}</p>`);
        console.error('Generación PDF:', resp);
      }
    })
    .fail(function(jqXHR, textStatus) {
      removeSpinners();
      $pdfContainer.html('<p style="color:red;">Error al generar PDF.</p>');
      console.error('Error AJAX (generar PDF):', textStatus, jqXHR);
    });
  });

});