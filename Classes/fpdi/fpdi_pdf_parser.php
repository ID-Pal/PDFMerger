<?php
/**
 * This file is part of FPDI
 *
 * @package   FPDI
 * @copyright Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 * @version   1.6.1
 */

if (!class_exists('pdf_parser')) {
    require_once('pdf_parser.php');
}

/**
 * Class fpdi_pdf_parser
 */
class fpdi_pdf_parser extends pdf_parser
{
    /**
     * Pages
     *
     * Index begins at 0
     *
     * @var array
     */
    protected $_pages;
    
    /**
     * Page count
     *
     * @var integer
     */
    protected $_pageCount;
    
    /**
     * Current page number
     *
     * @var integer
     */
    public $pageNo;
    
    /**
     * PDF version of imported document
     *
     * @var string
     */
    public $_pdfVersion;
    
    /**
     * Available BoxTypes
     *
     * @var array
     */
    public $availableBoxes = array('/MediaBox', '/CropBox', '/BleedBox', '/TrimBox', '/ArtBox');
        
    /**
     * The constructor.
     *
     * @param string $filename The source filename
     */
    public function __construct($filename)
    {
        parent::__construct($filename);

        // resolve Pages-Dictonary
        $pages = $this->resolveObject($this->_root[1][1]['/Pages']);

        // Read pages
        $this->_readPages($pages, $this->_pages);
        
        // count pages;
        $this->_pageCount = count($this->_pages);
    }
    
    /**
     * Get page count from source file.
     *
     * @return int
     */
    public function getPageCount()
    {
        return $this->_pageCount;
    }

    /**
     * Set the page number.
     *
     * @param int $pageNo Page number to use
     * @throws InvalidArgumentException
     */
    public function setPageNo($pageNo)
    {
        $pageNo = ((int) $pageNo) - 1;

        if ($pageNo < 0 || $pageNo >= $this->getPageCount()) {
            throw new InvalidArgumentException('Invalid page number!');
        }

        $this->pageNo = $pageNo;
    }
    
    /**
     * Get page-resources from current page
     *
     * @return array|boolean
     */
    public function getPageResources()
    {
        return $this->_getPageResources($this->_pages[$this->pageNo]);
    }

    /**
     * Build a "objNum.genNum" cycle key for a resolved object, or null when
     * the object is inline (not resolved through an OBJREF).
     *
     * @param array $obj A resolved object as returned by {@see resolveObject()}.
     * @return string|null
     */
    private function _pageObjectKey($obj)
    {
        if (is_array($obj) && isset($obj['obj']) && isset($obj['gen'])) {
            return $obj['obj'] . '.' . $obj['gen'];
        }
        return null;
    }

    /**
     * Get page-resources from a /Page dictionary.
     *
     * @param array $obj Array of pdf-data
     * @param array $visited Object keys already visited on this /Parent chain.
     * @return array|boolean
     * @throws pdf_parser_resource_exception When a /Parent cycle is detected.
     */
    protected function _getPageResources($obj, array $visited = array())
    {
        $obj = $this->resolveObject($obj);

        $key = $this->_pageObjectKey($obj);
        if ($key !== null) {
            if (isset($visited[$key])) {
                throw new pdf_parser_resource_exception(sprintf(
                    '_getPageResources cyclic /Parent chain at object %s', $key
                ));
            }
            $visited[$key] = true;
        }

        // If the current object has a resources
        // dictionary associated with it, we use
        // it. Otherwise, we move back to its
        // parent object.
        if (isset($obj[1][1]['/Resources'])) {
            $res = $this->resolveObject($obj[1][1]['/Resources']);
            if ($res[0] == pdf_parser::TYPE_OBJECT)
                return $res[1];
            return $res;
        }

        if (!isset($obj[1][1]['/Parent'])) {
            return false;
        }

        $res = $this->_getPageResources($obj[1][1]['/Parent'], $visited);
        if ($res[0] == pdf_parser::TYPE_OBJECT)
            return $res[1];
        return $res;
    }

    /**
     * Get content of current page.
     *
     * If /Contents is an array, the streams are concatenated
     *
     * @return string
     */
    public function getContent()
    {
        $buffer = '';
        
        if (isset($this->_pages[$this->pageNo][1][1]['/Contents'])) {
            $contents = $this->_getPageContent($this->_pages[$this->pageNo][1][1]['/Contents']);
            foreach ($contents AS $tmpContent) {
                $buffer .= $this->_unFilterStream($tmpContent) . ' ';
            }
        }
        
        return $buffer;
    }

    /**
     * Resolve all content objects.
     *
     * @param array $contentRef
     * @return array
     */
    protected function _getPageContent($contentRef)
    {
        $contents = array();
        
        if ($contentRef[0] == pdf_parser::TYPE_OBJREF) {
            $content = $this->resolveObject($contentRef);
            if ($content[1][0] == pdf_parser::TYPE_ARRAY) {
                $contents = $this->_getPageContent($content[1]);
            } else {
                $contents[] = $content;
            }
        } else if ($contentRef[0] == pdf_parser::TYPE_ARRAY) {
            foreach ($contentRef[1] AS $tmp_content_ref) {
                $contents = array_merge($contents, $this->_getPageContent($tmp_content_ref));
            }
        }

        return $contents;
    }

