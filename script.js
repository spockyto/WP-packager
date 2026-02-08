jQuery(document).ready(function ($) {
    // Manejar Marcar/Desmarcar Todos
    $('#wpp-select-all').on('change', function () {
        $('.wpp-plugin-select').prop('checked', $(this).prop('checked'));
    });

    $('#wpp-start').on('click', function () {
        let items = $('.wpp-item').filter(function () {
            return $(this).find('.wpp-plugin-select').is(':checked');
        });

        let button = $(this);

        if (items.length === 0) {
            alert(wpp_vars.select_at_least_one);
            return;
        }

        button.prop('disabled', true).text(wpp_vars.installing);
        $('.wpp-plugin-select, #wpp-select-all').prop('disabled', true);
        $('#wpp-log').empty().append('<div>' + wpp_vars.starting + items.length + wpp_vars.selected_plugins + '</div>');

        processPlugin(0);

        function processPlugin(index) {
            if (index >= items.length) {
                button.text(wpp_vars.finished);
                $('#wpp-log').append('<div>' + wpp_vars.processed + '</div>');
                return;
            }

            let item = $(items[index]);
            let slug = item.data('slug');
            let status = item.find('.wpp-status');
            let activate = $('#wpp-auto-activate').is(':checked') ? 1 : 0;

            status.removeClass('status-waiting').addClass('status-working').text(wpp_vars.status_installing);
            $('#wpp-log').append('<div>' + wpp_vars.processing + slug + '...</div>');

            $.post(wpp_vars.ajax_url, {
                action: 'wpp_install_plugin',
                slug: slug,
                activate: activate,
                nonce: wpp_vars.nonce
            }, function (response) {
                if (response.success) {
                    status.removeClass('status-working').addClass('status-success').text(wpp_vars.completed);
                    $('#wpp-log').append('<div style="color:green">✅ ' + slug + ': ' + response.data + '</div>');
                } else {
                    status.removeClass('status-working').addClass('status-error').text(wpp_vars.error);
                    $('#wpp-log').append('<div style="color:red">❌ ' + slug + ': ' + response.data + '</div>');
                }

                // Procesar el siguiente
                processPlugin(index + 1);
            });
        }
    });
});
