jQuery(function($){
  $('.oa-assistant-chat').each(function(){
    var w=$(this),
        slug=w.attr('data-slug'),
        ajaxUrl=w.attr('data-ajax'),
        nonce=w.attr('data-nonce'),
        msgs=w.find('.oa-messages'),
        input=w.find('input[name="user_message"]');

    function esc(t){
      return $('<div>').text(t).html();
    }

    function sendMessage(text){
      if(!text) return;
      msgs.append('<div class="msg user">'+esc(text)+'</div>');
      input.val('').focus();
      $.post(ajaxUrl,{
        action:'oa_assistant_chat',
        nonce:nonce,
        slug:slug,
        message:text
      }).done(function(res){
        msgs.append('<div class="msg bot">'+esc(res.success?res.data.reply:res.data)+'</div>');
      }).fail(function(){
        msgs.append('<div class="msg error">Error al enviar</div>');
      });
      msgs[0].scrollTop = msgs[0].scrollHeight;
    }
    w.find('.oa-form').on('submit', function(e){e.preventDefault(); sendMessage(input.val().trim());});
    input.on('keypress', function(e){ if(e.which===13){e.preventDefault(); sendMessage(input.val().trim());}});
  });
});
