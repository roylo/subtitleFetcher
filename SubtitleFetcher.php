<?php
require_once dirname(__FILE__) . '/lib/SimpleHtmlDom.inc';
require_once dirname(__FILE__) . '/lib/yaml/axial.configuration.inc';
require_once dirname(__FILE__) . '/lib/yaml/axial.configuration.yaml.inc';

class SubtitleFetcher
{
    private $_remote_host = "http://www.yyets.com";
    private $_order_offset = 4;
    private $_shows_name;
    private $_shows_folder;

    public function __construct()
    {
        $conf_path = dirname(__FILE__) . '/conf/SubtitleFetcher.yaml';
        $config = new Axial_Configuration_Yaml($conf_path, true);
        $this->_shows_name = $config->shows->toArray();
        $this->_meta_folder = $config->meta_folder;
        $this->_shows_folder = $config->shows_folder;
    }

    public function processAll()
    { //{{{
        foreach ($this->_shows_name as $name) {
            $sub_file_prefix = $this->initFolder($this->_shows_folder ."/". $name);
            $file_meta = $this->_meta_folder ."/". $name .".json";
            $meta_data = $this->getMetaFiles($file_meta);
            if ($meta_data) {
                $this->process($meta_data, $sub_file_prefix);
            }
        }
    } //}}}

    public function process($metadata, $sub_file_prefix)
    { //{{{
        $keyword = $metadata['name'] ."+". $metadata['season'] ."+". $metadata['cur_episode'];
        $search_url = $this->_remote_host ."/search/index?keyword=$keyword&type=subtitle&order=uptime";
        echo "search url: $search_url \n";
        $html = file_get_html($search_url);

        $success = false;
        $order_index = $this->findEpisodeVersion($html, $metadata['version']);
        if ($order_index !== null) {
            $links = $html->find('div.box_1 a');
            $target_link = $links[$order_index + $this->_order_offset]->href;
            echo "target_link: $target_link \n";
            $slash_pos = strrpos($target_link , '/');
            $id = substr($target_link, $slash_pos+1);
            $subtitle_path = "$sub_file_prefix"."$keyword";
            $ext_type = $this->downloadSubtitle($id, $subtitle_path);
            if ($ext_type) {
                //TODO rename file
                $subtitle_name = "$subtitle_path.$ext_type";
                rename($subtitle_path, $subtitle_name);
                echo "Download $subtitle_name success!\n";
                $metadata['timestamp']['download'] = time();
                $metadata['id'] = $id;
            }

        }

        $metadata['timestamp']['check'] = time();
        $success = empty($ext_type) ? false : true;
        $this->updateMetaData($metadata, $success);
    } //}}}

    private function findEpisodeVersion($html, $version_text)
    { //{{{
        foreach ($html->find('p.all_search_li4') as $index => $element) {
            $plain = $element->plaintext;
            $node = explode("\x0D", $plain);
            foreach ($node as $str) {
                $str = trim($str);
                list($version, $episode_ver) = explode(":", $str);
                $lower_case_version_text = strtolower($version_text);
                $lower_case_episode_ver = strtolower($episode_ver);
                if (strpos($lower_case_episode_ver, $lower_case_version_text) !== false) {
                    return $index;
                }
            }
        }
        return null;
    } //}}}

    private function downloadSubtitle($id, $subtitle_path)
    { //{{{
        $download_url = $this->_remote_host ."/subtitle/index/download?id=$id";
        $write_byte = file_put_contents($subtitle_path, file_get_contents($download_url));
        $content_type = mime_content_type($subtitle_path);
        $extension_type = substr(strrchr($content_type, "/"), 1);

        if (!$extension_type || $write_byte <= 0) {
            return false;
        }

        return $extension_type;
    } //}}}

    private function initFolder($file_dir)
    { //{{{
        if (!file_exists($file_dir)) {
            mkdir($file_dir);
        }
        $sub_file_prefix = "$file_dir/";
        return $sub_file_prefix;

    } //}}}

    private function getMetaFiles($file)
    { //{{{
        $json_arr = null;
        if (file_exists($file)) {
            $json_str = file_get_contents($file);
            $json_arr = json_decode($json_str, true);
        } else {
            return false;
        }
        return $json_arr;
    } //}}}

    function updateMetaData($metadata, $success)
    { //{{{
        if ($success) {
            $cur_episode_num = substr($metadata['cur_episode'], strrpos($metadata['cur_episode'], "e") + 1);
            $new_episode_num = $cur_episode_num + 1;
            if ($new_episode_num > $metadata['num_episode']) {
                $new_episode_num = 1;
                $cur_season_num = substr($metadata['season'], strrpos($metadata['season'], "s") + 1);
                $new_season_num = $cur_season_num + 1;
                $new_season_num_str = handle_prefix($new_season_num, 2, "0");
                $metadata['season'] = "s$new_season_num_str";

            }
            $new_episode_num_str = $this->handlePrefix($new_episode_num, 2, "0");
            $metadata['cur_episode'] = "e$new_episode_num_str";
        }

        $file = $this->_meta_folder ."/". $metadata['name'] .".json";
        echo $json_pretty = $this->jsonPretty(json_encode($metadata));
        echo file_put_contents($file, $json_pretty);
    } //}}}

    private function handlePrefix($str, $length, $prefix)
    { //{{{
        $new_str = null;
        for($index = 0; $index < ($length - strlen($str)); $index++) {
            $new_str .= $prefix;
        }
        $new_str .= $str;

        return $new_str;
    } //}}}

    private function jsonPretty($json, $options = array())
    { //{{{
        $tokens = preg_split('|([\{\}\]\[,])|', $json, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        $indent = 0;

        $format = 'txt';

        //$ind = "\t";
        $ind = "    ";

        if (isset($options['format'])) {
            $format = $options['format'];
        }

        switch ($format) {
            case 'html':
                $lineBreak = '<br />';
                $ind = '&nbsp;&nbsp;&nbsp;&nbsp;';
                break;
            default:
            case 'txt':
                $lineBreak = "\n";
                //$ind = "\t";
                $ind = "    ";
                break;
        }

        // override the defined indent setting with the supplied option
        if (isset($options['indent'])) {
            $ind = $options['indent'];
        }

        $inLiteral = false;
        foreach ($tokens as $token) {
            if ($token == '') {
                continue;
            }

            $prefix = str_repeat($ind, $indent);
            if (!$inLiteral && ($token == '{' || $token == '[')) {
                $indent++;
                if (($result != '') && ($result[(strlen($result) - 1)] == $lineBreak)) {
                    $result .= $prefix;
                }
                $result .= $token . $lineBreak;
            } elseif (!$inLiteral && ($token == '}' || $token == ']')) {
                $indent--;
                $prefix = str_repeat($ind, $indent);
                $result .= $lineBreak . $prefix . $token;
            } elseif (!$inLiteral && $token == ',') {
                $result .= $token . $lineBreak;
            } else {
                $result .= ( $inLiteral ? '' : $prefix ) . $token;

                // Count # of unescaped double-quotes in token, subtract # of
                // escaped double-quotes and if the result is odd then we are
                // inside a string literal
                if ((substr_count($token, "\"") - substr_count($token, "\\\"")) % 2 != 0) {
                    $inLiteral = !$inLiteral;
                }
            }
        }
        return $result;
    } //}}}
}

$sf = new SubtitleFetcher();
$sf->processAll();

