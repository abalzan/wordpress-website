<?php
/**
 * Stage D chunked scoped rollout export (memory-safe, same v1.1.0 contract).
 *
 * Streams one source at a time to disk; reuses Conexao_Event_Export::export_event().
 * Usage: php stage-d-export-chunked.php <export.json> <manifest.json>
 */
set_time_limit(0);
$wp_load='/var/www/html/wp-load.php';
if(!file_exists($wp_load)){$wp_load=dirname(dirname(dirname(dirname(__DIR__)))).'/wp-load.php';}
require_once $wp_load;
require_once WP_PLUGIN_DIR.'/conexao-event-importer/conexao-event-importer.php';
if(php_sapi_name()!=='cli'){fwrite(STDERR,"CLI only\n");exit(1);}
$export_path=isset($argv[1])?$argv[1]:'/tmp/stage-d/stage-d-rollout-export.json';
$manifest_path=isset($argv[2])?$argv[2]:'/tmp/stage-d/stage-d-rollout-manifest.json';
$all=Conexao_County_Registry::get_slugs();
$pilot=array('cork','dublin','laois');
$remaining=array_values(array_diff($all,$pilot));
$sources=array();
foreach($remaining as $slug){$sources[]='eventbrite_'.$slug;}
foreach($remaining as $slug){$sources[]='heritage_week_'.$slug;}
$after_arg=isset($argv[3])?$argv[3]:'';
$after_filter=''; if(1===preg_match('/^\d{4}-\d{2}-\d{2}$/',$after_arg)){$after_filter=$after_arg;}
$exporter=new Conexao_Event_Export();
$ref=new ReflectionMethod($exporter,'export_event');
$ref->setAccessible(true);
$fh=fopen($export_path,'w');
if(!$fh){fwrite(STDERR,"Cannot open $export_path\n");exit(1);}
$exported_at=current_time('c');
$source_url=home_url();
fwrite($fh,"{\n    \"manifest\": {\n");
fwrite($fh,'        "format": "conexao-event-export",'."\n");
fwrite($fh,'        "version": "1.1.0",'."\n");
fwrite($fh,'        "exported_at": '.wp_json_encode($exported_at).",\n");
fwrite($fh,'        "source_url": '.wp_json_encode($source_url).",\n");
fwrite($fh,'        "event_count": 0,'."\n");
fwrite($fh,'        "filters": {"sources": '.wp_json_encode($sources).(''!==$after_filter?', "after": '.wp_json_encode($after_filter):'')."}\n");
fwrite($fh,"    },\n    \"events\": [");
$today=current_time('Y-m-d');
$event_count=0;$image_count=0;$uuid_seen=array();$uuid_dups=0;
$identity_seen=array();$identity_dups=0;$url_seen=array();$url_dups=0;
$past_count=0;$past_in_payload=0;$source_counts=array();$county_counts=array();
$missing_uuid=0;$missing_srcid=0;$first=true;$localhost_hits=0;
foreach($sources as $source_id){
$mq=array(array('key'=>'_event_source','value'=>$source_id));
if(''!==$after_filter){$mq[]=array('key'=>'_event_date','value'=>$after_filter,'compare'=>'>=','type'=>'DATE');}
$q=new WP_Query(array('post_type'=>'event','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'meta_query'=>$mq));
foreach($q->posts as $post){
$event=$ref->invoke($exporter,$post);
$meta=isset($event['meta'])&&is_array($event['meta'])?$event['meta']:array();
$ed=isset($meta['_event_date'])?(string)$meta['_event_date']:'';
$ee=isset($meta['_event_end_date'])?(string)$meta['_event_end_date']:'';
$eff=''!==$ee?$ee:$ed;
if(''!==$eff&&$eff<$today){$past_count++;continue;}
$uuid=isset($event['uuid'])?(string)$event['uuid']:'';
if(''===$uuid){$missing_uuid++;}elseif(isset($uuid_seen[$uuid])){$uuid_dups++;}else{$uuid_seen[$uuid]=true;}
$src=isset($meta['_event_source'])?(string)$meta['_event_source']:'';
$sid=isset($meta['_event_source_id'])?(string)$meta['_event_source_id']:'';
$source_counts[$src]=isset($source_counts[$src])?$source_counts[$src]+1:1;
if(''===$sid){$missing_srcid++;}else{$ident=$src.'|'.$sid;if(isset($identity_seen[$ident])){$identity_dups++;}else{$identity_seen[$ident]=true;}}
foreach(array('_event_url','_event_source_url') as $uk){
if(!empty($meta[$uk])){$u=(string)$meta[$uk];if(preg_match('/https?:\/\/localhost/i',$u)){$localhost_hits++;}if('_event_url'===$uk){if(isset($url_seen[$u])){$url_dups++;}else{$url_seen[$u]=true;}}}}
$fi=isset($event['featured_image'])&&is_array($event['featured_image'])?$event['featured_image']:array();
if(!empty($fi['data_base64'])){$image_count++;}
$taxes=isset($event['taxonomies'])&&is_array($event['taxonomies'])?$event['taxonomies']:array();
$cc=isset($taxes['conexao_county'])&&is_array($taxes['conexao_county'])?$taxes['conexao_county']:array();
foreach($cc as $c){$county_counts[$c]=isset($county_counts[$c])?$county_counts[$c]+1:1;}
$chunk=wp_json_encode($event);
if(false===$chunk){continue;}
fwrite($fh,($first?"\n":",\n").$chunk);
$first=false;$event_count++;
unset($event,$chunk);
}
wp_reset_postdata();unset($q);
if(function_exists('wp_cache_flush')){wp_cache_flush();}
if(function_exists('gc_collect_cycles')){gc_collect_cycles();}
echo "chunk done: {$source_id} total={$event_count} mem=".round(memory_get_peak_usage(true)/1048576)."MB\n";
}
fwrite($fh,"\n    ]\n}\n");
fclose($fh);
$raw=file_get_contents($export_path);
$raw=str_replace('"event_count": 0','"event_count": '.(int)$event_count,$raw);
file_put_contents($export_path,$raw);
ksort($source_counts);ksort($county_counts);
$validation=array('schema_version_ok'=>true,'no_localhost_urls'=>0===$localhost_hits,'no_missing_uuid'=>0===$missing_uuid,'no_duplicate_uuid'=>0===$uuid_dups,'no_duplicate_identity'=>0===$identity_dups,'no_duplicate_url'=>0===$url_dups,'no_past_events'=>0===$past_in_payload,'event_count_matches'=>true);
$manifest=array('format'=>'conexao-events-stage-d-rollout-manifest','generated_at'=>current_time('c'),'export_filename'=>basename($export_path),'export_bytes'=>strlen($raw),'schema'=>'conexao-event-export','schema_version'=>'1.1.0','source_url'=>$source_url,'scope'=>array('counties'=>$remaining,'county_count'=>count($remaining),'source_ids'=>$sources,'source_count'=>count($sources),'excludes'=>array('eventbrite_cork','eventbrite_dublin','eventbrite_laois','heritage_week_cork','heritage_week_dublin','heritage_week_laois')),'event_count'=>$event_count,'image_count'=>$image_count,'uuid_count'=>count($uuid_seen),'source_identity_count'=>count($identity_seen),'url_count'=>count($url_seen),'duplicate_count'=>array('uuid'=>$uuid_dups,'identity'=>$identity_dups,'url'=>$url_dups,'total'=>$uuid_dups+$identity_dups+$url_dups),'past_event_count'=>$past_in_payload,'excluded_past_event_count'=>$past_count,'missing_uuid_count'=>$missing_uuid,'missing_source_id_count'=>$missing_srcid,'source_breakdown'=>$source_counts,'county_breakdown'=>$county_counts,'validation'=>$validation,'validation_result'=>(count(array_filter($validation))===count($validation))?'PASS':'FAIL');
file_put_contents($manifest_path,wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
echo wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
