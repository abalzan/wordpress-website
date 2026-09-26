<?php
require '/var/www/html/wp-load.php';
$out=array();
foreach (get_posts(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','lang'=>'pt')) as $id) {
  if ((int)pll_get_post($id,'en')>0) continue;
  $p=get_post($id);
  $terms=array();
  foreach (wp_get_post_terms($id,'conexao_category',array('fields'=>'slugs')) as $t) $terms[]=$t;
  $out[]=array('id'=>(int)$id,'slug'=>$p->post_name,'title'=>$p->post_title,
   'excerpt'=>$p->post_excerpt,'content'=>$p->post_content,'date'=>$p->post_date,
   'meta_desc'=>get_post_meta($id,'conexao_meta_description',true),'terms'=>$terms,
   'thumb'=>(int)get_post_thumbnail_id($id));
}
file_put_contents('/var/www/html/scripts/_tmp-posts.json', json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
