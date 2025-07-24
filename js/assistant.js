// Admin JS for OpenAI Assistant v2.9.24
jQuery(function($){
  console.log('OA Admin loaded v2.9.24');
  $('#oa-add-config').on('click', function(){
    var table = $('#oa-configs tbody');
    var idx = table.find('tr').length;
    var row = '<tr>'+
      '<td><input class="regular-text" name="oa_assistant_configs['+idx+'][nombre]" placeholder="Ej: Soporte" /></td>'+
      '<td><input class="regular-text" name="oa_assistant_configs['+idx+'][slug]" placeholder="soporte" /></td>'+
      '<td><input class="regular-text" name="oa_assistant_configs['+idx+'][assistant_id]" placeholder="asst_..." /></td>'+
      '<td><textarea name="oa_assistant_configs['+idx+'][developer_instructions]" rows="3" style="width:100%;" placeholder="Instrucciones para el assistant"></textarea></td>'+
      '<td><input class="regular-text" name="oa_assistant_configs['+idx+'][vector_store_id]" placeholder="categoria" /></td>'+
    '</tr>';
    table.append(row);
  });
});
