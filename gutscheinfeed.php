<?php
/*
Plugin Name: Gutscheinfeed
Plugin URI: http://www.gutscheinfeed.com
Description: Gutscheinfeed für Ihr Wordpress Blog.
Version: 1.4
Author: Florian Peez
Author URI: http://www.gutscheinfeed.com
*/

global $gutscheinfeed_db_version;
$gutscheinfeed_db_version = "1.0";
register_activation_hook(__FILE__,'gutscheinfeed_install');
add_action('admin_menu', 'gutscheinfeed_create_menu');
add_action('get_footer', 'gutscheinfeed_cron');
add_action('widgets_init', create_function('', 'return register_widget("GutscheinfeedWidget");'));
add_action('init',"gutscheinfeed_redirect");
add_action('template_redirect', 'gutscheinfeed_expired_redirect');
add_filter('posts_where', 'gutscheinfeed_exclude');

function gutscheinfeed_exclude($where) {
	global $wpdb;
	$expired = $wpdb->get_col("SELECT DISTINCT ID FROM $wpdb->posts AS p LEFT JOIN $wpdb->postmeta AS pm ON (p.ID = pm.post_id) WHERE pm.meta_key = 'gutscheinfeed_ablauf' AND date(pm.meta_value) < date(now()) GROUP BY pm.post_id");
	$expired_posts = implode(',', $expired);
	if (!empty($expired_posts)){
		$where .= " AND $wpdb->posts.ID NOT IN ($expired_posts)";
	}
	return $where;
}

function gutscheinfeed_expired_redirect() {
    global $post;
    if(is_single()) {
        $ablauf=get_post_meta($post->ID, 'gutscheinfeed_ablauf',true);
        $dt=explode(" ",$ablauf);
        $dd=explode("-",$dt[0]);
        $dh=explode(":",$dt[1]);
        $ts=mktime($dh[0],$dh[1],$dh[2],$dd[1],$dd[2],$dd[0]);
        if($ts<time()){
        	$redir=get_option('gutscheinfeed_redirecturl',get_bloginfo("home"));
        	header("Location: ".$redir);
        	exit;
        }
    }
}  
function gutscheinfeed_redirect(){
	$urlpart=get_option('gutscheinfeed_url','/gutschein_einloesen/');
	$is_gutschein=false;
	if(strpos($_SERVER["REQUEST_URI"],$urlpart)!==false){
		$temp=explode($urlpart,$_SERVER["REQUEST_URI"]);
		$temp=explode("/",$temp[1]);
		$temp=explode("_",urldecode($temp[0]));
		$gutscheinid=$temp[0];
		$anbieter=$temp[1];
		$is_gutschein=true;
	}
	if($_GET["gutscheineinloesen"]!=""){
		$gutscheinid=$_GET["gutscheineinloesen"];
		$anbieter=$_GET["anbieter"];
		$is_gutschein=true;
	}
	if($is_gutschein){
		$anbieter=str_replace("_",".",$anbieter);
		global $wpdb;
		$table_name = $wpdb->prefix . "gutscheinfeed";
		$link=$wpdb->get_var("select link from ".$table_name." where name='".$wpdb->escape($anbieter)."'");
		include_once(ABSPATH.WPINC.'/class-snoopy.php');
		$snoopy = new Snoopy();
		$snoopy->maxredirs=0;
		$result = $snoopy->fetch('http://www.gutscheinfeed.com/gutschein.php?id='.$gutscheinid.'&link='.urlencode(str_replace(".","___",$link))."&referer=".urlencode(str_replace(".","_",substr(get_bloginfo("home"),7))));
		if($result) {
			echo $snoopy->results;
		} else {
			echo "We were not able to complete your request";
		} 
		exit;	
	}
}

function gutscheinfeed_cron(){
	if($_SERVER["HTTP_REFERER"]!=""){
		$gutscheinfeed_lastcron=get_option("gutscheinfeed_lastcron");
		if($gutscheinfeed_lastcron<(time()-3600)){
			update_option("gutscheinfeed_lastcron",time());
			gutscheinfeed_import();
		}
	}
}

