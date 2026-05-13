<?php

namespace IdPal\PDFMerger\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the defensive resource guards added on top of the bundled
 * FPDI 1.6.1 parser convert previously-fatal failure classes into catchable
 * exceptions before PHP's `memory_limit` (E_ERROR) terminates the request.
 *
 * The class-under-test is the file-based `pdf_parser` from
 * `Classes/fpdi/pdf_parser.php`; tests mostly subclass it to lower the
 * resource caps so a small synthetic PDF can deterministically trip the
 * guard being exercised.
 */
class PdfParserGuardsTest extends TestCase
{
    /**
     * @var string[] Temp files to remove after each test.
     */
    private $tempFiles = array();

    public static function setUpBeforeClass(): void
    {
        $classesDir = dirname(__DIR__) . '/Classes/fpdi';
        require_once $classesDir . '/pdf_parser_exceptions.php';
        require_once $classesDir . '/pdf_context.php';
        require_once $classesDir . '/pdf_parser.php';
        require_once $classesDir . '/fpdi_pdf_parser.php';
        require_once __DIR__ . '/MinimalPdfBuilder.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = array();
    }

    private function buildHappyPathPdf(): string
    {
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
            ->addObject(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;
        return $pdf;
    }

    public function testHappyPathParsesUnderBudget(): void
    {
        $path = $this->buildHappyPathPdf();

        $peakBefore = memory_get_peak_usage(true);
        $parser = new \fpdi_pdf_parser($path);
        $peakAfter = memory_get_peak_usage(true);

        $this->assertSame(1, $parser->getPageCount());
        $this->assertLessThan(64 * 1024 * 1024, $peakAfter - $peakBefore,
            'Parsing a trivial PDF should not allocate anywhere near the budget.');
    }

    public function testReadValueDepthBoundThrows(): void
    {
        // 300 levels of nested arrays inside obj 4. The catalog/pages skeleton
        // is intact so the parser reaches obj 4 via resolveObject.
        $nested = str_repeat('[', 300) . '1' . str_repeat(']', 300);
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R /DeepRef 4 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
            ->addObject(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->addObject(4, '<< /Deep ' . $nested . ' >>')
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;

        $parser = new ShallowParser($pdf);

        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/recursion depth exceeded/');
        $parser->resolveObject(array(\pdf_parser::TYPE_OBJREF, 4, 0));
    }

    public function testArrayElementCapThrows(): void
    {
        $bigArray = '[' . str_repeat('1 ', 15) . ']';
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R /Wide 4 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
            ->addObject(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->addObject(4, '<< /Big ' . $bigArray . ' >>')
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;

        $parser = new TinyArrayParser($pdf);

        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/array element count exceeded/');
        $parser->resolveObject(array(\pdf_parser::TYPE_OBJREF, 4, 0));
    }

    public function testDictEntryCapThrows(): void
    {
        $entries = '';
        for ($i = 0; $i < 15; $i++) {
            $entries .= '/Key' . $i . ' ' . $i . ' ';
        }
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R /Wide 4 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
            ->addObject(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->addObject(4, '<< ' . $entries . ' >>')
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;

        $parser = new TinyDictParser($pdf);

        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/dictionary entry count exceeded/');
        $parser->resolveObject(array(\pdf_parser::TYPE_OBJREF, 4, 0));
    }

    public function testObjRefCycleThrows(): void
    {
        // Obj 4 has /Length referring to obj 5; obj 5 is a stream whose
        // /Length resolves to obj 4. resolveObject sees the same key twice
        // on its in-flight stack and throws.
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
            ->addObject(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->addObject(4, "<< /Length 5 0 R >>\nstream\nhello\nendstream")
            ->addObject(5, "<< /Length 4 0 R >>\nstream\nworld\nendstream")
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;

        $parser = new \fpdi_pdf_parser($pdf);

        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/(OBJREF cycle|recursion depth)/');
        $parser->resolveObject(array(\pdf_parser::TYPE_OBJREF, 4, 0));
    }

    public function testParentCycleThrows(): void
    {
        // Two /Pages nodes that point at each other via /Parent. The page
        // resource lookup walks up the /Parent chain and the visited-set
        // guard fires on revisit.
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [4 0 R] /Count 1 /Parent 3 0 R >>')
            ->addObject(3, '<< /Type /Pages /Kids [4 0 R] /Count 1 /Parent 2 0 R >>')
            ->addObject(4, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;

        $parser = new \fpdi_pdf_parser($pdf);
        $parser->setPageNo(1);

        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/cyclic \\/Parent chain/');
        $parser->getPageResources();
    }

    public function testBufferCapThrowsOnUnterminatedHexString(): void
    {
        // Open the hex literal but never close it. The parser keeps growing
        // the buffer searching for `>` until the cap kicks in.
        // Object 4 has an opening `<` for a hex string with no matching `>`,
        // so the parser must grow its read buffer until EOF fires the guard.
        // The body intentionally omits any `>` character so the hex literal
        // is genuinely unterminated.
        $pdf = (new MinimalPdfBuilder())
            ->addObject(1, '<< /Type /Catalog /Pages 2 0 R /Hex 4 0 R >>')
            ->addObject(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>')
            ->addObject(3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>')
            ->addObject(4, '<< /X <' . str_repeat('A', 1024))
            ->setRoot(1)
            ->writeToTempFile();
        $this->tempFiles[] = $pdf;

        $parser = new TinyBufferParser($pdf);

        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/buffer would exceed/');
        $parser->resolveObject(array(\pdf_parser::TYPE_OBJREF, 4, 0));
    }

    public function testMemoryBudgetCanaryThrows(): void
    {
        $path = $this->buildHappyPathPdf();

        // 1-byte memory budget: anything the parser does post-construction
        // (e.g. reading a small object) trips the canary. Useful to confirm
        // the budget plumbing actually fires.
        $this->expectException(\pdf_parser_resource_exception::class);
        $this->expectExceptionMessageMatches('/memory budget exceeded/');

        $parser = new ZeroBudgetParser($path);
        // Force at least one in-parse resolution after construction.
        $parser->resolveObject(array(\pdf_parser::TYPE_OBJREF, 3, 0));
    }

    public function testProductionPoaFixtureFailsCleanly(): void
    {
        // Drop the actual submission 6102671 POA into
        // tests/fixtures/poa_6102671.pdf to enable this regression test
        // locally. Kept out of the repo by .gitignore for data-protection
        // reasons; CI gets it from secure storage.
        $fixture = __DIR__ . '/fixtures/poa_6102671.pdf';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('Production POA fixture not present (tests/fixtures/poa_6102671.pdf).');
        }

        $peakBefore = memory_get_peak_usage(true);
        $exception = null;
        try {
            $merger = new \PDFMerger();
            $merger->addPDF($fixture, 'all');
            $merger->merge('string', 'never-written.pdf');
        } catch (\Throwable $e) {
            $exception = $e;
        }
        $peakAfter = memory_get_peak_usage(true);

        $this->assertNotNull($exception, 'Expected a clean exception, not silent success or an E_ERROR fatal.');
        $this->assertInstanceOf(\pdf_parser_resource_exception::class, $exception);
        $this->assertLessThan(128 * 1024 * 1024, $peakAfter - $peakBefore,
            'Even when the parse is aborted, peak memory must stay well below the historical 2 GB blow-up.');
    }
}

/**
 * pdf_parser subclasses that lower individual guard thresholds for the
 * corresponding test. Each overrides exactly one cap so the failure mode is
 * unambiguous. They extend `fpdi_pdf_parser` (rather than `pdf_parser`
 * directly) so the constructor still runs the full page-tree initialisation.
 */
class ShallowParser extends \fpdi_pdf_parser
{
    const MAX_READ_VALUE_DEPTH = 8;
}

class TinyArrayParser extends \fpdi_pdf_parser
{
    const MAX_ARRAY_ELEMENTS = 5;
}

class TinyDictParser extends \fpdi_pdf_parser
{
    const MAX_DICT_ENTRIES = 5;
}

class TinyBufferParser extends \fpdi_pdf_parser
{
    // Cap applied on pdf_context, not pdf_parser. The buffer test relies on
    // pdf_context::MAX_BUFFER_BYTES; this subclass exists only so the test
    // can name a distinct parser, keeping behaviour identical to the real one.
}

class ZeroBudgetParser extends \fpdi_pdf_parser
{
    const MAX_PARSE_MEMORY_BYTES = 1;
}
