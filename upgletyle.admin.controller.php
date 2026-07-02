<?php
    /**
     * @class  upgletyleAdminController
     * @author UPGLE (admin@upgle.com)
     * @brief  upgletyle module admin controller class
     **/

    class upgletyleAdminController extends upgletyle {

        /**
         * @brief Initialization
         **/
        function init() {
        }

        /**
         * @brief Upgletyle Admin Create
         **/
        function procUpgletyleAdminCreate() {
            $oModuleModel = &getModel('module');

            $user_id = Context::get('user_id');
			if(!$user_id) return new BaseObject(-1,'msg_invalid_request');

            $access_type = Context::get('access_type');
            $domain = preg_replace('/^(http|https):\/\//i','', trim(Context::get('domain')));

			if($access_type == 'local') $domain = 0;
			if($access_type != 'local' && !$domain) return new BaseObject(-1,'msg_invalid_request');


            $tmp_user_id_list = explode(',',$user_id);
            $user_id_list = array();
            foreach($tmp_user_id_list as $k => $v){
                $v = trim($v);
                if($v) $user_id_list[] = $v;
            }

            if(count($user_id_list)==0) return new BaseObject(-1,'msg_invalid_request');


            $output = $this->insertUpgletyle($domain, $user_id_list);
            if(!$output->toBool()) return $output;

            $this->add('module_srl', $output->get('module_srl'));
            $this->setMessage('msg_create_upgletyle');
        }

        function insertUpgletyle($domain, $user_id_list, $settings = null) {
            $upgletyle = new stdClass();
            $args = new stdClass();
            $obj = new stdClass();
            $doc = new stdClass();
            if(!is_array($user_id_list)) $user_id_list = array($user_id_list);

            $oMemberModel = &getModel('member');
            $oModuleModel = &getModel('module');
            $oModuleController = &getController('module');
            $oRssAdminController = &getAdminController('rss');
            $oUpgletyleModel = &getModel('upgletyle');
            $oUpgletyleController = &getController('upgletyle');
            $oDocumentController = &getController('document');

            $memberConfig = $oMemberModel->getMemberConfig();
            foreach($memberConfig->signupForm as $item){
            	if($item->isIdentifier) $identifierName = $item->name;
            }
            if($identifierName == "user_id") {
            	$member_srl = $oMemberModel->getMemberSrlByUserID($user_id_list[0]);
            	}
            else {
            	$member_srl = $oMemberModel->getMemberSrlByEmailAddress($user_id_list[0]);
            }
            if(!$member_srl) return new BaseObject(-1,'msg_not_user');
            $member_info = $oMemberModel->getMemberInfoByMemberSrl($member_srl);

			//insert a upgletyle module (domain_srl -1 = shared/local install, no dedicated domain)
            $upgletyle->domain_srl = -1;
            $upgletyle->mid = $this->upgletyle_mid;
            $upgletyle->module = 'upgletyle';
            $upgletyle->module_srl = getNextSequence();
            $upgletyle->skin = ($settings->skin) ? $settings->skin : $this->skin;
            $upgletyle->browser_title = ($settings->title) ? $settings->title : sprintf("%s's Upgletyle", $member_info->nick_name);

            $output = $oModuleController->insertModule($upgletyle);
            if(!$output->toBool()) return $output;

			$module_srl = $upgletyle->module_srl;

			//if a dedicated domain was requested, create it and point it at this module
			if($domain)
			{
				if(strpos($domain, '.') !== false) $domain = strtolower($domain);

				$domain_args = new stdClass();
				$domain_args->domain_srl = getNextSequence();
				$domain_args->domain = $domain;
				$domain_args->is_default_domain = 'N';
				$domain_args->index_module_srl = $module_srl;
				$domain_args->index_document_srl = 0;
				$domain_args->http_port = null;
				$domain_args->https_port = null;
				$domain_args->security = 'none';
				$domain_args->description = '';
				$domain_args->settings = json_encode(array('title' => $upgletyle->browser_title));
				$output = executeQuery('module.insertDomain', $domain_args);
				if(!$output->toBool()) return $output;

				$upgletyle->domain_srl = $domain_args->domain_srl;
				$output = $oModuleController->updateModule($upgletyle);
				if(!$output->toBool()) return $output;
			}

            $args->upgletyle_title = $upgletyle->browser_title;
            $args->module_srl = $module_srl;
            $args->member_srl = $member_srl;
            $args->post_style = $this->post_style;
            $args->post_list_count = $this->post_list_count;
            $args->comment_list_count = $this->comment_list_count;
            $args->guestbook_list_count = $this->guestbook_list_count;
            $args->input_email = $this->input_email;//'R'; // Y, N
            $args->input_website = $this->input_website;//'R'; // Y, N
            $args->post_editor_skin = $this->post_editor_skin;
            $args->post_use_prefix = $this->post_use_prefix;
            $args->post_use_suffix = $this->post_use_suffix;
            $args->comment_editor_skin = 'xpresseditor';
            $args->comment_editor_colorset = 'white';
            $args->guestbook_editor_skin = 'xpresseditor';
            $args->guestbook_editor_colorset = 'white';
            $args->timezone = $GLOBALS['_time_zone'];
            $output = executeQuery('upgletyle.insertUpgletyle', $args);
            if(!$output->toBool()) return $output;

            $oUpgletyleController->updateUpgletyleCommentEditor($module_srl, $args->comment_editor_skin, $args->comment_editor_colorset);

            $output = $oRssAdminController->setRssModuleConfig($module_srl, 'Y', 'Y');
            if(!$output->toBool()) return $output;

            // set category
            $obj->module_srl = $module_srl;
            $obj->title = Context::getLang('init_category_title');
            $oDocumentController->insertCategory($obj);

            FileHandler::copyDir($this->module_path.'skins/'.$upgletyle->skin, $oUpgletyleModel->getUpgletylePath($module_srl));

            foreach($user_id_list as $k => $v){
                $output = $oModuleController->insertAdminId($module_srl, $v);
                if(!$output->toBool()) return $output;
            }

            $langType = Context::getLangType();
            $file = sprintf('%ssample/%s.html',$this->module_path,$langType);
            if(!file_exists(FileHandler::getRealPath($file))){
                $file = sprintf('%ssample/ko.html',$this->module_path);
            }
            $oMemberModel = &getModel('member');         
			if($identifierName == "user_id") {
            	$member_srl = $oMemberModel->getMemberSrlByUserID($user_id_list[0]);
            	}
            else {
            	$member_srl = $oMemberModel->getMemberSrlByEmailAddress($user_id_list[0]);
            }
            $doc->module_srl = $module_srl;
            $doc->title = Context::getLang('sample_title');
            $doc->tags = Context::getLang('sample_tags');
            $doc->content = FileHandler::readFile($file);
            $doc->member_srl = $member_info->member_srl;
            $doc->user_id = $member_info->user_id;
            $doc->user_name = $member_info->user_name;
            $doc->nick_name = $member_info->nick_name;
            $doc->email_address = $member_info->email_address;
            $doc->homepage = $member_info->homepage;
            $output = $oDocumentController->insertDocument($doc, true);

            $output = new BaseObject();
            $output->add('module_srl',$module_srl);
            return $output;
        }

        function procUpgletyleAdminUpdate(){

			//get Module's model and controller
			$oModuleModel = getModel('module');
            $oModuleController = getController('module');

			$vars = Context::gets('user_id','domain','access_type','module_srl');
			if(!$vars->module_srl) return new BaseObject(-1,'msg_invalid_request');

			$domain = ($vars->access_type == 'domain') ? strtolower(trim($vars->domain)) : '';
            if(!$domain && $vars->access_type != 'local')
				return new BaseObject(-1,'msg_invalid_request');

			$module_info = $oModuleModel->getModuleInfoByModuleSrl($vars->module_srl);
			if(!$module_info) return new BaseObject(-1,'msg_invalid_request');

			$old_domain_srl = intval($module_info->domain_srl);
			$new_domain_srl = $old_domain_srl;

			//Change domain assignment of the upgletyle module
			if($vars->access_type == 'local' && $old_domain_srl > 0)
			{
				//switching from a dedicated domain back to the shared/local install
				$new_domain_srl = -1;

				//insert menu (module is no longer the index of its own domain)
				$oMenuAdminController = getAdminController('menu');

				$menuSrl = $oMenuAdminController->getUnlinkedMenu();
				$menuArgs = new stdClass();
				$menuArgs->menu_srl = $menuSrl;
				$menuArgs->menu_item_srl = getNextSequence();
				$menuArgs->parent_srl = 0;
				$menuArgs->open_window = 'N';
				$menuArgs->url = $module_info->mid;
				$menuArgs->expand = 'N';
				$menuArgs->is_shortcut = 'N';
				$menuArgs->name = $module_info->browser_title;
				$menuArgs->listorder = $menuSrl * -1;

				$menuItemOutput = executeQuery('menu.insertMenuItem', $menuArgs);
				if(!$menuItemOutput->toBool()) return $menuItemOutput;
				$oMenuAdminController->makeXmlFile($menuSrl);

				//update module's menu_srl
				$_args = new stdClass();
				$_args->menu_srl = $menuArgs->menu_srl;
				$_args->mid = $module_info->mid;
				$output = $oModuleController->updateModuleMenu($_args);
				if(!$output->toBool()) return $output;
			}
			elseif($vars->access_type != 'local' && $old_domain_srl <= 0)
			{
				//switching from the shared/local install to a dedicated domain

				//Delete menu (module becomes the index of its own domain)
				$oMenuAdminController = getAdminController('menu');

				$_args = new stdClass();
				$_args->url = $module_info->mid;
				$output = executeQuery('menu.getMenuItemByUrl', $_args);
				if($output->data)
				{
					$_args = new stdClass;
					$_args->menu_srl = $output->data->menu_srl;
					$_args->menu_item_srl = $output->data->menu_item_srl;

					$output = executeQuery('menu.getChildMenuCount', $_args);
					if(!$output->toBool()) return $output;
					if($output->data->count > 0)
					{
						return new BaseObject(-1, 'msg_cannot_delete_for_child');
					}
					$output = executeQuery('menu.deleteMenuItem', $_args);
					$oMenuAdminController->makeXmlFile($_args->menu_srl);
				}

				$domain_args = new stdClass();
				$domain_args->domain_srl = getNextSequence();
				$domain_args->domain = $domain;
				$domain_args->is_default_domain = 'N';
				$domain_args->index_module_srl = $vars->module_srl;
				$domain_args->index_document_srl = 0;
				$domain_args->http_port = null;
				$domain_args->https_port = null;
				$domain_args->security = 'none';
				$domain_args->description = '';
				$domain_args->settings = json_encode(array('title' => $module_info->browser_title));
				$output = executeQuery('module.insertDomain', $domain_args);
				if(!$output->toBool()) return $output;

				$new_domain_srl = $domain_args->domain_srl;
			}
			elseif($vars->access_type == 'domain' && $old_domain_srl > 0 && $domain)
			{
				//already on a dedicated domain; the domain name itself may have changed
				$existing = $oModuleModel->getSiteInfo($old_domain_srl);
				if($existing && $existing->domain_srl == $old_domain_srl)
				{
					$domain_args = new stdClass();
					$domain_args->domain_srl = $old_domain_srl;
					$domain_args->domain = $domain;
					$domain_args->index_module_srl = $vars->module_srl;
					$domain_args->index_document_srl = $existing->index_document_srl;
					$domain_args->http_port = $existing->http_port;
					$domain_args->https_port = $existing->https_port;
					$domain_args->security = $existing->security;
					$domain_args->settings = json_encode(get_object_vars($existing->settings));
					$output = executeQuery('module.updateDomain', $domain_args);
					if(!$output->toBool()) return $output;
				}
			}

			if($new_domain_srl != $old_domain_srl)
			{
				$module_info->domain_srl = $new_domain_srl;
				$output = $oModuleController->updateModule($module_info);
				if(!$output->toBool()) return $output;
			}

            $oMemberModel = &getModel('member');
			$member_config = $oMemberModel->getMemberConfig();

            $tmp_member_list = explode(',',$vars->user_id);
            $admin_list = array();
            $admin_member_srl = array();
            foreach($tmp_member_list as $k => $v){
                $v = trim($v);
                if($v){
	                if($member_config->identifier == "user_id") {
		            	$member_srl = $oMemberModel->getMemberSrlByUserID($v);
		            	}
		            else {
		            	$member_srl = $oMemberModel->getMemberSrlByEmailAddress($v);
		            }
                    if($member_srl){
                        $admin_list[] = $v;
                        $admin_member_srl[] = $member_srl;
                    }else{
                        return new BaseObject(-1,'msg_not_user');
                    }
                }
            }

            $oModuleController->deleteAdminId($vars->module_srl);
            foreach($admin_list as $k => $v){
                $output = $oModuleController->insertAdminId($vars->module_srl, $v);
                // TODO : insertAdminId return value
                if(!$output) return new BaseObject(-1,'msg_not_user');
                if(!$output->toBool()) return $output;
            }

            $args = new stdClass();
            $args->module_srl = $vars->module_srl;
            $args->member_srl = $admin_member_srl[0];
            $output = executeQuery('upgletyle.updateUpgletyle', $args);
            if(!$output->toBool()) return $output;

            $output = new BaseObject(1,'success_updated');
            $output->add('module_srl',$vars->module_srl);
            return $output;
        }

        function procUpgletyleAdminSwitch() {
            $args = new stdClass();
            $site_srl = Context::get('domain_srl');
            $skin = Context::get('skin');

            if(!$site_srl) return new BaseObject(-1,'msg_invalid_request');
            $oModuleModel = &getModel('module');
            $site_info = $oModuleModel->getSiteInfo($site_srl);
            $module_srl = $site_info->index_module_srl;
			$module_info = $oModuleModel->getModuleInfoByModuleSrl($module_srl);

			if($module_info->module == 'upgletyle') {
				
				//get upgletyle data
				$args->module_srl = $module_srl;
				$output = executeQuery('upgletyle.getUpgletyleOnly', $args);
				if(!$output->toBool()) return $output;
				else $upgletyle_data = $output->data;

				unset($args);
            $args = new stdClass();
				$args->textyle_title = $upgletyle_data->upgletyle_title;
				$args->textyle_content = $upgletyle_data->upgletyle_content;
				$args->profile_content = $upgletyle_data->profile_content;
				$args->post_prefix = $upgletyle_data->post_prefix;
				$args->post_suffix = $upgletyle_data->post_suffix;
				$args->subscription_date = $upgletyle_data->subscription_date;
				$args->module_srl = $upgletyle_data->module_srl;
				$args->member_srl = $upgletyle_data->member_srl;
				$args->post_style = $upgletyle_data->post_style;
				$args->post_list_count = $upgletyle_data->post_list_count;
				$args->comment_list_count = $upgletyle_data->comment_list_count;
				$args->guestbook_list_count = $upgletyle_data->guestbook_list_count;
				$args->input_email = $upgletyle_data->input_email;
				$args->input_website = $upgletyle_data->input_website;
				$args->post_editor_skin = $upgletyle_data->post_editor_skin;
				$args->post_use_prefix = $upgletyle_data->post_use_prefix;
				$args->post_use_suffix = $upgletyle_data->post_use_suffix;
				$args->comment_editor_skin = $upgletyle_data->comment_editor_skin;
				$args->comment_editor_colorset = $upgletyle_data->comment_editor_colorset;
				$args->guestbook_editor_skin = $upgletyle_data->guestbook_editor_skin;
				$args->guestbook_editor_colorset = $upgletyle_data->guestbook_editor_colorset;
				$args->timezone = $upgletyle_data->timezone;
				$output = executeQuery('textyle.insertTextyle', $args);
				if(!$output->toBool()) return $output;

				unset($args);
            $args = new stdClass();
				$args->module_srl = $module_srl;
				$output = executeQuery('upgletyle.deleteUpgletyle', $args);
				if(!$output->toBool()) return $output;

				unset($args);
            $args = new stdClass();
				$args->mid = 'textyle';
				$args->module = 'textyle';
			}
			elseif($module_info->module == 'textyle') {

				//get textyle data
				$args->module_srl = $module_srl;
				$output = executeQuery('upgletyle.getTextyleOnly', $args);
				if(!$output->toBool()) return $output;
				else $textyle_data = $output->data;

				unset($args);
            $args = new stdClass();
				$args->upgletyle_title = $textyle_data->textyle_title;
				$args->upgletyle_content = $textyle_data->textyle_content;
				$args->profile_content = $textyle_data->profile_content;
				$args->post_prefix = $textyle_data->post_prefix;
				$args->post_suffix = $textyle_data->post_suffix;
				$args->subscription_date = $textyle_data->subscription_date;
				$args->module_srl = $textyle_data->module_srl;
				$args->member_srl = $textyle_data->member_srl;
				$args->post_style = $textyle_data->post_style;
				$args->post_list_count = $textyle_data->post_list_count;
				$args->comment_list_count = $textyle_data->comment_list_count;
				$args->guestbook_list_count = $textyle_data->guestbook_list_count;
				$args->input_email = $textyle_data->input_email;
				$args->input_website = $textyle_data->input_website;
				$args->post_editor_skin = $textyle_data->post_editor_skin;
				$args->post_use_prefix = $textyle_data->post_use_prefix;
				$args->post_use_suffix = $textyle_data->post_use_suffix;
				$args->comment_editor_skin = $textyle_data->comment_editor_skin;
				$args->comment_editor_colorset = $textyle_data->comment_editor_colorset;
				$args->guestbook_editor_skin = $textyle_data->guestbook_editor_skin;
				$args->guestbook_editor_colorset = $textyle_data->guestbook_editor_colorset;
				$args->timezone = $textyle_data->timezone;
				$output = executeQuery('upgletyle.insertUpgletyle', $args);
				if(!$output->toBool()) return $output;

				unset($args);
            $args = new stdClass();
				$args->module_srl = $module_srl;
				$output = executeQuery('textyle.deleteTextyle', $args);
				if(!$output->toBool()) return $output;

				unset($args);
            $args = new stdClass();
				$args->mid = 'upgletyle';
				$args->module = 'upgletyle';
			}
	
			$args->site_srl = $site_srl;
            $args->module_srl = $module_srl;
			$args->skin = $skin;
            $oModuleController = &getController('module');
            $output = $oModuleController->updateModule($args);
            if(!$output->toBool()) return $output;

			//Skin reset
			if($module_info->module == 'upgletyle') {
				$oTextyleController = &getController('textyle');
				$oTextyleController->resetSkin($module_srl, $skin);
			}
			elseif($module_info->module == 'textyle') {
				$oUpgletyleController = &getController('upgletyle');
				$oUpgletyleController->resetSkin($module_srl, $skin);
			}

            $this->add('module','upgletyle');
            $this->add('page',Context::get('page'));
            $this->setMessage('success_switched');
		}

        function procUpgletyleAdminDelete() {

            $site_srl = Context::get('site_srl');
            $module_srl = Context::get('module_srl');
            if(!$module_srl) return new BaseObject(-1,'msg_invalid_request');

            $oUpgletyle = new UpgletyleInfo($module_srl);
            if($oUpgletyle->module_srl != $module_srl) return new BaseObject(-1,'msg_invalid_request');
            
			$oModuleController = &getController('module');
            $output = $oModuleController->deleteModule($module_srl);
            if(!$output->toBool()) return $output;

            $this->add('module','upgletyle');
            $this->add('page',Context::get('page'));
            $this->setMessage('success_deleted');
        }

        function procUpgletyleAdminInsertCustomMenu() {
            $oModuleController = &getController('module');
            $oModuleModel = &getModel('module');

            $config = $oModuleModel->getModuleConfig('upgletyle');
            $second_menus = Context::getLang('upgletyle_second_menus');

            $args = Context::getRequestVars();
            foreach($args as $key => $val) {
                if(strpos($key, 'hidden_')===false || $val!='Y') continue;
                $k = substr($key, 7);
                if(preg_match('/^([0-9]+)$/', $k)) {
                    $subs = $second_menus[$k];
                    if(count($subs)) {
                        $h = array_keys($subs);
                        for($i=0,$c=count($h);$i<$c;$i++) $hidden_menu[] = strtolower($h[$i]);
                    }
                }
                $hidden_menu[] = $k;
            }

            $config->hidden_menu = $hidden_menu;

            if(!$config->attached_menu || !is_array($config->attached_menu)) $config->attached_menu = array();

            $attached = array();
            foreach($args as $key => $val) {
                if(strpos($key, 'custom_act_')!==false && $val) {
                    $idx = substr($key, 11);
                    $attached[$idx]->act = $val;
                } elseif(strpos($key, 'custom_name_')!==false && $val) {
                    $idx = substr($key, 12);
                    $attached[$idx]->name = $val;

                }
            }

            if(count($attached)) {
                foreach($attached as $key => $val) {
                    if(!$val->act || !$val->name) continue;
                    $config->attached_menu[$key][$val->act] = $val->name;
                }
            }

            foreach($args as $key => $val) {
                if(strpos($key, 'delete_')===false || $val!='Y') continue;
                $delete_menu[] = substr($key, 7);
            }

            if(count($delete_menu)) {
                foreach($config->attached_menu as $key => $val) {
                    if(!count($val)) continue;
                    foreach($val as $k => $v) {
                        if(in_array(strtolower($k), $delete_menu)) unset($config->attached_menu[$key][$k]);
                    }
                }
            }
            $oModuleController->insertModuleConfig('upgletyle', $config);
        }

        function procUpgletyleAdminInsertBlogApiServices(){
            $args = Context::getRequestVars();

            if($args->textyle_blogapi_services_srl){
                $output = executeQuery('upgletyle.updateBlogApiService',$args);
            }else{
                $args->textyle_blogapi_services_srl = getNextSequence();
                $args->list_order = $args->textyle_blogapi_services_srl * -1;
                $output = executeQuery('upgletyle.insertBlogApiService',$args);
            }
        }

        function procUpgletyleAdminDeleteBlogApiServices(){
            $args = new stdClass();
            $args->textyle_blogapi_services_srl = Context::get('textyle_blogapi_services_srl');
            $output = executeQuery('upgletyle.deleteBlogApiService',$args);
            return $output;
        }

        function initUpgletyle($site_srl){
            $args = new stdClass();
            $obj = new stdClass();
            $doc = new stdClass();
            $oCounterController = &getController('counter');
            $oDocumentController = &getController('document');
            $oCommentController = &getController('comment');
            $oTagController = &getController('tag');
            $oAddonController = &getController('addon');
            $oEditorController = &getController('editor');
            // the trackback module was removed from Rhymix (and XE before it) due to spam abuse
            $oTrackbackController = &getController('trackback');
            $oModuleModel = &getModel('module');
            $oUpgletyleModel = &getModel('upgletyle');
            $oMemberModel = &getModel('member');

            $site_info = $oModuleModel->getSiteInfo($site_srl);
            $module_srl = $site_info->index_module_srl;
            $args->site_srl = $site_srl;

            $oUpgletyle = new UpgletyleInfo($module_srl);
            if($oUpgletyle->module_srl != $module_srl) return new BaseObject(-1,'msg_invalid_request');

            $oCounterController->deleteSiteCounterLogs($args->site_srl);
            $oAddonController->removeAddonConfig($args->site_srl);

            $args->module_srl = $module_srl;
            $output = executeQuery('upgletyle.deleteUpgletyleFavorites', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleTags', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleVoteLogs', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleMemos', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleReferer', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleApis', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleGuestbook', $args);
            $output = executeQuery('upgletyle.deleteUpgletyleSupporters', $args);
            $output = executeQuery('upgletyle.deletePublishLogs', $args);

            FileHandler::removeFile(sprintf("./files/cache/upgletyle/textyle_deny/%d.php",$module_srl));
            FileHandler::removeDir($oUpgletyleModel->getUpgletylePath($module_srl));

            // delete document comment tag
            $output = $oDocumentController->triggerDeleteModuleDocuments($args);
            $output = $oCommentController->triggerDeleteModuleComments($args);
            $output = $oTagController->triggerDeleteModuleTags($args);
            if($oTrackbackController) $output = $oTrackbackController->triggerDeleteModuleTrackbacks($args);
            $args->module_srl = $args->module_srl *-1;

            $output = $oDocumentController->triggerDeleteModuleDocuments($args);
            $output = $oCommentController->triggerDeleteModuleComments($args);
            $output = $oTagController->triggerDeleteModuleTags($args);
            $args->module_srl = $args->module_srl *-1;

            // set category
            $obj->module_srl = $module_srl;
            $obj->title = Context::getLang('init_category_title');
            $oDocumentController->insertCategory($obj);

            FileHandler::copyDir($this->module_path.'skins/'.$this->skin, $oUpgletyleModel->getUpgletylePath($module_srl));

            $langType = Context::getLangType();
            $file = sprintf('%ssample/%s.html',$this->module_path,$langType);
            if(!file_exists(FileHandler::getRealPath($file))){
                $file = sprintf('%ssample/ko.html',$this->module_path);
            }

            $member_info = $oMemberModel->getMemberInfoByEmailAddress($oUpgletyle->getUserId());

            $doc->module_srl = $module_srl;
            $doc->title = Context::getLang('sample_title');
            $doc->tags = Context::getLang('sample_tags');
            $doc->content = FileHandler::readFile($file);
            $doc->member_srl = $member_info->member_srl;
            $doc->user_id = $member_info->user_id;
            $doc->user_name = $member_info->user_name;
            $doc->nick_name = $member_info->nick_name;
            $doc->email_address = $member_info->email_address;
            $doc->homepage = $member_info->homepage;
            $output = $oDocumentController->insertDocument($doc, true);

            return new BaseObject(1,'success_upgletyle_init');
        }

		function exportUpgletyle($site_srl,$export_type='ttxml'){
            $args = new stdClass();
            require_once($this->module_path.'libs/exportUpgletyle.php');
			//$this->deleteExport($site_srl);

			$path = './files/cache/upgletyle/export/' . getNumberingPath($site_srl);
			FileHandler::makeDir($path);
			$file = $path.sprintf('tt-%s.xml',date('YmdHis'));

			// $upgletyle_srl 
			$oModuleModel = &getModel('module');
			$site_info = $oModuleModel->getSiteInfo($site_srl);
			$upgletyle_srl = $site_info->index_module_srl;

			$oExport = new TTXMLExport($file);
			$oExport->setUpgletyle($upgletyle_srl);
			$oExport->exportFile();

			$args->site_srl = $site_srl;
			$args->export_file = $file;
			$output = executeQuery('upgletyle.updateExport',$args);
			if(!$output->toBool()) return $output;
		}

		function procUpgletyleAdminExport(){
			$args = new stdClass();
			$site_srl = Context::get('site_srl');
			if(!$site_srl) $site_srl = $this->module_info->domain_srl;
			if(!$site_srl) return new BaseObject(-1,'msg_invalid_request');
			$export_type = Context::get('export_type');
			if(!$export_type) $export_type = 'ttxml';
			
			$args->site_srl = $site_srl;
			$output = executeQuery('upgletyle.getExport',$args);
			if(!$output->data){
				if(!$args->export_type || $args->export_type!='xexml') $args->export_type='ttxml';
				$logged_info = Context::get('logged_info');
				$args->module_srl = $this->module_srl;
				$args->member_srl = $logged_info->member_srl;
				$output = executeQuery('upgletyle.insertExport',$args);
			}

			$this->exportUpgletyle($site_srl,$export_type);
		}

		function procUpgletyleAdminDeleteExportUpgletyle(){
			$site_srl = Context::get('site_srl');
			if(!$site_srl) return new BaseObject(-1,'msg_invalid_request');

			$this->deleteExport($site_srl);
		}

		function deleteExport($site_srl){
			$args = new stdClass();
			$args->site_srl = $site_srl;
			$output = executeQuery('upgletyle.getExport',$args);

			if($output->data){
				FileHandler::removeFile($output->data->export_file);
				$args->site_srl = $site_srl;
				$output = executeQuery('upgletyle.deleteExport',$args);
				if(!$output->toBool()) return false;
			}
		}

		function procUpgletyleAdminInsertExtraMenuConfig(){
			$module_srl = Context::get('module_srl');

            $oModuleController = &getController('module');
            $oUpgletyleModel = &getModel('upgletyle');

			$vars = Context::getRequestVars();
			$allow_service = array();
            foreach($vars as $key => $val) {
                if(strpos($key,'allow_service_')===false) continue;
                $allow_service[substr($key, strlen('allow_service_'))] = $val;
            }

			$config = $oUpgletyleModel->getModulePartConfig($module_srl);
			$config->allow_service = $allow_service;

			if($module_srl){
                $oModuleController->insertModulePartConfig('upgletyle', $module_srl, $config);

			}else{
                $oModuleController->insertModuleConfig('upgletyle', $config);
			}
		}
    }
?>