function gutscheinfeed_link($id,$anbieter){
	global $wp_rewrite;
	$home=get_bloginfo("home");
	if(substr($home,-1)=="/"){
		$home=substr($home,0,-1);
	}
	$anbieter=str_replace(".","_",$anbieter);
	if($wp_rewrite->using_mod_rewrite_permalinks()){
		$link=$home.get_option('gutscheinfeed_url','/gutschein_einloesen/').$id."_".$anbieter;
	}else{
		$link=$home.'/index.php?gutscheineinloesen='.$id.'&anbieter='.urlencode($anbieter);
	}	
	return $link;
}

function gutscheinfeed_replace($item,$text){
	global $wpdb;
	$link=gutscheinfeed_link($item["gutscheinid"],$item["anbieter"]);
	$anbieter=$item["anbieter"];
	$start=$item["start"];
	$ende=$item["ende"];
	$wert=$item["wert"];
	$mbw=$item["mbw"];
	$description=$item["description"];
	$table_name = $wpdb->prefix . "gutscheinfeed";
	$dblinks=$result=$wpdb->get_row("select * from ".$table_name." where name='".$wpdb->escape($anbieter)."'");
	$logo="";
	if($dblinks){
		if($dblinks->image!=""){
			$logo="<img src=\"".$dblinks->image."\" border=\"0\">";
			if($dblinks->link!=""){
				$logo="<a href=\"".$dblinks->link."\" target=\"_blank\">".$logo."</a>";
			}
		}

	}
	$text=str_replace("{Anbieter}",$anbieter,$text);
	$text=str_replace("{Wert}",$wert,$text);
	$text=str_replace("{Bemerkung}",$description,$text);
	$text=str_replace("{Link}",$link,$text);
	$temp=explode(" ",$ende);
	$temp=explode("-",$temp[0]);
	$ablaufdatum=$temp[2].".".$temp[1].".".$temp[0];
	$temp=explode("{Ablaufdatum",$text);
	$temp=explode("}",$temp[1]);
	$temp=explode("|",$temp[0]);
	$text=str_replace("{Ablaufdatum|".$temp[1]."|".$temp[2]."}",$temp[1]." ".$ablaufdatum." ".$temp[2],$text);
	$temp=explode("{Mindestbestellwert",$text);
	$temp=explode("}",$temp[1]);
	$temp=explode("|",$temp[0]);
	if($mbw!=""){
		$text=str_replace("{Mindestbestellwert|".$temp[1]."|".$temp[2]."}",$temp[1]." ".$mbw." ".$temp[2],$text);
	}else{
		$text=str_replace("{Mindestbestellwert|".$temp[1]."|".$temp[2]."}","",$text);
	}
	$text=str_replace("{Logo}",$logo,$text);
	return $text;
}

function gutscheinfeed_import(){
	global $wpdb;
	$table_name = $wpdb->prefix . "gutscheinfeed";
	$gutscheinfeed_aktion=get_option('gutscheinfeed_aktion');
	if($gutscheinfeed_aktion!=0){
		$gutscheinfeed_user=get_option('gutscheinfeed_user');
		$gutscheinfeed_category=get_option('gutscheinfeed_category');
		$gutscheinfeed_titel=get_option('gutscheinfeed_titel');
		$gutscheinfeed_text=get_option('gutscheinfeed_text');
	}
	$gutscheinfeed_lastid=get_option("gutscheinfeed_lastid");
	include_once(ABSPATH.WPINC.'/rss.php');
	$rss = fetch_rss("http://www.gutscheinfeed.com/gutscheinfeed.php");
	$highid=0;
	foreach($rss->items as $item){
		if($item["anbieter"]!=""){
			if($wpdb->get_var("select count(id) from ".$table_name." where name='".$wpdb->escape($item["anbieter"])."'")==0){
				$wpdb->query("insert into ".$table_name." (name) values ('".$wpdb->escape($item["anbieter"])."')");
			}	
		}
		if($gutscheinfeed_aktion!=0){
			if($item["gutscheinid"]>$gutscheinfeed_lastid){
				$gutscheinfeed_post = array();
  				$gutscheinfeed_post['post_title'] = gutscheinfeed_replace($item,$gutscheinfeed_titel);
  				$gutscheinfeed_post['post_content'] = gutscheinfeed_replace($item,$gutscheinfeed_text);;
  				if($gutscheinfeed_aktion==1){
	  				$gutscheinfeed_post['post_status'] = 'draft';
  				}
  				if($gutscheinfeed_aktion==2){
	  				$gutscheinfeed_post['post_status'] = 'publish';
  				}
  				$gutscheinfeed_post['post_author'] = $gutscheinfeed_user;
  				$gutscheinfeed_post['post_category'] = array($gutscheinfeed_category);
  				$post_id=wp_insert_post($gutscheinfeed_post);
				update_post_meta($post_id, 'gutscheinfeed_ablauf', $item["ende"]);
			}
			if($highid<$item["gutscheinid"]){
				$highid=$item["gutscheinid"];
			}
		}
	}
	if($highid>0){
		update_option("gutscheinfeed_lastid",$highid);
	}
}

