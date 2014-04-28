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
        $config = new Axial_Configuration_Yaml('conf/SubtitleFetcher.yaml', true);
        $this->_shows_name = $config->shows->toArray();
        $this->_meta_folder = $config->meta_folder;
        $this->_shows_folder = $config->shows_folder;
    }

    public function process($metadata, $sub_file_prefix)
    { //{{{
        $keyword = $metadata['name'] ."+". $metadata['season'] ."+". $metadata['cur_episode'];
        $search_url = $this->_remote_host ."/search/index?keyword=$keyword&type=subtitle&order=uptime";
//      echo "search url: $search_url \n";
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
                echo "Download $subtitle_path success!\n";
                $metadata['timestamp']['download'] = time();
                $metadata['id'] = $id;
            }

        }

        $metadata['timestamp']['check'] = time();
//        update_metadata($metadata, $success, PATH_PREFIX);
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

        if ($extension_type || $write_byte <= 0) {
            return false;
        }

        return $extension_type;
    } //}}}

    public function processAll()
    {
        foreach ($this->_shows_name as $name) {
            $this->initFolder($this->_shows_folder ."/". $name);
            $file_meta = $this->_meta_folder ."/". $name .".json";
            $meta_data = $this->getMetaFiles($file_meta);
            if ($meta_data) {
            }
        }
    }

    private function initFolder($file_dir)
    { //{{{
        if (!file_exists($file_dir)) {
            mkdir($file_dir);
        }
        $sub_file_prefix = "$file_dir/";
        return $sub_file_prefix;

    } //}}}

    private function getMetaFiles($file)
    {
        $json_arr = null;
        if (file_exists($file)) {
            $json_str = file_get_contents($file);
            $json_arr = json_decode($json_str, true);
        } else {
            return false;
        }
        return $json_arr;
    }
}

$sf = new SubtitleFetcher();
$sf->processAll();

