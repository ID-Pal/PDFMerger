<?php

namespace IdPal\PDFMerger\Tests;

/**
 * Builds a minimal valid PDF 1.4 document on disk from a list of raw object
 * body strings. Used by the guard test suite to exercise the parser without
 * needing real-world PDFs as binary fixtures.
 *
 * The builder is intentionally small. It computes a conventional xref table,
 * a trailer pointing at the configured root object reference, and writes the
 * result to a temp file. Callers receive the path and are responsible for
 * deletion.
 */
class MinimalPdfBuilder
{
    /**
     * Object bodies indexed by 1-based object number. Each value is the raw
     * dictionary or stream body (without the surrounding `N 0 obj ... endobj`
     * envelope which the builder adds itself).
     *
     * @var array<int, string>
     */
    private $objects = array();

    /**
     * Object number to point /Root at in the trailer.
     *
     * @var int
     */
    private $rootObjNum = 1;

    /**
     * @param int    $objNum 1-based object number.
     * @param string $body   Raw object body (e.g. "<< /Type /Catalog /Pages 2 0 R >>").
     * @return $this
     */
    public function addObject($objNum, $body)
    {
        $this->objects[$objNum] = $body;
        return $this;
    }

    /**
     * @param int $objNum
     * @return $this
     */
    public function setRoot($objNum)
    {
        $this->rootObjNum = $objNum;
        return $this;
    }

    /**
     * Write the assembled PDF to a temp file and return its path.
     *
     * @return string Path to the written file.
     */
    public function writeToTempFile()
    {
        $body = $this->renderBytes();
        $path = tempnam(sys_get_temp_dir(), 'pdfmerger-test-');
        file_put_contents($path, $body);
        return $path;
    }

    /**
     * @return string The full PDF byte sequence.
     */
    public function renderBytes()
    {
        ksort($this->objects);

        $output = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array();

        foreach ($this->objects as $num => $bodyRaw) {
            $offsets[$num] = strlen($output);
            $output .= $num . " 0 obj\n" . $bodyRaw . "\nendobj\n";
        }

        $xrefOffset = strlen($output);
        $maxNum = max(array_keys($this->objects));
        $size = $maxNum + 1;

        $output .= "xref\n0 " . $size . "\n";
        $output .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxNum; $i++) {
            if (isset($offsets[$i])) {
                $output .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $output .= "0000000000 65535 f \n";
            }
        }

        $output .= "trailer\n<< /Size " . $size . " /Root " . $this->rootObjNum . " 0 R >>\n";
        $output .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $output;
    }
}
