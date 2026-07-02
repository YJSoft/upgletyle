<?php
    class blogger {
        var $url = null;
        var $user_id = null;
        var $password = null;
        var $blogid = null;

        function __construct($url, $user_id = null, $password = null) {
            if(!preg_match('/^(http|https)/i',$url)) $url = 'http://'.$url;
            $this->url = $url;
            $this->user_id = $user_id;
            $this->password = $password;
        }

        function getUsersBlogs() {
            $oXmlParser = new XmlParser();

            $input = sprintf(
                '<?xml version="1.0" encoding="utf-8" ?><methodCall><methodName>blogger.getUsersBlogs</methodName><params><param><value><string>%s</string></value></param><param><value><string>%s</string></value></param><param><value><string>%s</string></value></param></params></methodCall>',
                '0123456789ABCDEF',
                $this->user_id,
                $this->password
            );
            $output = $this->_request($this->url, $input, 'application/octet-stream','POST');

            $xmlDoc = $oXmlParser->parse($output);

            if(isset($xmlDoc->methodresponse->fault)) {
                $code = $xmlDoc->methodresponse->fault->value->struct->member[0]->value->int->body;
                $message = $xmlDoc->methodresponse->fault->value->struct->member[1]->value->string->body;
                return new BaseObject($code, $message);
            }

            $val = $xmlDoc->methodresponse->params->param->value->array->data->value->struct->member;
            $output = new BaseObject();
            $output->add('url', $val[0]->value->string->body?$val[0]->value->string->body:$val[0]->value->body);
            $output->add('blogid', $blogid = $val[1]->value->string->body?$val[1]->value->string->body:$val[1]->value->body);
            $output->add('name', $val[2]->value->string->body?$val[2]->value->string->body:$val[2]->value->body);
            return $output;
        }

        function getCategories() {
            return array();
        }

        function newMediaObject($filename, $source_file) {
            return new BaseObject();
        }

        function newPost($oDocument, $category = null) {
            $oXmlParser = new XmlParser();

            $output = $this->getUsersBlogs();
            if(!$output->toBool()) return $output;
            $this->blogid = $output->get('blogid');

            $input = sprintf(
                '<?xml version="1.0"?>'.
                '<methodcall>'.
                '<methodname>blogger.newPost</methodname>'.
                '<params>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s<string></value></param>'.
                '<param><value><boolean>1</boolean></value></param>'.
                '</params>'.
                '</methodcall>',
                '0123456789ABCDEF',
                $this->blogid,
                $this->user_id,
                $this->password,
                str_replace(array('&','<','>'),array('&amp;','&lt;','&gt;'),$oDocument->get('content'))
            );

            $output = $this->_request($this->url, $input,  'application/octet-stream','POST');
            $xmlDoc = $oXmlParser->parse($output);

            if(isset($xmlDoc->methodresponse->fault)) {
                $code = $xmlDoc->methodresponse->fault->value->struct->member[0]->value->int->body;
                $message = $xmlDoc->methodresponse->fault->value->struct->member[1]->value->string->body;
                return new BaseObject($code, $message);
            }
            $postid = $xmlDoc->methodresponse->params->param->value->string->body;
            if(!$postid) $postid = $xmlDoc->methodresponse->params->param->value->i4->body;
            $output = new BaseObject();
            $output->add('postid', $postid);
            return $output;
        }

        function editPost($postid, $oDocument, $category = null) {
            $oXmlParser = new XmlParser();

            $output = $this->getUsersBlogs();
            if(!$output->toBool()) return $output;
            $this->blogid = $output->get('blogid');

            $input = sprintf(
                '<?xml version="1.0"?>'.
                '<methodcall>'.
                '<methodname>blogger.editPost</methodname>'.
                '<params>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s</string></value></param>'.
                '<param><value><string>%s<string></value></param>'.
                '<param><value><boolean>1</boolean></value></param>'.
                '</params>'.
                '</methodcall>',
                '0123456789ABCDEF',
                $postid,
                $this->user_id,
                $this->password,
                str_replace(array('&','<','>'),array('&amp;','&lt;','&gt;'),$oDocument->get('content'))
            );

            $output = $this->_request($this->url, $input,  'application/octet-stream','POST');
            $xmlDoc = $oXmlParser->parse($output);

            if(isset($xmlDoc->methodresponse->fault)) {
                $code = $xmlDoc->methodresponse->fault->value->struct->member[0]->value->int->body;
                $message = $xmlDoc->methodresponse->fault->value->struct->member[1]->value->string->body;
                return new BaseObject($code, $message);
            }
            $output = new BaseObject();
            $output->add('postid', $postid);
            return $output;
        }


        function _request($url, $body = null, $content_type = 'text/html', $method='GET', $headers = array(), $cookies = array()) {
            $url_info = parse_url($url);
            $host = $url_info['host'];

            if(!$content_type) $content_type = 'text/html';

            $cookie_settings = array();
            if($cookies[$host]) {
                $cookie_settings[$host] = $cookies[$host];
            }

            $settings = array('follow_redirects' => true);

            return FileHandler::getRemoteResource($url, $body, 10, $method, $content_type, $headers, $cookie_settings, array(), $settings);
        }

    }
?>
