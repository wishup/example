<?php
/*
обработчик формы

*/
require_once 'ads_form_class_lib.php';
class Ads_Form_Handler extends Ads_Form_Handler_Lib {
	// статическая страница на которой написана форма
	var $page_id;
	// flag режим редактирование или новый EDIT | NEW
	var $mode;
    // шаблон формы может быть загружен 2 раза первый для подключения фильтров и сканирования полей
    // второй раз непосредственно для печати
    // флаг true говрит что шаблон формы печатается
    var $print_form_template;
	 /* flag показывает откуда запуск обекта либо через фильр the_content
	 или через механизм shortcode */
	var $starting_from;
	// действие запрошеное пользователем UPDATE, EDIT, PUBLISH, DELETE_FILE ....
	var $action;
	// объект  WP_Error
	var $wp_error;
	// массив имена полей которые разрешено сохранять
	var $ads_fields_form = array('ads_basecat', 'ads_password','ID',
	'ADS_ACTION','ads_captcha','ads_files','ads_overdue','ads_old_categories',
	'ads_form_id','ads_form_name', 'hiddens', 'tabs', 'ads_location',
     'ads_latitude',  'ads_longitude', 'post_content', 'error', 'ads_answer' );
	// имя файла шаблона формы   он присваиваивеца в процессе
	// var $name_template;
	// содержимое shortcode [AdsForm]content[/AdsForm]
	var $content;
	// массив атрибутов тега [AdsForm att1=test att2=456 ...]
	var $atts = array();
	// опции настроек merge $ads_options + $ads_config + $atts
	var $options = array();
	// результирующий  html код формы
	var $html = '';
	// информационое сообщение пост сохранен пост удален ссылка  на него
	var $html_info = '';
	// асмасив имя поля => условие
	var $terms;
	//асмасив имя поля=>текст ошибки то что задоно в шорткоде emsg='Ошибка бля!'
	var $emsg;
	// слова разрешенные к использованию в условиях
    var $approve_terms_words = array('or','xor','and','OR','XOR','AND','int',
    'is_string','is_numeric','is_float',
    'is_array', 'strlen', 'empty', 'current', 'count' );
    // скрытые поля
    var $hidden_fields;

/* основной алгоритм - ядро плагина */
function main( $atts='', $content='', $code=''){
    // инициализация данных определяемся что-откуда выставляем флаги
    $this->init( $atts, $content, $code);

    // если разрешено писать только зарегистрированым
 	if( !$this->check_user_status() ){
        $this->action = 'SKIP';
    }



	switch ($this->action){

	// если нету action значит новое сообщение
    default:   //
		if( $this->can_new_post() ){
			$this->print_form();
		}
    break;

/*
в этом блоке проверка данных обработка и если все ок сохранение и upload
загрузка файлов работает в обход онсновных проверок в случае если
пост не был создан из-за каких-то ошибок в save_form() метод upload()
создаст черновик записи и присоеденит файлы к нему
если юзер допустил ошибку при вводе даных чтобы не потерять загруженые
файлы и не нервировать юзера :)
*/
    case 'PARSE':
    case 'UPDATE':
    case 'PUBLISH':
    case 'SAVE':
// если новый пост но юзеру  нельзя создавать новый\'
    	if( $this->is_newpost() AND !$this->can_new_post() ){
  	    	break;
    	}elseif( $this->is_edit() AND !$this->can_edit_post() ){
    		break;
    	}
        // проверяем капчу
    	if( !$this->check_captcha() ){
    		$this->print_form();
    		break;
    	}

        $template = $this->check_category_template();
        if( $template == 'content' ){
            //убираем двойные слеши и всякий мусор
            $this->pre_format();

            // парсим content  ищем имена полей name="ads_name" - условия
            $this->parser_content( $this->content );

           	// отфильтровываем незаконные поля
           	$this->filter_fields_form();

            // запускаем пользовательские фильтры
            $_REQUEST = apply_filters('ads_check_fields', $_REQUEST, $this);
            // копирует ошибки из $_REQUEST['error'] => $this->wp_error->errors

            $this->load_error( $_REQUEST['error'] );

            // обязательные проверки
            $this->mandatory_inspections();

            $this->upload();

            // печатаем форму
            $this->html = $this->print_form_content( $this->content );


            // если нет ошибок сохраняем форму
            if( !$this->isset_error() ){
                $post_id = $this->save_form();
                if( $post_id )
                    do_action( 'ads_after_saving', $post_id, $_REQUEST, $this);
            }



        }elseif( is_string($template) or is_array($template) ){
            // подгружаем шаблон чтобы подключить поль фильтр
            // грабем имена полей name="ads_name"

            $this->load_template($this->name_template);
              //убираем двойные слеши и всякий мусор
            $this->pre_format();
            // отфильтровываем незаконные поля
           	$this->filter_fields_form();

            // запускаем пользовательские фильтры
            $_REQUEST = apply_filters('ads_check_fields', $_REQUEST, $this);
            // копирует ошибки из $_REQUEST['error'] => $this->wp_error->errors
            $this->load_error( $_REQUEST['error'] );

            // обязательные проверки
            $this->mandatory_inspections();

            $this->upload();

            // если нет ошибок сохраняем форму
            if( !$this->isset_error() ){
                $post_id = $this->save_form();
                if( $post_id )
                    do_action( 'ads_after_saving', $post_id, $_REQUEST, $this);
            }

            // печатаем форму
            $this->html = $this->print_form_template( $this->name_template );

        }else{
            $this->html = $this->print_check_category();
        }

    break;
    // юзер хочет ввести ID и пароль чтобы редактировать \ удалить запись
    // пользователь пытается получить доступ к запсиси для редактирования
    case 'LOGIN':
    case 'EDIT':
   		if( $this->can_edit_post() ){
        	$this->load_post();
        	$this->print_form();
   		}else{
   			$this->print_login_form();
   		}
    break;

	// удаление объявления
    case 'DELETE':
    case 'TO_TRASH':
   		if( $this->can_delete() ){
        	$this->delete();
   		}
    break;

	case 'DELETE_FILE':
		if($this->can_delete()){
			$this->file_delete();
		}
		$this->set_draft();
		$this->print_form();
	break;
	case 'ROTATE_LEFT':
	case 'ROTATE_RIGHT':
		if($this->can_delete()){
			$this->file_rotate();
		}
		//$this->set_draft();
		$this->print_form();
	break;

    case 'SKIP':
    break;

/*	case 'MYPOSTS':
		$this->my_posts();
	break;

	case 'CONDITIONS':
		$this->conditions();
	break;*/

    } // EDN CASE


    // debugging
	//echo "action: <b>$this->action</b>; mode: <b>$this->mode</b>; starting_from: <b>$this->starting_from</b>; name_template: <b>$this->name_template</b>; ";
    //echo '<pre>$_COOKIE:'.time(); print_r ( $_COOKIE ); echo '</pre>';
    //echo '<pre>$_REQUEST:';print_r($_REQUEST);echo '</pre>';
    //echo '<pre>options:';print_r($this->options);echo '</pre>';

/*
конечный html код формируеца из
0) вставляем кнопки вместо кода <!--ads-bottons-->
1) панели вкладок New Post / Edit
2) информационное сообщение пост сохранен ID, пароль ...
2) блок all_errors - все ошибки
3) и собствено сама форма
*/
    $this->html = $this->genrete_replace_bottons($this->html);
    $result = "<div class='ads_wordpress'  id='ads_page_$this->page_id'>\n";
    $result .= $this->new_edit_tab();
    $result .= $this->get_info_msg();
    $result .= $this->get_all_errors();
    $result .= "<div class='ads_clear'></div>\n";
    $result .= $this->add_hidden_fields( $this->html );
    $result .= "</div>\n";
    return $result;
}


function mandatory_inspections(){
	// обязательная проверка должно быть одно из 2 заголовок или контент записи
	if(!($_REQUEST['post_content'] OR $_REQUEST['post_title'])){
	$this->wp_error->add( 'ads_main',
		__("Error: Enter the text or title!",
		'ads-wordpress') );
	}
    // проверяем WP теги
    $this->check_set_tags();
}

function isset_error(){


    if( sizeof( $this->wp_error->get_error_codes() ) ) return true;
	return false;
}


// инициализация данных определяемся что-откуда выставляем флаги
function init($atts='', $content='', $code=''){
	global $ads_options, $ads_config, $ads_fields_form, $page_id, $post,$ads_ads_config,$ads_ads_options;
    // нет переменных  $ads_config, $ads_options востанавливаем из резервных ссылок
    if(!is_array($ads_options)) $ads_options = &$ads_ads_options;
    if(!is_array($ads_config)) $ads_config = &$ads_ads_config;
	$this->page_id = $post->ID;
	$this->post_name = $post->post_name;
   // действие запрошеное пользователем UPDATE, EDIT, PUBLISH, DELETE_FILE
	if(is_array($_REQUEST['ADS_ACTION'])){
		$this->action = key( $_REQUEST['ADS_ACTION']);
	}
	else
		$this->action = $_REQUEST['ADS_ACTION'];


	// flag режим редактирование или новый EDIT | NEW
	if( $_REQUEST['ID'] OR $this->action == 'EDIT')
		$this->mode = 'EDIT';
    else
        $this->mode = 'NEW';

    // запоминаем состояние табов
    if($_REQUEST['tabs'])
        $this->tabs = $_REQUEST['tabs'];
    else
        $this->tabs = $this->mode;

    $this->set_hidden('tabs', $this->tabs );


/* flag показывает откуда запуск обекта либо через фильр the_content
	 или через механизм shortcode */
    if($code == 'AdsForm')
    	$this->starting_from = 'shortcode';
    else
    	$this->starting_from = 'the_content';


    $this->content = $content;
    if(is_array($atts))
    	$this->atts = $atts;

    // ссливаем вместе опции конфик и атрибуты если есть
    $this->options = array_merge($ads_options, $ads_config, $this->atts );
    unset($this->options['ads_fields_form']);
    // а теперь финт ушами чтобы внешние функции заработали правильно
    $GLOBALS['ads_options'] =  & $this->options;
    $GLOBALS['ads_config']= & $this->options;

    // коректируем ads_base_category
    if($this->options['ads_base_category'])
        $this->options['ads_base_category'] =
            slug_to_term_id($this->options['ads_base_category'], 'category');

    // коректируем ads_exclude
    if($this->options['ads_exclude'])
        $this->options['ads_exclude'] =
            slug_to_term_id($this->options['ads_exclude'], 'category');

    // коректируем ads_location_root
    if($this->options['ads_location_root'])
        $this->options['ads_location_root'] =
            slug_to_term_id($this->options['ads_location_root'], 'category');

    // коректируем ads_overdue_category категория корзин
    if($this->options['ads_overdue_category'])
        $this->options['ads_overdue_category'] =
            slug_to_term_id($this->options['ads_overdue_category'], 'category');

    // ads_fields_form
    if(is_array( $ads_config['ads_fields_form'] ))
    	$this->ads_fields_form  =  array_merge($this->ads_fields_form, $ads_config['ads_fields_form'] ) ;
	if(is_array($_SESSION['ads_fields_form']))
		$this->ads_fields_form  = array_merge($this->ads_fields_form, $_SESSION['ads_fields_form']);
 	$this->ads_fields_form = array_unique ($this->ads_fields_form);
 	$this->wp_error = new WP_Error;
}

/* смена базовой категории */
function check_change_basecat(){
    if($_REQUEST['ads_change_basecat'] AND current_user_can('edit_others_posts')){
        $_REQUEST['ads_basecat'] = $_REQUEST['ads_change_basecat'];
    }
}

/*
возращает $this->name_template, это свойство
работает как флаг и хранит имя шаблона
если !isset(name_template) -  выбор еще не сделан запускаем алгоритм
возращает :
(name_template ===false) - требуется выбор категории
(name_template == 'content' ) - выбор уже сделан шаблон из content берем
(name_template = имя шаблона ) - содержит имя шаблона
*/
function check_category_template(){
    $this->check_change_basecat();

	if( isset($this->name_template) )
		return $this->name_template;
	if($this->options['choice_category']=='off'){ // выбор категорий отключен
        if( $this->options['name_template'] ) {
        	$this->name_template = $this->options['name_template'];
        }elseif($this->content){
       // запускаем [ads_cat]  вырезаем блоки категорий
       		$this->content = $this->do_one_shortcode(
       											$this->content, 'ads_cat' );
        	$this->name_template = 'content';
        }else{
        	$this->name_template = $ads_config['form_templates']['default'];
        }

	} else {
    	$this->name_template =
    			$this->category_template($_REQUEST['ads_basecat']);
    	if($this->name_template){
            if( $this->options['name_template'] ) {
            	$this->name_template = $this->options['name_template'];
            }elseif($this->content){
            //запускаем [ads_cat]  вырезаем блоки категорий
                $this->content = $this->do_one_shortcode(
                					$this->content, 'ads_cat' );
           		$this->name_template = 'content';
            }
    	}
    }
    return $this->name_template;
}



// печатет $this->html_form   создает html код формы
function print_form(){
// check_category_template() возращает 1 если категория уже выбрана
// или выбор не требуется - отменен
// false если треб выбор категории
// check_category_template() устанавливает свойство  $this->name_template
    $this->check_category_template();
	if( $this->name_template == 'content' ){
		$html_form = $this->print_form_content( $this->content );
	}elseif( $this->name_template ){
		$html_form = $this->print_form_template( $this->name_template );
	} else { // тут готовим список категорий для выбора !
		$html_form = $this->print_check_category();
	}
	$this->html = $this->html_status."\n".$html_form;
}


/*печатаем выбор категорий */
function  print_check_category(){
	ob_start();
    include $this->options['dir_template'].'/'.$this->options['choice_category'];
    $html = ob_get_contents();
	ob_end_clean();
	return  $html;
}

/*печатает форму из содержимого schorcode*/
function print_form_content($content){
    //удаление комментариев
    $content = preg_replace('~(?<!:)//.*$~m','',$content);
	// добавляем шорт коды
	$this->add_shortcodes();
	 // запускаем обработку тегов
  	$content = do_shortcode( $content );
    $content = preg_replace('~(\n\r){2,}~si', "\n\r", $content );
	$html_form = <<<AdsForm
<form action="" accept-charset="utf-8" enctype="multipart/form-data"
    method="post" class="ads_form" id="ads_form$this->page_id">
    $content
</form>
AdsForm;
	include_once('FormPersister.php');
	$_POST = $_REQUEST;
	$ob = new HTML_FormPersister();
    $html_form = $ob->process($html_form);
	return $html_form;//$content.$this->print_buttons();
}
/*печатает html код формы из заданаго шаблона в дир /templates*/
function print_form_template($template){
    $this->print_form_template = true;// начинаем печатать шаблон
	$ads_config = $ads_options = &$this->options;
	$url_captcha = $this->get_url_captcha();
	extract($this->options);
    ob_start();
    if(is_array($template)) {
       list($file_name, $class_name) = $template;
       if(!$this->oTmpl){
            include $this->options['dir_template'].'/'.$file_name;
            $this->oTmpl = new $class_name($this);
       }

       $this->oTmpl->form( $url_captcha );
    }else
        include $this->options['dir_template'].'/'.$template;

    $html_form = ob_get_contents();
    ob_end_clean();
	include_once('FormPersister.php');
	$_POST = $_REQUEST;
	$ob = new HTML_FormPersister();
    $html_form = $ob->process($html_form);
	return $html_form;//$content.$this->print_buttons();
}

/* загружает данные в $_REQUEST  массив */
function load_post(){
    $load_post = get_post($_REQUEST['ID'],ARRAY_A);
    unset($load_post['tags_input']);
    // запускаем пользовательский фильтр
    $load_post = apply_filters('ads_load_post', $load_post, $this);
    if( !is_array($load_post) ) return;
    unset($load_post['post_password']);
    // загружаем мета поля
    $arrCustom = get_post_custom($_REQUEST['ID']);
    foreach ($arrCustom as $key=>$value) {
        $args[$key] = maybe_unserialize( array_shift($value) );
  	}
  	// проверяем соответствие ads_form_page_id
  	// ads_form_name   - post_name страницы с формой ввода
  	global $post;
    //echo '<pre>';print_r($args);echo '</pre>';
    //echo '<pre>';print_r($post->post_name);echo '</pre>';
  	if($args['ads_form_name'] !== $post->post_name  ){
		$page = $this->get_page_by_name($args['ads_form_name']);
        unset( $page->post_content);
  		if($page){

  			$redirect = add_query_arg(array('ADS_ACTION'=>'EDIT','ID'=>$_REQUEST['ID'],
  				'ads_password'=> $_REQUEST['ads_password'], 'page_id'=>$page->ID ), home_url() );
  			wp_redirect( $redirect );
			exit;
  		}else{
        	$this->wp_error->add('load_post',
			__("Error when loading data. Record created on the page with the post_name =
			<b>{$args['ads_form_name']}</b>, it may have been removed. Current page form
			has a post_name = <b>$post->post_name</b>. Editing can be extended, but errors may occur."));
  		}
  		//$redirect = add_query_arg()
  	}

  	// загружаем теги
    if( $arr = wp_get_post_tags($_REQUEST['ID']) ){
        foreach ($arr as $key=>$value) {
           $arr2[] = $value->name;
        }
        $args['tags_input'] = implode(', ',$arr2);
        $args['array_tags'] = $arr2;
    }
    // загружаем категориии
    $args['post_category'] = wp_get_post_categories($_REQUEST['ID']);
    $_REQUEST  = array_merge($load_post,$args);
    $this->set_hidden('ID', $_REQUEST['ID']);
    if($_REQUEST['ads_password'])
    	 $this->set_hidden('ads_password', $_REQUEST['ads_password']);
    $this->set_hidden('mode', 'EDIT' );
    $this->set_hidden('ads_IP', ads_getip() );
    $this->set_hidden('ads_basecat',$_REQUEST['ads_basecat'] );


    return true;
}


/*
Кнопки управление печатаем в 2этапа поскольку нам надо
дождаться окончание сохранения данных в первый проход просто вставляем в текст
заглушки <!--ads-bottons-->
а после сохранения формы в конце генерируем набор кнопок  и вставляем в вместо этого кода
*/
/* печатаем кнопки управления */
function print_buttons($return = 0){
    $html =  '<!--ads-bottons-->';
 	if($return) return $html;
 	else echo $html;
}

function genrete_replace_bottons($html){
    global $user_ID;
    $status = get_post_status($_REQUEST['ID']);
    $trash = $this->is_trash($_REQUEST['ID']);
    if( $_REQUEST['ID'] )
        if($trash)
            $arr_bottons['PUBLISH'] =  __('Restore','ads-wordpress');
        elseif($status == 'publish' )
            $arr_bottons['PUBLISH'] =  __('Update','ads-wordpress');
        else
            $arr_bottons['PUBLISH'] =  __('Publish','ads-wordpress');
    else
        $arr_bottons['PUBLISH'] =  __('Publish','ads-wordpress');


    $arr_bottons['SAVE'] =  __('Save in draft','ads-wordpress');
    $arr_bottons['TO_TRASH'] =  __('to Trash','ads-wordpress');
    $arr_bottons['DELETE'] =  __('Delete','ads-wordpress');

    if( $trash )
        unset($arr_bottons['TO_TRASH']);

    if(!$_REQUEST['ID']){
        unset($arr_bottons['TO_TRASH'], $arr_bottons['DELETE'] );
    }

    if(current_user_can( 'edit_others_posts', $user_ID )){
        if(!( $this->options['ads_overdue_category']
            OR $this->options['ads_overdue_metafield']) )
            unset($arr_bottons['TO_TRASH']);

    }else{
        if($this->options['ads_post_status'] == 'publish'){
            unset($arr_bottons['SAVE']);
        }elseif($this->options['ads_post_status'] == 'pending'){
            unset($arr_bottons['PUBLISH']);
            $arr_bottons['SAVE'] =  __('Send to moderation','ads-wordpress');
        } else {
            unset($arr_bottons['PUBLISH']);
        }
        //если включена корзина
        if( $this->options['ads_overdue_category']
            OR $this->options['ads_overdue_metafield'])
        {
           unset($arr_bottons['DELETE']);
        }else{
           unset($arr_bottons['TO_TRASH']);
        }
    }

    foreach($arr_bottons as $key=>$value){
        $bottons .= "<button type=\"submit\" name=\"ADS_ACTION\" value=\"$key\">$value</button>";
    }

    return str_replace('<!--ads-bottons-->', $bottons, $html);
}



/* печатает форму для ввода ID и пароля записи*/
function print_login_form(){
	$ads_config = $ads_config = &$this->options;
	extract($this->options);
    ob_start();
    include_once $this->options['dir_template'].'/'.$this->options['form_login'];
    $html = ob_get_contents();
    ob_end_clean();

     if($last_post = $this->last_edited_posts() )
    	$html .= $last_post;

	include_once('FormPersister.php');
	$ob = new HTML_FormPersister();
    $html = $ob->process($html);



    $this->html = $html;
}

// сохраняет данный
function save_form(){
    // устанавливаем параметров записи

    $this->set_post_param();

    // востановление объвления из корзины
    if( $this->is_trash() ){
        $this->restore();
    }

    $this->set_status();
	//  отключаем ревизии  pre_post_update wp_save_post_revision
	remove_action('pre_post_update', 'wp_save_post_revision');
    remove_action('post_updated', 'wp_save_post_revision');  
	//  подключаем фитр для добавления полей adp_
	add_filter('wp_insert_post_data','ads_table_fields',10,2);

	if((int)$_REQUEST['ID'] ){// запись уже создана делаем обновление

		// проверяем может ли пользователь редактировать запись
		if( !($this->can_edit_post() AND get_post($_REQUEST['ID'])) ){
			$this->wp_error->add('save_form',
			__('Error in save_form() -> wp_update_post() do not update post.'));
			// возвращаем статус в исходное положение
			$_REQUEST['post_status'] = get_post_status($_REQUEST['ID']);
			return false;
		}
	    // если все нормально продолжаем
		wp_delete_object_term_relationships($_REQUEST['ID'], array('post_tag'));
		wp_delete_object_term_relationships($_REQUEST['ID'], array('category'));
		$ads_ID = wp_update_post($_REQUEST);
		if( $ads_ID != $_REQUEST['ID'] ) {
			$this->wp_error->add('save_form',
			__('Error in save_form() -> wp_update_post().'));
			$_REQUEST['post_status'] = get_post_status($_REQUEST['ID']);
			return false;
		}

	} else {  // записи еще нет, создаем новую
        $newpst=1;
		if(!$this->can_new_post())  return false;

		$ads_ID = wp_insert_post( $_REQUEST, 1 );



		if(is_wp_error($ads_ID) ) {
			$this->wp_error = $ads_ID;
			$_REQUEST['post_status'] = '';
			return false;
		} else {
			$_REQUEST['ID'] = $ads_ID;
			$this->set_hidden('ID', $ads_ID);
			$this->set_hidden('ads_password', ads_genPassword() );
			$this->set_hidden('ads_IP', ads_getip() );

		}
	}


    $this->save_meta();
    $this->ads_info_saved();
    $this->set_cookies();
    $this->ads_clear_cache();
    $this->send_mail();

    //if( isset($newpst) ){
        $my_post = array(
            'ID' => $ads_ID,
            'post_status' => 'hidden',
        );

        wp_update_post($my_post);
    //}

    header("Location: ".$_SERVER['HTTP_REFERER']);
    die;
	return $ads_ID;
}


// удаляет пост
function delete(){
	global $user_ID;
	if($this->options['ads_overdue_category']	OR $this->options['ads_overdue_metafield'])
		$this->trash = 1;
	else $this->trash = 0;

	if(current_user_can( 'edit_others_posts', $user_ID )){
		if( $this->action == 'TO_TRASH' AND $this->trash )
			$this->to_trash();
		else $this->delete_post();
	} else {
		if( $this->trash )
			$this->to_trash();
		else
			$this->delete_post();
	}
}

// запрос изменения \ сохранения \ добавления записи
function is_save(){
    if( in_array($this->action, array('UPDATE', 'PUBLISH', 'SAVE') ) )
        return true;
    else
        return false;
}
// если мы в режиме редактирования
function is_edit(){
	if($this->mode == 'EDIT') return true;
	else return false;
}
// если создаем новый пост
function is_newpost(){
	if($this->mode == 'NEW') return true;
	else return false;
}
/*
проверяет коректность пользовательского пароля
или если у паользователя есть права доступа к этой записи  admin или редактор
*/
function can_edit_post(){
    global $user_ID;
    if(!(int)$_REQUEST['ID']){
    	$this->wp_error->add('can_edit', __( 'Enter ID and password ','ads-wordpress') );
    	return false;
    }
    if($user_ID)
        if(user_can_edit_post($user_ID, $_REQUEST['ID']))
            return true;

    $ads_password = get_post_meta($_REQUEST['ID'], 'ads_password', 1);
    if( $ads_password AND ($ads_password == $_REQUEST['ads_password']) ){
        if( $this->is_trash($_REQUEST['ID']) AND !$this->options['ads_restore'] ){
            $this->wp_error->add('can_edit', __('Error, You do not edit this entry!','ads-wordpress'));
            $this->wp_error->add('can_edit', __('Post is trash, recovery is prohibited','ads-wordpress'));
            return false;
        }
        else
            return true;
    }else {
        $this->wp_error->add('can_edit', __('Error, incorrect password or ID, do not edit this entry!','ads-wordpress'));
        return false;
    }

}
/*
проверяет может ли пользователь создатть новую запись
контролирует интервал новых сообщений
*/
function can_new_post(){
    global $ads_options,$user_ID;

    if(class_exists('Ads_Antispam')){
        global $ads_ob_antispam;
        if(!$ads_ob_antispam)
            $ads_ob_antispam = new Ads_Antispam($this);
        $ads_ob_antispam->check_ip();
        $ads_ob_antispam->check_auto_ip();
        if($ads_ob_antispam->_post['error']['ads_ip']) {
            $this->wp_error->add('can_new_post', implode('<br>', $ads_ob_antispam->_post['error']['ads_ip']));
             return false;
        }
    }

    if(current_user_can( 'edit_others_posts', $user_ID ))
    	return true;
    $timeInterval = (real)$this->options['ads_interval']*3600;
    if(!$timeInterval) return true;
    if( time() - (int)$_COOKIE['ads_lastnew'] < $timeInterval){
    	$ostTime = $timeInterval + (int)$_COOKIE['ads_lastnew'] - time();
        $min = floor($ostTime / 60);
        $sec = $ostTime % 60;
        $hors = floor($min/60);
        $min = $min % 60;
        $this->wp_error->add('can_new_post', sprintf (__("Sorry, you can write next entry after %d hours %d min %d sec. <br /><a target='_top' href = './'>Home page</a>",'ads-wordpress'),$hors,$min,$sec) );
        if($last_post = $this->last_edited_posts() )
        	$this->html_info .= $last_post;
        return false;
    }
    return true;
}

/* функция проверяет возможность удаления поста  */
function  can_delete(){
	global $user_ID;
	if($user_ID)
		if(user_can_delete_post($user_ID, $_REQUEST['ID']))
			return true;


    $ads_password = get_post_meta($_REQUEST['ID'], 'ads_password', 1);
    if( $ads_password AND ($ads_password == $_REQUEST['ads_password']) )
    	return true;
    else {
        $this->wp_error->add('can_delete',
        __('Error, incorrect password or ID, do not delete this entry!',
        'ads-wordpress'));
        return false;
    }
}

}// end class Ads_Form_Handler