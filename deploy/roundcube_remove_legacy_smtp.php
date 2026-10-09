<?php
// Static editing only; never evaluate private configuration.
$source=stream_get_contents(STDIN);
$tokens=token_get_all($source,TOKEN_PARSE);
$semantic=[];$offset=0;
foreach($tokens as $t){
 $value=is_array($t)?$t[1]:$t;
 if(!is_array($t)||!in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$semantic[]=[$t,$offset,$offset+strlen($value)];
 $offset+=strlen($value);
}
$keys=['smtp_host','smtp_server','smtp_user','smtp_pass','smtp_port','smtp_auth_type'];
$edits=[];$found=[];
for($i=0;$i+4<count($semantic);$i++){
 $p=array_slice($semantic,$i,7);$t=array_column($p,0);
 if(!is_array($t[0])||$t[0][0]!==T_VARIABLE||$t[0][1]!=='$config'||$t[1]!=='['||!is_array($t[2])||$t[2][0]!==T_CONSTANT_ENCAPSED_STRING||$t[3]!==']')continue;
 $key=substr($t[2][1],1,-1);
 if(!in_array($key,$keys,true))continue;
 if(count($p)!==7||$t[4]!=='='||!is_array($t[5])||!in_array($t[5][0],[T_CONSTANT_ENCAPSED_STRING,T_LNUMBER],true)||$t[6]!==';')exit(2);
 if(isset($found[$key]))exit(2);
 $found[$key]=true;$edits[]=[$p[0][1],$p[6][2]];
}
foreach(['smtp_host','smtp_user','smtp_pass'] as $key)if(!isset($found[$key]))exit(2);
foreach(array_reverse($edits) as [$start,$end])$source=substr($source,0,$start).substr($source,$end);
token_get_all($source,TOKEN_PARSE);
echo $source;
