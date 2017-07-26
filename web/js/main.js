$('.js-confirm').on('click', function (e) {
    if (!confirm('Are you sure?')) {
        e.preventDefault();
    }
});

var hasShiftClicked = false;

$('.js-ajax button').on('click', function (e) {
    hasShiftClicked = e.shiftKey;
});

$('.js-ajax').on('submit', function (e) {
    e.preventDefault();
    var form = $(e.target);

    if (form.hasClass('progress')) {
        return;
    }

    form.addClass('progress');
    var handler = function (data) {
        data = data.responseJSON || data;

        form.removeClass('progress');
        if (data.status == 'success' && !hasShiftClicked) {
            document.location.href = document.location.href;
            return;
        }

        if (data.status === undefined || data.message === undefined) {
            data = {
                status: 'error',
                message: 'Unknown backend error',
                details: 'Please check the php error log or TORAN_DIR/app/logs/prod.log and report it to toran@nelm.io'
            };
        }

        var overlay = $('<div class="overlay"><h1 class="title '+data.status+'">'+data.message+'</h1></div>');
        overlay.append(data.details);
        $('body').prepend(overlay);
    }

    $.ajax({
        method: form.data('method') || form.attr('method') || 'POST',
        url: form.attr('action') + (hasShiftClicked ? '?showOutput=1' : ''),
        data: form.serialize(),
        success: handler,
        error: handler
    });
});


$('#form_license_personal').on('change', function (e) {
    $('#form_license').attr('disabled', $('#form_license_personal').is(':checked') ? 'disabled' : null);
});
$('#form_license').on('keyup', function (e) {
    if ($(this).val() != '') {
        $('#form_license_personal').prop('checked', false);
    }
});
