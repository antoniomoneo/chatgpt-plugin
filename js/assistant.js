// Admin JS for OpenAI Assistant v2.9.21
jQuery(function($){
  console.log('OA Admin loaded v2.9.21');
  $('#oa-add-config').on('click', function(){
    var table = $('#oa-configs tbody');
    var idx = table.find('tr').length;
    var row = '<tr>'+
      '<td><input name="oa_assistant_configs['+idx+'][nombre]" /></td>'+
      '<td><input name="oa_assistant_configs['+idx+'][slug]" /></td>'+
      '<td><input name="oa_assistant_configs['+idx+'][assistant_id]" /></td>'+
      '<td><textarea name="oa_assistant_configs['+idx+'][developer_instructions]"></textarea></td>'+
      '<td><input name="oa_assistant_configs['+idx+'][vector_store_id]" /></td>'+
    '</tr>';
    table.append(row);
  });
});