<?php
    /**
     * @class  upgletyle
     * @author UPGLE (admin@upgle.com)
     * @brief  upgletyle module main class
     **/

    require_once(_XE_PATH_.'modules/upgletyle/upgletyle.info.php');

    class upgletyle extends ModuleObject {

        /**
         * @berif default mid
         **/
        var $upgletyle_mid = 'upgletyle';

        /**
         * @berif default skin
         **/
        var $skin = 'happyLetter';
        var $mskin = 'default';

        // post list
        var $post_style = 'content';//,'summary','list'
        var $post_list_count = 1;

        // list count
        var $comment_list_count = 30;
        var $guestbook_list_count = 30;

        // guestbook and comment input require
        var $input_email = 'R';//,'Y','N;
        var $input_website = 'R';//'Y','N';
        var $post_editor_skin = "dreditor";

        var $post_use_prefix = 'Y';//'Y','N';
        var $post_use_suffix = 'Y';//'Y','N';

        var $search_option = array('title','content','title_content','comment','user_name','nick_name','user_id','tag'); ///< 검색 옵션
        var $order_target = array('list_order', 'update_order', 'regdate', 'voted_count', 'readed_count', 'comment_count', 'title'); // 정렬 옵션

        var $add_triggers = array(
            array('display', 'upgletyle', 'controller', 'triggerMemberMenu', 'before'),
            array('comment.insertComment', 'upgletyle', 'controller', 'triggerInsertComment', 'after'),
            array('comment.deleteComment', 'upgletyle', 'controller', 'triggerDeleteComment', 'after'),
            array('trackback.insertTrackback', 'upgletyle', 'controller', 'triggerInsertTrackback', 'after'),
            array('trackback.deleteTrackback', 'upgletyle', 'controller', 'triggerDeleteTrackback', 'after'),
            array('moduleHandler.proc', 'upgletyle', 'controller', 'triggerApplyLayout', 'after'),
            array('module.deleteModule', 'upgletyle', 'controller', 'triggerDeleteModule', 'after'),
            array('moduleHandler.init', 'upgletyle', 'controller', 'triggerModuleInitBefore', 'before')
        );

        /**
         * @brief module install
         **/
        function moduleInstall() {
            $oModuleController = &getController('module');

            foreach($this->add_triggers as $trigger) {
                $oModuleController->insertTrigger($trigger[0], $trigger[1], $trigger[2], $trigger[3], $trigger[4]);
            }
        }

        /**
         * @brief check for update method
         **/
        function checkUpdate() {
            $oDB = &DB::getInstance();
            $oModuleModel = &getModel('module');

            foreach($this->add_triggers as $trigger) {
                if(!$oModuleModel->getTrigger($trigger[0], $trigger[1], $trigger[2], $trigger[3], $trigger[4])) return true;
            }

			//colum check
			if(!$oDB->isColumnExists("upgletyle","category_list_count")) return true;
			if(!$oDB->isColumnExists("upgletyle","permalink")) return true;

			//legacy site_srl -> domain_srl migration (virtual site -> multidomain)
			//only run once core has finished migrating sites -> domains
			if($oDB->isTableExists('domains') && $oModuleModel->getDefaultDomainInfo())
			{
				$args = new stdClass();
				$args->module = 'upgletyle';
				$output = executeQueryArray('upgletyle.getUpgletyleLegacySiteSrl', $args);
				if($output->toBool() && $output->data) return true;
			}

            return false;
        }

        /**
         * @brief module update
         **/
        function moduleUpdate() {
            $oDB = &DB::getInstance();
            $oModuleModel = &getModel('module');
            $oModuleController = &getController('module');

            foreach($this->add_triggers as $trigger) {
                if(!$oModuleModel->getTrigger($trigger[0], $trigger[1], $trigger[2], $trigger[3], $trigger[4])) {
                    $oModuleController->insertTrigger($trigger[0], $trigger[1], $trigger[2], $trigger[3], $trigger[4]);
                }
            }

			//post_list_count 컬럼 추가
			if(!$oDB->isColumnExists("upgletyle","category_list_count")) $oDB->addColumn('upgletyle',"category_list_count","varchar",2,30,true);
			if(!$oDB->isColumnExists("upgletyle","permalink")) $oDB->addColumn('upgletyle',"permalink","varchar",40,'permalink_default',true);

			//legacy site_srl -> domain_srl migration (virtual site -> multidomain).
			//core's own module.moduleUpdate() migrates the old 'sites' table into 'domains',
			//reusing the same numeric id (domain_srl = old site_srl). Wait for that to finish first,
			//then point any upgletyle module row that still has a legacy nonzero site_srl at the
			//matching domain_srl (they are guaranteed to be the same id by core's migration).
			if($oDB->isTableExists('domains') && $oModuleModel->getDefaultDomainInfo())
			{
				$args = new stdClass();
				$args->module = 'upgletyle';
				$output = executeQueryArray('upgletyle.getUpgletyleLegacySiteSrl', $args);
				if($output->toBool() && $output->data)
				{
					foreach($output->data as $row)
					{
						$migrate_args = new stdClass();
						$migrate_args->module_srl = $row->module_srl;
						$migrate_args->domain_srl = $row->site_srl;
						executeQuery('upgletyle.updateUpgletyleModuleDomainSrl', $migrate_args);
					}
					Rhymix\Framework\Cache::clearGroup('site_and_module');
				}
			}

			return new BaseObject(0, 'success_updated');
        }

        /**
         * @brief recompile cache
         **/
        function recompileCache() {
        }


        function checkXECoreVersion($requried_version){
			$result = version_compare(__ZBXE_VERSION__,$requried_version,'>=');
			if($result != 1) return false;

			return true;
        }
    }
?>