function gutscheinfeed_create_menu() {
	add_menu_page('Gutscheinfeed', 'Gutscheinfeed', 'administrator', 'gutscheinfeedmenuhandle', 'gutscheinfeed_settings_page');
	add_submenu_page( 'gutscheinfeedmenuhandle', 'Weiterleitungen', 'Weiterleitungen', 'administrator', 'gutscheinfeedsubmenuhandle', 'gutscheinfeed_redirects_page');
	add_action( 'admin_init', 'gutscheinfeed_register_mysettings' );
}

function gutscheinfeed_register_mysettings() {
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_aktion' );
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_category' );
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_user' );
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_titel' );
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_text' );
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_url' );
	register_setting( 'gutscheinfeed-settings-group', 'gutscheinfeed_redirecturl' );
}

function gutscheinfeed_redirects_page(){
global $wpdb;
$table_name = $wpdb->prefix . "gutscheinfeed";
if($_POST["counter"]>0){
	for($i=0;$i<$_POST["counter"];$i++){
		if($_POST["id_".$i]!=""){
			$wpdb->query("update ".$table_name." set link='".$wpdb->escape($_POST["link_".$i])."',image='".$wpdb->escape($_POST["logo_".$i])."' where id='".$wpdb->escape($_POST["id_".$i])."'");	
		}
	}
}
if($_POST["edited"]>0){
	$wpdb->query("update ".$table_name." set link='".$wpdb->escape($_POST["link"])."',image='".$wpdb->escape($_POST["logo"])."' where id='".$wpdb->escape($_POST["edited"])."'");	
}
?>	
<div class="wrap">
<h2>Gutscheinfeed Weiterleitungen</h2>
<?php
if($_POST["edit"]!=""){
	$result=$wpdb->get_row("select * from ".$table_name." where id='".$wpdb->escape($_POST["edit"])."'");
	if($result){
		echo "<h3>Bearbeiten</h3><form method=\"post\"><input type=\"hidden\" name=\"edited\" value=\"".$result->id."\"><table>";
		echo "<tr><td>Anbieter</td><td>".$result->name."</td></tr>";
		echo "<tr><td>Link</td><td><input type=\"text\" name=\"link\" value=\"".$result->link."\"></td></tr>";
		echo "<tr><td>Logo</td><td><input type=\"text\" name=\"link\" value=\"".$result->logo."\"></td></tr>";
		echo "</table><input type=\"submit\" value=\"Speichern\"></form>";	
	}
}else{
?>
<p>Damit Sie mit dem Gutscheinfeed.com Plugin Geld verdienen, müssen Sie hier Affiliate-Links für die jeweiligen Anbieter hinterlegen. Beim Einlösen eines Gutscheins wird zu etwa 80% der von Ihnen hinterlegte Link verwendet, falls kein Link hinterlegt ist wird automatisch die Weiterleitung über Gutscheinfeed.com veranlaßt.</p>
<p>Entsprechende Affiliate Links finden Sie bei diesen Netzwerken:<br />
<a href="http://www.adcell.de/click.php?bid=50-40645" target="_blank"><img src="http://www.adcell.de/img.php?bid=50-40645" alt="ADCELL" border="0" height="60" width="120"></a>&nbsp;<a href="http://klick.affiliwelt.net/klick.php?bannerid=34652&amp;pid=31317&amp;prid=289" target="_blank"><img src="http://view-affiliwelt.net/b34652_31317_289.gif" alt="affiliwelt.net - Gib mir 5" border="0" height="62" width="124"></a>&nbsp;<a href="http://www1.belboon.de/adtracking/02cb800823f90004db00019f.html" target="_blank"><img src="http://www1.belboon.de/adtracking/02cb800823f90004db00019f.img" alt="belboon Partnerprogramm-Netzwerk" border="0" height="60" width="120"></a>&nbsp;<a href="http://clix.superclix.de/cgi-bin/clix.cgi?id=efpemuc&amp;pp=1&amp;linknr=50872" target="_blank"><img src="http://clix.superclix.de/images/logo/Logo_SuperClix_120x60.jpg" alt="SuperClix - das Partnerprogramm-Netzwerk" border="0" height="60" width="120"></a>&nbsp;<a href="http://www.zanox-affiliate.de/ppc/?16400571C1427964872T"><img src="http://www.zanox-affiliate.de/ppv/?16400571C1427964872" alt="Werden Sie jetzt Partner von zanox!" align="bottom" border="0" height="38" hspace="1" width="120"></a>
</p>
<?php
if($_POST["suche"]!=""){
	$results=$wpdb->get_results("select * from ".$table_name." where name like ('%".$wpdb->escape($_POST["suche"])."%')");
	if($results){
		echo "<h3>Suchergebnisse</h3><table>";
		foreach ($results as $result){
			echo "<tr><td>".$result->name."</td><td>".$result->link."</td><td>".$result->logo."</td><td><form method=\"post\"><input type=\"hidden\" name=\"edit\" value=\"".$result->id."\"><input type=\"submit\" value=\"Bearbeiten\"></form></td></tr>";	
		}
		echo "</table>";	
	}
}
?>
<h3>Anbieter bei denen noch kein Link hinterlegt ist:</h3>
<form method="post">
<table>
<tr><th>Anbieter</th><th>Link</th><th>Logo (optional)</th></tr>
<?php
$todos = $wpdb->get_results("select * FROM $table_name WHERE link = ''");
$counter=0;
foreach ($todos as $todo) {
	echo "<tr><td>".$todo->name."</td><td><input type=\"hidden\" name=\"id_".$counter."\" value=\"".$todo->id."\"><input type=\"text\" name=\"link_".$counter."\"></td><td><input type=\"text\" name=\"logo_".$counter."\"></td></tr>";
	$counter++;
}
?>
</table>
<input type="hidden" name="counter" value="<?php echo $counter; ?>">
<input type="submit" value="Speichern">
</form>
<h3>Anbieter suchen:</h3>
<p>Falls Sie den Link oder das Logo eines Anbieters ändern möchten, können Sie hier nach dem Anbieter suchen und die Daten anschließend bearbeiten</p>
<form method="post">
<input type="text" name="suche">
<input type="submit" value="Suchen">
</form>
<?php } ?>
</div>
<?php	
}

