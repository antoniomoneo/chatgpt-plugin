jQuery(document).ready(function($){
    $('#oa-assistant-form').on('submit', function(e){
        e.preventDefault();
        var question = $('#oa-question').val();
        $('#oa-response').text('...');
        $.ajax({
            method: 'POST',
            url: OA_Assistant.rest_url,
            beforeSend: function(xhr){
                xhr.setRequestHeader('X-WP-Nonce', OA_Assistant.nonce);
            },
            data: { question: question }
        }).done(function(res){
            if(res.answer){
                $('#oa-response').text(res.answer);
            } else if(res.error){
                $('#oa-response').text(res.error);
            }
        }).fail(function(){
            $('#oa-response').text('Error');
        });
    });
});
