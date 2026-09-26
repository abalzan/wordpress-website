<?php
require '/var/www/html/wp-load.php';
$b2 = conexao_b2_page_allowlist();
$out = array();
foreach (get_posts(array('post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','lang'=>'pt','orderby'=>'ID','order'=>'ASC')) as $id) {
  if ((int)pll_get_post($id,'en')>0) continue;
  if (in_array(get_post($id)->post_name,$b2,true)) continue;
  $p = get_post($id);
  $out[] = array('id'=>$id,'slug'=>$p->post_name,'title'=>$p->post_title,
    'content'=>$p->post_content,'excerpt'=>$p->post_excerpt,
    'template'=>get_post_meta($id,'_wp_page_template',true) ?: 'default',
    'meta_desc'=>get_post_meta($id,'conexao_meta_description',true));
}
file_put_contents('/var/www/html/scripts/_tmp-pages.json', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
