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
  const $verificationNotice = $('#gcp-verification-notice');
  const ajaxStrings = typeof gcp_ajax_obj === 'undefined' ? {} : gcp_ajax_obj;
  const AJAX_URL = typeof ajaxurl !== 'undefined'
    ? ajaxurl
    : (ajaxStrings.ajaxurl || '');
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

  function clearNotice($target) {
    if ($target && $target.length) {
      $target.empty();
    }
  }

  function showNotice($target, messages, isSuccess = true) {
    if (!messages || !messages.length) {
      return;
    }

    if (!$target || !$target.length) {
      alert(messages.join('\n'));
      return;
    }

    const $notice = $('<div>')
      .addClass(`notice ${isSuccess ? 'notice-success' : 'notice-error'} is-dismissible`);

    messages.forEach(msg => {
      $notice.append($('<p>').text(msg));
    });

    $target.empty().append($notice);
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

    if (!AJAX_URL) {
      console.error('URL de AJAX no disponible.');
      return alert('Error de configuración. Contacta al administrador.');
    }

    const nonce = $('#gcp_nonce').val();
    if (!nonce) {
      console.error('Nonce no encontrado.');
      return alert('Error de seguridad.');
    }

    showSpinner($cedula);

    $.post(AJAX_URL, {
      action: 'gcp_buscar_contacto_por_cedula',
      cedula: val,
      nonce: nonce
    }, 'json')
    .done(function(resp) {
      removeSpinners();
      const data = resp && resp.data ? resp.data : null;
      if (!resp.success || !data) {
        const msg = data && data.message ? data.message : 'No se encontró el contacto.';
        return alert(msg);
      }

      const c = data;
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
    const $noticeTarget = $verificationNotice.length ? $verificationNotice : $pdfContainer;
    const val = $cedula.val().trim();
    clearNotice($noticeTarget);

    if (!val) {
      const missingMsg = ajaxStrings.verificationMissingCedula || 'Por favor, ingresa una cédula.';
      showNotice($noticeTarget, [missingMsg], false);
      return;
    }

    const $spinnerAnchor = $noticeTarget.length ? $noticeTarget : $cedula;
    if (!AJAX_URL) {
      showNotice($noticeTarget, ['No se pudo determinar la URL de AJAX.'], false);
      return;
    }
    showSpinner($spinnerAnchor);
    const nonce = $('#gcp_nonce').val();
    if (!nonce) {
      removeSpinners();
      showNotice($noticeTarget, ['Error de seguridad.'], false);
      return;
    }

    $.post(AJAX_URL, {
      action: 'gcp_guardar_verificacion_registro',
      nonce: nonce,
      cedula: val
    }, 'json')
    .done(function(resp) {
      removeSpinners();
      const data = resp && resp.data ? resp.data : null;
      if (resp.success) {
        const successMsg = ajaxStrings.verificationSuccess || 'Verificación Exitosa';
        const messages = [successMsg];
        if (data && data.message && data.message !== successMsg) {
          messages.push(data.message);
        }
        showNotice($noticeTarget, messages, true);
      } else {
        const fallbackMsg = data && data.message ? data.message : (ajaxStrings.verificationError || 'Error al registrar.');
        showNotice($noticeTarget, [fallbackMsg], false);
      }
    })
    .fail(function() {
      removeSpinners();
      const fallback = ajaxStrings.verificationCommError || 'Error de comunicación al registrar.';
      showNotice($noticeTarget, [fallback], false);
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

    if (!AJAX_URL) {
      removeSpinners();
      $pdfContainer.html('<p style="color:red;">No se pudo determinar la URL de AJAX.</p>');
      return;
    }

    $.ajax({
      url: AJAX_URL,
      method: 'POST',
      dataType: 'json',
      data: formData
    })
    .done(function(resp) {
      removeSpinners();
      const data = resp && resp.data ? resp.data : null;
      if (resp.success && data && data.pdf_url) {
        const fn = data.file_name || 'certificado.pdf';
        $pdfContainer.html(`
          <p>PDF generado:
            <a href="${data.pdf_url}" download="${fn}" target="_blank" class="button">
              Descargar/Ver
            </a>
          </p>
        `);
      } else {
        const msg = data && data.message ? data.message : 'No se recibió URL de PDF.';
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

  // 5) Gestión del módulo de estudiantes inscritos
  function setEditRowState($row, isOpen) {
    $row.toggleClass('is-open', isOpen);
    $row.attr('aria-hidden', isOpen ? 'false' : 'true');
    if (isOpen) {
      $row.prop('hidden', false).removeAttr('hidden');
      $row.css('display', 'table-row');
    } else {
      $row.css('display', 'none');
      $row.prop('hidden', true).attr('hidden', 'hidden');
    }
  }

  function updateEditQueryParam(recordId) {
    if (!window.history || !window.history.replaceState) {
      return;
    }

    var href = window.location.href;
    var hashIndex = href.indexOf('#');
    var hash = '';
    if (hashIndex !== -1) {
      hash = href.substring(hashIndex);
      href = href.substring(0, hashIndex);
    }

    if (typeof URLSearchParams !== 'undefined') {
      var urlParts = href.split('?');
      var base = urlParts[0];
      var params = new URLSearchParams(urlParts[1] || '');
      if (recordId) {
        params.set('edit_id', recordId);
      } else {
        params.delete('edit_id');
      }
      var query = params.toString();
      var newHref = query ? base + '?' + query : base;
      window.history.replaceState({}, '', newHref + hash);
      return;
    }

    var queryIndex = href.indexOf('?');
    var baseHref = queryIndex !== -1 ? href.substring(0, queryIndex) : href;
    var search = queryIndex !== -1 ? href.substring(queryIndex + 1) : '';
    var segments = search ? search.split('&') : [];
    var key = 'edit_id=';
    var replaced = false;
    var cleaned = [];

    for (var i = 0; i < segments.length; i += 1) {
      if (segments[i].indexOf(key) === 0) {
        if (recordId) {
          cleaned.push(key + encodeURIComponent(recordId));
        }
        replaced = true;
      } else if (segments[i]) {
        cleaned.push(segments[i]);
      }
    }

    if (!replaced && recordId) {
      cleaned.push(key + encodeURIComponent(recordId));
    }

    var newUrl = baseHref;
    if (cleaned.length) {
      newUrl += '?' + cleaned.join('&');
    }
    window.history.replaceState({}, '', newUrl + hash);
  }

  $(document).on('click', '.gcp-toggle-edit', function(e) {
    const $button = $(this);
    const targetId = $button.data('target');
    if (!targetId) {
      return;
    }

    const $row = $(`#${targetId}`);
    if (!$row.length) {
      return;
    }

    e.preventDefault();

    const willOpen = !$row.hasClass('is-open');

    // Close any other open rows to keep the interface tidy.
    $('.gcp-student-edit-row.is-open').not($row).each(function() {
      const $other = $(this);
      setEditRowState($other, false);
      const otherId = $other.attr('id');
      $(`.gcp-toggle-edit[data-target="${otherId}"]`).attr('aria-expanded', 'false');
    });

    setEditRowState($row, willOpen);
    $button.attr('aria-expanded', willOpen ? 'true' : 'false');

    if (willOpen) {
      const idFragment = targetId.replace('gcp-edit-row-', '');
      updateEditQueryParam(idFragment);
      const $firstInput = $row.find('input, select, textarea').filter(':visible').first();
      if ($firstInput.length) {
        setTimeout(function() {
          $firstInput.trigger('focus');
        }, 0);
      }
    } else {
      updateEditQueryParam('');
    }
  });

  $(document).on('click', '.gcp-cancel-edit', function(e) {
    const $link = $(this);
    const targetId = $link.data('target');
    if (!targetId) {
      return;
    }

    const $row = $(`#${targetId}`);
    if (!$row.length) {
      return;
    }

    e.preventDefault();
    setEditRowState($row, false);
    updateEditQueryParam('');
    $(`.gcp-toggle-edit[data-target="${targetId}"]`).attr('aria-expanded', 'false').trigger('focus');
  });

  $(document).on('submit', '.gcp-student-edit-form', function(e) {
    const confirmMessage = '¿Estás seguro de que deseas guardar estos cambios? Se actualizará el registro en la base de datos.';
    if (!window.confirm(confirmMessage)) {
      e.preventDefault();
    }
  });

});
