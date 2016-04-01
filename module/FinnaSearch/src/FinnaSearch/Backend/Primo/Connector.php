<?php

/**
 * Primo Central connector.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  Search
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
namespace FinnaSearch\Backend\Primo;

/**
 * Primo Central connector.
 *
 * @category VuFind
 * @package  Search
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class Connector extends \VuFindSearch\Backend\Primo\Connector
{
    /**
     * Whether highlighting is enabled
     *
     * @var bool
     */
    protected $highlighting;

    /**
     * Hidden filters
     * @var array
     */
    protected $hiddenFilters = [];

    /**
     * Set hidden filters
     *
     * @param array $filters Hidden filters
     *
     * @return void
     */
    public function setHiddenFilters($filters)
    {
        $this->hiddenFilters = $filters;
    }

    /**
     * Set highlighting on|off.
     *
     * @param boolean $enabled enabled
     *
     * @return void
     */
    public function setHighlighting($enabled)
    {
        $this->highlighting = $enabled;
    }

    /**
     * Small wrapper for sendRequest, process to simplify error handling.
     *
     * @param string $qs     Query string
     * @param string $method HTTP method
     *
     * @return object    The parsed primo data
     * @throws \Exception
     */
    protected function call($qs, $method = 'GET')
    {
        if ($this->highlighting) {
            $fields = ['title','creator','description'];
            $qs .= '&highlight=true';
            foreach ($fields as $field) {
                $qs .= "&displayField=$field";
            }
        }
        return parent::call($qs, $method);
    }

    /**
     * Support method for query() -- perform inner search logic
     *
     * @param string $institution Institution
     * @param array  $terms       Associative array:
     *     index       string: primo index to search (default "any")
     *     lookfor     string: actual search terms
     * @param array  $args        Associative array of optional arguments:
     *     phrase      bool:   true if it's a quoted phrase (default false)
     *     onCampus    bool:   (default true)
     *     didyoumean  bool:   (default false)
     *     filterList  array:  (field, value) pairs to filter results (def null)
     *     pageNumber  string: index of first record (default 1)
     *     limit       string: number of records to return (default 20)
     *     sort        string: value to be used by for sorting (default null)
     *     returnErr   bool:   false to fail on error; true to return empty
     *                         empty result set with an error field (def true)
     *     Anything in $args   not listed here will be ignored.
     *
     * Note: some input parameters accepted by Primo are not implemented here:
     *  - dym (did you mean)
     *  - highlight
     *  - more (get more)
     *  - lang (specify input language so engine can do lang. recognition)
     *  - displayField (has to do with highlighting somehow)
     *
     * @throws \Exception
     * @return array             An array of query results
     */
    protected function performSearch($institution, $terms, $args)
    {
        $map = ['contains_all' => 'AND', 'contains' => 'OR'];

        // Regex for quoted words
        $pattern = '/"(.*?)"/';

        foreach ($terms as &$term) {
            if (isset($term['op']) && isset($map[$term['op']])) {
                $lookfor = trim($term['lookfor']);
                $op = $map[$term['op']];
                $words = $quoted = [];
                if (preg_match_all($pattern, $lookfor, $quoted)) {
                    // Search term includes quoted words, preserve them as groups.
                    $quoted = $quoted[0];
                    $unquoted = preg_replace($pattern, '', $lookfor);
                    $unquoted = preg_replace('/\s\s+/', ' ', $unquoted);
                    $unquoted = explode(' ', $unquoted);
                    $words = array_merge($unquoted, $quoted);
                } else {
                    // No quoted words in search term
                    $words = explode(' ', $lookfor);
                }
                $words = array_filter($words);

                $lookfor = implode(" $op ", $words);
                $term['op'] = 'contains';
                $term['lookfor'] = $lookfor;
            }
        }
        foreach ($this->hiddenFilters as $filter => $value) {
            if ($filter == 'pcAvailability') {
                $args['pcAvailability'] = (bool)$value;
            } else {
                $args['filterList'][$filter][] = $value;
            }
        }
        return parent::performSearch($institution, $terms, $args);
    }

    /**
     * Translate Primo's XML into array of arrays.
     *
     * @param array $data The raw xml from Primo
     *
     * @return array      The processed response from Primo
     */
    protected function process($data)
    {
        $res = parent::process($data);

        // Load API content as XML objects
        $sxe = new \SimpleXmlElement($data);

        if ($sxe === false) {
            throw new \Exception('Error while parsing the document');
        }

        // Register the 'sear' namespace at the top level to avoid problems:
        $sxe->registerXPathNamespace(
            'sear', 'http://www.exlibrisgroup.com/xsd/jaguar/search'
        );

        // Get the available namespaces. The Primo API uses multiple namespaces.
        // Will be used to navigate the DOM for elements that have namespaces
        $namespaces = $sxe->getNameSpaces(true);

        $docset = $sxe->xpath('//sear:DOC');
        if (empty($docset) && isset($sxe->JAGROOT->RESULT->DOCSET->DOC)) {
            $docset = $sxe->JAGROOT->RESULT->DOCSET->DOC;
        }

        for ($i = 0; $i < count($docset); $i++) {
            $doc = $docset[$i];

            // Set OpenURL
            $sear = $doc->children($namespaces['sear']);
            if ($openUrl = $this->getOpenUrl($sear)) {
                $res['documents'][$i]['url'] = $openUrl;
            } else {
                unset($res['documents'][$i]['url']);
            }

            // Prefix records id's
            $res['documents'][$i]['recordid']
                = 'pci.' . $res['documents'][$i]['recordid'];

            // Process highlighting
            if ($this->highlighting) {
                // VuFind strips Primo highlighting tags from the description,
                // so we need to re-read the field (preserving highlighting tags).
                $description = isset($doc->PrimoNMBib->record->display->description)
                    ? (string)$doc->PrimoNMBib->record->display->description
                    : (string)$doc->PrimoNMBib->record->search->description;

                $description = trim(mb_substr($description, 0, 2500, 'UTF-8'));

                // these may contain all kinds of metadata, and just stripping
                //   tags mushes it all together confusingly.
                $description = str_replace("P>", "p>", $description);

                $d_arr = explode("<p>", $description);
                foreach ($d_arr as &$value) {
                    $value = trim(($value));
                    if (trim(strip_tags($value)) === '') {
                        // get rid of entries that would just have spaces
                        unset($d_arr[$value]);
                    }
                }

                // now all paragraphs are converted to linebreaks
                $description = implode("<br>", $d_arr);
                $res['documents'][$i]['description'] = $description;

                $highlightFields = [
                    'title' => 'title',
                    'creator' => 'author',
                    'description' => 'description'
                ];

                $start = '<span class="searchword">';
                $end = '</span>';

                $hilited = [];

                foreach ($res['documents'][$i] as $fieldName => $fieldData) {
                    $isArr = is_array($fieldData);
                    $values = $isArr ? $fieldData : [$fieldData];
                    if (isset($highlightFields[$fieldName])) {
                        $valuesHilited = [];
                        foreach ($values as $val) {
                            if (stripos($val, $start) !== false
                                && stripos($val, $end) !== false
                            ) {
                                // Replace Primo hilite-tags
                                $hilitedVal = $val;
                                $hilitedVal = str_replace(
                                    $start, '{{{{START_HILITE}}}}', $hilitedVal
                                );
                                $hilitedVal = str_replace(
                                    $end, '{{{{END_HILITE}}}}', $hilitedVal
                                );
                                $valuesHilited[] = $hilitedVal;
                            }
                        }
                        if (!empty($valuesHilited)) {
                            $hilited[$highlightFields[$fieldName]] = $valuesHilited;
                        }
                    }

                    foreach ($values as &$val) {
                        // Strip Primo hilite-tags from record fields
                        $val = str_replace($start, '', $val);
                        $val = str_replace($end, '', $val);
                    }
                    $res['documents'][$i][$fieldName]
                        = $isArr ? $values : $values[0];
                }
                $res['documents'][$i]['highlightDetails'] = $hilited;
            }
        }

        return $res;
    }

    /**
     * Retrieves a document specified by the ID.
     *
     * @param string $recordId  The document to retrieve from the Primo API
     * @param string $inst_code Institution code (optional)
     * @param bool   $onCampus  Whether the user is on campus
     *
     * @throws \Exception
     * @return string    The requested resource
     */
    public function getRecord($recordId, $inst_code = null, $onCampus = false)
    {
        list(,$recordId) = explode('.', $recordId, 2);
        return parent::getRecord($recordId, $inst_code, $onCampus);
    }

    /**
     * Retrieves multiple documents specified by the ID.
     *
     * @param array  $recordIds The documents to retrieve from the Primo API
     * @param string $inst_code Institution code (optional)
     * @param bool   $onCampus  Whether the user is on campus
     *
     * @throws \Exception
     * @return string    The requested resource
     */
    public function getRecords($recordIds, $inst_code = null, $onCampus = false)
    {
        $recordIds = array_map(
            function ($recordId) {
                list(,$recordId) = explode('.', $recordId, 2);
                return $recordId;
            },
            $recordIds
        );
        return parent::getRecords($recordIds, $inst_code, $onCampus);
    }

    /**
     * Helper function for retrieving the OpenURL link from a Primo result.
     *
     * @param SimpleXmlElement $sear XML-element to search
     *
     * @throws \Exception
     * @return string|false
     */
    protected function getOpenUrl($sear)
    {
        $result = null;
        if (!empty($sear->LINKS->openurl)) {
            if (($url = $sear->LINKS->openurl) !== '') {
                $result = (string)$url;
            }
        }

        $attr = $sear->GETIT->attributes();
        if (!empty($attr->GetIt2)) {
            if (($url = (string)$attr->GetIt2) !== '') {
                $result = (string)$url;
            }
        }

        if (!empty($attr->GetIt1)) {
            if (($url = (string)$attr->GetIt1) !== '') {
                $result = (string)$url;
            }
        }

        if ($result) {
            // Remove blacklisted and empty URL parameters
            $blacklist = ['rft_id' => 'info:oai/'];

            if (strstr($result, '?') === false) {
                return $result;
            }

            list($host, $query) = explode('?', $result);

            $params = [];
            foreach (explode('&', $query) as $param) {
                if (strstr($param, '=') === false) {
                    continue;
                }
                list($key, $val) = explode('=', $param, 2);
                $val = trim($val);
                if ($val == ''
                    || isset($blacklist[$key]) && $blacklist[$key] == $val
                ) {
                    continue;
                }
                $params[$key] = $val;
            }
            $query = http_build_query($params);
            return "$host?$query";
        }

        return false;
    }
}