function gutscheinfeed_settings_page() {
?>
<div class="wrap">
<h2>Gutscheinfeed einlesen</h2>
Der Gutscheinfeed wird automatisch in regelmäßigen Abständen eingelesen wenn Besucher auf Ihre Seite kommen, wenn Sie jetzt Gutscheine manuell importieren möchten klicken Sie bitte auf Gutscheine einlesen.<br />
<?php
if($_POST["aktion"]=="einlesen"){
	if(get_option('gutscheinfeed_aktion')==0){
		echo "<strong>Bitte w&auml;hlen Sie erst eine Aktion f&uuml;r neue Gutscheine aus (Entwurf oder Publizieren)</strong>";
	}else{
		gutscheinfeed_import();
	}
}
?>
<form method="post">
<input type="hidden" name="aktion" value="einlesen" ?>
<input type="submit" value="Gutscheine einlesen">
</form>
<h2>Gutscheinfeed Einstellungen</h2>
<form method="post" action="options.php">
    <?php settings_fields( 'gutscheinfeed-settings-group' ); ?>
    <table class="form-table">
    	<tr valign="top">
        <td colspan="2">W&auml;hlen Sie aus was beim Einlesen eines neuen Gutscheincodes passieren soll, unabhängig von den Einstellungen auf dieser Seite können Sie immer das Gutschein-Widget einbinden.</td>
        </tr> 

        <tr valign="top">
        <th scope="row">Aktion</th>
        <td><select name="gutscheinfeed_aktion" style="width:200px;">
        <option value="">Bitte auswählen</option>
        <?php
        $choices=array(0=>"keine Aktion",1=>"als Entwurf speichern",2=>"als Beitrag publizieren");
        $selected=get_option('gutscheinfeed_aktion');
        for($i=0;$i<count($choices);$i++){
        	if($selected==$i){
        		echo "<option value=\"".$i."\" selected>".$choices[$i]."</option>";	
        	}else{
        		echo "<option value=\"".$i."\">".$choices[$i]."</option>";	
        	}
        }
        ?>
        </select>
        </td>
        </tr>
        <tr valign="top">
        <td colspan="2">Legen Sie die URL-Form für das Einlösen eines Gutscheins fest.</td>
        </tr> 
        <tr valign="top">
        <th scope="row">URL</th>
        <td><input type="text" name="gutscheinfeed_url" value="<?php echo get_option('gutscheinfeed_url','/gutschein_einloesen/'); ?>" style="width:400px;" />(/gutschein_einloesen/)</td>
        </tr>
        <tr valign="top">
        <td colspan="2">Legen Sie die Seite fest auf die ein abgelaufener Gutschein weiterleiten soll.</td>
        </tr> 
        <tr valign="top">
        <th scope="row">URL</th>
        <td><input type="text" name="gutscheinfeed_redirecturl" value="<?php echo get_option('gutscheinfeed_redirecturl',get_bloginfo("home")); ?>" style="width:400px;" />(<?php echo get_bloginfo("home"); ?>)</td>
        </tr>
        <tr valign="top">
        <td colspan="2">Legen Sie die Kategorie fest in der neue Gutscheinbeiträge angelegt werden sollen.</td>
        </tr>
                <tr valign="top">
        <th scope="row">Kategorie f&uuml;r Gutscheine</th>
        <td><select name="gutscheinfeed_category" style="width:200px;">
        <option value="">Bitte auswählen</option>
        <?php
        $categories=get_categories('hide_empty=0');
        $selected=get_option('gutscheinfeed_category','1');
        foreach($categories as $category){
        	if($selected==$category->cat_ID){
        		echo "<option value=\"".$category->cat_ID."\" selected>".$category->cat_name."</option>";	
        	}else{
        		echo "<option value=\"".$category->cat_ID."\">".$category->cat_name."</option>";	
        	}
        }
        ?>
        </select>
        </td>
        </tr>
        <tr valign="top">
        <td colspan="2">Legen Sie den Benutzer fest für den neue Gutscheinbeiträge angelegt werden sollen.</td>
        </tr>
                <tr valign="top">
        <th scope="row">Benutzer f&uuml;r Gutscheine</th>
        <td><select name="gutscheinfeed_user" style="width:200px;">
        <option value="">Bitte auswählen</option>
        <?php
        $selected=get_option('gutscheinfeed_user','1');
        global $wpdb;
        $users = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->users;"));
		foreach($users as $user){
			if($selected==$user->ID){
        		echo "<option value=\"".$user->ID."\" selected>".$user->user_nicename."</option>";	
        	}else{
        		echo "<option value=\"".$user->ID."\">".$user->user_nicename."</option>";	
        	}
        }
        ?>
        </select>
        </td>
        </tr>
        <tr valign="top">
        <td colspan="2">Geben Sie die Textvorlage für neue Gutscheinbeiträge ein, folgende Platzhalter stehen zur Verfügung:<br /><br />
        {Anbieter} - Gutscheinanbieter<br />
        {Logo} - Logo des Gutscheinanbieters (falls hinterlegt) - wird automatisch verlinkt<br />
        {Wert} - Gutscheinwert (Euro oder Prozent)<br />
        {Ablaufdatum|gültig bis|}<br /> 
        {Mindestbestellwert|gültig ab|Warenwert} - Mindesbestellwert für diesen Gutschein mit Text (wird nicht angezeigt wenn kein MBW)<br />
        {Bemerkung} - weitere Bemerkungen zum Gutschein<br />
        {Link} - Link zum Einlösen des Gutscheins<br />
        </td>
        </tr>
        <tr valign="top">
        <th scope="row">Beitragstitel</th>
        <td><input type="text" name="gutscheinfeed_titel" value="<?php echo get_option('gutscheinfeed_titel',"{Wert} Gutschein bei {Anbieter}"); ?>" style="width:400px;" />({Wert} Gutschein bei {Anbieter})</td>
        </tr>
        <tr valign="top">
        <th scope="row">Beitragstext</th>
        <td><textarea name="gutscheinfeed_text" style="width:400px;height:300px;float:left"><?php echo get_option('gutscheinfeed_text',"{Logo}\r\nMit diesem Gutschein sparen Sie {Wert} bei Ihrem Einkauf bei {Anbieter}.\r\n{Ablaufdatum|Der Gutschein kann bis zum|eingelöst werden.}\r\n{Mindestbestellwert|Der Gutschein ist gültig ab|Mindestbestellwert.}\r\n{Bemerkung}\r\n&lt;a href=\"{Link}\" target=\"_blank\"&gt;Hier klicken um den Gutschein bei {Anbieter} einzulösen&lt;/a&gt;"); ?></textarea>
        {Logo}<br /> 
        Mit diesem Gutschein sparen Sie {Wert} bei Ihrem Einkauf bei {Anbieter}.<br /> 
{Ablaufdatum|Der Gutschein kann bis zum|eingelöst werden.}<br />        
{Mindestbestellwert|Der Gutschein ist gültig ab|Mindestbestellwert.}<br /> 
{Bemerkung}<br /> 
	&lt;a href="{Link}" target="_blank"&gt;Hier klicken um den Gutschein bei {Anbieter} einzulösen	&lt;/a&gt;</td>
        </tr>

    </table>
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>

</form>
</div>
<?php
}