    /**
     * Get a boundary box from a page
     *
     * Array format is same as used by FPDF_TPL.
     *
     * @param array $page a /Page dictionary
     * @param string $boxIndex Type of box {see {@link $availableBoxes})
     * @param float Scale factor from user space units to points
     *
     * @return array|boolean
     */
    protected function _getPageBox($page, $boxIndex, $k, array $visited = array())
    {
        $page = $this->resolveObject($page);

        $key = $this->_pageObjectKey($page);
        if ($key !== null) {
            if (isset($visited[$key])) {
                throw new pdf_parser_resource_exception(sprintf(
                    '_getPageBox cyclic /Parent chain at object %s', $key
                ));
            }
            $visited[$key] = true;
        }

        $box = null;
        if (isset($page[1][1][$boxIndex])) {
            $box = $page[1][1][$boxIndex];
        }
        
        if (!is_null($box) && $box[0] == pdf_parser::TYPE_OBJREF) {
            $tmp_box = $this->resolveObject($box);
            $box = $tmp_box[1];
        }
            
        if (!is_null($box) && $box[0] == pdf_parser::TYPE_ARRAY) {
            $b = $box[1];
            return array(
                'x' => $b[0][1] / $k,
                'y' => $b[1][1] / $k,
                'w' => abs($b[0][1] - $b[2][1]) / $k,
                'h' => abs($b[1][1] - $b[3][1]) / $k,
                'llx' => min($b[0][1], $b[2][1]) / $k,
                'lly' => min($b[1][1], $b[3][1]) / $k,
                'urx' => max($b[0][1], $b[2][1]) / $k,
                'ury' => max($b[1][1], $b[3][1]) / $k,
            );
        } else if (!isset($page[1][1]['/Parent'])) {
            return false;
        } else {
            return $this->_getPageBox($this->resolveObject($page[1][1]['/Parent']), $boxIndex, $k, $visited);
        }
    }

    /**
     * Get all page boundary boxes by page number
     * 
     * @param int $pageNo The page number
     * @param float $k Scale factor from user space units to points
     * @return array
     * @throws InvalidArgumentException
     */
    public function getPageBoxes($pageNo, $k)
    {
        if (!isset($this->_pages[$pageNo - 1])) {
            throw new InvalidArgumentException('Page ' . $pageNo . ' does not exists.');
        }

        return $this->_getPageBoxes($this->_pages[$pageNo - 1], $k);
    }
    
    /**
     * Get all boxes from /Page dictionary
     *
     * @param array $page A /Page dictionary
     * @param float $k Scale factor from user space units to points
     * @return array
     */
    protected function _getPageBoxes($page, $k)
    {
        $boxes = array();

        foreach($this->availableBoxes AS $box) {
            if ($_box = $this->_getPageBox($page, $box, $k)) {
                $boxes[$box] = $_box;
            }
        }

        return $boxes;
    }

    /**
     * Get the page rotation by page number
     *
     * @param integer $pageNo
     * @throws InvalidArgumentException
     * @return array
     */
    public function getPageRotation($pageNo)
    {
        if (!isset($this->_pages[$pageNo - 1])) {
            throw new InvalidArgumentException('Page ' . $pageNo . ' does not exists.');
        }

        return $this->_getPageRotation($this->_pages[$pageNo - 1]);
    }

    /**
     * Get the rotation value of a page
     *
     * @param array $obj A /Page dictionary
     * @param array $visited Object keys already visited on this /Parent chain.
     * @return array|bool
     * @throws pdf_parser_resource_exception When a /Parent cycle is detected.
     */
    protected function _getPageRotation($obj, array $visited = array())
    {
        $obj = $this->resolveObject($obj);

        $key = $this->_pageObjectKey($obj);
        if ($key !== null) {
            if (isset($visited[$key])) {
                throw new pdf_parser_resource_exception(sprintf(
                    '_getPageRotation cyclic /Parent chain at object %s', $key
                ));
            }
            $visited[$key] = true;
        }

        if (isset($obj[1][1]['/Rotate'])) {
            $res = $this->resolveObject($obj[1][1]['/Rotate']);
            if ($res[0] == pdf_parser::TYPE_OBJECT)
                return $res[1];
            return $res;
        }

        if (!isset($obj[1][1]['/Parent'])) {
            return false;
        }

        $res = $this->_getPageRotation($obj[1][1]['/Parent'], $visited);
        if(false == $res) return false;
        if ($res[0] == pdf_parser::TYPE_OBJECT)
            return $res[1];

        return $res;
    }

    /**
     * Read all pages
     *
     * @param array $pages /Pages dictionary
     * @param array $result The result array
     * @param array $visited Object keys already visited on this /Kids chain.
     * @throws Exception
     * @throws pdf_parser_resource_exception When a /Pages /Kids cycle is detected.
     */
    protected function _readPages(&$pages, &$result, array $visited = array())
    {
        $parentKey = $this->_pageObjectKey($pages);
        if ($parentKey !== null) {
            if (isset($visited[$parentKey])) {
                throw new pdf_parser_resource_exception(sprintf(
                    '_readPages cyclic /Pages /Kids chain at object %s', $parentKey
                ));
            }
            $visited[$parentKey] = true;
        }

        // Get the kids dictionary
        $_kids = $this->resolveObject($pages[1][1]['/Kids']);

        if (!is_array($_kids)) {
            throw new Exception('Cannot find /Kids in current /Page-Dictionary');
        }

        if ($_kids[0] === self::TYPE_OBJECT) {
            $_kids =  $_kids[1];
        }

        $kids = $_kids[1];

        foreach ($kids as $v) {
            $pg = $this->resolveObject($v);
            if ($pg[1][1]['/Type'][1] === '/Pages') {
                // If one of the kids is an embedded
                // /Pages array, resolve it as well.
                $this->_readPages($pg, $result, $visited);
            } else {
                $result[] = $pg;
            }
        }
    }
}