function gutscheinfeed_install () {
   global $wpdb;
   global $gutscheinfeed_db_version;
   $table_name = $wpdb->prefix . "gutscheinfeed";
   if($wpdb->get_var("show tables like '$table_name'") != $table_name) {
      $sql = "CREATE TABLE " . $table_name . " (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  name varchar(255) NOT NULL,
	  link varchar(255) NOT NULL,
	  image VARCHAR(255) NOT NULL,
	  UNIQUE KEY id (id)
	);";

      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($sql);

      add_option("gutscheinfeed_db_version", $gutscheinfeed_db_version);

   }
}


class GutscheinfeedWidget extends WP_Widget {
    function GutscheinfeedWidget() {
        parent::WP_Widget(false, $name = 'Gutscheinfeed Widget');	
    }

    function widget($args, $instance) {		
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $description = $instance['description'];
		if($description==""){
        	$description="{Wert} Gutschein bei {Anbieter}";
        }
        $number = $instance['number'];
        if($number==""){
        	$number="5";
        }
        ?>
              <?php echo $before_widget; ?>
                  <?php if ( $title )
                        echo $before_title . $title . $after_title; 
                  include_once(ABSPATH.WPINC.'/rss.php');
				$rss = fetch_rss("http://www.gutscheinfeed.com/gutscheinfeed.php");
				echo "<ul>";
				$i=0;
			foreach($rss->items as $item){
				echo "<li><a href=\"".gutscheinfeed_link($item["gutscheinid"],$item["anbieter"])."\" target=\"_blank\">".gutscheinfeed_replace($item,$description)."</a></li>";
				$i++;
				if($i>=$number){
				break;	
				}
  			}
  			echo "</ul>";
               echo $after_widget; ?>
        <?php
    }

    function update($new_instance, $old_instance) {				
	$instance = $old_instance;
	$instance['title'] = strip_tags($new_instance['title']);
	$instance['description'] = strip_tags($new_instance['description']);
	$instance['number'] = strip_tags($new_instance['number']);
        return $instance;
    }

    function form($instance) {				
        $title = esc_attr($instance['title']);
        $description = esc_attr($instance['description']);
        if($description==""){
        	$description="{Wert} Gutschein bei {Anbieter}";
        }
        $number = esc_attr($instance['number']);
        if($number==""){
        	$number="5";
        }
        ?>
            <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label>
            <label for="<?php echo $this->get_field_id('description'); ?>">Aufbau: <input class="widefat" id="<?php echo $this->get_field_id('description'); ?>" name="<?php echo $this->get_field_name('description'); ?>" type="text" value="<?php echo $description; ?>" /></label>
            <label for="<?php echo $this->get_field_id('number'); ?>">Anzahl: <input class="widefat" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" type="text" value="<?php echo $number; ?>" /></label></p>
        <?php 
    }
}
?>
